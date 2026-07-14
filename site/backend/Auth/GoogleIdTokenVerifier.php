<?php

declare(strict_types=1);

namespace Politiks\App\Auth;

use JsonException;

final class GoogleIdTokenVerifier implements GoogleTokenVerifier
{
    /** @param callable():int|null $clock */
    public function __construct(
        private readonly ?string $clientId,
        private readonly JwksProvider $jwks,
        private readonly JwtSignatureVerifier $signatureVerifier,
        private readonly mixed $clock = null,
    ) {
    }

    public function verify(string $credential): array
    {
        if ($this->clientId === null || $this->clientId === '') {
            throw new GoogleAuthException(
                'GOOGLE_LOGIN_NOT_CONFIGURED',
                'Die Google-Anmeldung ist nicht konfiguriert.',
                503,
            );
        }
        if ($credential === '' || strlen($credential) > 16_384) {
            throw $this->invalidToken();
        }
        $parts = explode('.', $credential);
        if (count($parts) !== 3 || in_array('', $parts, true)) {
            throw $this->invalidToken();
        }
        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;
        $header = $this->decodeJsonPart($encodedHeader);
        $payload = $this->decodeJsonPart($encodedPayload);
        $signature = $this->base64UrlDecode($encodedSignature);

        if (($header['alg'] ?? null) !== 'RS256') {
            throw $this->invalidToken();
        }
        $kid = $header['kid'] ?? null;
        if (!is_string($kid) || $kid === '' || strlen($kid) > 256) {
            throw $this->invalidToken();
        }
        $matchingKey = null;
        foreach ($this->jwks->keys() as $key) {
            if (($key['kid'] ?? null) === $kid) {
                $matchingKey = $key;
                break;
            }
        }
        if ($matchingKey === null) {
            throw new GoogleAuthException(
                'INVALID_GOOGLE_SIGNATURE',
                'Das Google-Anmeldetoken hat keine gültige Signatur.',
            );
        }
        $pem = self::jwkToPem($matchingKey);
        if (!$this->signatureVerifier->verify($encodedHeader . '.' . $encodedPayload, $signature, $pem)) {
            throw new GoogleAuthException(
                'INVALID_GOOGLE_SIGNATURE',
                'Das Google-Anmeldetoken hat keine gültige Signatur.',
            );
        }

        $audience = $payload['aud'] ?? null;
        $audienceMatches = is_string($audience) && hash_equals($this->clientId, $audience);
        if (is_array($audience)) {
            $audienceMatches = in_array($this->clientId, $audience, true)
                && ($payload['azp'] ?? null) === $this->clientId;
        }
        if (!$audienceMatches) {
            throw new GoogleAuthException('GOOGLE_AUDIENCE_MISMATCH', 'Das Google-Anmeldetoken ist nicht für Politiks bestimmt.');
        }
        if (!in_array($payload['iss'] ?? null, ['accounts.google.com', 'https://accounts.google.com'], true)) {
            throw new GoogleAuthException('INVALID_GOOGLE_ISSUER', 'Der Aussteller des Google-Anmeldetokens ist ungültig.');
        }
        $now = is_callable($this->clock) ? (int) ($this->clock)() : time();
        $expiresAt = $payload['exp'] ?? null;
        if (!is_int($expiresAt) || $expiresAt <= $now) {
            throw new GoogleAuthException('GOOGLE_TOKEN_EXPIRED', 'Das Google-Anmeldetoken ist abgelaufen.');
        }
        if (($payload['email_verified'] ?? null) !== true) {
            throw new GoogleAuthException('GOOGLE_EMAIL_NOT_VERIFIED', 'Die Google-E-Mail-Adresse ist nicht bestätigt.');
        }
        $sub = $this->boundedString($payload['sub'] ?? null, 191);
        $email = $this->boundedString($payload['email'] ?? null, 320);
        if ($sub === '' || $email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw $this->invalidToken();
        }
        $name = $this->boundedString($payload['name'] ?? '', 255);
        if ($name === '') {
            $name = explode('@', $email, 2)[0];
        }
        $picture = $this->boundedString($payload['picture'] ?? '', 2048);
        $pictureHost = $picture === '' ? null : parse_url($picture, PHP_URL_HOST);
        if ($picture === ''
            || filter_var($picture, FILTER_VALIDATE_URL) === false
            || !str_starts_with($picture, 'https://')
            || $pictureHost !== 'lh3.googleusercontent.com') {
            $picture = null;
        }
        return [
            'sub' => $sub,
            'email' => strtolower($email),
            'email_verified' => true,
            'name' => $name,
            'picture' => $picture,
        ];
    }

    /** @param array<string, mixed> $jwk */
    public static function jwkToPem(array $jwk): string
    {
        if (isset($jwk['x5c'][0]) && is_string($jwk['x5c'][0])) {
            $certificate = preg_replace('/\s+/', '', $jwk['x5c'][0]);
            if (!is_string($certificate) || $certificate === '' || base64_decode($certificate, true) === false) {
                throw new GoogleAuthException('INVALID_GOOGLE_SIGNATURE', 'Der Google-Signaturschlüssel ist ungültig.');
            }
            return "-----BEGIN CERTIFICATE-----\n"
                . chunk_split($certificate, 64, "\n")
                . "-----END CERTIFICATE-----\n";
        }
        if (($jwk['kty'] ?? null) !== 'RSA' || !is_string($jwk['n'] ?? null) || !is_string($jwk['e'] ?? null)) {
            throw new GoogleAuthException('INVALID_GOOGLE_SIGNATURE', 'Der Google-Signaturschlüssel ist ungültig.');
        }
        $modulus = self::decodeStatic($jwk['n']);
        $exponent = self::decodeStatic($jwk['e']);
        $rsa = self::asn1Sequence(self::asn1Integer($modulus) . self::asn1Integer($exponent));
        $algorithm = self::asn1Sequence("\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00");
        $publicKey = self::asn1Sequence($algorithm . "\x03" . self::asn1Length(strlen($rsa) + 1) . "\x00" . $rsa);
        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($publicKey), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    /** @return array<string, mixed> */
    private function decodeJsonPart(string $encoded): array
    {
        try {
            $decoded = json_decode($this->base64UrlDecode($encoded), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw $this->invalidToken();
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw $this->invalidToken();
        }
        return $decoded;
    }

    private function base64UrlDecode(string $value): string
    {
        try {
            return self::decodeStatic($value);
        } catch (GoogleAuthException) {
            throw $this->invalidToken();
        }
    }

    private static function decodeStatic(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
            throw new GoogleAuthException('INVALID_GOOGLE_TOKEN', 'Das Google-Anmeldetoken ist ungültig.');
        }
        $padding = (4 - strlen($value) % 4) % 4;
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', $padding), true);
        if ($decoded === false) {
            throw new GoogleAuthException('INVALID_GOOGLE_TOKEN', 'Das Google-Anmeldetoken ist ungültig.');
        }
        return $decoded;
    }

    private function boundedString(mixed $value, int $maxLength): string
    {
        if (!is_string($value) || strlen($value) > $maxLength) {
            return '';
        }
        return trim($value);
    }

    private function invalidToken(): GoogleAuthException
    {
        return new GoogleAuthException('INVALID_GOOGLE_TOKEN', 'Das Google-Anmeldetoken ist ungültig.');
    }

    private static function asn1Sequence(string $body): string
    {
        return "\x30" . self::asn1Length(strlen($body)) . $body;
    }

    private static function asn1Integer(string $value): string
    {
        $value = ltrim($value, "\x00");
        if ($value === '') {
            $value = "\x00";
        }
        if ((ord($value[0]) & 0x80) !== 0) {
            $value = "\x00" . $value;
        }
        return "\x02" . self::asn1Length(strlen($value)) . $value;
    }

    private static function asn1Length(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }
        $encoded = '';
        while ($length > 0) {
            $encoded = chr($length & 0xff) . $encoded;
            $length >>= 8;
        }
        return chr(0x80 | strlen($encoded)) . $encoded;
    }
}
