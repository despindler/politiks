<?php

declare(strict_types=1);

namespace Politiks\App\Security;

final class Csrf
{
    private const SESSION_KEY = 'csrf_token';

    public function __construct(private readonly SessionStore $session)
    {
    }

    public function token(): string
    {
        $token = $this->session->get(self::SESSION_KEY);
        if (!is_string($token) || strlen($token) !== 64) {
            $token = bin2hex(random_bytes(32));
            $this->session->set(self::SESSION_KEY, $token);
        }
        return $token;
    }

    public function valid(?string $provided): bool
    {
        return is_string($provided)
            && strlen($provided) === 64
            && hash_equals($this->token(), $provided);
    }
}
