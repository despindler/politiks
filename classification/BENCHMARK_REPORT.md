# Classification benchmark and full-run report

Assessment date: 2026-07-14

Taxonomy: `1.0.0`

Ruleset: `1.0.0`

Source snapshot: `full_swiss_2026-07-14`

## Benchmark result

`scripts/evaluate_classification_benchmark.py` passes all five labeled synthetic cases:

| Case | Kind | Required behavior | Result |
|---|---|---|---|
| `clear_tax_large_company` | Clear | Tax topic, tax decrease, named group, explicit beneficiary | Pass |
| `clear_social_cost` | Clear | Social-security topic, transfer decrease, low-income cost bearer | Pass |
| `procedural_no_forced_effect` | Procedural | No beneficiary, cost bearer, or mechanism forced | Pass |
| `ambiguous_no_forced_effect` | Ambiguous | No beneficiary, cost bearer, or mechanism forced | Pass |
| `mixed_explicit_effects` | Mixed | Separate low-income benefit and taxpayer cost suggestions | Pass |

The benchmark checks transparent rule behavior; it is deliberately small and is not a claim of political or statistical validity.

## Full deterministic run

Two consecutive runs produced the same run key and logical counts:

- 21,569 voting-event documents evaluated;
- 10,111 pending suggestions;
- 9,301 policy-topic suggestions;
- 803 affected-group suggestions with unclear direction;
- 6 direct mechanism suggestions (five increases, one decrease);
- 1 direct beneficiary suggestion based on the explicit title `Erhöhung der Familienzulagen`;
- 0 cost-bearer suggestions in the available full source wording;
- 0 reviewed/publishable classifications; and
- 21,569 rebuilt search documents with zero foreign-key violations.

Sparse mechanism/beneficiary output is intentional. A named group is not automatically treated as helped or harmed. No reviewed classification exists until a human review decision is committed.

## Error modes and review requirements

- Keyword presence establishes topical relevance, not the direction or desirability of a policy.
- An affair title is repeated across several event-level votes. Each suggestion applies to that event's search/discovery record, but the exact question and Yes/No meanings must still be inspected.
- A vote on an amendment, minority proposal, return motion, or procedural step can carry the affair's topic without Yes necessarily supporting the affair as a whole.
- German rules miss French, Italian, Romansh, synonyms, negation, and effects that require legal/economic reasoning.
- The full snapshot currently lacks per-affair long text, summaries, and linked official topics/descriptors. Rules therefore rely mainly on titles, questions, and semantics, and the classifier records the matched field.
- Benefit/cost phrases can quote, reject, or discuss a proposal rather than enact it. Even explicit matches remain pending.
- Indirect, claimed, mixed, distributional, and second-order effects are not inferred by the deterministic rules.
- The benchmark is synthetic and too small to estimate precision/recall. Expanding it requires reviewed real examples with provenance and disagreement notes.

The safe operating rule is therefore: deterministic and model outputs assist discovery; only the latest human `accepted` or `edited` review appears in the `reviewed_classification` publication view.
