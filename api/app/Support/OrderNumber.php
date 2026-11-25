<?php

namespace App\Support;

/** Random public order identifier ORD-XXXXX-XXXXX (30^10 values, no I L O U 0 1). Resolved from the container so tests can fake it. */
class OrderNumber
{
    public const ALPHABET = 'ABCDEFGHJKMNPQRSTVWXYZ23456789';

    public function generate(): string
    {
        return 'ORD-'.$this->block().'-'.$this->block();
    }

    private function block(): string
    {
        $max = strlen(self::ALPHABET) - 1;

        return implode('', array_map(fn () => self::ALPHABET[random_int(0, $max)], range(1, 5)));
    }
}
