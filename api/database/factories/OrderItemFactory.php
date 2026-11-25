<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A consistent snapshot line copied from a product (line_total = unit * quantity).
 *
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    public function definition(): array
    {
        $product = Product::factory()->create();
        $quantity = fake()->numberBetween(1, 5);

        return [
            'order_id' => Order::factory(),
            'product_id' => $product->id,
            'part_number' => $product->part_number,
            'name' => $product->name,
            'brand' => $product->brand,
            'unit_price_cents' => $product->price_cents,
            'quantity' => $quantity,
            'line_total_cents' => $product->price_cents * $quantity,
        ];
    }

    /** Snapshot an existing product at its current price. */
    public function ofProduct(Product $product, int $quantity = 1): static
    {
        return $this->state([
            'product_id' => $product->id, 'part_number' => $product->part_number, 'name' => $product->name,
            'brand' => $product->brand, 'unit_price_cents' => $product->price_cents, 'quantity' => $quantity,
            'line_total_cents' => $product->price_cents * $quantity,
        ]);
    }
}
