<?php

declare(strict_types=1);

use Politiks\App\Auth\AuthService;
use Politiks\App\Auth\GoogleAuthException;
use Politiks\App\Auth\GoogleIdTokenVerifier;
use Politiks\App\Auth\GoogleTokenVerifier;
use Politiks\App\Auth\JwksProvider;
use Politiks\App\Auth\JwtSignatureVerifier;
use Politiks\App\Auth\UserStore;
use Politiks\App\Security\Csrf;
use Politiks\App\Security\SessionStore;

require_once __DIR__ . '/../../site/backend/bootstrap.php';

final class StaticJwks implements JwksProvider
{
    /** @param list<array<string, mixed>> $keys */
    public function __construct(private readonly array $keys)
    {
    }

    public function keys(): array
    {
        return $this->keys;
    }
}

final class ControlledSignature implements JwtSignatureVerifier
{
    public function __construct(
        private readonly bool $valid = true,
        private readonly ?GoogleAuthException $failure = null,
    ) {
    }

    public function verify(string $signedData, string $signature, string $publicKeyPem): bool
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
        return $this->valid;
    }
}

final class MemorySession implements SessionStore
{
    /** @var array<string, mixed> */
    public array $values = [];
    public int $regenerations = 0;
    public bool $destroyed = false;
    private string $sessionId = 'before';

    public function start(): void {}
    public function get(string $key): mixed { return $this->values[$key] ?? null; }
    public function set(string $key, mixed $value): void { $this->values[$key] = $value; }
    public function remove(string $key): void { unset($this->values[$key]); }
    public function regenerate(): void { $this->regenerations++; $this->sessionId = 'after'; }
    public function destroy(): void { $this->values = []; $this->destroyed = true; }
    public function id(): string { return $this->sessionId; }
}

final class MemoryUsers implements UserStore
{
    /** @var array<int, array{id:int,email:string,display_name:string,avatar_url:?string,role:string}> */
    private array $users = [];

    public function loginOrCreate(array $identity): array
    {
        foreach ($this->users as $user) {
            if ($user['email'] === $identity['email']) return $user;
        }
        $user = [
            'id' => count($this->users) + 1,
            'email' => $identity['email'],
            'display_name' => $identity['name'],
            'avatar_url' => $identity['picture'],
            'role' => 'user',
        ];
        $this->users[$user['id']] = $user;
        return $user;
    }

    public function findActiveById(int $id): ?array
    {
        return $this->users[$id] ?? null;
    }
}

final class StaticGoogleVerifier implements GoogleTokenVerifier
{
    public function verify(string $credential): array
    {
        if ($credential !== 'valid') {
            throw new GoogleAuthException('INVALID_GOOGLE_TOKEN', 'invalid');
        }
        return [
            'sub' => 'subject-1',
            'email' => 'person@example.test',
            'email_verified' => true,
            'name' => 'Test Person',
            'picture' => null,
        ];
    }
}

/** @param array<string, mixed> $payload */
function googleJwt(array $payload, array $header = ['alg' => 'RS256', 'kid' => 'key-1']): string
{
    $encode = static fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    return $encode(json_encode($header, JSON_THROW_ON_ERROR))
        . '.' . $encode(json_encode($payload, JSON_THROW_ON_ERROR))
        . '.' . $encode('test-signature');
}

/** @param callable():void $operation */
function assertGoogleError(string $expectedCode, callable $operation): void
{
    try {
        $operation();
    } catch (GoogleAuthException $error) {
        assertSameValue($expectedCode, $error->errorCode, 'Google error code must be stable.');
        return;
    }
    throw new TestFailure(sprintf('Expected Google error %s.', $expectedCode));
}

/** @return array<string, mixed> */
function validGooglePayload(): array
{
    return [
        'sub' => 'subject-1',
        'email' => 'Person@Example.test',
        'email_verified' => true,
        'name' => 'Test Person',
        'aud' => 'client.apps.googleusercontent.com',
        'iss' => 'https://accounts.google.com',
        'exp' => 2_000,
    ];
}

/** @param array<string, mixed>|null $payload */
function tokenVerifier(?array $payload = null, bool $signatureValid = true): array
{
    $verifier = new GoogleIdTokenVerifier(
        'client.apps.googleusercontent.com',
        new StaticJwks([['kid' => 'key-1', 'kty' => 'RSA', 'n' => 'AQAB', 'e' => 'AQAB']]),
        new ControlledSignature($signatureValid),
        static fn (): int => 1_000,
    );
    $payload ??= validGooglePayload();
    return [$verifier, googleJwt($payload)];
}

return [
    'Google verifier accepts a signed token with required claims' => static function (): void {
        [$verifier, $token] = tokenVerifier();
        $identity = $verifier->verify($token);
        assertSameValue('subject-1', $identity['sub'], 'Stable Google subject should be returned.');
        assertSameValue('person@example.test', $identity['email'], 'Verified email should be normalized.');
    },
    'Google verifier rejects malformed tokens' => static function (): void {
        [$verifier] = tokenVerifier();
        assertGoogleError('INVALID_GOOGLE_TOKEN', static fn () => $verifier->verify('not-a-jwt'));
    },
    'Google verifier rejects an invalid signature before claims are accepted' => static function (): void {
        [$verifier, $token] = tokenVerifier(signatureValid: false);
        assertGoogleError('INVALID_GOOGLE_SIGNATURE', static fn () => $verifier->verify($token));
    },
    'Google verifier rejects audience mismatch' => static function (): void {
        $payload = validGooglePayload();
        $payload['aud'] = 'another-client';
        [$verifier, $token] = tokenVerifier($payload);
        assertGoogleError('GOOGLE_AUDIENCE_MISMATCH', static fn () => $verifier->verify($token));
    },
    'Google verifier rejects issuer mismatch' => static function (): void {
        $payload = validGooglePayload();
        $payload['iss'] = 'https://example.test';
        [$verifier, $token] = tokenVerifier($payload);
        assertGoogleError('INVALID_GOOGLE_ISSUER', static fn () => $verifier->verify($token));
    },
    'Google verifier rejects expired tokens' => static function (): void {
        $payload = validGooglePayload();
        $payload['exp'] = 999;
        [$verifier, $token] = tokenVerifier($payload);
        assertGoogleError('GOOGLE_TOKEN_EXPIRED', static fn () => $verifier->verify($token));
    },
    'Google verifier rejects unverified email' => static function (): void {
        $payload = validGooglePayload();
        $payload['email_verified'] = false;
        [$verifier, $token] = tokenVerifier($payload);
        assertGoogleError('GOOGLE_EMAIL_NOT_VERIFIED', static fn () => $verifier->verify($token));
    },
    'Google verifier reports unavailable crypto with a stable code' => static function (): void {
        $failure = new GoogleAuthException('GOOGLE_OPENSSL_UNAVAILABLE', 'unavailable', 503);
        $verifier = new GoogleIdTokenVerifier(
            'client.apps.googleusercontent.com',
            new StaticJwks([['kid' => 'key-1', 'kty' => 'RSA', 'n' => 'AQAB', 'e' => 'AQAB']]),
            new ControlledSignature(true, $failure),
            static fn (): int => 1_000,
        );
        assertGoogleError(
            'GOOGLE_OPENSSL_UNAVAILABLE',
            static fn () => $verifier->verify(googleJwt(validGooglePayload())),
        );
    },
    'Google verifier reports unavailable signing keys with a stable code' => static function (): void {
        $keys = new class implements JwksProvider {
            public function keys(): array
            {
                throw new GoogleAuthException('GOOGLE_KEYS_UNAVAILABLE', 'unavailable', 503);
            }
        };
        $verifier = new GoogleIdTokenVerifier(
            'client.apps.googleusercontent.com',
            $keys,
            new ControlledSignature(),
            static fn (): int => 1_000,
        );
        assertGoogleError(
            'GOOGLE_KEYS_UNAVAILABLE',
            static fn () => $verifier->verify(googleJwt(validGooglePayload())),
        );
    },
    'Google verifier reports disabled configuration with a stable code' => static function (): void {
        $verifier = new GoogleIdTokenVerifier(
            null,
            new StaticJwks([]),
            new ControlledSignature(),
        );
        assertGoogleError('GOOGLE_LOGIN_NOT_CONFIGURED', static fn () => $verifier->verify('credential'));
    },
    'RSA JWK conversion produces a PEM public key' => static function (): void {
        $pem = GoogleIdTokenVerifier::jwkToPem(['kty' => 'RSA', 'n' => 'AQAB', 'e' => 'AQAB']);
        assertTrue(str_starts_with($pem, "-----BEGIN PUBLIC KEY-----\n"), 'PEM header must be present.');
        assertTrue(str_ends_with($pem, "-----END PUBLIC KEY-----\n"), 'PEM footer must be present.');
    },
    'authentication regenerates session and reuses the current user' => static function (): void {
        $session = new MemorySession();
        $service = new AuthService(new StaticGoogleVerifier(), new MemoryUsers(), $session);
        $before = $session->id();
        $user = $service->googleLogin('valid');
        assertSameValue(1, $session->regenerations, 'Login must regenerate the session ID.');
        assertTrue($before !== $session->id(), 'Session ID must change after login.');
        assertSameValue($user, $service->currentUser(), 'Current user should come from server-side session state.');
    },
    'CSRF tokens are stable per session and reject mismatches' => static function (): void {
        $csrf = new Csrf(new MemorySession());
        $token = $csrf->token();
        assertSameValue(64, strlen($token), 'CSRF token should contain 32 random bytes as hex.');
        assertTrue($csrf->valid($token), 'Current token should validate.');
        assertTrue(!$csrf->valid(str_repeat('0', 64)), 'A different token must be rejected.');
    },
];
