<?php
/**
 * contact-submit.php
 * ------------------------------------------------------------
 * Handles the "Let's Connect" inquiry form. Validates the
 * submitted fields, screens for basic spam, and dispatches a
 * styled email to the recipients from server/.env (see
 * lib/config.php for the full precedence chain).
 */

require_once __DIR__ . '/lib/mailer.php';
require_once __DIR__ . '/lib/config.php';
require_once __DIR__ . '/lib/spam-guard.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Enforce POST method only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}

const SUBMISSIONS_LOG = __DIR__ . '/contact-submissions.log';

function logSubmission($message) {
    $entry = '[' . date('c') . '] ' . $message . PHP_EOL;
    @file_put_contents(SUBMISSIONS_LOG, $entry, FILE_APPEND);
}

$config = gss_load_mail_config();

$recipients   = $config['contact_recipients'];
$mailFrom     = $config['contact_mail_from'];
$mailFromName = $config['contact_mail_from_name'];

function sanitizeLine($str) {
    return gss_sanitize_line($str);
}

// 0. Spam screening. Bots get a generic success response (so scripted
// retries don't learn anything from the failure) but no email is sent.
$spamReason = gss_is_spam_submission($_POST);
if ($spamReason !== false) {
    logSubmission('Blocked suspected spam submission: ' . $spamReason);
    echo json_encode([
        'success' => true,
        'message' => 'Thank you! Your message has been sent — we\'ll be in touch soon.'
    ]);
    exit;
}

// 1. Collect and sanitize form fields
$firstName    = sanitizeLine($_POST['first'] ?? '');
$lastName     = sanitizeLine($_POST['last'] ?? '');
$email        = sanitizeLine($_POST['email'] ?? '');
$countryCode  = sanitizeLine($_POST['country_code'] ?? '');
$phone        = sanitizeLine($_POST['phone'] ?? '');
$area         = sanitizeLine($_POST['area'] ?? '');
$subjectLine  = sanitizeLine($_POST['subject'] ?? '');
// Message is the only multi-line field: strip only carriage returns so
// paragraph breaks survive, and drop any blank line an injection attempt
// might rely on to smuggle extra headers.
$message      = trim(str_replace("\r", '', (string)($_POST['message'] ?? '')));

$consentAcknowledged = sanitizeLine($_POST['consent'] ?? 'true');
$privacyVersion      = sanitizeLine($_POST['privacy_policy_version'] ?? 'Current');
$acknowledgedAt      = sanitizeLine($_POST['privacy_acknowledged_at'] ?? date('c'));

// 2. Validate required fields
$errors = [];
if (empty($firstName)) {
    $errors[] = 'First name is required.';
}
if (empty($lastName)) {
    $errors[] = 'Last name is required.';
}
if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'A valid email address is required.';
}
if (empty($message)) {
    $errors[] = 'Please include a short message so we know how to help.';
}
// Phone is optional, but if it was entered it should look like a phone number.
if (!empty($phone) && strlen(preg_replace('/[^0-9+]/', '', $phone)) < 7) {
    $errors[] = 'The phone number entered does not look valid.';
}

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => implode(' ', $errors),
        'errors'  => $errors
    ]);
    exit;
}

// 3. Prepare email details
$fullName     = trim($firstName . ' ' . $lastName);
$fullPhone    = trim($countryCode . ' ' . $phone);
$timestampUtc = gmdate('Y-m-d H:i:s') . ' UTC';
$subject      = 'New Website Inquiry: ' . $fullName . ($subjectLine !== '' ? ' — ' . $subjectLine : '');

// 4. Construct HTML and Plain-Text Bodies
$plainText = <<<TXT
NEW INQUIRY (GSS LET'S CONNECT)
--------------------------------------------------
Name:                {$fullName}
Email Address:       {$email}
Phone Number:        {$fullPhone}
Area of Interest:    {$area}
Subject:             {$subjectLine}

Message:
{$message}

Submission Date:     {$timestampUtc}
Privacy Consent:     Acknowledged ({$privacyVersion}) at {$acknowledgedAt}
TXT;

$recipientsJoined = htmlspecialchars(implode(', ', $recipients));
$messageHtml      = nl2br(htmlspecialchars($message), false);

$htmlContent = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<style>
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #f4f6f9; margin: 0; padding: 24px; color: #1e293b; }
  .card { max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 8px; border: 1px solid #e2e8f0; overflow: hidden; box-shadow: 0 4px 12px rgba(15, 34, 71, 0.06); }
  .header { background: #0F2247; padding: 24px 32px; border-bottom: 3px solid #C79A4B; }
  .header h1 { margin: 0; color: #ffffff; font-size: 20px; font-weight: 600; letter-spacing: 0.5px; }
  .header p { margin: 6px 0 0; color: #C79A4B; font-size: 13px; text-transform: uppercase; letter-spacing: 1px; font-weight: 700; }
  .body { padding: 28px 32px; }
  .table { width: 100%; border-collapse: collapse; margin-top: 12px; }
  .table th { text-align: left; padding: 10px 12px; font-size: 12px; text-transform: uppercase; color: #64748b; font-weight: 700; border-bottom: 1px solid #e2e8f0; width: 35%; }
  .table td { padding: 10px 12px; font-size: 15px; color: #0F2247; font-weight: 500; border-bottom: 1px solid #f1f5f9; }
  .badge { display: inline-block; padding: 3px 10px; background: rgba(199, 154, 75, 0.15); color: #8a641d; border-radius: 9999px; font-weight: 600; font-size: 12px; }
  .message-box { margin-top: 24px; padding: 18px 20px; background: #f8fafc; border: 1px solid #e2e8f0; border-left: 3px solid #C79A4B; border-radius: 6px; }
  .message-box__label { margin: 0 0 8px; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; color: #64748b; font-weight: 700; }
  .message-box__text { margin: 0; font-size: 15px; line-height: 1.6; color: #0F2247; white-space: normal; }
  .footer { background: #f8fafc; padding: 16px 32px; border-top: 1px solid #e2e8f0; font-size: 12px; color: #64748b; line-height: 1.5; }
</style>
</head>
<body>
<div class="card">
  <div class="header">
    <p>Global Soft Systems — Let&rsquo;s Connect</p>
    <h1>New Website Inquiry</h1>
  </div>
  <div class="body">
    <table class="table">
      <tr>
        <th>Name</th>
        <td><strong>{$fullName}</strong></td>
      </tr>
      <tr>
        <th>Email Address</th>
        <td><a href="mailto:{$email}" style="color:#0F2247; text-decoration:underline;">{$email}</a></td>
      </tr>
HTML;

if ($fullPhone !== '') {
    $htmlContent .= <<<HTML

      <tr>
        <th>Phone Number</th>
        <td><a href="tel:{$fullPhone}" style="color:#0F2247; text-decoration:none;">{$fullPhone}</a></td>
      </tr>
HTML;
}

if ($area !== '') {
    $htmlContent .= <<<HTML

      <tr>
        <th>Area of Interest</th>
        <td><span class="badge">{$area}</span></td>
      </tr>
HTML;
}

if ($subjectLine !== '') {
    $htmlContent .= <<<HTML

      <tr>
        <th>Subject</th>
        <td>{$subjectLine}</td>
      </tr>
HTML;
}

$htmlContent .= <<<HTML

      <tr>
        <th>Submitted At</th>
        <td>{$timestampUtc}</td>
      </tr>
    </table>

    <div class="message-box">
      <p class="message-box__label">Message</p>
      <p class="message-box__text">{$messageHtml}</p>
    </div>
  </div>
  <div class="footer">
    Privacy Policy Consent: Acknowledged ({$privacyVersion})<br>
    This automated notification was delivered to: {$recipientsJoined}
  </div>
</div>
</body>
</html>
HTML;

// 5. Dispatch the email (SMTP if configured, PHP mail() otherwise) via the
// shared mailer in lib/mailer.php.
$toHeader = implode(', ', $recipients);
$sent = gss_dispatch_mail([
    'recipients'   => $recipients,
    'subject'      => $subject,
    'plainText'    => $plainText,
    'htmlContent'  => $htmlContent,
    'mailFrom'     => $mailFrom,
    'mailFromName' => $mailFromName,
    'replyToName'  => $fullName,
    'replyToEmail' => $email,
    'smtpConfig'   => $config['smtp'] ?? [],
    'logFile'      => SUBMISSIONS_LOG,
]);

// Always log submission summary for auditing / disaster recovery
$logSummary = "Inquiry: {$fullName} <{$email}> | Phone: {$fullPhone} | Area: {$area} | Subject: {$subjectLine} | Delivered to: {$toHeader} | Status: " . ($sent ? 'SENT' : 'NOT_SENT_MTA_OFFLINE');
logSubmission($logSummary);

// In development environments without an active MTA, allow logging to succeed gracefully
$isDevEnv = (in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1']) || strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false);
if (!$sent && $isDevEnv && !empty($config['log_submissions'])) {
    $sent = true; // Logged successfully on development server
}

if ($sent) {
    echo json_encode([
        'success' => true,
        'message' => 'Thank you! Your message has been sent — we\'ll be in touch soon.'
    ]);
} else {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'We were unable to deliver your message right now. Please try again or reach out to contact@globalsoftsystems.com.'
    ]);
}
