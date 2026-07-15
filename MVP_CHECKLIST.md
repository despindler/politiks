# MVP-Datenqualitäts- und Sicherheitscheckliste

Stand: 15. Juli 2026. „Automatisiert“ bedeutet, dass der Check im Repository reproduzierbar ist; „Zielhost“ muss beim Produktions-Deployment protokolliert werden.

## Datenqualität

- [x] Offizielle Quelldateien sind unveränderlich geplant, mit Bytezahl/SHA-256 manifestiert und vor Import validiert.
- [x] Forschungsimport, Klassifikation und MariaDB-Publikation sind reproduzierbar; nur geprüfte Klassifikationen gelangen in die Runtime-Sicht.
- [x] Jede Referenzpublikation trägt Snapshot-, Schema-, Taxonomie-, Klassifikations- und Inhaltsdigests sowie Tabellenzählungen.
- [x] Insights und Evidenz bleiben an eine unveränderliche Referenzpublikation gebunden.
- [x] Historische Partei-, Fraktions- und Mandatszeiträume werden getrennt gespeichert und bei der Mitgliederauswahl geschnitten.
- [x] Exakte Abstimmungsfrage, Ja-/Nein-Bedeutung, Typ, Datum, Kennungen, Provenienz und Teilnahme bleiben sichtbar.
- [x] Ja/Nein-Richtung ignoriert Enthaltung/Nichtteilnahme; Split, fehlende Teilnahme und Einschränkungen bleiben explizit.
- [x] Nutzerbehauptung und Kampagnenmaterial sind strukturell und visuell von offiziellen Parlamentsdaten getrennt.
- [x] Der Full-Snapshot-Qualitätsbericht dokumentiert unbekannte Kammerzuordnung, semantische Lücken und weitere Grenzen.
- [ ] Zielhost: vollständigen Schweizer Produktionssnapshot frisch publizieren und Zählungen/Checksumme protokollieren.

## Anwendungssicherheit und Datenschutz

- [x] Vorbereitete Statements, Transaktionen und owner-scoped 404-Grenzen schützen alle mutierenden beziehungsweise privaten Zugriffe.
- [x] Jede Mutation verlangt eine gültige sitzungsgebundene CSRF-Bestätigung.
- [x] Login rotiert die Session-ID; Cookies sind HTTP-only, SameSite=Lax und in Produktion Secure.
- [x] Produktion verlangt HTTPS, verbietet Testauthentifizierung und zeigt keine PHP-Fehlerdetails an.
- [x] Google-ID-Token werden auf RS256-Signatur, Schlüssel, Audience, Issuer, Ablauf und bestätigte E-Mail geprüft.
- [x] CSP, Framing-, MIME-, Referrer-, Permissions-, COOP- und HSTS-Header sind gesetzt; Fremdquellen sind eng begrenzt.
- [x] Entwürfe und nicht gelistete Insights erscheinen nicht im öffentlichen Katalog oder unter erratbaren Insight-URLs.
- [x] Nicht gelistete Tokens sind zufällig, nur gehasht gespeichert und mit `noindex, nofollow` ausgeliefert.
- [x] Nutzertexte werden als Textknoten gerendert; externe Links und generierte YouTube-Frames sind eingeschränkt.
- [x] Uploads sind grössenbegrenzt, dekodiert geprüft, zufällig benannt, nicht direkt öffentlich und autorisiert gestreamt.
- [x] Das versionierte `site/` enthält keine Test-Zugangsdaten, Router, SQLite-Dateien, Snapshots oder Entwicklungswerkzeuge.
- [x] `.env*`, Datenbanken, Uploads, Tokens und Logs sind Git-ignoriert; `.env.example` enthält nur Platzhalter.
- [x] Die KI-Vorauswahl ist standardmässig aus, akzeptiert nur offizielle OpenAI-Responses-Endpunkte und trennt vertrauenswürdige Prompts von Kriterien/Kandidaten als Daten.
- [x] Kriterien, öffentliche Kandidatenfelder und aggregierte Kohortenzahlen sind offengelegt; Identität, Ratsmitgliedernamen, Einzelstimmen, Uploads, Kampagnenmaterial und übrige Insight-Texte werden nicht an OpenAI gesendet.
- [x] Das KI-Betriebsprotokoll enthält Request-ID, Hashes, Status, Laufzeit, Tokenzahlen, Modell und Prompt-Version, aber keine Rohkriterien, Kandidatentexte, Google-Identität oder Kampagneninhalte.
- [x] Der Deployment-Audit verbietet deterministische KI-Testadapter/-zugangsdaten und verlangt deaktivierte, leere AI-Platzhalter.
- [ ] Zielhost: Dateirechte, `.htaccess`-Sperren, Security-/Cache-Header und Log-Redaktion prüfen.
- [ ] Zielhost: echten Google-Login und erlaubten JavaScript-Origin prüfen.
- [ ] Zielhost: `DATENSCHUTZ_KI.md` mit Betreiberangaben/rechtlicher Prüfung veröffentlichen und OpenAI-Datenkontrollen protokollieren.
- [ ] Zielhost: separates OpenAI-Produktionsprojekt, privaten eingeschränkten Schlüssel, Modell-/Kosten-/Ratenlimits und Notabschaltung prüfen; KI erst danach aktivieren.
- [ ] Betrieb: verschlüsseltes MariaDB-/Upload-/Konfigurationsbackup erstellen und Restore in separater Datenbank testen.

## Release-Akzeptanz

- [x] `npm run verify` umfasst PHP-, vier MariaDB-, Deployment- und Desktop/Mobile-Playwright-Prüfungen.
- [x] `npm run verify:clean` setzt ausschliesslich eine `.env.test`-Datenbank zurück, erzeugt deterministische Zwei-Nutzer-/Sichtbarkeitsfixtures und führt die Gesamtsuite aus.
- [x] Der browserbasierte kritische Pfad umfasst Katalog, Login, Scope, Mitglieder/Kohorte, Outlier, Suche/Evidenz, Kampagnenkontext, Entwurf, nicht gelisteten Link, Veröffentlichung, Signed-out-Katalog und Owner-Edit.
- [x] Visuelle Referenzen decken Desktop/Mobile und Hell/Dunkel für Shell, Katalog, Abstimmungsarbeitsraum und Kampagnenkontext ab.
- [x] Die versionierte deutsche KI-Auswahlevaluation deckt erforderliche, verbotene, mehrdeutige und leere Resultate ab; normale Akzeptanz verwendet null externe KI-Anfragen.
- [x] KI-Modal und angewandter Filter sind für Desktop/Mobile sowie Hell/Dunkel visuell geprüft.
- [ ] Optional vor KI-Freigabe: `npm.cmd run test:ai-live` mit separatem Entwicklungsschlüssel erfolgreich ausführen und Request/Modell/Token protokollieren.
- [ ] Zielhost: Smoke-Protokoll gemäss `DEPLOYMENT.md` vollständig abhaken.
