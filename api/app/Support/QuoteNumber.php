<?php

namespace App\Support;

/** Random public quote identifier QUO-XXXXX-XXXXX, same alphabet as orders. Resolved from the container so tests can fake it. */
class QuoteNumber
{
    public function generate(): string
    {
        return 'QUO-'.$this->block().'-'.$this->block();
    }

    private function block(): string
    {
        $max = strlen(OrderNumber::ALPHABET) - 1;

        return implode('', array_map(fn () => OrderNumber::ALPHABET[random_int(0, $max)], range(1, 5)));
    }
}
