<?php

declare(strict_types=1);

namespace Politiks\App\Insight;

use RuntimeException;

final class InsightException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }
}
