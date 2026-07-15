<?php

declare(strict_types=1);

use Politiks\Tooling\Environment;
use Politiks\Tooling\MariaDb;
use Politiks\Tooling\SqlScript;

require __DIR__ . '/lib/Environment.php';
require __DIR__ . '/lib/MariaDb.php';
require __DIR__ . '/lib/SqlScript.php';

$root = dirname(__DIR__);
$envPath = $root . DIRECTORY_SEPARATOR . '.env.test';
$reset = false;

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--env=')) {
        $candidate = substr($argument, 6);
        $envPath = str_starts_with($candidate, '/') || str_starts_with($candidate, '\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $candidate) === 1
            ? $candidate
            : $root . DIRECTORY_SEPARATOR . $candidate;
    } elseif ($argument === '--reset') {
        $reset = true;
    } else {
        fwrite(STDERR, sprintf("Unknown argument: %s\n", $argument));
        exit(2);
    }
}

try {
    $environment = Environment::load($envPath);
    try {
        $connection = MariaDb::connect($environment);
    } catch (PDOException $error) {
        if ((string) $error->getCode() !== '1049' || basename($envPath) !== '.env.test') {
            throw $error;
        }
        $serverConnection = MariaDb::connect($environment, false);
        $databaseName = $environment['DB_NAME'];
        $serverConnection->exec(
            sprintf(
                'CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                $databaseName
            )
        );
        $connection = MariaDb::connect($environment);
    }
    $serverVersion = strtolower((string) $connection->query('SELECT VERSION()')->fetchColumn());
    $serverFamily = str_contains($serverVersion, 'mariadb') ? 'MariaDB' : 'MySQL-compatible';

    if ($reset) {
        if (basename($envPath) !== '.env.test') {
            throw new RuntimeException('--reset is allowed only with an environment file named .env.test.');
        }
        $tables = [
            'ai_filter_run',
            'ai_filter_cache',
            'insight_campaign_context',
            'insight_vote_evidence',
            'insight_member',
            'insight',
            'app_user',
            'ai_prompt_template',
            'ref_vote_search_document',
            'ref_reviewed_classification',
            'ref_taxonomy_term',
            'ref_voting_choice',
            'ref_voting_aggregate',
            'ref_voting_event',
            'ref_matter_descriptor',
            'ref_matter_topic',
            'ref_official_descriptor',
            'ref_official_topic',
            'ref_matter_summary',
            'ref_matter_text',
            'ref_matter',
            'ref_person_faction_membership',
            'ref_person_party_membership',
            'ref_person_mandate',
            'ref_person_identifier',
            'ref_person',
            'ref_faction',
            'ref_party',
            'ref_committee',
            'ref_subdivision',
            'ref_session',
            'ref_legislative_period',
            'ref_chamber',
            'ref_legislature',
            'ref_country',
            'reference_state',
            'reference_publication',
        ];
        $connection->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($tables as $table) {
                $connection->exec(sprintf('DROP TABLE IF EXISTS `%s`', $table));
            }
        } finally {
            $connection->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    $statements = SqlScript::execute($connection, $root . '/database/mariadb/schema.sql');
    $tableCount = (int) $connection->query(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()"
    )->fetchColumn();
    echo json_encode(
        [
            'database_ready' => true,
            'server_family' => $serverFamily,
            'reset' => $reset,
            'schema_statements' => $statements,
            'tables_in_database' => $tableCount,
        ],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    ), PHP_EOL;
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'MariaDB bootstrap failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
