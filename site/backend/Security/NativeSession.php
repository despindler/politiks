<?php

declare(strict_types=1);

namespace Politiks\App\Security;

use Politiks\App\Config;
use RuntimeException;

final class NativeSession implements SessionStore
{
    private bool $started = false;

    public function __construct(private readonly Config $config)
    {
    }

    public function start(): void
    {
        if ($this->started || session_status() === PHP_SESSION_ACTIVE) {
            $this->started = true;
            return;
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');
        session_name($this->config->sessionName);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $this->config->usesSecureCookies(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        if (!session_start()) {
            throw new RuntimeException('Die Sitzung konnte nicht gestartet werden.');
        }
        $this->started = true;
    }

    public function get(string $key): mixed
    {
        $this->start();
        return $_SESSION[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->start();
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        $this->start();
        unset($_SESSION[$key]);
    }

    public function regenerate(): void
    {
        $this->start();
        if (!session_regenerate_id(true)) {
            throw new RuntimeException('Die Sitzungs-ID konnte nicht erneuert werden.');
        }
    }

    public function destroy(): void
    {
        $this->start();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $parameters = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $parameters['path'],
                'domain' => $parameters['domain'],
                'secure' => $parameters['secure'],
                'httponly' => $parameters['httponly'],
                'samesite' => $parameters['samesite'] ?? 'Lax',
            ]);
        }
        session_destroy();
        $this->started = false;
    }

    public function id(): string
    {
        $this->start();
        return session_id();
    }
}
