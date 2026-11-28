<?php

namespace App\Support;

/** Parses TRUSTED_PROXIES. Anything that could mean "trust everybody" or cannot be parsed throws, in every environment. */
class TrustedProxies
{
    /** @return list<string> */
    public static function fromEnvString(?string $value): array
    {
        $value = trim((string) $value);

        if ($value === '') {
            return [];
        }

        $entries = [];
        foreach (explode(',', $value) as $entry) {
            $entry = trim($entry);

            if (! self::valid($entry)) {
                // The entry itself is not echoed: a wrong value in an environment file may be a secret pasted in the wrong place.
                throw new InvalidConfiguration('TRUSTED_PROXIES must be a comma separated list of IP addresses or CIDR ranges (the wildcard "*" is refused; a prefix shorter than /8 for IPv4 or /16 for IPv6 is refused).');
            }
            $entries[] = $entry;
        }

        return $entries;
    }

    private static function valid(string $entry): bool
    {
        if (filter_var($entry, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        if (preg_match('/^([0-9a-fA-F:.]+)\/(\d{1,3})$/D', $entry, $m) !== 1) {
            return false;
        }

        $ipv6 = str_contains($m[1], ':');

        return filter_var($m[1], FILTER_VALIDATE_IP, $ipv6 ? FILTER_FLAG_IPV6 : FILTER_FLAG_IPV4) !== false
            && (int) $m[2] <= ($ipv6 ? 128 : 32)
            // A short prefix is "a large part of the internet" in disguise (0.0.0.0/1 is half of it).
            && (int) $m[2] >= ($ipv6 ? 16 : 8);
    }
}
