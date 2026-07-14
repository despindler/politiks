<?php

declare(strict_types=1);

use Politiks\App\Insight\CampaignContextStore;
use Politiks\App\Insight\InsightException;
use Politiks\App\Insight\InsightStore;
use Politiks\Tooling\Environment;
use Politiks\Tooling\MariaDb;

require __DIR__ . '/../../scripts/lib/Environment.php';
require __DIR__ . '/../../scripts/lib/MariaDb.php';
require __DIR__ . '/../../site/backend/bootstrap.php';
require __DIR__ . '/TestReferenceFixture.php';

$emails = ['context.owner@example.test', 'context.other@example.test'];
$storage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'politiks-context-' . bin2hex(random_bytes(8));

function removeContextTestDirectory(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($path);
}

try {
    $pdo = MariaDb::connect(Environment::load(dirname(__DIR__, 2) . '/.env.test'));
    ensureWizardReferenceFixture($pdo);
    $placeholders = implode(', ', array_fill(0, count($emails), '?'));
    $lookup = $pdo->prepare("SELECT id FROM app_user WHERE email IN ($placeholders)");
    $lookup->execute($emails);
    $oldIds = $lookup->fetchAll(PDO::FETCH_COLUMN);
    if ($oldIds !== []) {
        $pdo->prepare('DELETE FROM insight WHERE owner_user_id IN (' . implode(', ', array_fill(0, count($oldIds), '?')) . ')')->execute($oldIds);
    }
    $pdo->prepare("DELETE FROM app_user WHERE email IN ($placeholders)")->execute($emails);
    $insertUser = $pdo->prepare(
        "INSERT INTO app_user (google_sub, email, display_name, role, is_active, created_at, updated_at)
         VALUES (?, ?, ?, 'user', 1, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))"
    );
    $insertUser->execute(['context-owner-sub', $emails[0], 'Context Owner']);
    $ownerId = (int) $pdo->lastInsertId();
    $insertUser->execute(['context-other-sub', $emails[1], 'Context Other']);
    $otherId = (int) $pdo->lastInsertId();

    mkdir($storage, 0700, true);
    $store = new CampaignContextStore(
        static fn (): PDO => $pdo,
        $storage,
        2_097_152,
        static fn (string $path): bool => is_file($path),
        static fn (string $from, string $to): bool => rename($from, $to),
    );
    $insights = new InsightStore(static fn (): PDO => $pdo, 'https://politiks.example.test');
    $draft = $insights->createDraft($ownerId);

    $youtube = $store->createRemote($ownerId, $draft['public_id'], [
        'context_type' => 'youtube',
        'label' => '<img src=x onerror=alert(1)>',
        'attribution' => 'Beispielkanal',
        'description' => '<script>alert(1)</script>',
        'source_url' => 'https://youtu.be/dQw4w9WgXcQ?t=7',
    ]);
    if ($youtube['source_url'] !== 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'
        || $youtube['label'] !== '<img src=x onerror=alert(1)>') {
        throw new RuntimeException('YouTube normalization or literal text storage failed.');
    }
    $link = $store->createRemote($ownerId, $draft['public_id'], [
        'context_type' => 'link', 'label' => 'Wahlprogramm', 'source_url' => 'https://example.org/programm',
    ]);

    $validPng = tempnam(sys_get_temp_dir(), 'politiks-png-');
    file_put_contents($validPng, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true));
    $image = $store->uploadImage($ownerId, $draft['public_id'], [
        'error' => UPLOAD_ERR_OK, 'tmp_name' => $validPng, 'name' => 'poster.php.png',
    ], ['label' => 'Wahlplakat', 'attribution' => 'Beispielpartei', 'source_url' => 'https://example.org/plakat']);
    $storedImage = $store->imageForViewer($image['id'], $ownerId, null);
    if (!is_file($storedImage['path']) || str_ends_with($storedImage['path'], 'poster.php.png')) {
        throw new RuntimeException('Generated image storage name failed.');
    }

    foreach ([
        ['name' => 'script.png', 'body' => '<?php echo "owned"; ?>'],
        ['name' => 'vector.svg', 'body' => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
    ] as $invalid) {
        $path = tempnam(sys_get_temp_dir(), 'politiks-invalid-');
        file_put_contents($path, $invalid['body']);
        $rejected = false;
        try {
            $store->uploadImage($ownerId, $draft['public_id'], ['error' => UPLOAD_ERR_OK, 'tmp_name' => $path, 'name' => $invalid['name']], []);
        } catch (InsightException $error) {
            $rejected = in_array($error->errorCode, ['UPLOAD_TOO_LARGE', 'UNSUPPORTED_IMAGE'], true);
        } finally {
            if (is_file($path)) unlink($path);
        }
        if (!$rejected) throw new RuntimeException('Executable or SVG upload was not rejected.');
    }
    $oversize = tempnam(sys_get_temp_dir(), 'politiks-large-');
    file_put_contents($oversize, str_repeat('x', 2_097_153));
    try {
        $store->uploadImage($ownerId, $draft['public_id'], ['error' => UPLOAD_ERR_OK, 'tmp_name' => $oversize, 'name' => 'large.png'], []);
        throw new RuntimeException('Oversized upload was accepted.');
    } catch (InsightException $error) {
        if ($error->errorCode !== 'UPLOAD_TOO_LARGE') throw $error;
    } finally {
        if (is_file($oversize)) unlink($oversize);
    }

    $crossOwnerDenied = 0;
    foreach ([
        static fn () => $store->update($otherId, $draft['public_id'], $link['id'], ['label' => 'Manipuliert', 'source_url' => 'https://example.org']),
        static fn () => $store->reorder($otherId, $draft['public_id'], [$image['id'], $link['id'], $youtube['id']]),
        static fn () => $store->delete($otherId, $draft['public_id'], $image['id']),
    ] as $attempt) {
        try { $attempt(); } catch (InsightException $error) { if ($error->status === 404) $crossOwnerDenied++; }
    }
    if ($crossOwnerDenied !== 3) throw new RuntimeException('Cross-owner mutation was not fully denied.');

    $ordered = $store->reorder($ownerId, $draft['public_id'], [$image['id'], $link['id'], $youtube['id']]);
    if (array_column($ordered, 'id') !== [$image['id'], $link['id'], $youtube['id']]) {
        throw new RuntimeException('Context reordering failed.');
    }
    $shared = $insights->update($ownerId, $draft['public_id'], ['visibility' => 'unlisted']);
    $token = basename($shared['share_url']);
    $store->imageForViewer($image['id'], null, $token);
    try {
        $store->imageForViewer($image['id'], null, null);
        throw new RuntimeException('Unlisted image leaked without its share token.');
    } catch (InsightException $error) {
        if ($error->status !== 404) throw $error;
    }
    $sharedInsight = $insights->findShared($token);
    if (($sharedInsight['campaign_context_count'] ?? 0) !== 3 || count($sharedInsight['campaign_contexts'] ?? []) !== 3
        || !str_contains($sharedInsight['campaign_contexts'][0]['media_url'], '?share=')) {
        throw new RuntimeException('Shared context presentation failed.');
    }

    $imagePath = $storedImage['path'];
    $store->delete($ownerId, $draft['public_id'], $image['id']);
    if (is_file($imagePath)) throw new RuntimeException('Deleted image remained in protected storage.');

    echo json_encode([
        'context_types_valid' => true,
        'image_validation_valid' => true,
        'ownership_isolated' => true,
        'authorized_streaming_valid' => true,
        'ordering_and_deletion_valid' => true,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Campaign-context integration failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
} finally {
    if (isset($pdo)) {
        try {
            $lookup->execute($emails);
            $ids = $lookup->fetchAll(PDO::FETCH_COLUMN);
            if ($ids !== []) {
                $pdo->prepare('DELETE FROM insight WHERE owner_user_id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')')->execute($ids);
            }
            $pdo->prepare("DELETE FROM app_user WHERE email IN ($placeholders)")->execute($emails);
        } catch (Throwable) {}
    }
    removeContextTestDirectory($storage);
}
