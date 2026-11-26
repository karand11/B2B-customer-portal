<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            // Invoices need a billing address (up to 6 lines); fake and never asserted on.
            'billing_address' => fake()->streetAddress()."\n".fake()->postcode().' '.fake()->city(),
            'tax_id' => 'TAX-'.fake()->numerify('########'),
        ];
    }
}
