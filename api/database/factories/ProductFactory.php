<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Valid by default (satisfies every CHECK). Tests override attributes explicitly.
 *
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        $partNumber = strtoupper(fake()->unique()->bothify('??####-??'));

        return [
            'category_id' => Category::factory(),
            'part_number' => $partNumber,
            'name' => "Test bearing {$partNumber}",
            'description' => null,
            'brand' => 'SKF',
            'inner_diameter_mm' => 25,
            'outer_diameter_mm' => 52,
            'width_mm' => 15,
            'material' => 'Chrome steel',
            'seal_type' => 'open',
            'price_cents' => 1250,
            'price_on_request' => false,
            'stock_status' => 'in_stock',
            'active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['active' => false]);
    }

    public function priceOnRequest(): static
    {
        return $this->state(['price_cents' => null, 'price_on_request' => true]);
    }

    public function inStock(): static
    {
        return $this->state(['stock_status' => 'in_stock']);
    }

    public function lowStock(): static
    {
        return $this->state(['stock_status' => 'low_stock']);
    }

    public function outOfStock(): static
    {
        return $this->state(['stock_status' => 'out_of_stock']);
    }

    public function onOrder(): static
    {
        return $this->state(['stock_status' => 'on_order']);
    }
}
