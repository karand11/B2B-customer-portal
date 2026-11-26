<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A consistent line (free-text kind, line_total = unit * quantity). Invoices normally get their lines from InvoiceFactory;
 * use this only to add an extra line to an existing invoice (position must be unique per invoice).
 *
 * @extends Factory<InvoiceItem>
 */
class InvoiceItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'position' => 1,
            'part_number' => null,
            'name' => 'Custom taper roller bearing',
            'brand' => null,
            'quantity' => 2,
            'unit_price_cents' => 1000,
            'line_total_cents' => 2000,
        ];
    }
}
