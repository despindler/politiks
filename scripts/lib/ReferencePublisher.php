<?php

declare(strict_types=1);

namespace Politiks\Tooling;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;

final class ReferencePublisher
{
    private const READ_MODEL_VERSION = '1.0.0';
    private const BATCH_SIZE = 200;

    public function __construct(
        private readonly PDO $source,
        private readonly PDO $destination,
    ) {
        $this->source->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed> */
    public function publish(?string $simulateFailureAfter = null, ?string $testKeySuffix = null): array
    {
        $metadata = $this->publicationMetadata($testKeySuffix);
        $this->destination->beginTransaction();
        try {
            $this->destination->query(
                'SELECT singleton_id FROM reference_state WHERE singleton_id=1 FOR UPDATE'
            )->fetch();
            $existing = $this->selectPublication($metadata['publication_key']);
            if ($existing !== null) {
                if ($existing['status'] === 'loading') {
                    throw new RuntimeException('An incomplete publication with this key already exists.');
                }
                foreach (['source_digest', 'review_digest'] as $field) {
                    if (!hash_equals((string) $existing[$field], (string) $metadata[$field])) {
                        throw new RuntimeException('Publication-key metadata collision detected.');
                    }
                }
                $publicationId = (int) $existing['id'];
                $this->activatePublication($publicationId);
                $this->destination->commit();
                return [
                    'publication_id' => $publicationId,
                    'publication_key' => $metadata['publication_key'],
                    'reused' => true,
                    'content_sha256' => $existing['content_sha256'],
                    'counts' => json_decode((string) $existing['counts_json'], true, 512, JSON_THROW_ON_ERROR),
                ];
            }

            $insertPublication = $this->destination->prepare(
                "INSERT INTO reference_publication
                 (publication_key, source_snapshot, source_schema_version, source_digest,
                  taxonomy_version, taxonomy_digest, review_digest, status, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'loading', UTC_TIMESTAMP(6))"
            );
            $insertPublication->execute([
                $metadata['publication_key'],
                $metadata['source_snapshot'],
                $metadata['source_schema_version'],
                $metadata['source_digest'],
                $metadata['taxonomy_version'],
                $metadata['taxonomy_digest'],
                $metadata['review_digest'],
            ]);
            $publicationId = (int) $this->destination->lastInsertId();

            $hash = hash_init('sha256');
            $counts = [];
            foreach ($this->tableMappings() as $mapping) {
                $count = $this->publishTable($publicationId, $mapping, $hash);
                $counts[$mapping['destination']] = $count;
                $destinationCount = $this->countDestinationRows($mapping['destination'], $publicationId);
                if ($destinationCount !== $count) {
                    throw new RuntimeException(
                        sprintf(
                            'Reconciliation failed for %s: source %d, destination %d.',
                            $mapping['destination'],
                            $count,
                            $destinationCount
                        )
                    );
                }
                if ($simulateFailureAfter === $mapping['destination']) {
                    throw new RuntimeException('Simulated publication failure after ' . $simulateFailureAfter);
                }
            }
            $contentDigest = hash_final($hash);
            $countsJson = $this->canonicalJson($counts);
            $complete = $this->destination->prepare(
                "UPDATE reference_publication
                 SET content_sha256=?, counts_json=?, status='active', activated_at=UTC_TIMESTAMP(6)
                 WHERE id=? AND status='loading'"
            );
            $complete->execute([$contentDigest, $countsJson, $publicationId]);
            if ($complete->rowCount() !== 1) {
                throw new RuntimeException('Publication could not be finalized from the loading state.');
            }
            $this->activatePublication($publicationId);
            $this->destination->commit();

            return [
                'publication_id' => $publicationId,
                'publication_key' => $metadata['publication_key'],
                'reused' => false,
                'content_sha256' => $contentDigest,
                'counts' => $counts,
            ];
        } catch (Throwable $error) {
            if ($this->destination->inTransaction()) {
                $this->destination->rollBack();
            }
            throw $error;
        }
    }

    /** @return array<string, mixed> */
    private function publicationMetadata(?string $testKeySuffix): array
    {
        $run = $this->source->query(
            "SELECT snapshot_name, schema_version FROM import_run
             WHERE status='completed' ORDER BY id"
        )->fetchAll();
        if (count($run) !== 1) {
            throw new RuntimeException('Publication requires exactly one completed SQLite import run.');
        }

        $sourceHash = hash_init('sha256');
        $sourceFiles = $this->source->query(
            'SELECT local_path, sha256 FROM source_file ORDER BY local_path'
        );
        while ($row = $sourceFiles->fetch()) {
            hash_update($sourceHash, $this->canonicalJson($row) . "\n");
        }
        $sourceDigest = hash_final($sourceHash);

        $taxonomy = $this->source->query(
            "SELECT version, definition_sha256 FROM taxonomy_version
             WHERE status='active' ORDER BY id DESC LIMIT 1"
        )->fetch();

        $reviewHash = hash_init('sha256');
        $reviewed = $this->source->query(
            'SELECT * FROM reviewed_classification ORDER BY suggestion_key'
        );
        while ($row = $reviewed->fetch()) {
            hash_update($reviewHash, $this->canonicalJson($row) . "\n");
        }
        $reviewDigest = hash_final($reviewHash);

        $keyInput = [
            'read_model_version' => self::READ_MODEL_VERSION,
            'source_snapshot' => $run[0]['snapshot_name'],
            'source_schema_version' => $run[0]['schema_version'],
            'source_digest' => $sourceDigest,
            'taxonomy_version' => $taxonomy['version'] ?? null,
            'taxonomy_digest' => $taxonomy['definition_sha256'] ?? null,
            'review_digest' => $reviewDigest,
        ];
        if ($testKeySuffix !== null) {
            $keyInput['test_key_suffix'] = $testKeySuffix;
        }

        return [
            ...$keyInput,
            'publication_key' => hash('sha256', $this->canonicalJson($keyInput)),
        ];
    }

    /** @return array<string, mixed>|null */
    private function selectPublication(string $key): ?array
    {
        $statement = $this->destination->prepare(
            'SELECT * FROM reference_publication WHERE publication_key=? FOR UPDATE'
        );
        $statement->execute([$key]);
        $row = $statement->fetch();
        return $row === false ? null : $row;
    }

    private function activatePublication(int $publicationId): void
    {
        $retire = $this->destination->prepare(
            "UPDATE reference_publication SET status='retired'
             WHERE status='active' AND id<>?"
        );
        $retire->execute([$publicationId]);
        $activate = $this->destination->prepare(
            "UPDATE reference_publication
             SET status='active', activated_at=COALESCE(activated_at, UTC_TIMESTAMP(6))
             WHERE id=? AND status IN ('active', 'retired')"
        );
        $activate->execute([$publicationId]);
        $state = $this->destination->prepare(
            'UPDATE reference_state SET active_publication_id=?, updated_at=UTC_TIMESTAMP(6) WHERE singleton_id=1'
        );
        $state->execute([$publicationId]);
    }

    /**
     * @param array{destination:string, columns:list<string>, sql:string, datetime_columns?:list<string>} $mapping
     * @param resource $hash
     */
    private function publishTable(int $publicationId, array $mapping, $hash): int
    {
        $statement = $this->source->query($mapping['sql']);
        $batch = [];
        $count = 0;
        hash_update($hash, $mapping['destination'] . "\n");
        while ($row = $statement->fetch()) {
            $normalized = [];
            foreach ($mapping['columns'] as $column) {
                if (!array_key_exists($column, $row)) {
                    throw new RuntimeException(
                        sprintf('Source mapping for %s lacks %s.', $mapping['destination'], $column)
                    );
                }
                $value = $row[$column];
                if ($value !== null && in_array($column, $mapping['datetime_columns'] ?? [], true)) {
                    $value = $this->mariaDateTime((string) $value);
                }
                $normalized[$column] = $value;
            }
            hash_update($hash, $this->canonicalJson($normalized) . "\n");
            $batch[] = $normalized;
            $count++;
            if (count($batch) >= self::BATCH_SIZE) {
                $this->insertBatch($mapping['destination'], $mapping['columns'], $publicationId, $batch);
                $batch = [];
            }
        }
        if ($batch !== []) {
            $this->insertBatch($mapping['destination'], $mapping['columns'], $publicationId, $batch);
        }
        return $count;
    }

    /** @param list<string> $columns @param list<array<string, mixed>> $rows */
    private function insertBatch(string $table, array $columns, int $publicationId, array $rows): void
    {
        $allColumns = ['publication_id', ...$columns];
        $quotedColumns = implode(', ', array_map(static fn (string $column): string => "`$column`", $allColumns));
        $group = '(' . implode(', ', array_fill(0, count($allColumns), '?')) . ')';
        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES %s',
            $table,
            $quotedColumns,
            implode(', ', array_fill(0, count($rows), $group))
        );
        $parameters = [];
        foreach ($rows as $row) {
            $parameters[] = $publicationId;
            foreach ($columns as $column) {
                $parameters[] = $row[$column];
            }
        }
        $statement = $this->destination->prepare($sql);
        $statement->execute($parameters);
    }

    private function countDestinationRows(string $table, int $publicationId): int
    {
        $statement = $this->destination->prepare(
            sprintf('SELECT COUNT(*) FROM `%s` WHERE publication_id=?', $table)
        );
        $statement->execute([$publicationId]);
        return (int) $statement->fetchColumn();
    }

    private function mariaDateTime(string $value): string
    {
        $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    /** @param mixed $value */
    private function canonicalJson($value): string
    {
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        );
    }

    /** @return list<array{destination:string, columns:list<string>, sql:string, datetime_columns?:list<string>}> */
    private function tableMappings(): array
    {
        return [
            ['destination' => 'ref_country', 'columns' => ['source_id', 'iso2', 'iso3', 'name_de'], 'sql' => 'SELECT id source_id, iso2, iso3, name_de FROM country ORDER BY id'],
            ['destination' => 'ref_legislature', 'columns' => ['source_id', 'country_source_id', 'source_system', 'source_identifier', 'name', 'valid_from', 'valid_to'], 'sql' => 'SELECT id source_id, country_id country_source_id, source_system, source_identifier, name, valid_from, valid_to FROM legislature ORDER BY id'],
            ['destination' => 'ref_chamber', 'columns' => ['source_id', 'legislature_source_id', 'source_system', 'source_identifier', 'code', 'abbreviation', 'name', 'chamber_type'], 'sql' => 'SELECT id source_id, legislature_id legislature_source_id, source_system, source_identifier, code, abbreviation, name, chamber_type FROM chamber ORDER BY id'],
            ['destination' => 'ref_legislative_period', 'columns' => ['source_id', 'legislature_source_id', 'source_system', 'source_identifier', 'code', 'name', 'date_from', 'date_to'], 'sql' => 'SELECT id source_id, legislature_id legislature_source_id, source_system, source_identifier, code, name, date_from, date_to FROM legislative_period ORDER BY id'],
            ['destination' => 'ref_session', 'columns' => ['source_id', 'legislature_source_id', 'period_source_id', 'source_system', 'source_identifier', 'code', 'name', 'date_from', 'date_to'], 'sql' => 'SELECT id source_id, legislature_id legislature_source_id, legislative_period_id period_source_id, source_system, source_identifier, code, name, date_from, date_to FROM parliamentary_session ORDER BY id'],
            ['destination' => 'ref_subdivision', 'columns' => ['source_id', 'country_source_id', 'source_system', 'source_identifier', 'code', 'abbreviation', 'name', 'subdivision_type'], 'sql' => "SELECT id source_id, country_id country_source_id, source_system, source_identifier, code, abbreviation, name, NULL subdivision_type FROM subdivision ORDER BY id"],
            ['destination' => 'ref_committee', 'columns' => ['source_id', 'legislature_source_id', 'chamber_source_id', 'source_system', 'source_identifier', 'code', 'abbreviation', 'name', 'committee_type', 'valid_from', 'valid_to'], 'sql' => 'SELECT id source_id, legislature_id legislature_source_id, chamber_id chamber_source_id, source_system, source_identifier, code, abbreviation, name, committee_type, valid_from, valid_to FROM committee ORDER BY id'],
            ['destination' => 'ref_party', 'columns' => ['source_id', 'country_source_id', 'source_system', 'source_identifier', 'code', 'abbreviation', 'name', 'valid_from', 'valid_to'], 'sql' => 'SELECT id source_id, country_id country_source_id, source_system, source_identifier, code, abbreviation, name, valid_from, valid_to FROM political_party ORDER BY id'],
            ['destination' => 'ref_faction', 'columns' => ['source_id', 'legislature_source_id', 'source_system', 'source_identifier', 'code', 'abbreviation', 'name', 'valid_from', 'valid_to'], 'sql' => 'SELECT id source_id, legislature_id legislature_source_id, source_system, source_identifier, code, abbreviation, name, valid_from, valid_to FROM parliamentary_faction ORDER BY id'],
            ['destination' => 'ref_person', 'columns' => ['source_id', 'display_name', 'first_name', 'last_name', 'gender', 'birth_date', 'death_date'], 'sql' => 'SELECT id source_id, display_name, first_name, last_name, gender, birth_date, death_date FROM person ORDER BY id'],
            ['destination' => 'ref_person_identifier', 'columns' => ['source_id', 'person_source_id', 'source_system', 'namespace', 'identifier', 'resolution_method'], 'sql' => 'SELECT id source_id, person_id person_source_id, source_system, namespace, identifier, resolution_method FROM person_identifier ORDER BY id'],
            ['destination' => 'ref_person_mandate', 'columns' => ['source_id', 'person_source_id', 'chamber_source_id', 'subdivision_source_id', 'date_from', 'date_to', 'is_inferred', 'evidence_basis'], 'sql' => "SELECT id source_id, person_id person_source_id, chamber_id chamber_source_id, subdivision_id subdivision_source_id, date_from, date_to, 0 is_inferred, 'source_normalized_mandate' evidence_basis FROM person_mandate ORDER BY id"],
            ['destination' => 'ref_person_party_membership', 'columns' => ['source_id', 'person_source_id', 'party_source_id', 'date_from', 'date_to', 'is_inferred', 'evidence_basis'], 'sql' => 'SELECT id source_id, person_id person_source_id, party_id party_source_id, date_from, date_to, is_inferred, evidence_basis FROM person_party_membership ORDER BY id'],
            ['destination' => 'ref_person_faction_membership', 'columns' => ['source_id', 'person_source_id', 'faction_source_id', 'date_from', 'date_to', 'is_inferred', 'evidence_basis'], 'sql' => 'SELECT id source_id, person_id person_source_id, faction_id faction_source_id, date_from, date_to, is_inferred, evidence_basis FROM person_faction_membership ORDER BY id'],
            ['destination' => 'ref_matter', 'columns' => ['source_id', 'legislature_source_id', 'source_system', 'source_identifier', 'formatted_identifier', 'matter_type', 'matter_state', 'title', 'submitted_at', 'source_updated_at', 'provenance_url', 'provenance_sha256'], 'datetime_columns' => ['submitted_at', 'source_updated_at'], 'sql' => "SELECT matter.id source_id, matter.legislature_id legislature_source_id, matter.source_system, matter.source_identifier, matter.formatted_identifier, type.name matter_type, state.name matter_state, matter.title, matter.submitted_at, matter.source_updated_at, file.final_url provenance_url, file.sha256 provenance_sha256 FROM parliamentary_matter matter LEFT JOIN matter_type type ON type.id=matter.matter_type_id LEFT JOIN matter_state state ON state.id=matter.matter_state_id LEFT JOIN source_record record ON record.id=matter.source_record_id LEFT JOIN source_file file ON file.id=record.source_file_id ORDER BY matter.id"],
            ['destination' => 'ref_matter_text', 'columns' => ['source_id', 'matter_source_id', 'language', 'text_type_identifier', 'text_type_name', 'body_html'], 'sql' => 'SELECT id source_id, matter_id matter_source_id, language, text_type_identifier, text_type_name, body_html FROM matter_text ORDER BY id'],
            ['destination' => 'ref_matter_summary', 'columns' => ['source_id', 'matter_source_id', 'language', 'description_html', 'initial_situation_html', 'proceedings_html'], 'sql' => 'SELECT id source_id, matter_id matter_source_id, language, description_html, initial_situation_html, proceedings_html FROM matter_summary ORDER BY id'],
            ['destination' => 'ref_official_topic', 'columns' => ['source_id', 'legislature_source_id', 'source_system', 'source_identifier', 'code', 'name'], 'sql' => 'SELECT id source_id, legislature_id legislature_source_id, source_system, source_identifier, code, name FROM official_topic ORDER BY id'],
            ['destination' => 'ref_official_descriptor', 'columns' => ['source_id', 'legislature_source_id', 'source_system', 'source_identifier', 'descriptor_type', 'name'], 'sql' => 'SELECT id source_id, legislature_id legislature_source_id, source_system, source_identifier, descriptor_type, name FROM official_descriptor ORDER BY id'],
            ['destination' => 'ref_matter_topic', 'columns' => ['matter_source_id', 'topic_source_id'], 'sql' => 'SELECT matter_id matter_source_id, topic_id topic_source_id FROM matter_topic ORDER BY matter_id, topic_id'],
            ['destination' => 'ref_matter_descriptor', 'columns' => ['matter_source_id', 'descriptor_source_id'], 'sql' => 'SELECT matter_id matter_source_id, descriptor_id descriptor_source_id FROM matter_descriptor ORDER BY matter_id, descriptor_id'],
            ['destination' => 'ref_voting_event', 'columns' => ['source_id', 'matter_source_id', 'chamber_source_id', 'session_source_id', 'source_system', 'source_identifier', 'registration_number', 'occurred_at', 'division_text', 'submission_text', 'meaning_yes', 'meaning_no', 'vote_type', 'vote_type_basis', 'overall_decision', 'chamber_resolution_basis', 'provenance_url', 'provenance_sha256'], 'datetime_columns' => ['occurred_at'], 'sql' => 'SELECT event.id source_id, event.matter_id matter_source_id, event.chamber_id chamber_source_id, event.session_id session_source_id, event.source_system, event.source_identifier, event.registration_number, event.occurred_at, event.division_text, event.submission_text, event.meaning_yes, event.meaning_no, event.vote_type, event.vote_type_basis, event.overall_decision, event.chamber_resolution_basis, file.final_url provenance_url, file.sha256 provenance_sha256 FROM voting_event event LEFT JOIN source_record record ON record.id=event.source_record_id LEFT JOIN source_file file ON file.id=record.source_file_id ORDER BY event.id'],
            ['destination' => 'ref_voting_aggregate', 'columns' => ['source_id', 'voting_event_source_id', 'aggregate_scope', 'source_choice_code', 'normalized_choice', 'vote_count', 'mapping_is_inferred'], 'sql' => 'SELECT id source_id, voting_event_id voting_event_source_id, aggregate_scope, source_choice_code, normalized_choice, vote_count, mapping_is_inferred FROM voting_aggregate ORDER BY id'],
            ['destination' => 'ref_voting_choice', 'columns' => ['source_id', 'voting_event_source_id', 'person_source_id', 'source_system', 'source_identifier', 'raw_decision', 'normalized_choice'], 'sql' => 'SELECT id source_id, voting_event_id voting_event_source_id, person_id person_source_id, source_system, source_identifier, raw_decision, normalized_choice FROM voting_choice ORDER BY id'],
            ['destination' => 'ref_taxonomy_term', 'columns' => ['source_id', 'taxonomy_version', 'dimension', 'code', 'parent_code', 'name_de', 'description_de', 'sort_order'], 'sql' => 'SELECT term.id source_id, version.version taxonomy_version, term.dimension, term.code, term.parent_code, term.name_de, term.description_de, term.sort_order FROM taxonomy_term term JOIN taxonomy_version version ON version.id=term.taxonomy_version_id ORDER BY term.id'],
            ['destination' => 'ref_reviewed_classification', 'columns' => ['suggestion_key', 'classification_run_source_id', 'source_snapshot', 'classification_method', 'target_kind', 'matter_source_id', 'voting_event_source_id', 'taxonomy_term_source_id', 'relationship', 'effect_direction', 'directness', 'confidence', 'evidence_field', 'evidence_passage', 'reviewer', 'reviewed_at', 'review_decision', 'notes'], 'datetime_columns' => ['reviewed_at'], 'sql' => 'SELECT suggestion_key, classification_run_id classification_run_source_id, source_snapshot, classification_method, target_kind, matter_id matter_source_id, voting_event_id voting_event_source_id, taxonomy_term_id taxonomy_term_source_id, relationship, effect_direction, directness, confidence, evidence_field, evidence_passage, reviewer, reviewed_at, decision review_decision, notes FROM reviewed_classification ORDER BY suggestion_key'],
            ['destination' => 'ref_vote_search_document', 'columns' => ['voting_event_source_id', 'affair_identifier', 'affair_source_identifier', 'voting_identifier', 'registration_number', 'occurred_on', 'title', 'exact_question', 'meaning_yes', 'meaning_no', 'official_metadata', 'reviewed_classifications', 'full_text'], 'sql' => 'SELECT voting_event_id voting_event_source_id, affair_identifier, affair_source_identifier, voting_identifier, registration_number, occurred_on, title, exact_question, meaning_yes, meaning_no, official_metadata, reviewed_classifications, full_text FROM voting_event_search_document ORDER BY voting_event_id'],
        ];
    }
}
