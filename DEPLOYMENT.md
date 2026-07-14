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

## 5. Datenbank und Referenzdaten

Vor dem ersten Start ein Backupziel festlegen. Danach das idempotente Schema aus dem vollständigen Repository gegen die Produktionskonfiguration anwenden:

```powershell
php scripts/bootstrap_mariadb.php --env=site/.env
```

`--reset` ist für Produktionsdateien technisch gesperrt. Alternativ kann ein Provider-Importwerkzeug `site/database/schema.sql` mit einem DDL-berechtigten Benutzer importieren.

Die Schweizer Referenzdaten werden nicht über HTTP geladen. Zuerst lokal die vollständige, checksum-geprüfte SQLite-Forschungsdatenbank neu erzeugen und klassifizieren. Dann von einer vertrauenswürdigen Maschine mit Datenbankzugriff atomar publizieren:

```powershell
php scripts/publish_reference_data.php --env=site/.env --sqlite=database/parliament.sqlite
php scripts/verify_reference_publication.php --env=site/.env
```

Nur eine vollständig verifizierte Full-Snapshot-Datenbank darf produktiv publiziert werden. Der Publisher schreibt eine neue unveränderliche Referenzpublikation in einer Transaktion und aktiviert sie erst nach Abgleich aller Tabellen. Bereits gespeicherte Insights bleiben an ihre ursprüngliche Publikation gebunden.

## 6. Release aktivieren und prüfen

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

Zusätzlich in den Response-Headern CSP, `X-Content-Type-Options: nosniff`, Referrer-Policy und auf HTTPS `Strict-Transport-Security` prüfen. Danach mit einem echten Google-Konto anmelden, einen Entwurf erstellen, eine Bilddatei hochladen, den geschützten Abruf prüfen und wieder archivieren. PHP- beziehungsweise Apache-Logs dürfen weder Token noch Konfigurationswerte enthalten.

## 7. Backup und Wiederherstellung

Zusammengehörig sichern:

1. MariaDB mit Transaktionskonsistenz, Routinen/Triggern und `utf8mb4`;
2. den vollständigen Inhalt von `site/storage/uploads/`;
3. die Produktions-`.env` separat verschlüsselt; und
4. Commit-ID beziehungsweise Release-Archiv.

Beispiel für einen Datenbankdump (Passwort nicht in die Kommandozeile schreiben; Provider-Login-Datei oder interaktive Eingabe verwenden):

```text
mysqldump --single-transaction --quick --routines --triggers --default-character-set=utf8mb4 DATENBANK > politiks-YYYYMMDD.sql
```

Für eine konsistente Upload-Sicherung während Schreibverkehr entweder ein Provider-Snapshot verwenden oder ein kurzes Wartungsfenster einplanen. Wiederherstellung zuerst in einer separaten Datenbank testen: Dump importieren, Uploads zurückspielen, `.env` auf die Testdatenbank richten, Referenzpublikation verifizieren und die Smoke-Checks aus Abschnitt 6 ausführen.

## 8. Rollback

Vor Schema- oder Anwendungsänderungen immer Datenbank und Uploads sichern. Bei einem reinen Codefehler das vorherige Release wieder aktivieren und dieselbe `.env` sowie dasselbe `storage/` weiterverwenden. Datenbankschemaänderungen sind vorwärtskompatibel und werden nicht blind zurückgerollt; wenn eine Migration Daten verändert hat, den getesteten Komplett-Backupstand in einer neuen Datenbank wiederherstellen und erst danach umschalten.

Eine fehlerhafte neue Referenzpublikation wird nicht durch Löschen repariert. Wenn sie bereits aktiv ist, zunächst Ursache und Prüfergebnis dokumentieren. Die Aktivierung kann in einem kontrollierten Datenbank-Wartungsfenster auf eine verifizierte ältere Publikations-ID zurückgesetzt werden; sicherer ist die Wiederherstellung des unmittelbar davor erstellten Backups. Danach stets `verify_reference_publication.php` ausführen.

## 9. Bekannte hostabhängige Prüfpunkte

Die lokale Entwicklung verwendet derzeit PHP 8.2.30 ohne alle produktiven Erweiterungen und einen MySQL-kompatiblen Testserver. Vor Freigabe bleiben deshalb auf dem Zielhost zwingend zu bestätigen: PHP 8.4, MariaDB 10.6.18, OpenSSL/cURL/mbstring/PDO-MySQL, wirksame `.htaccess`-Overrides, HTTPS/HSTS, Dateirechte, Google-Origin und ein echter Google-Login. Für jeden Fehlschlag sind Provider-Einstellung, beobachteter Header/Fehler und die getestete Korrektur im Release-Protokoll festzuhalten.
