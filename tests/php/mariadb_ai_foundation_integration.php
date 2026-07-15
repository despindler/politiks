<?php

declare(strict_types=1);

use Politiks\App\Ai\AiPromptStore;
use Politiks\Tooling\Environment;
use Politiks\Tooling\MariaDb;

require __DIR__ . '/../../scripts/lib/Environment.php';
require __DIR__ . '/../../scripts/lib/MariaDb.php';
require __DIR__ . '/../../site/backend/bootstrap.php';
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

try {
    if (basename($envPath) !== '.env.test') {
        throw new RuntimeException('AI foundation integration tests require .env.test.');
    }
    $pdo = MariaDb::connect(Environment::load($envPath));
    $publicationId = ensureWizardReferenceFixture($pdo);
    $prompt = (new AiPromptStore(static fn (): PDO => $pdo))->active('vote_filter_selection');
    if ($prompt['version'] !== 2 || $prompt['output_schema_version'] !== 'vote_filter_selection_v1') {
        throw new RuntimeException('The active AI selection prompt is not the expected versioned contract.');
    }

    $pdo->beginTransaction();
    $unique = bin2hex(random_bytes(6));
    $insertUser = $pdo->prepare(
        "INSERT INTO app_user
         (google_sub, email, display_name, role, is_active, created_at, updated_at)
         VALUES (?, ?, 'AI Foundation Test', 'user', 1, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))"
    );
    $insertUser->execute(['ai-foundation-' . $unique, 'ai-foundation-' . $unique . '@example.test']);
    $ownerId = (int) $pdo->lastInsertId();

    $insertInsight = $pdo->prepare(
        "INSERT INTO insight
         (public_id, owner_user_id, reference_publication_id, visibility, created_at, updated_at)
         VALUES (?, ?, ?, 'draft', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))"
    );
    $insertInsight->execute([str_pad('AI' . strtoupper($unique), 26, '0'), $ownerId, $publicationId]);
    $insightId = (int) $pdo->lastInsertId();
    $digest = hash('sha256', 'ai-foundation-' . $unique);

    $insertRun = $pdo->prepare(
        "INSERT INTO ai_filter_run
         (request_id, owner_user_id, insight_id, reference_publication_id, prompt_template_id,
          model, criteria_sha256, candidate_sha256, cohort_sha256, status, cache_hit,
          candidate_count, matched_count, ambiguous_count, input_tokens, output_tokens,
          cached_input_tokens, latency_ms, created_at, completed_at)
         VALUES (?, ?, ?, ?, ?, 'deterministic-test-model', ?, ?, ?, 'completed', 0,
                 2, 1, 1, 100, 20, 10, 15, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))"
    );
    $insertRun->execute([
        bin2hex(random_bytes(16)), $ownerId, $insightId, $publicationId, $prompt['id'],
        $digest, $digest, $digest,
    ]);

    $resultJson = json_encode([
        'matches' => [['id' => 910301, 'reason' => 'Deterministischer Treffer']],
        'ambiguous' => [],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    $insertCache = $pdo->prepare(
        "INSERT INTO ai_filter_cache
         (owner_user_id, insight_id, reference_publication_id, prompt_template_id, model,
          criteria_sha256, candidate_sha256, cohort_sha256, result_json, created_at, expires_at)
         VALUES (?, ?, ?, ?, 'deterministic-test-model', ?, ?, ?, ?, UTC_TIMESTAMP(6),
                 DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 1 HOUR))"
    );
    $insertCache->execute([
        $ownerId, $insightId, $publicationId, $prompt['id'], $digest, $digest, $digest, $resultJson,
    ]);

    $rate = $pdo->prepare(
        'SELECT COUNT(*) FROM ai_filter_run WHERE owner_user_id=? AND created_at >= DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 HOUR)'
    );
    $rate->execute([$ownerId]);
    if ((int) $rate->fetchColumn() !== 1) {
        throw new RuntimeException('AI run metadata cannot support per-user hourly accounting.');
    }
    $cache = $pdo->prepare(
        'SELECT result_json FROM ai_filter_cache
         WHERE owner_user_id=? AND insight_id=? AND expires_at > UTC_TIMESTAMP(6)'
    );
    $cache->execute([$ownerId, $insightId]);
    $cached = json_decode((string) $cache->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
    if (($cached['matches'][0]['id'] ?? null) !== 910301) {
        throw new RuntimeException('AI cache metadata did not round-trip valid JSON.');
    }
    $pdo->rollBack();

    echo json_encode([
        'ai_foundation_ready' => true,
        'prompt_version' => $prompt['version'],
        'output_schema_version' => $prompt['output_schema_version'],
        'local_database_smoke' => true,
        'external_ai_requests' => 0,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
} catch (Throwable $error) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'AI foundation integration failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
