<?php

declare(strict_types=1);

use Politiks\Tooling\Environment;
use Politiks\Tooling\MariaDb;

require __DIR__ . '/../../scripts/lib/Environment.php';
require __DIR__ . '/../../scripts/lib/MariaDb.php';

$root = dirname(__DIR__, 2);
$envPath = $root . DIRECTORY_SEPARATOR . '.env.test';

try {
    $pdo = MariaDb::connect(Environment::load($envPath));
    $pdo->beginTransaction();
    $publicationId = $pdo->query('SELECT active_publication_id FROM reference_state WHERE singleton_id=1')->fetchColumn();
    if ($publicationId === false || $publicationId === null) {
        throw new RuntimeException('Playwright seed requires an active reference publication.');
    }

    $users = [
        ['playwright-google-subject', 'playwright.user@example.test', 'Mara Muster'],
        ['playwright-community-subject', 'community@example.test', 'Nina Beispiel'],
    ];
    $upsertUser = $pdo->prepare(
        "INSERT INTO app_user (google_sub, email, display_name, role, is_active, created_at, updated_at)
         VALUES (?, ?, ?, 'user', 1, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))
         ON DUPLICATE KEY UPDATE display_name=VALUES(display_name), is_active=1, updated_at=UTC_TIMESTAMP(6)"
    );
    foreach ($users as $user) {
        $upsertUser->execute($user);
    }
    $findUser = $pdo->prepare('SELECT id FROM app_user WHERE google_sub=?');
    $findUser->execute([$users[0][0]]);
    $ownerId = (int) $findUser->fetchColumn();
    $findUser->execute([$users[1][0]]);
    $communityId = (int) $findUser->fetchColumn();

    $publicIds = [
        'aaaaaaaaaaaaaaaaaaaaaaaaaa',
        'bbbbbbbbbbbbbbbbbbbbbbbbbb',
        'cccccccccccccccccccccccccc',
        'dddddddddddddddddddddddddd',
    ];
    $delete = $pdo->prepare('DELETE FROM insight WHERE public_id IN (?, ?, ?, ?)');
    $delete->execute($publicIds);

    $lookup = static function (PDO $pdo, string $table, int $publicationId): ?int {
        $statement = $pdo->prepare("SELECT source_id FROM $table WHERE publication_id=? ORDER BY source_id LIMIT 1");
        $statement->execute([$publicationId]);
        $value = $statement->fetchColumn();
        return $value === false ? null : (int) $value;
    };
    $countryId = $lookup($pdo, 'ref_country', (int) $publicationId);
    $legislatureId = $lookup($pdo, 'ref_legislature', (int) $publicationId);
    $chamberId = $lookup($pdo, 'ref_chamber', (int) $publicationId);
    $partyId = $lookup($pdo, 'ref_party', (int) $publicationId);
    $eventId = $lookup($pdo, 'ref_voting_event', (int) $publicationId);

    $insert = $pdo->prepare(
        'INSERT INTO insight
         (public_id, owner_user_id, reference_publication_id, country_source_id, legislature_source_id,
          chamber_source_id, party_source_id, period_from, period_to, title, claim_text,
          explanatory_notes, visibility, share_token_hash, created_at, updated_at, published_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), ?)'
    );
    $publishedAt = '2026-01-15 12:00:00.000000';
    $insert->execute([
        $publicIds[0], $communityId, $publicationId, $countryId, $legislatureId, $chamberId, $partyId,
        '2023-01-01', '2025-12-31', 'Versprechen und Abstimmungsverhalten im Vergleich',
        'Die ausgewählten Abstimmungen zeigen, wie sich öffentliche Positionierung und parlamentarisches Handeln vergleichen lassen.',
        'Dieser Beispieldatensatz trennt die Einordnung der Autorin von den offiziellen Abstimmungsdaten.',
        'public', null, $publishedAt,
    ]);
    $publicInsightId = (int) $pdo->lastInsertId();
    if ($eventId !== null) {
        $evidence = $pdo->prepare(
            'INSERT INTO insight_vote_evidence
             (insight_id, reference_publication_id, voting_event_source_id, position, created_at)
             VALUES (?, ?, ?, 1, UTC_TIMESTAMP(6))'
        );
        $evidence->execute([$publicInsightId, $publicationId, $eventId]);
    }

    $shareToken = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopq';
    $personal = [
        [$publicIds[1], 'Mein erster Entwurf', null, 'Noch in Bearbeitung.', 'draft', null, null],
        [$publicIds[2], 'Analyse für mein Team', 'Dieser Insight ist nur über seinen Link sichtbar.', null, 'unlisted', hash('sha256', $shareToken), null],
        [$publicIds[3], 'Mein veröffentlichter Insight', 'Eine öffentlich sichtbare, von Mara verfasste Einordnung.', null, 'public', null, '2026-01-16 12:00:00.000000'],
    ];
    foreach ($personal as $row) {
        $insert->execute([
            $row[0], $ownerId, $publicationId, $countryId, $legislatureId, $chamberId, $partyId,
            null, null, $row[1], $row[2], $row[3], $row[4], $row[5], $row[6],
        ]);
    }
    $pdo->commit();
    echo "Playwright catalogue fixture ready.\n";
} catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Playwright seed failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
