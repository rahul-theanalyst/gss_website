<?php
/**
 * server/lib/env.php
 * ------------------------------------------------------------
 * Minimal .env loader — no Composer/vendor dependency, matching
 * this project's "no build step" setup (see README.md).
 *
 * Reads KEY=VALUE lines from server/.env (gitignored, never
 * committed) into getenv()/$_ENV. Real OS/host environment
 * variables always win — this only fills in gaps, so the exact
 * same code works locally (via .env) and on a host that sets
 * variables through its own control panel.
 */

if (!function_exists('gss_load_env')) {
    function gss_load_env($path) {
        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (strpos($line, '=') === false) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key   = trim($key);
            $value = trim($value);

            // Strip a single layer of matching quotes, e.g. NAME="GSS Careers".
            $len = strlen($value);
            if ($len >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[$len - 1] === $value[0]) {
                $value = substr($value, 1, $len - 2);
            }

            if ($key === '') {
                continue;
            }

            // Don't clobber a variable the real environment already set.
            if (getenv($key) === false) {
                putenv($key . '=' . $value);
                $_ENV[$key] = $value;
            }
        }
    }
}
