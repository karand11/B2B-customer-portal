<?php

return [
    'max_lines' => 50,
    'max_validity_days' => 365,
    'max_note_length' => 2000,

    // Per company: quotes that are still open (submitted, in review, or quoted and not yet expired).
    'max_open_per_company' => (int) env('QUOTES_MAX_OPEN_PER_COMPANY', 50),
    // Upload attempts per user per hour (the 20 per minute limiter stays on top of this).
    'uploads_per_hour' => (int) env('QUOTES_UPLOADS_PER_HOUR', 120),
    // At most this many staff addresses get the "new quote request" mail (one message each); more are skipped with a warning.
    'max_notify_recipients' => 50,

    // One shared mailbox for "new quote request" mails; empty means every active staff and admin user.
    'notify_address' => env('QUOTES_NOTIFY_ADDRESS') ?: null,

    'attachments' => [
        'disk' => 'quotes',
        'directory' => 'quote-attachments',
        'max_bytes' => 10 * 1024 * 1024,
        'max_files' => 10,
        'max_total_bytes' => 25 * 1024 * 1024,
        // All attachments of all quotes of one company, closed quotes included (files are never freed by closing a quote).
        'max_company_bytes' => (int) env('QUOTES_COMPANY_MAX_BYTES', 500 * 1024 * 1024),
        // extension => [canonical MIME stored and served, sniffed MIME types accepted for it]
        'types' => [
            'pdf' => ['application/pdf', ['application/pdf']],
            'png' => ['image/png', ['image/png']],
            'jpg' => ['image/jpeg', ['image/jpeg']],
            'jpeg' => ['image/jpeg', ['image/jpeg']],
            'txt' => ['text/plain', ['text/plain']],
            // libmagic versions differ on CSV.
            'csv' => ['text/csv', ['text/csv', 'text/plain', 'application/csv']],
        ],
    ],
];
