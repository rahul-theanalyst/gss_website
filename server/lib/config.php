<?php
/**
 * server/lib/config.php
 * ------------------------------------------------------------
 * Loads the Ceipal careers API configuration for careers-jobs.php.
 *
 * The ONLY source is server/.env.gss_newsite — gitignored, never
 * committed, and blocked from the web by server/.htaccess. No
 * credential is written in any other file.
 */

require_once __DIR__ . '/env.php';

const GSS_SETTINGS_FILE = '/home4/globalso/.env.gss_newsite';

if (!function_exists('gss_load_ceipal_config')) {
    function gss_load_ceipal_config() {
        $settings = gss_read_env_file(GSS_SETTINGS_FILE);
        return [
            'api_key' => $settings['CEIPAL_API_KEY'] ?? '',
            'cp_id'   => $settings['CEIPAL_CP_ID'] ?? '',
        ];
    }
}
