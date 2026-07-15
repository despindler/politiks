<?php

declare(strict_types=1);

namespace Politiks\App\Ai;

use DateTimeImmutable;

final class AiQueryPlanContract
{
    private const VOTE_TYPES = [
        'final_vote',
        'overall_vote',
        'substantive',
        'amendment',
        'procedural',
        'other',
        'unknown',
    ];

    /** @return array<string,mixed> */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'search_terms' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 80],
                    'minItems' => 1,
                    'maxItems' => 8,
                ],
                'exclude_terms' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 80],
                    'maxItems' => 8,
                ],
                'date_from' => ['type' => ['string', 'null']],
                'date_to' => ['type' => ['string', 'null']],
                'vote_types' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'enum' => self::VOTE_TYPES],
                    'maxItems' => 7,
                ],
            ],
            'required' => ['search_terms', 'exclude_terms', 'date_from', 'date_to', 'vote_types'],
        ];
    }

    /** @param array<string,mixed> $data @return array{search_terms:list<string>,exclude_terms:list<string>,date_from:?string,date_to:?string,vote_types:list<string>} */
    public static function normalize(array $data): array
    {
        $searchTerms = self::terms($data['search_terms'] ?? null, 1, 'Suchbegriffe');
        $excludeTerms = self::terms($data['exclude_terms'] ?? null, 0, 'Ausschlussbegriffe');
        $dateFrom = self::date($data['date_from'] ?? null);
        $dateTo = self::date($data['date_to'] ?? null);
        if ($dateFrom !== null && $dateTo !== null && $dateFrom > $dateTo) {
            throw new AiFilterException('AI_RESPONSE_INVALID', 'Der KI-Suchplan enthält einen ungültigen Zeitraum.');
        }
        $voteTypes = $data['vote_types'] ?? null;
        if (!is_array($voteTypes) || !array_is_list($voteTypes) || count($voteTypes) > count(self::VOTE_TYPES)) {
            throw new AiFilterException('AI_RESPONSE_INVALID', 'Der KI-Suchplan enthält ungültige Abstimmungstypen.');
        }
        $normalizedTypes = [];
        foreach ($voteTypes as $voteType) {
            if (!is_string($voteType) || !in_array($voteType, self::VOTE_TYPES, true)) {
                throw new AiFilterException('AI_RESPONSE_INVALID', 'Der KI-Suchplan enthält einen unbekannten Abstimmungstyp.');
            }
            $normalizedTypes[$voteType] = true;
        }
        return [
            'search_terms' => $searchTerms,
            'exclude_terms' => $excludeTerms,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'vote_types' => array_keys($normalizedTypes),
        ];
    }

    /** @return list<string> */
    private static function terms(mixed $values, int $minimum, string $label): array
    {
        if (!is_array($values) || !array_is_list($values) || count($values) < $minimum || count($values) > 8) {
            throw new AiFilterException('AI_RESPONSE_INVALID', 'Der KI-Suchplan enthält ungültige ' . $label . '.');
        }
        $result = [];
        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new AiFilterException('AI_RESPONSE_INVALID', 'Der KI-Suchplan enthält ungültige ' . $label . '.');
            }
            $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', trim($value));
            $value = is_string($value) ? trim($value) : '';
            if ($value === '' || strlen($value) > 80) {
                throw new AiFilterException('AI_RESPONSE_INVALID', 'Der KI-Suchplan enthält ungültige ' . $label . '.');
            }
            $result[$value] = true;
        }
        if (count($result) < $minimum) {
            throw new AiFilterException('AI_RESPONSE_INVALID', 'Der KI-Suchplan enthält zu wenige ' . $label . '.');
        }
        return array_keys($result);
    }

    private static function date(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            throw new AiFilterException('AI_RESPONSE_INVALID', 'Der KI-Suchplan enthält ein ungültiges Datum.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new AiFilterException('AI_RESPONSE_INVALID', 'Der KI-Suchplan enthält ein ungültiges Datum.');
        }
        return $value;
    }
}
