<?php

use App\Support\PartNumber;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    foreach (['6205', '6205-2RS', '6205-2RS/C3', '16205', '6305', '7205B'] as $pn) {
        makeProduct($pn);
    }
    loginAs(makeUser());
});

function search(string $q, string $extra = '')
{
    return test()->getJson('/api/v1/products?q='.rawurlencode($q).$extra);
}

it('finds partial matches and orders by relevance: exact, prefix by part number, then substring', function () {
    $response = search('6205')->assertOk();

    expect(partNumbers($response))->toBe(['6205', '6205-2RS', '6205-2RS/C3', '16205'])
        ->and($response->json('meta.total'))->toBe(4);
});

it('returns the exact full part number first', function () {
    expect(partNumbers(search('6205-2RS')))->toBe(['6205-2RS', '6205-2RS/C3']);
});

it('matches partial and suffix input', function (string $q, array $expected) {
    expect(partNumbers(search($q)))->toBe($expected);
})->with([
    'middle 205' => ['205', ['16205', '6205', '6205-2RS', '6205-2RS/C3', '7205B']],
    'suffix 2rs' => ['2rs', ['6205-2RS', '6205-2RS/C3']],
    'clearance' => ['/c3', ['6205-2RS/C3']],
]);

it('treats case, spaces, dashes and slashes alike', function (string $q) {
    expect(partNumbers(search($q)))->toBe(['6205-2RS', '6205-2RS/C3']);
})->with(['6205-2rs', '6205-2RS', '6205 2rs', '62052RS', '  6205-2RS  ', '6205.2.rs']);

it('returns 200 with an empty page when nothing matches', function () {
    search('99999')->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total', 0)->assertJsonPath('meta.last_page', 1);
});

it('lets an explicit sort override relevance', function () {
    expect(partNumbers(search('6205', '&sort=-part_number')))->toBe(['6205-2RS/C3', '6205-2RS', '6205', '16205']);
});

it('keeps the PHP normaliser identical to the generated column', function () {
    $inputs = ['6205-2RS', '6205 2rs', 'nu206', 'A/B.c-d_e', '  x  ', "\tTab\n", '00123', 'ÄÖ6205', 'caf'."\u{e9}".'1', 'İ1', "\u{212A}1", '6205–2RS', 'A+B&C'];
    foreach ($inputs as $i => $input) {
        $key = $i + 1;
        // Part numbers must be unique after normalisation, so make each input unique via a marker.
        DB::table('products')->insert([
            'category_id' => category()->id, 'part_number' => $input.'Z'.$key, 'name' => 'n', 'brand' => 'SKF',
            'inner_diameter_mm' => 1, 'outer_diameter_mm' => 2, 'width_mm' => 1, 'material' => 'm',
            'seal_type' => 'open', 'price_cents' => 1, 'stock_status' => 'in_stock',
        ]);
        $stored = DB::table('products')->where('part_number', $input.'Z'.$key)->value('part_number_search');

        expect($stored)->toBe(PartNumber::normalise($input.'Z'.$key));
    }
});
