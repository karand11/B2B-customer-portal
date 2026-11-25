<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Support\CatalogCache;
use App\Support\PartNumber;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProductSeeder extends Seeder
{
    /** Real ISO designations: [designation, category slug, d, D, B] (mm). */
    private const ANCHORS = [
        ['6000', 'deep-groove-ball', 10, 26, 8], ['6200', 'deep-groove-ball', 10, 30, 9],
        ['6201', 'deep-groove-ball', 12, 32, 10], ['6202', 'deep-groove-ball', 15, 35, 11],
        ['6203', 'deep-groove-ball', 17, 40, 12], ['6204', 'deep-groove-ball', 20, 47, 14],
        ['6205', 'deep-groove-ball', 25, 52, 15], ['6005', 'deep-groove-ball', 25, 47, 12],
        ['6305', 'deep-groove-ball', 25, 62, 17], ['6206', 'deep-groove-ball', 30, 62, 16],
        ['7205B', 'angular-contact-ball', 25, 52, 15], ['7206B', 'angular-contact-ball', 30, 62, 16],
        ['30205', 'tapered-roller', 25, 52, 16.25], ['NU206', 'cylindrical-roller', 30, 62, 16],
        ['51105', 'thrust-ball', 25, 42, 11], ['51106', 'thrust-ball', 30, 47, 11],
        ['51205', 'thrust-ball', 25, 47, 15], ['22205', 'spherical-roller', 25, 52, 18],
        ['HK2512', 'needle-roller', 25, 32, 12],
    ];

    /** Generated series: [category slug, designation template, D = d*mul + add, B = (D-d)*bMul, sealable]. */
    private const SERIES = [
        ['deep-groove-ball', '60{c}', 1.4, 12, 0.55, true],
        ['deep-groove-ball', '62{c}', 1.7, 9.5, 0.55, true],
        ['deep-groove-ball', '63{c}', 2.1, 9.5, 0.46, true],
        ['angular-contact-ball', '70{c}B', 1.4, 12, 0.55, true],
        ['angular-contact-ball', '72{c}B', 1.7, 9.5, 0.55, true],
        ['angular-contact-ball', '73{c}B', 2.1, 9.5, 0.46, true],
        ['tapered-roller', '302{c}', 1.7, 9.5, 0.6, false],
        ['tapered-roller', '303{c}', 2.1, 9.5, 0.5, false],
        ['tapered-roller', '322{c}', 1.7, 9.5, 0.7, false],
        ['cylindrical-roller', 'NU2{c}', 1.7, 9.5, 0.6, false],
        ['cylindrical-roller', 'NU3{c}', 2.1, 9.5, 0.5, false],
        ['cylindrical-roller', 'NU4{c}', 2.4, 12, 0.45, false],
        ['thrust-ball', '511{c}', 1.4, 7, 0.65, false],
        ['thrust-ball', '512{c}', 1.5, 9, 0.8, false],
        ['thrust-ball', '513{c}', 1.7, 10, 0.9, false],
        ['spherical-roller', '222{c}', 1.7, 9.5, 0.7, false],
        ['spherical-roller', '223{c}', 2.1, 9.5, 0.6, false],
        ['spherical-roller', '230{c}', 1.7, 9.5, 0.9, false],
        ['needle-roller', 'HK{d}{B}', 1.0, 7, 1.5, false],
        ['needle-roller', 'HK{d}{B}', 1.0, 7, 1.7, false],
        ['needle-roller', 'HK{d}{B}', 1.0, 7, 2.3, false],
    ];

    private const BORES = [10, 12, 15, 17, 20, 25, 30, 35, 40, 45, 50, 55, 60, 65, 70, 75, 80, 85, 90, 100, 110, 120];

    private const BRANDS = ['SKF' => '', 'FAG' => '-F', 'NSK' => '-N', 'NTN' => '-T', 'Timken' => '-K'];

    private const CLEARANCES = ['', '/C3', '', '', '/C4'];

    private const SEALS = ['open' => '', '2rs' => '-2RS', 'zz' => '-2Z'];

    public function run(): void
    {
        // Same allowlist as DemoSeeder: demo data never reaches staging or production.
        abort_unless(app()->environment(['local', 'testing']), 500, 'ProductSeeder only runs in local/testing.');

        if (Category::count() === 0) {
            $this->call(CategorySeeder::class);
        }

        $active = (int) config('catalog.seed_count');
        $total = $active + intdiv($active, 20);
        $categories = Category::pluck('id', 'slug')->all();
        $names = Category::pluck('name', 'slug')->all();
        $rows = [];

        foreach ($this->products($total) as $k => $p) {
            $rows[] = $this->row($k, $p, $categories[$p['slug']], $names[$p['slug']]);
        }

        DB::transaction(function () use ($rows) {
            foreach (array_chunk($rows, 500) as $chunk) {
                Product::upsert($chunk, ['part_number_search'], [
                    'category_id', 'part_number', 'name', 'description', 'brand', 'inner_diameter_mm', 'outer_diameter_mm',
                    'width_mm', 'material', 'seal_type', 'price_cents', 'price_on_request', 'stock_status', 'active',
                ]);
            }
        });

        CatalogCache::flush();
    }

    /**
     * Deterministic list of $total products (anchors first), derived only from the loop index.
     *
     * @return list<array{pn: string, slug: string, d: int|float, D: int|float, B: int|float, brand: string, seal: string}>
     */
    private function products(int $total): array
    {
        $items = [];
        $seen = [];

        foreach (self::ANCHORS as [$pn, $slug, $d, $D, $B]) {
            $items[] = ['pn' => $pn, 'slug' => $slug, 'd' => $d, 'D' => $D, 'B' => $B, 'brand' => 'SKF', 'seal' => 'open'];
            $seen[PartNumber::normalise($pn)] = true;
        }

        $bases = $this->bases();
        $n = count($bases);
        $brands = array_keys(self::BRANDS);

        for ($i = 0; count($items) < $total; $i++) {
            $base = $bases[$i % $n];
            $brand = $brands[$i % 5];
            $seals = $base['sealable'] ? array_keys(self::SEALS) : ['open'];
            $seal = $seals[intdiv($i, 5) % count($seals)];
            $round = intdiv($i, $n * 5); // distinct (base, brand) pairs repeat only after n * 5 steps
            $pn = $base['pn'].self::SEALS[$seal].self::CLEARANCES[intdiv($i, 15) % 5].self::BRANDS[$brand]
                .($round > 0 ? "-X{$round}" : '');
            $key = PartNumber::normalise($pn);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $items[] = ['pn' => $pn, 'slug' => $base['slug'], 'd' => $base['d'], 'D' => $base['D'], 'B' => $base['B'],
                'brand' => $brand, 'seal' => $seal];
        }

        return $items;
    }

    /** @return list<array{pn: string, slug: string, d: int, D: int, B: int, sealable: bool}> */
    private function bases(): array
    {
        $bases = [];

        foreach (self::BORES as $d) {
            $code = $d < 20 ? ['10' => '00', '12' => '01', '15' => '02', '17' => '03'][$d] : sprintf('%02d', intdiv($d, 5));

            foreach (self::SERIES as [$slug, $template, $mul, $add, $bMul, $sealable]) {
                $D = (int) round($d * $mul + $add);
                $B = max(4, (int) round(($D - $d) * $bMul));
                $bases[] = [
                    'pn' => str_replace(['{c}', '{d}', '{B}'], [$code, $d, sprintf('%02d', $B)], $template),
                    'slug' => $slug, 'd' => $d, 'D' => $D, 'B' => $B, 'sealable' => $sealable,
                ];
            }
        }

        return $bases;
    }

    /** Distributions are index modulo arithmetic: material 80/12/8, stock 60/15/15/10, ~5% price on request. */
    private function row(int $k, array $p, int $categoryId, string $categoryName): array
    {
        $pn = strtoupper($p['pn']);
        $onRequest = $k % 20 === 7;
        $stock = ($k + intdiv($k, 20) * 3) % 20;
        $m = $k % 25;
        $brandFactor = 1 + array_search($p['brand'], array_keys(self::BRANDS), true) * 0.07;
        $price = min(25000, 150 + (int) round(($p['d'] * $p['D'] * $p['B']) / 6 * $brandFactor));
        $dims = fn ($v) => (float) $v == floor((float) $v) ? (int) $v : (float) $v;
        $size = $dims($p['d']).'x'.$dims($p['D']).'x'.$dims($p['B']);

        return [
            'category_id' => $categoryId,
            'part_number' => $pn,
            'name' => rtrim($categoryName, 's')." {$pn} {$size}",
            'description' => "{$p['brand']} {$pn}, {$size} mm. Demo catalog data.",
            'brand' => $p['brand'],
            'inner_diameter_mm' => $p['d'],
            'outer_diameter_mm' => $p['D'],
            'width_mm' => $p['B'],
            'material' => $m < 20 ? 'Chrome steel' : ($m < 23 ? 'Stainless steel' : 'Ceramic hybrid'),
            'seal_type' => $p['seal'],
            'price_cents' => $onRequest ? null : $price,
            'price_on_request' => $onRequest,
            'stock_status' => $stock < 12 ? 'in_stock' : ($stock < 15 ? 'low_stock' : ($stock < 18 ? 'on_order' : 'out_of_stock')),
            'active' => $k % 21 !== 20,
        ];
    }
}
