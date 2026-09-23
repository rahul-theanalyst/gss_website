<?php
/**
 * server/lib/mailer.php
 * ------------------------------------------------------------
 * Shared mail dispatch for the site's form endpoints
 * (careers-submit.php, contact-submit.php). Sends via PHPMailer,
 * using a real SMTP mailbox if one's configured, otherwise PHP's
 * mail() transport (which only works if the host has a local MTA).
 *
 * PHPMailer is vendored directly under server/lib/PHPMailer/src —
 * no Composer — so this still runs as-is under `php -S` locally and
 * on shared hosting alike. See server/lib/PHPMailer/README.md.
 */

if (!function_exists('gss_sanitize_line')) {
    // Strips CR/LF (and their percent-encoded forms) so form input can never
    // inject extra headers or SMTP commands.
    function gss_sanitize_line($str) {
        return trim(str_replace(["\r", "\n", "%0a", "%0d", "%0A", "%0D"], '', (string)$str));
    }
}

if (!function_exists('gss_log_line')) {
    function gss_log_line($logFile, $message) {
        $entry = '[' . date('c') . '] ' . $message . PHP_EOL;
        @file_put_contents($logFile, $entry, FILE_APPEND);
    }
}

if (!function_exists('gss_load_phpmailer')) {
    // Vendored, no Composer: server/lib/PHPMailer/src/{Exception,SMTP,PHPMailer}.php
    function gss_load_phpmailer() {
        if (class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
            return true;
        }
        $src = __DIR__ . '/PHPMailer/src/';
        foreach (['Exception.php', 'SMTP.php', 'PHPMailer.php'] as $file) {
            $path = $src . $file;
            if (!is_file($path)) {
                return false;
            }
            require_once $path;
        }
        return class_exists(\PHPMailer\PHPMailer\PHPMailer::class);
    }
}

/**
 * Sends via PHPMailer — SMTP if $smtpConfig has a host and is enabled,
 * otherwise PHP's mail() transport (still built and encoded by PHPMailer,
 * not a raw @mail() call). Handles its own MIME/multipart/attachment
 * construction and header-injection safety internally.
 */
if (!function_exists('gss_send_via_phpmailer')) {
    function gss_send_via_phpmailer($recipients, $subject, $htmlContent, $plainText, $mailFrom, $mailFromName, $replyToName, $replyToEmail, $attachment, $smtpConfig, $logFile) {
        if (!gss_load_phpmailer()) {
            gss_log_line($logFile, 'PHPMailer skipped: vendored source files not found under server/lib/PHPMailer/src/.');
            return false;
        }

        $mail = new \PHPMailer\PHPMailer\PHPMailer(true); // throw exceptions
        try {
            $mail->CharSet = 'UTF-8';

            $host = trim($smtpConfig['host'] ?? '');
            if (!empty($smtpConfig['enabled']) && $host !== '') {
                $mail->isSMTP();
                $mail->Host       = $host;
                $mail->Port       = (int)($smtpConfig['port'] ?? 587);
                $mail->SMTPAutoTLS = true;
                $enc = strtolower($smtpConfig['encryption'] ?? 'tls');
                if ($enc === 'ssl') {
                    $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
                } elseif ($enc === 'tls') {
                    $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                } else {
                    $mail->SMTPSecure = '';
                    $mail->SMTPAutoTLS = false;
                }
                $user = (string)($smtpConfig['username'] ?? '');
                $pass = (string)($smtpConfig['password'] ?? '');
                if ($user !== '' && $pass !== '') {
                    $mail->SMTPAuth = true;
                    $mail->Username = $user;
                    $mail->Password = $pass;
                } else {
                    $mail->SMTPAuth = false;
                }
            } else {
                $mail->isMail();
            }

            $mail->setFrom($mailFrom, $mailFromName);
            foreach ($recipients as $recipient) {
                $recipient = trim($recipient);
                if ($recipient !== '') {
                    $mail->addAddress($recipient);
                }
            }
            if ($replyToEmail !== '') {
                $mail->addReplyTo($replyToEmail, $replyToName !== '' ? $replyToName : $replyToEmail);
            }

            $mail->Subject = $subject;
            $mail->isHTML(true);
            $mail->Body    = $htmlContent;
            $mail->AltBody = $plainText;

            if ($attachment) {
                $mail->addStringAttachment(
                    $attachment['data'],
                    $attachment['filename'],
                    \PHPMailer\PHPMailer\PHPMailer::ENCODING_BASE64,
                    $attachment['mime'] ?? 'application/octet-stream'
                );
            }

            return $mail->send();
        } catch (\PHPMailer\PHPMailer\Exception $e) {
            gss_log_line($logFile, 'PHPMailer failed: ' . $mail->ErrorInfo);
            return false;
        } catch (\Throwable $e) {
            gss_log_line($logFile, 'PHPMailer failed: ' . $e->getMessage());
            return false;
        }
    }
}

/**
 * $args keys:
 *   recipients      string[]  required
 *   subject         string    required
 *   plainText       string    required
 *   htmlContent     string    required
 *   mailFrom        string    required (envelope + header From address)
 *   mailFromName    string    required
 *   replyToName     string    optional
 *   replyToEmail    string    optional
 *   attachment      array|null  ['filename' => .., 'mime' => .., 'data' => raw bytes]
 *   smtpConfig      array     from config's 'smtp' block — used by PHPMailer
 *                              if enabled, else PHPMailer falls back to mail()
 *   logFile         string    absolute path for delivery diagnostics
 *
 * Returns true only when the message was actually accepted for delivery.
 */
function gss_dispatch_mail(array $args) {
    $recipients   = array_values(array_filter(array_map('trim', $args['recipients'] ?? [])));
    $subject      = gss_sanitize_line($args['subject'] ?? '');
    $plainText    = $args['plainText'] ?? '';
    $htmlContent  = $args['htmlContent'] ?? '';
    $mailFrom     = gss_sanitize_line($args['mailFrom'] ?? 'noreply@globalsoftsystems.com');
    $mailFromName = gss_sanitize_line($args['mailFromName'] ?? 'GSS Website');
    $replyToName  = gss_sanitize_line($args['replyToName'] ?? '');
    $replyToEmail = gss_sanitize_line($args['replyToEmail'] ?? '');
    $attachment   = $args['attachment'] ?? null;
    $smtpConfig   = $args['smtpConfig'] ?? [];
    $logFile      = $args['logFile'] ?? (sys_get_temp_dir() . '/gss-mail.log');

    if (empty($recipients) || $subject === '') {
        return false;
    }

    return gss_send_via_phpmailer(
        $recipients,
        $subject,
        $htmlContent,
        $plainText,
        $mailFrom,
        $mailFromName,
        $replyToName,
        $replyToEmail,
        $attachment,
        $smtpConfig,
        $logFile
    );
}
