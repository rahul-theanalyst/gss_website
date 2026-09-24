<?php
/**
 * server/lib/form-mail.php
 * ------------------------------------------------------------
 * Shared by careers-submit.php and contact-submit.php. Sends with
 * PHP's native mail() — no SMTP, no passwords, no libraries. On
 * HostGator, mail() hands the message to the server's own mail
 * agent, which is authorised to send for globalsoftsystems.com.
 */

const FORM_MAIL_TO   = 'erah@globalsoftsystems.com, erah@gsspros.com';
// Must be an address on the domain hosted on this server, or Outlook and
// others will likely reject/spam the message.
const FORM_MAIL_FROM = 'noreply@globalsoftsystems.com';

// Strips CR/LF so form input can never inject extra mail headers.
function form_clean($value) {
    return trim(str_replace(["\r", "\n"], ' ', (string)$value));
}

// Keeps line breaks (for the message textarea) but normalises them.
function form_clean_multiline($value) {
    return trim(str_replace("\r", '', (string)$value));
}

function form_log($logFile, $message) {
    @file_put_contents($logFile, '[' . date('c') . '] ' . $message . PHP_EOL, FILE_APPEND);
}

// Redirect back to the form's own page and stop, e.g.
// ../html/career.html?status=success
function form_redirect($page, $status) {
    header('Location: ' . $page . '?status=' . $status);
    exit();
}

/**
 * Sends a plain-text email with mail(); optionally one attachment
 * ['filename' => ..., 'mime' => ..., 'data' => raw bytes].
 * Returns mail()'s result: true = accepted by the server's mail agent.
 */
function form_send_mail($subject, $body, $attachment = null) {
    // UTF-8-safe subject (names may contain accents).
    $encodedSubject = '=?UTF-8?B?' . base64_encode(form_clean($subject)) . '?=';

    $headers  = 'From: ' . FORM_MAIL_FROM . "\r\n";
    $headers .= 'Reply-To: ' . FORM_MAIL_FROM . "\r\n";
    $headers .= "MIME-Version: 1.0\r\n";

    if ($attachment === null) {
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $headers .= "Content-Transfer-Encoding: 8bit\r\n";
        $message  = $body;
    } else {
        $boundary = 'gss_' . bin2hex(random_bytes(12));
        $headers .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n";
        $filename = str_replace(['"', "\r", "\n"], '', $attachment['filename']);

        $message  = "--{$boundary}\r\n";
        $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $message .= $body . "\r\n\r\n";
        $message .= "--{$boundary}\r\n";
        $message .= "Content-Type: {$attachment['mime']}; name=\"{$filename}\"\r\n";
        $message .= "Content-Transfer-Encoding: base64\r\n";
        $message .= "Content-Disposition: attachment; filename=\"{$filename}\"\r\n\r\n";
        $message .= chunk_split(base64_encode($attachment['data'])) . "\r\n";
        $message .= "--{$boundary}--\r\n";
    }

    // -f sets the envelope sender (bounce address) to the same domain
    // address, which HostGator's mail agent expects.
    return @mail(FORM_MAIL_TO, $encodedSubject, $message, $headers, '-f' . FORM_MAIL_FROM);
}
