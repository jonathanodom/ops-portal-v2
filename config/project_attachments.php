<?php

return [
    'disk' => env('PROJECT_ATTACHMENT_DISK', 'local'),
    'max_file_kb' => 20480,
    'categories' => [
        'site_photo' => 'Site Photo',
        'design_document' => 'Design Document',
        'as_built' => 'As-Built',
        'vendor_document' => 'Vendor Document',
        'equipment_list' => 'Equipment List',
        'customer_supplied' => 'Customer-Supplied',
        'reference' => 'Reference',
        'other' => 'Other',
    ],
];
