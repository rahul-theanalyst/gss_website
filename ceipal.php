<?php

$url = "https://careerapi.ceipal.com/M1JvNlZRZU44WDBhYXRPemt6SEw1Zz09/CareerPortalJobPostings/?page=1";

$data = [
    "from_chatbot" => "0",
    "chatbot_job_title" => "",
    "chatbot_skills" => "",
    "chatbot_city" => "",
    "chatbot_state" => "",
    "chatbot_country" => "",
    "searchkey" => "",
    "country" => "",
    "state" => "",
    "city" => "",
    "industry" => "",
    "status" => "",
    "offering" => "",
    "profession" => "",
    "speciality" => "",
    "job_type" => "",
    "hc_state" => "",
    "page" => "1",
    "api_key" => "M1JvNlZRZU44WDBhYXRPemt6SEw1Zz09",
    "method" => "CareerPortalJobPostings",
    "cp_id" => "Z3RkUkt2OXZJVld2MjFpOVRSTXoxZz09",
    "from_career_portal" => "1"
];

$ch = curl_init($url);

curl_setopt_array($ch, [
    CURLOPT_POST => true,

    CURLOPT_POSTFIELDS => http_build_query($data),

    CURLOPT_RETURNTRANSFER => true,

    CURLOPT_HTTPHEADER => [
        "Content-Type: application/x-www-form-urlencoded",
        "Accept: text/html, */*; q=0.01",
        "Accept-Language: en-US,en;q=0.9",
        "Cache-Control: no-cache",
        "Pragma: no-cache",
        "Origin: https://jobsapi.ceipal.com",
        "Referer: https://jobsapi.ceipal.com/",
        "User-Agent: Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Mobile Safari/537.36"
    ],

    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2
]);

$response = curl_exec($ch);

if ($response === false) {
    http_response_code(500);

    echo "cURL Error: " . curl_error($ch);

    exit;
}

$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

http_response_code($httpCode);

echo $response;