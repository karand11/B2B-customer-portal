<?php

namespace Database\Seeders;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class OrderDemoSeeder extends Seeder
{
    /** [number, creator email, status, created at (UTC), PO reference or null]. Fixed on purpose: no now(), no random. */
    private const ORDERS = [
        ['ORD-NWAAA-AAAA2', 'alice@northwind.test', 'pending', '2026-11-20 09:00:00', 'NW-PO-1001'],
        ['ORD-NWAAA-AAAA3', 'bob@northwind.test', 'confirmed', '2026-11-12 10:30:00', 'NW-PO-1002'],
        ['ORD-NWAAA-AAAA4', 'alice@northwind.test', 'shipped', '2026-10-28 14:00:00', null],
        ['ORD-NWAAA-AAAA5', 'bob@northwind.test', 'delivered', '2026-09-15 08:45:00', null],
        ['ORD-NWAAA-AAAA6', 'alice@northwind.test', 'cancelled', '2026-09-02 16:20:00', null],
        ['ORD-GXAAA-AAAA2', 'carol@globex.test', 'pending', '2026-11-21 11:00:00', 'GX-PO-2001'],
        ['ORD-GXAAA-AAAA3', 'carol@globex.test', 'delivered', '2026-10-05 13:10:00', null],
        ['ORD-GXAAA-AAAA4', 'carol@globex.test', 'cancelled', '2026-09-20 09:30:00', null],
    ];

    /** Quantities of the three lines of the n-th order. */
    private const QUANTITIES = [[2, 1, 5], [10, 2, 1], [1, 4, 3], [6, 6, 2], [3, 1, 1], [8, 2, 4], [1, 1, 12], [5, 3, 2]];

    /** DEMO ONLY (local/testing). Idempotent: an order whose number exists is skipped. */
    public function run(): void
    {
        abort_unless(app()->environment(['local', 'testing']), 500, 'OrderDemoSeeder only runs in local/testing.');

        User::where('email', 'staff@portal.test')->exists() || $this->call(DemoSeeder::class);
        Product::exists() || $this->call(ProductSeeder::class);

        $staff = User::where('email', 'staff@portal.test')->firstOrFail();
        $products = Product::active()->where('price_on_request', false)->where('stock_status', 'in_stock')->orderBy('id')->limit(40)->get()->values();

        DB::transaction(function () use ($staff, $products) {
            foreach (self::ORDERS as $n => [$number, $email, $status, $createdAt, $po]) {
                if (Order::where('order_number', $number)->exists()) {
                    continue;
                }

                $creator = User::where('email', $email)->firstOrFail();
                $created = Carbon::parse($createdAt, 'UTC');
                $lines = collect([0, 1, 2])->map(fn ($i) => [$products[($n + $i) % $products->count()], self::QUANTITIES[$n][$i]]);
                $subtotal = $lines->sum(fn ($l) => $l[0]->price_cents * $l[1]);
                $chain = match ($status) {
                    'pending' => [], 'confirmed' => ['confirmed'], 'shipped' => ['confirmed', 'shipped'],
                    'delivered' => ['confirmed', 'shipped', 'delivered'], 'cancelled' => ['cancelled'],
                };

                $order = (new Order)->forceFill([
                    'order_number' => $number, 'company_id' => $creator->company_id, 'created_by_user_id' => $creator->id,
                    'status' => OrderStatus::from($status), 'currency' => config('catalog.currency'), 'po_reference' => $po,
                    'subtotal_cents' => $subtotal, 'tax_cents' => 0, 'shipping_cents' => 0, 'total_cents' => $subtotal,
                    'created_at' => $created, 'updated_at' => $created->copy()->addHours(count($chain)),
                ]);
                $order->save();

                foreach ($lines as [$product, $quantity]) {
                    (new OrderItem)->forceFill([
                        'order_id' => $order->id, 'product_id' => $product->id, 'part_number' => $product->part_number,
                        'name' => $product->name, 'brand' => $product->brand, 'unit_price_cents' => $product->price_cents,
                        'quantity' => $quantity, 'line_total_cents' => $product->price_cents * $quantity,
                        'created_at' => $created, 'updated_at' => $created,
                    ])->save();
                }

                $from = null;
                foreach (['pending', ...$chain] as $step => $to) {
                    (new OrderStatusHistory)->forceFill([
                        'order_id' => $order->id, 'from_status' => $from, 'to_status' => $to,
                        'actor_user_id' => $step === 0 ? $creator->id : $staff->id, 'created_at' => $created->copy()->addHours($step),
                    ])->save();
                    $from = $to;
                }
            }
        });
    }
}
