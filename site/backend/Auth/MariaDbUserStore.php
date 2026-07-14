<?php

declare(strict_types=1);

namespace Politiks\App\Auth;

use Closure;
use PDO;
use PDOException;
use Throwable;

final class MariaDbUserStore implements UserStore
{
    private ?PDO $connection = null;

    /** @param Closure():PDO $connectionFactory */
    public function __construct(private readonly Closure $connectionFactory)
    {
    }

    public function loginOrCreate(array $identity): array
    {
        $pdo = $this->connection();
        $pdo->beginTransaction();
        try {
            $user = $this->findByGoogleSub($identity['sub'], true);
            if ($user === null) {
                $user = $this->findByEmail($identity['email'], true);
                if ($user !== null && $user['google_sub'] !== null && !hash_equals((string) $user['google_sub'], $identity['sub'])) {
                    throw new GoogleAuthException(
                        'GOOGLE_ACCOUNT_LINK_CONFLICT',
                        'Dieses Konto ist bereits mit einer anderen Google-Identität verknüpft.',
                        409,
                    );
                }
            }

            if ($user === null) {
                $insert = $pdo->prepare(
                    "INSERT INTO app_user
                     (google_sub, email, display_name, avatar_url, role, is_active, created_at, updated_at, last_login_at)
                     VALUES (?, ?, ?, ?, 'user', 1, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))"
                );
                $insert->execute([
                    $identity['sub'],
                    $identity['email'],
                    $identity['name'],
                    $identity['picture'],
                ]);
                $user = $this->findById((int) $pdo->lastInsertId(), true);
            } else {
                if ((int) $user['is_active'] !== 1) {
                    throw new GoogleAuthException('ACCOUNT_DISABLED', 'Dieses Benutzerkonto ist deaktiviert.', 403);
                }
                $update = $pdo->prepare(
                    "UPDATE app_user
                     SET google_sub=?, email=?, display_name=?, avatar_url=?, updated_at=UTC_TIMESTAMP(6), last_login_at=UTC_TIMESTAMP(6)
                     WHERE id=?"
                );
                $update->execute([
                    $identity['sub'],
                    $identity['email'],
                    $identity['name'],
                    $identity['picture'],
                    $user['id'],
                ]);
                $user = $this->findById((int) $user['id'], true);
            }
            if ($user === null) {
                throw new GoogleAuthException('AUTH_STORAGE_FAILED', 'Die Anmeldung konnte nicht gespeichert werden.', 500);
            }
            $pdo->commit();
            return $this->publicUser($user);
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($error instanceof GoogleAuthException) {
                throw $error;
            }
            if ($error instanceof PDOException && in_array((string) $error->getCode(), ['23000', '23505'], true)) {
                throw new GoogleAuthException(
                    'AUTH_STORAGE_CONFLICT',
                    'Die Anmeldung wurde gleichzeitig verarbeitet. Bitte versuchen Sie es erneut.',
                    409,
                );
            }
            throw new GoogleAuthException('AUTH_STORAGE_FAILED', 'Die Anmeldung konnte nicht gespeichert werden.', 500);
        }
    }

    public function findActiveById(int $id): ?array
    {
        $user = $this->findById($id, false);
        if ($user === null || (int) $user['is_active'] !== 1) {
            return null;
        }
        return $this->publicUser($user);
    }

    /** @return array<string, mixed>|null */
    private function findByGoogleSub(string $sub, bool $forUpdate): ?array
    {
        $statement = $this->connection()->prepare(
            'SELECT * FROM app_user WHERE google_sub=?' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute([$sub]);
        $row = $statement->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    private function findByEmail(string $email, bool $forUpdate): ?array
    {
        $statement = $this->connection()->prepare(
            'SELECT * FROM app_user WHERE email=?' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute([$email]);
        $row = $statement->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    private function findById(int $id, bool $forUpdate): ?array
    {
        $statement = $this->connection()->prepare(
            'SELECT * FROM app_user WHERE id=?' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute([$id]);
        $row = $statement->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<string, mixed> $user @return array{id:int,email:string,display_name:string,avatar_url:?string,role:string} */
    private function publicUser(array $user): array
    {
        return [
            'id' => (int) $user['id'],
            'email' => (string) $user['email'],
            'display_name' => (string) $user['display_name'],
            'avatar_url' => $user['avatar_url'] === null ? null : (string) $user['avatar_url'],
            'role' => (string) $user['role'],
        ];
    }

    private function connection(): PDO
    {
        return $this->connection ??= ($this->connectionFactory)();
    }
}
