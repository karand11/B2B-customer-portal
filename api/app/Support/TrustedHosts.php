<?php

namespace App\Support;

/**
 * Parses TRUSTED_HOSTS: exact host names only. A value with a scheme, port, path or wildcard could never equal a Host header,
 * so it would answer 400 to every visitor: it throws at boot instead (in every environment).
 */
class TrustedHosts
{
    private const HOST = '/^(?=.{1,253}$)[a-z0-9_]([a-z0-9_-]{0,61}[a-z0-9_])?(\.[a-z0-9_]([a-z0-9_-]{0,61}[a-z0-9_])?)*$/D';

    /** @return list<string> lower case host names; empty tokens (a trailing comma) are ignored */
    public static function fromEnvString(?string $value): array
    {
        $hosts = [];
        foreach (explode(',', (string) $value) as $host) {
            $host = strtolower(trim($host));

            if ($host === '') {
                continue;
            }
            if (preg_match(self::HOST, $host) !== 1) {
                // The entry is not echoed: a wrong value in an environment file may be a secret pasted in the wrong place.
                throw new InvalidConfiguration('TRUSTED_HOSTS must be a comma separated list of host names only (no scheme, port, path or wildcard).');
            }
            $hosts[] = $host;
        }

        return $hosts;
    }

    /** `php artisan portal:check-config` must still boot with a bad list so that it can report it. */
    public static function runningCheckConfig(): bool
    {
        return in_array('portal:check-config', (array) ($_SERVER['argv'] ?? []), true);
    }
}
