<?php

declare(strict_types=1);

namespace Politiks\App;

use Politiks\App\Auth\AuthService;
use Politiks\App\Auth\GoogleIdTokenVerifier;
use Politiks\App\Auth\GoogleJwksProvider;
use Politiks\App\Auth\MariaDbUserStore;
use Politiks\App\Auth\OpenSslJwtSignatureVerifier;
use Politiks\App\Auth\TestGoogleTokenVerifier;
use Politiks\App\Security\Csrf;
use Politiks\App\Security\NativeSession;
use Politiks\App\Insight\InsightStore;
use Politiks\App\Insight\WizardStore;
use Politiks\App\Insight\CampaignContextStore;

final class ApplicationFactory
{
    public static function create(): Application
    {
        $config = Config::load();
        $session = new NativeSession($config);
        $database = new Database($config);
        $users = new MariaDbUserStore($database->connection(...));
        $verifier = $config->testAuthEnabled
            ? new TestGoogleTokenVerifier()
            : new GoogleIdTokenVerifier(
                $config->googleClientId,
                new GoogleJwksProvider(
                    $config->googleJwksUrl,
                    $config->storagePath . '/cache/google-jwks.json',
                ),
                new OpenSslJwtSignatureVerifier(),
            );
        $campaignContexts = new CampaignContextStore(
            $database->connection(...),
            $config->storagePath,
            $config->uploadMaxBytes,
        );
        return new Application(
            $config,
            new AuthService($verifier, $users, $session),
            new Csrf($session),
            new InsightStore($database->connection(...), $config->appUrl),
            new WizardStore($database->connection(...)),
            $campaignContexts,
        );
    }
}
