<?php

return [
    'attachment_disk' => env('COMMERCIAL_ATTACHMENT_DISK', 'local'),
    'attachment_max_kb' => 20480,
    'proposal_disk' => env('COMMERCIAL_PROPOSAL_DISK', 'local'),
    'proposal_media_max_kb' => 20480,
    'proposal_signature_max_bytes' => 1048576,
    'acceptance_statement_version' => '2026-08-v1',
    'acceptance_statement' => 'I confirm that I am authorized to approve this Proposal and accept the selected scope, pricing, payment schedule, and terms shown above.',
    'verification_minutes' => 15,
];
