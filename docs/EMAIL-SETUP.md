# Email setup — Careers and Let's Connect forms

Both forms (`html/career.html`'s "Stay connected" panel and
`html/contact.html`'s "Let's Connect" form) submit via JavaScript
`fetch()` to a PHP endpoint in `server/`, which validates the input,
screens it for basic spam, and emails it out.

```text
html/career.html   ──POST──▶  server/careers-submit.php   ──┐
html/contact.html  ──POST──▶  server/contact-submit.php   ──┤
                                                              ▼
                                              server/lib/mailer.php
                                          (SMTP if configured, else mail())
```

Shared code lives in `server/lib/`:

- `env.php` — tiny `.env` file loader (no Composer dependency)
- `config.php` — merges environment variables → `server/.env` →
  legacy `server/careers-config.php` → hardcoded defaults
- `spam-guard.php` — honeypot + submission-timing checks
- `mailer.php` — builds the MIME message and sends it via SMTP or
  PHP's `mail()`

## 1. Configure recipients and credentials

Copy the template once:

```bash
cp server/.env.example server/.env
```

`server/.env` is gitignored — it never gets committed. Open it and
set:

```ini
CAREERS_RECIPIENTS=you@example.com,teammate@example.com
CONTACT_RECIPIENTS=you@example.com,teammate@example.com
```

Two comma-separated addresses per form, sent to simultaneously. When
you're ready to go live, change these two lines to the official
addresses — nothing else in the codebase needs to change.

**Recipients never need a password.** They're just destination
addresses; nothing you configure here logs into their inbox. The only
credential involved is for the *sending* account below.

## 2. Enable real delivery (local testing)

This project's dev server (`php -S`, see the root `README.md`) has no
mail transport of its own. With `SMTP_ENABLED=false`, both endpoints
still validate correctly and log every submission to
`server/careers-submissions.log` / `server/contact-submissions.log`,
but no email actually gets sent — PHP's `mail()` has nothing to hand
the message to.

To actually receive mail locally, relay through a real SMTP account.
**Gmail with an App Password** is the fastest path if you don't
already have another provider in mind:

1. Turn on 2-Step Verification on the Google account you'll send
   from, if it isn't already: <https://myaccount.google.com/security>
2. Create an App Password: <https://myaccount.google.com/apppasswords>
   → app "Mail", device "Other", name it e.g. "GSS local dev" → copy
   the 16-character password it generates.
3. Fill in `server/.env`:

   ```ini
   SMTP_ENABLED=true
   SMTP_HOST=smtp.gmail.com
   SMTP_PORT=587
   SMTP_ENCRYPTION=tls
   SMTP_USERNAME=your.address@gmail.com
   SMTP_PASSWORD=xxxxxxxxxxxxxxxx
   ```

   Use the 16-character App Password here, not your normal Gmail
   password — Google rejects normal-password SMTP logins outright.

4. Restart the PHP dev server if it's running, then submit either
   form. Check the recipient inbox(es) — and if delivery fails, check
   `server/careers-submissions.log` / `server/contact-submissions.log`,
   which record the SMTP server's exact rejection reason.

Any other SMTP provider (Outlook/Microsoft 365, SendGrid, Mailgun,
Amazon SES, your host's own SMTP, ...) works the same way — just
point `SMTP_HOST`/`SMTP_PORT`/`SMTP_ENCRYPTION`/`SMTP_USERNAME`/
`SMTP_PASSWORD` at that provider's values.

If you'd rather not use a real personal inbox as the sender while
testing, a sandbox SMTP service (e.g. Mailtrap, Ethereal) works too —
point the same `SMTP_*` variables at it. Mail will land in that
service's dashboard instead of a real inbox, which is fine for
checking the layout but won't reach `CONTACT_RECIPIENTS` for real.

## 3. Deploying later (HostGator or any host)

Not needed yet — this is set up for local testing only, as requested.
When it's time to deploy:

- Prefer setting `CAREERS_RECIPIENTS`, `CONTACT_RECIPIENTS`,
  `SMTP_*`, etc. as real environment variables through the host's own
  control panel (cPanel's "PHP Environment Variables" page under
  MultiPHP INI Editor / Software, or an `.htaccess` `SetEnv` line) —
  `server/lib/config.php` already prefers real environment variables
  over `.env` automatically, no code change required.
- Alternatively, upload a `server/.env` with production values
  directly (it's still gitignored, so it never went through git) —
  just make sure it's not web-readable (this project's `server/`
  files are all `.php`, and `.env` itself isn't served by PHP, but
  keeping it outside the web root on a shared host is best practice
  if the host allows it).
- Many hosts, including HostGator, provide a working local MTA, so
  `SMTP_ENABLED=false` (plain `mail()`) may just work there even
  without SMTP credentials — worth trying before configuring SMTP.

## 4. Spam protection

Both forms carry two lightweight, dependency-free checks (no
CAPTCHA, no external service):

- **Honeypot** — a field named `hp_website`, hidden off-screen in the
  page (not just `display:none`) and never shown to a real visitor.
  Simple bots that auto-fill every input trip it; the server rejects
  any submission where it arrives non-empty.
- **Timing** — the page stamps `form_rendered_at` (a timestamp) via
  JavaScript when the form becomes available. A submission that
  arrives less than 3 seconds later is treated as scripted, not a
  person reading the form.

Both endpoints respond with the normal success message when a spam
check trips (so a scripted retry doesn't learn anything from the
rejection) but send no email; the block is recorded in the
submissions log for visibility. A visitor with JavaScript disabled
simply won't send a timestamp, and the timing check is skipped for
them rather than rejecting a genuine submission.
