# Deployable web application

Everything required at runtime on the Apache/PHP host must live under this directory, with `index.php` at its root.

Production secrets belong in ignored `site/.env`. The committed Apache rules deny HTTP access to environment files, backend internals, database scripts, logs, and private upload storage.

`database/schema.sql` is a deployment artifact, not an HTTP installer. Apply it only with the repository-level CLI bootstrap command.

Runtime structure:

- `index.php` and `router.php`: Apache front controller and local PHP-server router.
- `backend/`: framework-free configuration, HTTP, session/CSRF, persistence, and Google token verification; never public.
- `assets/`: local application, Bootstrap 5.3.8, and Bootstrap Icons 1.13.1 assets.
- `database/`: protected schema contract.
- `storage/`: protected runtime cache, log, and upload roots.
- `.htaccess`: routing and deny rules for every internal path above.

Google Identity Services remains remotely hosted because Google does not support self-hosting that library. All ordinary UI assets are local. The response CSP permits only the narrowly required Google script/style/frame/connect endpoints, the Google profile-image host, and generated privacy-enhanced YouTube frames in addition to same-origin resources. User-supplied hosts never enter the CSP or become arbitrary embeds.
