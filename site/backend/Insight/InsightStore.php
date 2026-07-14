<?php

declare(strict_types=1);

namespace Politiks\App\Insight;

use Closure;
use PDO;
use Throwable;

final class InsightStore
{
    private ?PDO $connection = null;

    /** @param Closure():PDO $connectionFactory */
    public function __construct(
        private readonly Closure $connectionFactory,
        private readonly string $appUrl,
    ) {
    }

    /** @return array{items:list<array<string,mixed>>,pagination:array<string,int>} */
    public function publicPage(int $page, int $perPage): array
    {
        [$page, $perPage, $offset] = $this->pagination($page, $perPage);
        $total = (int) $this->connection()->query(
            "SELECT COUNT(*) FROM insight WHERE visibility='public' AND archived_at IS NULL"
        )->fetchColumn();
        $statement = $this->connection()->prepare(
            $this->baseSelect()
            . " WHERE insight.visibility='public' AND insight.archived_at IS NULL
                ORDER BY insight.published_at DESC, insight.id DESC LIMIT ? OFFSET ?"
        );
        $statement->bindValue(1, $perPage, PDO::PARAM_INT);
        $statement->bindValue(2, $offset, PDO::PARAM_INT);
        $statement->execute();
        return $this->pageResult($statement->fetchAll(), $page, $perPage, $total, false);
    }

    /** @return array{items:list<array<string,mixed>>,pagination:array<string,int>} */
    public function ownerPage(int $ownerId, int $page, int $perPage): array
    {
        [$page, $perPage, $offset] = $this->pagination($page, $perPage);
        $count = $this->connection()->prepare(
            'SELECT COUNT(*) FROM insight WHERE owner_user_id=? AND archived_at IS NULL'
        );
        $count->execute([$ownerId]);
        $total = (int) $count->fetchColumn();
        $statement = $this->connection()->prepare(
            $this->baseSelect()
            . ' WHERE insight.owner_user_id=? AND insight.archived_at IS NULL
                ORDER BY insight.updated_at DESC, insight.id DESC LIMIT ? OFFSET ?'
        );
        $statement->bindValue(1, $ownerId, PDO::PARAM_INT);
        $statement->bindValue(2, $perPage, PDO::PARAM_INT);
        $statement->bindValue(3, $offset, PDO::PARAM_INT);
        $statement->execute();
        return $this->pageResult($statement->fetchAll(), $page, $perPage, $total, true);
    }

    /** @return array<string,mixed>|null */
    public function findVisible(string $publicId, ?int $viewerId): ?array
    {
        $statement = $this->connection()->prepare(
            $this->baseSelect()
            . " WHERE insight.public_id=? AND insight.archived_at IS NULL
                AND (insight.visibility='public' OR insight.owner_user_id=?)"
        );
        $statement->execute([$publicId, $viewerId ?? 0]);
        $rows = $statement->fetchAll();
        if ($rows === []) {
            return null;
        }
        return $this->hydrate($rows, $viewerId !== null && (int) $rows[0]['owner_user_id'] === $viewerId)[0];
    }

    /** @return array<string,mixed>|null */
    public function findShared(string $token): ?array
    {
        $statement = $this->connection()->prepare(
            $this->baseSelect()
            . " WHERE insight.share_token_hash=? AND insight.visibility='unlisted'
                AND insight.archived_at IS NULL"
        );
        $statement->execute([hash('sha256', $token)]);
        $rows = $statement->fetchAll();
        return $rows === [] ? null : $this->hydrate($rows, false)[0];
    }

    /** @return array<string,mixed> */
    public function createDraft(int $ownerId): array
    {
        $activePublication = $this->connection()->query(
            'SELECT active_publication_id FROM reference_state WHERE singleton_id=1'
        )->fetchColumn();
        if ($activePublication === false || $activePublication === null) {
            throw new InsightException(
                'REFERENCE_DATA_UNAVAILABLE',
                'Die parlamentarischen Referenzdaten sind noch nicht verfügbar.',
                503,
            );
        }
        $publicId = bin2hex(random_bytes(13));
        $insert = $this->connection()->prepare(
            "INSERT INTO insight
             (public_id, owner_user_id, reference_publication_id, title, visibility, created_at, updated_at)
             VALUES (?, ?, ?, 'Unbenannter Insight', 'draft', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))"
        );
        $insert->execute([$publicId, $ownerId, (int) $activePublication]);
        return $this->ownerByPublicId($ownerId, $publicId);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function update(int $ownerId, string $publicId, array $input): array
    {
        $allowed = ['title', 'claim_text', 'explanatory_notes', 'visibility'];
        foreach (array_keys($input) as $field) {
            if (!in_array($field, $allowed, true)) {
                throw new InsightException('UNKNOWN_FIELD', 'Die Anfrage enthält ein unbekanntes Feld.');
            }
        }
        if ($input === []) {
            throw new InsightException('NO_CHANGES', 'Es wurden keine Änderungen übermittelt.');
        }
        $connection = $this->connection();
        $connection->beginTransaction();
        try {
            $current = $this->ownerRow($ownerId, $publicId, true);
        $title = array_key_exists('title', $input)
            ? $this->text($input['title'], 'Titel', 255, true)
            : $current['title'];
        $claim = array_key_exists('claim_text', $input)
            ? $this->text($input['claim_text'], 'Aussage', 5_000, true)
            : $current['claim_text'];
        $notes = array_key_exists('explanatory_notes', $input)
            ? $this->text($input['explanatory_notes'], 'Erläuterung', 20_000, true)
            : $current['explanatory_notes'];
        $visibility = array_key_exists('visibility', $input) ? $input['visibility'] : $current['visibility'];
        if (!is_string($visibility) || !in_array($visibility, ['draft', 'unlisted', 'public'], true)) {
            throw new InsightException('INVALID_VISIBILITY', 'Der Sichtbarkeitsstatus ist ungültig.');
        }
        if ($visibility === 'public' && ($title === null || $title === '' || $claim === null || $claim === '')) {
            throw new InsightException(
                'PUBLICATION_INCOMPLETE',
                'Für eine Veröffentlichung sind Titel und Aussage erforderlich.',
            );
        }
        if ($visibility === 'public') {
            foreach (['country_source_id', 'legislature_source_id', 'chamber_source_id', 'party_source_id', 'period_from', 'period_to'] as $field) {
                if ($current[$field] === null) {
                    throw new InsightException(
                        'PUBLICATION_INCOMPLETE',
                        'Für eine Veröffentlichung ist ein vollständiger parlamentarischer Rahmen erforderlich.',
                    );
                }
            }
            $counts = $connection->prepare(
                'SELECT
                   (SELECT COUNT(*) FROM insight_member WHERE insight_id=?) member_count,
                   (SELECT COUNT(*) FROM insight_vote_evidence WHERE insight_id=?) evidence_count,
                   (SELECT COUNT(DISTINCT evidence.voting_event_source_id)
                    FROM insight_vote_evidence evidence
                    JOIN insight_member member ON member.insight_id=evidence.insight_id
                    JOIN ref_voting_event event ON event.publication_id=evidence.reference_publication_id
                      AND event.source_id=evidence.voting_event_source_id
                    JOIN ref_voting_choice choice ON choice.publication_id=evidence.reference_publication_id
                      AND choice.voting_event_source_id=evidence.voting_event_source_id
                      AND choice.person_source_id=member.person_source_id
                      AND choice.normalized_choice<>\'not_participating\'
                    JOIN ref_person_mandate mandate ON mandate.publication_id=evidence.reference_publication_id
                      AND mandate.person_source_id=member.person_source_id
                      AND mandate.chamber_source_id=event.chamber_source_id
                      AND COALESCE(mandate.date_from, \'0001-01-01\')<=DATE(event.occurred_at)
                      AND COALESCE(mandate.date_to, \'9999-12-31\')>=DATE(event.occurred_at)
                    WHERE evidence.insight_id=?) participating_evidence_count'
            );
            $counts->execute([$current['id'], $current['id'], $current['id']]);
            $publicationCounts = $counts->fetch();
            if ((int) $publicationCounts['member_count'] === 0 || (int) $publicationCounts['evidence_count'] === 0) {
                throw new InsightException(
                    'PUBLICATION_INCOMPLETE',
                    'Wähle für eine Veröffentlichung mindestens ein Mitglied und eine Abstimmung aus.',
                );
            }
            if ((int) $publicationCounts['participating_evidence_count'] !== (int) $publicationCounts['evidence_count']) {
                throw new InsightException(
                    'EVIDENCE_WITHOUT_PARTICIPATION',
                    'Mindestens eine ausgewählte Abstimmung hat keine aufgezeichnete Teilnahme im aktuellen Mitgliederkreis.',
                );
            }
        }

        $shareToken = null;
        $shareHash = $current['share_token_hash'];
        if (array_key_exists('visibility', $input)) {
            if ($visibility === 'unlisted') {
                $shareToken = $this->newShareToken();
                $shareHash = hash('sha256', $shareToken);
            } else {
                $shareHash = null;
            }
        }
        $publishedAtSql = $visibility === 'public' ? 'COALESCE(published_at, UTC_TIMESTAMP(6))' : 'NULL';
        $update = $connection->prepare(
            "UPDATE insight SET title=?, claim_text=?, explanatory_notes=?, visibility=?,
             share_token_hash=?, published_at=$publishedAtSql, updated_at=UTC_TIMESTAMP(6)
             WHERE id=? AND owner_user_id=? AND archived_at IS NULL"
        );
        $update->execute([$title, $claim, $notes, $visibility, $shareHash, $current['id'], $ownerId]);
            $connection->commit();
        } catch (Throwable $error) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $error;
        }
        $result = $this->ownerByPublicId($ownerId, $publicId);
        if ($shareToken !== null) {
            $result['share_url'] = $this->appUrl . '/geteilt/' . $shareToken;
        }
        return $result;
    }

    public function archive(int $ownerId, string $publicId): void
    {
        $statement = $this->connection()->prepare(
            "UPDATE insight SET visibility='draft', share_token_hash=NULL, published_at=NULL,
             archived_at=UTC_TIMESTAMP(6), updated_at=UTC_TIMESTAMP(6)
             WHERE public_id=? AND owner_user_id=? AND archived_at IS NULL"
        );
        $statement->execute([$publicId, $ownerId]);
        if ($statement->rowCount() !== 1) {
            throw $this->notFound();
        }
    }

    /** @return array<string,mixed> */
    private function ownerByPublicId(int $ownerId, string $publicId): array
    {
        $statement = $this->connection()->prepare(
            $this->baseSelect() . ' WHERE insight.public_id=? AND insight.owner_user_id=? AND insight.archived_at IS NULL'
        );
        $statement->execute([$publicId, $ownerId]);
        $rows = $statement->fetchAll();
        if ($rows === []) {
            throw $this->notFound();
        }
        return $this->hydrate($rows, true)[0];
    }

    /** @return array<string,mixed> */
    private function ownerRow(int $ownerId, string $publicId, bool $forUpdate): array
    {
        $statement = $this->connection()->prepare(
            'SELECT * FROM insight WHERE public_id=? AND owner_user_id=? AND archived_at IS NULL'
            . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute([$publicId, $ownerId]);
        $row = $statement->fetch();
        if ($row === false) {
            throw $this->notFound();
        }
        return $row;
    }

    private function baseSelect(): string
    {
        return "SELECT insight.*, owner.display_name author_name,
                country.name_de country_name, chamber.name chamber_name, party.name party_name,
                (SELECT COUNT(*) FROM insight_member member WHERE member.insight_id=insight.id) member_count,
                (SELECT COUNT(*) FROM insight_vote_evidence evidence WHERE evidence.insight_id=insight.id) evidence_count,
                (SELECT COUNT(*) FROM insight_campaign_context context WHERE context.insight_id=insight.id) context_count
                FROM insight
                JOIN app_user owner ON owner.id=insight.owner_user_id
                LEFT JOIN ref_country country ON country.publication_id=insight.reference_publication_id
                  AND country.source_id=insight.country_source_id
                LEFT JOIN ref_chamber chamber ON chamber.publication_id=insight.reference_publication_id
                  AND chamber.source_id=insight.chamber_source_id
                LEFT JOIN ref_party party ON party.publication_id=insight.reference_publication_id
                  AND party.source_id=insight.party_source_id";
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private function hydrate(array $rows, bool $ownerView): array
    {
        if ($rows === []) {
            return [];
        }
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $votes = $this->connection()->prepare(
            "SELECT evidence.insight_id, evidence.position, event.source_identifier voting_identifier,
                    event.occurred_at, event.vote_type, event.division_text, event.submission_text,
                    event.meaning_yes, event.meaning_no, matter.formatted_identifier affair_identifier,
                    matter.title
             FROM insight_vote_evidence evidence
             JOIN ref_voting_event event ON event.publication_id=evidence.reference_publication_id
               AND event.source_id=evidence.voting_event_source_id
             LEFT JOIN ref_matter matter ON matter.publication_id=event.publication_id
               AND matter.source_id=event.matter_source_id
             WHERE evidence.insight_id IN ($placeholders)
             ORDER BY evidence.insight_id, evidence.position"
        );
        $votes->execute($ids);
        $byInsight = [];
        foreach ($votes->fetchAll() as $vote) {
            $byInsight[(int) $vote['insight_id']][] = [
                'position' => (int) $vote['position'],
                'voting_identifier' => $vote['voting_identifier'],
                'affair_identifier' => $vote['affair_identifier'],
                'occurred_at' => $vote['occurred_at'],
                'vote_type' => $vote['vote_type'],
                'title' => $vote['title'] ?? $vote['division_text'] ?? $vote['submission_text'],
                'exact_question' => $vote['submission_text'] ?? $vote['division_text'],
                'meaning_yes' => $vote['meaning_yes'],
                'meaning_no' => $vote['meaning_no'],
            ];
        }

        return array_map(function (array $row) use ($ownerView, $byInsight): array {
            $item = [
                'public_id' => $row['public_id'],
                'title' => $row['title'],
                'claim_text' => $row['claim_text'],
                'explanatory_notes' => $row['explanatory_notes'],
                'visibility' => $row['visibility'],
                'author' => $row['author_name'],
                'scope' => [
                    'country' => $row['country_name'],
                    'chamber' => $row['chamber_name'],
                    'party' => $row['party_name'],
                    'period_from' => $row['period_from'],
                    'period_to' => $row['period_to'],
                ],
                'member_count' => (int) $row['member_count'],
                'evidence_count' => (int) $row['evidence_count'],
                'campaign_context_count' => (int) $row['context_count'],
                'votes' => $byInsight[(int) $row['id']] ?? [],
                'published_at' => $row['published_at'],
                'updated_at' => $row['updated_at'],
            ];
            if ($ownerView) {
                $item['has_share_link'] = $row['share_token_hash'] !== null;
            }
            return $item;
        }, $rows);
    }

    /** @param list<array<string,mixed>> $rows @return array{items:list<array<string,mixed>>,pagination:array<string,int>} */
    private function pageResult(array $rows, int $page, int $perPage, int $total, bool $ownerView): array
    {
        return [
            'items' => $this->hydrate($rows, $ownerView),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $total === 0 ? 0 : (int) ceil($total / $perPage),
            ],
        ];
    }

    /** @return array{int,int,int} */
    private function pagination(int $page, int $perPage): array
    {
        if ($page < 1 || $page > 10_000 || $perPage < 1 || $perPage > 24) {
            throw new InsightException('INVALID_PAGINATION', 'Die Seitennummerierung ist ungültig.');
        }
        return [$page, $perPage, ($page - 1) * $perPage];
    }

    private function text(mixed $value, string $label, int $maxLength, bool $nullable): ?string
    {
        if ($value === null && $nullable) {
            return null;
        }
        if (!is_string($value)) {
            throw new InsightException('INVALID_TEXT', sprintf('%s muss Text sein.', $label));
        }
        $value = trim($value);
        if (strlen($value) > $maxLength) {
            throw new InsightException('TEXT_TOO_LONG', sprintf('%s ist zu lang.', $label));
        }
        return $value === '' && $nullable ? null : $value;
    }

    private function newShareToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function notFound(): InsightException
    {
        return new InsightException('INSIGHT_NOT_FOUND', 'Der Insight wurde nicht gefunden.', 404);
    }

    private function connection(): PDO
    {
        return $this->connection ??= ($this->connectionFactory)();
    }
}
