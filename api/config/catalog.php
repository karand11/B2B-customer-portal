<?php

$currency = strtoupper((string) env('CATALOG_CURRENCY', 'USD'));
$seedCount = (int) env('CATALOG_SEED_COUNT', 250);

return [
    // One ISO 4217 code for every price in the catalog (not stored per product).
    'currency' => preg_match('/^[A-Z]{3}$/D', $currency) ? $currency : 'USD',

    // Number of ACTIVE demo products ProductSeeder creates (plus floor(n / 20) inactive ones).
    'seed_count' => max(1, min(20000, $seedCount)),
];
