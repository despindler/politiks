<?php

declare(strict_types=1);

namespace Politiks\Tooling;

use RuntimeException;

final class Environment
{
    /** @return array<string, string> */
    public static function load(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException(sprintf('Environment file is not readable: %s', $path));
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new RuntimeException(sprintf('Environment file could not be read: %s', $path));
        }

        $values = [];
        foreach ($lines as $lineNumber => $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $trimmed, $matches) !== 1) {
                throw new RuntimeException(sprintf('Invalid environment line %d.', $lineNumber + 1));
            }
            $key = $matches[1];
            if (array_key_exists($key, $values)) {
                throw new RuntimeException(sprintf('Duplicate environment setting %s.', $key));
            }
            $value = $matches[2];
            if (strlen($value) >= 2) {
                $first = $value[0];
                $last = $value[strlen($value) - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }
            $values[$key] = $value;
        }

        return $values;
    }

    /** @param array<string, string> $values @param list<string> $keys */
    public static function requireKeys(array $values, array $keys): void
    {
        $missing = [];
        foreach ($keys as $key) {
            if (!array_key_exists($key, $values) || ($key !== 'DB_PASSWORD' && $values[$key] === '')) {
                $missing[] = $key;
            }
        }
        if ($missing !== []) {
            throw new RuntimeException('Missing required environment settings: ' . implode(', ', $missing));
        }
    }
}
