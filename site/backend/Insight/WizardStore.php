<?php

declare(strict_types=1);

namespace Politiks\App\Insight;

use Closure;
use DateTimeImmutable;
use PDO;
use Throwable;

final class WizardStore
{
    private ?PDO $connection = null;

    /** @param Closure():PDO $connectionFactory */
    public function __construct(private readonly Closure $connectionFactory)
    {
    }

    /** @return array<string,mixed> */
    public function state(int $ownerId, string $publicId): array
    {
        $insight = $this->ownerRow($ownerId, $publicId);
        $publicationId = (int) $insight['reference_publication_id'];
        $members = $this->connection()->prepare(
            'SELECT member.person_source_id, person.display_name, member.position
             FROM insight_member member
             JOIN ref_person person ON person.publication_id=member.reference_publication_id
               AND person.source_id=member.person_source_id
             WHERE member.insight_id=? ORDER BY member.position'
        );
        $members->execute([$insight['id']]);
        $evidence = $this->connection()->prepare(
            'SELECT voting_event_source_id, position FROM insight_vote_evidence
             WHERE insight_id=? ORDER BY position'
        );
        $evidence->execute([$insight['id']]);

        return [
            'insight' => [
                'public_id' => $insight['public_id'],
                'title' => $insight['title'],
                'claim_text' => $insight['claim_text'],
                'explanatory_notes' => $insight['explanatory_notes'],
                'visibility' => $insight['visibility'],
                'scope' => [
                    'country_id' => $this->nullableInt($insight['country_source_id']),
                    'legislature_id' => $this->nullableInt($insight['legislature_source_id']),
                    'chamber_id' => $this->nullableInt($insight['chamber_source_id']),
                    'party_id' => $this->nullableInt($insight['party_source_id']),
                    'period_from' => $insight['period_from'],
                    'period_to' => $insight['period_to'],
                ],
                'members' => array_map(static fn (array $row): array => [
                    'id' => (int) $row['person_source_id'],
                    'name' => $row['display_name'],
                    'position' => (int) $row['position'],
                ], $members->fetchAll()),
                'evidence_ids' => array_map('intval', $evidence->fetchAll(PDO::FETCH_COLUMN)),
            ],
            'options' => $this->options($publicationId),
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function saveScope(int $ownerId, string $publicId, array $input): array
    {
        $required = ['country_id', 'legislature_id', 'chamber_id', 'party_id', 'period_from', 'period_to'];
        foreach ($required as $field) {
            if (!array_key_exists($field, $input)) {
                throw new InsightException('SCOPE_INCOMPLETE', 'Der parlamentarische Rahmen ist unvollständig.');
            }
        }
        $ids = [];
        foreach (array_slice($required, 0, 4) as $field) {
            $value = $input[$field];
            if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
                throw new InsightException('INVALID_SCOPE', 'Der parlamentarische Rahmen ist ungültig.');
            }
            $ids[$field] = (int) $value;
        }
        $from = $this->date($input['period_from'], 'Startdatum');
        $to = $this->date($input['period_to'], 'Enddatum');
        if ($from > $to) {
            throw new InsightException('INVALID_PERIOD', 'Das Startdatum muss vor dem Enddatum liegen.');
        }
        $insight = $this->ownerRow($ownerId, $publicId);
        $valid = $this->connection()->prepare(
            'SELECT COUNT(*) FROM ref_country country
             JOIN ref_legislature legislature ON legislature.publication_id=country.publication_id
               AND legislature.country_source_id=country.source_id
             JOIN ref_chamber chamber ON chamber.publication_id=legislature.publication_id
               AND chamber.legislature_source_id=legislature.source_id
             JOIN ref_party party ON party.publication_id=country.publication_id
               AND party.country_source_id=country.source_id
             WHERE country.publication_id=? AND country.source_id=? AND legislature.source_id=?
               AND chamber.source_id=? AND party.source_id=?'
        );
        $valid->execute([
            $insight['reference_publication_id'], $ids['country_id'], $ids['legislature_id'],
            $ids['chamber_id'], $ids['party_id'],
        ]);
        if ((int) $valid->fetchColumn() !== 1) {
            throw new InsightException('INVALID_SCOPE', 'Die gewählte Kombination ist nicht verfügbar.');
        }
        $scopeChanged = (int) ($insight['country_source_id'] ?? 0) !== $ids['country_id']
            || (int) ($insight['legislature_source_id'] ?? 0) !== $ids['legislature_id']
            || (int) ($insight['chamber_source_id'] ?? 0) !== $ids['chamber_id']
            || (int) ($insight['party_source_id'] ?? 0) !== $ids['party_id']
            || $insight['period_from'] !== $from || $insight['period_to'] !== $to;
        $pdo = $this->connection();
        $pdo->beginTransaction();
        $update = $pdo->prepare(
            'UPDATE insight SET country_source_id=?, legislature_source_id=?, chamber_source_id=?,
             party_source_id=?, period_from=?, period_to=?, updated_at=UTC_TIMESTAMP(6)
             WHERE id=? AND owner_user_id=?'
        );
        try {
            $update->execute([
                $ids['country_id'], $ids['legislature_id'], $ids['chamber_id'], $ids['party_id'],
                $from, $to, $insight['id'], $ownerId,
            ]);
            if ($scopeChanged) {
                $pdo->prepare('DELETE FROM insight_vote_evidence WHERE insight_id=?')->execute([$insight['id']]);
                $pdo->prepare('DELETE FROM insight_member WHERE insight_id=?')->execute([$insight['id']]);
            }
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
        return $this->state($ownerId, $publicId)['insight']['scope'];
    }

    /** @return list<array<string,mixed>> */
    public function eligibleMembers(int $ownerId, string $publicId): array
    {
        $insight = $this->ownerRow($ownerId, $publicId);
        $this->requireScope($insight);
        return $this->eligibleMemberRows($insight);
    }

    /** @param mixed $memberIds @return list<int> */
    public function saveMembers(int $ownerId, string $publicId, mixed $memberIds): array
    {
        $ids = $this->integerList($memberIds, 200, 'Mitglieder');
        if ($ids === []) {
            throw new InsightException('MEMBERS_REQUIRED', 'Wähle mindestens ein Mitglied aus.');
        }
        $insight = $this->ownerRow($ownerId, $publicId);
        $this->requireScope($insight);
        $eligible = array_column($this->eligibleMemberRows($insight), 'id');
        if (array_diff($ids, $eligible) !== []) {
            throw new InsightException('INVALID_MEMBER', 'Mindestens ein Mitglied gehört nicht zum gewählten Rahmen.');
        }
        $pdo = $this->connection();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM insight_member WHERE insight_id=?')->execute([$insight['id']]);
            $insert = $pdo->prepare(
                'INSERT INTO insight_member
                 (insight_id, reference_publication_id, person_source_id, position, created_at)
                 VALUES (?, ?, ?, ?, UTC_TIMESTAMP(6))'
            );
            foreach ($ids as $position => $id) {
                $insert->execute([$insight['id'], $insight['reference_publication_id'], $id, $position + 1]);
            }
            $pdo->prepare('UPDATE insight SET updated_at=UTC_TIMESTAMP(6) WHERE id=?')->execute([$insight['id']]);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
        return $ids;
    }

    /** @param mixed $eventIds @return list<int> */
    public function saveEvidence(int $ownerId, string $publicId, mixed $eventIds): array
    {
        $ids = $this->integerList($eventIds, 50, 'Abstimmungen');
        $insight = $this->ownerRow($ownerId, $publicId);
        $this->requireScope($insight);
        if ($ids !== []) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $check = $this->connection()->prepare(
                "SELECT COUNT(*) FROM ref_voting_event WHERE publication_id=? AND source_id IN ($placeholders)
                 AND chamber_source_id=? AND DATE(occurred_at) BETWEEN ? AND ?"
            );
            $check->execute([
                $insight['reference_publication_id'], ...$ids, $insight['chamber_source_id'],
                $insight['period_from'], $insight['period_to'],
            ]);
            if ((int) $check->fetchColumn() !== count($ids)) {
                throw new InsightException('INVALID_EVIDENCE', 'Mindestens eine Abstimmung gehört nicht zum gewählten Rahmen.');
            }
        }
        $pdo = $this->connection();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM insight_vote_evidence WHERE insight_id=?')->execute([$insight['id']]);
            $insert = $pdo->prepare(
                'INSERT INTO insight_vote_evidence
                 (insight_id, reference_publication_id, voting_event_source_id, position, created_at)
                 VALUES (?, ?, ?, ?, UTC_TIMESTAMP(6))'
            );
            foreach ($ids as $position => $id) {
                $insert->execute([$insight['id'], $insight['reference_publication_id'], $id, $position + 1]);
            }
            $pdo->prepare('UPDATE insight SET updated_at=UTC_TIMESTAMP(6) WHERE id=?')->execute([$insight['id']]);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
        return $ids;
    }

    /** @param mixed $memberIds @param mixed $eventIds @return array{items:list<array<string,mixed>>,total:int,limited:bool} */
    public function votes(int $ownerId, string $publicId, mixed $memberIds, mixed $query, mixed $eventIds = null): array
    {
        $ids = $this->integerList($memberIds, 200, 'Mitglieder');
        if ($ids === []) {
            throw new InsightException('MEMBERS_REQUIRED', 'Wähle mindestens ein Mitglied für die Auswertung aus.');
        }
        if (!is_string($query)) {
            throw new InsightException('INVALID_SEARCH', 'Der Suchbegriff ist ungültig.');
        }
        $query = trim($query);
        if (strlen($query) > 200) {
            throw new InsightException('INVALID_SEARCH', 'Der Suchbegriff ist zu lang.');
        }
        $insight = $this->ownerRow($ownerId, $publicId);
        $this->requireScope($insight);
        $eligible = array_column($this->eligibleMemberRows($insight), 'id');
        if (array_diff($ids, $eligible) !== []) {
            throw new InsightException('INVALID_MEMBER', 'Die Auswertung enthält ein Mitglied ausserhalb des Rahmens.');
        }

        $evidenceStatement = $this->connection()->prepare(
            'SELECT voting_event_source_id FROM insight_vote_evidence WHERE insight_id=? ORDER BY position'
        );
        $evidenceStatement->execute([$insight['id']]);
        $selectedEvidence = array_map('intval', $evidenceStatement->fetchAll(PDO::FETCH_COLUMN));
        $filterEventIds = $eventIds === null ? null : $this->integerList($eventIds, 300, 'Abstimmungen');
        if ($filterEventIds === [] && $selectedEvidence === []) {
            return ['items' => [], 'total' => 0, 'limited' => false];
        }

        $memberPlaceholders = implode(',', array_fill(0, count($ids), '?'));
        $participationSql = " AND (EXISTS (
            SELECT 1 FROM ref_voting_choice cohort_choice
            WHERE cohort_choice.publication_id=event.publication_id
              AND cohort_choice.voting_event_source_id=event.source_id
              AND cohort_choice.person_source_id IN ($memberPlaceholders)
              AND cohort_choice.normalized_choice<>'not_participating'";
        $parameters = [
            $insight['reference_publication_id'], $insight['chamber_source_id'],
            $insight['period_from'], $insight['period_to'],
            ...$ids,
        ];
        $participationSql .= ')';
        if ($selectedEvidence !== []) {
            $participationSql .= ' OR event.source_id IN (' . implode(',', array_fill(0, count($selectedEvidence), '?')) . ')';
            array_push($parameters, ...$selectedEvidence);
        }
        $participationSql .= ')';

        $eventFilterSql = '';
        if ($filterEventIds !== null) {
            $eventFilters = [];
            if ($filterEventIds !== []) {
                $eventFilters[] = 'event.source_id IN ('
                    . implode(',', array_fill(0, count($filterEventIds), '?')) . ')';
                array_push($parameters, ...$filterEventIds);
            }
            if ($selectedEvidence !== []) {
                $eventFilters[] = 'event.source_id IN ('
                    . implode(',', array_fill(0, count($selectedEvidence), '?')) . ')';
                array_push($parameters, ...$selectedEvidence);
            }
            $eventFilterSql = ' AND (' . implode(' OR ', $eventFilters) . ')';
        }

        $searchSql = '';
        if ($query !== '') {
            $searchSql = ' AND ((document.voting_identifier=? OR document.affair_identifier=?
                OR event.registration_number=? OR document.full_text LIKE ?)';
            array_push($parameters, $query, $query, $query, '%' . $query . '%');
            if ($selectedEvidence !== []) {
                $searchSql .= ' OR event.source_id IN (' . implode(',', array_fill(0, count($selectedEvidence), '?')) . ')';
                array_push($parameters, ...$selectedEvidence);
            }
            $searchSql .= ')';
        }
        $events = $this->connection()->prepare(
            "SELECT event.source_id, document.voting_identifier, document.affair_identifier,
                    event.registration_number, event.occurred_at, event.vote_type, event.division_text,
                    event.submission_text, event.meaning_yes, event.meaning_no, event.overall_decision,
                    event.provenance_url, event.provenance_sha256, matter.title, chamber.name chamber_name,
                    MAX(document.full_text) match_text,
                    GROUP_CONCAT(DISTINCT topic.name ORDER BY topic.name SEPARATOR ' · ') official_topics,
                    GROUP_CONCAT(DISTINCT term.name_de ORDER BY term.sort_order SEPARATOR ' · ') reviewed_terms
             FROM ref_voting_event event
             JOIN ref_vote_search_document document ON document.publication_id=event.publication_id
               AND document.voting_event_source_id=event.source_id
             LEFT JOIN ref_matter matter ON matter.publication_id=event.publication_id
               AND matter.source_id=event.matter_source_id
             LEFT JOIN ref_chamber chamber ON chamber.publication_id=event.publication_id
               AND chamber.source_id=event.chamber_source_id
             LEFT JOIN ref_matter_topic matter_topic ON matter_topic.publication_id=matter.publication_id
               AND matter_topic.matter_source_id=matter.source_id
             LEFT JOIN ref_official_topic topic ON topic.publication_id=matter_topic.publication_id
               AND topic.source_id=matter_topic.topic_source_id
             LEFT JOIN ref_reviewed_classification reviewed ON reviewed.publication_id=event.publication_id
               AND (reviewed.voting_event_source_id=event.source_id OR reviewed.matter_source_id=matter.source_id)
             LEFT JOIN ref_taxonomy_term term ON term.publication_id=reviewed.publication_id
               AND term.source_id=reviewed.taxonomy_term_source_id
             WHERE event.publication_id=? AND event.chamber_source_id=?
               AND DATE(event.occurred_at) BETWEEN ? AND ? $participationSql $eventFilterSql $searchSql
             GROUP BY event.publication_id, event.source_id, document.voting_identifier,
                      document.affair_identifier, event.registration_number, event.occurred_at,
                      event.vote_type, event.division_text, event.submission_text, event.meaning_yes,
                      event.meaning_no, event.overall_decision, event.provenance_url,
                      event.provenance_sha256, matter.title, chamber.name
             ORDER BY CASE WHEN event.vote_type IN ('final_vote','overall_vote') THEN 0 ELSE 1 END,
                      event.occurred_at DESC, event.source_id DESC LIMIT 101"
        );
        $events->execute($parameters);
        $eventRows = $events->fetchAll();
        $limited = count($eventRows) > 100;
        $eventRows = array_slice($eventRows, 0, 100);
        if ($eventRows === []) {
            return ['items' => [], 'total' => 0, 'limited' => false];
        }

        $eventIds = array_map(static fn (array $row): int => (int) $row['source_id'], $eventRows);
        $eventPlaceholders = implode(',', array_fill(0, count($eventIds), '?'));
        $choices = $this->connection()->prepare(
            "SELECT choice.voting_event_source_id, choice.person_source_id, choice.normalized_choice,
                    choice.raw_decision, person.display_name,
                    (SELECT party.name FROM ref_person_party_membership membership
                     JOIN ref_party party ON party.publication_id=membership.publication_id
                       AND party.source_id=membership.party_source_id
                     JOIN ref_voting_event dated_event ON dated_event.publication_id=choice.publication_id
                       AND dated_event.source_id=choice.voting_event_source_id
                     WHERE membership.publication_id=choice.publication_id
                       AND membership.person_source_id=choice.person_source_id
                       AND COALESCE(membership.date_from, '0001-01-01')<=DATE(dated_event.occurred_at)
                       AND COALESCE(membership.date_to, '9999-12-31')>=DATE(dated_event.occurred_at)
                     ORDER BY membership.date_from DESC LIMIT 1) party_name,
                    (SELECT faction.name FROM ref_person_faction_membership membership
                     JOIN ref_faction faction ON faction.publication_id=membership.publication_id
                       AND faction.source_id=membership.faction_source_id
                     JOIN ref_voting_event dated_event ON dated_event.publication_id=choice.publication_id
                       AND dated_event.source_id=choice.voting_event_source_id
                     WHERE membership.publication_id=choice.publication_id
                       AND membership.person_source_id=choice.person_source_id
                       AND COALESCE(membership.date_from, '0001-01-01')<=DATE(dated_event.occurred_at)
                       AND COALESCE(membership.date_to, '9999-12-31')>=DATE(dated_event.occurred_at)
                     ORDER BY membership.date_from DESC LIMIT 1) faction_name
             FROM ref_voting_choice choice
             JOIN ref_person person ON person.publication_id=choice.publication_id
               AND person.source_id=choice.person_source_id
             WHERE choice.publication_id=? AND choice.voting_event_source_id IN ($eventPlaceholders)
               AND choice.person_source_id IN ($memberPlaceholders)"
        );
        $choices->execute([$insight['reference_publication_id'], ...$eventIds, ...$ids]);
        $choiceMap = [];
        foreach ($choices->fetchAll() as $choice) {
            $choiceMap[(int) $choice['voting_event_source_id']][(int) $choice['person_source_id']] = $choice;
        }
        $memberRows = $this->memberNamesAndMandates(
            (int) $insight['reference_publication_id'], (int) $insight['chamber_source_id'], $ids
        );

        $items = array_map(function (array $event) use ($ids, $choiceMap, $memberRows, $query): array {
            $eventId = (int) $event['source_id'];
            $date = substr((string) $event['occurred_at'], 0, 10);
            $counts = ['yes' => 0, 'no' => 0, 'abstain' => 0, 'other' => 0, 'not_participating' => 0, 'no_mandate' => 0];
            $selectedMembers = [];
            foreach ($ids as $memberId) {
                $member = $memberRows[$memberId];
                $hasMandate = false;
                foreach ($member['mandates'] as $mandate) {
                    if (($mandate['date_from'] === null || $mandate['date_from'] <= $date)
                        && ($mandate['date_to'] === null || $mandate['date_to'] >= $date)) {
                        $hasMandate = true;
                        break;
                    }
                }
                $choice = $choiceMap[$eventId][$memberId] ?? null;
                $normalized = $hasMandate ? ($choice['normalized_choice'] ?? 'not_participating') : 'no_mandate';
                if (!array_key_exists($normalized, $counts)) {
                    $normalized = 'other';
                }
                ++$counts[$normalized];
                $selectedMembers[] = [
                    'id' => $memberId,
                    'name' => $member['name'],
                    'choice' => $normalized,
                    'raw_decision' => $choice['raw_decision'] ?? null,
                    'party' => $choice['party_name'] ?? null,
                    'faction' => $choice['faction_name'] ?? null,
                ];
            }
            $direction = 'non_directional';
            if ($counts['yes'] > $counts['no']) {
                $direction = 'yes';
            } elseif ($counts['no'] > $counts['yes']) {
                $direction = 'no';
            } elseif ($counts['yes'] + $counts['no'] > 0) {
                $direction = 'split';
            }
            $directional = $counts['yes'] + $counts['no'];
            return [
                'id' => $eventId,
                'voting_identifier' => $event['voting_identifier'],
                'affair_identifier' => $event['affair_identifier'],
                'registration_number' => $event['registration_number'],
                'occurred_at' => $event['occurred_at'],
                'vote_type' => $event['vote_type'],
                'title' => $event['title'] ?? $event['division_text'] ?? $event['submission_text'] ?? 'Abstimmung ohne Titel',
                'exact_question' => $event['submission_text'] ?? $event['division_text'],
                'meaning_yes' => $event['meaning_yes'],
                'meaning_no' => $event['meaning_no'],
                'overall_decision' => $event['overall_decision'],
                'chamber' => $event['chamber_name'],
                'match_context' => $this->matchContext((string) $event['match_text'], $query),
                'official_topics' => $event['official_topics'] ? explode(' · ', $event['official_topics']) : [],
                'reviewed_classifications' => $event['reviewed_terms'] ? explode(' · ', $event['reviewed_terms']) : [],
                'provenance_url' => $event['provenance_url'],
                'provenance_sha256' => $event['provenance_sha256'],
                'direction' => $direction,
                'counts' => $counts,
                'eligible_count' => count($ids) - $counts['no_mandate'],
                'participating_count' => count($ids) - $counts['no_mandate'] - $counts['not_participating'],
                'cohesion' => $directional === 0 ? null : max($counts['yes'], $counts['no']) / $directional,
                'members' => $selectedMembers,
            ];
        }, $eventRows);
        $items = array_values(array_filter(
            $items,
            static fn (array $item): bool => $item['participating_count'] > 0
                || in_array($item['id'], $selectedEvidence, true),
        ));
        foreach ($items as &$item) {
            $item['participation_warning'] = $item['participating_count'] === 0
                && in_array($item['id'], $selectedEvidence, true);
        }
        unset($item);
        return ['items' => $items, 'total' => count($items), 'limited' => $limited];
    }

    private function matchContext(string $text, string $query): ?string
    {
        if ($query === '') {
            return null;
        }
        $position = stripos($text, $query);
        if ($position === false) {
            return null;
        }
        $start = max(0, $position - 90);
        $context = trim(substr($text, $start, strlen($query) + 180));
        return ($start > 0 ? '… ' : '') . $context
            . ($start + strlen($context) < strlen($text) ? ' …' : '');
    }

    /** @return array<string,list<array<string,mixed>>|array<string,mixed>> */
    private function options(int $publicationId): array
    {
        $query = function (string $sql) use ($publicationId): array {
            $statement = $this->connection()->prepare($sql);
            $statement->execute([$publicationId]);
            return array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'name' => $row['name'],
                ...isset($row['parent_id']) ? ['parent_id' => (int) $row['parent_id']] : [],
            ], $statement->fetchAll());
        };
        $range = $this->connection()->prepare(
            'SELECT MIN(DATE(occurred_at)) date_from, MAX(DATE(occurred_at)) date_to
             FROM ref_voting_event WHERE publication_id=?'
        );
        $range->execute([$publicationId]);
        return [
            'countries' => $query('SELECT source_id id, name_de name FROM ref_country WHERE publication_id=? ORDER BY name_de'),
            'legislatures' => $query('SELECT source_id id, country_source_id parent_id, name FROM ref_legislature WHERE publication_id=? ORDER BY name'),
            'chambers' => $query("SELECT source_id id, legislature_source_id parent_id, name FROM ref_chamber WHERE publication_id=? AND chamber_type<>'unknown' ORDER BY name"),
            'parties' => $query('SELECT source_id id, country_source_id parent_id, name FROM ref_party WHERE publication_id=? AND EXISTS (SELECT 1 FROM ref_person_party_membership membership WHERE membership.publication_id=ref_party.publication_id AND membership.party_source_id=ref_party.source_id) ORDER BY name'),
            'date_range' => $range->fetch(),
        ];
    }

    /** @param array<string,mixed> $insight @return list<array<string,mixed>> */
    private function eligibleMemberRows(array $insight): array
    {
        $statement = $this->connection()->prepare(
            "SELECT person.source_id id, person.display_name name,
                    GROUP_CONCAT(DISTINCT faction.name ORDER BY faction.name SEPARATOR ' · ') faction,
                    MIN(party_membership.date_from) party_from, MAX(party_membership.date_to) party_to
             FROM ref_person_party_membership party_membership
             JOIN ref_person person ON person.publication_id=party_membership.publication_id
               AND person.source_id=party_membership.person_source_id
             JOIN ref_person_mandate mandate ON mandate.publication_id=person.publication_id
               AND mandate.person_source_id=person.source_id AND mandate.chamber_source_id=?
               AND COALESCE(mandate.date_to, '9999-12-31')>=? AND COALESCE(mandate.date_from, '0001-01-01')<=?
             LEFT JOIN ref_person_faction_membership faction_membership
               ON faction_membership.publication_id=person.publication_id
               AND faction_membership.person_source_id=person.source_id
               AND COALESCE(faction_membership.date_to, '9999-12-31')>=?
               AND COALESCE(faction_membership.date_from, '0001-01-01')<=?
             LEFT JOIN ref_faction faction ON faction.publication_id=faction_membership.publication_id
               AND faction.source_id=faction_membership.faction_source_id
             WHERE party_membership.publication_id=? AND party_membership.party_source_id=?
               AND COALESCE(party_membership.date_to, '9999-12-31')>=?
               AND COALESCE(party_membership.date_from, '0001-01-01')<=?
             GROUP BY person.source_id, person.display_name ORDER BY person.display_name, person.source_id"
        );
        $statement->execute([
            $insight['chamber_source_id'], $insight['period_from'], $insight['period_to'],
            $insight['period_from'], $insight['period_to'], $insight['reference_publication_id'],
            $insight['party_source_id'], $insight['period_from'], $insight['period_to'],
        ]);
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'faction' => $row['faction'],
            'party_from' => $row['party_from'],
            'party_to' => $row['party_to'],
        ], $statement->fetchAll());
    }

    /** @param list<int> $ids @return array<int,array{name:string,mandates:list<array{date_from:?string,date_to:?string}>}> */
    private function memberNamesAndMandates(int $publicationId, int $chamberId, array $ids): array
    {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $this->connection()->prepare(
            "SELECT person.source_id, person.display_name, mandate.date_from, mandate.date_to
             FROM ref_person person
             LEFT JOIN ref_person_mandate mandate ON mandate.publication_id=person.publication_id
               AND mandate.person_source_id=person.source_id AND mandate.chamber_source_id=?
             WHERE person.publication_id=? AND person.source_id IN ($placeholders)"
        );
        $statement->execute([$chamberId, $publicationId, ...$ids]);
        $result = [];
        foreach ($statement->fetchAll() as $row) {
            $id = (int) $row['source_id'];
            $result[$id] ??= ['name' => $row['display_name'], 'mandates' => []];
            if ($row['date_from'] !== null || $row['date_to'] !== null) {
                $result[$id]['mandates'][] = ['date_from' => $row['date_from'], 'date_to' => $row['date_to']];
            }
        }
        return $result;
    }

    /** @return array<string,mixed> */
    private function ownerRow(int $ownerId, string $publicId): array
    {
        $statement = $this->connection()->prepare(
            'SELECT * FROM insight WHERE public_id=? AND owner_user_id=? AND archived_at IS NULL'
        );
        $statement->execute([$publicId, $ownerId]);
        $row = $statement->fetch();
        if ($row === false) {
            throw new InsightException('INSIGHT_NOT_FOUND', 'Der Insight wurde nicht gefunden.', 404);
        }
        return $row;
    }

    /** @param array<string,mixed> $insight */
    private function requireScope(array $insight): void
    {
        foreach (['country_source_id', 'legislature_source_id', 'chamber_source_id', 'party_source_id', 'period_from', 'period_to'] as $field) {
            if ($insight[$field] === null) {
                throw new InsightException('SCOPE_INCOMPLETE', 'Lege zuerst den parlamentarischen Rahmen fest.');
            }
        }
    }

    /** @return list<int> */
    private function integerList(mixed $values, int $maximum, string $label): array
    {
        if (!is_array($values) || count($values) > $maximum) {
            throw new InsightException('INVALID_SELECTION', $label . ' enthalten eine ungültige Auswahl.');
        }
        $result = [];
        foreach ($values as $value) {
            if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
                throw new InsightException('INVALID_SELECTION', $label . ' enthalten eine ungültige Auswahl.');
            }
            $result[] = (int) $value;
        }
        if (count(array_unique($result)) !== count($result)) {
            throw new InsightException('INVALID_SELECTION', $label . ' enthalten doppelte Einträge.');
        }
        return $result;
    }

    private function date(mixed $value, string $label): string
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw new InsightException('INVALID_DATE', $label . ' ist ungültig.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InsightException('INVALID_DATE', $label . ' ist ungültig.');
        }
        return $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private function connection(): PDO
    {
        return $this->connection ??= ($this->connectionFactory)();
    }
}
