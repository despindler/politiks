<?php

declare(strict_types=1);

namespace Politiks\App\Auth;

interface JwksProvider
{
    /** @return list<array<string, mixed>> */
    public function keys(): array;
}
