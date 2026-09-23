# PHPMailer (vendored, no Composer)

Source: <https://github.com/PHPMailer/PHPMailer>, tag `v7.1.1`.
License: LGPL-2.1 (see `LICENSE` in this folder) — usable in closed-source
projects; only PHPMailer's own source stays under LGPL, not this site's code.

Only the three files actually needed are kept, unmodified, under `src/`:

- `PHPMailer.php`
- `SMTP.php`
- `Exception.php`

This project has no build step (see the root `README.md`), so there's no
Composer/`vendor/` here — `server/lib/mailer.php` requires these three files
directly (`gss_load_phpmailer()`). To upgrade, replace these three files with
a newer tagged release's `src/*.php` and re-run `php -l` on each.
