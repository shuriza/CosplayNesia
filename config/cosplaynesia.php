<?php

return [
    'default_product_image' => 'https://images.unsplash.com/photo-1544005313-94ddf0286df2?auto=format&fit=crop&w=700&q=85',
    'listing_media_disk' => env('LISTING_MEDIA_DISK', 'public'),
    'legal' => [
        'terms_version' => env('TERMS_VERSION', '2026-09-19'),
        'privacy_version' => env('PRIVACY_VERSION', '2026-09-19'),
        'rental_policy_version' => env('RENTAL_POLICY_VERSION', '2026-09-19'),
    ],
    'backoffice' => [
        // How long a fresh password confirmation lasts before a sensitive read must re-confirm.
        'step_up_ttl_minutes' => (int) env('BACKOFFICE_STEP_UP_TTL_MINUTES', 15),
    ],
];
