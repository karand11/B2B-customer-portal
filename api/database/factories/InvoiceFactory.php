<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use App\Services\InvoiceNumberAllocator;
use App\Services\InvoiceSnapshotBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * A fully consistent invoice built through the SAME allocator and snapshot builder as production, so numbers are real and
 * sequential. Needs an open transaction (the allocator refuses otherwise; every Pest test has one). The final status is written
 * in ONE insert because the immutability triggers forbid later edits.
 *
 * Without forOrder() it creates a confirmed order (random catalog lines) for forCompany() or a new company.
 * Defaults: open, issued today (UTC), due issue date + config terms. overdue() defaults to issued 45 days ago.
 *
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    public function definition(): array
    {
        return ['__opts' => []];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Invoice $invoice) {
            $o = $invoice->getAttribute('__opts');
            $invoice->offsetUnset('__opts');

            $status = $o['status'] ?? 'open';
            $today = now()->utc()->toImmutable()->startOfDay();
            $issue = isset($o['issue']) ? CarbonImmutable::parse($o['issue'], 'UTC')->startOfDay() : ($status === 'overdue' ? $today->subDays(45) : $today);
            $createdAt = isset($o['issue']) || $status === 'overdue' ? $issue->setTime(9, 0) : now()->utc();
            $due = $o['due'] ?? $issue->addDays((int) config('invoicing.payment_terms_days'))->toDateString();

            $order = $o['order'] ?? $this->makeOrder($o);
            $items = $order->items()->get();
            $actor = $o['actor'] ?? User::factory()->staff()->create();
            $builder = app(InvoiceSnapshotBuilder::class);

            $attributes = $builder->attributes(
                $order, $items, app(InvoiceNumberAllocator::class)->allocate($issue), $due, $o['rate'] ?? (int) config('invoicing.vat_rate_bp'), $actor->id,
                $builder->seller(), $builder->buyer(Company::findOrFail($order->company_id)),
                ['status' => $status, 'paid_on' => $status === 'paid' ? ($o['paidOn'] ?? $issue->toDateString()) : null, 'created_at' => $createdAt, 'updated_at' => $createdAt],
            );
            $invoice->forceFill($attributes);

            $row = fn (?string $from, string $to, ?int $by, ?string $note, int $hours) => [
                'from_status' => $from, 'to_status' => $to, 'actor_user_id' => $by, 'note' => $note, 'created_at' => $createdAt->addHours($hours),
            ];
            $history = [$row(null, 'open', $actor->id, null, 0)];
            match ($status) {
                'overdue' => $history[] = $row('open', 'overdue', null, null, 1),
                'paid' => $history[] = $row('open', 'paid', $actor->id, null, 1),
                'void' => $history[] = $row('open', 'void', $actor->id, 'Voided in test.', 1),
                default => null,
            };

            $invoice->setRelation('plan', ['items' => $builder->itemRows($items, $createdAt), 'history' => $history]);
        })->afterCreating(function (Invoice $invoice) {
            $plan = $invoice->getRelation('plan');
            $invoice->unsetRelation('plan');
            app(InvoiceSnapshotBuilder::class)->writeChildren($invoice, $plan['items'], $plan['history']);
        });
    }

    /** Invoice this existing order (company, lines and PO reference come from it). */
    public function forOrder(Order $order): static
    {
        return $this->opts(['order' => $order]);
    }

    /** Invoice a new confirmed order of this company. */
    public function forCompany(Company $company): static
    {
        return $this->opts(['company' => $company]);
    }

    /** A new order with $n free-text lines (fast bulk insert) instead of the default 1 to 3 catalog lines. */
    public function withLines(int $n): static
    {
        return $this->opts(['lines' => $n]);
    }

    public function open(): static
    {
        return $this->opts(['status' => 'open']);
    }

    /** STORED overdue plus the system history row. (A stored-open invoice with a past due date is "effectively" overdue: use dueOn().) */
    public function overdue(): static
    {
        return $this->opts(['status' => 'overdue']);
    }

    public function paid(?string $paidOn = null): static
    {
        return $this->opts(['status' => 'paid', 'paidOn' => $paidOn]);
    }

    public function void(): static
    {
        return $this->opts(['status' => 'void']);
    }

    /** Issue date (UTC, YYYY-MM-DD); the number's year follows it. */
    public function issuedOn(string $date): static
    {
        return $this->opts(['issue' => $date]);
    }

    public function dueOn(string $date): static
    {
        return $this->opts(['due' => $date]);
    }

    /** VAT rate in basis points for this invoice. */
    public function rate(int $bp): static
    {
        return $this->opts(['rate' => $bp]);
    }

    public function by(User $staff): static
    {
        return $this->opts(['actor' => $staff]);
    }

    /** @param  array<string, mixed>  $o */
    private function opts(array $o): static
    {
        return $this->state(fn (array $attributes) => ['__opts' => $o + ($attributes['__opts'] ?? [])]);
    }

    /** @param  array<string, mixed>  $o */
    private function makeOrder(array $o): Order
    {
        $factory = Order::factory()->confirmed();
        isset($o['company']) && $factory = $factory->forCompany($o['company']);
        isset($o['lines']) && $factory = $factory->withLines([]);
        $order = $factory->create();

        if (isset($o['lines'])) {
            $at = $order->created_at;
            $rows = array_map(fn (int $i) => [
                'order_id' => $order->id, 'product_id' => null, 'part_number' => null, 'name' => "Custom bearing line {$i}", 'brand' => null,
                'unit_price_cents' => 1000 + $i, 'quantity' => 2, 'line_total_cents' => 2 * (1000 + $i), 'created_at' => $at, 'updated_at' => $at,
            ], $o['lines'] > 0 ? range(1, $o['lines']) : []);
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('order_items')->insert($chunk);
            }
            $subtotal = (int) array_sum(array_column($rows, 'line_total_cents'));
            $order->forceFill(['subtotal_cents' => $subtotal, 'total_cents' => $subtotal])->saveQuietly();
        }

        return $order;
    }
}
