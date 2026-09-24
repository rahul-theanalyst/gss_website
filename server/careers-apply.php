<?php
/**
 * careers-apply.php
 * ------------------------------------------------------------
 * "Easy Apply" for the Careers page. The browser only ever talks to this
 * endpoint; it talks to Ceipal. Three actions:
 *
 *   GET  ?action=form&job=<ceipal job id>   the job's application form —
 *        fields, required flags and dropdown options exactly as configured
 *        in Ceipal, so the page's own form always matches Ceipal's.
 *   GET  ?action=states&country=<id>        State/Province list for a country.
 *   POST action=submit                      validates the application against
 *        that same form definition and submits it (with the résumé) to
 *        Ceipal's apply-without-registration endpoint — the one Ceipal's own
 *        career widget uses — so it lands in Ceipal as a normal application.
 *
 * Ceipal's widget shows a CAPTCHA, but it is drawn and checked only in the
 * browser and never reaches Ceipal. Here it is replaced by server-side
 * checks: the shared honeypot/timing guard, a per-IP rate limit, and full
 * validation. See docs/CEIPAL-INTEGRATION.md ("Easy Apply").
 */

require_once __DIR__ . '/lib/config.php';
require_once __DIR__ . '/lib/spam-guard.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

const APPLY_API_BASE   = 'https://careerapi.ceipal.com/';
const APPLY_ORIGIN     = 'https://jobsapi.ceipal.com';
const APPLY_REFERER    = 'https://jobsapi.ceipal.com/';
const APPLY_ERROR_LOG  = __DIR__ . '/careers-errors.log';
const APPLY_FORM_TTL   = 15 * 60;       // form definitions: same freshness as the job feed
const APPLY_STATES_TTL = 24 * 60 * 60;  // state lists practically never change
const APPLY_MAX_FILE   = 10 * 1024 * 1024;
const APPLY_RATE_MAX   = 5;             // submissions per IP ...
const APPLY_RATE_SPAN  = 10 * 60;       // ... per 10 minutes

// Field types this form knows how to render and validate. Anything else
// Ceipal adds later is still shown and sent, as a plain text field.
const APPLY_TEXT_TYPES = ['text', 'text_email', 'text_mobile', 'text_phone', 'text_number',
                          'text_zipcode', 'text_url', 'multi_select_text'];

/* ── plumbing ─────────────────────────────────────────────── */

function applyLog($message) {
    @file_put_contents(APPLY_ERROR_LOG, '[' . date('c') . '] easy-apply: ' . $message . PHP_EOL, FILE_APPEND);
}

function applyRespond($status, array $payload) {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function applyFail($status, $message) {
    applyRespond($status, ['ok' => false, 'message' => $message]);
}

function applyConfig() {
    $config = gss_load_ceipal_config();
    if (empty($config['api_key']) || empty($config['cp_id'])) {
        applyLog('CEIPAL_API_KEY / CEIPAL_CP_ID are not set.');
        applyFail(503, 'Online applications are temporarily unavailable. Please try again shortly.');
    }
    return $config;
}

/** Ceipal job ids are URL-safe base64 tokens (e.g. "z5G7h3l6...7iY="). */
function applyValidJobId($id) {
    return is_string($id) && preg_match('/^[A-Za-z0-9_\-+\/]{8,200}={0,2}$/', $id) === 1;
}

/** One request to careerapi.ceipal.com, with the transport settings that
 *  careers-jobs.php found Ceipal requires (HTTP/1.1 + negotiated encoding,
 *  widget origin). Returns [httpCode, decodedJson|null]. */
function ceipalRequest($url, $postFields = null) {
    if (!extension_loaded('curl')) {
        applyLog('PHP cURL extension is required.');
        return [0, null];
    }
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_TIMEOUT         => $postFields === null ? 15 : 60,
        CURLOPT_CONNECTTIMEOUT  => 5,
        CURLOPT_HTTP_VERSION    => CURL_HTTP_VERSION_1_1,
        CURLOPT_ENCODING        => '',
        CURLOPT_FOLLOWLOCATION  => false,
        CURLOPT_HTTPHEADER      => [
            'Accept: application/json, text/html, */*; q=0.01',
            'Origin: ' . APPLY_ORIGIN,
            'Referer: ' . APPLY_REFERER,
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
                . '(KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
        ],
    ];
    if ($postFields !== null) {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = $postFields; // array → multipart/form-data
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    unset($ch);

    if ($body === false) {
        applyLog("request failed: $err");
        return [0, null];
    }
    $json = json_decode($body, true);
    return [$code, is_array($json) ? $json : null];
}

/** Small file cache in the system temp dir (outside the web root). */
function applyCacheGet($key, $ttl) {
    $file = sys_get_temp_dir() . '/gss-apply-' . sha1($key) . '.json';
    if (!is_file($file) || time() - filemtime($file) > $ttl) return null;
    $data = json_decode((string) @file_get_contents($file), true);
    return is_array($data) ? $data : null;
}

function applyCachePut($key, array $data) {
    $file = sys_get_temp_dir() . '/gss-apply-' . sha1($key) . '.json';
    @file_put_contents($file, json_encode($data), LOCK_EX);
}

/* ── form definition ──────────────────────────────────────── */

/** Normalises Ceipal's option shapes — [{id,name}] or {value: label} —
 *  into [{value, label}]. */
function normaliseOptions($field) {
    $out = [];
    $options = $field['options'] ?? null;
    if (!is_array($options)) return $out;

    $isList = array_keys($options) === range(0, count($options) - 1);
    if ($isList) {
        foreach ($options as $o) {
            if (!is_array($o) || !isset($o['id'], $o['name'])) continue;
            $out[] = ['value' => (string) $o['id'], 'label' => trim((string) $o['name']),
                      'default' => !empty($o['is_default'])];
        }
    } else {
        foreach ($options as $value => $label) {
            $out[] = ['value' => (string) $value, 'label' => trim((string) $label), 'default' => false];
        }
    }
    return $out;
}

/** Turns one raw Ceipal field into the shape the page renders. */
function normaliseField($group, $f) {
    if (!is_array($f) || !isset($f['key'])) return null;
    $key   = (string) $f['key'];
    $type  = (string) ($f['type'] ?? 'text');
    $label = trim((string) ($f['label'] ?? $key));
    $options = normaliseOptions($f);
    if ($key === 'work_authorization') {
        // Ceipal's widget never preselects this one (it starts on "Select"),
        // even though an option carries is_default — match that.
        foreach ($options as &$o) $o['default'] = false;
        unset($o);
    }

    $field = [
        'name'     => $group . '.' . $key,   // exactly the name Ceipal's own form posts
        'key'      => $key,
        'label'    => $label,
        'required' => (string) ($f['is_required'] ?? '0') === '1',
    ];

    if ($type === 'file_upload') {
        $field['kind'] = 'file';
    } elseif ($type === 'dropdown' && $key === 'country') {
        $field['kind'] = 'country';
        $field['options'] = $options;
    } elseif ($type === 'dropdown' && $key === 'state') {
        $field['kind'] = 'state';             // options loaded per country
    } elseif (in_array($type, ['dropdown', 'dropdown_number', 'radio_button'], true)) {
        $field['kind'] = 'select';
        $field['options'] = $options;
    } elseif (in_array($type, ['multi_dropdown', 'multi_select', 'check_box', 'multi_choice'], true)) {
        $field['kind'] = 'multi';             // sent comma-joined, like Ceipal's widget
        $field['options'] = $options;
    } elseif ($type === 'multiline_text') {
        $field['kind'] = 'textarea';
    } elseif ($type === 'date') {
        $field['kind'] = 'date';
    } elseif ($type === 'text' && $options) {
        // e.g. Relocation: a free-text field in Ceipal that carries Yes/No
        // options — offer them as a choice, but send the text itself.
        $field['kind'] = 'select';
        $field['options'] = array_map(function ($o) {
            return ['value' => $o['label'], 'label' => $o['label'], 'default' => $o['default']];
        }, $options);
    } else {
        $field['kind'] = 'text';
        $field['format'] = in_array($type, APPLY_TEXT_TYPES, true) ? $type : 'text';
    }
    return $field;
}

/** Fetches (or reuses) a job's application form from Ceipal. Returns null
 *  when the job is unknown or doesn't accept Easy Apply. */
function loadApplyForm($jobId, $config) {
    $cacheKey = 'form|' . $config['api_key'] . '|' . $jobId;
    $cached = applyCacheGet($cacheKey, APPLY_FORM_TTL);
    if ($cached !== null) return $cached;

    $url = APPLY_API_BASE . rawurlencode($config['api_key']) . '/CareerPortalJobPostings/'
         . rawurlencode($jobId) . '/?from_career_portal=1&cp_id=' . rawurlencode($config['cp_id']);
    [$code, $raw] = ceipalRequest($url);
    if ($code !== 200 || !$raw || empty($raw['form_fields']) || !is_array($raw['form_fields'])) {
        applyLog("form definition unavailable for job $jobId (HTTP $code)");
        return null;
    }

    $sections = [];
    foreach (['standard_fields', 'custom_fields', 'submission_fields', 'document_fields'] as $group) {
        $list = $raw['form_fields'][$group] ?? [];
        if (!is_array($list)) continue;
        foreach ($list as $f) {
            $field = normaliseField($group, $f);
            if ($field !== null) $sections[$group][] = $field;
        }
    }

    $terms = '';
    if ((string) ($raw['terms_and_conditions_enabled'] ?? '0') === '1') {
        $terms = trim(strip_tags(html_entity_decode((string) ($raw['terms_and_conditions'] ?? ''),
                                                    ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    $form = [
        'jobId'      => $jobId,
        'title'      => trim((string) ($raw['public_job_title'] ?? '')),
        'jobCode'    => trim((string) ($raw['job_code'] ?? '')),
        'easyApply'  => (string) ($raw['apply_with_out_registration'] ?? '0') === '1',
        'applyLink'  => (string) ($raw['apply_job_login'] ?? $raw['apply_job'] ?? ''),
        'terms'      => $terms,
        'sections'   => $sections,
    ];
    applyCachePut($cacheKey, $form);
    return $form;
}

function loadStates($countryId, $config) {
    $cacheKey = 'states|' . $countryId;
    $cached = applyCacheGet($cacheKey, APPLY_STATES_TTL);
    if ($cached !== null) return $cached;

    $url = APPLY_API_BASE . rawurlencode($config['api_key']) . '/CareerPortalStates/?country=' . $countryId;
    [$code, $raw] = ceipalRequest($url);
    if ($code !== 200 || !is_array($raw) || (isset($raw['status']) && !isset($raw[0]))) {
        applyLog("state list unavailable for country $countryId (HTTP $code)");
        return null;
    }
    $states = [];
    foreach ($raw as $s) {
        if (is_array($s) && isset($s['id'], $s['name'])) {
            $states[] = ['value' => (string) $s['id'], 'label' => trim((string) $s['name'])];
        }
    }
    applyCachePut($cacheKey, $states);
    return $states;
}

/* ── submission ───────────────────────────────────────────── */

/** Per-IP limit, kept in the temp dir. Fails open if the file can't be used. */
function applyRateLimited() {
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $file = sys_get_temp_dir() . '/gss-apply-rate-' . sha1($ip) . '.json';
    $now = time();
    $hits = json_decode((string) @file_get_contents($file), true);
    $hits = is_array($hits) ? array_values(array_filter($hits, function ($t) use ($now) {
        return is_int($t) && $now - $t < APPLY_RATE_SPAN;
    })) : [];
    if (count($hits) >= APPLY_RATE_MAX) return true;
    $hits[] = $now;
    @file_put_contents($file, json_encode($hits), LOCK_EX);
    return false;
}

/** Checks one uploaded document: size, extension and real file signature. */
function validDocument($file) {
    if (!is_array($file) || is_array($file['error'] ?? null) || ($file['error'] ?? 1) !== UPLOAD_ERR_OK) return false;
    if (!is_uploaded_file($file['tmp_name'])) return false;
    $size = filesize($file['tmp_name']);
    if ($size <= 0 || $size > APPLY_MAX_FILE) return false;

    $ext  = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    $head = (string) file_get_contents($file['tmp_name'], false, null, 0, 8);
    if ($ext === 'pdf')  return strncmp($head, '%PDF-', 5) === 0;
    if ($ext === 'docx') return strncmp($head, "PK\x03\x04", 4) === 0;
    if ($ext === 'doc')  return strncmp($head, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1", 8) === 0;
    return false;
}

function documentMime($name) {
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    return [
        'pdf'  => 'application/pdf',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ][$ext] ?? 'application/octet-stream';
}

/** PHP turns "standard_fields.firstname" into "standard_fields_firstname"
 *  in $_POST/$_FILES, so the page posts each field under a safe alias
 *  (f_<index>) and this maps it back to Ceipal's real field name. */
function fieldAlias($index) {
    return 'f_' . $index;
}

function handleSubmit($config) {
    if (gss_is_spam_submission($_POST) !== false) {
        // Pretend success so bots learn nothing; nothing is sent to Ceipal.
        applyRespond(200, ['ok' => true, 'message' => 'Thank you — your application has been submitted.']);
    }
    if (applyRateLimited()) {
        applyFail(429, 'Too many applications from this connection. Please try again in a few minutes.');
    }

    $jobId = (string) ($_POST['job'] ?? '');
    if (!applyValidJobId($jobId)) applyFail(400, 'This role could not be found.');

    $form = loadApplyForm($jobId, $config);
    if ($form === null || !$form['easyApply']) {
        applyFail(409, 'This role is no longer accepting Easy Apply applications.');
    }

    $post   = ['job_id' => $jobId, 'applicant_id' => '0', 'type' => 'applyWithoutRegistration'];
    $errors = [];
    $index  = 0;
    $countryValue = '';

    foreach ($form['sections'] as $fields) {
        foreach ($fields as $field) {
            $alias = fieldAlias($index++);
            $label = $field['label'];

            if ($field['kind'] === 'file') {
                $file = $_FILES[$alias] ?? null;
                $given = is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
                if (!$given) {
                    if ($field['required']) $errors[$alias] = "Please attach your $label.";
                    continue;
                }
                if (!validDocument($file)) {
                    $errors[$alias] = "$label must be a PDF or Word document up to 10 MB.";
                    continue;
                }
                $post[$field['name']] = new CURLFile($file['tmp_name'], documentMime($file['name']),
                                                     basename((string) $file['name']));
                continue;
            }

            $raw = $_POST[$alias] ?? '';
            if (is_array($raw)) $raw = implode(',', array_map('strval', $raw));
            $value = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $raw));
            if ($field['kind'] === 'textarea') {
                $value = trim(str_replace("\r\n", "\n", (string) $raw));
            }

            if ($value === '') {
                if ($field['required']) $errors[$alias] = "Please enter your $label.";
                $post[$field['name']] = '';
                continue;
            }

            $max = $field['kind'] === 'textarea' ? 4000 : 250;
            if (mb_strlen($value) > $max) { $errors[$alias] = "$label is too long."; continue; }

            switch ($field['kind']) {
                case 'country':
                case 'select':
                    $allowed = array_column($field['options'] ?? [], 'value');
                    if (!in_array($value, $allowed, true)) $errors[$alias] = "Please choose a valid $label.";
                    if ($field['kind'] === 'country') $countryValue = $value;
                    break;
                case 'multi':
                    $allowed = array_column($field['options'] ?? [], 'value');
                    foreach (explode(',', $value) as $v) {
                        if (!in_array($v, $allowed, true)) { $errors[$alias] = "Please choose a valid $label."; break; }
                    }
                    break;
                case 'state':
                    // checked against the chosen country's list below
                    break;
                case 'date':
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) $errors[$alias] = "Please enter a valid $label.";
                    break;
                case 'text':
                    $format = $field['format'] ?? 'text';
                    if ($format === 'text_email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                        $errors[$alias] = 'Please enter a valid email address.';
                    } elseif (in_array($format, ['text_mobile', 'text_phone'], true)) {
                        // Accept any common format; send Ceipal the plain form its
                        // own widget allows ("+" and digits), e.g. +15550102000.
                        $digits = preg_replace('/\D/', '', $value);
                        if (!preg_match('/^[+\d\s().\/-]+$/', $value) || strlen($digits) < 7 || strlen($digits) > 15) {
                            $errors[$alias] = 'Please enter a valid phone number.';
                        } else {
                            $value = (strpos(ltrim($value), '+') === 0 ? '+' : '') . $digits;
                        }
                    } elseif ($format === 'text_number' && !preg_match('/^\d+(\.\d+)?$/', $value)) {
                        $errors[$alias] = "Please enter a number for $label.";
                    }
                    break;
            }
            $post[$field['name']] = $value;
        }
    }

    // State must belong to the chosen country's list.
    $index = 0;
    foreach ($form['sections'] as $fields) {
        foreach ($fields as $field) {
            $alias = fieldAlias($index++);
            if ($field['kind'] !== 'state' || isset($errors[$alias]) || ($post[$field['name']] ?? '') === '') continue;
            $states = ctype_digit($countryValue) ? loadStates($countryValue, $config) : null;
            if ($states !== null && !in_array($post[$field['name']], array_column($states, 'value'), true)) {
                $errors[$alias] = 'Please choose a valid ' . $field['label'] . '.';
            }
        }
    }

    $termsAccepted = ($_POST['terms'] ?? '') === '1';
    if ($form['terms'] !== '' && !$termsAccepted) {
        $errors['terms'] = 'Please accept the terms and conditions.';
    }

    if ($errors) {
        applyRespond(422, ['ok' => false, 'message' => 'Please check the highlighted fields.', 'errors' => $errors]);
    }

    // The rest of what Ceipal's own widget form always sends.
    $timeZone = (string) ($_POST['time_zone'] ?? '');
    $post += [
        'parsing_resume_flag'                => '0',
        'cleaned_resumename'                 => '',
        'orignal_attachment_name'            => '',
        'terms_conditions'                   => $termsAccepted ? '1' : '0',
        'time_zone'                          => in_array($timeZone, timezone_identifiers_list(), true) ? $timeZone : 'UTC',
        'standard_fields.gdpr_consent_type'  => '1',
        'standard_fields.gdpr_consent_period'=> '',
    ];

    $url = APPLY_API_BASE . rawurlencode($config['api_key']) . '/CareerPortalApplyJobWithoutRegistrationCareerPage/';
    [$code, $res] = ceipalRequest($url, $post);

    if ($code === 200 && is_array($res) && (string) ($res['success'] ?? '') === '1') {
        applyLog("submitted application for job {$form['jobCode']}"); // no applicant details in the log
        applyRespond(200, [
            'ok'      => true,
            'message' => 'Thank you — your application for ' . ($form['title'] ?: 'this role')
                       . ' has been submitted. Our recruiting team will be in touch if your profile is a match.',
        ]);
    }

    $ceipalMessage = is_array($res) ? trim(strip_tags((string) ($res['message'] ?? ''))) : '';
    applyLog("Ceipal rejected application for job {$form['jobCode']} (HTTP $code): " . substr($ceipalMessage, 0, 200));
    if ($code === 400 && $ceipalMessage !== '') {
        // Ceipal's own validation messages (e.g. "already applied") are safe to show.
        applyFail(422, $ceipalMessage);
    }
    applyFail(502, 'We couldn’t submit your application just now. Please try again in a few minutes.');
}

/* ── go ────────────────────────────────────────────────────── */
if (defined('CAREERS_APPLY_TEST_MODE')) return;

$config = applyConfig();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST') {
    // An oversized upload empties $_POST entirely; say so instead of "not found".
    if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        applyFail(413, 'Your résumé is too large. Please upload a file up to 10 MB.');
    }
    handleSubmit($config);
}

$action = (string) ($_GET['action'] ?? '');

if ($action === 'form') {
    $jobId = (string) ($_GET['job'] ?? '');
    if (!applyValidJobId($jobId)) applyFail(400, 'This role could not be found.');
    $form = loadApplyForm($jobId, $config);
    if ($form === null) applyFail(502, 'The application form couldn’t be loaded. Please try again shortly.');
    if (!$form['easyApply']) applyFail(409, 'This role is no longer accepting Easy Apply applications.');
    unset($form['applyLink']);
    applyRespond(200, ['ok' => true, 'form' => $form]);
}

if ($action === 'states') {
    $country = (string) ($_GET['country'] ?? '');
    if (!ctype_digit($country) || strlen($country) > 6) applyFail(400, 'Unknown country.');
    $states = loadStates($country, $config);
    if ($states === null) applyFail(502, 'The state list couldn’t be loaded. Please try again.');
    applyRespond(200, ['ok' => true, 'states' => $states]);
}

applyFail(400, 'Unknown request.');
