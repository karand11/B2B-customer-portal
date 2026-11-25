<?php

it('returns ok for a guest without touching the database', function () {
    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertExactJson(['status' => 'ok']);
});
