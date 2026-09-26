# Ceipal careers integration

`html/career.html` loads `js/pages/careers.js`, which requests `server/careers-jobs.php` on the same
origin. PHP posts the supplied form fields to Ceipal and converts its `results`
into the existing job board format. Search, location filtering, role details,
and Apply buttons use that feed. Credentials stay in PHP, outside browser code.

## Deployment

1. Serve this project on PHP 8+ hosting with the cURL extension and outbound
   HTTPS access to `careerapi.ceipal.com` (the job list) and
   `candidateportal.ceipal.com` (per-job full description/skills/experience —
   see "Role details" below). A static host or VS Code Live Server cannot
   execute this integration.
2. Put the portal credentials in `server/.env.gss_newsite` (`CEIPAL_API_KEY`,
   `CEIPAL_CP_ID`). It is the only file that holds them, the code reads it
   directly, and it is gitignored — upload it to
   the server's `server/` directory separately, under the same name.
3. Upload the `html/`, `css/`, `js/` and `images/` folders, the root
   `index.html`, and the `server/` directory, preserving their folder structure.
   Allow PHP to write the cache beside the endpoint if caching is desired.
4. Open `/server/careers-jobs.php`. A successful response is JSON with a `jobs` array.
   Then open `/html/career.html#opportunities` and check a role's Apply link.

For local use, run the VS Code task **Start website (PHP + live careers)**
from **Terminal > Run Task**, then visit `http://127.0.0.1:8080/html/career.html`.
The equivalent command is `php -S 127.0.0.1:8080 -t .` from the project root.
If the preview is already running on port 8080, reuse it instead of starting
a second server.

The workspace's Live Server proxy forwards requests to this PHP server.
Restart Live Server once after changing its proxy settings to use port 5500.
Keep the PHP server running while using either preview URL. Live Server alone
serves `.php` as source instead of executing it, which prevents jobs from loading.

## Role details

Ceipal's job-list endpoint only ever returns a ~190-character teaser
description with no skills or experience. Each job's `View Role` panel needs
the full text, so `server/careers-jobs.php` makes one extra call per job — in
parallel, via `curl_multi` — to the candidate portal's own (unauthenticated)
job-description API, keyed by the opaque token at the end of that job's
`campus_portal_job_details_url`. This fills in the full description, the
skills list, and the experience range.

Ceipal's list feed no longer includes `campus_portal_job_details_url` for every job. When it's missing, the detail token (and the full description, pay text and minimum experience) come from the candidate portal's public job list, matched by job code (`fetchPortalJobIndex()`).

This enrichment is best-effort: if a job has no details URL, or its request
fails or times out, that job simply keeps its teaser description with no
skills/experience — the whole feed never fails because of it.

## Easy Apply

Roles that allow it in Ceipal (`apply_with_out_registration`) show two buttons
in their `View Role` panel:

- **Easy Apply** opens our own form (`js/pages/easy-apply.js`), styled like the
  rest of the site.
- **Apply Now** opens Ceipal's candidate portal in a new tab, for candidates
  who want a full Ceipal profile.

`server/careers-apply.php` sits between the form and Ceipal:

- `?action=form&job=<id>` loads that job's application form from Ceipal
  (`CareerPortalJobPostings/<id>/`), with its fields, required flags and
  dropdown options (Work Authorization, Tax Terms, Country, and so on). The
  page builds the form from this, so changes HR makes to the form in Ceipal
  show up on the site automatically. Definitions are cached for 15 minutes in
  the system temp directory.
- `?action=states&country=<id>` loads the State/County/Province list for the
  chosen country (`CareerPortalStates`), cached for a day.
- `POST action=submit` checks the application against that same definition
  (required fields, valid dropdown choices, email, phone, and a PDF/DOC/DOCX
  résumé up to 10 MB checked by file signature). It then sends the application
  to `CareerPortalApplyJobWithoutRegistrationCareerPage/`, the endpoint
  Ceipal's own career widget uses, so it arrives in Ceipal as a normal
  application.

Ceipal's own widget draws its CAPTCHA in the browser and never sends it to
Ceipal, and Ceipal's API has nothing to reuse: the form definition carries no
CAPTCHA, and the widget's own endpoint (`careerPortalWidget/`) refuses requests
from outside Ceipal's hosted career portal. So Easy Apply has its own CAPTCHA,
checked on the server:

- `?action=captcha` returns a distorted 5-character image (drawn with PHP's GD
  extension) and a signed token. The token is an HMAC of the answer and an
  expiry under a secret kept in `server/.captcha-secret` (generated on first
  use, gitignored, blocked by `server/.htaccess`). The answer itself is never
  stored.
- On submit, the typed answer must match the token within 10 minutes, and each
  token is accepted once. A wrong answer returns a field error and the form
  loads a new image. It is checked before the rate limit, so a typo doesn't
  use up an attempt.
- If a server lacks GD, the CAPTCHA falls back to a short arithmetic question,
  checked the same way.

Alongside it are the shared honeypot/timing guard (`lib/spam-guard.php`) and a
limit of 5 applications per IP address per 10 minutes. Applicant details are never
written to the logs. Only the job code and Ceipal's response are logged, in
`server/careers-errors.log`.

`server/.htaccess` must allow `careers-apply.php`, as it does the other
endpoints.

## Refresh and failures

- Successful results are cached for 12 minutes. Remove `server/careers-cache.json`
  to force a refresh after changing portal configuration.
- All pages must succeed before replacing the cache. At most 100 pages are
  requested; exceeding that limit fails instead of publishing an incomplete list.
- On failure, a valid previous cache less than one hour old may be used.
  Otherwise the endpoint returns HTTP 503 and the page displays its load-error
  message. Stale or sample data is never silently shown as live jobs.
- `X-Careers-Source` identifies `ceipal`, `cache`, or `stale-cache` responses.
- Errors are recorded in `server/careers-errors.log`. Restrict public access to runtime
  logs using your host's configuration. Keep PHP error display disabled in
  production so diagnostics do not corrupt JSON responses.

## Verified live connection

On 2026-09-16, the full PHP endpoint fetched and transformed 14 live jobs with
14 HTTPS application links. The same request also succeeded in Postman Desktop.

The initial PHP request returned HTTP 400 with `Bot access is not allowed`.
Setting `CURLOPT_HTTP_VERSION` to `CURL_HTTP_VERSION_1_1` and enabling negotiated
response compression with `CURLOPT_ENCODING => ''` resolved that error with
the existing credentials, headers, and form fields. Both options are retained;
they were tested together, so the individual cause has not been isolated.
Retest the endpoint on the actual deployment host after uploading.

Run regression checks with `php tests/careers-jobs-test.php`.
