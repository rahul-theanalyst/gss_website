<?php
/**
 * server/lib/config.php
 * ------------------------------------------------------------
 * Loads the Ceipal careers API configuration for careers-jobs.php.
 * (The forms' email settings are not configured here — see
 * lib/form-mail.php.)
 *
 * Precedence, highest first:
 *   1. Real environment variables (however the host sets them —
 *      cPanel/HostGator "PHP Environment Variables", Apache
 *      SetEnv, a process manager, etc.)
 *   2. server/.env (gitignored — see .env.example for the list
 *      of keys; this is the recommended place for local testing)
 *   3. server/careers-config.php (gitignored legacy config file,
 *      kept only as a fallback so nothing breaks if a value
 *      hasn't been migrated to .env yet)
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

if (!function_exists('gss_load_ceipal_config')) {
    // Env vars first (CEIPAL_API_KEY /
    // CEIPAL_CP_ID in server/.env, or the host's own environment), falling back
    // to the legacy server/careers-config.php only if those aren't set.
    function gss_load_ceipal_config() {
        gss_load_env(__DIR__ . '/../.env');
        $legacy = gss_load_legacy_config();

        return [
            'api_key' => gss_env('CEIPAL_API_KEY', $legacy['api_key'] ?? ''),
            'cp_id'   => gss_env('CEIPAL_CP_ID', $legacy['cp_id'] ?? ''),
        ];
    }
}
