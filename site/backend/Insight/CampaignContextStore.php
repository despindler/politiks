<?php

declare(strict_types=1);

namespace Politiks\App\Insight;

use Closure;
use PDO;
use Throwable;

final class CampaignContextStore
{
    private ?PDO $connection = null;

    /** @param Closure():PDO $connectionFactory */
    public function __construct(
        private readonly Closure $connectionFactory,
        private readonly string $storagePath,
        private readonly int $maxUploadBytes,
        private readonly ?Closure $uploadVerifier = null,
        private readonly ?Closure $uploadMover = null,
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function ownerContexts(int $ownerId, string $publicId): array
    {
        $insight = $this->ownerInsight($ownerId, $publicId, false);
        return $this->contexts((int) $insight['id']);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function createRemote(int $ownerId, string $publicId, array $input): array
    {
        $type = $input['context_type'] ?? null;
        if (!is_string($type) || !in_array($type, ['youtube', 'link'], true)) {
            throw new InsightException('INVALID_CONTEXT_TYPE', 'Wähle YouTube oder einen externen Link.');
        }
        $metadata = $this->metadata($input);
        $sourceUrl = $this->safeUrl($input['source_url'] ?? null);
        $videoId = null;
        if ($type === 'youtube') {
            $videoId = self::youtubeVideoId($sourceUrl);
            if ($videoId === null) {
                throw new InsightException(
                    'INVALID_YOUTUBE_URL',
                    'Verwende einen YouTube-Link im Format youtube.com/watch?v=… oder youtu.be/….',
                );
            }
            $sourceUrl = 'https://www.youtube.com/watch?v=' . $videoId;
        }

        $connection = $this->connection();
        $connection->beginTransaction();
        try {
            $insight = $this->ownerInsight($ownerId, $publicId, true);
            $position = $this->nextPosition((int) $insight['id']);
            $insert = $connection->prepare(
                'INSERT INTO insight_campaign_context
                 (insight_id, context_type, position, label, attribution, description, source_url,
                  youtube_video_id, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))'
            );
            $insert->execute([
                $insight['id'], $type, $position, $metadata['label'], $metadata['attribution'],
                $metadata['description'], $sourceUrl, $videoId,
            ]);
            $id = (int) $connection->lastInsertId();
            $connection->commit();
        } catch (Throwable $error) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $error;
        }
        return $this->context($id);
    }

    /** @param array<string,mixed> $file @param array<string,mixed> $input @return array<string,mixed> */
    public function uploadImage(int $ownerId, string $publicId, array $file, array $input): array
    {
        $metadata = $this->metadata($input);
        $upload = $this->validateImage($file);
        $relative = sprintf('uploads/campaign/%s/%s.%s', substr($upload['sha256'], 0, 2), bin2hex(random_bytes(24)), $upload['extension']);
        $absolute = $this->storagePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $directory = dirname($absolute);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new InsightException('UPLOAD_STORAGE_FAILED', 'Das Bild konnte nicht sicher gespeichert werden.', 500);
        }
        $mover = $this->uploadMover ?? static fn (string $from, string $to): bool => move_uploaded_file($from, $to);
        if (!$mover($upload['tmp_name'], $absolute)) {
            throw new InsightException('UPLOAD_STORAGE_FAILED', 'Das Bild konnte nicht sicher gespeichert werden.', 500);
        }
        @chmod($absolute, 0600);

        $connection = $this->connection();
        try {
            $connection->beginTransaction();
            $insight = $this->ownerInsight($ownerId, $publicId, true);
            $position = $this->nextPosition((int) $insight['id']);
            $insert = $connection->prepare(
                'INSERT INTO insight_campaign_context
                 (insight_id, context_type, position, label, attribution, description, source_url,
                  storage_key, original_filename, media_type, byte_count, sha256, created_at, updated_at)
                 VALUES (?, \'image\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))'
            );
            $insert->execute([
                $insight['id'], $position, $metadata['label'], $metadata['attribution'], $metadata['description'],
                $metadata['source_url'], $relative, $upload['original_filename'], $upload['media_type'],
                $upload['byte_count'], $upload['sha256'],
            ]);
            $id = (int) $connection->lastInsertId();
            $connection->commit();
        } catch (Throwable $error) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            @unlink($absolute);
            throw $error;
        }
        return $this->context($id);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function update(int $ownerId, string $publicId, int $contextId, array $input): array
    {
        $metadata = $this->metadata($input);
        $connection = $this->connection();
        $connection->beginTransaction();
        try {
            $insight = $this->ownerInsight($ownerId, $publicId, true);
            $current = $this->ownedContext((int) $insight['id'], $contextId, true);
            $sourceUrl = $current['source_url'];
            $videoId = $current['youtube_video_id'];
            if ($current['context_type'] === 'image') {
                $sourceUrl = $metadata['source_url'];
            } else {
                $sourceUrl = $this->safeUrl($input['source_url'] ?? null);
                if ($current['context_type'] === 'youtube') {
                    $videoId = self::youtubeVideoId($sourceUrl);
                    if ($videoId === null) {
                        throw new InsightException('INVALID_YOUTUBE_URL', 'Der YouTube-Link wird nicht unterstützt.');
                    }
                    $sourceUrl = 'https://www.youtube.com/watch?v=' . $videoId;
                }
            }
            $statement = $connection->prepare(
                'UPDATE insight_campaign_context SET label=?, attribution=?, description=?, source_url=?,
                 youtube_video_id=?, updated_at=UTC_TIMESTAMP(6) WHERE id=? AND insight_id=?'
            );
            $statement->execute([
                $metadata['label'], $metadata['attribution'], $metadata['description'], $sourceUrl,
                $videoId, $contextId, $insight['id'],
            ]);
            $connection->commit();
        } catch (Throwable $error) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $error;
        }
        return $this->context($contextId);
    }

    /** @param mixed $ids @return list<array<string,mixed>> */
    public function reorder(int $ownerId, string $publicId, mixed $ids): array
    {
        if (!is_array($ids) || $ids === [] || count($ids) > 100) {
            throw new InsightException('INVALID_CONTEXT_ORDER', 'Die Reihenfolge ist ungültig.');
        }
        $normalized = [];
        foreach ($ids as $id) {
            if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
                throw new InsightException('INVALID_CONTEXT_ORDER', 'Die Reihenfolge ist ungültig.');
            }
            $normalized[] = (int) $id;
        }
        if (count(array_unique($normalized)) !== count($normalized)) {
            throw new InsightException('INVALID_CONTEXT_ORDER', 'Die Reihenfolge enthält Duplikate.');
        }

        $connection = $this->connection();
        $connection->beginTransaction();
        try {
            $insight = $this->ownerInsight($ownerId, $publicId, true);
            $existing = $connection->prepare('SELECT id FROM insight_campaign_context WHERE insight_id=? ORDER BY position FOR UPDATE');
            $existing->execute([$insight['id']]);
            $existingIds = array_map('intval', $existing->fetchAll(PDO::FETCH_COLUMN));
            if ($existingIds !== $normalized) {
                $sortedA = $existingIds;
                $sortedB = $normalized;
                sort($sortedA);
                sort($sortedB);
                if ($sortedA !== $sortedB) {
                    throw new InsightException('INVALID_CONTEXT_ORDER', 'Die Reihenfolge muss alle eigenen Elemente genau einmal enthalten.');
                }
            }
            $update = $connection->prepare('UPDATE insight_campaign_context SET position=?, updated_at=UTC_TIMESTAMP(6) WHERE id=? AND insight_id=?');
            foreach ($normalized as $position => $id) {
                $update->execute([1_000_000 + $position, $id, $insight['id']]);
            }
            foreach ($normalized as $position => $id) {
                $update->execute([$position, $id, $insight['id']]);
            }
            $connection->commit();
        } catch (Throwable $error) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $error;
        }
        return $this->contexts((int) $insight['id']);
    }

    public function delete(int $ownerId, string $publicId, int $contextId): void
    {
        $connection = $this->connection();
        $connection->beginTransaction();
        try {
            $insight = $this->ownerInsight($ownerId, $publicId, true);
            $context = $this->ownedContext((int) $insight['id'], $contextId, true);
            $delete = $connection->prepare('DELETE FROM insight_campaign_context WHERE id=? AND insight_id=?');
            $delete->execute([$contextId, $insight['id']]);
            $remaining = $connection->prepare('SELECT id FROM insight_campaign_context WHERE insight_id=? ORDER BY position FOR UPDATE');
            $remaining->execute([$insight['id']]);
            $update = $connection->prepare('UPDATE insight_campaign_context SET position=? WHERE id=?');
            foreach ($remaining->fetchAll(PDO::FETCH_COLUMN) as $position => $id) {
                $update->execute([$position, $id]);
            }
            $connection->commit();
        } catch (Throwable $error) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $error;
        }
        if (is_string($context['storage_key']) && $context['storage_key'] !== '') {
            $path = $this->storagePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $context['storage_key']);
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    /** @return array{path:string,media_type:string,byte_count:int,sha256:string} */
    public function imageForViewer(int $contextId, ?int $viewerId, ?string $shareToken): array
    {
        $statement = $this->connection()->prepare(
            "SELECT context.storage_key, context.media_type, context.byte_count, context.sha256,
                    insight.owner_user_id, insight.visibility, insight.share_token_hash, insight.archived_at
             FROM insight_campaign_context context
             JOIN insight ON insight.id=context.insight_id
             WHERE context.id=? AND context.context_type='image'"
        );
        $statement->execute([$contextId]);
        $row = $statement->fetch();
        if ($row === false) {
            throw new InsightException('CONTEXT_IMAGE_NOT_FOUND', 'Das Bild wurde nicht gefunden.', 404);
        }
        $shared = is_string($shareToken) && strlen($shareToken) === 43
            && is_string($row['share_token_hash'])
            && hash_equals($row['share_token_hash'], hash('sha256', $shareToken));
        $allowed = $row['archived_at'] === null && (
            $row['visibility'] === 'public'
            || ($viewerId !== null && (int) $row['owner_user_id'] === $viewerId)
            || ($row['visibility'] === 'unlisted' && $shared)
        );
        if (!$allowed || !is_string($row['storage_key']) || !is_string($row['media_type'])) {
            throw new InsightException('CONTEXT_IMAGE_NOT_FOUND', 'Das Bild wurde nicht gefunden.', 404);
        }
        $path = $this->storagePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $row['storage_key']);
        $realPath = realpath($path);
        $storageRoot = realpath($this->storagePath);
        if (!is_file($path) || $realPath === false || $storageRoot === false
            || !str_starts_with($realPath, rtrim($storageRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
            throw new InsightException('CONTEXT_IMAGE_NOT_FOUND', 'Das Bild wurde nicht gefunden.', 404);
        }
        return ['path' => $realPath, 'media_type' => $row['media_type'], 'byte_count' => (int) $row['byte_count'], 'sha256' => $row['sha256']];
    }

    public static function youtubeVideoId(string $url): ?string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || strtolower($parts['scheme']) !== 'https'
            || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $host = strtolower(rtrim($parts['host'], '.'));
        $candidate = null;
        if ($host === 'youtu.be' && isset($parts['path'])) {
            $candidate = trim($parts['path'], '/');
        } elseif (in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com'], true) && ($parts['path'] ?? '') === '/watch') {
            parse_str($parts['query'] ?? '', $query);
            $candidate = $query['v'] ?? null;
        }
        return is_string($candidate) && preg_match('/^[A-Za-z0-9_-]{11}$/D', $candidate) === 1 ? $candidate : null;
    }

    /** @param array<string,mixed> $input @return array{label:?string,attribution:?string,description:?string,source_url:?string} */
    private function metadata(array $input): array
    {
        $allowed = ['context_type', 'label', 'attribution', 'description', 'source_url'];
        foreach (array_keys($input) as $key) {
            if (!in_array($key, $allowed, true)) {
                throw new InsightException('UNKNOWN_FIELD', 'Die Anfrage enthält ein unbekanntes Feld.');
            }
        }
        return [
            'label' => $this->text($input['label'] ?? null, 'Bezeichnung', 255),
            'attribution' => $this->text($input['attribution'] ?? null, 'Urheberangabe', 255),
            'description' => $this->text($input['description'] ?? null, 'Beschreibung', 5_000),
            'source_url' => $this->optionalUrl($input['source_url'] ?? null),
        ];
    }

    private function optionalUrl(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return $this->safeUrl($value);
    }

    private function text(mixed $value, string $label, int $maxLength): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new InsightException('INVALID_CONTEXT_TEXT', sprintf('%s muss Text sein.', $label));
        }
        $value = trim($value);
        if (strlen($value) > $maxLength) {
            throw new InsightException('CONTEXT_TEXT_TOO_LONG', sprintf('%s ist zu lang.', $label));
        }
        return $value === '' ? null : $value;
    }

    private function safeUrl(mixed $value): string
    {
        if (!is_string($value)) {
            throw new InsightException('INVALID_CONTEXT_URL', 'Eine vollständige Webadresse ist erforderlich.');
        }
        $value = trim($value);
        $parts = parse_url($value);
        if (strlen($value) > 2_048 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1 || filter_var($value, FILTER_VALIDATE_URL) === false
            || !is_array($parts) || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass'])) {
            throw new InsightException('INVALID_CONTEXT_URL', 'Verwende eine vollständige HTTP- oder HTTPS-Webadresse ohne Zugangsdaten.');
        }
        return $value;
    }

    /** @param array<string,mixed> $file @return array{tmp_name:string,original_filename:string,media_type:string,byte_count:int,sha256:string,extension:string} */
    private function validateImage(array $file): array
    {
        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if (!is_int($error) || $error !== UPLOAD_ERR_OK) {
            $code = in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'UPLOAD_TOO_LARGE' : 'INVALID_IMAGE_UPLOAD';
            $status = $code === 'UPLOAD_TOO_LARGE' ? 413 : 422;
            throw new InsightException($code, $code === 'UPLOAD_TOO_LARGE' ? 'Das Bild ist zu gross.' : 'Das Bild konnte nicht gelesen werden.', $status);
        }
        $tmp = $file['tmp_name'] ?? null;
        $name = $file['name'] ?? null;
        if (!is_string($tmp) || !is_string($name) || $tmp === '' || strlen($name) > 255) {
            throw new InsightException('INVALID_IMAGE_UPLOAD', 'Die Bilddatei ist ungültig.');
        }
        $verifier = $this->uploadVerifier ?? static fn (string $path): bool => is_uploaded_file($path);
        if (!$verifier($tmp) || !is_file($tmp)) {
            throw new InsightException('INVALID_IMAGE_UPLOAD', 'Die Bilddatei ist ungültig.');
        }
        $size = filesize($tmp);
        if ($size === false || $size < 32 || $size > $this->maxUploadBytes) {
            throw new InsightException('UPLOAD_TOO_LARGE', 'Das Bild überschreitet die erlaubte Dateigrösse.', 413);
        }
        $image = @getimagesize($tmp);
        $formats = [
            IMAGETYPE_JPEG => ['image/jpeg', 'jpg'],
            IMAGETYPE_PNG => ['image/png', 'png'],
            IMAGETYPE_WEBP => ['image/webp', 'webp'],
        ];
        $type = is_array($image) ? ($image[2] ?? null) : null;
        $mime = is_array($image) ? ($image['mime'] ?? null) : null;
        if (!is_int($type) || !isset($formats[$type]) || $mime !== $formats[$type][0]
            || ($image[0] ?? 0) < 1 || ($image[1] ?? 0) < 1 || ($image[0] ?? 0) > 12_000 || ($image[1] ?? 0) > 12_000
            || (int) $image[0] * (int) $image[1] > 40_000_000 || !$this->validImageStructure($tmp, $type, (int) $size)) {
            throw new InsightException('UNSUPPORTED_IMAGE', 'Erlaubt sind geprüfte JPEG-, PNG- oder WebP-Bilder.', 422);
        }
        $sha = hash_file('sha256', $tmp);
        if (!is_string($sha)) {
            throw new InsightException('INVALID_IMAGE_UPLOAD', 'Das Bild konnte nicht geprüft werden.');
        }
        return [
            'tmp_name' => $tmp,
            'original_filename' => basename(str_replace('\\', '/', $name)),
            'media_type' => $formats[$type][0],
            'byte_count' => (int) $size,
            'sha256' => $sha,
            'extension' => $formats[$type][1],
        ];
    }

    private function validImageStructure(string $path, int $type, int $size): bool
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        try {
            $start = fread($handle, 12);
            if (!is_string($start)) {
                return false;
            }
            if ($type === IMAGETYPE_PNG) {
                if ($start !== "\x89PNG\r\n\x1a\n\x00\x00\x00\r") {
                    return false;
                }
                if (fseek($handle, -12, SEEK_END) !== 0) {
                    return false;
                }
                return fread($handle, 12) === "\x00\x00\x00\x00IEND\xaeB\x60\x82";
            }
            if ($type === IMAGETYPE_JPEG) {
                if (!str_starts_with($start, "\xff\xd8\xff") || fseek($handle, -2, SEEK_END) !== 0) {
                    return false;
                }
                return fread($handle, 2) === "\xff\xd9";
            }
            if ($type === IMAGETYPE_WEBP) {
                if (substr($start, 0, 4) !== 'RIFF' || substr($start, 8, 4) !== 'WEBP') {
                    return false;
                }
                $declared = unpack('Vsize', substr($start, 4, 4));
                return is_array($declared) && (int) $declared['size'] + 8 === $size;
            }
            return false;
        } finally {
            fclose($handle);
        }
    }

    /** @return array<string,mixed> */
    private function ownerInsight(int $ownerId, string $publicId, bool $forUpdate): array
    {
        $statement = $this->connection()->prepare(
            'SELECT id FROM insight WHERE public_id=? AND owner_user_id=? AND archived_at IS NULL' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute([$publicId, $ownerId]);
        $row = $statement->fetch();
        if ($row === false) {
            throw new InsightException('INSIGHT_NOT_FOUND', 'Der Insight wurde nicht gefunden.', 404);
        }
        return $row;
    }

    /** @return array<string,mixed> */
    private function ownedContext(int $insightId, int $contextId, bool $forUpdate): array
    {
        $statement = $this->connection()->prepare(
            'SELECT * FROM insight_campaign_context WHERE id=? AND insight_id=?' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute([$contextId, $insightId]);
        $row = $statement->fetch();
        if ($row === false) {
            throw new InsightException('CONTEXT_NOT_FOUND', 'Das Kontextmaterial wurde nicht gefunden.', 404);
        }
        return $row;
    }

    private function nextPosition(int $insightId): int
    {
        $statement = $this->connection()->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM insight_campaign_context WHERE insight_id=?');
        $statement->execute([$insightId]);
        return (int) $statement->fetchColumn();
    }

    /** @return list<array<string,mixed>> */
    private function contexts(int $insightId): array
    {
        $statement = $this->connection()->prepare('SELECT * FROM insight_campaign_context WHERE insight_id=? ORDER BY position, id');
        $statement->execute([$insightId]);
        return array_map($this->serialize(...), $statement->fetchAll());
    }

    /** @return array<string,mixed> */
    private function context(int $id): array
    {
        $statement = $this->connection()->prepare('SELECT * FROM insight_campaign_context WHERE id=?');
        $statement->execute([$id]);
        $row = $statement->fetch();
        if ($row === false) {
            throw new InsightException('CONTEXT_NOT_FOUND', 'Das Kontextmaterial wurde nicht gefunden.', 404);
        }
        return $this->serialize($row);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function serialize(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'context_type' => $row['context_type'],
            'position' => (int) $row['position'],
            'label' => $row['label'],
            'attribution' => $row['attribution'],
            'description' => $row['description'],
            'source_url' => $row['source_url'],
            'youtube_video_id' => $row['youtube_video_id'],
            'media_url' => $row['context_type'] === 'image' ? '/media/campaign-context/' . $row['id'] : null,
            'original_filename' => $row['original_filename'],
            'media_type' => $row['media_type'],
            'byte_count' => $row['byte_count'] === null ? null : (int) $row['byte_count'],
        ];
    }

    private function connection(): PDO
    {
        return $this->connection ??= ($this->connectionFactory)();
    }
}
