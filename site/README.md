# Deployable web application

Everything required at runtime on the Apache/PHP host must live under this directory, with `index.php` at its root.

Production secrets belong in ignored `site/.env`. Apache rules added with the application foundation must deny HTTP access to environment files, backend internals, database scripts, logs, and private upload storage.
