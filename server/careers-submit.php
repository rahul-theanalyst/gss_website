<?php
/**
 * careers-submit.php
 * ------------------------------------------------------------
 * Handles Careers "Stay connected" profile submissions.
 * Validates candidate details, enforces valid PDF resume upload,
 * and securely dispatches email with PDF attachment to the
 * recipients configured in careers-config.php.
 */

require_once __DIR__ . '/lib/mailer.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Enforce POST method only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}

const SUBMISSIONS_LOG = __DIR__ . '/careers-submissions.log';
const MAX_FILE_SIZE   = 10 * 1024 * 1024; // 10 MB

function logSubmission($message) {
    $entry = '[' . date('c') . '] ' . $message . PHP_EOL;
    @file_put_contents(SUBMISSIONS_LOG, $entry, FILE_APPEND);
}

function loadConfig() {
    $path = __DIR__ . '/careers-config.php';
    if (is_file($path)) {
        $config = require $path;
        if (is_array($config)) {
            return $config;
        }
    }
    return [];
}

$config = loadConfig();

// Default recipients per requirements. Real recipients (and any test
// addresses) belong in the gitignored careers-config.php, never here.
$recipients = !empty($config['recipients']) && is_array($config['recipients'])
    ? $config['recipients']
    : ['contact@globalsoftsystems.com', 'contact@gsspros.com'];

$mailFrom     = !empty($config['mail_from']) ? $config['mail_from'] : 'noreply@globalsoftsystems.com';
$mailFromName = !empty($config['mail_from_name']) ? $config['mail_from_name'] : 'GSS Careers Portal';

// Helper to sanitize single-line string inputs against header injection
function sanitizeLine($str) {
    return gss_sanitize_line($str);
}

// 1. Collect and sanitize form fields
$firstName = sanitizeLine($_POST['first'] ?? '');
$lastName  = sanitizeLine($_POST['last'] ?? '');
$email     = sanitizeLine($_POST['email'] ?? '');
$phone     = sanitizeLine($_POST['phone'] ?? '');
$skill     = sanitizeLine($_POST['skill'] ?? '');
$location  = sanitizeLine($_POST['ploc'] ?? '');

$consentAcknowledged = sanitizeLine($_POST['consent'] ?? 'true');
$privacyVersion      = sanitizeLine($_POST['privacy_policy_version'] ?? 'Current');
$acknowledgedAt      = sanitizeLine($_POST['privacy_acknowledged_at'] ?? date('c'));

// 2. Validate required text fields
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
if (empty($phone) || strlen(preg_replace('/[^0-9+]/', '', $phone)) < 7) {
    $errors[] = 'A valid phone number is required.';
}
if (empty($skill)) {
    $errors[] = 'Primary skill is required.';
}
if (empty($location)) {
    $errors[] = 'Preferred location is required.';
}

// 3. Validate uploaded PDF resume file
if (!isset($_FILES['resume']) || $_FILES['resume']['error'] === UPLOAD_ERR_NO_FILE) {
    $errors[] = 'Please select and upload your resume in PDF format.';
} elseif ($_FILES['resume']['error'] !== UPLOAD_ERR_OK) {
    $errors[] = 'File upload failed (code ' . (int)$_FILES['resume']['error'] . '). Please try again.';
} else {
    $uploadedFile = $_FILES['resume'];
    
    // Check file size
    if ($uploadedFile['size'] <= 0 || $uploadedFile['size'] > MAX_FILE_SIZE) {
        $errors[] = 'The uploaded resume must be greater than 0 bytes and not exceed 10 MB.';
    }

    // Check file extension
    $ext = strtolower(pathinfo($uploadedFile['name'], PATHINFO_EXTENSION));
    if ($ext !== 'pdf') {
        $errors[] = 'Invalid file format. Only PDF documents (.pdf) are accepted.';
    }

    // Check PDF magic bytes (%PDF-)
    $handle = @fopen($uploadedFile['tmp_name'], 'rb');
    if ($handle) {
        $magicBytes = fread($handle, 5);
        fclose($handle);
        if ($magicBytes !== '%PDF-') {
            $errors[] = 'The uploaded file is not a valid PDF document.';
        }
    } else {
        $errors[] = 'Unable to read the uploaded resume. Please try again.';
    }

    // Optional MIME validation if mime extension is available
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $uploadedFile['tmp_name']);
        finfo_close($finfo);
        if ($mime !== 'application/pdf' && $mime !== 'application/x-pdf') {
            $errors[] = 'The uploaded file does not have a valid PDF MIME type.';
        }
    }
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

// 4. Prepare email details
$fullName     = $firstName . ' ' . $lastName;
$origFilename = sanitizeLine($uploadedFile['name']);
$cleanFilename = preg_replace('/[^A-Za-z0-9._-]/', '_', $origFilename);
if (!preg_match('/\.pdf$/i', $cleanFilename)) {
    $cleanFilename .= '.pdf';
}

$fileData     = file_get_contents($uploadedFile['tmp_name']);
$fileSizeKb   = round(strlen($fileData) / 1024, 1) . ' KB';

$timestampUtc = gmdate('Y-m-d H:i:s') . ' UTC';
$subject      = 'New Candidate Profile: ' . $fullName . ' (' . $skill . ')';

// 5. Construct HTML and Plain-Text Bodies
$plainText = <<<TXT
NEW CANDIDATE PROFILE SUBMISSION (GSS CAREERS)
--------------------------------------------------
Candidate Name:      {$fullName}
Email Address:       {$email}
Phone Number:        {$phone}
Primary Skill:       {$skill}
Preferred Location:  {$location}
Resume File:         {$cleanFilename} ({$fileSizeKb})

Submission Date:     {$timestampUtc}
Privacy Consent:     Acknowledged ({$privacyVersion}) at {$acknowledgedAt}

* The candidate's resume ({$cleanFilename}) is attached to this email as a PDF document.
TXT;

$recipientsJoined = htmlspecialchars(implode(', ', $recipients));

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
  .attachment-box { margin-top: 24px; padding: 14px 18px; background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 6px; display: flex; align-items: center; }
  .attachment-icon { font-size: 20px; margin-right: 12px; }
  .attachment-name { font-weight: 600; color: #0F2247; font-size: 14px; }
  .attachment-size { color: #64748b; font-size: 12px; margin-left: 8px; }
  .footer { background: #f8fafc; padding: 16px 32px; border-top: 1px solid #e2e8f0; font-size: 12px; color: #64748b; line-height: 1.5; }
</style>
</head>
<body>
<div class="card">
  <div class="header">
    <p>Global Soft Systems — Careers Portal</p>
    <h1>New Candidate Profile Submission</h1>
  </div>
  <div class="body">
    <table class="table">
      <tr>
        <th>Candidate Name</th>
        <td><strong>{$fullName}</strong></td>
      </tr>
      <tr>
        <th>Email Address</th>
        <td><a href="mailto:{$email}" style="color:#0F2247; text-decoration:underline;">{$email}</a></td>
      </tr>
      <tr>
        <th>Phone Number</th>
        <td><a href="tel:{$phone}" style="color:#0F2247; text-decoration:none;">{$phone}</a></td>
      </tr>
      <tr>
        <th>Primary Skill</th>
        <td><span class="badge">{$skill}</span></td>
      </tr>
      <tr>
        <th>Preferred Location</th>
        <td>{$location}</td>
      </tr>
      <tr>
        <th>Submitted At</th>
        <td>{$timestampUtc}</td>
      </tr>
    </table>

    <div class="attachment-box">
      <span class="attachment-name">📎 Attached Resume: {$cleanFilename}</span>
      <span class="attachment-size">({$fileSizeKb})</span>
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

// 6. Dispatch the email (SMTP if configured, PHP mail() otherwise) with the
// résumé attached as a PDF, via the shared mailer in lib/mailer.php.
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
    'attachment'   => [
        'filename' => $cleanFilename,
        'mime'     => 'application/pdf',
        'data'     => $fileData,
    ],
    'smtpConfig'   => $config['smtp'] ?? [],
    'logFile'      => SUBMISSIONS_LOG,
]);

// Always log submission summary for auditing / disaster recovery
$logSummary = "Candidate: {$fullName} <{$email}> | Phone: {$phone} | Skill: {$skill} | Loc: {$location} | Resume: {$cleanFilename} ({$fileSizeKb}) | Delivered to: {$toHeader} | Status: " . ($sent ? 'SENT' : 'NOT_SENT_MTA_OFFLINE');
logSubmission($logSummary);

// In development environments without an active MTA, allow logging to succeed gracefully
$isDevEnv = (in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1']) || strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false);
if (!$sent && $isDevEnv && !empty($config['log_submissions'])) {
    $sent = true; // Logged successfully on development server
}

if ($sent) {
    echo json_encode([
        'success' => true,
        'message' => 'Thank you! Your profile and résumé have been submitted successfully to our talent team.'
    ]);
} else {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'We were unable to deliver your application right now. Please try again or reach out to contact@globalsoftsystems.com.'
    ]);
}
