<?php

declare(strict_types=1);

use Politiks\Tooling\AiSelectionEvaluation;

require __DIR__ . '/lib/AiSelectionEvaluation.php';

$root = dirname(__DIR__);
$fixturePath = $root . '/classification/ai-filter/v1.de.json';
$onlyCase = null;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--fixture=')) {
        $candidate = substr($argument, 10);
        $fixturePath = preg_match('~^(?:[A-Za-z]:[\\/]|[\\/])~', $candidate) === 1
            ? $candidate
            : $root . DIRECTORY_SEPARATOR . $candidate;
    } elseif (str_starts_with($argument, '--case=')) {
        $onlyCase = substr($argument, 7);
    } else {
        fwrite(STDERR, 'Unbekanntes Argument: ' . $argument . PHP_EOL);
        exit(2);
    }
}

try {
    $fixture = json_decode((string) file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($fixture)) {
        throw new RuntimeException('Die Evaluationsdatei ist ungültig.');
    }
    $report = AiSelectionEvaluation::run(
        $fixture,
        static function (array $case): array {
            $result = $case['deterministic_result'] ?? null;
            if (!is_array($result)) {
                throw new RuntimeException('Deterministisches Ergebnis fehlt für ' . $case['id'] . '.');
            }
            return [
                'matches' => $result['matches'] ?? null,
                'ambiguous' => $result['ambiguous'] ?? null,
            ];
        },
        $onlyCase,
    );
    $report['provider'] = 'deterministic-fixture';
    $report['external_ai_requests'] = 0;
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit($report['failed'] === 0 ? 0 : 1);
} catch (Throwable $error) {
    fwrite(STDERR, 'KI-Auswahlevaluation fehlgeschlagen: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
