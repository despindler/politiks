<?php

declare(strict_types=1);

namespace Politiks\App\Ai;

final class TestAiResponsesClient implements AiResponsesClient
{
    public function structuredResponse(
        string $developerPrompt,
        array $userData,
        string $schemaName,
        array $schema,
        string $safetyIdentifier,
    ): array {
        if ($schemaName === 'vote_filter_query_plan_v1') {
            $criterion = strtolower((string) ($userData['criterion'] ?? ''));
            $terms = match (true) {
                str_contains($criterion, 'steuer') => ['Steuerentlastung'],
                str_contains($criterion, 'grundversorgung') => ['Grundversorgung'],
                str_contains($criterion, 'plattform') => ['Plattformen'],
                str_contains($criterion, 'energie') => ['Energien'],
                str_contains($criterion, 'raumfahrt') => ['Raumfahrt'],
                default => ['TEST'],
            };
            return $this->response([
                'search_terms' => $terms,
                'exclude_terms' => [],
                'date_from' => null,
                'date_to' => null,
                'vote_types' => [],
            ]);
        }

        $criterion = strtolower((string) ($userData['criterion'] ?? ''));
        $matches = [];
        $ambiguous = [];
        foreach (($userData['candidates'] ?? []) as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $title = strtolower((string) ($candidate['title'] ?? ''));
            $item = ['id' => (int) $candidate['id'], 'reason' => 'Der Titel erfüllt das Testkriterium.'];
            if (str_contains($criterion, 'grundversorgung')) {
                $ambiguous[] = $item;
            } elseif (str_contains($criterion, 'steuer') && str_contains($title, 'steuer')) {
                $matches[] = $item;
            } elseif (!str_contains($criterion, 'steuer')) {
                $matches[] = $item;
            }
        }
        return $this->response(['matches' => $matches, 'ambiguous' => $ambiguous]);
    }

    /** @param array<string,mixed> $data @return array{data:array<string,mixed>,model:string,usage:array{input_tokens:int,output_tokens:int,cached_input_tokens:int}} */
    private function response(array $data): array
    {
        return [
            'data' => $data,
            'model' => 'playwright-deterministic-model',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'cached_input_tokens' => 0],
        ];
    }
}
