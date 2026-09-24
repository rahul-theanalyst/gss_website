# Email setup — Careers and Let's Connect forms

Both forms (`html/career.html`'s "Stay connected" panel and
`html/contact.html`'s "Let's Connect" form) post to a PHP endpoint in
`server/`, which validates the input, emails it with **PHP's native
`mail()`**, and redirects back to the form page:

```text
html/career.html   ──POST──▶  server/careers-submit.php  ──┐
html/contact.html  ──POST──▶  server/contact-submit.php  ──┤  mail()
                                                             ▼
                      302 → html/<form page>?status=success | ?status=error
```

No SMTP, no mailbox password, no mail library. Shared code:

- `server/lib/form-mail.php` — recipients, From address, the `mail()`
  call (with the résumé as an attachment for Careers), and the redirect
- `server/lib/spam-guard.php` — honeypot + submission-timing checks

## 1. Recipients and sender

Set in `server/lib/form-mail.php` (not in the frontend):

```php
const FORM_MAIL_TO   = 'erah@globalsoftsystems.com, erah@gsspros.com';
const FORM_MAIL_FROM = 'noreply@globalsoftsystems.com';
```

`From` and `Reply-To` are both `FORM_MAIL_FROM`, and it's also used as
the envelope sender (`-f`). It must be an address on the domain hosted
on the same server (globalsoftsystems.com on HostGator), or Outlook and
others are likely to reject or spam-file the message. The noreply
mailbox doesn't need to exist. The visitor's own address is in the
email body ("Reply to … at: …"), since Reply-To is noreply.

## 2. How the result reaches the page

- The page's JavaScript sends the form in the background and follows
  the redirect; `?status=success` shows the green message under the
  form (and clears it), anything else shows the red one (fields kept).
- A plain form post (no background request) lands on the form page
  with `?status=…` in the address; the page shows the same message and
  removes the parameter from the URL.
- `status=success` means `mail()` returned true: the server's mail
  agent **accepted** the message. Arrival in the inbox happens after
  that; check the inbox and spam folder. On HostGator, cPanel →
  **Track Delivery** shows what happened to each message.
- Every submission is logged in `server/careers-submissions.log` /
  `server/contact-submissions.log` (blocked by `server/.htaccess`).

**Local testing:** `mail()` needs a mail agent on the machine. The
local `php -S` server on Windows has none, so locally every valid
submission ends in `?status=error` (logged as `mail(): FAILED`). Real
sending only happens on the hosting server.

## 3. Deploying to HostGator

Upload the site as usual; there is nothing to configure for email.
`server/.env` (uploaded as `.env.gss_newsite`, renamed) now only holds
the Ceipal careers API keys — see below.

## 4. Ceipal careers API credentials

`server/careers-jobs.php` (the live job listings on `html/career.html`)
reads its Ceipal `api_key`/`cp_id` through `server/.env`
(see `server/.env.example`) — nothing is hardcoded in any `.php` file:

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

Both endpoints redirect with `status=success` when a spam
check trips (so a scripted retry doesn't learn anything from the
rejection) but send no email; the block is recorded in the
submissions log for visibility. A visitor with JavaScript disabled
simply won't send a timestamp, and the timing check is skipped for
them rather than rejecting a genuine submission.

## 6. Upload limits

The résumé must be a PDF (extension and `%PDF-` file signature
checked), max 10 MB. It is never saved: it goes from PHP's temporary
upload file straight into the email. `server/.user.ini` raises
`upload_max_filesize` to 10M and `post_max_size` to 12M on
PHP-FPM/CGI hosts such as HostGator.
