<?php

declare(strict_types=1);

namespace Politiks\App\Ai;

use RuntimeException;
use Throwable;

final class AiFilterException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 502,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
