<?php

declare(strict_types=1);

namespace Politiks\App\Auth;

final class TestGoogleTokenVerifier implements GoogleTokenVerifier
{
    public function verify(string $credential): array
    {
        if (preg_match('/^playwright-valid-google-credential(?:-([a-z0-9-]{1,40}))?$/D', $credential, $matches) !== 1) {
            throw new GoogleAuthException('INVALID_GOOGLE_TOKEN', 'Das Google-Anmeldetoken ist ungültig.');
        }
        $identity = $matches[1] ?? 'default';
        return [
            'sub' => 'playwright-google-subject-' . $identity,
            'email' => sprintf('playwright.%s@example.test', $identity),
            'email_verified' => true,
            'name' => 'Mara Muster',
            'picture' => null,
        ];
    }
}
