<?php

declare(strict_types=1);

namespace Politiks\App;

use Politiks\App\Auth\AuthService;
use Politiks\App\Auth\GoogleAuthException;
use Politiks\App\Security\Csrf;
use Throwable;

final class Application
{
    public function __construct(
        private readonly Config $config,
        private readonly AuthService $auth,
        private readonly Csrf $csrf,
    ) {
    }

    public function run(): never
    {
        Http::securityHeaders($this->config);
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));

        try {
            if ($method === 'GET' && $path === '/') {
                header('Content-Type: text/html; charset=utf-8');
                header('Cache-Control: no-cache');
                echo HomePage::render();
                exit;
            }
            if ($method === 'GET' && $path === '/api/auth-config') {
                Http::json(['ok' => true, 'google_client_id' => $this->config->googleClientId]);
            }
            if ($method === 'GET' && $path === '/api/session') {
                $user = $this->auth->currentUser();
                Http::json([
                    'ok' => true,
                    'authenticated' => $user !== null,
                    'csrf_token' => $this->csrf->token(),
                    'user' => $user,
                ]);
            }
            if ($method === 'POST' && $path === '/api/google-login') {
                $this->requireCsrf();
                $body = Http::jsonBody();
                $credential = $body['credential'] ?? null;
                if (!is_string($credential) || trim($credential) === '') {
                    throw new HttpFailure(
                        422,
                        'GOOGLE_CREDENTIAL_REQUIRED',
                        'Eine Google-Anmeldebestätigung ist erforderlich.',
                    );
                }
                $user = $this->auth->googleLogin(trim($credential));
                Http::json(['ok' => true, 'user' => $user]);
            }
            if ($method === 'POST' && $path === '/api/logout') {
                $this->requireCsrf();
                $this->auth->logout();
                Http::json(['ok' => true]);
            }
            if (str_starts_with($path, '/api/')) {
                throw new HttpFailure(404, 'ROUTE_NOT_FOUND', 'API-Endpunkt nicht gefunden.');
            }
            http_response_code(404);
            header('Content-Type: text/html; charset=utf-8');
            echo HomePage::notFound();
            exit;
        } catch (GoogleAuthException $error) {
            $this->error($error->status, $error->errorCode, $error->getMessage());
        } catch (HttpFailure $error) {
            $this->error($error->status, $error->errorCode, $error->getMessage());
        } catch (Throwable $error) {
            error_log('Politiks request failure: ' . $error::class);
            $this->error(500, 'INTERNAL_ERROR', 'Die Anfrage konnte nicht verarbeitet werden.');
        }
    }

    private function requireCsrf(): void
    {
        $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        if (!$this->csrf->valid(is_string($provided) ? $provided : null)) {
            throw new HttpFailure(403, 'CSRF_FAILED', 'Die Sicherheitsbestätigung ist ungültig.');
        }
    }

    private function error(int $status, string $code, string $message): never
    {
        Http::json([
            'ok' => false,
            'error_code' => $code,
            'message' => $message,
            'details' => (object) [],
        ], $status);
    }
}
