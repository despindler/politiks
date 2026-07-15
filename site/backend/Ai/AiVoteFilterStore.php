<?php

declare(strict_types=1);

namespace Politiks\App\Ai;

use Closure;
use JsonException;
use PDO;
use Politiks\App\Insight\InsightException;

final class AiVoteFilterStore
{
    private ?PDO $connection = null;

    /** @param Closure():PDO $connectionFactory */
    public function __construct(private readonly Closure $connectionFactory)
    {
    }

    /** @return array{id:int,reference_publication_id:int,chamber_source_id:int,party_source_id:int,period_from:string,period_to:string} */
    public function ownedScope(int $ownerId, string $publicId): array
    {
        $statement = $this->connection()->prepare(
            'SELECT id, reference_publication_id, chamber_source_id, party_source_id, period_from, period_to
             FROM insight WHERE public_id=? AND owner_user_id=? AND archived_at IS NULL'
        );
        $statement->execute([$publicId, $ownerId]);
        $row = $statement->fetch();
        if ($row === false) {
            throw new InsightException('INSIGHT_NOT_FOUND', 'Der Insight wurde nicht gefunden.', 404);
        }
        foreach (['chamber_source_id', 'party_source_id', 'period_from', 'period_to'] as $field) {
            if ($row[$field] === null) {
                throw new InsightException('SCOPE_INCOMPLETE', 'Lege zuerst den parlamentarischen Rahmen fest.');
            }
        }
        return [
            'id' => (int) $row['id'],
            'reference_publication_id' => (int) $row['reference_publication_id'],
            'chamber_source_id' => (int) $row['chamber_source_id'],
            'party_source_id' => (int) $row['party_source_id'],
            'period_from' => (string) $row['period_from'],
            'period_to' => (string) $row['period_to'],
        ];
    }

    /** @param list<int> $memberIds */
    public function assertEligibleCohort(array $scope, array $memberIds): void
    {
        $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
        $statement = $this->connection()->prepare(
            "SELECT DISTINCT person.source_id
             FROM ref_person person
             JOIN ref_person_party_membership party_membership
               ON party_membership.publication_id=person.publication_id
              AND party_membership.person_source_id=person.source_id
              AND party_membership.party_source_id=?
              AND COALESCE(party_membership.date_to, '9999-12-31')>=?
              AND COALESCE(party_membership.date_from, '0001-01-01')<=?
             JOIN ref_person_mandate mandate
               ON mandate.publication_id=person.publication_id
              AND mandate.person_source_id=person.source_id
              AND mandate.chamber_source_id=?
              AND COALESCE(mandate.date_to, '9999-12-31')>=?
              AND COALESCE(mandate.date_from, '0001-01-01')<=?
             WHERE person.publication_id=? AND person.source_id IN ($placeholders)"
        );
        $statement->execute([
            $scope['party_source_id'], $scope['period_from'], $scope['period_to'],
            $scope['chamber_source_id'], $scope['period_from'], $scope['period_to'],
            $scope['reference_publication_id'], ...$memberIds,
        ]);
        $eligible = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
        sort($eligible);
        $expected = $memberIds;
        sort($expected);
        if ($eligible !== $expected) {
            throw new InsightException('INVALID_MEMBER', 'Die KI-Auswertung enthält ein Mitglied ausserhalb des Rahmens.');
        }
    }

    public function recentBillableRuns(int $ownerId): int
    {
        $statement = $this->connection()->prepare(
            "SELECT COUNT(*) FROM ai_filter_run
             WHERE owner_user_id=? AND created_at>=DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 HOUR)
               AND status<>'rate_limited' AND cache_hit=0"
        );
        $statement->execute([$ownerId]);
        return (int) $statement->fetchColumn();
    }

    /** @return null|array<string,mixed> */
    public function cached(
        int $ownerId,
        array $scope,
        int $promptId,
        string $model,
        string $criteriaHash,
        string $cohortHash,
    ): ?array {
        $statement = $this->connection()->prepare(
            'SELECT result_json, candidate_sha256 FROM ai_filter_cache
             WHERE owner_user_id=? AND insight_id=? AND reference_publication_id=?
               AND prompt_template_id=? AND model=? AND criteria_sha256=? AND cohort_sha256=?
               AND expires_at>UTC_TIMESTAMP(6)
             ORDER BY created_at DESC LIMIT 1'
        );
        $statement->execute([
            $ownerId, $scope['id'], $scope['reference_publication_id'], $promptId,
            $model, $criteriaHash, $cohortHash,
        ]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }
        try {
            $result = json_decode((string) $row['result_json'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new AiFilterException('AI_CACHE_INVALID', 'Der gespeicherte KI-Filter ist ungültig.', 500, $error);
        }
        if (!is_array($result)) {
            throw new AiFilterException('AI_CACHE_INVALID', 'Der gespeicherte KI-Filter ist ungültig.', 500);
        }
        $result['cache_hit'] = true;
        $result['_candidate_hash'] = (string) $row['candidate_sha256'];
        return $result;
    }

    public function startRun(
        string $requestId,
        int $ownerId,
        array $scope,
        int $promptId,
        string $model,
        string $criteriaHash,
        string $cohortHash,
        string $status = 'started',
    ): void {
        $emptyCandidates = hash('sha256', '[]');
        $statement = $this->connection()->prepare(
            "INSERT INTO ai_filter_run
             (request_id, owner_user_id, insight_id, reference_publication_id, prompt_template_id,
              model, criteria_sha256, candidate_sha256, cohort_sha256, status, cache_hit,
              candidate_count, matched_count, ambiguous_count, created_at, completed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 0, 0, UTC_TIMESTAMP(6),
                     CASE WHEN ?='started' THEN NULL ELSE UTC_TIMESTAMP(6) END)"
        );
        $statement->execute([
            $requestId, $ownerId, $scope['id'], $scope['reference_publication_id'], $promptId,
            $model, $criteriaHash, $emptyCandidates, $cohortHash, $status, $status,
        ]);
    }

    /** @param array<string,mixed> $cached */
    public function recordCacheHit(
        string $requestId,
        int $ownerId,
        array $scope,
        int $promptId,
        string $model,
        string $criteriaHash,
        string $cohortHash,
        string $candidateHash,
        array $cached,
    ): void {
        $statement = $this->connection()->prepare(
            "INSERT INTO ai_filter_run
             (request_id, owner_user_id, insight_id, reference_publication_id, prompt_template_id,
              model, criteria_sha256, candidate_sha256, cohort_sha256, status, cache_hit,
              candidate_count, matched_count, ambiguous_count, latency_ms, created_at, completed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed', 1, ?, ?, ?, 0,
                     UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))"
        );
        $statement->execute([
            $requestId, $ownerId, $scope['id'], $scope['reference_publication_id'], $promptId,
            $model, $criteriaHash, $candidateHash, $cohortHash,
            (int) ($cached['candidate_count'] ?? 0), count($cached['matches'] ?? []),
            count($cached['ambiguous'] ?? []),
        ]);
    }

    public function completeRun(
        string $requestId,
        string $candidateHash,
        int $candidateCount,
        int $matchedCount,
        int $ambiguousCount,
        int $inputTokens,
        int $outputTokens,
        int $cachedInputTokens,
        int $latencyMs,
    ): void {
        $statement = $this->connection()->prepare(
            "UPDATE ai_filter_run SET status='completed', candidate_sha256=?, candidate_count=?,
             matched_count=?, ambiguous_count=?, input_tokens=?, output_tokens=?,
             cached_input_tokens=?, latency_ms=?, completed_at=UTC_TIMESTAMP(6) WHERE request_id=?"
        );
        $statement->execute([
            $candidateHash, $candidateCount, $matchedCount, $ambiguousCount,
            $inputTokens, $outputTokens, $cachedInputTokens, $latencyMs, $requestId,
        ]);
    }

    public function failRun(string $requestId, string $status, int $latencyMs): void
    {
        if (!in_array($status, ['refused', 'failed', 'timeout'], true)) {
            $status = 'failed';
        }
        $statement = $this->connection()->prepare(
            "UPDATE ai_filter_run SET status=?, latency_ms=?, completed_at=UTC_TIMESTAMP(6)
             WHERE request_id=? AND status='started'"
        );
        $statement->execute([$status, $latencyMs, $requestId]);
    }

    /** @param array<string,mixed> $result */
    public function cache(
        int $ownerId,
        array $scope,
        int $promptId,
        string $model,
        string $criteriaHash,
        string $candidateHash,
        string $cohortHash,
        array $result,
        int $ttlSeconds,
    ): void {
        try {
            $json = json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $error) {
            throw new AiFilterException('AI_CACHE_INVALID', 'Der KI-Filter konnte nicht gespeichert werden.', 500, $error);
        }
        $statement = $this->connection()->prepare(
            'INSERT INTO ai_filter_cache
             (owner_user_id, insight_id, reference_publication_id, prompt_template_id, model,
              criteria_sha256, candidate_sha256, cohort_sha256, result_json, created_at, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6),
                     DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? SECOND))
             ON DUPLICATE KEY UPDATE result_json=VALUES(result_json), created_at=VALUES(created_at),
                                     expires_at=VALUES(expires_at)'
        );
        $statement->execute([
            $ownerId, $scope['id'], $scope['reference_publication_id'], $promptId, $model,
            $criteriaHash, $candidateHash, $cohortHash, $json, $ttlSeconds,
        ]);
    }

    /**
     * @param array{search_terms:list<string>,exclude_terms:list<string>,date_from:?string,date_to:?string,vote_types:list<string>} $plan
     * @param list<int> $memberIds
     * @return array{items:list<array<string,mixed>>,limited:bool}
     */
    public function candidates(array $scope, array $plan, array $memberIds, int $limit): array
    {
        $dateFrom = max($scope['period_from'], $plan['date_from'] ?? $scope['period_from']);
        $dateTo = min($scope['period_to'], $plan['date_to'] ?? $scope['period_to']);
        if ($dateFrom > $dateTo) {
            return ['items' => [], 'limited' => false];
        }

        $memberPlaceholders = implode(',', array_fill(0, count($memberIds), '?'));
        $termPlaceholders = implode(',', array_fill(0, count($plan['search_terms']), '?'));
        $searchParts = [
            "document.voting_identifier IN ($termPlaceholders)",
            "document.affair_identifier IN ($termPlaceholders)",
            "document.registration_number IN ($termPlaceholders)",
            'MATCH(document.full_text) AGAINST (? IN NATURAL LANGUAGE MODE)',
        ];
        foreach ($plan['search_terms'] as $_) {
            $searchParts[] = "document.full_text LIKE ? ESCAPE '='";
        }
        $where = ' AND (' . implode(' OR ', $searchParts) . ')';
        foreach ($plan['exclude_terms'] as $_) {
            $where .= " AND document.full_text NOT LIKE ? ESCAPE '='";
        }
        if ($plan['vote_types'] !== []) {
            $where .= ' AND event.vote_type IN (' . implode(',', array_fill(0, count($plan['vote_types']), '?')) . ')';
        }
        $sqlLimit = max(1, $limit) + 1;
        $statement = $this->connection()->prepare(
            "SELECT event.source_id id, document.voting_identifier, document.affair_identifier,
                    document.registration_number, document.occurred_on, event.vote_type,
                    document.title, document.exact_question, document.meaning_yes, document.meaning_no,
                    document.official_metadata, document.reviewed_classifications,
                    SUM(choice.normalized_choice='yes') cohort_yes,
                    SUM(choice.normalized_choice='no') cohort_no,
                    SUM(choice.normalized_choice='abstain') cohort_abstain,
                    SUM(choice.normalized_choice='other') cohort_other
             FROM ref_vote_search_document document
             JOIN ref_voting_event event ON event.publication_id=document.publication_id
               AND event.source_id=document.voting_event_source_id
             JOIN ref_voting_choice choice ON choice.publication_id=event.publication_id
               AND choice.voting_event_source_id=event.source_id
               AND choice.person_source_id IN ($memberPlaceholders)
             WHERE event.publication_id=? AND event.chamber_source_id=?
               AND document.occurred_on BETWEEN ? AND ? $where
             GROUP BY event.publication_id, event.source_id, document.voting_identifier,
                      document.affair_identifier, document.registration_number, document.occurred_on,
                      event.vote_type, document.title, document.exact_question, document.meaning_yes,
                      document.meaning_no, document.official_metadata, document.reviewed_classifications
             HAVING SUM(choice.normalized_choice<>'not_participating')>0
             ORDER BY CASE WHEN event.vote_type IN ('final_vote','overall_vote') THEN 0 ELSE 1 END,
                      document.occurred_on DESC, event.source_id DESC LIMIT $sqlLimit"
        );
        $like = static fn (string $term): string => '%' . str_replace(
            ['=', '%', '_'], ['==', '=%', '=_'], $term
        ) . '%';
        $parameters = [
            ...$memberIds,
            $scope['reference_publication_id'], $scope['chamber_source_id'], $dateFrom, $dateTo,
            ...$plan['search_terms'], ...$plan['search_terms'], ...$plan['search_terms'],
            implode(' ', $plan['search_terms']),
            ...array_map($like, $plan['search_terms']),
            ...array_map($like, $plan['exclude_terms']),
            ...$plan['vote_types'],
        ];
        $statement->execute($parameters);
        $rows = $statement->fetchAll();
        $limited = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        return [
            'items' => array_map(fn (array $row): array => $this->candidate($row), $rows),
            'limited' => $limited,
        ];
    }

    /** @return array<string,mixed> */
    private function candidate(array $row): array
    {
        $yes = (int) $row['cohort_yes'];
        $no = (int) $row['cohort_no'];
        $direction = $yes > $no ? 'yes' : ($no > $yes ? 'no' : (($yes + $no) > 0 ? 'split' : 'non_directional'));
        return [
            'id' => (int) $row['id'],
            'voting_identifier' => $this->text($row['voting_identifier'], 191),
            'affair_identifier' => $this->text($row['affair_identifier'], 191),
            'registration_number' => $this->text($row['registration_number'], 191),
            'occurred_on' => (string) $row['occurred_on'],
            'vote_type' => $this->text($row['vote_type'], 64),
            'title' => $this->text($row['title'], 600),
            'exact_question' => $this->text($row['exact_question'], 1600),
            'meaning_yes' => $this->text($row['meaning_yes'], 800),
            'meaning_no' => $this->text($row['meaning_no'], 800),
            'official_metadata' => $this->text($row['official_metadata'], 1200),
            'reviewed_classifications' => $this->text($row['reviewed_classifications'], 800),
            'cohort_direction' => $direction,
            'cohort_counts' => [
                'yes' => $yes,
                'no' => $no,
                'abstain' => (int) $row['cohort_abstain'],
                'other' => (int) $row['cohort_other'],
            ],
        ];
    }

    private function text(mixed $value, int $maximum): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        if (strlen($value) <= $maximum) {
            return $value;
        }
        return substr($value, 0, $maximum - 3) . '...';
    }

    private function connection(): PDO
    {
        return $this->connection ??= ($this->connectionFactory)();
    }
}
