<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Politiks\\App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

if (getenv('APP_ENV') === 'test' && getenv('POLITIKS_TEST_AUTH') === 'enabled') {
    $testBootstrap = getenv('POLITIKS_TEST_AUTH_BOOTSTRAP');
    if (!is_string($testBootstrap) || $testBootstrap === '' || !is_file($testBootstrap)) {
        throw new RuntimeException('Der Test-Authentifizierungsadapter ist nicht verfügbar.');
    }
    require $testBootstrap;
}
