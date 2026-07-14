<?php

declare(strict_types=1);

namespace Politiks\Tooling;

use PDO;
use RuntimeException;

final class SqlScript
{
    public static function execute(PDO $connection, string $path): int
    {
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException(sprintf('SQL file is not readable: %s', $path));
        }
        $statements = self::statements($sql);
        $executed = 0;
        foreach ($statements as $statement) {
            $connection->exec($statement);
            $executed++;
        }
        return $executed;
    }

    /** @return list<string> */
    public static function statements(string $sql): array
    {
        $parts = preg_split('/;\s*(?:\r?\n|$)/', $sql);
        if ($parts === false) {
            throw new RuntimeException('Unable to split SQL script.');
        }
        $statements = [];
        foreach ($parts as $statement) {
            if (trim($statement) === '' || preg_match('/^\s*--[^\r\n]*\s*$/s', $statement) === 1) {
                continue;
            }
            $statements[] = $statement;
        }
        return $statements;
    }
}
