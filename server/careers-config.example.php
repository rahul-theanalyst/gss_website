<?php
/**
 * Copy this file to careers-config.php and fill in your real values.
 * careers-config.php is gitignored — never commit real credentials.
 */
return [
    // From the Ceipal widget embed tag:
    //   data-ceipal-api-key="..."          -> api_key
    //   data-ceipal-career-portal-id="..." -> cp_id
    'api_key' => 'YOUR_CEIPAL_API_KEY',
    'cp_id'   => 'YOUR_CEIPAL_CAREER_PORTAL_ID',

    // Careers profile submission email configuration
    'recipients' => [
        'contact@globalsoftsystems.com',
        'contact@gsspros.com',
    ],
    'mail_from'      => 'noreply@globalsoftsystems.com',
    'mail_from_name' => 'GSS Careers Portal',

    // "Let's Connect" contact form — optional; falls back to the careers
    // 'recipients' / 'mail_from' above when left unset.
    // 'contact_recipients'      => ['contact@globalsoftsystems.com'],
    // 'contact_mail_from'       => 'noreply@globalsoftsystems.com',
    // 'contact_mail_from_name'  => "GSS Let's Connect",

    // Optional SMTP settings, shared by both forms (if empty or disabled,
    // uses standard PHP mail() — which needs a local MTA and will silently
    // fail under `php -S`, the built-in dev server). To actually receive
    // mail while developing locally, enable this with real credentials,
    // e.g. Gmail SMTP + an App Password (myaccount.google.com/apppasswords):
    //   host: smtp.gmail.com, port: 587, encryption: tls,
    //   username: your.address@gmail.com, password: <16-char app password>
    'smtp' => [
        'enabled'    => false,
        'host'       => 'smtp.example.com',
        'port'       => 587,
        'username'   => 'user@example.com',
        'password'   => 'secret_password',
        'encryption' => 'tls', // 'tls', 'ssl', or ''
    ],

    // Log submissions to server/careers-submissions.log / contact-submissions.log
    'log_submissions' => true,
];
