<?php

declare(strict_types=1);

namespace Politiks\App;

use Politiks\App\Auth\AuthService;
use Politiks\App\Auth\GoogleAuthException;
use Politiks\App\Insight\InsightException;
use Politiks\App\Insight\InsightStore;
use Politiks\App\Insight\WizardStore;
use Politiks\App\Insight\CampaignContextStore;
use Politiks\App\Security\Csrf;
use Throwable;

final class Application
{
    public function __construct(
        private readonly Config $config,
        private readonly AuthService $auth,
        private readonly Csrf $csrf,
        private readonly InsightStore $insights,
        private readonly WizardStore $wizard,
        private readonly CampaignContextStore $campaignContexts,
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
            if ($method === 'GET' && preg_match('~^/geteilt/([A-Za-z0-9_-]{43})$~', $path, $matches) === 1) {
                if ($this->insights->findShared($matches[1]) === null) {
                    throw new HttpFailure(404, 'INSIGHT_NOT_FOUND', 'Der Insight wurde nicht gefunden.');
                }
                header('Content-Type: text/html; charset=utf-8');
                header('Cache-Control: no-store');
                header('X-Robots-Tag: noindex, nofollow');
                echo HomePage::render(true);
                exit;
            }
            if ($method === 'GET' && preg_match('~^/insights/([a-f0-9]{26})/bearbeiten$~', $path, $matches) === 1) {
                $user = $this->requireUser();
                $this->wizard->state($user['id'], $matches[1]);
                header('Content-Type: text/html; charset=utf-8');
                header('Cache-Control: no-store');
                header('X-Robots-Tag: noindex, nofollow');
                echo WizardPage::render($matches[1]);
                exit;
            }
            if ($method === 'GET' && preg_match('~^/media/campaign-context/([1-9][0-9]*)$~', $path, $matches) === 1) {
                $user = $this->auth->currentUser();
                $share = $_GET['share'] ?? null;
                $image = $this->campaignContexts->imageForViewer(
                    (int) $matches[1],
                    $user['id'] ?? null,
                    is_string($share) ? $share : null,
                );
                header('Content-Type: ' . $image['media_type']);
                header('Content-Length: ' . $image['byte_count']);
                header('Content-Disposition: inline');
                header('Cache-Control: private, no-store');
                header('ETag: "' . $image['sha256'] . '"');
                readfile($image['path']);
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
            if ($method === 'GET' && $path === '/api/insights/public') {
                Http::json(['ok' => true] + $this->insights->publicPage(...$this->pagination(6)));
            }
            if ($method === 'GET' && $path === '/api/insights/mine') {
                $user = $this->requireUser();
                Http::json(['ok' => true] + $this->insights->ownerPage($user['id'], ...$this->pagination(8)));
            }
            if ($method === 'POST' && $path === '/api/insights') {
                $this->requireCsrf();
                $user = $this->requireUser();
                Http::json(['ok' => true, 'insight' => $this->insights->createDraft($user['id'])], 201);
            }
            if ($method === 'GET' && preg_match('~^/api/insights/([a-f0-9]{26})/wizard$~', $path, $matches) === 1) {
                $user = $this->requireUser();
                Http::json(['ok' => true] + $this->wizard->state($user['id'], $matches[1]));
            }
            if ($method === 'PUT' && preg_match('~^/api/insights/([a-f0-9]{26})/scope$~', $path, $matches) === 1) {
                $this->requireCsrf();
                $user = $this->requireUser();
                Http::json(['ok' => true, 'scope' => $this->wizard->saveScope($user['id'], $matches[1], Http::jsonBody())]);
            }
            if ($method === 'GET' && preg_match('~^/api/insights/([a-f0-9]{26})/members$~', $path, $matches) === 1) {
                $user = $this->requireUser();
                Http::json(['ok' => true, 'items' => $this->wizard->eligibleMembers($user['id'], $matches[1])]);
            }
            if ($method === 'PUT' && preg_match('~^/api/insights/([a-f0-9]{26})/members$~', $path, $matches) === 1) {
                $this->requireCsrf();
                $user = $this->requireUser();
                $body = Http::jsonBody();
                Http::json(['ok' => true, 'member_ids' => $this->wizard->saveMembers($user['id'], $matches[1], $body['member_ids'] ?? null)]);
            }
            if ($method === 'PUT' && preg_match('~^/api/insights/([a-f0-9]{26})/evidence$~', $path, $matches) === 1) {
                $this->requireCsrf();
                $user = $this->requireUser();
                $body = Http::jsonBody();
                Http::json(['ok' => true, 'evidence_ids' => $this->wizard->saveEvidence($user['id'], $matches[1], $body['evidence_ids'] ?? null)]);
            }
            if ($method === 'POST' && preg_match('~^/api/insights/([a-f0-9]{26})/votes$~', $path, $matches) === 1) {
                $this->requireCsrf();
                $user = $this->requireUser();
                $body = Http::jsonBody();
                Http::json(['ok' => true] + $this->wizard->votes(
                    $user['id'], $matches[1], $body['member_ids'] ?? null, $body['query'] ?? ''
                ));
            }
            if ($method === 'GET' && preg_match('~^/api/insights/([a-f0-9]{26})/contexts$~', $path, $matches) === 1) {
                $user = $this->requireUser();
                Http::json(['ok' => true, 'items' => $this->campaignContexts->ownerContexts($user['id'], $matches[1])]);
            }
            if ($method === 'POST' && preg_match('~^/api/insights/([a-f0-9]{26})/contexts$~', $path, $matches) === 1) {
                $this->requireCsrf();
                $user = $this->requireUser();
                Http::json(['ok' => true, 'item' => $this->campaignContexts->createRemote($user['id'], $matches[1], Http::jsonBody())], 201);
            }
            if ($method === 'POST' && preg_match('~^/api/insights/([a-f0-9]{26})/context-images$~', $path, $matches) === 1) {
                $this->requireCsrf();
                $user = $this->requireUser();
                $contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
                if ($contentType !== 'multipart/form-data') {
                    throw new HttpFailure(415, 'CONTENT_TYPE_REQUIRED', 'Multipart-Formulardaten sind erforderlich.');
                }
                $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
                if ($length > $this->config->uploadMaxBytes + 100_000) {
                    throw new HttpFailure(413, 'REQUEST_TOO_LARGE', 'Das Bild ist zu gross.');
                }
                $file = $_FILES['image'] ?? null;
                if (!is_array($file)) {
                    throw new HttpFailure(422, 'IMAGE_REQUIRED', 'Wähle eine Bilddatei aus.');
                }
                Http::json([
                    'ok' => true,
                    'item' => $this->campaignContexts->uploadImage($user['id'], $matches[1], $file, $_POST),
                ], 201);
            }
            if ($method === 'PUT' && preg_match('~^/api/insights/([a-f0-9]{26})/contexts/order$~', $path, $matches) === 1) {
                $this->requireCsrf();
                $user = $this->requireUser();
                $body = Http::jsonBody();
                Http::json(['ok' => true, 'items' => $this->campaignContexts->reorder($user['id'], $matches[1], $body['context_ids'] ?? null)]);
            }
            if ($method === 'PATCH' && preg_match('~^/api/insights/([a-f0-9]{26})/contexts/([1-9][0-9]*)$~', $path, $matches) === 1) {
                $this->requireCsrf();
                $user = $this->requireUser();
                Http::json(['ok' => true, 'item' => $this->campaignContexts->update($user['id'], $matches[1], (int) $matches[2], Http::jsonBody())]);
            }
            if ($method === 'DELETE' && preg_match('~^/api/insights/([a-f0-9]{26})/contexts/([1-9][0-9]*)$~', $path, $matches) === 1) {
                $this->requireCsrf();
                $user = $this->requireUser();
                $this->campaignContexts->delete($user['id'], $matches[1], (int) $matches[2]);
                Http::json(['ok' => true]);
            }
            if ($method === 'GET' && preg_match('~^/api/insights/([a-f0-9]{26})$~', $path, $matches) === 1) {
                $user = $this->auth->currentUser();
                $insight = $this->insights->findVisible($matches[1], $user['id'] ?? null);
                if ($insight === null) {
                    throw new InsightException('INSIGHT_NOT_FOUND', 'Der Insight wurde nicht gefunden.', 404);
                }
                Http::json(['ok' => true, 'insight' => $insight]);
            }
            if ($method === 'PATCH' && preg_match('~^/api/insights/([a-f0-9]{26})$~', $path, $matches) === 1) {
                $this->requireCsrf();
                $user = $this->requireUser();
                Http::json(['ok' => true, 'insight' => $this->insights->update($user['id'], $matches[1], Http::jsonBody())]);
            }
            if ($method === 'DELETE' && preg_match('~^/api/insights/([a-f0-9]{26})$~', $path, $matches) === 1) {
                $this->requireCsrf();
                $user = $this->requireUser();
                $this->insights->archive($user['id'], $matches[1]);
                Http::json(['ok' => true]);
            }
            if ($method === 'GET' && preg_match('~^/api/shared-insights/([A-Za-z0-9_-]{43})$~', $path, $matches) === 1) {
                $insight = $this->insights->findShared($matches[1]);
                if ($insight === null) {
                    throw new InsightException('INSIGHT_NOT_FOUND', 'Der Insight wurde nicht gefunden.', 404);
                }
                header('X-Robots-Tag: noindex, nofollow');
                Http::json(['ok' => true, 'insight' => $insight]);
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
        } catch (InsightException $error) {
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

    /** @return array{id:int,email:string,display_name:string,avatar_url:?string,role:string} */
    private function requireUser(): array
    {
        $user = $this->auth->currentUser();
        if ($user === null) {
            throw new HttpFailure(401, 'AUTHENTICATION_REQUIRED', 'Bitte melde dich zuerst an.');
        }
        return $user;
    }

    /** @return array{int,int} */
    private function pagination(int $defaultPerPage): array
    {
        $page = $_GET['page'] ?? '1';
        $perPage = $_GET['per_page'] ?? (string) $defaultPerPage;
        if (!is_string($page) || !ctype_digit($page) || !is_string($perPage) || !ctype_digit($perPage)) {
            throw new InsightException('INVALID_PAGINATION', 'Die Seitennummerierung ist ungültig.');
        }
        return [(int) $page, (int) $perPage];
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
