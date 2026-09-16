<?php
/**
 * careers-jobs.php
 * ------------------------------------------------------------
 * Server-side proxy between the Careers page and Ceipal's job feed.
 *
 * The browser never talks to Ceipal directly — it only ever calls this
 * endpoint, on our own domain. This file is the only thing that talks
 * to Ceipal, and only from the server. See:
 *   - CareerPortalJobPostings_API_Documentation.md
 *   - CareerPortalJobPostings_Integration_Guide.md
 *
 * Output shape matches jobs.json exactly (see EDITING-JOBS.md), so
 * careers.js needs no changes beyond pointing DATA_URL at this file.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const CACHE_FILE   = __DIR__ . '/careers-cache.json';
const CACHE_TTL    = 12 * 60;       // seconds — "cache 10-15 minutes" per the guide
const ERROR_LOG     = __DIR__ . '/careers-errors.log';
const STATIC_FALLBACK = __DIR__ . '/jobs.json';
const MAX_PAGES     = 5;            // safety cap on pagination loops

const CEIPAL_ORIGIN  = 'https://jobsapi.ceipal.com';
const CEIPAL_REFERER = 'https://jobsapi.ceipal.com/';

function logError($message) {
    $line = '[' . date('c') . '] ' . $message . PHP_EOL;
    @file_put_contents(ERROR_LOG, $line, FILE_APPEND);
}

function loadConfig() {
    $path = __DIR__ . '/careers-config.php';
    if (!is_file($path)) {
        logError('careers-config.php is missing. Copy careers-config.example.php and fill in real values.');
        return null;
    }
    $config = require $path;
    if (empty($config['api_key']) || empty($config['cp_id'])) {
        logError('careers-config.php is missing api_key or cp_id.');
        return null;
    }
    return $config;
}

/** One page of Ceipal's CareerPortalJobPostings endpoint. Returns the decoded
 *  response array, or null (and logs) on any failure. */
function fetchCeipalPage($page, $config) {
    $apiKey = $config['api_key'];
    $cpId   = $config['cp_id'];
    $url    = 'https://careerapi.ceipal.com/' . rawurlencode($apiKey)
            . '/CareerPortalJobPostings/?page=' . (int) $page;

    $fields = [
        'from_chatbot'        => '0',
        'chatbot_job_title'   => '',
        'chatbot_skills'      => '',
        'chatbot_city'        => '',
        'chatbot_state'       => '',
        'chatbot_country'     => '',
        'searchkey'           => '',
        'country'             => '',
        'state'               => '',
        'city'                => '',
        'industry'            => '',
        'status'              => '',
        'offering'            => '',
        'profession'          => '',
        'speciality'          => '',
        'job_type'            => '',
        'hc_state'            => '',
        'page'                => (string) $page,
        'api_key'             => $apiKey,
        'method'              => 'CareerPortalJobPostings',
        'cp_id'               => $cpId,
        'from_career_portal'  => '1',
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $fields, // multipart/form-data (array form)
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Accept: text/html, */*; q=0.01',
            'Origin: ' . CEIPAL_ORIGIN,
            'Referer: ' . CEIPAL_REFERER,
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
                . '(KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
        ],
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        logError("Ceipal request failed (page $page): $err");
        return null;
    }
    $data = json_decode($body, true);
    if (!is_array($data)) {
        logError("Ceipal returned non-JSON (page $page): " . substr($body, 0, 200));
        return null;
    }
    if ($code !== 200 || (isset($data['success']) && !$data['success'])) {
        $msg = $data['message'] ?? ('HTTP ' . $code);
        logError("Ceipal rejected the request (page $page): $msg");
        return null;
    }
    return $data;
}

/** Pulls every page of open postings, up to MAX_PAGES. Returns a flat
 *  array of raw Ceipal job objects, or null if even the first page fails. */
function fetchAllJobs($config) {
    $all = [];
    $page = 1;
    $totalCount = null;
    while ($page <= MAX_PAGES) {
        $data = fetchCeipalPage($page, $config);
        if ($data === null) {
            return $page === 1 ? null : $all; // first-page failure is fatal; later pages just stop
        }
        $results = $data['results'] ?? [];
        if (!is_array($results) || !count($results)) break;
        $all = array_merge($all, $results);

        // Confirmed against a real response: "page_count" is NOT the number
        // of pages (it mirrors "count", the total job count) despite what
        // the reverse-engineered docs guessed — "num_pages" is the real
        // page count. Stopping once we've collected "count" items is the
        // most robust signal either way, independent of that field mixup.
        if ($totalCount === null) $totalCount = (int) ($data['count'] ?? count($all));
        if (count($all) >= $totalCount) break;

        $numPages = (int) ($data['num_pages'] ?? 1);
        if ($page >= $numPages) break;
        $page++;
    }
    return $all;
}

/** Strips HTML down to readable plain text — careers.js escapes whatever
 *  we send, so this must be plain text, not sanitized HTML. */
function htmlToPlainText($html) {
    if (!is_string($html) || $html === '') return '';
    $text = preg_replace('/<\s*(br|\/p|\/div|\/li)\s*\/?>/i', "\n", $html);
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/[ \t]+/', ' ', $text);
    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    return trim($text);
}

/** Ceipal wraps each location in parens and comma-separates multiple ones,
 *  e.g. "(Lakeland, FL, 33801), (Atlanta, GA, 30377)" — a plain trim() only
 *  strips the outermost pair, leaving stray "), (" in the middle for
 *  multi-location postings. This strips only the true outer parens and
 *  joins the rest with a semicolon. */
function formatLocation($raw) {
    if (!is_string($raw) || trim($raw) === '') return '';
    $s = trim($raw);
    $s = preg_replace('/^\(|\)$/', '', $s);
    $s = str_replace('), (', '; ', $s);
    return trim($s);
}

function firstNonEmpty(...$values) {
    foreach ($values as $v) {
        if (is_string($v) && trim($v) !== '') return trim($v);
    }
    return '';
}

/** Ceipal's raw job object -> the exact shape jobs.json / careers.js expect. */
function transformJob($raw) {
    $title = firstNonEmpty($raw['public_job_title'] ?? null, $raw['position_title'] ?? null);
    if ($title === '') return null; // careers.js drops titleless entries anyway

    $location = firstNonEmpty(
        $raw['multpile_job_location'] ?? null,
        implode(', ', array_filter([$raw['city'] ?? '', $raw['state'] ?? '', $raw['country'] ?? '']))
    );
    $location = formatLocation($location);

    $remote = !empty($raw['remote_opportunities']);
    $type = $remote ? 'Remote' : firstNonEmpty($raw['tax_terms'] ?? null, 'Full Time');

    $applyLink = firstNonEmpty(
        $raw['apply_job_without_registration'] ?? null,
        $raw['apply_job'] ?? null,
        $raw['apply_job_login'] ?? null,
        'contact.html'
    );

    $payLine = '';
    if (!empty($raw['pay_rates']) && is_array($raw['pay_rates'])) {
        $pr = $raw['pay_rates'][0];
        $min = $pr['min_pay_rate'] ?? '';
        $max = $pr['max_pay_rate'] ?? '';
        $freq = $pr['pay_rate_pay_frequency_type'] ?? '';
        if ($min !== '' && $max !== '') {
            $payLine = '$' . number_format((float) $min) . '–$' . number_format((float) $max)
                . ($freq ? ' / ' . strtolower($freq) : '');
        }
    }

    $description = htmlToPlainText(firstNonEmpty(
        $raw['public_job_desc'] ?? null,
        $raw['requistion_description'] ?? null
    ));
    if ($payLine !== '') {
        $description = trim($description . "\n\n" . $payLine);
    }

    return [
        'id'          => (string) firstNonEmpty((string) ($raw['job_code'] ?? ''), (string) ($raw['id'] ?? ''), $title),
        'title'       => $title,
        'location'    => $location ?: 'Location on request',
        'type'        => $type,
        'experience'  => '', // not provided by this feed
        // "client" is always "Global Soft Systems, Inc" for GSS's own
        // postings (not third-party client names), and "industry" is
        // empty on every posting in this account — neither maps to a
        // real department/practice category, so this is left blank
        // rather than repeating the same unhelpful value on every card.
        'department'  => '',
        'description' => $description,
        'skills'      => [], // not provided by this feed
        'applyLink'   => $applyLink,
        'featured'    => false,
    ];
}

function serveJson($payload) {
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function serveCacheOrStaticFallback() {
    if (is_file(CACHE_FILE)) {
        $cached = @file_get_contents(CACHE_FILE);
        if ($cached !== false) { echo $cached; exit; }
    }
    if (is_file(STATIC_FALLBACK)) {
        logError('Serving static jobs.json fallback — no usable cache.');
        readfile(STATIC_FALLBACK);
        exit;
    }
    serveJson(['jobs' => []]); // careers.js shows its built-in "No current openings" state
}

/* ── go ────────────────────────────────────────────────────── */
// (skipped when included by a test harness that only needs the functions above)
if (defined('CEIPAL_JOBS_TEST_MODE')) return;

if (is_file(CACHE_FILE) && (time() - filemtime(CACHE_FILE)) < CACHE_TTL) {
    readfile(CACHE_FILE);
    exit;
}

$config = loadConfig();
if ($config === null) {
    serveCacheOrStaticFallback();
}

$rawJobs = fetchAllJobs($config);
if ($rawJobs === null) {
    serveCacheOrStaticFallback();
}

$jobs = array_values(array_filter(array_map('transformJob', $rawJobs)));
$payload = json_encode(['jobs' => $jobs], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

@file_put_contents(CACHE_FILE, $payload);
echo $payload;
