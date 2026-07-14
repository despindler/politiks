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

require $siteRoot . DIRECTORY_SEPARATOR . 'index.php';
return true;
