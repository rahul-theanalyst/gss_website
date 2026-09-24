<?php
/**
 * careers-jobs.php
 * ------------------------------------------------------------
 * Server-side proxy between the Careers page and Ceipal's job feed.
 *
 * The browser never talks to Ceipal directly — it only ever calls this
 * endpoint, on our own domain. See docs/CEIPAL-INTEGRATION.md for setup.
 *
 * Output shape matches what careers.js expects, so no front-end changes
 * are needed beyond pointing DATA_URL at this file.
 */

require_once __DIR__ . '/lib/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const CACHE_FILE   = __DIR__ . '/careers-cache.json';
const CACHE_TTL    = 12 * 60;       // seconds — "cache 10-15 minutes" per the guide
const ERROR_LOG     = __DIR__ . '/careers-errors.log';
const MAX_PAGES     = 100;          // safety cap; never publish a truncated feed

const CEIPAL_ORIGIN  = 'https://jobsapi.ceipal.com';
const CEIPAL_REFERER = 'https://jobsapi.ceipal.com/';

function logError($message) {
    $line = '[' . date('c') . '] ' . $message . PHP_EOL;
    @file_put_contents(ERROR_LOG, $line, FILE_APPEND);
}

// Reads CEIPAL_API_KEY / CEIPAL_CP_ID via the shared config loader
// (server/.env.gss_newsite only — gss_load_ceipal_config() in lib/config.php).
function loadConfig() {
    $config = gss_load_ceipal_config();
    if (empty($config['api_key']) || empty($config['cp_id'])) {
        logError('CEIPAL_API_KEY / CEIPAL_CP_ID are not set. Add them to server/.env.gss_newsite.');
        return null;
    }
    return $config;
}

/** One page of Ceipal's CareerPortalJobPostings endpoint. Returns the decoded
 *  response array, or null (and logs) on any failure. */
function fetchCeipalPage($page, $config) {
    if (!extension_loaded('curl')) {
        logError('PHP cURL extension is required.');
        return null;
    }
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
        // Match the supplied form-urlencoded request (arrays send multipart).
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        // This combination matches the working Postman transport. Without it,
        // the same form and headers returned Ceipal's bot-access error locally.
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        CURLOPT_ENCODING       => '',
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: text/html, */*; q=0.01',
            'Origin: ' . CEIPAL_ORIGIN,
            'Referer: ' . CEIPAL_REFERER,
            'User-Agent: Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36 '
                . '(KHTML, like Gecko) Chrome/152.0.0.0 Mobile Safari/537.36',
        ],
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch);

    if ($body === false) {
        logError("Ceipal request failed (page $page): $err");
        return null;
    }
    $data = json_decode($body, true);
    if (!is_array($data)) {
        logError("Ceipal returned non-JSON (page $page, HTTP $code).");
        return null;
    }
    if ($code !== 200 || (isset($data['success']) && !$data['success'])) {
        $msg = $data['message'] ?? ('HTTP ' . $code);
        logError("Ceipal rejected the request (page $page, HTTP $code): $msg");
        return null;
    }
    return $data;
}

/** Return a complete feed, or null so a failed page cannot replace the cache. */
function fetchAllJobs($config, $fetchPage = 'fetchCeipalPage') {
    $all = [];
    $page = 1;
    $totalCount = null;
    while ($page <= MAX_PAGES) {
        $data = $fetchPage($page, $config);
        if ($data === null) {
            return null;
        }
        $results = $data['results'] ?? null;
        if (!is_array($results)) {
            logError("Ceipal response missing results (page $page).");
            return null;
        }
        if (isset($data['count']) && is_numeric($data['count'])) $totalCount = (int) $data['count'];
        if (!count($results)) {
            if ($totalCount !== null && count($all) < $totalCount) return null;
            return $all;
        }
        foreach ($results as $result) {
            if (!is_array($result)) return null;
        }
        $all = array_merge($all, $results);

        // Confirmed against a real response: "page_count" is NOT the number
        // of pages (it mirrors "count", the total job count) despite what
        // the reverse-engineered docs guessed — "num_pages" is the real
        // page count. Stopping once we've collected "count" items is the
        // most robust signal either way, independent of that field mixup.
        if ($totalCount !== null && count($all) >= $totalCount) return $all;

        $numPages = (int) ($data['num_pages'] ?? 0);
        if ($numPages > 0 && $page >= $numPages) {
            return $totalCount !== null && count($all) < $totalCount ? null : $all;
        }
        $page++;
    }
    logError('Ceipal pagination exceeded safety limit.');
    return null;
}

/** Strips HTML down to readable plain text — careers.js escapes whatever
 *  we send, so this must be plain text, not sanitized HTML. Used for the
 *  search haystack and as the fallback when htmlToBlocks() can't parse. */
function htmlToPlainText($html) {
    if (!is_string($html) || $html === '') return '';
    $text = preg_replace('/<\s*(br|\/p|\/div|\/li)\s*\/?>/i', "\n", $html);
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/[ \t]+/', ' ', $text);
    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    return trim($text);
}

/** Collapses runs of whitespace (including line breaks pre-substituted for
 *  <br>) down to single spaces. */
function normalizeSpace($text) {
    return trim(preg_replace('/\s+/u', ' ', (string) $text));
}

/** A short "<label>: <value>" paragraph, e.g. "Experience: 8-10 Years" or
 *  "Duration: 12 months", becomes a highlighted label/value line instead of
 *  a heading or a run-on paragraph. Ceipal postings write these both bolded
 *  ("Location : Southlake, TX") and plain ("Duration: 12 months"), so this
 *  matches on the text shape alone rather than requiring bold markup — the
 *  short, letter-led label is what makes it look like a field name. */
function detectLabelValue($text) {
    if (!preg_match('/^([A-Za-z][^:]{0,27}?)\s*:\s*(.+)$/u', $text, $m)) return null;
    $label = trim($m[1]);
    $value = trim($m[2]);
    if ($label === '' || $value === '') return null;
    return ['type' => 'label', 'label' => $label, 'value' => $value];
}

/** A plain <p> hand-typed as "• some point" (or "- "/"* ") instead of a real
 *  <li> — seen in some Ceipal postings. Strips the marker so appendBlock()
 *  can fold it into a synthetic list alongside its sibling bullet lines. */
function detectBulletText($text) {
    if (!preg_match('/^(?:[•▪‣●∙·]|[-*])\s+(.+)$/u', $text, $m)) return null;
    $item = trim($m[1]);
    return $item === '' ? null : $item;
}

/** Ceipal never marks section headings with real <h1>-<h6> tags or even
 *  consistent bold — "Job Summary", "Key Responsibilities", "Backend
 *  (.NET)" etc. are just short plain <p> lines ahead of a paragraph or
 *  list. A short, unpunctuated line is treated as a heading; this is a
 *  heuristic, not a guarantee, by necessity of the source data. */
function isHeadingText($text) {
    if (!preg_match('/[A-Za-z]/', $text)) return false;
    if (preg_match('/[.!?]$/', $text)) return false;
    if (strlen($text) > 60) return false;
    $words = preg_split('/\s+/', trim($text));
    return count($words) > 0 && count($words) <= 7;
}

/** Every <li> directly under $node, flattening one level of nested
 *  <ul>/<ol> (Ceipal's sub-bullets, e.g. Angular > Routing/Pipes/...) into
 *  the same list with an 'sub' flag rather than a fully recursive tree —
 *  plenty for a job-description bullet list. */
function collectListItems($node) {
    $items = [];
    foreach ($node->childNodes as $child) {
        if ($child->nodeType !== XML_ELEMENT_NODE || strtolower($child->nodeName) !== 'li') continue;

        $ownText = '';
        $nested = [];
        foreach ($child->childNodes as $liChild) {
            if ($liChild->nodeType === XML_ELEMENT_NODE
                && in_array(strtolower($liChild->nodeName), ['ul', 'ol'], true)) {
                foreach (collectListItems($liChild) as $n) { $n['sub'] = true; $nested[] = $n; }
            } elseif ($liChild->nodeType === XML_ELEMENT_NODE && strtolower($liChild->nodeName) === 'br') {
                $ownText .= ' '; // keep words on either side from running together
            } else {
                $ownText .= $liChild->textContent;
            }
        }
        $ownText = normalizeSpace($ownText);
        if ($ownText !== '') $items[] = ['text' => $ownText, 'sub' => false];
        foreach ($nested as $n) $items[] = $n;
    }
    return $items;
}

/** Appends one or more blocks to $blocks for a single child of the
 *  description's root element. See htmlToBlocks() for the block shapes. */
function appendBlock(&$blocks, $node) {
    if ($node->nodeType === XML_TEXT_NODE) {
        appendTextBlock($blocks, normalizeSpace($node->textContent));
        return;
    }
    if ($node->nodeType !== XML_ELEMENT_NODE) return;

    $tag = strtolower($node->nodeName);
    if ($tag === 'ul' || $tag === 'ol') {
        $items = collectListItems($node);
        if ($items) $blocks[] = ['type' => 'list', 'items' => $items];
        return;
    }
    if (preg_match('/^h[1-6]$/', $tag)) {
        $text = normalizeSpace($node->textContent);
        if ($text !== '') $blocks[] = ['type' => 'heading', 'text' => rtrim($text, ':')];
        return;
    }
    if (in_array($tag, ['p', 'div', 'span'], true)) {
        // Ceipal often packs several logically separate lines — a title
        // plus "Location: Remote<br>Duration: Contract to Hire" — into one
        // <p> using <br> instead of separate paragraphs. Split on those so
        // each line still gets classified (and rendered) on its own.
        foreach (splitOnBreaks($node) as $text) appendTextBlock($blocks, $text);
        return;
    }
    // anything unrecognised (stray inline tags, bare <br> at the root, …)
    // — just walk its children
    foreach ($node->childNodes as $child) appendBlock($blocks, $child);
}

/** Classifies one already-normalized line of text as a bullet (folded into
 *  the preceding synthetic list), a label/value pair, a heading, or a plain
 *  paragraph, appending the result to $blocks. Shared by appendBlock()'s
 *  text-node case and its per-line handling of <p>/<div>/<span>. */
function appendTextBlock(&$blocks, $text) {
    if ($text === '') return;

    $bulletText = detectBulletText($text);
    if ($bulletText !== null) {
        $last = $blocks ? $blocks[count($blocks) - 1] : null;
        if ($last && $last['type'] === 'list' && !empty($last['_synthetic'])) {
            $blocks[count($blocks) - 1]['items'][] = ['text' => $bulletText, 'sub' => false];
        } else {
            $blocks[] = ['type' => 'list', '_synthetic' => true, 'items' => [['text' => $bulletText, 'sub' => false]]];
        }
        return;
    }

    $labelValue = detectLabelValue($text);
    if ($labelValue) { $blocks[] = $labelValue; return; }

    if (isHeadingText($text)) { $blocks[] = ['type' => 'heading', 'text' => rtrim($text, ':')]; return; }

    $blocks[] = ['type' => 'para', 'text' => $text];
}

/** $node's inline content (mixed text and elements like <strong>/<span>),
 *  split into separate normalized lines at each <br> — so "Title<br>Location:
 *  Remote<br>Duration: Contract to Hire" inside one <p> yields three lines
 *  instead of one run-on line. Nested block content (<ul> etc.) isn't
 *  expected inside these inline wrappers and is skipped here; appendBlock()
 *  handles it directly when it appears as a sibling instead. */
function splitOnBreaks($node) {
    $segments = [''];
    $walk = function ($n) use (&$walk, &$segments) {
        foreach ($n->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE && strtolower($child->nodeName) === 'br') {
                $segments[] = '';
            } elseif ($child->nodeType === XML_TEXT_NODE) {
                $segments[count($segments) - 1] .= $child->textContent;
            } elseif ($child->nodeType === XML_ELEMENT_NODE) {
                $walk($child);
            }
        }
    };
    $walk($node);
    return array_values(array_filter(array_map('normalizeSpace', $segments), function ($s) { return $s !== ''; }));
}

/** Converts a Ceipal job-description HTML fragment into typed blocks
 *  careers.js can render with real structure — headings, "label: value"
 *  lines, paragraphs, bullet lists — instead of one flattened plain-text
 *  blob. Returns [] (not an exception) for anything it can't parse; the
 *  caller falls back to htmlToPlainText() in that case. */
function htmlToBlocks($html) {
    if (!is_string($html) || trim($html) === '' || !class_exists('DOMDocument')) return [];

    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $ok = $doc->loadHTML('<?xml encoding="UTF-8"><div>' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    if (!$ok) return [];

    $root = $doc->getElementsByTagName('div')->item(0);
    if (!$root) return [];

    $blocks = [];
    foreach ($root->childNodes as $node) appendBlock($blocks, $node);
    foreach ($blocks as &$b) unset($b['_synthetic']);
    unset($b);
    return $blocks;
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

/** Formatted "$min–$max / freq" line from a raw job's pay_rates, or ''. Shared
 *  by transformJob() (teaser feed) and applyJobDetail() (recomputed once the
 *  teaser description is replaced by the full one). */
function payLineFor($raw) {
    if (empty($raw['pay_rates']) || !is_array($raw['pay_rates'])) return '';
    $pr = $raw['pay_rates'][0];
    $min = $pr['min_pay_rate'] ?? '';
    $max = $pr['max_pay_rate'] ?? '';
    $freq = $pr['pay_rate_pay_frequency_type'] ?? '';
    if ($min === '' || $max === '') return '';
    return '$' . number_format((float) $min) . '–$' . number_format((float) $max)
        . ($freq ? ' / ' . strtolower($freq) : '');
}

/** Ceipal's raw job object -> the exact shape careers.js expects.
 *  The list feed only ever returns a ~190-character teaser description with
 *  no skills or experience — applyJobDetail() fills those in afterwards from
 *  the per-job detail endpoint. This function stays a pure, offline transform
 *  (see tests/careers-jobs-test.php) so the detail fetch lives outside it. */
function transformJob($raw) {
    $title = firstNonEmpty($raw['public_job_title'] ?? null, $raw['position_title'] ?? null);
    if ($title === '') return null; // careers.js drops titleless entries anyway

    $location = firstNonEmpty(
        $raw['multpile_job_location'] ?? null,
        implode(', ', array_filter([$raw['city'] ?? '', $raw['state'] ?? '', $raw['country'] ?? '']))
    );
    $location = formatLocation($location);

    $remote = in_array(strtolower(trim((string) ($raw['remote_opportunities'] ?? ''))), ['1', 'true', 'yes', 'remote'], true);
    $type = $remote ? 'Remote' : firstNonEmpty($raw['tax_terms'] ?? null, 'Full Time');

    $applyLink = firstNonEmpty(
        $raw['apply_job_without_registration'] ?? null,
        $raw['apply_job'] ?? null,
        $raw['apply_job_login'] ?? null,
        'contact.html'
    );

    $payLine = payLineFor($raw);

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
        'experience'  => '', // filled in by applyJobDetail() when available
        // "client" is always "Global Soft Systems, Inc" for GSS's own
        // postings (not third-party client names), and "industry" is
        // empty on every posting in this account — neither maps to a
        // real department/practice category, so this is left blank
        // rather than repeating the same unhelpful value on every card.
        'department'  => '',
        'description' => $description,
        'skills'      => [], // filled in by applyJobDetail() when available
        'applyLink'   => $applyLink,
        'featured'    => false,
    ];
}

/** The list feed's "campus_portal_job_details_url" ends in an opaque token
 *  that the candidate portal's own (unauthenticated) job-description API
 *  accepts — see fetchJobDetailsBatch(). Pulls just that last path segment. */
function extractDetailToken($url) {
    if (!is_string($url) || trim($url) === '') return '';
    $path = parse_url(trim($url), PHP_URL_PATH);
    if (!is_string($path) || $path === '') return '';
    $parts = array_values(array_filter(explode('/', $path), function ($p) { return $p !== ''; }));
    return $parts ? (string) end($parts) : '';
}

/** Merges the full description/skills/experience from one detail-endpoint
 *  response ($detail — the decoded "data.jobInfo" object) into an
 *  already-transformed $job. Falls through to the teaser fields already on
 *  $job wherever the detail response doesn't have something usable. */
function applyJobDetail($job, $raw, $detail) {
    $html = $detail['descriptionData']['jobDescription'] ?? '';
    $fullDescription = htmlToPlainText($html);
    if ($fullDescription !== '') {
        $payLine = payLineFor($raw);
        // 'description' stays plain text — it feeds careers.js's search
        // haystack and is the fallback render when 'descriptionBlocks' is
        // empty (parse failure, or no <p>/<ul> structure to find).
        $job['description'] = $payLine !== ''
            ? trim($fullDescription . "\n\n" . $payLine)
            : $fullDescription;

        $blocks = htmlToBlocks($html);
        if ($blocks) {
            if ($payLine !== '') $blocks[] = ['type' => 'label', 'label' => 'Pay Rate', 'value' => $payLine];
            $job['descriptionBlocks'] = $blocks;
        }
    }

    $skills = $detail['skillsData']['skills'] ?? null;
    if (is_array($skills) && count($skills)) {
        $job['skills'] = array_values(array_unique(array_filter(array_map(
            function ($s) { return trim((string) $s); },
            $skills
        ))));
    }

    $experience = trim((string) ($detail['jobDetails']['experience'] ?? ''));
    if ($experience !== '') $job['experience'] = $experience;

    return $job;
}

const DETAIL_BATCH_SIZE = 20; // concurrent connections per curl_multi round

/** Fetches CareerPortalJobPostings-list detail pages concurrently (curl_multi)
 *  for every raw job that has a details URL, in fixed-size batches so a large
 *  feed never opens hundreds of sockets at once. Returns [ $rawJobs index =>
 *  decoded "data.jobInfo" array ], silently omitting any job whose fetch
 *  failed — this enrichment is best-effort and must never block the feed. */
function fetchJobDetailsBatch($rawJobs) {
    if (!extension_loaded('curl')) return [];

    $tokens = [];
    foreach ($rawJobs as $i => $raw) {
        $token = extractDetailToken($raw['campus_portal_job_details_url'] ?? null);
        if ($token !== '') $tokens[$i] = $token;
    }
    if (!$tokens) return [];

    $results = [];
    foreach (array_chunk($tokens, DETAIL_BATCH_SIZE, true) as $chunk) {
        $results += fetchJobDetailsChunk($chunk);
    }
    return $results;
}

/** One curl_multi round for up to DETAIL_BATCH_SIZE [index => token] pairs. */
function fetchJobDetailsChunk($tokensByIndex) {
    $mh = curl_multi_init();
    $handles = [];
    foreach ($tokensByIndex as $i => $token) {
        $ch = curl_init('https://candidateportal.ceipal.com/api/jobs/description/' . rawurlencode($token));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
                    . '(KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
            ],
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$i] = $ch;
    }

    $active = null;
    do {
        $status = curl_multi_exec($mh, $active);
        if ($active) curl_multi_select($mh, 2.0);
    } while ($active && $status === CURLM_OK);

    $results = [];
    foreach ($handles as $i => $ch) {
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $body = curl_multi_getcontent($ch);
        curl_multi_remove_handle($mh, $ch);
        unset($ch);

        $jobInfo = null;
        if ($code === 200 && is_string($body)) {
            $data = json_decode($body, true);
            if (is_array($data) && is_array($data['data']['jobInfo'] ?? null)) {
                $jobInfo = $data['data']['jobInfo'];
            }
        }
        if ($jobInfo !== null) $results[$i] = $jobInfo;
    }
    curl_multi_close($mh);
    return $results;
}

function serveJson($payload) {
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function readJobsCache() {
    if (!is_file(CACHE_FILE)) return null;
    $data = json_decode((string) @file_get_contents(CACHE_FILE), true);
    return is_array($data) && isset($data['jobs']) && is_array($data['jobs']) ? $data : null;
}

function serveUnavailable() {
    // Brief outages may use the last successful feed, but not indefinitely.
    $cached = readJobsCache();
    if ($cached !== null && time() - filemtime(CACHE_FILE) < 60 * 60) {
        header('X-Careers-Source: stale-cache');
        serveJson($cached);
    }
    http_response_code(503);
    header('Retry-After: 60');
    serveJson(['error' => 'Current openings are temporarily unavailable.']);
}

/* ── go ────────────────────────────────────────────────────── */
// (skipped when included by a test harness that only needs the functions above)
if (defined('CEIPAL_JOBS_TEST_MODE')) return;

$cached = readJobsCache();
if ($cached !== null && (time() - filemtime(CACHE_FILE)) < CACHE_TTL) {
    header('X-Careers-Source: cache');
    serveJson($cached);
}

$config = loadConfig();
if ($config === null) {
    serveUnavailable();
}

$rawJobs = fetchAllJobs($config);
if ($rawJobs === null) {
    serveUnavailable();
}

$details = fetchJobDetailsBatch($rawJobs);
$jobs = [];
foreach ($rawJobs as $i => $raw) {
    $job = transformJob($raw);
    if ($job === null) continue;
    if (isset($details[$i])) $job = applyJobDetail($job, $raw, $details[$i]);
    $jobs[] = $job;
}
$payload = json_encode(['jobs' => $jobs], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

if ($payload === false) serveUnavailable();
$temporary = @tempnam(__DIR__, 'careers-cache-');
if ($temporary !== false) {
    if (@file_put_contents($temporary, $payload) !== false) @rename($temporary, CACHE_FILE);
    if (is_file($temporary)) @unlink($temporary);
}
header('X-Careers-Source: ceipal');
echo $payload;
