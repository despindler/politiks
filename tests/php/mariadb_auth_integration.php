<?php

declare(strict_types=1);

use Politiks\App\Auth\GoogleAuthException;
use Politiks\App\Auth\MariaDbUserStore;
use Politiks\Tooling\Environment;
use Politiks\Tooling\MariaDb;

require __DIR__ . '/../../scripts/lib/Environment.php';
require __DIR__ . '/../../scripts/lib/MariaDb.php';
require __DIR__ . '/../../site/backend/bootstrap.php';

$root = dirname(__DIR__, 2);
$envPath = $root . DIRECTORY_SEPARATOR . '.env.test';
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--env=')) {
        $candidate = substr($argument, 6);
        $envPath = preg_match('~^(?:[A-Za-z]:[\\/]|[\\/])~', $candidate) === 1
            ? $candidate
            : $root . DIRECTORY_SEPARATOR . $candidate;
    } else {
        fwrite(STDERR, sprintf("Unknown argument: %s\n", $argument));
        exit(2);
    }
}

$emails = ['auth.create@example.test', 'auth.link@example.test', 'auth.disabled@example.test'];

try {
    if (basename($envPath) !== '.env.test') {
        throw new RuntimeException('Authentication integration tests require .env.test.');
    }
    $pdo = MariaDb::connect(Environment::load($envPath));
    $placeholders = implode(', ', array_fill(0, count($emails), '?'));
    $cleanup = $pdo->prepare(sprintf('DELETE FROM app_user WHERE email IN (%s)', $placeholders));
    $cleanup->execute($emails);

    $store = new MariaDbUserStore(static fn (): PDO => $pdo);
    $identity = [
        'sub' => 'auth-create-subject',
        'email' => $emails[0],
        'email_verified' => true,
        'name' => 'Create User',
        'picture' => null,
    ];
    $created = $store->loginOrCreate($identity);
    $reused = $store->loginOrCreate($identity);
    if ($created['id'] !== $reused['id']) {
        throw new RuntimeException('Repeated Google login created a duplicate user.');
    }

    $insert = $pdo->prepare(
        "INSERT INTO app_user
         (google_sub, email, display_name, avatar_url, role, is_active, created_at, updated_at)
         VALUES (NULL, ?, 'Existing User', NULL, 'user', 1, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))"
    );
    $insert->execute([$emails[1]]);
    $existingId = (int) $pdo->lastInsertId();
    $linked = $store->loginOrCreate([
        'sub' => 'auth-linked-subject',
        'email' => $emails[1],
        'email_verified' => true,
        'name' => 'Linked User',
        'picture' => null,
    ]);
    if ($linked['id'] !== $existingId) {
        throw new RuntimeException('Verified-email linking created a duplicate user.');
    }
    $linkedSub = $pdo->prepare('SELECT google_sub FROM app_user WHERE id=?');
    $linkedSub->execute([$existingId]);
    if ($linkedSub->fetchColumn() !== 'auth-linked-subject') {
        throw new RuntimeException('Verified-email linking did not persist the Google subject.');
    }

    $insertDisabled = $pdo->prepare(
        "INSERT INTO app_user
         (google_sub, email, display_name, avatar_url, role, is_active, created_at, updated_at)
         VALUES (?, ?, 'Disabled User', NULL, 'user', 0, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))"
    );
    $insertDisabled->execute(['auth-disabled-subject', $emails[2]]);
    $disabledRejected = false;
    try {
        $store->loginOrCreate([
            'sub' => 'auth-disabled-subject',
            'email' => $emails[2],
            'email_verified' => true,
            'name' => 'Disabled User',
            'picture' => null,
        ]);
    } catch (GoogleAuthException $error) {
        $disabledRejected = $error->errorCode === 'ACCOUNT_DISABLED';
    }
    if (!$disabledRejected) {
        throw new RuntimeException('A disabled account was not rejected safely.');
    }

    $count = $pdo->prepare(sprintf('SELECT COUNT(*) FROM app_user WHERE email IN (%s)', $placeholders));
    $count->execute($emails);
    $rowCount = (int) $count->fetchColumn();
    if ($rowCount !== 3) {
        throw new RuntimeException(sprintf('Expected three integration users, found %d.', $rowCount));
    }

    echo json_encode([
        'authentication_storage_valid' => true,
        'created_and_reused_same_user' => true,
        'verified_email_linked_existing_user' => true,
        'disabled_user_rejected' => true,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
    $cleanup->execute($emails);
    exit(0);
} catch (Throwable $error) {
    if (isset($cleanup)) {
        try {
            $cleanup->execute($emails);
        } catch (Throwable) {
        }
    }
    fwrite(STDERR, 'Authentication integration failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
