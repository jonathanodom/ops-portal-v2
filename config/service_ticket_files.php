<?php

return [
    'disk' => env('SERVICE_TICKET_FILE_DISK', 'local'),
    'max_file_kb' => 20480,
    'mimetypes' => [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/heic',
        'image/heif',
    ],
];
