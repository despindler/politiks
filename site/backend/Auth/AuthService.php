<?php

declare(strict_types=1);

namespace Politiks\App\Auth;

use Politiks\App\Security\SessionStore;

final class AuthService
{
    private const USER_ID_KEY = 'authenticated_user_id';

    public function __construct(
        private readonly GoogleTokenVerifier $verifier,
        private readonly UserStore $users,
        private readonly SessionStore $session,
    ) {
    }

    /** @return array{id:int,email:string,display_name:string,avatar_url:?string,role:string} */
    public function googleLogin(string $credential): array
    {
        $identity = $this->verifier->verify($credential);
        $user = $this->users->loginOrCreate($identity);
        $this->session->regenerate();
        $this->session->set(self::USER_ID_KEY, $user['id']);
        return $user;
    }

    /** @return array{id:int,email:string,display_name:string,avatar_url:?string,role:string}|null */
    public function currentUser(): ?array
    {
        $id = $this->session->get(self::USER_ID_KEY);
        if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
            return null;
        }
        $user = $this->users->findActiveById((int) $id);
        if ($user === null) {
            $this->session->remove(self::USER_ID_KEY);
        }
        return $user;
    }

    public function logout(): void
    {
        $this->session->destroy();
    }
}
