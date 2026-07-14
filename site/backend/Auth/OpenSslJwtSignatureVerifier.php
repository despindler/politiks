<?php

declare(strict_types=1);

namespace Politiks\App\Auth;

final class OpenSslJwtSignatureVerifier implements JwtSignatureVerifier
{
    public function verify(string $signedData, string $signature, string $publicKeyPem): bool
    {
        if (!function_exists('openssl_verify') || !defined('OPENSSL_ALGO_SHA256')) {
            throw new GoogleAuthException(
                'GOOGLE_OPENSSL_UNAVAILABLE',
                'Die sichere Google-Anmeldung ist auf diesem Server nicht verfügbar.',
                503,
            );
        }
        return @openssl_verify($signedData, $signature, $publicKeyPem, OPENSSL_ALGO_SHA256) === 1;
    }
}
