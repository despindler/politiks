<?php

declare(strict_types=1);

$siteRoot = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'site';
$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
$protected = preg_match('~^/(?:backend|database|storage|logs)(?:/|$)~i', $path) === 1
    || preg_match('~/(?:\.env(?:\.[^/]*)?|[^/]*\.(?:sql|log))(?:$|/)~i', $path) === 1;

if ($protected) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Nicht gefunden.\n";
    return true;
}

$candidate = $siteRoot . str_replace('/', DIRECTORY_SEPARATOR, $path);
if ($path !== '/' && is_file($candidate)) {
    return false;
}

$bootstrap = $siteRoot . DIRECTORY_SEPARATOR . 'backend' . DIRECTORY_SEPARATOR . 'bootstrap.php';
require $bootstrap;
require __DIR__ . DIRECTORY_SEPARATOR . 'TestAiResponsesClient.php';

try {
    Politiks\App\ApplicationFactory::create(new Politiks\App\Ai\TestAiResponsesClient())->run();
} catch (Throwable $error) {
    error_log('Politiks test startup failure: ' . $error::class);
    $isApi = str_starts_with($path, '/api/');
    http_response_code(503);
    header('Cache-Control: no-store');
    if ($isApi) {
        header('Content-Type: application/json; charset=utf-8');
        echo '{"ok":false,"error_code":"APP_UNAVAILABLE","message":"Politiks ist vorübergehend nicht verfügbar.","details":{}}';
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="de"><meta charset="utf-8"><title>Politiks</title>'
            . '<main><h1>Politiks</h1><p>Die Anwendung ist vorübergehend nicht verfügbar.</p></main></html>';
    }
}
return true;
