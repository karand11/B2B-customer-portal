<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Support\CatalogCache;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    /** Reference data (the bearing taxonomy). Safe in every environment, including production. */
    public const CATEGORIES = [
        'deep-groove-ball' => 'Deep groove ball bearings',
        'angular-contact-ball' => 'Angular contact ball bearings',
        'tapered-roller' => 'Tapered roller bearings',
        'cylindrical-roller' => 'Cylindrical roller bearings',
        'thrust-ball' => 'Thrust ball bearings',
        'spherical-roller' => 'Spherical roller bearings',
        'needle-roller' => 'Needle roller bearings',
    ];

    public function run(): void
    {
        $order = 0;
        $rows = [];

        foreach (self::CATEGORIES as $slug => $name) {
            $rows[] = ['slug' => $slug, 'name' => $name, 'sort_order' => $order += 10];
        }

        Category::upsert($rows, ['slug'], ['name', 'sort_order']);
        CatalogCache::flush();
    }
}
