<?php

declare(strict_types=1);

namespace Politiks\App;

final class WizardPage
{
    public static function render(string $publicId): string
    {
        $encodedId = htmlspecialchars($publicId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return <<<HTML
<!doctype html>
<html lang="de" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="robots" content="noindex, nofollow">
    <title>Insight bearbeiten – Politiks</title>
    <script src="/assets/theme-init.js"></script>
    <link rel="stylesheet" href="/assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="/assets/vendor/bootstrap-icons/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/assets/app.css">
    <link rel="stylesheet" href="/assets/wizard.css">
</head>
<body data-insight-id="$encodedId">
    <a class="visually-hidden-focusable skip-link" href="#wizard-main">Zum Formular springen</a>
    <nav class="navbar sticky-top app-navbar" aria-label="Insight-Navigation">
        <div class="container py-2 d-flex gap-3">
            <a class="navbar-brand d-flex align-items-center gap-2" href="/"><span class="brand-mark"><i class="bi bi-columns-gap" aria-hidden="true"></i></span><span>Politiks</span></a>
            <div class="ms-auto d-flex gap-2">
                <button class="btn btn-quiet" type="button" data-theme-cycle aria-label="Farbschema wechseln"><i class="bi bi-circle-half" aria-hidden="true"></i></button>
                <a class="btn btn-outline-secondary" href="/#meine-insights"><i class="bi bi-x-lg me-1" aria-hidden="true"></i><span class="d-none d-sm-inline">Schliessen</span></a>
            </div>
        </div>
    </nav>

    <main class="container py-4 py-lg-5" id="wizard-main">
        <div class="row align-items-end g-3 mb-4">
            <div class="col-lg-8"><p class="eyebrow mb-2">Insight-Werkstatt</p><h1 class="display-6 fw-semibold mb-1" data-wizard-title>Insight erstellen</h1><p class="text-body-secondary mb-0">Untersuche offizielle Abstimmungen und halte deine Einordnung getrennt davon fest.</p></div>
            <div class="col-lg-4"><div class="save-indicator text-lg-end" data-save-status role="status" aria-live="polite"><i class="bi bi-cloud-check me-1" aria-hidden="true"></i>Entwurf geladen</div></div>
        </div>

        <form data-wizard aria-busy="true" novalidate>
            <div class="wizard-activity" data-wizard-activity role="status" aria-live="polite">
                <div class="d-flex align-items-center gap-2 mb-2"><span class="spinner-border spinner-border-sm" aria-hidden="true"></span><span data-wizard-activity-text>Assistent wird geladen …</span></div>
                <div class="operation-progress" role="progressbar" aria-label="Ladevorgang läuft"><span></span></div>
            </div>
            <ul class="nav wizard-progress mb-4" role="tablist" aria-label="Schritte zur Erstellung eines Insights">
                <li class="nav-item"><button class="nav-link active" type="button" role="tab" data-step-target aria-current="step" aria-selected="true" aria-controls="wizard-step-1"><span class="step-index">1</span><span class="step-copy"><span class="step-title"><i class="bi bi-bounding-box me-1" aria-hidden="true"></i>Rahmen</span><span class="step-caption">Partei, Rat und Zeitraum</span></span></button></li>
                <li class="nav-item"><button class="nav-link" type="button" role="tab" data-step-target aria-selected="false" aria-controls="wizard-step-2"><span class="step-index">2</span><span class="step-copy"><span class="step-title"><i class="bi bi-people me-1" aria-hidden="true"></i>Mitglieder</span><span class="step-caption">Personenkreis festlegen</span></span></button></li>
                <li class="nav-item"><button class="nav-link" type="button" role="tab" data-step-target aria-selected="false" aria-controls="wizard-step-3"><span class="step-index">3</span><span class="step-copy"><span class="step-title"><i class="bi bi-columns-gap me-1" aria-hidden="true"></i>Abstimmungen</span><span class="step-caption">Ja, Nein und Abweichungen</span></span></button></li>
                <li class="nav-item"><button class="nav-link" type="button" role="tab" data-step-target aria-selected="false" aria-controls="wizard-step-4"><span class="step-index">4</span><span class="step-copy"><span class="step-title"><i class="bi bi-chat-square-quote me-1" aria-hidden="true"></i>Einordnung</span><span class="step-caption">Aussage formulieren</span></span></button></li>
                <li class="nav-item"><button class="nav-link" type="button" role="tab" data-step-target aria-selected="false" aria-controls="wizard-step-5"><span class="step-index">5</span><span class="step-copy"><span class="step-title"><i class="bi bi-clipboard-check me-1" aria-hidden="true"></i>Prüfen</span><span class="step-caption">Kontrollieren und speichern</span></span></button></li>
            </ul>

            <div class="alert alert-danger d-none" data-validation-alert role="alert" tabindex="-1"></div>

            <section class="wizard-step active surface-card wizard-panel" id="wizard-step-1" role="tabpanel" aria-labelledby="step-title-1">
                <div class="section-heading"><span class="icon-tile"><i class="bi bi-bounding-box" aria-hidden="true"></i></span><div><h2 class="h3 mb-1" id="step-title-1">Parlamentarischen Rahmen wählen</h2><p class="text-body-secondary mb-0">Alle späteren Ergebnisse beziehen sich auf diese Partei, Kammer und Zeitspanne.</p></div></div>
                <div class="row g-3 mt-1">
                    <div class="col-md-6"><label class="form-label" for="scope-country">Land</label><select class="form-select" id="scope-country" name="country_id" required></select><div class="form-text">Im MVP ist nur die Schweiz verfügbar.</div></div>
                    <div class="col-md-6"><label class="form-label" for="scope-legislature">Parlament</label><select class="form-select" id="scope-legislature" name="legislature_id" required></select></div>
                    <div class="col-md-6"><label class="form-label" for="scope-chamber">Rat</label><select class="form-select" id="scope-chamber" name="chamber_id" required></select></div>
                    <div class="col-md-6"><label class="form-label" for="scope-party">Formale Partei</label><select class="form-select" id="scope-party" name="party_id" required></select><div class="form-text">Die Fraktionszugehörigkeit wird separat gezeigt.</div></div>
                    <div class="col-md-6"><label class="form-label" for="scope-from">Von</label><input class="form-control" type="date" id="scope-from" name="period_from" required></div>
                    <div class="col-md-6"><label class="form-label" for="scope-to">Bis</label><input class="form-control" type="date" id="scope-to" name="period_to" required></div>
                </div>
                <div class="wizard-actions"><button class="btn btn-primary w-100" type="button" data-next>Mitglieder auswählen <i class="bi bi-arrow-right ms-1" aria-hidden="true"></i></button></div>
            </section>

            <section class="wizard-step surface-card wizard-panel" id="wizard-step-2" role="tabpanel" aria-labelledby="step-title-2">
                <div class="section-heading"><span class="icon-tile"><i class="bi bi-people" aria-hidden="true"></i></span><div><h2 class="h3 mb-1" id="step-title-2">Mitglieder auswählen</h2><p class="text-body-secondary mb-0">Aufgeführt werden Personen mit überlappender Partei- und Ratszugehörigkeit.</p></div></div>
                <div class="row g-3 align-items-end my-2">
                    <div class="col-lg-7"><label class="form-label" for="member-search">Mitglieder suchen</label><div class="input-group"><span class="input-group-text"><i class="bi bi-search" aria-hidden="true"></i></span><input class="form-control" id="member-search" type="search" placeholder="Name oder Fraktion"></div></div>
                    <div class="col-lg-5"><div class="btn-group w-100" role="group" aria-label="Mitgliederauswahl"><button class="btn btn-outline-secondary" type="button" data-members-all>Alle auswählen</button><button class="btn btn-outline-secondary" type="button" data-members-none>Alle abwählen</button></div></div>
                </div>
                <p class="selection-summary" data-member-summary role="status" aria-live="polite">Mitglieder werden geladen …</p>
                <div class="member-grid" data-member-list aria-busy="false"></div>
                <div class="wizard-actions row g-2"><div class="col-sm-5"><button class="btn btn-outline-secondary w-100" type="button" data-prev><i class="bi bi-arrow-left me-1"></i>Zurück</button></div><div class="col-sm-7"><button class="btn btn-primary w-100" type="button" data-next>Abstimmungen untersuchen <i class="bi bi-arrow-right ms-1"></i></button></div></div>
            </section>

            <section class="wizard-step" id="wizard-step-3" role="tabpanel" aria-labelledby="step-title-3">
                <div class="cohort-panel surface-card sticky-top mb-3">
                    <div class="d-flex flex-column flex-lg-row gap-3 align-items-lg-center"><div class="flex-grow-1"><p class="eyebrow mb-1">Aktiver Mitgliederkreis</p><h2 class="h4 mb-1" id="step-title-3" data-cohort-basis>Abstimmungen untersuchen</h2><p class="small text-body-secondary mb-0">Die Richtung beschreibt die Mehrheit der ausgewählten Mitglieder, nicht das Gesamtergebnis des Rates.</p></div><div class="d-flex gap-2"><button class="btn btn-outline-secondary" type="button" data-cohort-toggle><i class="bi bi-people me-1"></i>Mitglieder</button><button class="btn btn-outline-secondary" type="button" data-cohort-reset><i class="bi bi-arrow-counterclockwise me-1"></i>Zurücksetzen</button></div></div>
                    <div class="cohort-editor mt-3 d-none" data-cohort-editor><input class="form-control mb-2" type="search" placeholder="Mitglied suchen" aria-label="Mitglied im aktiven Kreis suchen" data-cohort-search><div class="cohort-checks" data-cohort-list></div></div>
                </div>

                <div class="surface-card wizard-panel mb-3">
                    <label class="form-label fw-semibold" for="vote-search">Abstimmungen und Geschäfte durchsuchen</label>
                    <div class="input-group input-group-lg"><span class="input-group-text"><i class="bi bi-search" aria-hidden="true"></i></span><input class="form-control" id="vote-search" type="search" placeholder="Titel, Geschäft, Abstimmungs-ID, Frage, Bedeutung …"><button class="btn btn-primary" type="button" data-vote-search>Suchen</button></div>
                    <div class="row g-3 mt-1"><div class="col-md-7"><div class="btn-group w-100 direction-filter" role="group" aria-label="Richtung im ausgewählten Mitgliederkreis"><button class="btn btn-outline-secondary active" type="button" data-direction="all">Alle</button><button class="btn btn-outline-success" type="button" data-direction="yes">Ja</button><button class="btn btn-outline-danger" type="button" data-direction="no">Nein</button><button class="btn btn-outline-secondary" type="button" data-direction="neutral">Geteilt</button></div></div><div class="col-md-5"><select class="form-select" data-cohesion-filter aria-label="Nach Einigkeit filtern"><option value="all">Alle Mehrheiten</option><option value="unanimous">Einstimmig</option><option value="majority">Mehrheitlich</option><option value="close">Knapp</option></select></div></div>
                    <details class="filter-panel mt-3"><summary class="small fw-semibold">Weitere Filter</summary><div class="row g-2 pt-3"><div class="col-sm-6 col-lg-3"><label class="form-label small" for="vote-type-filter">Abstimmungstyp</label><select class="form-select" id="vote-type-filter" data-vote-type-filter><option value="all">Alle Typen</option></select></div><div class="col-sm-6 col-lg-3"><label class="form-label small" for="topic-filter">Offizielles Thema</label><select class="form-select" id="topic-filter" data-topic-filter><option value="all">Alle Themen</option></select></div><div class="col-sm-6 col-lg-3"><label class="form-label small" for="classification-filter">Geprüfte Klassifikation</label><select class="form-select" id="classification-filter" data-classification-filter><option value="all">Alle Klassifikationen</option></select></div><div class="col-sm-6 col-lg-3"><label class="form-label small" for="member-filter">Abweichende Stimme</label><select class="form-select" id="member-filter" data-member-filter><option value="all">Alle Mitglieder</option></select></div></div><p class="form-text mb-0">Rat und Zeitraum werden durch den gewählten Rahmen begrenzt. Klassifikationen sind als geprüft oder offiziell gekennzeichnet.</p></details>
                    <p class="small text-body-secondary mt-3 mb-0" data-vote-status role="status" aria-live="polite">Wähle Mitglieder, um Ergebnisse zu berechnen.</p>
                </div>

                <div class="mobile-result-tabs d-md-none mb-3" role="group" aria-label="Ergebnisansicht"><button class="btn btn-outline-success active" type="button" data-mobile-view="yes">Ja <span data-count-yes>0</span></button><button class="btn btn-outline-danger" type="button" data-mobile-view="no">Nein <span data-count-no>0</span></button><button class="btn btn-outline-secondary" type="button" data-mobile-view="neutral">Geteilt <span data-count-neutral>0</span></button></div>
                <div class="row g-3 vote-columns" data-vote-columns aria-busy="false">
                    <div class="col-md-4 vote-column vote-column-yes" data-vote-column="yes"><h3 class="h5"><i class="bi bi-check-circle me-1"></i>Ja <span class="badge text-bg-success" data-count-yes>0</span></h3><div class="vote-card-list" data-votes-yes></div></div>
                    <div class="col-md-4 vote-column vote-column-no" data-vote-column="no"><h3 class="h5"><i class="bi bi-x-circle me-1"></i>Nein <span class="badge text-bg-danger" data-count-no>0</span></h3><div class="vote-card-list" data-votes-no></div></div>
                    <div class="col-md-4 vote-column vote-column-neutral" data-vote-column="neutral"><h3 class="h5"><i class="bi bi-distribute-horizontal me-1"></i>Geteilt / neutral <span class="badge text-bg-secondary" data-count-neutral>0</span></h3><div class="vote-card-list" data-votes-neutral></div></div>
                </div>
                <div class="surface-card wizard-panel mt-3"><div class="d-flex align-items-center justify-content-between gap-3"><div><p class="eyebrow mb-1">Abweichungen entdecken</p><h3 class="h5 mb-0">Übereinstimmung mit der Kohortmehrheit</h3></div><button class="btn btn-outline-secondary" type="button" data-outlier-toggle>Auswertung öffnen</button></div><div class="mt-3 d-none" data-outlier-panel></div></div>
                <div class="evidence-tray surface-card mt-3" data-evidence-tray><div><strong><i class="bi bi-bookmark-check me-1"></i><span data-evidence-count>0</span> Abstimmungen ausgewählt</strong><p class="small text-body-secondary mb-0">Die Auswahl bleibt bei Suche und Mitgliederwechsel erhalten.</p></div><button class="btn btn-outline-secondary" type="button" data-evidence-review>Auswahl prüfen</button></div>
                <div class="wizard-actions row g-2"><div class="col-sm-5"><button class="btn btn-outline-secondary w-100" type="button" data-prev><i class="bi bi-arrow-left me-1"></i>Zurück</button></div><div class="col-sm-7"><button class="btn btn-primary w-100" type="button" data-next>Einordnung schreiben <i class="bi bi-arrow-right ms-1"></i></button></div></div>
            </section>

            <section class="wizard-step surface-card wizard-panel" id="wizard-step-4" role="tabpanel" aria-labelledby="step-title-4">
                <div class="section-heading"><span class="icon-tile"><i class="bi bi-chat-square-quote" aria-hidden="true"></i></span><div><h2 class="h3 mb-1" id="step-title-4">Deine Einordnung</h2><p class="text-body-secondary mb-0">Deine Aussage bleibt klar als Interpretation von den offiziellen Abstimmungsdaten getrennt.</p></div></div>
                <div class="mt-4"><label class="form-label" for="wizard-title">Titel</label><input class="form-control form-control-lg" id="wizard-title" name="title" maxlength="255" required></div>
                <div class="mt-3"><label class="form-label" for="wizard-claim">Aussage</label><textarea class="form-control" id="wizard-claim" name="claim_text" rows="6" maxlength="5000" required></textarea><div class="form-text">Formuliere, was die ausgewählten Abstimmungen deiner Ansicht nach zeigen.</div></div>
                <div class="mt-3"><label class="form-label" for="wizard-notes">Erläuterung und Einschränkungen</label><textarea class="form-control" id="wizard-notes" name="explanatory_notes" rows="5" maxlength="20000"></textarea></div>
                <div class="campaign-context-section mt-4 pt-4 border-top">
                    <div class="d-flex flex-column flex-lg-row align-items-lg-center gap-3 mb-3"><div class="flex-grow-1"><p class="eyebrow mb-1">Nutzerbereitgestellter Kontext</p><h3 class="h5 mb-1">Kampagnenmaterial</h3><p class="small text-body-secondary mb-0">Diese Bilder und Links stammen von dir. Sie sind keine offiziellen Parlamentsdaten und werden separat ausgewiesen.</p></div></div>
                    <div class="row g-2 context-add-actions"><div class="col-md-4"><button class="btn btn-outline-secondary w-100" type="button" data-context-add="image"><i class="bi bi-image me-1"></i>Bild hochladen</button></div><div class="col-md-4"><button class="btn btn-outline-secondary w-100" type="button" data-context-add="youtube"><i class="bi bi-youtube me-1"></i>YouTube-Video</button></div><div class="col-md-4"><button class="btn btn-outline-secondary w-100" type="button" data-context-add="link"><i class="bi bi-link-45deg me-1"></i>Weblink</button></div></div>
                    <div class="alert alert-danger d-none mt-3" data-context-error role="alert" tabindex="-1"></div>
                    <div class="context-list mt-3" data-context-list></div>
                    <div class="context-empty rounded-3 p-4 mt-3 text-center text-body-secondary" data-context-empty><i class="bi bi-megaphone d-block fs-3 mb-2" aria-hidden="true"></i>Noch kein Kampagnenmaterial hinzugefügt.</div>
                </div>
                <div class="wizard-actions row g-2"><div class="col-sm-5"><button class="btn btn-outline-secondary w-100" type="button" data-prev><i class="bi bi-arrow-left me-1"></i>Zurück</button></div><div class="col-sm-7"><button class="btn btn-primary w-100" type="button" data-next>Insight prüfen <i class="bi bi-arrow-right ms-1"></i></button></div></div>
            </section>

            <section class="wizard-step surface-card wizard-panel" id="wizard-step-5" role="tabpanel" aria-labelledby="step-title-5">
                <div class="section-heading"><span class="icon-tile"><i class="bi bi-clipboard-check" aria-hidden="true"></i></span><div><h2 class="h3 mb-1" id="step-title-5">Prüfen und speichern</h2><p class="text-body-secondary mb-0">Kontrolliere Rahmen, Mitglieder, Evidenz und Sichtbarkeit.</p></div></div>
                <div class="review-grid mt-4" data-review-summary></div>
                <div class="mt-4"><label class="form-label fw-semibold" for="wizard-visibility">Sichtbarkeit</label><select class="form-select form-select-lg" id="wizard-visibility" name="visibility"><option value="draft">Entwurf – nur für mich</option><option value="unlisted">Nicht gelistet – nur mit Link</option><option value="public">Öffentlich – im Katalog</option></select><div class="form-text">Unvollständige Insights können jederzeit als Entwurf gespeichert werden.</div></div>
                <div class="share-panel rounded-3 p-3 mt-3 d-none" data-wizard-share><label class="form-label" for="wizard-share-url">Neuer Freigabelink</label><input class="form-control" id="wizard-share-url" readonly data-wizard-share-url></div>
                <div class="wizard-actions row g-2"><div class="col-sm-5"><button class="btn btn-outline-secondary w-100" type="button" data-prev><i class="bi bi-arrow-left me-1"></i>Zurück</button></div><div class="col-sm-7"><button class="btn btn-primary btn-lg w-100" type="submit"><i class="bi bi-check2-circle me-1"></i>Insight speichern</button></div></div>
            </section>
        </form>
    </main>

    <div class="modal fade" id="evidence-modal" tabindex="-1" aria-labelledby="evidence-modal-title" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h2 class="modal-title h4" id="evidence-modal-title">Ausgewählte Abstimmungen</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Schliessen"></button></div><div class="modal-body" data-evidence-modal-list></div><div class="modal-footer"><button class="btn btn-primary w-100" type="button" data-bs-dismiss="modal">Fertig</button></div></div></div></div>
    <div class="modal fade" id="context-modal" tabindex="-1" aria-labelledby="context-modal-title" aria-hidden="true"><div class="modal-dialog modal-lg"><form class="modal-content" data-context-form><div class="modal-header"><div><p class="eyebrow mb-1">Nutzerbereitgestellter Kontext</p><h2 class="modal-title h4" id="context-modal-title" data-context-modal-title>Material hinzufügen</h2></div><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Schliessen"></button></div><div class="modal-body"><input type="hidden" name="context_id"><input type="hidden" name="context_type"><div class="alert alert-danger d-none" data-context-modal-error role="alert"></div><div class="mb-3" data-context-file-row><label class="form-label" for="context-image">Bilddatei</label><input class="form-control" id="context-image" name="image" type="file" accept="image/jpeg,image/png,image/webp"><div class="form-text">JPEG, PNG oder WebP bis zur konfigurierten Uploadgrösse.</div></div><div class="mb-3" data-context-url-row><label class="form-label" for="context-url" data-context-url-label>Webadresse</label><input class="form-control" id="context-url" name="source_url" type="url" maxlength="2048" placeholder="https://…"></div><div class="row g-3"><div class="col-md-6"><label class="form-label" for="context-label">Bezeichnung</label><input class="form-control" id="context-label" name="label" maxlength="255" placeholder="z. B. Wahlplakat 2023"></div><div class="col-md-6"><label class="form-label" for="context-attribution">Urheberangabe</label><input class="form-control" id="context-attribution" name="attribution" maxlength="255" placeholder="Partei, Fotograf:in oder Kanal"></div><div class="col-12"><label class="form-label" for="context-description">Beschreibung</label><textarea class="form-control" id="context-description" name="description" rows="3" maxlength="5000"></textarea></div></div></div><div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Abbrechen</button><button class="btn btn-primary" type="submit"><i class="bi bi-check2 me-1"></i>Speichern</button></div></form></div></div>
    <script src="/assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
    <script src="/assets/wizard.js"></script>
</body>
</html>
HTML;
    }
}
