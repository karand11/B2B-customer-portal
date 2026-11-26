<?php

namespace App\Services;

use Carbon\CarbonImmutable;

final readonly class AllocatedNumber
{
    public function __construct(
        public int $year,
        public int $seq,
        public string $number,
        public CarbonImmutable $issueDate,
    ) {}
}
