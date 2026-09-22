<?php
/**
 * server/lib/config.php
 * ------------------------------------------------------------
 * Loads mail configuration (recipients, from-address, SMTP
 * credentials) for careers-submit.php and contact-submit.php.
 *
 * Precedence, highest first:
 *   1. Real environment variables (however the host sets them —
 *      cPanel/HostGator "PHP Environment Variables", Apache
 *      SetEnv, a process manager, etc.)
 *   2. server/.env (gitignored — see .env.example for the list
 *      of keys; this is the recommended place for local testing)
 *   3. server/careers-config.php (gitignored legacy config file;
 *      still used for the unrelated Ceipal api_key/cp_id, and
 *      kept here only as a fallback so nothing breaks if a
 *      value hasn't been migrated to .env yet)
 *   4. Hardcoded defaults below.
 *
 * Credentials never need to live in PHP source — only in .env or
 * the host's own environment, both of which are gitignored / kept
 * out of the repo.
 */

require_once __DIR__ . '/env.php';

if (!function_exists('gss_env')) {
    function gss_env($key, $default = null) {
        $value = getenv($key);
        return ($value === false || $value === '') ? $default : $value;
    }
}

if (!function_exists('gss_env_bool')) {
    function gss_env_bool($key, $default = false) {
        $value = getenv($key);
        if ($value === false || $value === '') {
            return $default;
        }
        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }
}

if (!function_exists('gss_env_list')) {
    // Comma-separated env value -> trimmed, non-empty array of strings.
    function gss_env_list($key, array $default = []) {
        $value = getenv($key);
        if ($value === false || trim($value) === '') {
            return $default;
        }
        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }
}

if (!function_exists('gss_load_legacy_config')) {
    function gss_load_legacy_config() {
        $path = __DIR__ . '/../careers-config.php';
        if (is_file($path)) {
            $config = require $path;
            if (is_array($config)) {
                return $config;
            }
        }
        return [];
    }
}

if (!function_exists('gss_load_mail_config')) {
    function gss_load_mail_config() {
        gss_load_env(__DIR__ . '/../.env');
        $legacy = gss_load_legacy_config();

        $defaultRecipients = ['contact@globalsoftsystems.com', 'contact@gsspros.com'];

        $careersRecipients = gss_env_list(
            'CAREERS_RECIPIENTS',
            (!empty($legacy['recipients']) && is_array($legacy['recipients']))
                ? $legacy['recipients']
                : $defaultRecipients
        );

        $contactRecipients = gss_env_list(
            'CONTACT_RECIPIENTS',
            (!empty($legacy['contact_recipients']) && is_array($legacy['contact_recipients']))
                ? $legacy['contact_recipients']
                : $careersRecipients
        );

        $mailFrom     = gss_env('MAIL_FROM', $legacy['mail_from'] ?? 'noreply@globalsoftsystems.com');
        $mailFromName = gss_env('MAIL_FROM_NAME', $legacy['mail_from_name'] ?? 'GSS Careers Portal');

        $legacySmtp = is_array($legacy['smtp'] ?? null) ? $legacy['smtp'] : [];

        return [
            'careers_recipients'     => $careersRecipients,
            'contact_recipients'     => $contactRecipients,
            'mail_from'              => $mailFrom,
            'mail_from_name'         => $mailFromName,
            'contact_mail_from'      => gss_env('CONTACT_MAIL_FROM', $legacy['contact_mail_from'] ?? $mailFrom),
            'contact_mail_from_name' => gss_env('CONTACT_MAIL_FROM_NAME', $legacy['contact_mail_from_name'] ?? "GSS Let's Connect"),
            'log_submissions'        => gss_env_bool('LOG_SUBMISSIONS', $legacy['log_submissions'] ?? true),
            'smtp' => [
                'enabled'    => gss_env_bool('SMTP_ENABLED', $legacySmtp['enabled'] ?? false),
                'host'       => gss_env('SMTP_HOST', $legacySmtp['host'] ?? ''),
                'port'       => (int)gss_env('SMTP_PORT', $legacySmtp['port'] ?? 587),
                'username'   => gss_env('SMTP_USERNAME', $legacySmtp['username'] ?? ''),
                'password'   => gss_env('SMTP_PASSWORD', $legacySmtp['password'] ?? ''),
                'encryption' => gss_env('SMTP_ENCRYPTION', $legacySmtp['encryption'] ?? 'tls'),
                'from'       => gss_env('SMTP_FROM', $legacySmtp['from'] ?? null),
            ],
        ];
    }
}
