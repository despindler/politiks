<?php

declare(strict_types=1);

use Politiks\Tooling\SqlDumpSplitter;

require __DIR__ . '/lib/SqlDumpSplitter.php';

$root = dirname(__DIR__);
$manifestPath = null;

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--manifest=')) {
        $manifestPath = substr($argument, 11);
    } else {
        fwrite(STDERR, sprintf("Unknown argument: %s\n", $argument));
        exit(2);
    }
}

if ($manifestPath === null || trim($manifestPath) === '') {
    fwrite(STDERR, "Usage: php scripts/verify_split_sql_dump.php --manifest=PATH\n");
    exit(2);
}

if (
    !str_starts_with($manifestPath, '/')
    && !str_starts_with($manifestPath, '\\')
    && preg_match('/^[A-Za-z]:[\\\\\/]/', $manifestPath) !== 1
) {
    $manifestPath = $root . DIRECTORY_SEPARATOR . $manifestPath;
}

try {
    echo json_encode(
        SqlDumpSplitter::verify($manifestPath),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    ), PHP_EOL;
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'Split SQL verification failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
