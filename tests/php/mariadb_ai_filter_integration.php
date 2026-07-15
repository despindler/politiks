<?php

declare(strict_types=1);

use Politiks\App\Ai\AiFilterException;
use Politiks\App\Ai\AiPromptStore;
use Politiks\App\Ai\AiVoteFilterService;
use Politiks\App\Ai\AiVoteFilterStore;
use Politiks\App\Insight\InsightException;
use Politiks\App\Insight\InsightStore;
use Politiks\App\Insight\WizardStore;
use Politiks\TestSupport\DeterministicAiResponsesClient;
use Politiks\Tooling\Environment;
use Politiks\Tooling\MariaDb;

require __DIR__ . '/../../scripts/lib/Environment.php';
require __DIR__ . '/../../scripts/lib/MariaDb.php';
require __DIR__ . '/../../site/backend/bootstrap.php';
require __DIR__ . '/../support/DeterministicAiResponsesClient.php';
require __DIR__ . '/TestReferenceFixture.php';

$root = dirname(__DIR__, 2);
$envPath = $root . DIRECTORY_SEPARATOR . '.env.test';
foreach (array_slice($argv, 1) as $argument) {
    if (!str_starts_with($argument, '--env=')) {
        fwrite(STDERR, sprintf("Unknown argument: %s\n", $argument));
        exit(2);
    }
    $candidate = substr($argument, 6);
    $envPath = preg_match('~^(?:[A-Za-z]:[\\/]|[\\/])~', $candidate) === 1
        ? $candidate
        : $root . DIRECTORY_SEPARATOR . $candidate;
}

$ownerIds = [];

/** @return array{data:array<string,mixed>} */
function planResponse(array $terms, array $exclude = [], array $types = []): array
{
    return ['data' => [
        'search_terms' => $terms,
        'exclude_terms' => $exclude,
        'date_from' => null,
        'date_to' => null,
        'vote_types' => $types,
    ]];
}

/** @return array{data:array<string,mixed>} */
function selectionResponse(array $matches, array $ambiguous = []): array
{
    return ['data' => ['matches' => $matches, 'ambiguous' => $ambiguous]];
}

try {
    if (basename($envPath) !== '.env.test') {
        throw new RuntimeException('AI filter integration tests require .env.test.');
    }
    $pdo = MariaDb::connect(Environment::load($envPath));
    $fixturePublicationId = ensureWizardReferenceFixture($pdo);
    $unique = bin2hex(random_bytes(5));
    $insertUser = $pdo->prepare(
        "INSERT INTO app_user (google_sub, email, display_name, role, is_active, created_at, updated_at)
         VALUES (?, ?, ?, 'user', 1, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))"
    );
    $insertUser->execute(['ai-filter-owner-' . $unique, 'ai-filter-owner-' . $unique . '@example.test', 'AI Filter Owner']);
    $ownerId = (int) $pdo->lastInsertId();
    $ownerIds[] = $ownerId;
    $insertUser->execute(['ai-filter-other-' . $unique, 'ai-filter-other-' . $unique . '@example.test', 'AI Filter Other']);
    $otherId = (int) $pdo->lastInsertId();
    $ownerIds[] = $otherId;

    $insights = new InsightStore(static fn (): PDO => $pdo, 'https://politiks.example.test');
    $wizard = new WizardStore(static fn (): PDO => $pdo);
    $draft = $insights->createDraft($ownerId);
    $wizard->saveScope($ownerId, $draft['public_id'], [
        'country_id' => 910001,
        'legislature_id' => 910002,
        'chamber_id' => 910003,
        'party_id' => 910004,
        'period_from' => '2025-01-01',
        'period_to' => '2025-12-31',
    ]);
    $memberIds = [910101, 910102, 910103, 910104];
    $wizard->saveMembers($ownerId, $draft['public_id'], $memberIds);

    $client = new DeterministicAiResponsesClient([
        planResponse(['Steuerentlastung', 'Grundversorgung']),
        selectionResponse(
            [['id' => 910301, 'reason' => 'Betrifft eine Steuerentlastung.']],
            [['id' => 910302, 'reason' => 'Grundversorgung ist nur teilweise einschlägig.']],
        ),
        planResponse(['Steuerentlastung', 'Grundversorgung']),
        selectionResponse([['id' => 910301, 'reason' => 'Treffer nach Kandidatenänderung.']]),
        planResponse(['NR:TEST-3']),
        selectionResponse([['id' => 910303, 'reason' => 'Exakte Abstimmungskennung.']]),
        planResponse(['Steuerentlastung']),
        selectionResponse([['id' => 999999, 'reason' => 'Erfundene ID.']]),
        planResponse(['Steuerentlastung', 'Grundversorgung']),
        selectionResponse([['id' => 910301, 'reason' => 'Treffer mit verändertem Kollektiv.']]),
    ]);
    $config = [
        'enabled' => true,
        'model' => 'deterministic-test-model',
        'candidate_limit' => 300,
        'chunk_size' => 75,
        'cache_ttl_seconds' => 3600,
        'hourly_limit' => 10,
    ];
    $service = new AiVoteFilterService(
        new AiVoteFilterStore(static fn (): PDO => $pdo),
        new AiPromptStore(static fn (): PDO => $pdo),
        $client,
        $config,
        str_repeat('a', 64),
    );
    $criterion = 'Finde Vorlagen zu Steuerentlastungen und zur Grundversorgung.';
    $result = $service->filter($ownerId, $draft['public_id'], $criterion, $memberIds);
    if ($result['cache_hit'] || array_column($result['matches'], 'id') !== [910301]
        || array_column($result['ambiguous'], 'id') !== [910302]) {
        throw new RuntimeException('Hybrid retrieval did not return the deterministic match and ambiguity groups.');
    }
    if (($result['matches'][0]['cohort_direction'] ?? null) !== 'yes'
        || ($result['ambiguous'][0]['cohort_direction'] ?? null) !== 'no') {
        throw new RuntimeException('AI result previews did not retain authoritative cohort directions.');
    }
    if (count($client->requests) !== 2
        || $client->requests[0]['schemaName'] !== 'vote_filter_query_plan_v1'
        || $client->requests[1]['schemaName'] !== 'vote_filter_selection_v1') {
        throw new RuntimeException('The two-stage structured provider flow was not used.');
    }

    $cached = $service->filter($ownerId, $draft['public_id'], $criterion, $memberIds);
    if (!$cached['cache_hit'] || count($client->requests) !== 2) {
        throw new RuntimeException('An identical request did not reuse the cache before provider access.');
    }
    $titleStatement = $pdo->prepare(
        'SELECT title FROM ref_vote_search_document WHERE publication_id=? AND voting_event_source_id=910301'
    );
    $titleStatement->execute([$fixturePublicationId]);
    $originalTitle = (string) $titleStatement->fetchColumn();
    $changeTitle = $pdo->prepare(
        'UPDATE ref_vote_search_document SET title=? WHERE publication_id=? AND voting_event_source_id=910301'
    );
    $changeTitle->execute([$originalTitle . ' (geändert)', $fixturePublicationId]);
    try {
        $candidateChanged = $service->filter($ownerId, $draft['public_id'], $criterion, $memberIds);
        if ($candidateChanged['cache_hit'] || count($client->requests) !== 4) {
            throw new RuntimeException('Changing candidate content did not invalidate the AI cache.');
        }
    } finally {
        $changeTitle->execute([$originalTitle, $fixturePublicationId]);
    }
    $exact = $service->filter($ownerId, $draft['public_id'], 'Nur die Abstimmung NR:TEST-3', $memberIds);
    if (array_column($exact['matches'], 'id') !== [910303]) {
        throw new RuntimeException('Exact identifier retrieval did not reach semantic selection.');
    }

    try {
        $service->filter($otherId, $draft['public_id'], $criterion, $memberIds);
        throw new RuntimeException('Cross-owner AI filtering should not be possible.');
    } catch (InsightException $error) {
        if ($error->errorCode !== 'INSIGHT_NOT_FOUND' || $error->status !== 404) {
            throw $error;
        }
    }
    try {
        $service->filter($ownerId, $draft['public_id'], 'Steuerentlastung', [999999]);
        throw new RuntimeException('Out-of-scope cohort members should be rejected.');
    } catch (InsightException $error) {
        if ($error->errorCode !== 'INVALID_MEMBER') {
            throw $error;
        }
    }

    try {
        $service->filter($ownerId, $draft['public_id'], 'Steuerentlastung', $memberIds);
        throw new RuntimeException('An unknown provider ID should have failed validation.');
    } catch (AiFilterException $error) {
        if ($error->errorCode !== 'AI_RESPONSE_UNKNOWN_ID') {
            throw $error;
        }
    }
    $failedRuns = $pdo->prepare(
        "SELECT COUNT(*) FROM ai_filter_run WHERE owner_user_id=? AND status='failed'"
    );
    $failedRuns->execute([$ownerId]);
    if ((int) $failedRuns->fetchColumn() < 1) {
        throw new RuntimeException('Failed structured output was not recorded safely.');
    }

    $cohortChanged = $service->filter($ownerId, $draft['public_id'], $criterion, [910101, 910104]);
    if ($cohortChanged['cache_hit'] || count($client->requests) !== 10) {
        throw new RuntimeException('Changing the selected-member cohort did not invalidate the AI cache.');
    }

    $retrievalStore = new AiVoteFilterStore(static fn (): PDO => $pdo);
    $scope = $retrievalStore->ownedScope($ownerId, $draft['public_id']);
    $excluded = $retrievalStore->candidates($scope, [
        'search_terms' => ['Steuerentlastung'],
        'exclude_terms' => ['Steuerentlastung'],
        'date_from' => null,
        'date_to' => null,
        'vote_types' => [],
    ], $memberIds, 300);
    if ($excluded['items'] !== []) {
        throw new RuntimeException('Retrieval exclusion terms did not remove matching records.');
    }
    $typed = $retrievalStore->candidates($scope, [
        'search_terms' => ['TEST'],
        'exclude_terms' => [],
        'date_from' => '2025-03-01',
        'date_to' => '2025-03-31',
        'vote_types' => ['final_vote'],
    ], $memberIds, 300);
    if (array_column($typed['items'], 'id') !== [910301]) {
        throw new RuntimeException('Retrieval date and vote-type hints were not enforced.');
    }

    $chunkClient = new DeterministicAiResponsesClient([
        planResponse(['TEST']),
        selectionResponse([['id' => 910302, 'reason' => 'Treffer im ersten Block.']]),
        selectionResponse(
            [['id' => 910305, 'reason' => 'Treffer im zweiten Block.']],
            [['id' => 910304, 'reason' => 'Mehrdeutig im zweiten Block.']],
        ),
        selectionResponse([['id' => 910303, 'reason' => 'Treffer im dritten Block.']]),
    ]);
    $chunkService = new AiVoteFilterService(
        new AiVoteFilterStore(static fn (): PDO => $pdo),
        new AiPromptStore(static fn (): PDO => $pdo),
        $chunkClient,
        array_replace($config, ['model' => 'deterministic-chunk-model', 'chunk_size' => 2]),
        str_repeat('c', 64),
    );
    $chunked = $chunkService->filter($ownerId, $draft['public_id'], 'Alle TEST-Abstimmungen', $memberIds);
    if (array_column($chunked['matches'], 'id') !== [910302, 910305, 910303]
        || array_column($chunked['ambiguous'], 'id') !== [910304]
        || count($chunkClient->requests) !== 4) {
        throw new RuntimeException('Bounded chunk selection did not merge deterministic groups correctly.');
    }

    $rateDraft = $insights->createDraft($otherId);
    $wizard->saveScope($otherId, $rateDraft['public_id'], [
        'country_id' => 910001,
        'legislature_id' => 910002,
        'chamber_id' => 910003,
        'party_id' => 910004,
        'period_from' => '2025-01-01',
        'period_to' => '2025-12-31',
    ]);
    $rateClient = new DeterministicAiResponsesClient([
        planResponse(['Steuerentlastung']),
        selectionResponse([['id' => 910301, 'reason' => 'Treffer.']]),
    ]);
    $rateService = new AiVoteFilterService(
        new AiVoteFilterStore(static fn (): PDO => $pdo),
        new AiPromptStore(static fn (): PDO => $pdo),
        $rateClient,
        array_replace($config, ['hourly_limit' => 1]),
        str_repeat('b', 64),
    );
    $rateService->filter($otherId, $rateDraft['public_id'], 'Steuerentlastung', $memberIds);
    try {
        $rateService->filter($otherId, $rateDraft['public_id'], 'Grundversorgung', $memberIds);
        throw new RuntimeException('The per-user hourly limit should reject a second uncached request.');
    } catch (AiFilterException $error) {
        if ($error->errorCode !== 'AI_FILTER_RATE_LIMITED' || $error->status !== 429) {
            throw $error;
        }
    }
    if (count($rateClient->requests) !== 2) {
        throw new RuntimeException('Rate limiting occurred only after provider traffic.');
    }

    $runColumns = $pdo->query(
        "SELECT column_name FROM information_schema.columns
         WHERE table_schema=DATABASE() AND table_name='ai_filter_run' ORDER BY ordinal_position"
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ([
        'request_id', 'model', 'criteria_sha256', 'candidate_sha256', 'status', 'latency_ms',
        'input_tokens', 'output_tokens', 'cached_input_tokens', 'prompt_template_id',
    ] as $requiredColumn) {
        if (!in_array($requiredColumn, $runColumns, true)) {
            throw new RuntimeException('Privacy-safe AI run metadata lacks ' . $requiredColumn . '.');
        }
    }
    foreach (['criterion', 'raw_criterion', 'candidate_text', 'email', 'google_sub', 'campaign_material'] as $forbiddenColumn) {
        if (in_array($forbiddenColumn, $runColumns, true)) {
            throw new RuntimeException('AI run metadata must not store ' . $forbiddenColumn . '.');
        }
    }
    $safeRun = $pdo->prepare(
        'SELECT run.request_id, run.model, run.criteria_sha256, run.candidate_sha256, run.status,
                run.latency_ms, run.input_tokens, run.output_tokens, run.cached_input_tokens,
                prompt.version prompt_version
         FROM ai_filter_run run JOIN ai_prompt_template prompt ON prompt.id=run.prompt_template_id
         WHERE run.owner_user_id=? AND run.status=\'completed\' ORDER BY run.id DESC LIMIT 1'
    );
    $safeRun->execute([$ownerId]);
    $safeRunJson = json_encode($safeRun->fetch(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    foreach ([$criterion, 'ai-filter-owner-', '@example.test', 'Steuerentlastung und Gegenfinanzierung'] as $privateValue) {
        if (str_contains($safeRunJson, $privateValue)) {
            throw new RuntimeException('AI run metadata leaked raw user, identity, or candidate content.');
        }
    }

    $runSummary = $pdo->prepare(
        'SELECT status, cache_hit, COUNT(*) amount FROM ai_filter_run
         WHERE owner_user_id=? GROUP BY status, cache_hit ORDER BY status, cache_hit'
    );
    $runSummary->execute([$ownerId]);
    echo json_encode([
        'hybrid_retrieval_valid' => true,
        'exact_identifier_valid' => true,
        'ownership_and_cohort_valid' => true,
        'cache_reuse_valid' => true,
        'cohort_cache_invalidation_valid' => true,
        'candidate_cache_invalidation_valid' => true,
        'retrieval_hints_valid' => true,
        'chunk_merge_valid' => true,
        'rate_limit_valid' => true,
        'unknown_id_rejected' => true,
        'privacy_safe_run_log_valid' => true,
        'provider_requests' => count($client->requests) + count($chunkClient->requests) + count($rateClient->requests),
        'external_ai_requests' => 0,
        'run_summary' => $runSummary->fetchAll(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'AI filter integration failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
} finally {
    if (isset($pdo)) {
        foreach ($ownerIds as $cleanupOwnerId) {
            try {
                $pdo->prepare('DELETE FROM insight WHERE owner_user_id=?')->execute([$cleanupOwnerId]);
                $pdo->prepare('DELETE FROM app_user WHERE id=?')->execute([$cleanupOwnerId]);
            } catch (Throwable) {
            }
        }
    }
}
