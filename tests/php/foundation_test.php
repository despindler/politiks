<?php

declare(strict_types=1);

return [
    'deployment root contains index.php' => static function (): void {
        assertTrue(is_file(__DIR__ . '/../../site/index.php'), 'site/index.php must exist.');
    },
    'environment example contains required keys' => static function (): void {
        $content = file_get_contents(__DIR__ . '/../../.env.example');
        assertTrue($content !== false, '.env.example must be readable.');

        $required = [
            'APP_ENV',
            'APP_URL',
            'APP_TIMEZONE',
            'APP_SECRET',
            'APP_SESSION_NAME',
            'DB_HOST',
            'DB_PORT',
            'DB_NAME',
            'DB_USER',
            'DB_PASSWORD',
            'DB_CHARSET',
            'GOOGLE_CLIENT_ID',
            'GOOGLE_JWKS_URL',
            'UPLOAD_MAX_BYTES',
            'TEST_BASE_URL',
        ];

        foreach ($required as $key) {
            assertTrue(
                preg_match('/^' . preg_quote($key, '/') . '=/m', $content) === 1,
                sprintf('.env.example is missing %s.', $key),
            );
        }
    },
    'secret environment files are ignored' => static function (): void {
        $ignore = file_get_contents(__DIR__ . '/../../.gitignore');
        assertTrue($ignore !== false, '.gitignore must be readable.');
        assertTrue(str_contains($ignore, '.env*'), '.gitignore must ignore .env variants.');
        assertTrue(str_contains($ignore, '!.env.example'), '.env.example must remain trackable.');
    },
];
