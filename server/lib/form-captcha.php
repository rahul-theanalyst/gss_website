<?php
/**
 * server/lib/form-captcha.php
 * ------------------------------------------------------------
 * Image CAPTCHA for the site's plain forms (Let's Connect and the
 * Careers "Stay connected" form). The browser gets a challenge from
 * server/form-captcha.php; the form handlers check the answer with
 * gss_form_captcha_valid() before anything is emailed.
 *
 * Same scheme as the Easy Apply CAPTCHA in careers-apply.php, kept
 * separate (own secret, own used-token store) so Easy Apply is untouched.
 *
 * Stateless: the answer is never stored. The browser gets an image plus a
 * token "nonce.expiry.signature", where the signature is an HMAC of the
 * nonce, expiry and answer under a server-only secret. On submit the typed
 * answer must reproduce that signature before the expiry, and each nonce
 * is accepted once. Without GD (no image support) it falls back to a small
 * arithmetic question, checked the same way.
 */

const FORM_CAPTCHA_TTL      = 20 * 60;  // a CAPTCHA must be solved within 20 minutes
const FORM_CAPTCHA_LENGTH   = 5;
// no 0/O, 1/I/L: characters that are easy to confuse in a distorted image
const FORM_CAPTCHA_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
const FORM_CAPTCHA_SECRET_FILE = __DIR__ . '/../.form-captcha-secret'; // gitignored; server/.htaccess blocks it

function gss_form_captcha_secret() {
    $secret = is_readable(FORM_CAPTCHA_SECRET_FILE) ? trim((string) @file_get_contents(FORM_CAPTCHA_SECRET_FILE)) : '';
    if (strlen($secret) < 32) {
        $secret = bin2hex(random_bytes(32));
        if (@file_put_contents(FORM_CAPTCHA_SECRET_FILE, $secret, LOCK_EX) === false) {
            error_log('form-captcha: could not write ' . basename(FORM_CAPTCHA_SECRET_FILE) . '; CAPTCHAs will not survive between requests');
        }
        @chmod(FORM_CAPTCHA_SECRET_FILE, 0600);
    }
    return $secret;
}

function gss_form_captcha_sign($nonce, $expiry, $answer) {
    return hash_hmac('sha256', $nonce . '|' . $expiry . '|' . strtoupper($answer), gss_form_captcha_secret());
}

/** PNG of the code: each character drawn with GD's built-in font, enlarged,
 *  rotated and offset on its own, over a faint pattern of lines and dots. */
function gss_form_captcha_image($code) {
    $w = 190; $h = 62;
    $img = imagecreatetruecolor($w, $h);
    imagefill($img, 0, 0, imagecolorallocate($img, 244, 246, 250));
    for ($i = 0; $i < 7; $i++) {
        $c = imagecolorallocate($img, random_int(150, 205), random_int(160, 210), random_int(185, 225));
        imagesetthickness($img, random_int(1, 2));
        imageline($img, random_int(0, $w), random_int(0, $h), random_int(0, $w), random_int(0, $h), $c);
    }
    imagesetthickness($img, 1);
    for ($i = 0; $i < 260; $i++) {
        imagesetpixel($img, random_int(0, $w - 1), random_int(0, $h - 1),
                      imagecolorallocate($img, random_int(120, 200), random_int(130, 205), random_int(160, 220)));
    }
    $font = 5; $fw = imagefontwidth($font); $fh = imagefontheight($font);
    $scale = 2.6;
    $step = ($w - 20) / strlen($code);
    for ($i = 0; $i < strlen($code); $i++) {
        $glyph = imagecreatetruecolor($fw, $fh);
        $bg = imagecolorallocate($glyph, 255, 255, 255);
        imagefill($glyph, 0, 0, $bg);
        imagecolortransparent($glyph, $bg);
        imagestring($glyph, $font, 0, 0, $code[$i],
                    imagecolorallocate($glyph, random_int(10, 45), random_int(25, 55), random_int(70, 110)));
        $gw = (int) round($fw * $scale); $gh = (int) round($fh * $scale);
        $big = imagecreatetruecolor($gw, $gh);
        $bigBg = imagecolorallocate($big, 255, 255, 255);
        imagefill($big, 0, 0, $bigBg);
        imagecolortransparent($big, $bigBg);
        imagecopyresized($big, $glyph, 0, 0, 0, 0, $gw, $gh, $fw, $fh);
        $rot = imagerotate($big, random_int(-22, 22), $bigBg);
        imagecolortransparent($rot, imagecolorat($rot, 0, 0));
        $x = (int) (10 + $i * $step + random_int(-3, 3));
        $y = (int) (($h - imagesy($rot)) / 2 + random_int(-5, 5));
        imagecopymerge($img, $rot, $x, $y, 0, 0, imagesx($rot), imagesy($rot), 100);
    }
    // two lines across the text, drawn last so they cross the characters
    for ($i = 0; $i < 2; $i++) {
        $c = imagecolorallocate($img, random_int(60, 110), random_int(80, 120), random_int(130, 170));
        imagesetthickness($img, 2);
        imageline($img, 0, random_int(12, $h - 12), $w, random_int(12, $h - 12), $c);
    }
    ob_start();
    imagepng($img);
    return 'data:image/png;base64,' . base64_encode((string) ob_get_clean());
}

/** A new challenge for the browser: the image (or question) and its token. */
function gss_form_captcha_new() {
    $nonce = bin2hex(random_bytes(12));
    $expiry = time() + FORM_CAPTCHA_TTL;
    if (function_exists('imagecreatetruecolor') && function_exists('imagepng')) {
        $code = '';
        for ($i = 0; $i < FORM_CAPTCHA_LENGTH; $i++) {
            $code .= FORM_CAPTCHA_ALPHABET[random_int(0, strlen(FORM_CAPTCHA_ALPHABET) - 1)];
        }
        return ['ok' => true, 'token' => $nonce . '.' . $expiry . '.' . gss_form_captcha_sign($nonce, $expiry, $code),
                'image' => gss_form_captcha_image($code)];
    }
    $a = random_int(2, 9); $b = random_int(2, 9);
    return ['ok' => true, 'token' => $nonce . '.' . $expiry . '.' . gss_form_captcha_sign($nonce, $expiry, (string) ($a + $b)),
            'question' => "What is $a + $b?"];
}

/** Marks a nonce as used. False if it was already used. If the temp dir
 *  can't be written, replay protection fails open; the signature and
 *  expiry checks still apply. */
function gss_form_captcha_use_nonce($nonce) {
    $file = sys_get_temp_dir() . '/gss-form-captcha-' . $nonce;
    if (file_exists($file)) return false;
    $fh = @fopen($file, 'x');
    if ($fh === false) return !file_exists($file);
    fclose($fh);
    return true;
}

/** True if the typed answer matches an unexpired, unused token. The nonce
 *  is used up either way, so every attempt needs a fresh CAPTCHA. */
function gss_form_captcha_valid($token, $answer) {
    if (!is_string($token) || !preg_match('/^([a-f0-9]{24})\.(\d{10})\.([a-f0-9]{64})$/', $token, $m)) return false;
    [, $nonce, $expiry, $sig] = $m;
    if ((int) $expiry < time()) return false;
    if (!gss_form_captcha_use_nonce($nonce)) return false;
    $answer = strtoupper(preg_replace('/\s+/', '', (string) $answer));
    return $answer !== '' && hash_equals(gss_form_captcha_sign($nonce, (int) $expiry, $answer), $sig);
}
