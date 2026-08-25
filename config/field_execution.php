<?php

return [
    'disk' => env('FIELD_MEDIA_DISK', 'local'), 'max_photos' => 20, 'max_photo_kb' => 20480,
    'ack_signature_disk' => env('FIELD_ACK_SIGNATURE_DISK', env('FIELD_MEDIA_DISK', 'local')),
    'ack_signature_max_bytes' => 1048576,
    'ack_statement_version' => 'service-closeout-v1',
    'ack_statement' => 'I acknowledge that the work described in this service closeout was presented to me. This signature acknowledges the service record and does not itself change pricing or payment terms.',
    'photo_categories' => ['before' => 'Before', 'after' => 'After', 'as_built' => 'As-built', 'damage' => 'Damage', 'serial_model' => 'Serial/model', 'other' => 'Other'],
    'billing_treatments' => ['billable' => 'Billable', 'warranty' => 'Warranty', 'customer_owned' => 'Customer-owned', 'no_charge' => 'No-charge'],
    'ack_fallbacks' => ['poc_not_on_site' => 'POC not on-site', 'representative_unavailable' => 'Representative unavailable', 'declined' => 'POC declined acknowledgment', 'remote_service' => 'Remote service', 'accessibility' => 'Accessibility/language limitation', 'other' => 'Other'],
    'no_photo_reasons' => ['customer_restricted' => 'Customer restricted', 'unsafe' => 'Unsafe conditions', 'equipment_failure' => 'Equipment failure', 'not_applicable' => 'Evidence not applicable', 'other' => 'Other'],
    'unavailable_reasons' => ['no_answer' => 'No answer', 'site_closed' => 'Site closed', 'access_denied' => 'Access denied', 'customer_rescheduled' => 'Customer rescheduled', 'other' => 'Other'],
];
