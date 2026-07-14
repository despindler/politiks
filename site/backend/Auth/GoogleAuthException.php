<?php

declare(strict_types=1);

namespace Politiks\App\Auth;

use RuntimeException;

final class GoogleAuthException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 401,
    ) {
        parent::__construct($message);
    }
}
