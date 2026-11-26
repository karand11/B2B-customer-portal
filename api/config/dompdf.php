<?php

/*
 * Hardened dompdf settings for invoice PDFs (barryvdh/laravel-dompdf v3, dompdf v3.1).
 * The invoice template has no images, links, fonts or stylesheets of its own: nothing is ever fetched.
 * Do not widen `chroot` to base_path(), and keep remote, PHP and JavaScript off.
 */
return [
    'show_warnings' => false,
    'public_path' => null,
    'convert_entities' => true,
    'options' => [
        // dompdf writes its font cache here (created by the repo; must be writable by the PHP user).
        'font_dir' => storage_path('fonts'),
        'font_cache' => storage_path('fonts'),
        'temp_dir' => sys_get_temp_dir(),
        // Only this (empty) directory may be read through file:// URLs. Never the project root.
        'chroot' => resource_path('pdf'),
        'allowed_protocols' => [
            'data://' => ['rules' => []],
        ],
        'artifactPathValidation' => null,
        'log_output_file' => null,
        'enable_font_subsetting' => true,
        'pdf_backend' => 'CPDF',
        'default_media_type' => 'print',
        'default_paper_size' => 'a4',
        'default_paper_orientation' => 'portrait',
        'default_font' => 'DejaVu Sans',
        'dpi' => 96,
        'enable_php' => false,
        'enable_javascript' => false,
        'enable_remote' => false,
        'allowed_remote_hosts' => null,
        'font_height_ratio' => 1.1,
        'enable_html5_parser' => true,
    ],
];
