<?php

declare(strict_types=1);

namespace Politiks\App;

use DateTimeZone;
use RuntimeException;

final class Config
{
    /**
     * @param array<string, string> $database
     * @param array{enabled:bool,api_key:?string,responses_url:string,model:string,timeout_seconds:int,max_output_tokens:int,candidate_limit:int,chunk_size:int,cache_ttl_seconds:int,hourly_limit:int} $aiFilter
     */
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
        public readonly int $uploadMaxBytes,
        public readonly bool $testAuthEnabled,
        public readonly array $aiFilter,
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
        $appUrlParts = parse_url($appUrl);
        if (filter_var($appUrl, FILTER_VALIDATE_URL) === false || !is_array($appUrlParts)
            || !in_array(strtolower((string) ($appUrlParts['scheme'] ?? '')), ['http', 'https'], true)
            || isset($appUrlParts['user']) || isset($appUrlParts['pass'])
            || isset($appUrlParts['query']) || isset($appUrlParts['fragment'])) {
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
        $uploadMaxBytes = $required('UPLOAD_MAX_BYTES');
        if (!ctype_digit($uploadMaxBytes) || (int) $uploadMaxBytes < 1024 || (int) $uploadMaxBytes > 20_971_520) {
            throw new RuntimeException('UPLOAD_MAX_BYTES muss zwischen 1024 und 20971520 liegen.');
        }

        $aiEnabledValue = Environment::value($values, 'AI_FILTER_ENABLED', '0') ?? '0';
        if (!in_array($aiEnabledValue, ['0', '1'], true)) {
            throw new RuntimeException('AI_FILTER_ENABLED muss 0 oder 1 sein.');
        }
        $aiEnabled = $aiEnabledValue === '1';
        $openAiApiKey = trim(Environment::value($values, 'OPENAI_API_KEY', '') ?? '');
        if ($openAiApiKey !== '' && (strlen($openAiApiKey) < 20 || strlen($openAiApiKey) > 512)) {
            throw new RuntimeException('OPENAI_API_KEY ist ungÃ¼ltig.');
        }
        if ($aiEnabled && $openAiApiKey === '') {
            throw new RuntimeException('OPENAI_API_KEY fehlt fÃ¼r den aktivierten KI-Filter.');
        }
        $responsesUrl = Environment::value(
            $values,
            'OPENAI_RESPONSES_URL',
            'https://api.openai.com/v1/responses',
        ) ?? '';
        $responsesParts = parse_url($responsesUrl);
        if (filter_var($responsesUrl, FILTER_VALIDATE_URL) === false || !is_array($responsesParts)
            || strtolower((string) ($responsesParts['scheme'] ?? '')) !== 'https'
            || isset($responsesParts['user']) || isset($responsesParts['pass'])
            || isset($responsesParts['query']) || isset($responsesParts['fragment'])) {
            throw new RuntimeException('OPENAI_RESPONSES_URL muss eine HTTPS-URL ohne Zugangsdaten oder Parameter sein.');
        }
        $openAiModel = Environment::value($values, 'OPENAI_MODEL', 'gpt-5.6-luna') ?? '';
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,95}$/', $openAiModel) !== 1) {
            throw new RuntimeException('OPENAI_MODEL ist ungÃ¼ltig.');
        }
        $boundedInteger = static function (
            string $key,
            int $default,
            int $minimum,
            int $maximum,
        ) use ($values): int {
            $value = Environment::value($values, $key, (string) $default) ?? '';
            if (!ctype_digit($value) || (int) $value < $minimum || (int) $value > $maximum) {
                throw new RuntimeException(sprintf(
                    '%s muss zwischen %d und %d liegen.',
                    $key,
                    $minimum,
                    $maximum,
                ));
            }
            return (int) $value;
        };
        $aiTimeoutSeconds = $boundedInteger('AI_FILTER_TIMEOUT_SECONDS', 30, 5, 120);
        $aiMaxOutputTokens = $boundedInteger('AI_FILTER_MAX_OUTPUT_TOKENS', 4096, 256, 16_384);
        $aiCandidateLimit = $boundedInteger('AI_FILTER_CANDIDATE_LIMIT', 300, 25, 500);
        $aiChunkSize = $boundedInteger('AI_FILTER_CHUNK_SIZE', 75, 10, 100);
        if ($aiChunkSize > $aiCandidateLimit) {
            throw new RuntimeException('AI_FILTER_CHUNK_SIZE darf das Kandidatenlimit nicht Ã¼berschreiten.');
        }
        $aiCacheTtlSeconds = $boundedInteger('AI_FILTER_CACHE_TTL_SECONDS', 3600, 60, 86_400);
        $aiHourlyLimit = $boundedInteger('AI_FILTER_HOURLY_LIMIT', 10, 1, 100);
        if ($environment === 'production') {
            if (strtolower((string) $appUrlParts['scheme']) !== 'https') {
                throw new RuntimeException('APP_URL muss in der Produktion HTTPS verwenden.');
            }
            if ($clientId === null) {
                throw new RuntimeException('GOOGLE_CLIENT_ID fehlt für die Produktion.');
            }
            if ($testFlag) {
                throw new RuntimeException('Test-Authentifizierung ist in der Produktion verboten.');
            }
        }

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
            (int) $uploadMaxBytes,
            $environment === 'test' && $testFlag,
            [
                'enabled' => $aiEnabled,
                'api_key' => $openAiApiKey === '' ? null : $openAiApiKey,
                'responses_url' => $responsesUrl,
                'model' => $openAiModel,
                'timeout_seconds' => $aiTimeoutSeconds,
                'max_output_tokens' => $aiMaxOutputTokens,
                'candidate_limit' => $aiCandidateLimit,
                'chunk_size' => $aiChunkSize,
                'cache_ttl_seconds' => $aiCacheTtlSeconds,
                'hourly_limit' => $aiHourlyLimit,
            ],
        );
    }

    public function usesSecureCookies(): bool
    {
        return str_starts_with($this->appUrl, 'https://');
    }
}
