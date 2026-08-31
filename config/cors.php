<?php

$leadIntakeOrigins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env(
        'LEAD_INTAKE_ALLOWED_ORIGINS',
        'https://newdaytech.net,https://www.newdaytech.net',
    )),
)));

return [
    'paths' => ['api/public/v1/leads'],

    'allowed_methods' => ['POST', 'OPTIONS'],

    'allowed_origins' => $leadIntakeOrigins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'Content-Type'],

    'exposed_headers' => [],

    'max_age' => 600,

    'supports_credentials' => false,
];
