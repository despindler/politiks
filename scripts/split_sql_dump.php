<?php

declare(strict_types=1);

use Politiks\Tooling\SqlDumpSplitter;

require __DIR__ . '/lib/SqlDumpSplitter.php';

$root = dirname(__DIR__);
$inputPath = null;
$outputPrefix = null;
$partCount = 5;
$force = false;

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--input=')) {
        $inputPath = substr($argument, 8);
    } elseif (str_starts_with($argument, '--output-prefix=')) {
        $outputPrefix = substr($argument, 16);
    } elseif (str_starts_with($argument, '--parts=')) {
        $partCount = filter_var(substr($argument, 8), FILTER_VALIDATE_INT);
        if ($partCount === false) {
            fwrite(STDERR, "--parts must be an integer.\n");
            exit(2);
        }
    } elseif ($argument === '--force') {
        $force = true;
    } else {
        fwrite(STDERR, sprintf("Unknown argument: %s\n", $argument));
        exit(2);
    }
}

if ($inputPath === null || trim($inputPath) === '') {
    fwrite(STDERR, "Usage: php scripts/split_sql_dump.php --input=PATH [--output-prefix=PATH] [--parts=5] [--force]\n");
    exit(2);
}

$absolute = static function (string $path) use ($root): string {
    return str_starts_with($path, '/')
        || str_starts_with($path, '\\')
        || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1
        ? $path
        : $root . DIRECTORY_SEPARATOR . $path;
};

$inputPath = $absolute($inputPath);
if ($outputPrefix === null || trim($outputPrefix) === '') {
    $baseName = basename($inputPath);
    $baseName = preg_replace('/\.sql(?:\.gz)?$/i', '', $baseName) ?? $baseName;
    $outputPrefix = dirname($inputPath) . DIRECTORY_SEPARATOR . $baseName;
} else {
    $outputPrefix = $absolute($outputPrefix);
}

try {
    $manifest = SqlDumpSplitter::split($inputPath, $outputPrefix, $partCount, $force);
    echo json_encode(
        $manifest,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    ), PHP_EOL;
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'SQL dump split failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
