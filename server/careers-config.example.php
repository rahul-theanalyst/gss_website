<?php
/**
 * Copy this file to careers-config.php and fill in your real values.
 * careers-config.php is gitignored — never commit real credentials.
 *
 * Mail settings (recipients, mail_from, smtp, ...) have moved to
 * server/.env — copy server/.env.example instead and see
 * docs/EMAIL-SETUP.md. The keys below are still read as a fallback
 * for anything .env doesn't set, so this file only needs the Ceipal
 * keys now unless you have a reason to keep mail config here too.
 */
return [
    // From the Ceipal widget embed tag:
    //   data-ceipal-api-key="..."          -> api_key
    //   data-ceipal-career-portal-id="..." -> cp_id
    'api_key' => 'YOUR_CEIPAL_API_KEY',
    'cp_id'   => 'YOUR_CEIPAL_CAREER_PORTAL_ID',

    // --- Legacy mail config fallback (prefer server/.env instead) ---
    // 'recipients' => ['contact@globalsoftsystems.com', 'contact@gsspros.com'],
    // 'mail_from'      => 'noreply@globalsoftsystems.com',
    // 'mail_from_name' => 'GSS Careers Portal',
    // 'contact_recipients'     => ['contact@globalsoftsystems.com'],
    // 'contact_mail_from'      => 'noreply@globalsoftsystems.com',
    // 'contact_mail_from_name' => "GSS Let's Connect",
    // 'brevo' => [
    //     'enabled'      => false,
    //     'api_key'      => '',
    //     'sender_email' => '',
    //     'sender_name'  => 'GSS Website',
    // ],
    // 'smtp' => [
    //     'enabled'    => false,
    //     'host'       => 'smtp.example.com',
    //     'port'       => 587,
    //     'username'   => 'user@example.com',
    //     'password'   => 'secret_password',
    //     'encryption' => 'tls', // 'tls', 'ssl', or ''
    // ],
    // 'log_submissions' => true,
];
