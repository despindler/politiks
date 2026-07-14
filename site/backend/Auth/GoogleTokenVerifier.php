<?php

declare(strict_types=1);

namespace Politiks\App\Auth;

interface GoogleTokenVerifier
{
    /** @return array{sub:string,email:string,email_verified:true,name:string,picture:?string} */
    public function verify(string $credential): array;
}
