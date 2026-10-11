<?php

return [
    'ssr' => ['enabled' => false],
    'devtools' => ['enabled' => false],
    'history' => ['encrypt' => true],
    'pages' => [
        'ensure_pages_exist' => true,
        'paths' => [resource_path('js/admin/pages')],
        'extensions' => ['vue'],
    ],
    'testing' => [
        'ensure_pages_exist' => true,
        'page_paths' => [resource_path('js/admin/pages')],
        'page_extensions' => ['vue'],
    ],
];
