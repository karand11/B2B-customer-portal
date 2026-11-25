<?php

beforeEach(function () {
    $tapered = category('tapered-roller')->id;
    $rows = [
        ['PA', [], 'SKF', 25, 52, 15, 'Chrome steel', 'open', 'in_stock'],
        ['PB', [], 'SKF', 25, 62, 17, 'Stainless steel', '2rs', 'low_stock'],
        ['PC', [], 'NSK', 30, 62, 16, 'Chrome steel', 'zz', 'out_of_stock'],
        ['PD', ['category_id' => $tapered], 'SKF', 25, 52, 16.25, 'Chrome steel', 'open', 'on_order'],
        ['PE', ['category_id' => $tapered], 'NSK', 40, 80, 20, 'Ceramic hybrid', 'open', 'in_stock'],
        ['PF', [], 'FAG', 17, 40, 12, 'Chrome steel', '2rs', 'in_stock'],
    ];

    foreach ($rows as [$pn, $extra, $brand, $d, $D, $B, $material, $seal, $stock]) {
        makeProduct($pn, $extra + [
            'brand' => $brand, 'inner_diameter_mm' => $d, 'outer_diameter_mm' => $D, 'width_mm' => $B,
            'material' => $material, 'seal_type' => $seal, 'stock_status' => $stock,
        ]);
    }

    loginAs(makeUser());
});

it('applies each filter alone', function (string $query, array $expected) {
    expect(partNumbers($this->getJson("/api/v1/products?{$query}")->assertOk()))->toBe($expected);
})->with([
    'category' => ['category=tapered-roller', ['PD', 'PE']],
    'category list is OR' => ['category=tapered-roller,deep-groove-ball', ['PA', 'PB', 'PC', 'PD', 'PE', 'PF']],
    'brand' => ['brand=NSK', ['PC', 'PE']],
    'brand list is OR' => ['brand=NSK,FAG', ['PC', 'PE', 'PF']],
    'brand with spaces around commas' => ['brand=NSK%2C%20FAG', ['PC', 'PE', 'PF']],
    'brand is case sensitive' => ['brand=nsk', []],
    'unknown brand matches nothing' => ['brand=Nope', []],
    'material' => ['material=Chrome%20steel', ['PA', 'PC', 'PD', 'PF']],
    'material list' => ['material=Stainless%20steel,Ceramic%20hybrid', ['PB', 'PE']],
    'seal_type' => ['seal_type=2rs', ['PB', 'PF']],
    'seal_type list' => ['seal_type=2rs,zz', ['PB', 'PC', 'PF']],
    'stock_status' => ['stock_status=in_stock', ['PA', 'PE', 'PF']],
    'stock_status list' => ['stock_status=low_stock,out_of_stock', ['PB', 'PC']],
    'inner min' => ['inner_diameter_min=30', ['PC', 'PE']],
    'inner max' => ['inner_diameter_max=17', ['PF']],
    'inner range inclusive' => ['inner_diameter_min=17&inner_diameter_max=25', ['PA', 'PB', 'PD', 'PF']],
    'outer min' => ['outer_diameter_min=62', ['PB', 'PC', 'PE']],
    'outer max' => ['outer_diameter_max=40', ['PF']],
    'outer range inclusive' => ['outer_diameter_min=52&outer_diameter_max=62', ['PA', 'PB', 'PC', 'PD']],
    'width min' => ['width_min=16.25', ['PB', 'PD', 'PE']],
    'width max' => ['width_max=16', ['PA', 'PC', 'PF']],
    'width range' => ['width_min=15&width_max=16.25', ['PA', 'PC', 'PD']],
    'exact value via equal bounds' => ['outer_diameter_min=52&outer_diameter_max=52', ['PA', 'PD']],
]);

it('combines type, outer diameter range and brand as an intersection', function () {
    $response = $this->getJson('/api/v1/products?category=deep-groove-ball&outer_diameter_min=50&outer_diameter_max=62&brand=SKF')->assertOk();

    expect(partNumbers($response))->toBe(['PA', 'PB'])->and($response->json('meta.total'))->toBe(2);
});

it('is OR within one filter and AND across filters', function () {
    expect(partNumbers($this->getJson('/api/v1/products?brand=SKF,NSK&seal_type=open,zz&stock_status=in_stock,on_order')))
        ->toBe(['PA', 'PD', 'PE']);
});

it('combines q with filters', function () {
    expect(partNumbers($this->getJson('/api/v1/products?q=p&brand=NSK')))->toBe(['PC', 'PE'])
        ->and(partNumbers($this->getJson('/api/v1/products?q=pd&brand=NSK')))->toBe([]);
});

it('ignores unknown parameters including company_id', function () {
    $plain = $this->getJson('/api/v1/products')->assertOk()->json();

    expect($this->getJson('/api/v1/products?company_id=999&foo=bar')->assertOk()->json())->toBe($plain);
});

it('treats empty filter values as absent', function () {
    $this->getJson('/api/v1/products?brand=&q=&sort=')->assertOk()->assertJsonPath('meta.total', 6);
});
