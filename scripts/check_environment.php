<?php

declare(strict_types=1);

const REQUIRED_PHP_VERSION = '8.4.0';
const REQUIRED_EXTENSIONS = ['pdo_mysql', 'openssl', 'curl', 'mbstring'];
const REQUIRED_ENV_KEYS = [
    'APP_ENV',
    'APP_URL',
    'APP_TIMEZONE',
    'APP_SECRET',
    'APP_SESSION_NAME',
    'DB_HOST',
    'DB_PORT',
    'DB_NAME',
    'DB_USER',
    'DB_PASSWORD',
    'DB_CHARSET',
    'GOOGLE_CLIENT_ID',
    'GOOGLE_JWKS_URL',
    'UPLOAD_MAX_BYTES',
    'TEST_BASE_URL',
];

/** @return array<string, string> */
function readEnvironmentNames(string $path): array
{
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException(sprintf('Environment file is not readable: %s', $path));
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        throw new RuntimeException(sprintf('Environment file could not be read: %s', $path));
    }

    $values = [];
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }

        if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*=/', $trimmed, $matches) === 1) {
            $values[$matches[1]] = 'present';
        }
    }

    return $values;
}

$root = dirname(__DIR__);
$envPath = $root . DIRECTORY_SEPARATOR . '.env.example';

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--env=')) {
        $candidate = substr($argument, strlen('--env='));
        $envPath = str_starts_with($candidate, DIRECTORY_SEPARATOR)
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $candidate) === 1
            ? $candidate
            : $root . DIRECTORY_SEPARATOR . $candidate;
    }
}

$problems = [];

printf("PHP CLI: %s (required: >= %s)\n", PHP_VERSION, REQUIRED_PHP_VERSION);
if (version_compare(PHP_VERSION, REQUIRED_PHP_VERSION, '<')) {
    $problems[] = sprintf('PHP %s or newer is required.', REQUIRED_PHP_VERSION);
}

foreach (REQUIRED_EXTENSIONS as $extension) {
    $loaded = extension_loaded($extension);
    printf("Extension %-10s %s\n", $extension . ':', $loaded ? 'available' : 'MISSING');
    if (!$loaded) {
        $problems[] = sprintf('PHP extension %s is required.', $extension);
    }
}

try {
    $environment = readEnvironmentNames($envPath);
    printf("Environment file: %s\n", $envPath);
    foreach (REQUIRED_ENV_KEYS as $key) {
        if (!array_key_exists($key, $environment)) {
            $problems[] = sprintf('Environment setting %s is missing.', $key);
        }
    }
    printf("Required setting names present: %d/%d\n", count(array_intersect(REQUIRED_ENV_KEYS, array_keys($environment))), count(REQUIRED_ENV_KEYS));
} catch (RuntimeException $error) {
    $problems[] = $error->getMessage();
}

if ($problems !== []) {
    fwrite(STDERR, "\nEnvironment is not ready:\n");
    foreach ($problems as $problem) {
        fwrite(STDERR, sprintf("- %s\n", $problem));
    }
    exit(1);
}

echo "\nEnvironment is ready.\n";
