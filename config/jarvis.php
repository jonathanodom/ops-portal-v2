<?php

return [
    'service_token_ttl_days' => (int) env('JARVIS_SERVICE_TOKEN_TTL_DAYS', 90),
    'api_read_limit_per_minute' => (int) env('JARVIS_API_READ_LIMIT_PER_MINUTE', 120),
    'api_write_limit_per_minute' => (int) env('JARVIS_API_WRITE_LIMIT_PER_MINUTE', 30),
    'api_max_request_bytes' => (int) env('JARVIS_API_MAX_REQUEST_BYTES', 262144),
];
