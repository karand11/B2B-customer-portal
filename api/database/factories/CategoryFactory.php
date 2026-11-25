<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    public function definition(): array
    {
        $word = fake()->unique()->lexify('type-????????');

        return ['slug' => $word, 'name' => ucfirst(str_replace('-', ' ', $word)), 'sort_order' => 0];
    }
}
