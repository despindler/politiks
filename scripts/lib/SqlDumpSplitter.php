<?php

declare(strict_types=1);

namespace Politiks\Tooling;

use RuntimeException;

final class SqlDumpSplitter
{
    private const PREAMBLE_TEMPLATE = <<<'SQL'
-- Politiks phpMyAdmin import part %d of %d
-- Source: %s
-- Import every part exactly once, in numeric order, into a clean database.
SET @POLITIKS_OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT;
SET @POLITIKS_OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS;
SET @POLITIKS_OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION;
SET @POLITIKS_OLD_TIME_ZONE=@@TIME_ZONE;
SET @POLITIKS_OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS;
SET @POLITIKS_OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS;
SET @POLITIKS_OLD_SQL_MODE=@@SQL_MODE;
SET @POLITIKS_OLD_SQL_NOTES=@@SQL_NOTES;
-- The source mysqldump footer references these conventional variables. Define
-- them in every independent phpMyAdmin session, including the final part.
SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT;
SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS;
SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION;
SET @OLD_TIME_ZONE=@@TIME_ZONE;
SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS;
SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS;
SET @OLD_SQL_MODE=@@SQL_MODE;
SET @OLD_SQL_NOTES=@@SQL_NOTES;
SET NAMES utf8mb4;
SET TIME_ZONE='+00:00';
SET UNIQUE_CHECKS=0;
SET FOREIGN_KEY_CHECKS=0;
SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';
SET SQL_NOTES=0;

SQL;

    private const FOOTER = <<<'SQL'

-- End of this Politiks import part.
UNLOCK TABLES;
SET SQL_NOTES=@POLITIKS_OLD_SQL_NOTES;
SET SQL_MODE=@POLITIKS_OLD_SQL_MODE;
SET FOREIGN_KEY_CHECKS=@POLITIKS_OLD_FOREIGN_KEY_CHECKS;
SET UNIQUE_CHECKS=@POLITIKS_OLD_UNIQUE_CHECKS;
SET TIME_ZONE=@POLITIKS_OLD_TIME_ZONE;
SET CHARACTER_SET_CLIENT=@POLITIKS_OLD_CHARACTER_SET_CLIENT;
SET CHARACTER_SET_RESULTS=@POLITIKS_OLD_CHARACTER_SET_RESULTS;
SET COLLATION_CONNECTION=@POLITIKS_OLD_COLLATION_CONNECTION;

SQL;

    /**
     * @return array{
     *   source_file: string,
     *   source_bytes: int,
     *   source_sha256: string,
     *   part_count: int,
     *   parts: list<array{
     *     part: int,
     *     file: string,
     *     payload_start_byte: int,
     *     payload_end_byte: int,
     *     payload_bytes: int,
     *     sql_bytes: int,
     *     gzip_bytes: int,
     *     sha256: string
     *   }>
     * }
     */
    public static function split(
        string $inputPath,
        string $outputPrefix,
        int $partCount = 5,
        bool $overwrite = false,
    ): array {
        if ($partCount < 2 || $partCount > 99) {
            throw new RuntimeException('Part count must be between 2 and 99.');
        }
        if (!is_file($inputPath) || !is_readable($inputPath)) {
            throw new RuntimeException(sprintf('SQL dump is not readable: %s', $inputPath));
        }

        $outputDirectory = dirname($outputPrefix);
        if (!is_dir($outputDirectory) || !is_writable($outputDirectory)) {
            throw new RuntimeException(sprintf('Output directory is not writable: %s', $outputDirectory));
        }

        $targets = [];
        for ($part = 1; $part <= $partCount; $part++) {
            $targets[$part] = sprintf(
                '%s-part-%02d-of-%02d.sql.gz',
                $outputPrefix,
                $part,
                $partCount,
            );
            if (!$overwrite && file_exists($targets[$part])) {
                throw new RuntimeException(sprintf('Output already exists: %s', $targets[$part]));
            }
        }

        $manifestPath = $outputPrefix . '-parts.json';
        if (!$overwrite && file_exists($manifestPath)) {
            throw new RuntimeException(sprintf('Output already exists: %s', $manifestPath));
        }

        [$sourceBytes, $sourceSha256, $statementBoundaries] = self::inspect($inputPath);
        if ($sourceBytes === 0) {
            throw new RuntimeException('SQL dump is empty.');
        }
        if ($statementBoundaries < $partCount - 1) {
            throw new RuntimeException(sprintf(
                'SQL dump has only %d safe statement boundaries; cannot create %d non-empty parts.',
                $statementBoundaries,
                $partCount,
            ));
        }

        $temporaryPaths = [];
        $metadata = [];
        $sourceName = basename($inputPath);
        $part = 1;
        $processedBytes = 0;
        $partStartByte = 0;
        $partPayloadBytes = 0;
        $partSqlBytes = 0;
        $handle = null;
        $temporaryPath = '';

        try {
            [$handle, $temporaryPath, $partSqlBytes] = self::openPart(
                $targets[$part],
                $part,
                $partCount,
                $sourceName,
            );
            $temporaryPaths[] = $temporaryPath;

            foreach (self::lines($inputPath) as $line) {
                self::writeGzip($handle, $line);
                $lineBytes = strlen($line);
                $processedBytes += $lineBytes;
                $partPayloadBytes += $lineBytes;
                $partSqlBytes += $lineBytes;

                $threshold = intdiv($sourceBytes * $part, $partCount);
                if (
                    $part < $partCount
                    && $processedBytes >= $threshold
                    && self::endsStatement($line)
                ) {
                    $metadata[] = self::closePart(
                        $handle,
                        $temporaryPath,
                        $targets[$part],
                        $part,
                        $partStartByte,
                        $processedBytes,
                        $partPayloadBytes,
                        $partSqlBytes,
                    );
                    $handle = null;
                    $part++;
                    $partStartByte = $processedBytes;
                    $partPayloadBytes = 0;
                    [$handle, $temporaryPath, $partSqlBytes] = self::openPart(
                        $targets[$part],
                        $part,
                        $partCount,
                        $sourceName,
                    );
                    $temporaryPaths[] = $temporaryPath;
                }
            }

            if ($part !== $partCount) {
                throw new RuntimeException(sprintf(
                    'Reached the end of the dump after creating %d of %d requested parts.',
                    $part,
                    $partCount,
                ));
            }

            $metadata[] = self::closePart(
                $handle,
                $temporaryPath,
                $targets[$part],
                $part,
                $partStartByte,
                $processedBytes,
                $partPayloadBytes,
                $partSqlBytes,
            );
            $handle = null;

            $manifest = [
                'source_file' => $sourceName,
                'source_bytes' => $sourceBytes,
                'source_sha256' => $sourceSha256,
                'part_count' => $partCount,
                'parts' => $metadata,
            ];
            $encoded = json_encode(
                $manifest,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ) . PHP_EOL;
            self::writeAtomic($manifestPath, $encoded, $overwrite);

            return $manifest;
        } catch (\Throwable $error) {
            if (is_resource($handle)) {
                gzclose($handle);
            }
            foreach ($temporaryPaths as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
            throw $error;
        }
    }

    /** @return array{verified: bool, files: int, payload_bytes: int, source_sha256: string} */
    public static function verify(string $manifestPath): array
    {
        $json = file_get_contents($manifestPath);
        if ($json === false) {
            throw new RuntimeException(sprintf('Split manifest is not readable: %s', $manifestPath));
        }
        $manifest = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($manifest) || !isset(
            $manifest['source_file'],
            $manifest['source_bytes'],
            $manifest['source_sha256'],
            $manifest['part_count'],
            $manifest['parts'],
        ) || !is_array($manifest['parts'])) {
            throw new RuntimeException('Split manifest has an invalid structure.');
        }

        $partCount = (int) $manifest['part_count'];
        if ($partCount < 2 || count($manifest['parts']) !== $partCount) {
            throw new RuntimeException('Split manifest part count does not match its file list.');
        }

        $sourceHash = hash_init('sha256');
        $payloadBytes = 0;
        $expectedStart = 0;
        $directory = dirname($manifestPath);

        foreach ($manifest['parts'] as $offset => $partMetadata) {
            if (!is_array($partMetadata)) {
                throw new RuntimeException('Split manifest contains invalid part metadata.');
            }
            $part = $offset + 1;
            $file = (string) ($partMetadata['file'] ?? '');
            if ($file === '' || basename($file) !== $file || (int) ($partMetadata['part'] ?? 0) !== $part) {
                throw new RuntimeException(sprintf('Split manifest part %d has an unsafe or mismatched filename.', $part));
            }
            $path = $directory . DIRECTORY_SEPARATOR . $file;
            $gzipBytes = filesize($path);
            $gzipHash = hash_file('sha256', $path);
            if (
                $gzipBytes === false
                || $gzipBytes !== (int) ($partMetadata['gzip_bytes'] ?? -1)
                || $gzipHash === false
                || !hash_equals((string) ($partMetadata['sha256'] ?? ''), $gzipHash)
            ) {
                throw new RuntimeException(sprintf('Split part %d gzip size or checksum does not match.', $part));
            }

            $payloadStart = (int) ($partMetadata['payload_start_byte'] ?? -1);
            $payloadEnd = (int) ($partMetadata['payload_end_byte'] ?? -1);
            $partPayloadBytes = (int) ($partMetadata['payload_bytes'] ?? -1);
            if (
                $payloadStart !== $expectedStart
                || $payloadEnd - $payloadStart !== $partPayloadBytes
                || $partPayloadBytes <= 0
            ) {
                throw new RuntimeException(sprintf('Split part %d payload byte range is invalid.', $part));
            }

            $handle = gzopen($path, 'rb');
            if ($handle === false) {
                throw new RuntimeException(sprintf('Unable to decompress split part %d.', $part));
            }
            try {
                $preamble = sprintf(
                    self::PREAMBLE_TEMPLATE,
                    $part,
                    $partCount,
                    (string) $manifest['source_file'],
                );
                if (self::readGzipExact($handle, strlen($preamble)) !== $preamble) {
                    throw new RuntimeException(sprintf('Split part %d preamble does not match.', $part));
                }
                self::hashGzipExact($handle, $partPayloadBytes, $sourceHash);
                if (self::readGzipExact($handle, strlen(self::FOOTER)) !== self::FOOTER) {
                    throw new RuntimeException(sprintf('Split part %d footer does not match.', $part));
                }
                if (gzread($handle, 1) !== '') {
                    throw new RuntimeException(sprintf('Split part %d contains untracked trailing bytes.', $part));
                }
            } finally {
                gzclose($handle);
            }

            $payloadBytes += $partPayloadBytes;
            $expectedStart = $payloadEnd;
        }

        $actualSourceHash = hash_final($sourceHash);
        if (
            $payloadBytes !== (int) $manifest['source_bytes']
            || !hash_equals((string) $manifest['source_sha256'], $actualSourceHash)
        ) {
            throw new RuntimeException('Combined split payload does not reproduce the source SQL dump.');
        }

        return [
            'verified' => true,
            'files' => $partCount,
            'payload_bytes' => $payloadBytes,
            'source_sha256' => $actualSourceHash,
        ];
    }

    /** @return array{int, string, int} */
    private static function inspect(string $path): array
    {
        $bytes = 0;
        $boundaries = 0;
        $hash = hash_init('sha256');
        foreach (self::lines($path) as $line) {
            $bytes += strlen($line);
            hash_update($hash, $line);
            if (self::endsStatement($line)) {
                $boundaries++;
            }
        }
        return [$bytes, hash_final($hash), $boundaries];
    }

    /** @return iterable<string> */
    private static function lines(string $path): iterable
    {
        $gzip = str_ends_with(strtolower($path), '.gz');
        $handle = $gzip ? gzopen($path, 'rb') : fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException(sprintf('Unable to open SQL dump: %s', $path));
        }

        try {
            if ($gzip) {
                while (!gzeof($handle)) {
                    $line = gzgets($handle);
                    if ($line === false) {
                        break;
                    }
                    yield $line;
                }
                return;
            }

            while (($line = fgets($handle)) !== false) {
                yield $line;
            }
        } finally {
            $gzip ? gzclose($handle) : fclose($handle);
        }
    }

    private static function endsStatement(string $line): bool
    {
        return str_ends_with(rtrim($line), ';');
    }

    /** @return array{resource, string, int} */
    private static function openPart(
        string $target,
        int $part,
        int $partCount,
        string $sourceName,
    ): array {
        $temporaryPath = $target . '.tmp-' . bin2hex(random_bytes(6));
        $handle = gzopen($temporaryPath, 'wb9');
        if ($handle === false) {
            throw new RuntimeException(sprintf('Unable to create gzip output: %s', $target));
        }
        $preamble = sprintf(self::PREAMBLE_TEMPLATE, $part, $partCount, $sourceName);
        self::writeGzip($handle, $preamble);
        return [$handle, $temporaryPath, strlen($preamble)];
    }

    /**
     * @param resource $handle
     * @return array{
     *   part: int,
     *   file: string,
     *   payload_start_byte: int,
     *   payload_end_byte: int,
     *   payload_bytes: int,
     *   sql_bytes: int,
     *   gzip_bytes: int,
     *   sha256: string
     * }
     */
    private static function closePart(
        $handle,
        string $temporaryPath,
        string $target,
        int $part,
        int $payloadStartByte,
        int $payloadEndByte,
        int $payloadBytes,
        int $sqlBytes,
    ): array {
        self::writeGzip($handle, self::FOOTER);
        $sqlBytes += strlen(self::FOOTER);
        if (!gzclose($handle)) {
            throw new RuntimeException(sprintf('Unable to finalize gzip output: %s', $target));
        }
        if (file_exists($target) && !unlink($target)) {
            throw new RuntimeException(sprintf('Unable to replace output: %s', $target));
        }
        if (!rename($temporaryPath, $target)) {
            throw new RuntimeException(sprintf('Unable to promote gzip output: %s', $target));
        }
        $gzipBytes = filesize($target);
        $sha256 = hash_file('sha256', $target);
        if ($gzipBytes === false || $sha256 === false) {
            throw new RuntimeException(sprintf('Unable to checksum gzip output: %s', $target));
        }
        return [
            'part' => $part,
            'file' => basename($target),
            'payload_start_byte' => $payloadStartByte,
            'payload_end_byte' => $payloadEndByte,
            'payload_bytes' => $payloadBytes,
            'sql_bytes' => $sqlBytes,
            'gzip_bytes' => $gzipBytes,
            'sha256' => $sha256,
        ];
    }

    /** @param resource $handle */
    private static function writeGzip($handle, string $contents): void
    {
        $offset = 0;
        $length = strlen($contents);
        while ($offset < $length) {
            $written = gzwrite($handle, substr($contents, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Unable to write gzip output.');
            }
            $offset += $written;
        }
    }

    private static function writeAtomic(string $target, string $contents, bool $overwrite): void
    {
        if (!$overwrite && file_exists($target)) {
            throw new RuntimeException(sprintf('Output already exists: %s', $target));
        }
        $temporaryPath = $target . '.tmp-' . bin2hex(random_bytes(6));
        if (file_put_contents($temporaryPath, $contents) !== strlen($contents)) {
            @unlink($temporaryPath);
            throw new RuntimeException(sprintf('Unable to write manifest: %s', $target));
        }
        if (file_exists($target) && !unlink($target)) {
            @unlink($temporaryPath);
            throw new RuntimeException(sprintf('Unable to replace manifest: %s', $target));
        }
        if (!rename($temporaryPath, $target)) {
            @unlink($temporaryPath);
            throw new RuntimeException(sprintf('Unable to promote manifest: %s', $target));
        }
    }

    /** @param resource $handle */
    private static function readGzipExact($handle, int $length): string
    {
        $contents = '';
        while (strlen($contents) < $length) {
            $chunk = gzread($handle, $length - strlen($contents));
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('Compressed SQL part ended unexpectedly.');
            }
            $contents .= $chunk;
        }
        return $contents;
    }

    /** @param resource $handle */
    private static function hashGzipExact($handle, int $length, \HashContext $hash): void
    {
        $remaining = $length;
        while ($remaining > 0) {
            $chunk = gzread($handle, min($remaining, 1024 * 1024));
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('Compressed SQL payload ended unexpectedly.');
            }
            $remaining -= strlen($chunk);
            hash_update($hash, $chunk);
        }
    }
}
