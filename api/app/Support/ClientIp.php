<?php

namespace App\Support;

class ClientIp
{
    /** Rate-limit key for an IP: IPv4 as is, IPv4-mapped IPv6 as IPv4, other IPv6 collapsed to its /64. */
    public static function key(?string $ip): string
    {
        $bin = $ip !== null && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? inet_pton($ip) : false;

        if ($bin === false) {
            return (string) $ip;
        }

        if (str_starts_with($bin, str_repeat("\0", 10)."\xff\xff")) {
            return inet_ntop(substr($bin, 12));
        }

        return bin2hex(substr($bin, 0, 8)).'/64';
    }
}
