<?php

declare(strict_types=1);

namespace Politiks\App;

use RuntimeException;

final class Environment
{
    /** @return array<string, string> */
    public static function loadOptional(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        if (!is_readable($path)) {
            throw new RuntimeException('Die Konfigurationsdatei ist nicht lesbar.');
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new RuntimeException('Die Konfigurationsdatei konnte nicht gelesen werden.');
        }

        $values = [];
        foreach ($lines as $lineNumber => $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $trimmed, $matches) !== 1) {
                throw new RuntimeException(sprintf('Ungültige Konfigurationszeile %d.', $lineNumber + 1));
            }
            if (array_key_exists($matches[1], $values)) {
                throw new RuntimeException(sprintf('Doppelter Konfigurationswert %s.', $matches[1]));
            }
            $value = $matches[2];
            if (strlen($value) >= 2) {
                $first = $value[0];
                $last = $value[strlen($value) - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }
            $values[$matches[1]] = $value;
        }
        return $values;
    }

    /** @param array<string, string> $fileValues */
    public static function value(array $fileValues, string $key, ?string $default = null): ?string
    {
        $processValue = getenv($key);
        if ($processValue !== false) {
            return $processValue;
        }
        return $fileValues[$key] ?? $default;
    }
}
