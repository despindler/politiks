<?php

declare(strict_types=1);

namespace Politiks\App;

use DateTimeZone;
use RuntimeException;

final class Config
{
    /** @param array<string, string> $database */
    private function __construct(
        public readonly string $environment,
        public readonly string $appUrl,
        public readonly string $timezone,
        public readonly string $appSecret,
        public readonly string $sessionName,
        public readonly array $database,
        public readonly ?string $googleClientId,
        public readonly string $googleJwksUrl,
        public readonly string $storagePath,
        public readonly bool $testAuthEnabled,
    ) {
    }

    public static function load(?string $path = null): self
    {
        $siteRoot = dirname(__DIR__);
        $configuredPath = getenv('POLITIKS_ENV_FILE');
        $path ??= $configuredPath !== false && $configuredPath !== '' ? $configuredPath : $siteRoot . '/.env';
        $values = Environment::loadOptional($path);

        $required = static function (string $key) use ($values): string {
            $value = Environment::value($values, $key);
            if ($value === null || $value === '') {
                throw new RuntimeException(sprintf('Konfigurationswert %s fehlt.', $key));
            }
            return $value;
        };

        $environment = $required('APP_ENV');
        if (!in_array($environment, ['production', 'development', 'test'], true)) {
            throw new RuntimeException('APP_ENV ist ungültig.');
        }
        $appUrl = rtrim($required('APP_URL'), '/');
        if (filter_var($appUrl, FILTER_VALIDATE_URL) === false) {
            throw new RuntimeException('APP_URL ist ungültig.');
        }
        $timezone = $required('APP_TIMEZONE');
        new DateTimeZone($timezone);
        $secret = $required('APP_SECRET');
        if (strlen($secret) < 32) {
            throw new RuntimeException('APP_SECRET muss mindestens 32 Zeichen lang sein.');
        }
        $sessionName = $required('APP_SESSION_NAME');
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]{2,63}$/', $sessionName) !== 1) {
            throw new RuntimeException('APP_SESSION_NAME ist ungültig.');
        }
        $dbPort = $required('DB_PORT');
        if (!ctype_digit($dbPort)) {
            throw new RuntimeException('DB_PORT muss numerisch sein.');
        }
        $dbName = $required('DB_NAME');
        if (preg_match('/^[A-Za-z0-9_]+$/', $dbName) !== 1) {
            throw new RuntimeException('DB_NAME ist ungültig.');
        }
        $charset = $required('DB_CHARSET');
        if ($charset !== 'utf8mb4') {
            throw new RuntimeException('DB_CHARSET muss utf8mb4 sein.');
        }
        $clientId = Environment::value($values, 'GOOGLE_CLIENT_ID', '');
        $clientId = $clientId === '' ? null : $clientId;
        if ($clientId !== null && (strlen($clientId) > 512 || !str_ends_with($clientId, '.apps.googleusercontent.com'))) {
            throw new RuntimeException('GOOGLE_CLIENT_ID ist ungültig.');
        }
        $jwksUrl = $required('GOOGLE_JWKS_URL');
        if (filter_var($jwksUrl, FILTER_VALIDATE_URL) === false || !str_starts_with($jwksUrl, 'https://')) {
            throw new RuntimeException('GOOGLE_JWKS_URL muss eine HTTPS-URL sein.');
        }
        $testFlag = Environment::value($values, 'POLITIKS_TEST_AUTH', '') === 'enabled';

        date_default_timezone_set($timezone);
        return new self(
            $environment,
            $appUrl,
            $timezone,
            $secret,
            $sessionName,
            [
                'host' => $required('DB_HOST'),
                'port' => $dbPort,
                'name' => $dbName,
                'user' => $required('DB_USER'),
                'password' => Environment::value($values, 'DB_PASSWORD', '') ?? '',
                'charset' => $charset,
            ],
            $clientId,
            $jwksUrl,
            $siteRoot . '/storage',
            $environment === 'test' && $testFlag,
        );
    }

    public function usesSecureCookies(): bool
    {
        return str_starts_with($this->appUrl, 'https://');
    }
}
