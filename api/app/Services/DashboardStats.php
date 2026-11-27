<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The back-office dashboard: four aggregate queries (one row each), live, bound parameters only. "Today" and the ISO week
 * (Monday 00:00 UTC) are computed here from now(), never with CURRENT_DATE/NOW(), so a frozen test clock and the contract agree.
 */
class DashboardStats
{
    /** @return array<string, mixed> */
    public function get(): array
    {
        $now = now()->utc();
        $today = $now->toDateString();
        $soon = $now->copy()->addDays(7)->toDateString();
        $weekStart = $now->copy()->startOfWeek(Carbon::MONDAY);

        $quotes = DB::selectOne("select
                count(*) filter (where status in ('submitted', 'in_review')) as open,
                count(*) filter (where status = 'quoted' and valid_until >= ?) as awaiting,
                count(*) filter (where status = 'quoted' and valid_until >= ? and valid_until <= ?) as expiring
            from quote_requests where status in ('submitted', 'in_review', 'quoted')", [$today, $today, $soon]);

        $orders = DB::selectOne("select
                count(*) filter (where created_at >= ?) as this_week,
                count(*) filter (where status = 'pending') as pending
            from orders where status = 'pending' or created_at >= ?", [$weekStart->format('Y-m-d H:i:s'), $weekStart->format('Y-m-d H:i:s')]);

        $invoices = DB::selectOne("select
                count(*) filter (where status = 'overdue' or (status = 'open' and due_date < ?)) as overdue_count,
                coalesce(sum(total_cents) filter (where status = 'overdue' or (status = 'open' and due_date < ?)), 0) as overdue_total,
                count(*) filter (where status = 'open' and due_date between ? and ?) as due_soon_count,
                coalesce(sum(total_cents) filter (where status = 'open' and due_date between ? and ?), 0) as due_soon_total
            from invoices where status in ('open', 'overdue')", [$today, $today, $today, $soon, $today, $soon]);

        $inactive = DB::selectOne('select count(*) as inactive from products where not active');

        return [
            'generated_at' => $now->format('Y-m-d\TH:i:s\Z'),
            'week_start' => $weekStart->toDateString(),
            'currency' => config('catalog.currency'),
            'quotes' => ['open' => (int) $quotes->open, 'awaiting_customer' => (int) $quotes->awaiting, 'expiring_soon' => (int) $quotes->expiring],
            'orders' => ['this_week' => (int) $orders->this_week, 'pending' => (int) $orders->pending],
            'invoices' => [
                'overdue_count' => (int) $invoices->overdue_count, 'overdue_total_cents' => (int) $invoices->overdue_total,
                'due_soon_count' => (int) $invoices->due_soon_count, 'due_soon_total_cents' => (int) $invoices->due_soon_total,
            ],
            'products' => ['inactive' => (int) $inactive->inactive],
        ];
    }
}
