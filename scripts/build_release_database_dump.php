<?php

declare(strict_types=1);

use Politiks\Tooling\Environment;
use Politiks\Tooling\MariaDb;
use Politiks\Tooling\SqlScript;

require __DIR__ . '/lib/Environment.php';
require __DIR__ . '/lib/MariaDb.php';
require __DIR__ . '/lib/SqlScript.php';

$root = dirname(__DIR__);
$envPath = null;
$inputPath = null;
$outputPath = null;
$confirmed = false;

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--env=')) {
        $envPath = resolvePath($root, substr($argument, 6));
    } elseif (str_starts_with($argument, '--input=')) {
        $inputPath = resolvePath($root, substr($argument, 8));
    } elseif (str_starts_with($argument, '--output=')) {
        $outputPath = resolvePath($root, substr($argument, 9));
    } elseif ($argument === '--replace-test-database') {
        $confirmed = true;
    } else {
        fwrite(STDERR, 'Unbekanntes Argument: ' . $argument . PHP_EOL);
        exit(2);
    }
}

try {
    if (!$confirmed || $envPath === null || basename($envPath) !== '.env.test') {
        throw new RuntimeException('Der Neuaufbau ist nur mit --replace-test-database und einer Datei namens .env.test erlaubt.');
    }
    if ($inputPath === null || !is_file($inputPath) || !str_ends_with(strtolower($inputPath), '.sql.gz')) {
        throw new RuntimeException('Ein vorhandener gzip-komprimierter SQL-Eingabedump ist erforderlich.');
    }
    if ($outputPath === null || !str_ends_with(strtolower($outputPath), '.sql.gz')) {
        throw new RuntimeException('Ein .sql.gz-Ausgabepfad ist erforderlich.');
    }
    if (is_file($outputPath)) {
        throw new RuntimeException('Die Ausgabedatei existiert bereits.');
    }

    $environment = Environment::load($envPath);
    Environment::requireKeys($environment, ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD']);
    $mysql = locateExecutable('mysql');
    $mysqldump = locateExecutable('mysqldump');
    $resetConnection = MariaDb::connect($environment);
    $tables = $resetConnection->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $resetConnection->exec('SET FOREIGN_KEY_CHECKS = 0');
    try {
        foreach ($tables as $table) {
            $resetConnection->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', (string) $table) . '`');
        }
    } finally {
        $resetConnection->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
    unset($resetConnection);
    withMysqlPassword($environment['DB_PASSWORD'], static function () use ($mysql, $environment, $inputPath): void {
        importGzipDump($mysql, $environment, $inputPath);
    });

    $connection = MariaDb::connect($environment);
    $schemaStatements = SqlScript::execute($connection, $root . '/database/mariadb/schema.sql');
    foreach (['app_user', 'insight', 'insight_member', 'insight_vote_evidence', 'insight_campaign_context', 'ai_filter_run', 'ai_filter_cache'] as $table) {
        $count = (int) $connection->query(sprintf('SELECT COUNT(*) FROM `%s`', $table))->fetchColumn();
        if ($count !== 0) {
            throw new RuntimeException(sprintf('Der Release-Dump darf keine Anwendungsdaten enthalten: %s hat %d Zeilen.', $table, $count));
        }
    }
    $activePublication = (int) $connection->query('SELECT active_publication_id FROM reference_state WHERE singleton_id = 1')->fetchColumn();
    if ($activePublication < 1) {
        throw new RuntimeException('Der importierte Dump enthält keine aktive Referenzpublikation.');
    }
    unset($connection);

    withMysqlPassword($environment['DB_PASSWORD'], static function () use ($mysqldump, $environment, $outputPath): void {
        exportGzipDump($mysqldump, $environment, $outputPath);
    });
    $bytes = filesize($outputPath);
    $sha256 = hash_file('sha256', $outputPath);
    if ($bytes === false || $sha256 === false) {
        throw new RuntimeException('Der fertige Dump konnte nicht geprüft werden.');
    }
    echo json_encode([
        'release_dump_ready' => true,
        'active_publication_id' => $activePublication,
        'schema_statements' => $schemaStatements,
        'gzip_bytes' => $bytes,
        'sha256' => $sha256,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
} catch (Throwable $error) {
    if ($outputPath !== null && is_file($outputPath)) {
        unlink($outputPath);
    }
    fwrite(STDERR, 'Release-Dump fehlgeschlagen: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}

function resolvePath(string $root, string $path): string
{
    return str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1
        ? $path
        : $root . DIRECTORY_SEPARATOR . $path;
}

function locateExecutable(string $name): string
{
    $command = PHP_OS_FAMILY === 'Windows' ? ['where.exe', $name . '.exe'] : ['sh', '-c', 'command -v ' . escapeshellarg($name)];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException(sprintf('%s konnte nicht gesucht werden.', $name));
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0 || !is_string($stdout)) {
        throw new RuntimeException(sprintf('%s ist nicht verfügbar: %s', $name, trim((string) $stderr)));
    }
    $path = trim(strtok($stdout, "\r\n") ?: '');
    if ($path === '' || !is_file($path)) {
        throw new RuntimeException(sprintf('%s wurde nicht gefunden.', $name));
    }
    return $path;
}

/** @param callable():void $callback */
function withMysqlPassword(string $password, callable $callback): void
{
    $previous = getenv('MYSQL_PWD');
    putenv('MYSQL_PWD=' . $password);
    try {
        $callback();
    } finally {
        $previous === false ? putenv('MYSQL_PWD') : putenv('MYSQL_PWD=' . $previous);
    }
}

/** @param array<string,string> $environment @return list<string> */
function mysqlConnectionArguments(array $environment): array
{
    return [
        '--host=' . $environment['DB_HOST'],
        '--port=' . $environment['DB_PORT'],
        '--user=' . $environment['DB_USER'],
        '--default-character-set=utf8mb4',
        $environment['DB_NAME'],
    ];
}

/** @param array<string,string> $environment */
function importGzipDump(string $mysql, array $environment, string $inputPath): void
{
    $process = proc_open(array_merge([$mysql, '--binary-mode=1'], mysqlConnectionArguments($environment)), [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Der MySQL-Importprozess konnte nicht gestartet werden.');
    }
    $input = gzopen($inputPath, 'rb');
    if ($input === false) {
        fclose($pipes[0]);
        proc_terminate($process);
        throw new RuntimeException('Der Eingabedump konnte nicht geöffnet werden.');
    }
    try {
        while (!gzeof($input)) {
            $chunk = gzread($input, 1024 * 1024);
            if ($chunk === false || ($chunk !== '' && fwrite($pipes[0], $chunk) === false)) {
                throw new RuntimeException('Der Eingabedump konnte nicht vollständig an MySQL übertragen werden.');
            }
        }
    } finally {
        gzclose($input);
        fclose($pipes[0]);
    }
    if (proc_close($process) !== 0) {
        throw new RuntimeException('MySQL hat den Import abgelehnt.');
    }
}

/** @param array<string,string> $environment */
function exportGzipDump(string $mysqldump, array $environment, string $outputPath): void
{
    $arguments = [
        $mysqldump,
        '--single-transaction',
        '--quick',
        '--routines',
        '--triggers',
        '--default-character-set=utf8mb4',
        '--set-gtid-purged=OFF',
        '--column-statistics=0',
        '--no-tablespaces',
    ];
    $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $process = proc_open(array_merge($arguments, mysqlConnectionArguments($environment)), [0 => ['file', $nullDevice, 'r'], 1 => ['pipe', 'w'], 2 => STDERR], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('mysqldump konnte nicht gestartet werden.');
    }
    $output = gzopen($outputPath, 'wb9');
    if ($output === false) {
        fclose($pipes[1]);
        proc_terminate($process);
        throw new RuntimeException('Die gzip-Ausgabedatei konnte nicht erstellt werden.');
    }
    try {
        while (!feof($pipes[1])) {
            $chunk = fread($pipes[1], 1024 * 1024);
            if ($chunk === false || ($chunk !== '' && gzwrite($output, $chunk) === false)) {
                throw new RuntimeException('Der Dump konnte nicht vollständig komprimiert werden.');
            }
        }
    } finally {
        fclose($pipes[1]);
        gzclose($output);
    }
    if (proc_close($process) !== 0) {
        throw new RuntimeException('mysqldump ist fehlgeschlagen.');
    }
}
