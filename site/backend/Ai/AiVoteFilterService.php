<?php

declare(strict_types=1);

namespace Politiks\App\Ai;

use JsonException;
use Politiks\App\Insight\InsightException;
use Throwable;

final class AiVoteFilterService
{
    /**
     * @param array{enabled:bool,model:string,candidate_limit:int,chunk_size:int,cache_ttl_seconds:int,hourly_limit:int} $config
     */
    public function __construct(
        private readonly AiVoteFilterStore $store,
        private readonly AiPromptStore $prompts,
        private readonly ?AiResponsesClient $client,
        private readonly array $config,
        private readonly string $appSecret,
    ) {
    }

    /** @return array<string,mixed> */
    public function filter(int $ownerId, string $publicId, mixed $criterionValue, mixed $memberValues): array
    {
        $startedAt = hrtime(true);
        $requestId = bin2hex(random_bytes(16));
        $runStarted = false;
        $scope = $this->store->ownedScope($ownerId, $publicId);

        if (!$this->config['enabled'] || $this->client === null) {
            throw new AiFilterException('AI_FILTER_DISABLED', 'Der KI-Filter ist derzeit nicht aktiviert.', 503);
        }
        $criterion = $this->criterion($criterionValue);
        $memberIds = $this->memberIds($memberValues);
        $this->store->assertEligibleCohort($scope, $memberIds);

        $queryPrompt = $this->prompts->active('vote_filter_query_plan');
        $selectionPrompt = $this->prompts->active('vote_filter_selection');
        if ($queryPrompt['output_schema_version'] !== 'vote_filter_query_plan_v1'
            || $selectionPrompt['output_schema_version'] !== 'vote_filter_selection_v1') {
            throw new AiFilterException('AI_PROMPT_UNAVAILABLE', 'Das KI-Filterformat ist nicht verfügbar.', 503);
        }
        $criteriaHash = $this->hash([
            'criterion' => $criterion,
            'query_prompt_id' => $queryPrompt['id'],
            'query_prompt_version' => $queryPrompt['version'],
        ]);
        $sortedMembers = $memberIds;
        sort($sortedMembers);
        $cohortHash = $this->hash($sortedMembers);

        $cached = $this->store->cached(
            $ownerId,
            $scope,
            $selectionPrompt['id'],
            $this->config['model'],
            $criteriaHash,
            $cohortHash,
        );
        if ($cached !== null) {
            $candidateHash = (string) $cached['_candidate_hash'];
            unset($cached['_candidate_hash']);
            $cachedPlan = AiQueryPlanContract::normalize(
                is_array($cached['search_plan'] ?? null) ? $cached['search_plan'] : [],
            );
            $currentCandidates = $this->store->candidates(
                $scope,
                $cachedPlan,
                $memberIds,
                $this->config['candidate_limit'],
            );
            if (hash_equals($candidateHash, $this->hash($currentCandidates['items']))) {
                $this->store->recordCacheHit(
                    $requestId,
                    $ownerId,
                    $scope,
                    $selectionPrompt['id'],
                    $this->config['model'],
                    $criteriaHash,
                    $cohortHash,
                    $candidateHash,
                    $cached,
                );
                return ['request_id' => $requestId] + $cached;
            }
        }

        if ($this->store->recentBillableRuns($ownerId) >= $this->config['hourly_limit']) {
            $this->store->startRun(
                $requestId,
                $ownerId,
                $scope,
                $selectionPrompt['id'],
                $this->config['model'],
                $criteriaHash,
                $cohortHash,
                'rate_limited',
            );
            throw new AiFilterException(
                'AI_FILTER_RATE_LIMITED',
                'Das Stundenlimit für den KI-Filter ist erreicht. Versuche es später erneut.',
                429,
            );
        }

        $this->store->startRun(
            $requestId,
            $ownerId,
            $scope,
            $selectionPrompt['id'],
            $this->config['model'],
            $criteriaHash,
            $cohortHash,
        );
        $runStarted = true;

        try {
            $usage = ['input_tokens' => 0, 'output_tokens' => 0, 'cached_input_tokens' => 0];
            $safetyIdentifier = 'user_' . substr(
                hash_hmac('sha256', (string) $ownerId, $this->appSecret),
                0,
                32,
            );
            $queryResponse = $this->client->structuredResponse(
                $queryPrompt['system_text'],
                [
                    'criterion' => $criterion,
                    'scope' => [
                        'period_from' => $scope['period_from'],
                        'period_to' => $scope['period_to'],
                    ],
                ],
                $queryPrompt['output_schema_version'],
                AiQueryPlanContract::schema(),
                $safetyIdentifier,
            );
            $this->addUsage($usage, $queryResponse['usage']);
            $plan = AiQueryPlanContract::normalize($queryResponse['data']);
            $retrieved = $this->store->candidates(
                $scope,
                $plan,
                $memberIds,
                $this->config['candidate_limit'],
            );
            $candidates = $retrieved['items'];
            $candidateHash = $this->hash($candidates);
            $matches = [];
            $ambiguous = [];

            foreach (array_chunk($candidates, $this->config['chunk_size']) as $chunk) {
                $selectionResponse = $this->client->structuredResponse(
                    $selectionPrompt['system_text'],
                    ['criterion' => $criterion, 'candidates' => $chunk],
                    $selectionPrompt['output_schema_version'],
                    AiSelectionContract::schema(),
                    $safetyIdentifier,
                );
                $this->addUsage($usage, $selectionResponse['usage']);
                $normalized = AiSelectionContract::normalize(
                    $selectionResponse['data'],
                    array_column($chunk, 'id'),
                    count($chunk),
                );
                foreach ($normalized['matches'] as $item) {
                    $matches[$item['id']] = $item['reason'];
                    unset($ambiguous[$item['id']]);
                }
                foreach ($normalized['ambiguous'] as $item) {
                    if (!isset($matches[$item['id']])) {
                        $ambiguous[$item['id']] = $item['reason'];
                    }
                }
            }

            $candidateMap = [];
            foreach ($candidates as $candidate) {
                $candidateMap[$candidate['id']] = $candidate;
            }
            $result = [
                'cache_hit' => false,
                'model' => $this->config['model'],
                'prompt_version' => $selectionPrompt['version'],
                'query_prompt_version' => $queryPrompt['version'],
                'candidate_count' => count($candidates),
                'limited' => $retrieved['limited'],
                'search_plan' => $plan,
                'matches' => $this->enrich($matches, $candidateMap),
                'ambiguous' => $this->enrich($ambiguous, $candidateMap),
            ];
            $elapsed = $this->elapsedMilliseconds($startedAt);
            $this->store->completeRun(
                $requestId,
                $candidateHash,
                count($candidates),
                count($result['matches']),
                count($result['ambiguous']),
                $usage['input_tokens'],
                $usage['output_tokens'],
                $usage['cached_input_tokens'],
                $elapsed,
            );
            $this->store->cache(
                $ownerId,
                $scope,
                $selectionPrompt['id'],
                $this->config['model'],
                $criteriaHash,
                $candidateHash,
                $cohortHash,
                $result,
                $this->config['cache_ttl_seconds'],
            );
            return ['request_id' => $requestId] + $result;
        } catch (Throwable $error) {
            if ($runStarted) {
                $status = $error instanceof AiFilterException && $error->errorCode === 'AI_RESPONSE_REFUSED'
                    ? 'refused'
                    : ($error instanceof AiFilterException && str_contains($error->errorCode, 'TIMEOUT')
                        ? 'timeout'
                        : 'failed');
                $this->store->failRun($requestId, $status, $this->elapsedMilliseconds($startedAt));
            }
            throw $error;
        }
    }

    private function criterion(mixed $value): string
    {
        if (!is_string($value)) {
            throw new AiFilterException('AI_CRITERION_INVALID', 'Formuliere ein Auswahlkriterium.', 422);
        }
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', ' ', trim($value));
        $value = is_string($value) ? trim($value) : '';
        if (strlen($value) < 3 || strlen($value) > 1000) {
            throw new AiFilterException(
                'AI_CRITERION_INVALID',
                'Das Auswahlkriterium muss zwischen 3 und 1000 Zeichen lang sein.',
                422,
            );
        }
        return $value;
    }

    /** @return list<int> */
    private function memberIds(mixed $values): array
    {
        if (!is_array($values) || !array_is_list($values) || $values === [] || count($values) > 200) {
            throw new InsightException('MEMBERS_REQUIRED', 'Wähle mindestens ein Mitglied für die KI-Auswertung aus.');
        }
        $ids = [];
        foreach ($values as $value) {
            if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
                throw new InsightException('INVALID_SELECTION', 'Die Mitglieder enthalten eine ungültige Auswahl.');
            }
            $id = (int) $value;
            if ($id < 1 || isset($ids[$id])) {
                throw new InsightException('INVALID_SELECTION', 'Die Mitglieder enthalten eine ungültige Auswahl.');
            }
            $ids[$id] = true;
        }
        return array_keys($ids);
    }

    /** @param array{input_tokens:?int,output_tokens:?int,cached_input_tokens:?int} $addition */
    private function addUsage(array &$usage, array $addition): void
    {
        foreach ($usage as $key => $value) {
            $usage[$key] = $value + max(0, (int) ($addition[$key] ?? 0));
        }
    }

    /**
     * @param array<int,string> $selected
     * @param array<int,array<string,mixed>> $candidateMap
     * @return list<array<string,mixed>>
     */
    private function enrich(array $selected, array $candidateMap): array
    {
        $result = [];
        foreach ($selected as $id => $reason) {
            if (!isset($candidateMap[$id])) {
                continue;
            }
            $candidate = $candidateMap[$id];
            $result[] = [
                'id' => $id,
                'reason' => $reason,
                'title' => $candidate['title'],
                'occurred_on' => $candidate['occurred_on'],
                'vote_type' => $candidate['vote_type'],
                'voting_identifier' => $candidate['voting_identifier'],
                'affair_identifier' => $candidate['affair_identifier'],
                'cohort_direction' => $candidate['cohort_direction'],
            ];
        }
        return $result;
    }

    private function hash(mixed $value): string
    {
        try {
            return hash('sha256', json_encode(
                $value,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
        } catch (JsonException $error) {
            throw new AiFilterException('AI_REQUEST_INVALID', 'Die KI-Anfrage konnte nicht vorbereitet werden.', 500, $error);
        }
    }

    private function elapsedMilliseconds(int $startedAt): int
    {
        return max(0, min(4_294_967_295, (int) round((hrtime(true) - $startedAt) / 1_000_000)));
    }
}
