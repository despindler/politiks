<?php

declare(strict_types=1);

namespace Politiks\App\Ai;

final class AiSelectionContract
{
    /**
     * @param list<int> $candidateIds
     * @return array<string, mixed>
     */
    public static function schema(array $candidateIds): array
    {
        if ($candidateIds === [] || !array_is_list($candidateIds)) {
            throw new AiFilterException('AI_SCHEMA_INVALID', 'Die Kandidaten-IDs für das KI-Antwortformat fehlen.', 500);
        }
        $allowedIds = [];
        foreach ($candidateIds as $candidateId) {
            if (!is_int($candidateId) || $candidateId < 1 || isset($allowedIds[$candidateId])) {
                throw new AiFilterException('AI_SCHEMA_INVALID', 'Die Kandidaten-IDs für das KI-Antwortformat sind ungültig.', 500);
            }
            $allowedIds[$candidateId] = true;
        }
        $selection = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'id' => ['type' => 'integer', 'enum' => array_keys($allowedIds)],
                'reason' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 500],
            ],
            'required' => ['id', 'reason'],
        ];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'matches' => ['type' => 'array', 'items' => $selection],
                'ambiguous' => ['type' => 'array', 'items' => $selection],
            ],
            'required' => ['matches', 'ambiguous'],
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @param list<int> $candidateIds
     * @return array{matches:list<array{id:int,reason:string}>,ambiguous:list<array{id:int,reason:string}>}
     */
    public static function normalize(array $data, array $candidateIds, int $maximumSelections): array
    {
        if ($maximumSelections < 1) {
            throw new AiFilterException('AI_RESPONSE_INVALID', 'Die KI-Auswahlgrenze ist ungültig.');
        }
        $allowed = array_fill_keys(array_map('strval', $candidateIds), true);
        $seen = [];
        $result = ['matches' => [], 'ambiguous' => []];

        foreach (['matches', 'ambiguous'] as $group) {
            if (!isset($data[$group]) || !is_array($data[$group]) || !array_is_list($data[$group])) {
                throw new AiFilterException('AI_RESPONSE_INVALID', 'Die KI-Antwort entspricht nicht dem Auswahlformat.');
            }
            foreach ($data[$group] as $item) {
                if (!is_array($item) || !array_key_exists('id', $item) || !array_key_exists('reason', $item)
                    || (!is_int($item['id']) && !(is_string($item['id']) && ctype_digit($item['id'])))
                    || !is_string($item['reason'])) {
                    throw new AiFilterException('AI_RESPONSE_INVALID', 'Die KI-Antwort enthält einen ungültigen Treffer.');
                }
                $id = (int) $item['id'];
                if ($id < 1 || !isset($allowed[(string) $id])) {
                    throw new AiFilterException('AI_RESPONSE_UNKNOWN_ID', 'Die KI-Antwort enthält eine unbekannte Abstimmungs-ID.');
                }
                $reason = trim($item['reason']);
                if ($reason === '' || strlen($reason) > 500) {
                    throw new AiFilterException('AI_RESPONSE_INVALID', 'Die KI-Antwort enthält eine ungültige Begründung.');
                }
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $result[$group][] = ['id' => $id, 'reason' => $reason];
                if (count($seen) > $maximumSelections) {
                    throw new AiFilterException('AI_RESPONSE_TOO_LARGE', 'Die KI-Antwort enthält zu viele Treffer.');
                }
            }
        }

        return $result;
    }
}
