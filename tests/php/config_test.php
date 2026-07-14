<?php

declare(strict_types=1);

require_once __DIR__ . '/../../site/backend/Environment.php';
require_once __DIR__ . '/../../site/backend/Config.php';

use Politiks\App\Config;

function productionConfigText(string $url = 'https://politiks.example'): string
{
    return implode("\n", [
        'APP_ENV=production',
        'APP_URL=' . $url,
        'APP_TIMEZONE=Europe/Zurich',
        'APP_SECRET=0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef',
        'APP_SESSION_NAME=politiks_session',
        'DB_HOST=127.0.0.1',
        'DB_PORT=3306',
        'DB_NAME=politiks',
        'DB_USER=politiks_app',
        'DB_PASSWORD=test-placeholder',
        'DB_CHARSET=utf8mb4',
        'GOOGLE_CLIENT_ID=production-client.apps.googleusercontent.com',
        'GOOGLE_JWKS_URL=https://www.googleapis.com/oauth2/v3/certs',
        'UPLOAD_MAX_BYTES=5242880',
        '',
    ]);
}

function withTemporaryConfig(string $contents, callable $callback): void
{
    $path = tempnam(sys_get_temp_dir(), 'politiks-config-');
    if ($path === false || file_put_contents($path, $contents) === false) {
        throw new TestFailure('Temporary configuration could not be created.');
    }
    try {
        $callback($path);
    } finally {
        @unlink($path);
    }
}

return [
    'production configuration accepts only a complete HTTPS origin' => static function (): void {
        withTemporaryConfig(productionConfigText(), static function (string $path): void {
            $config = Config::load($path);
            assertSameValue('production', $config->environment, 'Production environment should load.');
            assertTrue($config->usesSecureCookies(), 'Production cookies must be secure.');
        });
        withTemporaryConfig(productionConfigText('http://politiks.example'), static function (string $path): void {
            try {
                Config::load($path);
                throw new TestFailure('Production HTTP URL should be rejected.');
            } catch (RuntimeException $error) {
                assertTrue(str_contains($error->getMessage(), 'HTTPS'), 'The production URL error should mention HTTPS.');
            }
        });
    },
    'production configuration rejects the test authentication switch' => static function (): void {
        $previous = getenv('POLITIKS_TEST_AUTH');
        putenv('POLITIKS_TEST_AUTH=enabled');
        try {
            withTemporaryConfig(productionConfigText(), static function (string $path): void {
                try {
                    Config::load($path);
                    throw new TestFailure('Production test authentication should be rejected.');
                } catch (RuntimeException $error) {
                    assertTrue(str_contains($error->getMessage(), 'Test-Authentifizierung'), 'The test-auth error should be explicit.');
                }
            });
        } finally {
            $previous === false ? putenv('POLITIKS_TEST_AUTH') : putenv('POLITIKS_TEST_AUTH=' . $previous);
        }
    },
];
