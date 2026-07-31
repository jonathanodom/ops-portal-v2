<?php

return [
    'disk' => env('FIELD_MEDIA_DISK', 'local'), 'max_photos' => 20, 'max_photo_kb' => 20480,
    'photo_categories' => ['before' => 'Before', 'after' => 'After', 'as_built' => 'As-built', 'damage' => 'Damage', 'serial_model' => 'Serial/model', 'other' => 'Other'],
    'billing_treatments' => ['billable' => 'Billable', 'warranty' => 'Warranty', 'customer_owned' => 'Customer-owned', 'no_charge' => 'No-charge'],
    'ack_fallbacks' => ['representative_unavailable' => 'Representative unavailable', 'declined' => 'Declined', 'remote_service' => 'Remote service', 'accessibility' => 'Accessibility/language barrier', 'other' => 'Other'],
    'no_photo_reasons' => ['customer_restricted' => 'Customer restricted', 'unsafe' => 'Unsafe conditions', 'equipment_failure' => 'Equipment failure', 'not_applicable' => 'Evidence not applicable', 'other' => 'Other'],
    'unavailable_reasons' => ['no_answer' => 'No answer', 'site_closed' => 'Site closed', 'access_denied' => 'Access denied', 'customer_rescheduled' => 'Customer rescheduled', 'other' => 'Other'],
];
