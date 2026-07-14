<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$env = '.env.test';
$confirmed = false;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--env=')) {
        $env = substr($argument, 6);
    } elseif ($argument === '--reset-test-database') {
        $confirmed = true;
    } else {
        fwrite(STDERR, 'Unbekanntes Argument: ' . $argument . PHP_EOL);
        exit(2);
    }
}

$envPath = str_starts_with($env, '/') || str_starts_with($env, '\\')
    || preg_match('/^[A-Za-z]:[\\\\\/]/', $env) === 1
    ? $env
    : $root . DIRECTORY_SEPARATOR . $env;
if (!$confirmed || basename($envPath) !== '.env.test') {
    fwrite(STDERR, "Diese Prüfung setzt die Testdatenbank zurück. Verwende --reset-test-database und eine Datei namens .env.test.\n");
    exit(2);
}

/** @param list<string> $command */
function runMvpStep(array $command, string $cwd, string $label): void
{
    echo PHP_EOL, '== ', $label, ' ==', PHP_EOL;
    $process = proc_open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, $cwd);
    if (!is_resource($process) || proc_close($process) !== 0) {
        throw new RuntimeException(sprintf('Schritt fehlgeschlagen: %s', $label));
    }
}

try {
    runMvpStep([PHP_BINARY, 'scripts/bootstrap_mariadb.php', '--env=' . $envPath, '--reset'], $root, 'Saubere MariaDB-Testdatenbank');
    runMvpStep([PHP_BINARY, 'tests/php/seed_playwright_data.php'], $root, 'Deterministische Referenz- und Sichtbarkeitsdaten');
    $verificationCommand = ['npm', 'run', 'verify'];
    if (PHP_OS_FAMILY === 'Windows') {
        $verificationCommand = [];
        foreach (explode(PATH_SEPARATOR, getenv('PATH') ?: '') as $directory) {
            $directory = trim($directory, " \t\n\r\0\x0B\"");
            $node = $directory . DIRECTORY_SEPARATOR . 'node.exe';
            $npmCli = $directory . DIRECTORY_SEPARATOR . 'node_modules' . DIRECTORY_SEPARATOR . 'npm'
                . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'npm-cli.js';
            if (is_file($node) && is_file($npmCli)) {
                $verificationCommand = [$node, $npmCli, 'run', 'verify'];
                break;
            }
        }
        if ($verificationCommand === []) {
            throw new RuntimeException('Node.js/npm wurde im PATH nicht gefunden.');
        }
    }
    runMvpStep($verificationCommand, $root, 'PHP-, MariaDB-, Deployment- und Browserprüfung');
    echo PHP_EOL, "MVP-Akzeptanzprüfung vollständig bestanden.\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
