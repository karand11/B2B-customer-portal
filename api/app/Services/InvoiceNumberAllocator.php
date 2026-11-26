<?php

namespace App\Services;

use App\Exceptions\InvoiceYearRolled;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Gap-free invoice numbers: a counter ROW per year, changed inside the same transaction that inserts the invoice,
 * so a rollback undoes the increment (a Postgres sequence would burn the number). The only writer of the counter table.
 * The upsert row lock is held until the surrounding transaction ends, so concurrent issuers queue behind each other.
 */
class InvoiceNumberAllocator
{
    /** @param  Closure(): CarbonImmutable|null  $clock  tests only; defaults to the UTC wall clock */
    public function __construct(private readonly ?ConnectionInterface $connection = null, private readonly ?Closure $clock = null) {}

    /**
     * @param  CarbonImmutable|null  $issueDate  explicit date (seeder, factory); null = today (UTC), read AFTER the counter lock
     *
     * @throws InvoiceYearRolled when the clock crossed a year boundary between the two reads (nothing consumed; retry)
     * @throws LogicException outside a transaction (the lock would be released at once and a later failure would leave a gap)
     */
    public function allocate(?CarbonImmutable $issueDate = null): AllocatedNumber
    {
        $db = $this->connection ?? DB::connection();

        if ($db->transactionLevel() === 0) {
            throw new LogicException('Invoice numbers must be allocated inside the invoice transaction.');
        }

        // The clock is read exactly twice on the production path: once to pick the counter year, once AFTER the lock for the issue date.
        $now = $this->now();
        $year = ($issueDate ?? $now)->year;
        $row = $db->selectOne(
            'INSERT INTO invoice_number_counters (year, last_value, updated_at) VALUES (?, 1, ?)
             ON CONFLICT (year) DO UPDATE SET last_value = invoice_number_counters.last_value + 1, updated_at = excluded.updated_at
             RETURNING last_value',
            [$year, $now->toDateTimeString()]
        );

        // Read the date after the lock: within a year, numbers and issue dates are then non-decreasing.
        $issueDate ??= $this->now()->startOfDay();
        if ($issueDate->year !== $year) {
            throw new InvoiceYearRolled;
        }

        $seq = (int) $row->last_value;

        return new AllocatedNumber($year, $seq, sprintf('INV-%04d-%06d', $year, $seq), $issueDate->startOfDay());
    }

    private function now(): CarbonImmutable
    {
        return $this->clock ? ($this->clock)() : now()->utc()->toImmutable();
    }
}
