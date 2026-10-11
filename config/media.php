<?php

return [
    'image_max_bytes' => 10485760,
    'private_max_bytes' => 524288000,
    'image_max_dimension' => 6000,
    'image_max_pixels' => 16000000,
    'private_types' => [
        'application/pdf' => ['pdf'],
        'application/zip' => ['zip'],
        'text/plain' => ['txt', 'md', 'csv'],
        'audio/mpeg' => ['mp3'],
        'video/mp4' => ['mp4'],
    ],
];
