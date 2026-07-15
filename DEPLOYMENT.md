# Deployment-Handbuch

Dieses Handbuch beschreibt ein reproduzierbares Produktions-Deployment von Politiks auf Apache, PHP 8.4 und MariaDB 10.6. Der öffentliche DocumentRoot enthält ausschliesslich den Inhalt von `site/`; Forschungsdaten, Tests, Node/Python-Werkzeuge und Zugangsdaten gehören nicht in diesen Ordner.

## 1. Voraussetzungen

Der Zielhost benötigt:

- Apache mit aktivem `mod_rewrite`, `mod_headers` und erlaubten `.htaccess`-Overrides (`AllowOverride FileInfo Limit Options` oder `AllowOverride All`);
- eine gültige HTTPS-Konfiguration;
- PHP 8.4 mit `pdo_mysql`, `openssl`, `curl` und `mbstring`;
- MariaDB 10.6 mit `utf8mb4`; und
- Schreibrechte des PHP-Prozesses auf `site/storage/cache`, `site/storage/logs` und `site/storage/uploads`.

Empfohlene PHP-Grenzen für das Standardlimit von 5 MiB sind `upload_max_filesize=6M`, `post_max_size=7M`, `max_file_uploads=5`, `display_errors=Off`, `log_errors=On` und `session.use_strict_mode=1`. `upload_max_filesize` und `post_max_size` müssen etwas grösser als `UPLOAD_MAX_BYTES` sein; die Anwendung prüft die Nutzdatei zusätzlich selbst.

Vor jedem Release im Repository ausführen:

```powershell
npm.cmd run test:deploy
```

Die Prüfung lintet alle deploybaren PHP-Dateien und lehnt Test-Zugangsdaten, lokale Router, SQLite-/Quelldateien und Entwicklungswerkzeuge unter `site/` ab.

## 2. Release-Paket erstellen

Aus einem geprüften Commit entsteht ein Paket, dessen Wurzel direkt `index.php` enthält:

```powershell
git archive --format=zip --output=politiks-site.zip HEAD:site
```

Das Archiv enthält `.htaccess` und `.env.example`, aber keine echte `.env`, keine Uploads und keine Forschungsdatenbank. Den Archivinhalt in den vom Provider vorgegebenen DocumentRoot entpacken. Wenn der Provider einen festen Ordner wie `public_html` verlangt, liegt `index.php` anschliessend direkt in `public_html/`.

Sinnvolle Rechte auf einem Unix-Host:

```text
Dateien                         0644
Verzeichnisse                   0755
site/.env                       0600 oder 0640
storage/cache|logs|uploads      schreibbar für den PHP-Prozess
```

Die Anwendung erzeugt Unterverzeichnisse für Uploads selbst. Der Webserver muss Zugriffe auf `backend/`, `database/`, `storage/`, `logs/`, `.env*`, `*.sql` und `*.log` mit 404/403 abweisen. Ohne wirksame `.htaccess`-Regeln darf das Release nicht freigegeben werden.

## 3. Produktionskonfiguration

Auf dem Host `.env.example` nach `.env` kopieren und alle Platzhalter ersetzen. Die Datei bleibt unter `site/`, ist nicht versioniert und darf nie über HTTP, Support-Tickets oder Logs geteilt werden.

Wesentliche Werte:

- `APP_ENV=production`
- `APP_URL`: exakte öffentliche HTTPS-Basisadresse ohne abschliessenden Slash, Query oder Fragment
- `APP_SECRET`: mindestens 32 Zeichen, empfohlen 32 kryptographisch zufällige Bytes als Hex
- `APP_SESSION_NAME`: eigener Cookie-Name
- `DB_*`: dedizierter Anwendungsbenutzer und Datenbank
- `GOOGLE_CLIENT_ID`: öffentliche ID eines Google-OAuth-Webclients
- `UPLOAD_MAX_BYTES`: 1024 bis 20971520 Bytes

Ein Secret lokal erzeugen:

```powershell
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Der Datenbankbenutzer benötigt zur Laufzeit `SELECT`, `INSERT`, `UPDATE` und `DELETE` auf der Politiks-Datenbank. Schemaänderungen sollten mit einem getrennten Deployment-Benutzer erfolgen. `POLITIKS_TEST_AUTH` und `POLITIKS_TEST_AUTH_BOOTSTRAP` dürfen in Produktion nicht gesetzt sein; die Konfiguration lehnt Testauthentifizierung in `APP_ENV=production` zusätzlich ab.

## 4. Google Sign-In

In Google Cloud einen OAuth-2.0-Client vom Typ **Webanwendung** erstellen. Unter „Autorisierte JavaScript-Ursprünge“ exakt den Origin aus `APP_URL` eintragen, beispielsweise `https://insights.example.org`—ohne Pfad und ohne abschliessenden Slash. Für dieses ID-Token-Verfahren wird kein Client-Secret in Politiks gespeichert.

Die Client-ID in `GOOGLE_CLIENT_ID` eintragen und `GOOGLE_JWKS_URL` auf `https://www.googleapis.com/oauth2/v3/certs` belassen. Änderungen an Domain, Scheme oder Port erfordern einen passenden zusätzlichen Google-Origin. Nach dem Deployment eine echte Anmeldung testen; ein funktionierender Testadapter beweist nicht, dass die Google-Origin-Konfiguration stimmt.

## 5. Optionale KI-Vorauswahl

Die Funktion bleibt im Release mit `AI_FILTER_ENABLED=0` ausgeschaltet. Vor einer Aktivierung müssen der Datenschutztext aus `DATENSCHUTZ_KI.md`, ein separates OpenAI-Produktionsprojekt, Kosten-/Ratenlimits und ein Zielhost-Smoke freigegeben sein.

### Schlüssel und Projekt

1. In der OpenAI-Plattform ein eigenes Projekt nur für die Politiks-Produktion anlegen; Entwicklung und Produktion nicht denselben Schlüssel verwenden.
2. Einen Projekt- oder Service-Account-Schlüssel erzeugen und seine Berechtigungen auf den benötigten Responses-Zugriff beschränken. Keine Admin-API-Schlüssel verwenden.
3. Den Schlüssel ausschliesslich serverseitig als `OPENAI_API_KEY` in `site/.env` speichern. Er gehört nie in JavaScript, Git, ein Release-Archiv, einen Screenshot oder ein Support-Ticket. Die aktuellen OpenAI-Hinweise verlangen ebenfalls serverseitige Umgebungsvariablen und empfehlen getrennte Projektschlüssel ([API-Key-Sicherheit](https://help.openai.com/en/articles/5112595-best-practices-for-api-key-safety), [Projekttrennung](https://help.openai.com/en/articles/5008148)).
4. Im OpenAI-Projekt nur das freigegebene Modell erlauben und dessen Rate Limits setzen. Das Projektbudget ist zusätzlich als Warn- und Beobachtungsgrenze nützlich, ersetzt aber das anwendungsseitige Stundenlimit nicht.

Empfohlener Startpunkt ist `gpt-5.6-luna`, weil OpenAI dieses Responses-/Structured-Outputs-fähige Modell für kostenempfindliche hohe Last beschreibt. Modellverfügbarkeit und Preise sind vor jeder Aktivierung in der [aktuellen Modellübersicht](https://developers.openai.com/api/docs/models) zu prüfen; ein Modellwechsel erfordert die Evaluation aus `classification/ai-filter/v1.de.json` und einen neuen Zielhost-Smoke.

Konfiguration mit konservativen Startwerten:

```dotenv
AI_FILTER_ENABLED=0
OPENAI_API_KEY=privater-projektschluessel
OPENAI_RESPONSES_URL=https://api.openai.com/v1/responses
OPENAI_MODEL=gpt-5.6-luna
AI_FILTER_TIMEOUT_SECONDS=30
AI_FILTER_MAX_OUTPUT_TOKENS=4096
AI_FILTER_CANDIDATE_LIMIT=300
AI_FILTER_CHUNK_SIZE=75
AI_FILTER_CACHE_TTL_SECONDS=3600
AI_FILTER_HOURLY_LIMIT=10
```

`OPENAI_RESPONSES_URL` akzeptiert nur den offiziellen globalen oder einen zweibuchstabigen regionalen OpenAI-Responses-Endpunkt. `AI_FILTER_HOURLY_LIMIT` begrenzt nicht gecachte Läufe pro Nutzer tatsächlich; Kandidatenlimit, Blockgrösse und Ausgabetokens begrenzen die Kosten je Lauf. Der 30-Sekunden-Timeout verhindert unbegrenzt belegte PHP-Worker. Erst nach allen Freigaben `AI_FILTER_ENABLED=1` setzen. Die nächste PHP-Anfrage liest den Schalter neu; bei persistenten Spezial-Setups zusätzlich PHP-FPM/Apache beziehungsweise den Provider-App-Cache neu laden.

### Datenschutz und Datenkontrollen

Die UI und `DATENSCHUTZ_KI.md` nennen den exakten Datenumfang. Die Anwendung sendet das Kriterium und erforderliche öffentliche Parlamentsfelder, aggregierte Kohortenzahlen sowie eine pseudonyme Safety-ID. Google-Identität, Ratsmitgliedernamen, Einzelstimmen, Uploads, Kampagnenmaterial und nicht benötigte Insight-Texte werden ausgeschlossen. Jede Responses-Anfrage setzt `store=false`. Nach den aktuellen [OpenAI Data Controls](https://platform.openai.com/docs/models/default-usage-policies-by-endpoint) werden API-Daten nicht zum Training verwendet, sofern der Kunde nicht ausdrücklich opt-in wählt; standardmässige Missbrauchslogs können Kundeninhalt dennoch bis zu 30 Tage enthalten. Zero Data Retention oder Modified Abuse Monitoring darf deshalb nur behauptet werden, wenn es für das eingesetzte OpenAI-Projekt tatsächlich freigeschaltet und kontrolliert wurde.

### Evaluation und expliziter Live-Smoke

Die normale, kostenlose Evaluation verwendet ausschliesslich versionierte lokale Ergebnisse:

```powershell
npm.cmd run test:ai-eval
```

Ein echter Provider-Smoke ist absichtlich nicht Teil von `verify` oder `verify:clean`. Für genau einen kostenpflichtigen Entwicklungsaufruf `.env.ai-smoke.example` nach `.env.ai-smoke` kopieren, einen separaten Entwicklungsschlüssel als `OPENAI_DEVELOPMENT_API_KEY` eintragen und explizit starten:

```powershell
npm.cmd run test:ai-live
```

Das Skript akzeptiert keine Produktions-`.env` und liest nie `OPENAI_API_KEY`. Ohne `.env.ai-smoke` beziehungsweise Entwicklungsschlüssel meldet es `skipped` und führt null externe Anfragen aus.

### Sichere Betriebsdaten, Rotation und Notabschaltung

`ai_filter_run` ist das Betriebsprotokoll. Es enthält Request-ID, Hashes, Status, Cachetreffer, Laufzeit, Kandidaten-/Trefferzahlen, Tokenzahlen, Modell und über `prompt_template_id` die Prompt-Version; Klartextkriterium, Kandidatentext, Google-Identität und Kampagnenmaterial fehlen. Sichere aggregierte Tagesmetriken lassen sich beispielsweise so prüfen:

```sql
SELECT DATE(run.created_at) day, run.model, prompt.version prompt_version,
       run.status, run.cache_hit, COUNT(*) runs,
       ROUND(AVG(run.latency_ms)) avg_latency_ms,
       SUM(COALESCE(run.input_tokens,0)) input_tokens,
       SUM(COALESCE(run.output_tokens,0)) output_tokens
FROM ai_filter_run run
JOIN ai_prompt_template prompt ON prompt.id=run.prompt_template_id
WHERE run.created_at>=UTC_TIMESTAMP()-INTERVAL 7 DAY
GROUP BY DATE(run.created_at), run.model, prompt.version, run.status, run.cache_hit
ORDER BY day DESC, run.model, run.status;
```

Diese Zahlen gegen OpenAI Usage/Costs und die projektspezifischen Limits abgleichen. Keine Rohrequests in Apache-/PHP-Debuglogs aufnehmen. Abgelaufene Cachezeilen regelmässig löschen; die Aufbewahrungsdauer der Laufmetadaten betrieblich festlegen und dokumentieren.

Für eine Rotation zuerst einen neuen Projektschlüssel erzeugen, `OPENAI_API_KEY` serverseitig ersetzen, einen Smoke durchführen und erst dann den alten Schlüssel in der OpenAI-Plattform löschen. Bei Verdacht auf Offenlegung sofort `AI_FILTER_ENABLED=0` setzen und den kompromittierten Schlüssel löschen; die normalen Filter und die Evidenzauswahl bleiben verfügbar. Zusätzlich OpenAI-Usage, `ai_filter_run`-Status/Token und Webserverzugriffe ab dem Verdachtszeitpunkt prüfen.

## 6. Datenbank und Referenzdaten

Vor dem ersten Start ein Backupziel festlegen. Danach das idempotente Schema aus dem vollständigen Repository gegen die Produktionskonfiguration anwenden:

```powershell
php scripts/bootstrap_mariadb.php --env=site/.env
```

`--reset` ist für Produktionsdateien technisch gesperrt. Alternativ kann ein Provider-Importwerkzeug `database/mariadb/schema.sql` mit einem DDL-berechtigten Benutzer importieren.

Wenn phpMyAdmin den vollständigen Produktionsdump wegen des Upload-Limits nicht annimmt, stehen unter `database/exports/` fünf jeweils eigenständig eingerahmte `.sql.gz`-Teile bereit. In phpMyAdmin zuerst die leere Zieldatenbank auswählen und danach `part-01-of-05` bis `part-05-of-05` genau einmal in numerischer Reihenfolge importieren. Jeder Teil setzt die für seine separate HTTP-Sitzung erforderlichen Zeichensatz-, Zeitzonen- und Fremdschlüsseloptionen selbst. Die Dateien nicht über eine teilweise importierte Datenbank erneut ausführen: Nach einem Fehler die leere Zieldatenbank beziehungsweise das Backup wiederherstellen und bei Teil 01 neu beginnen. Dateinamen, Prüfsummen und der lokale Verifikationsbefehl stehen in `database/exports/README.md`.

Die Schweizer Referenzdaten werden nicht über HTTP geladen. Zuerst lokal die vollständige, checksum-geprüfte SQLite-Forschungsdatenbank neu erzeugen und klassifizieren. Dann von einer vertrauenswürdigen Maschine mit Datenbankzugriff atomar publizieren:

```powershell
php scripts/publish_reference_data.php --env=site/.env --sqlite=database/parliament.sqlite
php scripts/verify_reference_publication.php --env=site/.env
```

Nur eine vollständig verifizierte Full-Snapshot-Datenbank darf produktiv publiziert werden. Der Publisher schreibt eine neue unveränderliche Referenzpublikation in einer Transaktion und aktiviert sie erst nach Abgleich aller Tabellen. Bereits gespeicherte Insights bleiben an ihre ursprüngliche Publikation gebunden.

## 7. Release aktivieren und prüfen

Bei Shared Hosting die Dateien zuerst in ein separates Release-Verzeichnis hochladen und erst nach erfolgreicher Prüfung den DocumentRoot beziehungsweise den Provider-Alias umstellen, soweit der Anbieter dies unterstützt. `.env` und `storage/` sind releaseübergreifend zu erhalten; nie mit einem leeren Archiv überschreiben.

Prüfliste auf dem echten HTTPS-Origin:

```text
GET /                                      200, deutsche Landingpage
GET /api/auth-config                       200, erwartete öffentliche Client-ID
GET /api/session                           200, authenticated=false
GET /backend/Config.php                    404 oder 403
GET /database/schema.sql                   404 oder 403
GET /storage/README.md                     404 oder 403
GET /.env                                  404 oder 403
GET /assets/app.css                        200, Cache-Control vorhanden
```

Zusätzlich in den Response-Headern CSP, `X-Content-Type-Options: nosniff`, Referrer-Policy und auf HTTPS `Strict-Transport-Security` prüfen. Danach mit einem echten Google-Konto anmelden, einen Entwurf erstellen, eine Bilddatei hochladen, den geschützten Abruf prüfen und den Insight wieder löschen. Dabei müssen auch der Datenbankdatensatz und die geschützte Bilddatei entfernt werden. PHP- beziehungsweise Apache-Logs dürfen weder Token noch Konfigurationswerte enthalten.

Falls die KI-Funktion freigegeben wurde: Step 3 öffnen, einen klaren Evaluationsfall ausführen, Request-ID und aggregierten Laufdatensatz prüfen, den Filter entfernen und bestätigen, dass dabei keine Evidenz ausgewählt wurde. Timeout-/Fehleranzeige und `AI_FILTER_ENABLED=0` als Notabschaltung ebenfalls auf dem Zielhost testen.

## 8. Backup und Wiederherstellung

Zusammengehörig sichern:

1. MariaDB mit Transaktionskonsistenz, Routinen/Triggern und `utf8mb4`;
2. den vollständigen Inhalt von `site/storage/uploads/`;
3. die Produktions-`.env` separat verschlüsselt; und
4. Commit-ID beziehungsweise Release-Archiv.

Beispiel für einen Datenbankdump (Passwort nicht in die Kommandozeile schreiben; Provider-Login-Datei oder interaktive Eingabe verwenden):

```text
mysqldump --single-transaction --quick --routines --triggers --default-character-set=utf8mb4 DATENBANK > politiks-YYYYMMDD.sql
```

Für eine konsistente Upload-Sicherung während Schreibverkehr entweder ein Provider-Snapshot verwenden oder ein kurzes Wartungsfenster einplanen. Wiederherstellung zuerst in einer separaten Datenbank testen: Dump importieren, Uploads zurückspielen, `.env` auf die Testdatenbank richten, Referenzpublikation verifizieren und die Smoke-Checks aus Abschnitt 7 ausführen.

## 9. Rollback

Vor Schema- oder Anwendungsänderungen immer Datenbank und Uploads sichern. Bei einem reinen Codefehler das vorherige Release wieder aktivieren und dieselbe `.env` sowie dasselbe `storage/` weiterverwenden. Datenbankschemaänderungen sind vorwärtskompatibel und werden nicht blind zurückgerollt; wenn eine Migration Daten verändert hat, den getesteten Komplett-Backupstand in einer neuen Datenbank wiederherstellen und erst danach umschalten.

Eine fehlerhafte neue Referenzpublikation wird nicht durch Löschen repariert. Wenn sie bereits aktiv ist, zunächst Ursache und Prüfergebnis dokumentieren. Die Aktivierung kann in einem kontrollierten Datenbank-Wartungsfenster auf eine verifizierte ältere Publikations-ID zurückgesetzt werden; sicherer ist die Wiederherstellung des unmittelbar davor erstellten Backups. Danach stets `verify_reference_publication.php` ausführen.

Die KI-Vorauswahl hat einen unabhängigen, schemafreien Rollback: `AI_FILTER_ENABLED=0` deaktiviert den UI-Einstieg und den API-Service, ohne Insights, Evidenz oder Referenzdaten zu ändern. Bei semantischer Verschlechterung, unerwarteten Kosten oder Providerstörung zuerst diesen Schalter setzen. Code und Modell erst nach bestandener Evaluation wieder aktivieren; Cachezeilen dürfen bei Bedarf nach Sicherung und dokumentierter Ursache geleert werden.

## 10. Bekannte hostabhängige Prüfpunkte

Die lokale Entwicklung verwendet derzeit PHP 8.2.30 ohne alle produktiven Erweiterungen und einen MySQL-kompatiblen Testserver. Vor Freigabe bleiben deshalb auf dem Zielhost zwingend zu bestätigen: PHP 8.4, MariaDB 10.6.18, OpenSSL/cURL/mbstring/PDO-MySQL, wirksame `.htaccess`-Overrides, HTTPS/HSTS, Dateirechte, Google-Origin und ein echter Google-Login. Für jeden Fehlschlag sind Provider-Einstellung, beobachteter Header/Fehler und die getestete Korrektur im Release-Protokoll festzuhalten.
