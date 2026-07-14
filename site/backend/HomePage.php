<?php

declare(strict_types=1);

namespace Politiks\App;

final class HomePage
{
    public static function render(bool $shared = false): string
    {
        $html = <<<'HTML'
<!doctype html>
<html lang="de" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="description" content="Politiks macht parlamentarische Abstimmungen nachvollziehbar.">
    __ROBOTS__
    <title>Politiks – Abstimmungen verstehen</title>
    <script src="/assets/theme-init.js"></script>
    <link rel="stylesheet" href="/assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="/assets/vendor/bootstrap-icons/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body data-page="__PAGE__">
    <a class="visually-hidden-focusable skip-link" href="#main-content">Zum Inhalt springen</a>
    <nav class="navbar navbar-expand-lg sticky-top app-navbar" aria-label="Hauptnavigation">
        <div class="container py-2">
            <a class="navbar-brand d-flex align-items-center gap-2" href="/" aria-label="Politiks Startseite">
                <span class="brand-mark" aria-hidden="true"><i class="bi bi-columns-gap"></i></span>
                <span>Politiks</span>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#main-navigation" aria-controls="main-navigation" aria-expanded="false" aria-label="Navigation öffnen">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="main-navigation">
                <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-2 pt-3 pt-lg-0">
                    <li class="nav-item"><a class="nav-link" href="#insights">Öffentliche Insights</a></li>
                    <li class="nav-item d-none" data-authenticated><a class="nav-link" href="#meine-insights">Meine Insights</a></li>
                    <li class="nav-item dropdown">
                        <button class="btn btn-quiet dropdown-toggle w-100" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Farbschema wählen">
                            <i class="bi bi-circle-half me-2" data-theme-icon aria-hidden="true"></i><span data-theme-label>System</span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end theme-menu">
                            <li><button class="dropdown-item" type="button" data-theme-value="system"><i class="bi bi-circle-half me-2" aria-hidden="true"></i>System</button></li>
                            <li><button class="dropdown-item" type="button" data-theme-value="light"><i class="bi bi-sun me-2" aria-hidden="true"></i>Hell</button></li>
                            <li><button class="dropdown-item" type="button" data-theme-value="dark"><i class="bi bi-moon-stars me-2" aria-hidden="true"></i>Dunkel</button></li>
                        </ul>
                    </li>
                    <li class="nav-item d-none" data-authenticated>
                        <button class="btn btn-outline-secondary w-100" type="button" data-logout><i class="bi bi-box-arrow-right me-2"></i>Abmelden</button>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <main id="main-content">
        <section class="hero-section">
            <div class="container py-5 py-lg-6">
                <div class="row align-items-center g-5">
                    <div class="col-lg-7">
                        <p class="eyebrow mb-3"><span class="status-dot"></span>Schweizer Parlamentsdaten</p>
                        <h1 class="display-3 fw-semibold text-balance mb-4">Was Parteien sagen.<br><span class="text-accent">Wie ihre Mitglieder abstimmen.</span></h1>
                        <p class="lead text-body-secondary col-xl-10 mb-4">Politiks hilft dir, offizielle Abstimmungen zu untersuchen und daraus nachvollziehbare, sauber belegte Insights zu erstellen.</p>
                        <div class="row g-3 col-xl-10">
                            <div class="col-sm-6 d-none" data-authenticated>
                                <a class="btn btn-primary btn-lg w-100" href="#meine-insights"><i class="bi bi-plus-lg me-2"></i>Insight erstellen</a>
                            </div>
                            <div class="col-sm-6">
                                <a class="btn btn-outline-secondary btn-lg w-100" href="#insights"><i class="bi bi-arrow-down me-2"></i>Insights entdecken</a>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-5">
                        <aside class="auth-card surface-card" aria-labelledby="auth-title">
                            <div data-signed-out>
                                <span class="icon-tile mb-4"><i class="bi bi-shield-check"></i></span>
                                <h2 class="h4" id="auth-title">Mit Google anmelden</h2>
                                <p class="text-body-secondary mb-4">Erstelle und verwalte eigene Insights. Politiks erhält nur deine bestätigte E-Mail-Adresse und deinen Anzeigenamen.</p>
                                <div id="google-login" class="google-login d-grid" aria-live="polite"></div>
                                <p class="small text-body-secondary mt-3 mb-0" data-google-disabled hidden>Die Anmeldung ist derzeit nicht konfiguriert.</p>
                            </div>
                            <div class="d-none" data-authenticated>
                                <span class="icon-tile mb-4"><i class="bi bi-person-check"></i></span>
                                <p class="eyebrow mb-2">Angemeldet</p>
                                <h2 class="h4 mb-2" data-user-name>Willkommen</h2>
                                <p class="text-body-secondary mb-4" data-user-email></p>
                                <a class="btn btn-primary w-100" href="#meine-insights"><i class="bi bi-journal-text me-2"></i>Zu meinen Insights</a>
                            </div>
                            <div class="alert alert-danger mt-3 mb-0 d-none" role="alert" data-auth-message></div>
                        </aside>
                    </div>
                </div>
            </div>
        </section>

        <section class="process-section border-top border-bottom">
            <div class="container py-5">
                <div class="row g-4">
                    <div class="col-md-4"><div class="process-item"><span>01</span><div><h2 class="h6">Partei und Mitglieder</h2><p>Wähle den politischen und zeitlichen Rahmen.</p></div></div></div>
                    <div class="col-md-4"><div class="process-item"><span>02</span><div><h2 class="h6">Abstimmungen prüfen</h2><p>Vergleiche Ja, Nein und abweichende Stimmen.</p></div></div></div>
                    <div class="col-md-4"><div class="process-item"><span>03</span><div><h2 class="h6">Insight belegen</h2><p>Verbinde deine Einordnung mit präzisen Quellen.</p></div></div></div>
                </div>
            </div>
        </section>

        <section class="container py-5 py-lg-6" id="insights" aria-labelledby="insights-title">
            <div class="row align-items-end g-3 mb-4">
                <div class="col-lg-8">
                    <p class="eyebrow mb-2" data-catalogue-eyebrow>Von der Community</p>
                    <h2 class="display-6 fw-semibold mb-0" id="insights-title" data-catalogue-title>Öffentliche Insights</h2>
                </div>
                <div class="col-lg-4"><p class="text-body-secondary mb-0 text-lg-end">Behauptung und parlamentarische Evidenz bleiben klar getrennt.</p></div>
            </div>
            <div class="catalogue-status surface-card text-center" data-public-status role="status" aria-live="polite">
                <span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Insights werden geladen …
            </div>
            <div class="accordion insight-list d-none" id="public-insights-list" data-public-list></div>
            <button class="btn btn-outline-secondary w-100 mt-3 d-none" type="button" data-public-more>
                <i class="bi bi-arrow-down-circle me-2" aria-hidden="true"></i>Weitere Insights laden
            </button>
        </section>

        <section class="container pb-5 d-none" id="meine-insights" data-authenticated aria-labelledby="my-insights-title">
            <div class="surface-card p-4 p-lg-5">
                <div class="row align-items-end g-3 mb-4">
                    <div class="col-md-8">
                        <p class="eyebrow mb-2">Dein Arbeitsbereich</p>
                        <h2 class="h3 mb-1" id="my-insights-title">Meine Insights</h2>
                        <p class="text-body-secondary mb-0">Entwürfe, geteilte und veröffentlichte Insights an einem Ort.</p>
                    </div>
                    <div class="col-md-4">
                        <button class="btn btn-primary w-100" type="button" data-create-insight><i class="bi bi-plus-lg me-2"></i>Neuen Insight erstellen</button>
                    </div>
                </div>
                <div class="catalogue-status text-center" data-mine-status role="status" aria-live="polite">
                    <span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Deine Insights werden geladen …
                </div>
                <div class="row g-3 d-none" data-mine-list></div>
                <button class="btn btn-outline-secondary w-100 mt-3 d-none" type="button" data-mine-more>Weitere eigene Insights laden</button>
            </div>
        </section>
    </main>

    <div class="modal fade" id="insight-editor" tabindex="-1" aria-labelledby="insight-editor-title" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <form data-insight-form>
                    <div class="modal-header">
                        <div><p class="eyebrow mb-1">Insight bearbeiten</p><h2 class="modal-title h4" id="insight-editor-title">Titel und Aussage</h2></div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schliessen"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-danger d-none" role="alert" data-editor-error></div>
                        <div class="mb-3"><label class="form-label" for="insight-title">Titel</label><input class="form-control" id="insight-title" name="title" maxlength="255" required></div>
                        <div class="mb-3"><label class="form-label" for="insight-claim">Aussage</label><textarea class="form-control" id="insight-claim" name="claim_text" rows="5" maxlength="5000"></textarea><div class="form-text">Deine Interpretation – getrennt von der parlamentarischen Evidenz.</div></div>
                        <div class="mb-3"><label class="form-label" for="insight-notes">Erläuterung</label><textarea class="form-control" id="insight-notes" name="explanatory_notes" rows="4" maxlength="20000"></textarea></div>
                        <div class="mb-3"><label class="form-label" for="insight-visibility">Sichtbarkeit</label><select class="form-select" id="insight-visibility" name="visibility"><option value="draft">Entwurf – nur für mich</option><option value="unlisted">Nicht gelistet – nur mit Link</option><option value="public">Öffentlich – im Katalog</option></select></div>
                        <div class="share-panel rounded-3 p-3 d-none" data-share-panel><label class="form-label" for="insight-share-url">Neuer Freigabelink</label><div class="input-group"><input class="form-control" id="insight-share-url" readonly data-share-url><button class="btn btn-outline-secondary" type="button" data-copy-share><i class="bi bi-copy me-1"></i>Kopieren</button></div><p class="small text-body-secondary mt-2 mb-0">Der bisherige Link ist damit ungültig.</p></div>
                    </div>
                    <div class="modal-footer flex-column flex-sm-row">
                        <button type="button" class="btn btn-outline-danger w-100 me-sm-auto" data-archive-insight><i class="bi bi-archive me-2"></i>Archivieren</button>
                        <button type="button" class="btn btn-outline-secondary w-100" data-bs-dismiss="modal">Abbrechen</button>
                        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-check2 me-2"></i>Speichern</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <footer class="border-top py-4">
        <div class="container d-flex flex-column flex-sm-row justify-content-between gap-2 small text-body-secondary">
            <span>Politiks · Evidenz vor Behauptung</span>
            <span>Daten: Parlamentsdienste der Bundesversammlung, Bern</span>
        </div>
    </footer>

    <script src="/assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
    <script src="/assets/app.js"></script>
</body>
</html>
HTML;
        return str_replace(
            ['__ROBOTS__', '__PAGE__'],
            [$shared ? '<meta name="robots" content="noindex, nofollow">' : '', $shared ? 'shared' : 'home'],
            $html,
        );
    }

    public static function notFound(): string
    {
        return '<!doctype html><html lang="de"><meta charset="utf-8"><title>Nicht gefunden – Politiks</title>'
            . '<main><h1>Nicht gefunden</h1><p>Diese Seite existiert nicht.</p><a href="/">Zur Startseite</a></main></html>';
    }
}
