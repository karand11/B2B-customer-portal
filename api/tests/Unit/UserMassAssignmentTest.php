<?php

use App\Models\User;

it('never mass-assigns company_id or role', function () {
    $user = User::make(['name' => 'Eve', 'company_id' => 1, 'role' => 'admin']);
    $user->fill(['role' => 'admin', 'company_id' => 2, 'roles' => ['admin']]);

    expect($user->company_id)->toBeNull()
        ->and($user->getAttributes())->not->toHaveKeys(['company_id', 'role', 'roles'])
        ->and($user->name)->toBe('Eve');
});
