<?php

declare(strict_types=1);

use Politiks\Tooling\SqlDumpSplitter;

require_once __DIR__ . '/../../scripts/lib/SqlDumpSplitter.php';

return [
    'SQL dump splitter creates ordered standalone gzip parts at statement boundaries' => static function (): void {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'politiks-split-' . bin2hex(random_bytes(6));
        assertTrue(mkdir($directory), 'Temporary split directory must be created.');
        $input = $directory . DIRECTORY_SEPARATOR . 'fixture.sql';
        $prefix = $directory . DIRECTORY_SEPARATOR . 'fixture';
        $source = "-- fixture\nCREATE TABLE example (id INT PRIMARY KEY, note TEXT);\n";
        for ($index = 1; $index <= 20; $index++) {
            $source .= sprintf(
                "INSERT INTO example VALUES (%d, 'statement %d; value');\n",
                $index,
                $index,
            );
        }
        file_put_contents($input, $source);

        try {
            $manifest = SqlDumpSplitter::split($input, $prefix, 5);
            assertSameValue(5, $manifest['part_count'], 'Exactly five parts should be reported.');
            assertSameValue(strlen($source), $manifest['source_bytes'], 'Every source byte should be assigned.');
            assertSameValue(hash('sha256', $source), $manifest['source_sha256'], 'Source checksum should be stable.');
            assertSameValue(
                strlen($source),
                array_sum(array_column($manifest['parts'], 'payload_bytes')),
                'Part payloads should cover the complete source exactly once.',
            );

            foreach ($manifest['parts'] as $offset => $part) {
                assertSameValue($offset + 1, $part['part'], 'Parts should remain in numeric order.');
                $path = $directory . DIRECTORY_SEPARATOR . $part['file'];
                $sql = file_get_contents('compress.zlib://' . $path);
                assertTrue(is_string($sql), 'Each gzip part should decompress as SQL.');
                assertTrue(
                    str_contains($sql, sprintf('import part %d of 5', $offset + 1)),
                    'Each part should identify its import position.',
                );
                assertTrue(
                    str_contains($sql, 'SET FOREIGN_KEY_CHECKS=0;'),
                    'Each separate import session should disable foreign-key checks.',
                );
                assertTrue(
                    str_ends_with($sql, "SET COLLATION_CONNECTION=@POLITIKS_OLD_COLLATION_CONNECTION;\n"),
                    'Each part should restore its session settings.',
                );
                assertSameValue(
                    $part['sha256'],
                    hash_file('sha256', $path),
                    'Manifest checksum should match the generated gzip file.',
                );
            }

            $verification = SqlDumpSplitter::verify($prefix . '-parts.json');
            assertSameValue(true, $verification['verified'], 'The complete split should verify.');
            assertSameValue(5, $verification['files'], 'Verification should cover every part.');
            assertSameValue(strlen($source), $verification['payload_bytes'], 'Verification should reconstruct all bytes.');

            $rejected = false;
            try {
                SqlDumpSplitter::split($input, $prefix, 5);
            } catch (RuntimeException) {
                $rejected = true;
            }
            assertTrue($rejected, 'Existing split artifacts should not be overwritten without --force.');
        } finally {
            foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($directory);
        }
    },
];
