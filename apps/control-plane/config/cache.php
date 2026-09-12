<?php

return [
    'default' => env('CACHE_STORE', 'file'),

    'stores' => [
        'array' => ['driver' => 'array', 'serialize' => false],
        'file' => ['driver' => 'file', 'path' => storage_path('framework/cache/data')],
    ],

    'prefix' => 'control_plane_cache_',
];
