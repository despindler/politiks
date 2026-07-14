<?php

declare(strict_types=1);

namespace Politiks\App\Auth;

final class TestGoogleTokenVerifier implements GoogleTokenVerifier
{
    public function verify(string $credential): array
    {
        if (!hash_equals('playwright-valid-google-credential', $credential)) {
            throw new GoogleAuthException('INVALID_GOOGLE_TOKEN', 'Das Google-Anmeldetoken ist ungültig.');
        }
        return [
            'sub' => 'playwright-google-subject',
            'email' => 'playwright.user@example.test',
            'email_verified' => true,
            'name' => 'Mara Muster',
            'picture' => null,
        ];
    }
}
