<?php

declare(strict_types=1);

/**
 * Creates a deterministic test-only reference publication. It is never used by
 * production tooling and deliberately models cohort direction changes that the
 * compact official acquisition fixture cannot cover because its event chamber
 * is unknown.
 */
function ensureWizardReferenceFixture(PDO $pdo): int
{
    $key = hash('sha256', 'politiks-playwright-wizard-reference-v1');
    $find = $pdo->prepare('SELECT id FROM reference_publication WHERE publication_key=?');
    $find->execute([$key]);
    $publicationId = $find->fetchColumn();
    if ($publicationId === false) {
        $insert = $pdo->prepare(
            "INSERT INTO reference_publication
             (publication_key, source_snapshot, source_schema_version, source_digest,
              taxonomy_version, taxonomy_digest, review_digest, content_sha256,
              counts_json, status, created_at, activated_at)
             VALUES (?, 'synthetic-wizard-test-v1', 'test-1', ?, NULL, NULL, ?, ?, '{}',
                     'active', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))"
        );
        $digest = hash('sha256', 'synthetic-wizard-test-v1');
        $insert->execute([$key, $digest, $digest, $digest]);
        $publicationId = (int) $pdo->lastInsertId();
    } else {
        $publicationId = (int) $publicationId;
    }

    $execute = static function (PDO $pdo, string $sql, array $rows) use ($publicationId): void {
        $statement = $pdo->prepare($sql);
        foreach ($rows as $row) {
            $statement->execute([$publicationId, ...$row]);
        }
    };
    $execute($pdo,
        'INSERT INTO ref_country (publication_id, source_id, iso2, iso3, name_de)
         VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE name_de=VALUES(name_de)',
        [[910001, 'CH', 'CHE', 'Schweiz']]
    );
    $execute($pdo,
        "INSERT INTO ref_legislature
         (publication_id, source_id, country_source_id, source_system, source_identifier, name, valid_from, valid_to)
         VALUES (?, ?, 910001, 'test', ?, ?, '2023-01-01', '2027-12-31')
         ON DUPLICATE KEY UPDATE name=VALUES(name)",
        [[910002, 'bundesversammlung-test', 'Bundesversammlung']]
    );
    $execute($pdo,
        "INSERT INTO ref_chamber
         (publication_id, source_id, legislature_source_id, source_system, source_identifier, code, abbreviation, name, chamber_type)
         VALUES (?, ?, 910002, 'test', ?, 'NR', 'NR', 'Nationalrat', 'lower')
         ON DUPLICATE KEY UPDATE name=VALUES(name)",
        [[910003, 'nationalrat-test']]
    );
    $execute($pdo,
        "INSERT INTO ref_party
         (publication_id, source_id, country_source_id, source_system, source_identifier, code, abbreviation, name, valid_from, valid_to)
         VALUES (?, ?, 910001, 'test', ?, 'BSP', 'BSP', 'Beispielpartei Schweiz', '2020-01-01', NULL)
         ON DUPLICATE KEY UPDATE name=VALUES(name)",
        [[910004, 'beispielpartei-test']]
    );
    $execute($pdo,
        "INSERT INTO ref_faction
         (publication_id, source_id, legislature_source_id, source_system, source_identifier, code, abbreviation, name, valid_from, valid_to)
         VALUES (?, ?, 910002, 'test', ?, 'BFG', 'BFG', 'Beispielfraktion', '2020-01-01', NULL)
         ON DUPLICATE KEY UPDATE name=VALUES(name)",
        [[910005, 'beispielfraktion-test']]
    );

    $people = [
        [910101, 'Anna Beispiel', 'Anna', 'Beispiel'],
        [910102, 'Bruno Muster', 'Bruno', 'Muster'],
        [910103, 'Carla Probe', 'Carla', 'Probe'],
        [910104, 'David Demo', 'David', 'Demo'],
    ];
    $execute($pdo,
        'INSERT INTO ref_person (publication_id, source_id, display_name, first_name, last_name)
         VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE display_name=VALUES(display_name)',
        $people
    );
    $mandates = [];
    $partyMemberships = [];
    $factionMemberships = [];
    foreach ($people as $position => $person) {
        $mandates[] = [911000 + $position, $person[0]];
        $partyMemberships[] = [912000 + $position, $person[0]];
        $factionMemberships[] = [913000 + $position, $person[0]];
    }
    $execute($pdo,
        "INSERT INTO ref_person_mandate
         (publication_id, source_id, person_source_id, chamber_source_id, subdivision_source_id,
          date_from, date_to, is_inferred, evidence_basis)
         VALUES (?, ?, ?, 910003, NULL, '2023-01-01', '2027-12-31', 0, 'synthetic-test')
         ON DUPLICATE KEY UPDATE date_to=VALUES(date_to)",
        $mandates
    );
    $execute($pdo,
        "INSERT INTO ref_person_party_membership
         (publication_id, source_id, person_source_id, party_source_id, date_from, date_to, is_inferred, evidence_basis)
         VALUES (?, ?, ?, 910004, '2023-01-01', '2027-12-31', 0, 'synthetic-test')
         ON DUPLICATE KEY UPDATE date_to=VALUES(date_to)",
        $partyMemberships
    );
    $execute($pdo,
        "INSERT INTO ref_person_faction_membership
         (publication_id, source_id, person_source_id, faction_source_id, date_from, date_to, is_inferred, evidence_basis)
         VALUES (?, ?, ?, 910005, '2023-01-01', '2027-12-31', 0, 'synthetic-test')
         ON DUPLICATE KEY UPDATE date_to=VALUES(date_to)",
        $factionMemberships
    );
    $execute($pdo,
        "INSERT INTO ref_official_topic
         (publication_id, source_id, legislature_source_id, source_system, source_identifier, code, name)
         VALUES (?, 910010, 910002, 'test', 'wirtschaft-test', 'WIR', 'Wirtschaft')
         ON DUPLICATE KEY UPDATE name=VALUES(name)",
        [[]]
    );
    $execute($pdo,
        "INSERT INTO ref_taxonomy_term
         (publication_id, source_id, taxonomy_version, dimension, code, parent_code,
          name_de, description_de, sort_order)
         VALUES (?, 910011, 'test-1', 'policy_area', 'wirtschaft', NULL,
                 'Wirtschaftspolitik', 'Synthetische Testklassifikation', 1)
         ON DUPLICATE KEY UPDATE name_de=VALUES(name_de)",
        [[]]
    );

    $matterRows = [
        [910201, '25.001', '25.001', 'Steuerentlastung und Gegenfinanzierung'],
        [910202, '25.002', '25.002', 'Ausbau der sozialen Grundversorgung'],
        [910203, '25.003', '25.003', 'Regulierung digitaler Plattformen'],
        [910204, '25.004', '25.004', 'Verfahrensantrag zur Beratung'],
        [910205, '25.005', '25.005', 'Förderung erneuerbarer Energien'],
    ];
    $execute($pdo,
        "INSERT INTO ref_matter
         (publication_id, source_id, legislature_source_id, source_system, source_identifier,
          formatted_identifier, matter_type, matter_state, title, submitted_at, source_updated_at,
          provenance_url, provenance_sha256)
         VALUES (?, ?, 910002, 'test', ?, ?, 'Geschäft', 'Erledigt', ?, '2025-01-01', '2025-01-01',
                 'https://www.parlament.ch/', ?)
         ON DUPLICATE KEY UPDATE title=VALUES(title)",
        array_map(static fn (array $row): array => [...$row, hash('sha256', $row[1])], $matterRows)
    );
    $execute($pdo,
        'INSERT INTO ref_matter_topic (publication_id, matter_source_id, topic_source_id)
         VALUES (?, ?, 910010) ON DUPLICATE KEY UPDATE topic_source_id=VALUES(topic_source_id)',
        array_map(static fn (array $row): array => [$row[0]], $matterRows)
    );

    $eventRows = [
        [910301, 910201, 'NR:TEST-1', '1001', '2025-03-10 10:00:00', 'Schlussabstimmung Steuerentlastung', 'Wollen Sie der Vorlage zustimmen?', 'Annahme der Vorlage', 'Ablehnung der Vorlage', 'final_vote', 'Angenommen'],
        [910302, 910202, 'NR:TEST-2', '1002', '2025-04-11 11:00:00', 'Gesamtabstimmung Grundversorgung', 'Soll die Grundversorgung ausgebaut werden?', 'Ausbau', 'Kein Ausbau', 'overall_vote', 'Abgelehnt'],
        [910303, 910203, 'NR:TEST-3', '1003', '2025-05-12 09:30:00', 'Regulierung digitaler Plattformen', 'Soll die Regulierung angenommen werden?', 'Annahme', 'Ablehnung', 'substantive', 'Geteilt'],
        [910304, 910204, 'NR:TEST-4', '1004', '2025-06-13 14:00:00', 'Ordnungsantrag', 'Soll die Beratung verschoben werden?', 'Verschieben', 'Weiterberaten', 'procedural', 'Enthaltungen'],
        [910305, 910205, 'NR:TEST-5', '1005', '2025-07-14 15:00:00', 'Förderung erneuerbarer Energien', 'Soll die Förderung erhöht werden?', 'Förderung erhöhen', 'Förderung nicht erhöhen', 'substantive', 'Abgelehnt'],
    ];
    $execute($pdo,
        "INSERT INTO ref_voting_event
         (publication_id, source_id, matter_source_id, chamber_source_id, session_source_id,
          source_system, source_identifier, registration_number, occurred_at, division_text,
          submission_text, meaning_yes, meaning_no, vote_type, vote_type_basis, overall_decision,
          chamber_resolution_basis, provenance_url, provenance_sha256)
         VALUES (?, ?, ?, 910003, NULL, 'test', ?, ?, ?, ?, ?, ?, ?, ?, 'synthetic-test', ?,
                 'synthetic-test', 'https://www.parlament.ch/', ?)
         ON DUPLICATE KEY UPDATE division_text=VALUES(division_text), submission_text=VALUES(submission_text)",
        array_map(static fn (array $row): array => [...$row, hash('sha256', $row[2])], $eventRows)
    );

    $decisions = [
        910301 => ['yes', 'yes', 'no', 'abstain'],
        910302 => ['no', 'no', 'yes', 'no'],
        910303 => ['yes', 'no', 'not_participating', 'not_participating'],
        910304 => ['abstain', 'abstain', 'abstain', 'abstain'],
        910305 => ['yes', 'no', 'no', 'not_participating'],
    ];
    $choiceStatement = $pdo->prepare(
        "INSERT INTO ref_voting_choice
         (publication_id, source_id, voting_event_source_id, person_source_id, source_system,
          source_identifier, raw_decision, normalized_choice)
         VALUES (?, ?, ?, ?, 'test', ?, ?, ?)
         ON DUPLICATE KEY UPDATE normalized_choice=VALUES(normalized_choice), raw_decision=VALUES(raw_decision)"
    );
    $choiceSourceId = 914000;
    foreach ($decisions as $eventId => $eventDecisions) {
        foreach ($eventDecisions as $position => $decision) {
            $personId = $people[$position][0];
            ++$choiceSourceId;
            $choiceStatement->execute([
                $publicationId, $choiceSourceId, $eventId, $personId,
                sprintf('%d:%d', $eventId, $personId), $decision, $decision,
            ]);
        }
    }
    $searchStatement = $pdo->prepare(
        "INSERT INTO ref_vote_search_document
         (publication_id, voting_event_source_id, affair_identifier, affair_source_identifier,
          voting_identifier, registration_number, occurred_on, title, exact_question,
          meaning_yes, meaning_no, official_metadata, reviewed_classifications, full_text)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Wirtschaft Nationalrat', '', ?)
         ON DUPLICATE KEY UPDATE full_text=VALUES(full_text), title=VALUES(title)"
    );
    foreach ($eventRows as $position => $event) {
        $matter = $matterRows[$position];
        $fullText = implode(' ', [$matter[1], $matter[2], $matter[3], $event[2], $event[4], $event[5], $event[6], $event[7]]);
        $searchStatement->execute([
            $publicationId, $event[0], $matter[2], $matter[1], $event[2], $event[3],
            substr($event[4], 0, 10), $matter[3], $event[6], $event[7], $event[8], $fullText,
        ]);
    }
    $execute($pdo,
        "INSERT INTO ref_reviewed_classification
         (publication_id, suggestion_key, classification_run_source_id, source_snapshot,
          classification_method, target_kind, matter_source_id, voting_event_source_id,
          taxonomy_term_source_id, relationship, effect_direction, directness, confidence,
          evidence_field, evidence_passage, reviewer, reviewed_at, review_decision, notes)
         VALUES (?, ?, 1, 'synthetic-wizard-test-v1', 'manual', 'vote', NULL, 910301,
                 910011, 'supports', 'positive', 'direct', 1.0, 'title',
                 'Synthetische Evidenz', 'test-suite', '2026-01-01 00:00:00', 'accepted', NULL)
         ON DUPLICATE KEY UPDATE review_decision=VALUES(review_decision)",
        [[hash('sha256', 'wizard-reviewed-classification-910301')]]
    );

    $counts = [
        'ref_country' => 1,
        'ref_legislature' => 1,
        'ref_chamber' => 1,
        'ref_party' => 1,
        'ref_faction' => 1,
        'ref_person' => 4,
        'ref_person_mandate' => 4,
        'ref_person_party_membership' => 4,
        'ref_person_faction_membership' => 4,
        'ref_matter' => 5,
        'ref_official_topic' => 1,
        'ref_taxonomy_term' => 1,
        'ref_reviewed_classification' => 1,
        'ref_matter_topic' => 5,
        'ref_voting_event' => 5,
        'ref_voting_choice' => 20,
        'ref_vote_search_document' => 5,
    ];
    $updatePublication = $pdo->prepare(
        "UPDATE reference_publication SET counts_json=?, status='active', activated_at=UTC_TIMESTAMP(6) WHERE id=?"
    );
    $updatePublication->execute([json_encode($counts, JSON_THROW_ON_ERROR), $publicationId]);
    $pdo->prepare('UPDATE reference_state SET active_publication_id=?, updated_at=UTC_TIMESTAMP(6) WHERE singleton_id=1')
        ->execute([$publicationId]);
    return $publicationId;
}
