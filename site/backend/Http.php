<?php

declare(strict_types=1);

namespace Politiks\App;

use JsonException;
use RuntimeException;

final class Http
{
    /** @param array<string, mixed> $payload */
    public static function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        exit;
    }

    /** @return array<string, mixed> */
    public static function jsonBody(int $maxBytes = 20_000): array
    {
        $contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
        if ($contentType !== 'application/json') {
            throw new HttpFailure(415, 'CONTENT_TYPE_REQUIRED', 'JSON als Inhaltstyp ist erforderlich.');
        }
        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength > $maxBytes) {
            throw new HttpFailure(413, 'REQUEST_TOO_LARGE', 'Die Anfrage ist zu gross.');
        }
        $raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
        if ($raw === false || strlen($raw) > $maxBytes) {
            throw new HttpFailure(413, 'REQUEST_TOO_LARGE', 'Die Anfrage ist zu gross.');
        }
        try {
            $decoded = json_decode($raw, false, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new HttpFailure(400, 'INVALID_JSON', 'Die Anfrage enthält ungültiges JSON.');
        }
        if (!$decoded instanceof \stdClass) {
            throw new HttpFailure(400, 'INVALID_JSON_OBJECT', 'Ein JSON-Objekt ist erforderlich.');
        }
        return (array) $decoded;
    }

    public static function securityHeaders(Config $config): void
    {
        $policy = [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'none'",
            "form-action 'self'",
            "script-src 'self' https://accounts.google.com/gsi/client",
            "style-src 'self' https://accounts.google.com/gsi/style",
            "font-src 'self'",
            "img-src 'self' data: https://lh3.googleusercontent.com",
            "connect-src 'self' https://accounts.google.com/gsi/",
            "frame-src https://accounts.google.com/gsi/",
        ];
        if ($config->environment === 'production' && $config->usesSecureCookies()) {
            $policy[] = 'upgrade-insecure-requests';
        }
        header('Content-Security-Policy: ' . implode('; ', $policy));
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    }
}

final class HttpFailure extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
