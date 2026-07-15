<?php

declare(strict_types=1);

use Politiks\Tooling\Environment;
use Politiks\Tooling\ReferencePublisher;
use Politiks\Tooling\SqlScript;

require_once __DIR__ . '/../../scripts/lib/Environment.php';
require_once __DIR__ . '/../../scripts/lib/ReferencePublisher.php';
require_once __DIR__ . '/../../scripts/lib/SqlScript.php';

return [
    'environment parser preserves safe values without exporting them' => static function (): void {
        $path = tempnam(sys_get_temp_dir(), 'politiks-env-');
        assertTrue(is_string($path), 'A temporary environment path must be available.');
        try {
            file_put_contents($path, "DB_HOST=localhost\nDB_PASSWORD='value with spaces'\nEMPTY=\n");
            $values = Environment::load($path);
            assertSameValue('localhost', $values['DB_HOST'], 'Unquoted values should be parsed.');
            assertSameValue('value with spaces', $values['DB_PASSWORD'], 'Quoted values should be unwrapped.');
            assertSameValue('', $values['EMPTY'], 'Empty values should be preserved.');
        } finally {
            @unlink($path);
        }
    },
    'environment parser rejects duplicate settings' => static function (): void {
        $path = tempnam(sys_get_temp_dir(), 'politiks-env-');
        assertTrue(is_string($path), 'A temporary environment path must be available.');
        try {
            file_put_contents($path, "DB_HOST=first\nDB_HOST=second\n");
            $rejected = false;
            try {
                Environment::load($path);
            } catch (RuntimeException) {
                $rejected = true;
            }
            assertTrue($rejected, 'Duplicate keys must be rejected.');
        } finally {
            @unlink($path);
        }
    },
    'reference publication normalizes explicit unknown person dates' => static function (): void {
        $reflection = new ReflectionClass(ReferencePublisher::class);
        $publisher = $reflection->newInstanceWithoutConstructor();
        $normalizer = $reflection->getMethod('mariaDate');

        assertSameValue(
            null,
            $normalizer->invoke($publisher, 'Unknown'),
            'Explicit unknown dates must publish as SQL NULL.'
        );
        assertSameValue(
            '1984-01-31',
            $normalizer->invoke($publisher, '1984-01-31'),
            'Valid ISO dates must remain unchanged.'
        );

        $rejected = false;
        try {
            $normalizer->invoke($publisher, '31.01.1984');
        } catch (RuntimeException) {
            $rejected = true;
        }
        assertTrue($rejected, 'Non-ISO source dates must fail instead of being coerced by MariaDB.');
    },
    'MariaDB schema contains immutable publication and application ownership boundaries' => static function (): void {
        $schema = file_get_contents(__DIR__ . '/../../site/database/schema.sql');
        assertTrue($schema !== false, 'MariaDB schema must be readable.');
        foreach ([
            'CREATE TABLE IF NOT EXISTS reference_publication',
            'CREATE TABLE IF NOT EXISTS reference_state',
            'CREATE TABLE IF NOT EXISTS ref_voting_event',
            'CREATE TABLE IF NOT EXISTS ref_voting_choice',
            'CREATE TABLE IF NOT EXISTS app_user',
            'CREATE TABLE IF NOT EXISTS insight',
            'CREATE TABLE IF NOT EXISTS insight_member',
            'CREATE TABLE IF NOT EXISTS insight_vote_evidence',
            'CREATE TABLE IF NOT EXISTS insight_campaign_context',
            'CREATE TABLE IF NOT EXISTS ai_prompt_template',
            'CREATE TABLE IF NOT EXISTS ai_filter_cache',
            'CREATE TABLE IF NOT EXISTS ai_filter_run',
            "'vote_filter_selection'",
            "'vote_filter_query_plan'",
            'FULLTEXT KEY',
        ] as $requiredSql) {
            assertTrue(str_contains($schema, $requiredSql), sprintf('Schema lacks %s.', $requiredSql));
        }
        assertTrue(
            str_contains($schema, "visibility IN ('draft', 'unlisted', 'public')"),
            'Insight visibility lifecycle must be constrained.'
        );
    },
    'schema bootstrap script is executable as individual statements' => static function (): void {
        $sql = file_get_contents(__DIR__ . '/../../site/database/schema.sql');
        assertTrue($sql !== false, 'MariaDB schema must be readable.');
        $statements = SqlScript::statements($sql);
        assertTrue(is_array($statements), 'SQL script parser must return statements.');
        assertSameValue(45, count($statements), 'The complete schema statement count should remain explicit.');
        foreach ($statements as $statement) {
            assertTrue(trim($statement) !== '', 'Schema must not contain empty executable statements.');
        }
    },
    'database tooling is outside the public deployment root' => static function (): void {
        assertTrue(
            !is_file(__DIR__ . '/../../site/bootstrap_mariadb.php'),
            'The schema bootstrap must not be web-accessible.'
        );
        assertTrue(
            !is_file(__DIR__ . '/../../site/publish_reference_data.php'),
            'The publication command must not be web-accessible.'
        );
    },
];
