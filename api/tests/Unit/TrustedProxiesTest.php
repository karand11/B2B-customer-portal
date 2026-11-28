<?php

use App\Support\TrustedProxies;

it('parses the comma separated proxy list', function (?string $value, array $expected) {
    expect(TrustedProxies::fromEnvString($value))->toBe($expected);
})->with([
    'null' => [null, []],
    'empty' => ['', []],
    'blank' => ['   ', []],
    'one ipv4' => ['10.0.0.5', ['10.0.0.5']],
    'trimmed list' => [' 10.0.0.5 , 192.168.1.0/24 ', ['10.0.0.5', '192.168.1.0/24']],
    'ipv6' => ['2001:db8::1', ['2001:db8::1']],
    'ipv6 cidr' => ['2001:db8::/32', ['2001:db8::/32']],
    'cidr 32' => ['10.1.2.3/32', ['10.1.2.3/32']],
    'shortest ipv4 prefix' => ['10.0.0.0/8', ['10.0.0.0/8']],
    'shortest ipv6 prefix' => ['2001::/16', ['2001::/16']],
]);

it('refuses everything that could mean "trust everybody" or cannot be parsed', function (string $value) {
    expect(fn () => TrustedProxies::fromEnvString($value))->toThrow(InvalidArgumentException::class);
})->with([
    'star' => ['*'], 'double star' => ['**'], 'star in a list' => ['10.0.0.1,*'], 'empty token' => ['10.0.0.1,,10.0.0.2'], 'trailing comma' => ['10.0.0.1,'],
    'garbage' => ['not-an-ip'], 'bad ipv4' => ['999.1.1.1'], 'bad prefix' => ['10.0.0.0/33'], 'bad v6 prefix' => ['2001:db8::/129'], 'zero prefix v4' => ['0.0.0.0/0'],
    'zero prefix v6' => ['::/0'],
    'v4 prefix 1' => ['0.0.0.0/1'], 'v4 prefix 4' => ['10.0.0.0/4'], 'v4 prefix 7' => ['10.0.0.0/7'], 'v6 prefix 1' => ['::/1'], 'v6 prefix 15' => ['2000::/15'], 'v6 prefix 8' => ['2000::/8'], 'no prefix digits' => ['10.0.0.0/'], 'ip with port' => ['10.0.0.1:80'], 'host name' => ['proxy.internal'], 'REMOTE_ADDR' => ['REMOTE_ADDR'],
]);

it('does not echo the offending value', function () {
    try {
        TrustedProxies::fromEnvString('s3cr3t-pasted-by-mistake');
        $this->fail('expected an exception');
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())->not->toContain('s3cr3t');
    }
});

it('fails the application boot (config load) when TRUSTED_PROXIES is the wildcard', function () {
    // env() reads $_SERVER and $_ENV; putenv() is NOT used (it leaks between tests and is unreliable under parallel runs).
    $before = [$_SERVER['TRUSTED_PROXIES'] ?? null, $_ENV['TRUSTED_PROXIES'] ?? null];
    $_SERVER['TRUSTED_PROXIES'] = $_ENV['TRUSTED_PROXIES'] = '*';

    try {
        expect(fn () => require config_path('portal.php'))->toThrow(InvalidArgumentException::class);

        $_SERVER['TRUSTED_PROXIES'] = $_ENV['TRUSTED_PROXIES'] = '10.0.0.0/8, 192.0.2.1';
        expect((require config_path('portal.php'))['trusted_proxies'])->toBe(['10.0.0.0/8', '192.0.2.1']);
    } finally {
        foreach (['_SERVER', '_ENV'] as $i => $global) {
            $before[$i] === null ? $GLOBALS[$global]['TRUSTED_PROXIES'] = '' : $GLOBALS[$global]['TRUSTED_PROXIES'] = $before[$i];
        }
    }
});
