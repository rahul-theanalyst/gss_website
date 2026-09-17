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
2. Copy `server/careers-config.example.php` to `server/careers-config.php` if it does not
   exist, and set `api_key` and `cp_id` to the supplied portal credentials.
   This local file is gitignored; provision it separately on your server.
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

This enrichment is best-effort: if a job has no details URL, or its request
fails or times out, that job simply keeps its teaser description with no
skills/experience — the whole feed never fails because of it.

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

The existing standalone `tools/diagnostics/ceipal.php` is a development diagnostic script and is not
used by the careers page.

Run regression checks with `php tests/careers-jobs-test.php`.
