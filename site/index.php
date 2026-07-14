<?php

declare(strict_types=1);

use Politiks\App\ApplicationFactory;

require __DIR__ . '/backend/bootstrap.php';

try {
    ApplicationFactory::create()->run();
} catch (Throwable $error) {
    error_log('Politiks startup failure: ' . $error::class);
    $isApi = str_starts_with((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/api/');
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
