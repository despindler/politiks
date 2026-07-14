<?php

declare(strict_types=1);

use Politiks\App\Insight\InsightException;
use Politiks\App\Insight\InsightStore;
use Politiks\App\Insight\WizardStore;
use Politiks\Tooling\Environment;
use Politiks\Tooling\MariaDb;

require __DIR__ . '/../../scripts/lib/Environment.php';
require __DIR__ . '/../../scripts/lib/MariaDb.php';
require __DIR__ . '/../../site/backend/bootstrap.php';
require __DIR__ . '/TestReferenceFixture.php';

$emails = ['insight.owner@example.test', 'insight.other@example.test'];

try {
    $pdo = MariaDb::connect(Environment::load(dirname(__DIR__, 2) . '/.env.test'));
    ensureWizardReferenceFixture($pdo);
    $publication = $pdo->query('SELECT active_publication_id FROM reference_state WHERE singleton_id=1')->fetchColumn();
    if ($publication === false || $publication === null) {
        throw new RuntimeException('Insight integration requires an active reference publication.');
    }
    $placeholders = implode(', ', array_fill(0, count($emails), '?'));
    $lookupUsers = $pdo->prepare("SELECT id FROM app_user WHERE email IN ($placeholders)");
    $lookupUsers->execute($emails);
    $oldIds = $lookupUsers->fetchAll(PDO::FETCH_COLUMN);
    if ($oldIds !== []) {
        $deleteInsights = $pdo->prepare('DELETE FROM insight WHERE owner_user_id IN (' . implode(', ', array_fill(0, count($oldIds), '?')) . ')');
        $deleteInsights->execute($oldIds);
    }
    $cleanup = $pdo->prepare("DELETE FROM app_user WHERE email IN ($placeholders)");
    $cleanup->execute($emails);
    $insertUser = $pdo->prepare(
        "INSERT INTO app_user (google_sub, email, display_name, role, is_active, created_at, updated_at)
         VALUES (?, ?, ?, 'user', 1, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))"
    );
    $insertUser->execute(['insight-owner-sub', $emails[0], 'Insight Owner']);
    $ownerId = (int) $pdo->lastInsertId();
    $insertUser->execute(['insight-other-sub', $emails[1], 'Other User']);
    $otherId = (int) $pdo->lastInsertId();

    $store = new InsightStore(static fn (): PDO => $pdo, 'https://politiks.example.test');
    $wizard = new WizardStore(static fn (): PDO => $pdo);
    $draft = $store->createDraft($ownerId);
    if ($draft['visibility'] !== 'draft' || $store->findVisible($draft['public_id'], $otherId) !== null) {
        throw new RuntimeException('Draft visibility isolation failed.');
    }
    $shared = $store->update($ownerId, $draft['public_id'], [
        'title' => 'Integration Insight',
        'claim_text' => 'Eine ausreichend vollständige Aussage.',
        'visibility' => 'unlisted',
    ]);
    if (!isset($shared['share_url'])) {
        throw new RuntimeException('Unlisted update did not issue a share URL.');
    }
    $token = basename($shared['share_url']);
    if ($store->findShared($token) === null || $store->publicPage(1, 24)['pagination']['total'] < 0) {
        throw new RuntimeException('Unlisted share lookup failed.');
    }
    $wizard->saveScope($ownerId, $draft['public_id'], [
        'country_id' => 910001,
        'legislature_id' => 910002,
        'chamber_id' => 910003,
        'party_id' => 910004,
        'period_from' => '2025-01-01',
        'period_to' => '2025-12-31',
    ]);
    $wizard->saveMembers($ownerId, $draft['public_id'], [910101]);
    $wizard->saveEvidence($ownerId, $draft['public_id'], [910301]);
    $store->update($ownerId, $draft['public_id'], ['visibility' => 'public']);
    $visible = $store->findVisible($draft['public_id'], null);
    if ($visible === null || $visible['visibility'] !== 'public') {
        throw new RuntimeException('Public visibility transition failed.');
    }
    $denied = false;
    try {
        $store->update($otherId, $draft['public_id'], ['title' => 'Tampered']);
    } catch (InsightException $error) {
        $denied = $error->status === 404;
    }
    if (!$denied) {
        throw new RuntimeException('Ownership tampering was not rejected.');
    }
    if ($store->ownerPage($ownerId, 1, 24)['pagination']['total'] !== 1) {
        throw new RuntimeException('Owner catalogue did not include all states.');
    }
    $store->archive($ownerId, $draft['public_id']);
    if ($store->findVisible($draft['public_id'], $ownerId) !== null) {
        throw new RuntimeException('Archived insight remained retrievable.');
    }

    echo json_encode([
        'insight_lifecycle_valid' => true,
        'ownership_isolated' => true,
        'unlisted_token_valid' => true,
        'archive_removes_visibility' => true,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Insight integration failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
} finally {
    if (isset($pdo, $cleanup)) {
        try {
            $lookupUsers->execute($emails);
            $ids = $lookupUsers->fetchAll(PDO::FETCH_COLUMN);
            if ($ids !== []) {
                $deleteInsights = $pdo->prepare('DELETE FROM insight WHERE owner_user_id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')');
                $deleteInsights->execute($ids);
            }
            $cleanup->execute($emails);
        } catch (Throwable) {
        }
    }
}
