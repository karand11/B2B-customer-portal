<?php

use App\Support\TrustedHosts;

it('parses the comma separated host list, lower cased and trimmed, ignoring empty tokens', function (?string $value, array $expected) {
    expect(TrustedHosts::fromEnvString($value))->toBe($expected);
})->with([
    'null' => [null, []],
    'empty' => ['', []],
    'blank' => ['  ', []],
    'one' => ['api.portal.example', ['api.portal.example']],
    'list' => [' API.Portal.Example , localhost,127.0.0.1 ', ['api.portal.example', 'localhost', '127.0.0.1']],
    'trailing comma' => ['a.example,', ['a.example']],
    'docker name' => ['portal_api', ['portal_api']],
]);

it('refuses everything that is not a bare host name, with a clear message that does not echo the value', function (string $value) {
    try {
        TrustedHosts::fromEnvString($value);
        $this->fail('expected an exception');
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())->toContain('TRUSTED_HOSTS')->toContain('host names only')->not->toContain('secret');
    }
})->with([
    'scheme' => ['https://api.portal.example'], 'port' => ['api.portal.example:8443'], 'path' => ['api.portal.example/app'], 'wildcard' => ['*.portal.example'],
    'star' => ['*'], 'one bad in a list' => ['ok.example,https://secret.example'], 'space inside' => ['api portal.example'], 'trailing dot' => ['portal.example.'],
    'leading dot' => ['.portal.example'], 'double dot' => ['a..example'], 'at sign' => ['user@portal.example'], 'query' => ['portal.example?x=1'],
    'ipv6 literal' => ['[::1]'], 'dash first' => ['-a.example'], 'too long label' => [str_repeat('a', 64).'.example'],
]);

it('stops the boot (config load) on a bad TRUSTED_HOSTS, except for portal:check-config, which reports it', function () {
    $before = [$_SERVER['TRUSTED_HOSTS'] ?? '', $_ENV['TRUSTED_HOSTS'] ?? '', $_SERVER['argv'] ?? []];
    $_SERVER['TRUSTED_HOSTS'] = $_ENV['TRUSTED_HOSTS'] = 'https://api.portal.example:8443';

    try {
        expect(fn () => require config_path('portal.php'))->toThrow(InvalidArgumentException::class, 'TRUSTED_HOSTS');

        $_SERVER['argv'] = ['artisan', 'portal:check-config', '--json'];
        expect((require config_path('portal.php'))['trusted_hosts'])->toBe([]);
    } finally {
        [$_SERVER['TRUSTED_HOSTS'], $_ENV['TRUSTED_HOSTS'], $_SERVER['argv']] = $before;
    }
});
