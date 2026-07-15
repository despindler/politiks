<?php

declare(strict_types=1);

namespace Politiks\TestSupport;

use Politiks\App\Ai\AiResponsesClient;
use RuntimeException;

final class DeterministicAiResponsesClient implements AiResponsesClient
{
    /** @var list<array{data:array<string,mixed>,model:string,usage:array{input_tokens:?int,output_tokens:?int,cached_input_tokens:?int}}> */
    private array $responses;

    /** @var list<array<string,mixed>> */
    public array $requests = [];

    /** @param list<array{data:array<string,mixed>,model?:string,usage?:array{input_tokens:?int,output_tokens:?int,cached_input_tokens:?int}}> $responses */
    public function __construct(array $responses)
    {
        $this->responses = array_map(static fn (array $response): array => [
            'data' => $response['data'],
            'model' => $response['model'] ?? 'deterministic-test-model',
            'usage' => $response['usage'] ?? [
                'input_tokens' => 10,
                'output_tokens' => 5,
                'cached_input_tokens' => 0,
            ],
        ], $responses);
    }

    public function structuredResponse(
        string $developerPrompt,
        array $userData,
        string $schemaName,
        array $schema,
        string $safetyIdentifier,
    ): array {
        $this->requests[] = compact(
            'developerPrompt',
            'userData',
            'schemaName',
            'schema',
            'safetyIdentifier',
        );
        if ($this->responses === []) {
            throw new RuntimeException('No deterministic AI response remains.');
        }
        return array_shift($this->responses);
    }
}
