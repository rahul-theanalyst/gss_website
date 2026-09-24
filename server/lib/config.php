<?php

require_once __DIR__ . '/env.php';

const GSS_SETTINGS_FILE = '/home4/globalso/.env.gss_newsite';
// Local development only: the gitignored copy beside server/, used when the
// production file (outside the web root) doesn't exist on this machine.
const GSS_LOCAL_SETTINGS_FILE = __DIR__ . '/../.env.gss_newsite';

if (!function_exists('gss_load_ceipal_config')) {

    function gss_load_ceipal_config() {

        $file = is_readable(GSS_SETTINGS_FILE) ? GSS_SETTINGS_FILE : GSS_LOCAL_SETTINGS_FILE;
        $settings = gss_read_env_file($file);

        return [
            'api_key' => $settings['CEIPAL_API_KEY'] ?? '',
            'cp_id'   => $settings['CEIPAL_CP_ID'] ?? '',
        ];
    }
}