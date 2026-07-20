<?php

declare(strict_types=1);

use Politiks\Tooling\Environment;
use Politiks\Tooling\MariaDb;
use Politiks\Tooling\SqlScript;

require __DIR__ . '/../../scripts/lib/Environment.php';
require __DIR__ . '/../../scripts/lib/MariaDb.php';
require __DIR__ . '/../../scripts/lib/SqlScript.php';

$root = dirname(__DIR__, 2);
$envPath = $root . '/.env.test';
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--env=')) {
        $candidate = substr($argument, 6);
        $envPath = preg_match('~^(?:[A-Za-z]:[\\/]|[\\/])~', $candidate) === 1
            ? $candidate
            : $root . DIRECTORY_SEPARATOR . $candidate;
    } else {
        fwrite(STDERR, 'Unbekanntes Argument: ' . $argument . PHP_EOL);
        exit(2);
    }
}

try {
    if (basename($envPath) !== '.env.test') {
        throw new RuntimeException('Der destruktive Migrationstest akzeptiert nur eine Datei namens .env.test.');
    }
    $environment = Environment::load($envPath);
    $connection = MariaDb::connect($environment);
    $preservedTables = ['app_user', 'insight', 'reference_publication', 'ref_voting_event'];
    $before = [];
    foreach ($preservedTables as $table) {
        $before[$table] = (int) $connection->query(sprintf('SELECT COUNT(*) FROM `%s`', $table))->fetchColumn();
    }

    $connection->exec('SET FOREIGN_KEY_CHECKS = 0');
    try {
        foreach (['ai_filter_cache', 'ai_filter_run', 'ai_prompt_template'] as $table) {
            $connection->exec(sprintf('DROP TABLE IF EXISTS `%s`', $table));
        }
    } finally {
        $connection->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    $migration = $root . '/database/mariadb/migrations/migrate_milestones_11_14_ai_filter.sql';
    $migrationSql = file_get_contents($migration);
    if ($migrationSql === false) {
        throw new RuntimeException('Die Migrationsdatei konnte nicht gelesen werden.');
    }
    $migrationStatements = SqlScript::statements($migrationSql);
    $applyMigration = static function (PDO $connection, array $statements): int {
        foreach ($statements as $sql) {
            $result = $connection->query($sql);
            if ($result === false) {
                throw new RuntimeException('Eine Migrationsanweisung konnte nicht ausgeführt werden.');
            }
            if ($result->columnCount() > 0) {
                $result->fetchAll(PDO::FETCH_ASSOC);
            }
            $result->closeCursor();
        }
        return count($statements);
    };
    $firstStatements = $applyMigration($connection, $migrationStatements);
    $secondStatements = $applyMigration($connection, $migrationStatements);
    if ($firstStatements !== 12 || $secondStatements !== 12) {
        throw new RuntimeException('Die Migration muss bei jedem Lauf genau zwölf Anweisungen ausführen.');
    }

    foreach (['ai_prompt_template', 'ai_filter_cache', 'ai_filter_run'] as $table) {
        $exists = (int) $connection->query(sprintf(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '%s'",
            $table,
        ))->fetchColumn();
        if ($exists !== 1) {
            throw new RuntimeException('Migrationstabelle fehlt: ' . $table);
        }
    }
    $prompts = $connection->query(
        'SELECT purpose, version, output_schema_version, is_active, retired_at FROM ai_prompt_template ORDER BY purpose, version'
    )->fetchAll(PDO::FETCH_ASSOC);
    $expectedPrompts = [
        ['purpose' => 'vote_filter_query_plan', 'version' => 1, 'output_schema_version' => 'vote_filter_query_plan_v1', 'is_active' => 1, 'retired' => false],
        ['purpose' => 'vote_filter_selection', 'version' => 1, 'output_schema_version' => 'vote_filter_selection_v1', 'is_active' => 0, 'retired' => true],
        ['purpose' => 'vote_filter_selection', 'version' => 2, 'output_schema_version' => 'vote_filter_selection_v1', 'is_active' => 1, 'retired' => false],
    ];
    foreach ($prompts as &$prompt) {
        $prompt['version'] = (int) $prompt['version'];
        $prompt['is_active'] = (int) $prompt['is_active'];
        $prompt['retired'] = $prompt['retired_at'] !== null;
        unset($prompt['retired_at']);
    }
    unset($prompt);
    if ($prompts !== $expectedPrompts) {
        throw new RuntimeException('Die Migration muss Query-Plan v1 sowie Selection v1 (inaktiv) und v2 (aktiv) bereitstellen.');
    }
    foreach ($preservedTables as $table) {
        $after = (int) $connection->query(sprintf('SELECT COUNT(*) FROM `%s`', $table))->fetchColumn();
        if ($after !== $before[$table]) {
            throw new RuntimeException('Bestehende Daten wurden verändert: ' . $table);
        }
    }

    echo json_encode([
        'ai_migration_valid' => true,
        'repeatable' => true,
        'existing_data_preserved' => true,
        'schema_statements_per_run' => $firstStatements,
        'versioned_prompt_rows' => count($prompts),
        'active_prompt_versions' => count(array_filter($prompts, static fn (array $prompt): bool => $prompt['is_active'] === 1)),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'MariaDB-Migrationstest fehlgeschlagen: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
