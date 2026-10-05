<?php
/**
 * form-captcha.php
 * ------------------------------------------------------------
 * GET: a fresh CAPTCHA (image or question + token) for the Let's Connect
 * and Careers "Stay connected" forms. js/core/form-captcha.js shows it;
 * contact-submit.php and careers-submit.php check the answer.
 */

require_once __DIR__ . '/lib/form-captcha.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

echo json_encode(gss_form_captcha_new(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
