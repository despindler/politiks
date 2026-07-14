<?php

declare(strict_types=1);

namespace Politiks\Tooling;

use PDO;
use RuntimeException;

final class MariaDb
{
    /** @param array<string, string> $environment */
    public static function connect(array $environment, bool $selectDatabase = true): PDO
    {
        Environment::requireKeys(
            $environment,
            ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_CHARSET']
        );
        if ($environment['DB_CHARSET'] !== 'utf8mb4') {
            throw new RuntimeException('DB_CHARSET must be utf8mb4.');
        }
        if (!ctype_digit($environment['DB_PORT'])) {
            throw new RuntimeException('DB_PORT must be numeric.');
        }

        if (preg_match('/^[A-Za-z0-9_]+$/', $environment['DB_NAME']) !== 1) {
            throw new RuntimeException('DB_NAME may contain only letters, digits, and underscores.');
        }
        $dsn = sprintf('mysql:host=%s;port=%s;', $environment['DB_HOST'], $environment['DB_PORT']);
        if ($selectDatabase) {
            $dsn .= sprintf('dbname=%s;', $environment['DB_NAME']);
        }
        $dsn .= 'charset=utf8mb4';
        return new PDO(
            $dsn,
            $environment['DB_USER'],
            $environment['DB_PASSWORD'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]
        );
    }
}
