<?php

return [
    'live_enabled' => (bool) env('PAYMENTS_LIVE_ENABLED', false),
    'fake' => (bool) env('PAYMENTS_FAKE_PROVIDER', false),
    'private_disk' => env('PRIVATE_UPLOAD_DISK', 'local'),
    'attempt_minutes' => 60,
];
