<?php

return [
    'priorities' => [
        'low' => 'Low',
        'normal' => 'Normal',
        'high' => 'High',
        'urgent' => 'Urgent',
    ],
    'sources' => [
        'phone' => 'Phone',
        'email' => 'Email',
        'web' => 'Web',
        'internal' => 'Internal',
        // Additive: JARVIS-created tickets via /api/v1, per
        // docs/OPS_PORTAL_API_IMPLEMENTATION_PLAN_CODEX_v0.1.md §8.3 example payload.
        'jarvis' => 'JARVIS (AI agent)',
        'other' => 'Other',
    ],
    'purposes' => [
        'site_survey' => 'Site / Survey Visit',
        'installation_project' => 'Installation / Project',
        'service_call' => 'Service Visit',
        'warranty' => 'Warranty / Maintenance Visit',
        'internal_test' => 'Internal / Testing',
    ],
    'legacy_purposes' => [
        'callback' => 'Callback / Return Visit (legacy)',
    ],
    'purpose_aliases' => [
        'callback' => 'service_call',
    ],
    'billing_dispositions' => [
        'billable' => 'Billable',
        'non_billable' => 'Non-billable',
        'warranty' => 'Warranty',
        'included' => 'Included',
        'no_charge' => 'No charge',
    ],
    'statuses' => [
        'open' => 'Open',
        'on_hold' => 'On hold',
        'completed' => 'Completed',
        'canceled' => 'Canceled',
    ],
    'visit_statuses' => [
        'planned' => 'Planned',
        'scheduled' => 'Scheduled',
        'assigned' => 'Assigned',
        'en_route' => 'En route',
        'on_site' => 'On site',
        'pending_closeout' => 'Pending closeout',
        'returned_for_correction' => 'Returned for correction',
        'approved' => 'Approved',
        'customer_unavailable' => 'Customer unavailable',
        'canceled' => 'Canceled',
    ],
];
