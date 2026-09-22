<?php
/**
 * server/lib/spam-guard.php
 * ------------------------------------------------------------
 * Basic, dependency-free bot protection shared by both form
 * endpoints. No CAPTCHA/external service — two cheap signals:
 *
 * 1. Honeypot: a field real visitors never see or fill in
 *    (hidden off-screen in the page, not just display:none).
 *    Simple bots that auto-fill every input trip it.
 * 2. Timing: the form stamps its own render time via JS; a
 *    submission that arrives implausibly fast is almost
 *    certainly scripted, not a person reading the form.
 *
 * Both checks fail *open* when their signal is missing (e.g. a
 * real visitor with JavaScript disabled won't have a timestamp)
 * so this never blocks a genuine, non-JS submission — it only
 * catches the obvious bot behaviour.
 */

if (!function_exists('gss_is_spam_submission')) {
    function gss_is_spam_submission($post, $minSecondsToFill = 3) {
        // Honeypot field — must always arrive empty.
        $honeypot = trim((string)($post['hp_website'] ?? ''));
        if ($honeypot !== '') {
            return 'honeypot field was filled in';
        }

        // Timing check — only enforced when the timestamp is present.
        $renderedAt = $post['form_rendered_at'] ?? '';
        if ($renderedAt !== '' && ctype_digit((string)$renderedAt)) {
            $elapsedMs = (int)round(microtime(true) * 1000) - (int)$renderedAt;
            if ($elapsedMs >= 0 && $elapsedMs < ($minSecondsToFill * 1000)) {
                return "submitted {$elapsedMs}ms after the form rendered";
            }
        }

        return false;
    }
}
