<?php

declare(strict_types=1);

namespace Politiks\App\Ai;

interface AiResponsesClient
{
    /**
     * @param array<string, mixed> $userData
     * @param array<string, mixed> $schema
     * @return array{
     *   data:array<string,mixed>,
     *   model:string,
     *   usage:array{input_tokens:?int,output_tokens:?int,cached_input_tokens:?int}
     * }
     */
    public function structuredResponse(
        string $developerPrompt,
        array $userData,
        string $schemaName,
        array $schema,
        string $safetyIdentifier,
    ): array;
}
