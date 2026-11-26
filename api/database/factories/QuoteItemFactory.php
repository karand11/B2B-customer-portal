<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\QuoteItem;
use App\Models\QuoteRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A consistent unpriced custom line. Pair it with QuoteRequest::factory()->withLines([]) so positions do not clash.
 *
 * @extends Factory<QuoteItem>
 */
class QuoteItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'quote_request_id' => QuoteRequest::factory()->withLines([]),
            'position' => 1,
            'product_id' => null,
            'part_number' => null,
            'name' => 'Custom taper roller bearing',
            'brand' => null,
            'quantity' => 2,
            'unit_price_cents' => null,
            'line_total_cents' => null,
        ];
    }

    /** Snapshot an existing product. */
    public function catalog(Product $product): static
    {
        return $this->state(['product_id' => $product->id, 'part_number' => $product->part_number, 'name' => $product->name, 'brand' => $product->brand]);
    }

    public function custom(string $description = 'Custom taper roller bearing'): static
    {
        return $this->state(['product_id' => null, 'part_number' => null, 'name' => $description, 'brand' => null]);
    }

    /** Priced at $unit; the line total follows the quantity chosen by quantity(). */
    public function priced(int $unit): static
    {
        return $this->state(fn (array $attributes) => ['unit_price_cents' => $unit, 'line_total_cents' => $unit * $attributes['quantity']]);
    }

    public function quantity(int $quantity): static
    {
        return $this->state(fn (array $attributes) => ['quantity' => $quantity]
            + ($attributes['unit_price_cents'] !== null ? ['line_total_cents' => $attributes['unit_price_cents'] * $quantity] : []));
    }
}
