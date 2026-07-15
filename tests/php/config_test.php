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
    $isolatedKeys = [
        'AI_FILTER_ENABLED',
        'OPENAI_API_KEY',
        'OPENAI_RESPONSES_URL',
        'OPENAI_MODEL',
        'AI_FILTER_TIMEOUT_SECONDS',
        'AI_FILTER_MAX_OUTPUT_TOKENS',
        'AI_FILTER_CANDIDATE_LIMIT',
        'AI_FILTER_CHUNK_SIZE',
        'AI_FILTER_CACHE_TTL_SECONDS',
        'AI_FILTER_HOURLY_LIMIT',
    ];
    $previousValues = [];
    foreach ($isolatedKeys as $key) {
        $previousValues[$key] = getenv($key);
        putenv($key);
    }
    try {
        $callback($path);
    } finally {
        foreach ($previousValues as $key => $value) {
            $value === false ? putenv($key) : putenv($key . '=' . $value);
        }
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
    'AI filter remains off by default and requires a key when enabled' => static function (): void {
        withTemporaryConfig(productionConfigText(), static function (string $path): void {
            $config = Config::load($path);
            assertSameValue(false, $config->aiFilter['enabled'], 'AI filtering must be opt-in.');
            assertSameValue(null, $config->aiFilter['api_key'], 'A disabled filter must not require a key.');
            assertSameValue('gpt-5.6-luna', $config->aiFilter['model'], 'The documented model default should load.');
        });

        withTemporaryConfig(
            productionConfigText() . "AI_FILTER_ENABLED=1\n",
            static function (string $path): void {
                try {
                    Config::load($path);
                    throw new TestFailure('An enabled AI filter without a key should be rejected.');
                } catch (RuntimeException $error) {
                    assertTrue(str_contains($error->getMessage(), 'OPENAI_API_KEY'), 'The missing-key error should be explicit.');
                }
            },
        );
    },
    'AI filter configuration accepts bounded explicit settings' => static function (): void {
        $aiSettings = implode("\n", [
            'AI_FILTER_ENABLED=1',
            'OPENAI_API_KEY=test-only-openai-key-0123456789',
            'OPENAI_RESPONSES_URL=https://api.openai.com/v1/responses',
            'OPENAI_MODEL=gpt-test-model',
            'AI_FILTER_TIMEOUT_SECONDS=45',
            'AI_FILTER_MAX_OUTPUT_TOKENS=2048',
            'AI_FILTER_CANDIDATE_LIMIT=250',
            'AI_FILTER_CHUNK_SIZE=50',
            'AI_FILTER_CACHE_TTL_SECONDS=1800',
            'AI_FILTER_HOURLY_LIMIT=7',
            '',
        ]);
        withTemporaryConfig(productionConfigText() . $aiSettings, static function (string $path): void {
            $config = Config::load($path);
            assertSameValue(true, $config->aiFilter['enabled'], 'AI filtering should be enabled.');
            assertSameValue('gpt-test-model', $config->aiFilter['model'], 'The configured model should load.');
            assertSameValue(50, $config->aiFilter['chunk_size'], 'The configured chunk size should load.');
            assertSameValue(7, $config->aiFilter['hourly_limit'], 'The configured rate limit should load.');
        });
    },
    'AI filter configuration rejects unsafe endpoints and invalid bounds' => static function (): void {
        foreach ([
            "OPENAI_RESPONSES_URL=http://api.openai.com/v1/responses\n",
            "AI_FILTER_CANDIDATE_LIMIT=24\n",
            "AI_FILTER_CANDIDATE_LIMIT=25\nAI_FILTER_CHUNK_SIZE=26\n",
        ] as $invalidSettings) {
            withTemporaryConfig(productionConfigText() . $invalidSettings, static function (string $path): void {
                $rejected = false;
                try {
                    Config::load($path);
                } catch (RuntimeException) {
                    $rejected = true;
                }
                assertTrue($rejected, 'Unsafe AI settings must be rejected.');
            });
        }
    },
];
