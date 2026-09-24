<?php
/**
 * careers-submit.php
 * ------------------------------------------------------------
 * Careers "Stay connected" form (html/career.html). Validates the
 * fields and the PDF résumé, emails them with PHP's native mail()
 * (résumé attached), then redirects back to the form page with
 * ?status=success or ?status=error.
 *
 * The résumé is never saved: it's read from PHP's own temporary
 * upload file (outside the web root, deleted when the request ends)
 * straight into the email.
 */

require_once __DIR__ . '/lib/form-mail.php';
require_once __DIR__ . '/lib/spam-guard.php';

const FORM_PAGE       = '../html/career.html';
const SUBMISSIONS_LOG = __DIR__ . '/careers-submissions.log';
const MAX_FILE_SIZE   = 10 * 1024 * 1024; // 10 MB — matches js/pages/career-form.js

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . FORM_PAGE);
    exit();
}

// Bots (honeypot filled / submitted instantly): pretend success, send nothing.
$spamReason = gss_is_spam_submission($_POST);
if ($spamReason !== false) {
    form_log(SUBMISSIONS_LOG, 'Blocked suspected spam: ' . $spamReason);
    form_redirect(FORM_PAGE, 'success');
}

// Fields — names match html/career.html
$first    = form_clean($_POST['first'] ?? '');
$last     = form_clean($_POST['last'] ?? '');
$email    = form_clean($_POST['email'] ?? '');
$phone    = form_clean($_POST['phone'] ?? '');
$skill    = form_clean($_POST['skill'] ?? '');
$location = form_clean($_POST['ploc'] ?? '');
$privacyVersion    = form_clean($_POST['privacy_policy_version'] ?? '');
$disclaimerVersion = form_clean($_POST['disclaimer_version'] ?? '');
$acknowledgedAt    = form_clean($_POST['privacy_acknowledged_at'] ?? '');

$valid = $first !== '' && strlen($first) <= 100
    && $last !== '' && strlen($last) <= 100
    && filter_var($email, FILTER_VALIDATE_EMAIL) && strlen($email) <= 254
    && strlen(preg_replace('/[^0-9]/', '', $phone)) >= 7 && strlen($phone) <= 40
    && $skill !== '' && strlen($skill) <= 150
    && $location !== '' && strlen($location) <= 150;

// Résumé: PDF only (as the form allows), max 10 MB, checked by content.
$resume = $_FILES['resume'] ?? null;
$pdfOk = $resume
    && !is_array($resume['error'])
    && $resume['error'] === UPLOAD_ERR_OK
    && is_uploaded_file($resume['tmp_name'])
    && filesize($resume['tmp_name']) > 0
    && filesize($resume['tmp_name']) <= MAX_FILE_SIZE
    && strtolower(pathinfo($resume['name'], PATHINFO_EXTENSION)) === 'pdf'
    && file_get_contents($resume['tmp_name'], false, null, 0, 5) === '%PDF-';

if (!$valid || !$pdfOk) {
    form_log(SUBMISSIONS_LOG, 'Rejected: invalid fields or résumé (upload error code ' . (int)($resume['error'] ?? UPLOAD_ERR_NO_FILE) . ')');
    form_redirect(FORM_PAGE, 'error');
}

$fullName = $first . ' ' . $last;
$fileData = file_get_contents($resume['tmp_name']);
$namePart = trim(preg_replace('/[^A-Za-z0-9]+/', '_', $first . '_' . $last), '_');
$fileName = 'Resume_' . ($namePart !== '' ? substr($namePart, 0, 60) . '_' : '') . gmdate('Ymd') . '.pdf';

$subject = '[Careers] New Candidate Profile: ' . $fullName . ' (' . $skill . ')';
$message = "You received a new Careers submission:\n\n"
    . "Name:                {$fullName}\n"
    . "Email:               {$email}\n"
    . "Phone:               {$phone}\n"
    . "Primary skill:       {$skill}\n"
    . "Preferred location:  {$location}\n"
    . "Resume:              {$fileName} (attached)\n"
    . "Original file name:  " . form_clean(basename($resume['name'])) . "\n\n"
    . 'Submitted:           ' . gmdate('Y-m-d H:i:s') . " UTC\n"
    . "Privacy consent:     Privacy Policy {$privacyVersion}, Disclaimer {$disclaimerVersion}, at {$acknowledgedAt}\n\n"
    . "Reply to the candidate at: {$email}\n";

$sent = form_send_mail($subject, $message, [
    'filename' => $fileName,
    'mime'     => 'application/pdf',
    'data'     => $fileData,
]);

form_log(SUBMISSIONS_LOG, "Candidate: {$fullName} <{$email}> | Skill: {$skill} | Resume: {$fileName} | To: " . FORM_MAIL_TO
    . ' | mail(): ' . ($sent ? 'accepted by the server mail agent' : 'FAILED'));

if ($sent) {
    form_redirect(FORM_PAGE, 'success');
} else {
    form_redirect(FORM_PAGE, 'error');
}
