<?php

use App\Models\Company;
use App\Models\Product;
use App\Models\User;

/** @return list<string> the PHP files under the back-office controllers and the admin services */
function adminStructureFiles(): array
{
    $files = glob(app_path('Http/Controllers/Api/V1/Admin/*.php'));
    foreach (['ProductAdmin', 'CompanyAdmin', 'UserAdmin', 'UserInvitations', 'ProductImportService'] as $service) {
        $files[] = app_path("Services/{$service}.php");
    }

    return $files;
}

it('never fills a model from request input in the back-office code', function () {
    $forbidden = [
        '->fill(' => 'mass assignment through fill()',
        '::create(' => 'mass assignment through create()',
        '::firstOrCreate(' => 'mass assignment through firstOrCreate()',
        '::updateOrCreate(' => 'mass assignment through updateOrCreate()',
        '->update([' => 'mass assignment through update([...])',
        '$request->all(' => 'the whole request body',
        '$request->input()' => 'the whole request body',
        'request()->all(' => 'the whole request body',
        '$request->validated()' => 'the whole validated array handed on',
    ];
    $files = adminStructureFiles();

    expect($files)->not->toBeEmpty();
    foreach ($files as $file) {
        $code = file_get_contents($file);
        foreach ($forbidden as $needle => $why) {
            expect(str_contains($code, $needle))->toBeFalse(basename($file)." uses {$needle} ({$why})");
        }
    }
});

it('keeps every model that the back office writes closed to mass assignment, except the three user fields', function () {
    expect((new Product)->getFillable())->toBe([])->and((new Company)->getFillable())->toBe([])->and((new User)->getFillable())->toBe(['name', 'email', 'password']);
});

it('has no raw SQL string built from request input in the back-office code', function () {
    foreach (adminStructureFiles() as $file) {
        expect(preg_match('/(whereRaw|orderByRaw|selectRaw|DB::raw|DB::select|DB::statement)\([^)]*\$request/', file_get_contents($file)))->toBe(0, basename($file));
    }
});
