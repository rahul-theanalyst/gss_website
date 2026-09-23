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
                                    (PHPMailer: a real SMTP mailbox,
                                     or the hosting server's mail())
```

Shared code lives in `server/lib/`:

- `env.php` — tiny `.env` file loader (no Composer dependency)
- `config.php` — merges environment variables → `server/.env` →
  legacy `server/careers-config.php` → hardcoded defaults. Also
  loads the Ceipal careers API credentials the same way.
- `spam-guard.php` — honeypot + submission-timing checks
- `mailer.php` — builds the message and sends it via PHPMailer
  (vendored under `server/lib/PHPMailer/`, no Composer): a real SMTP
  mailbox if one's configured, otherwise the hosting server's own
  `mail()` — which only works if the host has a local mail agent
  (`php -S`, this project's local dev server, does not).

## 1. Configure recipients

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
addresses; nothing here logs into their inbox.

## 2. Sending mail

Why this needs any setup at all: sending mail always requires
proving to some outgoing server who's allowed to send — otherwise
anyone could send mail pretending to be anyone else. A real hosting
server usually has that built in; this project's local dev server
(`php -S`) does not, so local testing alone can validate the forms
but can't deliver a real email (confirmed: `server/careers-submissions.log`
/ `server/contact-submissions.log` record `NOT_SENT_MTA_OFFLINE` for
every local attempt with `SMTP_ENABLED=false`).

Two ways to actually deliver mail, in order of preference:

### Preferred: the hosting server's own mail()

Leave `SMTP_ENABLED=false` in `server/.env`. On a real host like
HostGator, PHP's `mail()` is usually backed by a working local mail
agent already configured for the domain — no mailbox password, no
external service, nothing else to set up. This is what
`server/.env.gss_newsite` (the deployment file — see below) is
configured to use.

### Alternative: a real SMTP mailbox you control

If `mail()` doesn't deliver reliably (or lands in spam), use the
credentials of a real mailbox you administer — e.g.
`contact@globalsoftsystems.com` once it exists on the host. In
cPanel: **Email Accounts → (the address) → Connect Devices** shows
its SMTP host/port. Fill in `server/.env`:

```ini
SMTP_ENABLED=true
SMTP_HOST=mail.globalsoftsystems.com
SMTP_PORT=465
SMTP_ENCRYPTION=ssl
SMTP_USERNAME=contact@globalsoftsystems.com
SMTP_PASSWORD=<that mailbox's real password>
```

Never put a personal Gmail/Outlook account password here — this is
specifically for a mailbox on the company's own domain that you
administer for this purpose.

## 3. Deploying to HostGator

`server/.env` and `server/careers-config.php` are gitignored and
never travel through git — they have to be created directly on the
server.

**`server/.env.gss_newsite`** in this repo is the deployment-ready
version: real Ceipal API credentials, official recipients, and
`SMTP_ENABLED=false` (the hosting server's own `mail()`, no mailbox
password). To use it:

1. Upload it to the server, into the `server/` directory.
2. Rename it to `.env` there (or upload it directly under that name).

Prefer setting these as real environment variables through the
host's own control panel (e.g. cPanel's "PHP Environment Variables"
page) over a committed file where possible — `server/lib/config.php`
already prefers real environment variables over `.env` automatically,
so nothing else changes either way.

## 4. Ceipal careers API credentials

`server/careers-jobs.php` (the live job listings on `html/career.html`)
reads its Ceipal `api_key`/`cp_id` through the same `server/.env`
mechanism as mail settings — nothing is hardcoded in any `.php` file:

```ini
CEIPAL_API_KEY=...
CEIPAL_CP_ID=...
```

From the Ceipal widget embed tag: `data-ceipal-api-key="..."` →
`CEIPAL_API_KEY`, `data-ceipal-career-portal-id="..."` →
`CEIPAL_CP_ID`. See `docs/CEIPAL-INTEGRATION.md` for the full
integration details.

## 5. Spam protection

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
