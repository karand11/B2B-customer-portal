<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use App\Services\InvoiceNumberAllocator;
use App\Services\InvoiceSnapshotBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class InvoiceDemoSeeder extends Seeder
{
    /**
     * [order number, issue date, due date (null = seeding day UTC + 14), stored status, paid on, void reason].
     * Fixed dates on purpose: no random, ascending issue dates so numbers follow issue order like in real use.
     * 000003 is stored `open` with a past due date on purpose (effective status overdue, until invoices:mark-overdue runs).
     */
    private const INVOICES = [
        ['ORD-NWAAA-AAAA5', '2026-09-16', '2026-10-16', 'paid', '2026-10-10', null],
        ['ORD-GXAAA-AAAA3', '2026-10-06', '2026-11-05', 'overdue', null, null],
        ['ORD-NWAAA-AAAA4', '2026-10-29', '2026-11-12', 'open', null, null],
        ['ORD-NWAAA-AAAA3', '2026-11-13', '2026-12-13', 'void', null, 'Wrong billing address (demo)'],
        ['ORD-NWAAA-AAAA3', '2026-11-14', null, 'open', null, null],
    ];

    /**
     * DEMO ONLY (local/testing). Idempotent: an order that already has any invoice is skipped. It does not call IssueInvoice
     * (it needs fixed past dates and history timestamps) but uses the SAME number allocator and snapshot builder, one transaction per invoice.
     */
    public function run(): void
    {
        abort_unless(app()->environment(['local', 'testing']), 500, 'InvoiceDemoSeeder only runs in local/testing.');

        User::where('email', 'staff@portal.test')->exists() || $this->call(DemoSeeder::class);
        Order::where('order_number', 'ORD-NWAAA-AAAA3')->exists() || $this->call(OrderDemoSeeder::class);

        $allocator = app(InvoiceNumberAllocator::class);
        $snapshots = app(InvoiceSnapshotBuilder::class);
        $staff = User::where('email', 'staff@portal.test')->firstOrFail();
        $snapshots->assertConfigured();
        $rate = config('invoicing.vat_rate_bp');
        $today = now()->utc()->toImmutable()->startOfDay();
        $newlyIssued = [];

        foreach (self::INVOICES as [$orderNumber, $issueDate, $dueDate, $status, $paidOn, $reason]) {
            $order = Order::where('order_number', $orderNumber)->firstOrFail();

            // Skip an order that has any invoice, except the one that is intentionally invoiced twice (void, then re-issued).
            if (Invoice::where('order_id', $order->id)->exists() && ! (in_array($orderNumber, $newlyIssued, true))) {
                continue;
            }

            DB::transaction(function () use ($allocator, $snapshots, $order, $issueDate, $dueDate, $status, $paidOn, $reason, $staff, $rate, $today) {
                $issue = CarbonImmutable::parse($issueDate, 'UTC');
                $at = $issue->setTime(9, 0);
                $items = $order->items()->get();
                $attributes = $snapshots->attributes(
                    $order, $items, $allocator->allocate($issue), $dueDate ?? $today->addDays(14)->toDateString(), $rate, $staff->id,
                    $snapshots->seller(), $snapshots->buyer(Company::findOrFail($order->company_id)),
                    ['status' => $status, 'paid_on' => $paidOn, 'created_at' => $at, 'updated_at' => $at],
                );
                $row = fn (string $to, ?int $by, ?string $note, CarbonImmutable $when) => [
                    'from_status' => 'open', 'to_status' => $to, 'actor_user_id' => $by, 'note' => $note, 'created_at' => $when,
                ];
                $history = [['from_status' => null, 'to_status' => 'open', 'actor_user_id' => $staff->id, 'note' => null, 'created_at' => $at]];
                match ($status) {
                    'paid' => $history[] = $row('paid', $staff->id, null, CarbonImmutable::parse($paidOn, 'UTC')->setTime(14, 30)),
                    'overdue' => $history[] = $row('overdue', null, null, CarbonImmutable::parse($attributes['due_date'], 'UTC')->addDay()->setTime(0, 5)),
                    'void' => $history[] = $row('void', $staff->id, $reason, $at->addDay()->setTime(11, 0)),
                    default => null,
                };

                $snapshots->write($attributes, $snapshots->itemRows($items, $at), $history);
            });
            $newlyIssued[] = $orderNumber;
        }
    }
}
