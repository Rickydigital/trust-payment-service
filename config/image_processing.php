<?php

return [
    'enabled' => env('IMAGE_PROCESSING_ENABLED', true),
    'quality' => (int) env('IMAGE_PROCESSING_QUALITY', 84),
    'max_pixels' => (int) env('IMAGE_PROCESSING_MAX_PIXELS', 16000000),

    'profiles' => [
        'default' => [
            'original' => [1600, 1600, 84],
            'thumbnail' => [240, 240, 80],
            'card' => [720, 720, 82],
            'detail' => [1400, 1400, 84],
        ],
        'product' => [
            'original' => [1800, 1800, 85],
            'thumbnail' => [240, 240, 80],
            'card' => [720, 720, 82],
            'detail' => [1400, 1400, 85],
        ],
        'avatar' => [
            'original' => [800, 800, 85],
            'thumbnail' => [160, 160, 80],
            'card' => [360, 360, 82],
            'detail' => [800, 800, 85],
        ],
        'advertisement' => [
            'original' => [1800, 1000, 85],
            'thumbnail' => [480, 270, 80],
            'card' => [1200, 675, 83],
            'detail' => [1600, 900, 85],
        ],
        'chat' => [
            'original' => [1400, 1400, 82],
            'thumbnail' => [240, 240, 78],
            'card' => [720, 720, 80],
            'detail' => [1280, 1280, 82],
        ],
        'proof' => [
            'original' => [1800, 1800, 86],
            'thumbnail' => [240, 240, 80],
            'card' => [720, 720, 82],
            'detail' => [1600, 1600, 86],
        ],
        'document' => [
            'original' => [2200, 2200, 90],
            'thumbnail' => [300, 300, 82],
            'card' => [900, 900, 86],
            'detail' => [2000, 2000, 90],
        ],
    ],

    'path_profiles' => [
        'products/' => 'product',
        'food/items/' => 'product',
        'avatars/' => 'avatar',
        'seller/logos/' => 'avatar',
        'food/businesses/' => 'advertisement',
        'marketing/' => 'advertisement',
        'chat/' => 'chat',
        'delivery-proofs/' => 'proof',
        'trust/kyc/' => 'document',
        'trust/evidence/' => 'document',
        'trust/deliveries/' => 'proof',
    ],
];
