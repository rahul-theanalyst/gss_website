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
                          (Brevo API, or a real SMTP mailbox, or mail())
```

Shared code lives in `server/lib/`:

- `env.php` — tiny `.env` file loader (no Composer dependency)
- `config.php` — merges environment variables → `server/.env` →
  legacy `server/careers-config.php` → hardcoded defaults
- `spam-guard.php` — honeypot + submission-timing checks
- `mailer.php` — builds the message and sends it, trying three
  transports in order (first one configured and successful wins):
  1. **Brevo** transactional email API — recommended, see below
  2. A real SMTP mailbox, if you configure one
  3. PHP's `mail()` — only works if the host has a local MTA

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
you're ready to go live, change these two lines to
`contact@globalsoftsystems.com,contact@gsspros.com` — nothing else in
the codebase needs to change.

**Recipients never need a password.** They're just destination
addresses; nothing here logs into their inbox.

## 2. Send mail via Brevo (no personal email account)

Why this is needed at all: sending mail always requires proving to
some outgoing server who's allowed to send — otherwise anyone could
send mail pretending to be anyone else. A real hosting server usually
has that built in (see "Deploying later" below), but this project's
local dev server (`php -S`) does not. Brevo's API stands in for that:
you authenticate with a service API key, not a personal inbox or its
password.

1. Sign up at <https://app.brevo.com/> — free, no credit card
   required. Any email address works for the account itself; it's
   just how you log in to Brevo's dashboard, not a credential this
   app ever uses.
2. Verify a sender address: **Senders, Domains & Dedicated IPs →
   Senders → Add a sender.** Enter a name and an email address you
   can receive mail at right now — one of your two test addresses is
   fine. Brevo emails that address a 6-digit code; enter it to
   confirm. This is a one-time confirmation code, not a password, and
   Brevo never asks for or stores that inbox's password.
3. Create an API key: **Settings (gear icon) → SMTP & API → API
   Keys → Generate a new API key.** Copy it — Brevo only shows it
   once.
4. Fill in `server/.env`:

   ```ini
   BREVO_ENABLED=true
   BREVO_API_KEY=xkeysib-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
   BREVO_SENDER_EMAIL=the-address-you-verified-in-step-2@example.com
   BREVO_SENDER_NAME=GSS Website
   ```

5. Submit either form locally. Check both recipient inboxes —
   including spam/junk, since the sender domain isn't authenticated
   yet (step 6 fixes that). If nothing arrives, check
   `server/careers-submissions.log` / `server/contact-submissions.log`,
   which record Brevo's exact rejection reason.

Free plan: 300 emails/day, no credit card, no time limit — comfortably
enough for both forms.

### Optional, for later: domain authentication

Not required for the forms to work, but recommended before switching
to the official addresses and going live, since Gmail and Yahoo now
require it for reliable inbox delivery: **Senders, Domains &
Dedicated IPs → Domains → Add a domain** (e.g.
`globalsoftsystems.com`) → add the DKIM/SPF/Brevo-code DNS records it
gives you, wherever that domain's DNS is managed (its registrar, or
HostGator's cPanel DNS zone editor). Once verified, every sender
`@globalsoftsystems.com` is automatically trusted — you could then
set `BREVO_SENDER_EMAIL=noreply@globalsoftsystems.com` instead of a
personal address.

## 3. Deploying later (HostGator or any host)

Not needed yet — this is set up for local testing only. When it's
time to deploy:

- Brevo keeps working exactly the same from a real host — no change
  needed beyond setting `BREVO_ENABLED`/`BREVO_API_KEY`/
  `BREVO_SENDER_EMAIL` there too (ideally as real environment
  variables through the host's control panel, e.g. cPanel's "PHP
  Environment Variables" page, rather than a committed file —
  `server/lib/config.php` already prefers real environment variables
  over `.env` automatically).
- Many hosts, including HostGator, also provide a working local mail
  agent, so leaving `BREVO_ENABLED=false` and just letting `mail()`
  run may work there without any external service at all — worth
  trying once deployed.
- `server/.env` itself can also be uploaded directly with production
  values if you'd rather not use the host's environment-variable UI —
  it's still gitignored, so it never goes through git either way.

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
