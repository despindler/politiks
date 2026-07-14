# Reusable Google Sign-In Integration Guide

This document gives Codex agents a reusable implementation pattern for adding Google Sign-In to a PHP/API-backed web application.

The pattern is intentionally domain-neutral. It assumes the project already has local users, server-side sessions, a JSON API, and a `users` table. The browser uses Google Identity Services only to obtain an ID token credential; the backend verifies that token before creating, linking, or logging in a local user.

## High-Level Architecture

Flow:

1. Frontend calls `GET /api/auth-config`.
2. Backend returns `google_client_id` only when `GOOGLE_CLIENT_ID` is configured.
3. Frontend dynamically loads `https://accounts.google.com/gsi/client`.
4. Frontend renders the Google Sign-In button.
5. Google returns an ID token credential to the browser callback.
6. Frontend posts `{ credential }` to `POST /api/google-login`.
7. Backend verifies the token signature and claims.
8. Backend finds, links, or creates a local user.
9. Backend logs the user in using the same server-side session mechanism as normal login.

The browser never decides who the user is. It only passes the Google ID token to the backend.

## Environment Configuration

Add these config values:

```env
GOOGLE_CLIENT_ID=
GOOGLE_JWKS_URL=https://www.googleapis.com/oauth2/v3/certs
```

`GOOGLE_CLIENT_ID` is the OAuth web client ID from Google Cloud.

`GOOGLE_JWKS_URL` is where the backend fetches Google's public keys for token signature verification. Keep it configurable for tests and future flexibility.

If `GOOGLE_CLIENT_ID` is empty, Google login is disabled and the frontend must not render the button.

## Database Schema

Extend the local `users` table with Google identity fields:

```sql
ALTER TABLE users
    ADD COLUMN google_sub VARCHAR(64) NULL,
    ADD COLUMN email VARCHAR(255) NULL,
    ADD COLUMN display_name VARCHAR(255) NULL,
    ADD UNIQUE KEY users_google_sub_unique (google_sub),
    ADD UNIQUE KEY users_email_unique (email);
```

Important decisions:

- `google_sub` is the stable Google account identifier and should be the primary Google link key.
- `email` is unique only if the application's account model supports one account per email.
- `email` can be used to link a Google identity to an existing local account, but only after the token's `email_verified` claim is true.
- `display_name` stores the Google profile name for display purposes.
- Local accounts and Google accounts can share the same `users` table.
- Google users should still get normal local fields such as `username`, `role`, and timestamps.
- Server-side sessions should work the same way for local and Google login.

If the existing project allows duplicate emails, do not add `users_email_unique`; instead, design an explicit account-linking flow.

## Backend Routes

Add two auth-related endpoints.

### `GET /api/auth-config`

Returns public auth configuration:

```json
{
  "ok": true,
  "google_client_id": "client-id.apps.googleusercontent.com"
}
```

If Google login is disabled:

```json
{
  "ok": true,
  "google_client_id": null
}
```

This lets the frontend decide whether to load Google Identity Services.

### `POST /api/google-login`

Request:

```json
{
  "credential": "google-id-token"
}
```

Success response:

```json
{
  "ok": true,
  "user": {
    "id": 123,
    "username": "example_user",
    "role": "user"
  }
}
```

Failure examples:

```json
{
  "ok": false,
  "error_code": "GOOGLE_CREDENTIAL_REQUIRED",
  "message": "Google credential is required.",
  "details": {}
}
```

```json
{
  "ok": false,
  "error_code": "INVALID_GOOGLE_TOKEN",
  "message": "Google credential is invalid.",
  "details": {}
}
```

Keep error response shape consistent with the rest of the API.

## Backend Verification

Use a dedicated verifier interface:

```php
interface GoogleTokenVerifier
{
    /**
     * @return array{sub: string, email: string, email_verified: bool, name: string}
     */
    public function verify(string $credential): array;
}
```

The production implementation can be named `GoogleIdTokenVerifier`.

It should verify:

- `GOOGLE_CLIENT_ID` is configured.
- The credential is a JWT with exactly three parts.
- Header uses `alg = RS256`.
- Header contains a `kid`.
- Payload `aud` equals the configured `GOOGLE_CLIENT_ID`.
- Payload `iss` is either `accounts.google.com` or `https://accounts.google.com`.
- Payload `exp` has not expired.
- Payload `email_verified` is true.
- JWT signature is valid using Google's JWKS public keys.

The verified identity returned to the auth controller should contain:

```php
[
    'sub' => (string) ($payload['sub'] ?? ''),
    'email' => (string) ($payload['email'] ?? ''),
    'email_verified' => true,
    'name' => (string) ($payload['name'] ?? ''),
]
```

Reject empty `sub` and empty/unverified email unless the application has a deliberately designed no-email account model.

## Signature Verification

Verify the JWT signature server-side.

Process:

1. Decode JWT header and payload using base64url decoding.
2. Read `kid` from the JWT header.
3. Fetch JWKS from `GOOGLE_JWKS_URL`.
4. Find the matching key by `kid`.
5. Convert the JWK or certificate to a PEM public key.
6. Run `openssl_verify()` against `base64url(header) + "." + base64url(payload)` using the decoded JWT signature and `OPENSSL_ALGO_SHA256`.

If verification fails, return a stable auth error such as:

```text
INVALID_GOOGLE_SIGNATURE
GOOGLE_KEYS_UNAVAILABLE
GOOGLE_OPENSSL_UNAVAILABLE
```

Implementation notes:

- Prefer PHP cURL for JWKS fetching when available.
- Fall back to stream wrappers only when `allow_url_fopen` is enabled.
- Suppress low-level network warnings and convert them into stable API errors.
- Support both RSA JWK modulus/exponent keys and certificate-style `x5c` keys if possible.
- Cache JWKS briefly if the project starts making many auth requests, but keep tests deterministic.

## User Linking And Creation

After the token is verified, use this order:

1. Find a user by `google_sub`.
2. If none exists, find a user by verified email when the app treats email as unique.
3. If a local user with that email exists, link the Google identity to it.
4. If no user exists, create a new local user.
5. Log the user in through the normal session mechanism.

Pseudo-code:

```php
$identity = $googleTokenVerifier->verify($credential);

$user = $users->findByGoogleSub($identity['sub']);

if ($user === null && $identity['email'] !== '') {
    $user = $users->findByEmail($identity['email']);

    if ($user !== null) {
        $users->linkGoogleIdentity(
            (int) $user['id'],
            $identity['sub'],
            $identity['email'],
            $identity['name']
        );

        $user = $users->findById((int) $user['id']);
    }
}

if ($user === null) {
    $user = $users->createGoogleUser(
        $identity['sub'],
        $identity['email'],
        $identity['name']
    );
}

$currentUser->login((int) $user['id']);
```

Make linking atomic enough that two simultaneous Google login requests cannot create duplicate accounts. Database uniqueness constraints on `google_sub` and `email` are the final guard.

## Local Username Generation

For newly created Google users, derive a local username from the email prefix when the application needs a username.

Example:

```text
example.user@example.com -> example_user
```

Rules:

- Lowercase.
- Replace non-`[a-z0-9_]` characters with `_`.
- Trim leading/trailing underscores.
- Fallback to `google_user`.
- Limit base length to the local schema limit.
- If taken, append `_1`, `_2`, and so on.

Pseudo-code:

```php
$base = strtolower((string) preg_replace('/[^a-z0-9_]+/', '_', explode('@', $email)[0] ?? 'google_user'));
$base = trim($base, '_') ?: 'google_user';
$base = substr($base, 0, 24);

$candidate = $base;
$suffix = 1;

while ($users->findByUsername($candidate) !== null) {
    $candidate = substr($base, 0, 24) . '_' . $suffix;
    $suffix++;
}
```

If the existing schema requires `password_hash`, store a placeholder hash for Google-created users:

```php
'google:' . hash('sha256', $googleSub)
```

This placeholder must not be accepted for password login. It only satisfies a non-null schema field. Prefer allowing `password_hash` to be nullable for externally authenticated accounts if the project can safely migrate to that model.

## Frontend Integration

HTML includes a hidden placeholder:

```html
<div id="google-login" class="google-login is-hidden"></div>
```

The frontend loads Google Sign-In only if the backend exposes a client ID:

```js
async function loadGoogleSignIn() {
  const payload = await api('/api/auth-config', {
    headers: { Accept: 'application/json' },
  });

  if (!payload.google_client_id) {
    return;
  }

  await loadScript('https://accounts.google.com/gsi/client');

  window.google.accounts.id.initialize({
    client_id: payload.google_client_id,
    callback: async (response) => {
      try {
        const login = await api('/api/google-login', {
          method: 'POST',
          body: JSON.stringify({ credential: response.credential }),
        });

        renderUser(login.user);
        setMessage('Logged in with Google.', false);
      } catch (error) {
        setMessage(error.message, true);
      }
    },
  });

  googleLogin.classList.remove('is-hidden');

  window.google.accounts.id.renderButton(googleLogin, {
    theme: 'outline',
    size: 'large',
    width: 260,
  });
}
```

The shared API helper should send cookies so Google login establishes the same app session as normal login:

```js
async function api(path, options = {}) {
  const response = await fetch(path, {
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
    },
    ...options,
  });

  const payload = await response.json();

  if (!response.ok || payload.ok === false) {
    const error = new Error(payload.message || 'Request failed.');
    error.code = payload.error_code || '';
    throw error;
  }

  return payload;
}
```

If the project uses a strict Content Security Policy, update it to permit the Google Identity Services script and any required Google frames according to the project's security policy.

## Error Handling

Keep Google auth failures explicit and stable.

Recommended error codes:

```text
GOOGLE_LOGIN_NOT_CONFIGURED
GOOGLE_CREDENTIAL_REQUIRED
INVALID_GOOGLE_TOKEN
GOOGLE_AUDIENCE_MISMATCH
INVALID_GOOGLE_ISSUER
GOOGLE_TOKEN_EXPIRED
GOOGLE_EMAIL_NOT_VERIFIED
GOOGLE_KEYS_UNAVAILABLE
GOOGLE_OPENSSL_UNAVAILABLE
INVALID_GOOGLE_SIGNATURE
AUTH_STORAGE_FAILED
DB_CONFIG_MISSING
```

The verifier can throw a `GoogleAuthFailedException` carrying an `errorCode`.

The controller should convert verifier failures to a JSON `401` response unless the failure is a storage/configuration problem that deserves `500` or `503`.

## Dependency Injection For Testing

Inject `GoogleTokenVerifier` into the auth controller or application.

Production uses:

```php
new GoogleIdTokenVerifier($config)
```

Tests use a fake verifier:

```php
final class FakeGoogleTokenVerifier implements GoogleTokenVerifier
{
    public function __construct(
        private readonly ?array $identity,
        private readonly ?GoogleAuthFailedException $exception = null
    ) {
    }

    public function verify(string $credential): array
    {
        if ($this->exception !== null) {
            throw $this->exception;
        }

        return $this->identity ?? [
            'sub' => 'google-sub-1',
            'email' => 'user@example.com',
            'email_verified' => true,
            'name' => 'Example User',
        ];
    }
}
```

This lets tests cover login behavior without depending on Google or the network.

## Tests To Port

Add tests for:

1. Google login creates a local user and establishes a session.
2. Repeated Google login reuses the linked user.
3. Existing local user with the same verified email gets linked instead of duplicated.
4. Invalid Google token returns `401` with a stable error code.
5. Missing credential returns `422` or another consistent validation status.
6. Disabled Google login returns a stable not-configured error.
7. Verifier reports key-fetch failure as `GOOGLE_KEYS_UNAVAILABLE`.
8. Verifier reports missing OpenSSL as `GOOGLE_OPENSSL_UNAVAILABLE`.
9. Verifier can convert RSA JWK keys to PEM.
10. Audience, issuer, expiration, email verification, and signature failures are each rejected.

Use fake verifier tests for auth-controller behavior and smaller verifier tests for JWT parsing and key conversion.

## Security Notes

Do:

- Verify ID tokens on the backend.
- Check `aud` against the configured client ID.
- Check `iss`.
- Check `exp`.
- Require verified email when using email for account linking.
- Verify the RS256 signature using Google's public keys.
- Use `google_sub` as the stable identity key.
- Keep normal app sessions server-side.
- Return only public user fields to the browser.
- Keep local password login behavior separate from Google login behavior.
- Preserve admin access through a deliberate local or linked account flow.

Do not:

- Trust decoded browser JWT payloads without backend verification.
- Use email alone as the permanent Google identity.
- Store Google credentials as passwords.
- Expose secrets in frontend config.
- Render the Google button when no client ID is configured.
- Grant elevated roles automatically to newly created Google users.
- Link accounts by unverified email.

## Minimal Porting Checklist

1. Add `GOOGLE_CLIENT_ID` and `GOOGLE_JWKS_URL` config.
2. Add `google_sub`, `email`, and `display_name` columns to `users`.
3. Add `GET /api/auth-config`.
4. Add `POST /api/google-login`.
5. Implement `GoogleTokenVerifier`.
6. Implement server-side ID token verification.
7. Add user lookup by `google_sub`.
8. Add user lookup by email only if the project treats verified emails as unique.
9. Add user creation/linking logic.
10. Reuse the existing session login mechanism.
11. Add the hidden frontend Google button container.
12. Load `https://accounts.google.com/gsi/client` dynamically.
13. Render the GIS button only when `google_client_id` is present.
14. Post `response.credential` to the backend.
15. Add fake-verifier tests for auth flow.
16. Add verifier tests for token validation failures.
17. Document required PHP extensions such as OpenSSL and the preferred HTTP client mechanism.

## Codex Implementation Notes

When instructing a Codex agent to add Google login to a project:

- Ask for a narrow milestone: configuration, schema, backend verification, frontend button, tests, and docs.
- Require backend token verification; do not accept a frontend-only login.
- Require stable JSON errors.
- Require deterministic tests with a fake verifier.
- Require the project README to document Google Cloud setup, required environment variables, and disabled-by-default behavior.
- Ask the agent to preserve existing local login and admin access unless the project explicitly changes that policy.
