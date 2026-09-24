<?php
/**
 * contact-submit.php
 * ------------------------------------------------------------
 * "Let's Connect" form (html/contact.html). Validates the fields,
 * emails them with PHP's native mail(), then redirects back to the
 * form page with ?status=success or ?status=error.
 */

require_once __DIR__ . '/lib/form-mail.php';
require_once __DIR__ . '/lib/spam-guard.php';

const FORM_PAGE       = '../html/contact.html';
const SUBMISSIONS_LOG = __DIR__ . '/contact-submissions.log';

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

// Fields — names match html/contact.html
$first       = form_clean($_POST['first'] ?? '');
$last        = form_clean($_POST['last'] ?? '');
$email       = form_clean($_POST['email'] ?? '');
$countryCode = form_clean($_POST['country_code'] ?? '');
$phone       = form_clean($_POST['phone'] ?? '');
$area        = form_clean($_POST['area'] ?? '');
$subjectLine = form_clean($_POST['subject'] ?? '');
$text        = form_clean_multiline($_POST['message'] ?? '');
$privacyVersion    = form_clean($_POST['privacy_policy_version'] ?? '');
$disclaimerVersion = form_clean($_POST['disclaimer_version'] ?? '');
$acknowledgedAt    = form_clean($_POST['privacy_acknowledged_at'] ?? '');

if (!preg_match('/^\+\d{1,4}$/', $countryCode)) {
    $countryCode = '';
}

$valid = $first !== '' && strlen($first) <= 100
    && $last !== '' && strlen($last) <= 100
    && filter_var($email, FILTER_VALIDATE_EMAIL) && strlen($email) <= 254
    && $text !== '' && strlen($text) <= 10000
    && ($phone === '' || (strlen(preg_replace('/[^0-9]/', '', $phone)) >= 7 && strlen($phone) <= 40))
    && strlen($area) <= 100
    && strlen($subjectLine) <= 200;

if (!$valid) {
    form_log(SUBMISSIONS_LOG, 'Rejected: invalid or missing fields');
    form_redirect(FORM_PAGE, 'error');
}

$fullName  = $first . ' ' . $last;
$fullPhone = $phone !== '' ? trim($countryCode . ' ' . $phone) : '';

$subject = "[Let's Connect] New Website Inquiry: " . $fullName . ($subjectLine !== '' ? ' — ' . $subjectLine : '');
$message = "You received a new Let's Connect submission:\n\n"
    . "Name:              {$fullName}\n"
    . "Email:             {$email}\n"
    . "Phone:             {$fullPhone}\n"
    . "How can we help:   {$area}\n"
    . "Subject:           {$subjectLine}\n\n"
    . "Message:\n{$text}\n\n"
    . 'Submitted:         ' . gmdate('Y-m-d H:i:s') . " UTC\n"
    . "Privacy consent:   Privacy Policy {$privacyVersion}, Disclaimer {$disclaimerVersion}, at {$acknowledgedAt}\n\n"
    . "Reply to the sender at: {$email}\n";

$sent = form_send_mail($subject, $message);

form_log(SUBMISSIONS_LOG, "Inquiry: {$fullName} <{$email}> | Area: {$area} | Subject: {$subjectLine} | To: " . FORM_MAIL_TO
    . ' | mail(): ' . ($sent ? 'accepted by the server mail agent' : 'FAILED'));

if ($sent) {
    form_redirect(FORM_PAGE, 'success');
} else {
    form_redirect(FORM_PAGE, 'error');
}
