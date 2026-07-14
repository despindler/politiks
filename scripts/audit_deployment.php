<?php

declare(strict_types=1);

$root = dirname(__DIR__);

/** @return array{status:int,stdout:string,stderr:string} */
function runAuditCommand(array $command, string $cwd): array
{
    $pipes = [];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    if (!is_resource($process)) {
        throw new RuntimeException('Ein Prüfprozess konnte nicht gestartet werden.');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [
        'status' => proc_close($process),
        'stdout' => $stdout === false ? '' : $stdout,
        'stderr' => $stderr === false ? '' : $stderr,
    ];
}

try {
    $listed = runAuditCommand(['git', 'ls-files', '--cached', '--others', '--exclude-standard', '-z', 'site'], $root);
    if ($listed['status'] !== 0) {
        throw new RuntimeException('Die versionierten Deployment-Dateien konnten nicht ermittelt werden.');
    }
    $files = array_values(array_filter(
        explode("\0", $listed['stdout']),
        static fn (string $path): bool => $path !== '' && is_file(dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path)),
    ));
    $required = [
        'site/.htaccess',
        'site/.env.example',
        'site/index.php',
        'site/backend/Application.php',
        'site/backend/Config.php',
        'site/database/schema.sql',
        'site/assets/app.css',
        'site/assets/app.js',
        'site/storage/cache/.gitkeep',
        'site/storage/logs/.gitkeep',
        'site/storage/uploads/.gitkeep',
    ];
    $problems = [];
    foreach ($required as $path) {
        if (!in_array($path, $files, true)) {
            $problems[] = sprintf('Erforderliche Datei fehlt: %s', $path);
        }
    }
    $forbiddenPath = '~(?:^|/)(?:tests?|node_modules|scripts?)(?:/|$)|(?:^|/)(?:router|Test[^/]*)\.php$|\.(?:sqlite3?|db|xlsx?|jsonl|ipynb)$~i';
    foreach ($files as $path) {
        if (preg_match($forbiddenPath, $path) === 1) {
            $problems[] = sprintf('Entwicklungs- oder Quelldatei im Deployment: %s', $path);
        }
        $basename = basename($path);
        if (str_starts_with($basename, '.env') && $basename !== '.env.example') {
            $problems[] = sprintf('Umgebungsdatei im Deployment: %s', $path);
        }
        $absolute = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
        if (is_file($absolute) && filesize($absolute) <= 2_000_000) {
            $content = file_get_contents($absolute);
            if (is_string($content) && str_contains($content, 'playwright-valid-google-credential')) {
                $problems[] = sprintf('Test-Zugangsdaten im Deployment: %s', $path);
            }
        }
        if (str_ends_with(strtolower($path), '.php')) {
            $lint = runAuditCommand([PHP_BINARY, '-l', $absolute], $root);
            if ($lint['status'] !== 0) {
                $problems[] = sprintf('PHP-Syntaxfehler: %s', $path);
            }
        }
    }
    $htaccess = file_get_contents($root . '/site/.htaccess');
    foreach (['Options -Indexes', 'backend|database|storage|logs', 'RewriteRule ^ index.php', 'X-Content-Type-Options'] as $rule) {
        if (!is_string($htaccess) || !str_contains($htaccess, $rule)) {
            $problems[] = sprintf('Apache-Schutzregel fehlt: %s', $rule);
        }
    }
    if ($problems !== []) {
        foreach ($problems as $problem) {
            fwrite(STDERR, '- ' . $problem . PHP_EOL);
        }
        exit(1);
    }
    echo json_encode([
        'deployment_ready' => true,
        'tracked_runtime_files' => count($files),
        'php_files_linted' => count(array_filter($files, static fn (string $path): bool => str_ends_with(strtolower($path), '.php'))),
        'test_credentials_absent' => true,
        'development_artifacts_absent' => true,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Deployment-Prüfung fehlgeschlagen: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
