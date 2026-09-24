<?php
/**
 * server/lib/env.php
 * ------------------------------------------------------------
 * Minimal KEY=VALUE file reader — no Composer/vendor dependency,
 * matching this project's "no build step" setup (see README.md).
 *
 * Returns the file's values as an array; it does not touch the
 * process environment, so nothing else can override them.
 */

if (!function_exists('gss_read_env_file')) {
    function gss_read_env_file($path) {
        $values = [];
        if (!is_file($path) || !is_readable($path)) {
            return $values;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return $values;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
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

            if ($key !== '') {
                $values[$key] = $value;
            }
        }
        return $values;
    }
}
