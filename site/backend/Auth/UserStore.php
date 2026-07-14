<?php

declare(strict_types=1);

namespace Politiks\App\Auth;

interface UserStore
{
    /**
     * @param array{sub:string,email:string,email_verified:true,name:string,picture:?string} $identity
     * @return array{id:int,email:string,display_name:string,avatar_url:?string,role:string}
     */
    public function loginOrCreate(array $identity): array;

    /** @return array{id:int,email:string,display_name:string,avatar_url:?string,role:string}|null */
    public function findActiveById(int $id): ?array;
}
