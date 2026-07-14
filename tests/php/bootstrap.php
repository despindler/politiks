<?php

declare(strict_types=1);

final class TestFailure extends RuntimeException
{
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new TestFailure($message);
    }
}

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new TestFailure(sprintf(
            '%s Expected %s, received %s.',
            $message,
            var_export($expected, true),
            var_export($actual, true),
        ));
    }
}
