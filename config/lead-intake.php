<?php

return [
    'organization_slug' => env('LEAD_INTAKE_ORGANIZATION_SLUG'),
    'rate_limit_per_minute' => (int) env('LEAD_INTAKE_RATE_LIMIT_PER_MINUTE', 5),
    'contact_consent_version' => env('LEAD_CONTACT_CONSENT_VERSION', 'website-v1'),
    'sms_consent_version' => env('LEAD_SMS_CONSENT_VERSION', 'website-v1'),
    'turnstile_secret' => env('TURNSTILE_SECRET_KEY'),

    'service_interests' => [
        'Security & Monitoring',
        'Video Surveillance',
        'Starlink Installation',
        'Wi-Fi & Networking',
        'Home Theater & AV',
        'Outdoor AV & Lighting',
        'Permanent Lighting',
        'Access Control',
        'Business Phones',
        'Business VoIP',
        'Managed IT / MSP',
        'Technology Support',
        'Other / Not Sure',
    ],
];
