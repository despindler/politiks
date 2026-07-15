<?php

declare(strict_types=1);

namespace Politiks\Tooling;

use RuntimeException;

final class AiSelectionEvaluation
{
    /**
     * @param array<string,mixed> $fixture
     * @param callable(array<string,mixed>,list<array<string,mixed>>):array{matches:list<int>,ambiguous:list<int>} $selector
     * @return array<string,mixed>
     */
    public static function run(array $fixture, callable $selector, ?string $onlyCase = null): array
    {
        self::validateFixture($fixture);
        /** @var list<array<string,mixed>> $records */
        $records = $fixture['records'];
        $results = [];
        $passed = 0;

        foreach ($fixture['cases'] as $case) {
            if ($onlyCase !== null && $case['id'] !== $onlyCase) {
                continue;
            }
            $actual = $selector($case, $records);
            $matchIds = self::ids($actual['matches'] ?? null, 'matches');
            $ambiguousIds = self::ids($actual['ambiguous'] ?? null, 'ambiguous');
            if (array_intersect($matchIds, $ambiguousIds) !== []) {
                throw new RuntimeException('Eine ID darf nicht zugleich Treffer und mehrdeutig sein.');
            }

            $required = self::ids($case['required_ids'], 'required_ids');
            $expectedAmbiguous = self::ids($case['ambiguous_ids'], 'ambiguous_ids');
            $forbidden = self::ids($case['forbidden_ids'], 'forbidden_ids');
            $selected = array_values(array_unique([...$matchIds, ...$ambiguousIds]));
            $missingRequired = array_values(array_diff($required, $matchIds));
            $missingAmbiguous = array_values(array_diff($expectedAmbiguous, $ambiguousIds));
            $forbiddenSelected = array_values(array_intersect($forbidden, $selected));
            $expectedSelected = array_values(array_unique([...$required, ...$expectedAmbiguous]));
            $unexpectedSelected = array_values(array_diff($selected, $expectedSelected));
            $casePassed = $missingRequired === [] && $missingAmbiguous === []
                && $forbiddenSelected === [] && $unexpectedSelected === [];
            $passed += $casePassed ? 1 : 0;
            $results[] = [
                'id' => $case['id'],
                'passed' => $casePassed,
                'expected_empty' => $required === [] && $expectedAmbiguous === [],
                'matches' => $matchIds,
                'ambiguous' => $ambiguousIds,
                'missing_required' => $missingRequired,
                'missing_ambiguous' => $missingAmbiguous,
                'forbidden_selected' => $forbiddenSelected,
                'unexpected_selected' => $unexpectedSelected,
            ];
        }

        if ($onlyCase !== null && $results === []) {
            throw new RuntimeException('Unbekannter Evaluationsfall: ' . $onlyCase);
        }

        return [
            'evaluation_id' => $fixture['evaluation_id'],
            'version' => $fixture['version'],
            'language' => $fixture['language'],
            'records' => count($records),
            'cases' => count($results),
            'passed' => $passed,
            'failed' => count($results) - $passed,
            'results' => $results,
        ];
    }

    /** @param array<string,mixed> $fixture */
    private static function validateFixture(array $fixture): void
    {
        if (($fixture['version'] ?? null) !== 1 || ($fixture['language'] ?? null) !== 'de'
            || !is_string($fixture['evaluation_id'] ?? null) || $fixture['evaluation_id'] === ''
            || !is_array($fixture['records'] ?? null) || !array_is_list($fixture['records'])
            || !is_array($fixture['cases'] ?? null) || !array_is_list($fixture['cases'])) {
            throw new RuntimeException('Die KI-Auswahlevaluation ist ungültig oder nicht unterstützt.');
        }
        $recordIds = [];
        foreach ($fixture['records'] as $record) {
            if (!is_array($record) || !is_int($record['id'] ?? null) || isset($recordIds[$record['id']])) {
                throw new RuntimeException('Die Evaluationsdatensätze benötigen eindeutige ganzzahlige IDs.');
            }
            $recordIds[$record['id']] = true;
        }
        $caseIds = [];
        foreach ($fixture['cases'] as $case) {
            if (!is_array($case) || !is_string($case['id'] ?? null) || isset($caseIds[$case['id']])
                || !is_string($case['criterion'] ?? null) || strlen(trim($case['criterion'])) < 3) {
                throw new RuntimeException('Die Evaluationsfälle benötigen eindeutige IDs und Kriterien.');
            }
            $caseIds[$case['id']] = true;
            $groups = [];
            foreach (['required_ids', 'ambiguous_ids', 'forbidden_ids'] as $group) {
                $groups[$group] = self::ids($case[$group] ?? null, $group);
                foreach ($groups[$group] as $id) {
                    if ($group !== 'forbidden_ids' && !isset($recordIds[$id])) {
                        throw new RuntimeException(sprintf('%s verweist auf eine unbekannte Evaluations-ID.', $group));
                    }
                }
            }
            if (array_intersect($groups['required_ids'], $groups['ambiguous_ids']) !== []
                || array_intersect($groups['required_ids'], $groups['forbidden_ids']) !== []
                || array_intersect($groups['ambiguous_ids'], $groups['forbidden_ids']) !== []) {
                throw new RuntimeException('Erwartete, mehrdeutige und verbotene IDs müssen disjunkt sein.');
            }
        }
    }

    /** @return list<int> */
    private static function ids(mixed $values, string $label): array
    {
        if (!is_array($values) || !array_is_list($values)) {
            throw new RuntimeException($label . ' muss eine Liste sein.');
        }
        $ids = [];
        foreach ($values as $value) {
            if (!is_int($value) || $value < 1 || isset($ids[$value])) {
                throw new RuntimeException($label . ' enthält eine ungültige oder doppelte ID.');
            }
            $ids[$value] = true;
        }
        return array_keys($ids);
    }
}
