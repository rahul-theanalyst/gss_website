<?php
/**
 * server/lib/mailer.php
 * ------------------------------------------------------------
 * Shared mail dispatch for the site's form endpoints
 * (careers-submit.php, contact-submit.php). Tries three
 * transports in order, first one configured and successful wins:
 *
 *   1. Brevo transactional email API (gss_send_via_brevo) — the
 *      recommended path. A service API key + one verified sender
 *      address; no personal email account or password involved.
 *   2. Raw-socket SMTP (gss_send_via_smtp) — optional, for a real
 *      mailbox you control (e.g. once the company domain has one).
 *   3. PHP's mail() — only works if the host has a local MTA.
 *
 * Kept dependency-free (no Composer/PHPMailer) so it runs as-is
 * under `php -S` locally and on shared hosting alike.
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

/**
 * Sends via the Brevo (formerly Sendinblue) transactional email HTTP API.
 * Auth is a single API key (no inbox login, no password, no app password)
 * and the "From" address must be one verified in the Brevo dashboard —
 * see docs/EMAIL-SETUP.md. https://api.brevo.com/v3/smtp/email
 */
if (!function_exists('gss_send_via_brevo')) {
    function gss_send_via_brevo($recipients, $subject, $htmlContent, $plainText, $senderEmail, $senderName, $replyToName, $replyToEmail, $apiKey, $attachment, $logFile) {
        if (!function_exists('curl_init')) {
            gss_log_line($logFile, 'Brevo API skipped: the PHP curl extension is not available.');
            return false;
        }
        if ($apiKey === '' || $senderEmail === '') {
            return false;
        }

        $payload = [
            'sender'      => ['email' => $senderEmail, 'name' => $senderName !== '' ? $senderName : $senderEmail],
            'to'          => array_map(function ($email) { return ['email' => trim($email)]; }, $recipients),
            'subject'     => $subject,
            'htmlContent' => $htmlContent,
            'textContent' => $plainText,
        ];

        if ($replyToEmail !== '') {
            $payload['replyTo'] = ['email' => $replyToEmail, 'name' => $replyToName !== '' ? $replyToName : $replyToEmail];
        }

        if ($attachment) {
            $payload['attachment'] = [[
                'content' => base64_encode($attachment['data']),
                'name'    => $attachment['filename'],
            ]];
        }

        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'accept: application/json',
                'api-key: ' . $apiKey,
                'content-type: application/json',
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 20,
        ]);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        // curl_close() is a no-op (deprecated) as of PHP 8.0+; the handle
        // is freed automatically once $ch goes out of scope.

        if ($response === false) {
            gss_log_line($logFile, "Brevo API request failed: {$curlError}");
            return false;
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            return true;
        }

        gss_log_line($logFile, "Brevo API rejected the message (HTTP {$httpCode}): " . trim($response));
        return false;
    }
}

/**
 * Raw-socket SMTP client (EHLO / STARTTLS / AUTH LOGIN / MAIL-RCPT-DATA).
 * Unlike a "fire and hope" sender, every stage checks the server's reply
 * code and fails loudly (logged) instead of reporting a false success.
 */
if (!function_exists('gss_send_via_smtp')) {
    function gss_send_via_smtp($recipients, $fromAddr, $subject, $headerLines, $mimeBody, $smtpConfig, $logFile) {
        $host = trim($smtpConfig['host'] ?? '');
        $port = (int)($smtpConfig['port'] ?? 587);
        $user = $smtpConfig['username'] ?? '';
        $pass = $smtpConfig['password'] ?? '';
        $enc  = strtolower($smtpConfig['encryption'] ?? 'tls');

        if ($host === '') {
            return false;
        }

        $protocol = ($enc === 'ssl') ? 'ssl://' : '';
        $socket = @fsockopen($protocol . $host, $port, $errno, $errstr, 15);
        if (!$socket) {
            gss_log_line($logFile, "SMTP connection failed to {$host}:{$port} - {$errstr} ({$errno})");
            return false;
        }
        stream_set_timeout($socket, 20);

        $readReply = function () use ($socket) {
            $data = '';
            while (($line = fgets($socket, 515)) !== false) {
                $data .= $line;
                // Multi-line replies use "250-text"; the final line uses "250 text".
                if (isset($line[3]) && $line[3] === ' ') break;
            }
            return $data;
        };
        $command = function ($cmd) use ($socket, $readReply) {
            fputs($socket, $cmd . "\r\n");
            return $readReply();
        };
        $codeOf = function ($reply) {
            return (int)substr($reply, 0, 3);
        };

        $greeting = $readReply();
        if ($codeOf($greeting) !== 220) {
            gss_log_line($logFile, "SMTP greeting failed from {$host}: " . trim($greeting));
            fclose($socket);
            return false;
        }

        $command('EHLO ' . (gethostname() ?: 'localhost'));

        if ($enc === 'tls') {
            $tlsReply = $command('STARTTLS');
            if ($codeOf($tlsReply) !== 220 || !stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                gss_log_line($logFile, "SMTP STARTTLS failed on {$host}: " . trim($tlsReply));
                fclose($socket);
                return false;
            }
            $command('EHLO ' . (gethostname() ?: 'localhost'));
        }

        if ($user !== '' && $pass !== '') {
            $authReply = $command('AUTH LOGIN');
            if ($codeOf($authReply) !== 334) {
                gss_log_line($logFile, "SMTP AUTH LOGIN not accepted by {$host}: " . trim($authReply));
                fclose($socket);
                return false;
            }
            $command(base64_encode($user));
            $passReply = $command(base64_encode($pass));
            if ($codeOf($passReply) !== 235) {
                gss_log_line($logFile, "SMTP authentication failed for {$user}: " . trim($passReply));
                fclose($socket);
                return false;
            }
        }

        $mailFromReply = $command('MAIL FROM:<' . $fromAddr . '>');
        if ($codeOf($mailFromReply) !== 250) {
            gss_log_line($logFile, "SMTP MAIL FROM rejected: " . trim($mailFromReply));
            fclose($socket);
            return false;
        }

        $acceptedAny = false;
        foreach ($recipients as $recipient) {
            $rcptReply = $command('RCPT TO:<' . trim($recipient) . '>');
            if ($codeOf($rcptReply) === 250 || $codeOf($rcptReply) === 251) {
                $acceptedAny = true;
            } else {
                gss_log_line($logFile, "SMTP RCPT TO rejected for {$recipient}: " . trim($rcptReply));
            }
        }
        if (!$acceptedAny) {
            gss_log_line($logFile, "SMTP: no recipients were accepted, aborting send.");
            $command('QUIT');
            fclose($socket);
            return false;
        }

        $dataReply = $command('DATA');
        if ($codeOf($dataReply) !== 354) {
            gss_log_line($logFile, "SMTP DATA rejected: " . trim($dataReply));
            fclose($socket);
            return false;
        }

        $allHeaders = implode("\r\n", $headerLines);
        $finalReply = $command(
            "Subject: {$subject}\r\nTo: " . implode(', ', $recipients) . "\r\n{$allHeaders}\r\n\r\n{$mimeBody}\r\n."
        );
        $sent = $codeOf($finalReply) === 250;
        if (!$sent) {
            gss_log_line($logFile, "SMTP message rejected after DATA: " . trim($finalReply));
        }

        $command('QUIT');
        fclose($socket);
        return $sent;
    }
}

/**
 * Builds the MIME body (multipart/alternative text+HTML, optionally wrapped
 * in multipart/mixed with a single attachment) and headers, then dispatches
 * via SMTP (if enabled and reachable) or PHP's mail() as a fallback.
 *
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
 *   brevoConfig     array     from config's 'brevo' block — tried first
 *   smtpConfig      array     from config's 'smtp' block — tried second
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
    $brevoConfig  = $args['brevoConfig'] ?? [];
    $smtpConfig   = $args['smtpConfig'] ?? [];
    $logFile      = $args['logFile'] ?? (sys_get_temp_dir() . '/gss-mail.log');

    if (empty($recipients) || $subject === '') {
        return false;
    }

    $mixedBoundary = '==_GSS_MIXED_' . md5(uniqid('', true));
    $altBoundary   = '==_GSS_ALT_'   . md5(uniqid('', true));

    $headers = [];
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'From: "' . addcslashes($mailFromName, '"') . '" <' . $mailFrom . '>';
    if ($replyToEmail !== '') {
        $replyLabel = $replyToName !== '' ? $replyToName : $replyToEmail;
        $headers[] = 'Reply-To: "' . addcslashes($replyLabel, '"') . '" <' . $replyToEmail . '>';
    }
    $headers[] = 'X-Mailer: PHP/' . phpversion();
    $headers[] = $attachment
        ? 'Content-Type: multipart/mixed; boundary="' . $mixedBoundary . '"'
        : 'Content-Type: multipart/alternative; boundary="' . $altBoundary . '"';

    $alt = "--" . $altBoundary . "\r\n";
    $alt .= "Content-Type: text/plain; charset=UTF-8; format=flowed\r\n";
    $alt .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
    $alt .= $plainText . "\r\n\r\n";
    $alt .= "--" . $altBoundary . "\r\n";
    $alt .= "Content-Type: text/html; charset=UTF-8\r\n";
    $alt .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $alt .= $htmlContent . "\r\n\r\n";
    $alt .= "--" . $altBoundary . "--\r\n";

    if ($attachment) {
        $filename = $attachment['filename'];
        $mime     = $attachment['mime'] ?? 'application/octet-stream';
        $data     = base64_encode($attachment['data']);
        $data     = chunk_split($data);

        $body  = "--" . $mixedBoundary . "\r\n";
        $body .= "Content-Type: multipart/alternative; boundary=\"" . $altBoundary . "\"\r\n\r\n";
        $body .= $alt . "\r\n";
        $body .= "--" . $mixedBoundary . "\r\n";
        $body .= 'Content-Type: ' . $mime . '; name="' . $filename . '"' . "\r\n";
        $body .= 'Content-Disposition: attachment; filename="' . $filename . '"' . "\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $body .= $data . "\r\n\r\n";
        $body .= "--" . $mixedBoundary . "--\r\n";
    } else {
        $body = $alt;
    }

    $sent = false;

    if (!empty($brevoConfig['enabled'])) {
        $sent = gss_send_via_brevo(
            $recipients,
            $subject,
            $htmlContent,
            $plainText,
            gss_sanitize_line($brevoConfig['sender_email'] ?? ''),
            gss_sanitize_line($brevoConfig['sender_name'] ?? $mailFromName),
            $replyToName,
            $replyToEmail,
            trim((string)($brevoConfig['api_key'] ?? '')),
            $attachment,
            $logFile
        );
    }

    if (!$sent && !empty($smtpConfig['enabled']) && !empty($smtpConfig['host'])) {
        $envelopeFrom = $smtpConfig['from'] ?? $mailFrom;
        $sent = gss_send_via_smtp($recipients, $envelopeFrom, $subject, $headers, $body, $smtpConfig, $logFile);
    }

    if (!$sent) {
        $toHeader = implode(', ', $recipients);
        $headerString = implode("\r\n", $headers);
        $sent = @mail($toHeader, $subject, $body, $headerString);
        if (!$sent) {
            gss_log_line($logFile, 'PHP mail() returned false (no local MTA, or the host rejected the message).');
        }
    }

    return $sent;
}
