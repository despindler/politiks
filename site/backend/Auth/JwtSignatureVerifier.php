<?php

declare(strict_types=1);

namespace Politiks\App\Auth;

interface JwtSignatureVerifier
{
    public function verify(string $signedData, string $signature, string $publicKeyPem): bool;
}
