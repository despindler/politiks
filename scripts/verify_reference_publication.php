<?php

declare(strict_types=1);

use Politiks\Tooling\Environment;
use Politiks\Tooling\MariaDb;

require __DIR__ . '/lib/Environment.php';
require __DIR__ . '/lib/MariaDb.php';

$root = dirname(__DIR__);
$envPath = $root . DIRECTORY_SEPARATOR . '.env.test';
$expectedPublicationCount = null;

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--env=')) {
        $candidate = substr($argument, 6);
        $envPath = preg_match('~^(?:[A-Za-z]:[\\/]|[\\/])~', $candidate) === 1
            ? $candidate
            : $root . DIRECTORY_SEPARATOR . $candidate;
    } elseif (str_starts_with($argument, '--expect-publications=')) {
        $value = substr($argument, 22);
        if ($value === '' || !ctype_digit($value)) {
            fwrite(STDERR, "--expect-publications must be a non-negative integer.\n");
            exit(2);
        }
        $expectedPublicationCount = (int) $value;
    } else {
        fwrite(STDERR, sprintf("Unknown argument: %s\n", $argument));
        exit(2);
    }
}

try {
    $environment = Environment::load($envPath);
    $connection = MariaDb::connect($environment);

    $active = $connection->query(
        "SELECT publication.id, publication.status, publication.counts_json
         FROM reference_state state
         JOIN reference_publication publication ON publication.id=state.active_publication_id
         WHERE state.singleton_id=1"
    )->fetch();
    if ($active === false || $active['status'] !== 'active') {
        throw new RuntimeException('The active publication pointer is missing or not active.');
    }

    $publicationCount = (int) $connection->query(
        'SELECT COUNT(*) FROM reference_publication'
    )->fetchColumn();
    if ($expectedPublicationCount !== null && $publicationCount !== $expectedPublicationCount) {
        throw new RuntimeException(sprintf(
            'Expected %d publication rows, found %d.',
            $expectedPublicationCount,
            $publicationCount
        ));
    }

    $loadingCount = (int) $connection->query(
        "SELECT COUNT(*) FROM reference_publication WHERE status='loading'"
    )->fetchColumn();
    if ($loadingCount !== 0) {
        throw new RuntimeException(sprintf('Found %d incomplete publication rows.', $loadingCount));
    }

    $counts = json_decode((string) $active['counts_json'], true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($counts) || $counts === []) {
        throw new RuntimeException('The active publication has no reconciliation counts.');
    }
    $activeId = (int) $active['id'];
    foreach ($counts as $table => $expectedCount) {
        if (!is_string($table) || preg_match('/^ref_[a-z0-9_]+$/', $table) !== 1) {
            throw new RuntimeException('Publication metadata contains an invalid reference table name.');
        }
        if (!is_int($expectedCount)) {
            throw new RuntimeException(sprintf('Publication count for %s is not an integer.', $table));
        }
        $statement = $connection->prepare(
            sprintf('SELECT COUNT(*) FROM `%s` WHERE publication_id=?', $table)
        );
        $statement->execute([$activeId]);
        $actualCount = (int) $statement->fetchColumn();
        if ($actualCount !== $expectedCount) {
            throw new RuntimeException(sprintf(
                'Count mismatch for %s: metadata %d, database %d.',
                $table,
                $expectedCount,
                $actualCount
            ));
        }
    }

    $representativeVote = $connection->prepare(
        "SELECT voting_identifier
         FROM ref_vote_search_document
         WHERE publication_id=? AND voting_identifier IS NOT NULL
         ORDER BY voting_event_source_id LIMIT 1"
    );
    $representativeVote->execute([$activeId]);
    $votingIdentifier = $representativeVote->fetchColumn();
    if (!is_string($votingIdentifier) || $votingIdentifier === '') {
        throw new RuntimeException('No representative vote identifier is available for exact search.');
    }
    $exactVote = $connection->prepare(
        'SELECT COUNT(*) FROM ref_vote_search_document WHERE publication_id=? AND voting_identifier=?'
    );
    $exactVote->execute([$activeId, $votingIdentifier]);
    if ((int) $exactVote->fetchColumn() < 1) {
        throw new RuntimeException('Exact vote-identifier lookup did not return its representative row.');
    }

    $datedPartyChoiceLinks = $connection->prepare(
        "SELECT COUNT(*)
         FROM ref_voting_choice choice_row
         JOIN ref_voting_event event
           ON event.publication_id=choice_row.publication_id
          AND event.source_id=choice_row.voting_event_source_id
         JOIN ref_person_party_membership membership
           ON membership.publication_id=choice_row.publication_id
          AND membership.person_source_id=choice_row.person_source_id
          AND DATE(event.occurred_at) >= membership.date_from
          AND (membership.date_to IS NULL OR DATE(event.occurred_at) <= membership.date_to)
         WHERE choice_row.publication_id=?"
    );
    $datedPartyChoiceLinks->execute([$activeId]);
    $partyChoiceCount = (int) $datedPartyChoiceLinks->fetchColumn();
    if ($partyChoiceCount < 1) {
        throw new RuntimeException('The date-valid party/member/vote query returned no representative rows.');
    }

    echo json_encode(
        [
            'publication_valid' => true,
            'active_publication_id' => $activeId,
            'publication_rows' => $publicationCount,
            'loading_publications' => $loadingCount,
            'reconciled_tables' => count($counts),
            'vote_search_documents' => $counts['ref_vote_search_document'] ?? null,
            'dated_party_choice_links' => $partyChoiceCount,
        ],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    ), PHP_EOL;
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'Reference publication verification failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
