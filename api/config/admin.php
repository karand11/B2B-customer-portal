<?php

return [
    /*
    | Two-step CSV product import over HTTP (php artisan products:import has its own, larger limit).
    */
    'imports' => [
        'disk' => 'imports',
        'directory' => '',
        'max_rows' => (int) env('ADMIN_IMPORT_MAX_ROWS', 5000),
        'max_bytes' => (int) env('ADMIN_IMPORT_MAX_BYTES', 5 * 1024 * 1024),
        'preview_minutes' => 60,
    ],
];
