<?php

declare(strict_types=1);

use Politiks\Tooling\Environment;
use Politiks\Tooling\MariaDb;
use Politiks\Tooling\ReferencePublisher;

require __DIR__ . '/lib/Environment.php';
require __DIR__ . '/lib/MariaDb.php';
require __DIR__ . '/lib/ReferencePublisher.php';

$root = dirname(__DIR__);
$envPath = $root . DIRECTORY_SEPARATOR . '.env.test';
$sqlitePath = $root . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'parliament.sqlite';
$simulateFailureAfter = null;
$testKeySuffix = null;

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--env=')) {
        $candidate = substr($argument, 6);
        $envPath = preg_match('~^(?:[A-Za-z]:[\\/]|[\\/])~', $candidate) === 1
            ? $candidate
            : $root . DIRECTORY_SEPARATOR . $candidate;
    } elseif (str_starts_with($argument, '--sqlite=')) {
        $candidate = substr($argument, 9);
        $sqlitePath = preg_match('~^(?:[A-Za-z]:[\\/]|[\\/])~', $candidate) === 1
            ? $candidate
            : $root . DIRECTORY_SEPARATOR . $candidate;
    } elseif (str_starts_with($argument, '--simulate-failure-after=')) {
        $simulateFailureAfter = substr($argument, 25);
    } elseif (str_starts_with($argument, '--test-key-suffix=')) {
        $testKeySuffix = substr($argument, 18);
    } else {
        fwrite(STDERR, sprintf("Unknown argument: %s\n", $argument));
        exit(2);
    }
}

try {
    if (!is_file($sqlitePath)) {
        throw new RuntimeException('Generated SQLite database not found. Recreate it before publication.');
    }
    if (($simulateFailureAfter !== null || $testKeySuffix !== null) && basename($envPath) !== '.env.test') {
        throw new RuntimeException('Failure simulation and key suffixes are allowed only with .env.test.');
    }
    if (($simulateFailureAfter === null) !== ($testKeySuffix === null)) {
        throw new RuntimeException('Failure simulation requires a unique --test-key-suffix and vice versa.');
    }

    $environment = Environment::load($envPath);
    $destination = MariaDb::connect($environment);
    $source = new PDO('sqlite:' . $sqlitePath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $publisher = new ReferencePublisher($source, $destination);
    $result = $publisher->publish($simulateFailureAfter, $testKeySuffix);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'Reference publication failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
