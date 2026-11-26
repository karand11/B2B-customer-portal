<?php

/*
 * Invoicing settings. EVERY value below is a PLACEHOLDER until the business sets real ones (VAT rate, seller identity,
 * bank details, legal wording). No VAT rate or legal text here has been verified. Do not go live on the defaults:
 * in production, issuing an invoice is refused while the seller name is still the placeholder.
 * Env values are read only in this file; code and tests use config('invoicing.*').
 */

// A non-numeric env value is kept as typed so issuing can refuse it (422) instead of silently turning it into 0.
$int = function (string $key, int $default): mixed {
    $raw = env($key);

    return $raw === null || $raw === '' ? $default : (preg_match('/^-?\d{1,9}$/D', (string) $raw) === 1 ? (int) $raw : $raw);
};

return [
    // Basis points of ONE standard rate per invoice (1900 = 19%). Allowed: integer 0 to 5000.
    'vat_rate_bp' => $int('INVOICING_VAT_RATE_BP', 0),
    'vat_label' => env('INVOICING_VAT_LABEL', 'VAT'),
    // Days from issue date to due date. Allowed: integer 0 to 120.
    'payment_terms_days' => $int('INVOICING_PAYMENT_TERMS_DAYS', 30),
    // Upper bound for the staff due_date override, in days after the issue date.
    'max_due_days' => 120,
    // Order statuses that can be invoiced.
    'eligible_order_statuses' => ['confirmed', 'shipped', 'delivered'],

    'seller' => [
        'name' => env('INVOICING_SELLER_NAME', 'PLACEHOLDER SELLER NAME'),
        // Lines separated by |, at most 6 lines of at most 80 characters.
        'address_lines' => array_values(array_filter(array_map('trim', explode('|', (string) env(
            'INVOICING_SELLER_ADDRESS',
            'PLACEHOLDER Street 1|00000 PLACEHOLDER City|PLACEHOLDER Country'
        ))), fn ($line) => $line !== '')),
        'tax_id' => env('INVOICING_SELLER_TAX_ID', 'PLACEHOLDER-VAT-ID'),
        'email' => env('INVOICING_SELLER_EMAIL', 'billing@example.invalid'),
        'phone' => env('INVOICING_SELLER_PHONE', '+00 000 000000'),
        'bank' => [
            'account_holder' => env('INVOICING_BANK_HOLDER', 'PLACEHOLDER'),
            'bank_name' => env('INVOICING_BANK_NAME', 'PLACEHOLDER BANK'),
            // Deliberately not a valid-looking IBAN.
            'iban' => env('INVOICING_BANK_IBAN', 'XX00 PLACEHOLDER IBAN'),
            'bic' => env('INVOICING_BANK_BIC', 'PLACEHOLDER'),
        ],
        // No day count in the text: the due date is printed separately and may be overridden per invoice.
        'payment_terms_text' => env('INVOICING_PAYMENT_TERMS_TEXT', 'Please pay by bank transfer and quote the invoice number. (PLACEHOLDER TEXT)'),
        // One line, at most 300 characters.
        'footer' => env('INVOICING_FOOTER', 'PLACEHOLDER legal footer: registered office, registration number, managing directors.'),
    ],
];
