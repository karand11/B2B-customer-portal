<?php

namespace Database\Seeders;

use App\Enums\QuoteStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\QuoteItem;
use App\Models\QuoteRequest;
use App\Models\QuoteStatusHistory;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class QuoteDemoSeeder extends Seeder
{
    /** [number, creator email, status, created at (UTC)]. Fixed on purpose: no random; only the quoted rows use "today" for valid_until. */
    private const QUOTES = [
        ['QUO-NWAAA-AAAA2', 'alice@northwind.test', 'submitted', '2026-11-22 09:00:00'],
        ['QUO-NWAAA-AAAA3', 'bob@northwind.test', 'in_review', '2026-11-21 14:30:00'],
        ['QUO-NWAAA-AAAA4', 'alice@northwind.test', 'quoted', '2026-11-18 11:00:00'],
        ['QUO-NWAAA-AAAA5', 'bob@northwind.test', 'accepted', '2026-11-05 09:00:00'],
        ['QUO-NWAAA-AAAA6', 'alice@northwind.test', 'declined', '2026-11-02 15:00:00'],
        ['QUO-NWAAA-AAAA7', 'alice@northwind.test', 'expired', '2026-10-10 10:00:00'],
        ['QUO-GXAAA-AAAA2', 'carol@globex.test', 'submitted', '2026-11-23 08:15:00'],
        ['QUO-GXAAA-AAAA3', 'carol@globex.test', 'quoted', '2026-11-19 16:40:00'],
    ];

    /** DEMO ONLY (local/testing). Idempotent: a quote whose number exists is skipped. No attachments (no files are seeded). */
    public function run(): void
    {
        abort_unless(app()->environment(['local', 'testing']), 500, 'QuoteDemoSeeder only runs in local/testing.');

        User::where('email', 'staff@portal.test')->exists() || $this->call(DemoSeeder::class);
        Product::exists() || $this->call(ProductSeeder::class);
        Order::where('order_number', 'ORD-NWAAA-AAAA3')->exists() || $this->call(OrderDemoSeeder::class);

        $staff = User::where('email', 'staff@portal.test')->firstOrFail();
        $catalog = Product::active()->where('price_on_request', true)->orderBy('id')->first()
            ?? Product::active()->where('price_on_request', false)->orderBy('id')->firstOrFail();
        $today = now()->utc();

        DB::transaction(function () use ($staff, $catalog, $today) {
            foreach (self::QUOTES as [$number, $email, $status, $createdAt]) {
                if (QuoteRequest::where('quote_number', $number)->exists()) {
                    continue;
                }

                $creator = User::where('email', $email)->firstOrFail();
                $created = Carbon::parse($createdAt, 'UTC');
                $spec = $this->spec($number, $catalog, $today);
                $priced = ! in_array(null, array_column($spec['lines'], 2), true);
                $subtotal = $priced ? array_sum(array_map(fn ($l) => $l[1] * $l[2], $spec['lines'])) : null;
                $sent = in_array($status, ['quoted', 'accepted', 'declined', 'expired'], true); // every demo decline happened after sending
                $chain = match ($status) {
                    'submitted' => [],
                    'in_review' => [['in_review', $staff, 2, null]],
                    'quoted' => [['in_review', $staff, 2, null], ['quoted', $staff, 4, null]],
                    'accepted' => [['in_review', $staff, 24, null], ['quoted', $staff, 48, null], ['accepted', $creator, 24 * 7 + 1, null]],
                    'declined' => [['in_review', $staff, 2, null], ['quoted', $staff, 4, null], ['declined', $creator, 26, 'Price too high']],
                    'expired' => [['in_review', $staff, 2, null], ['quoted', $staff, 4, null], ['expired', null, 24 * 20, null]],
                };

                $quote = (new QuoteRequest)->forceFill([
                    'quote_number' => $number, 'company_id' => $creator->company_id, 'created_by_user_id' => $creator->id,
                    'status' => QuoteStatus::from($status), 'currency' => config('catalog.currency'),
                    'po_reference' => $spec['po'], 'customer_note' => $spec['customer_note'], 'staff_note' => $sent ? $spec['staff_note'] : null,
                    'internal_note' => $spec['internal_note'], 'valid_until' => $sent ? $spec['valid_until'] : null,
                    'sent_at' => $sent ? $created->copy()->addHours(4) : null, 'revision' => $sent ? 1 : 0,
                    'subtotal_cents' => $subtotal, 'tax_cents' => 0, 'shipping_cents' => 0, 'total_cents' => $subtotal,
                    'created_at' => $created, 'updated_at' => $created->copy()->addHours(max(array_column($chain, 2) ?: [0])),
                ]);
                $quote->save();
                isset($spec['link_order']) && $spec['link_order']->forceFill(['quote_request_id' => $quote->id])->save();

                foreach ($spec['lines'] as $i => [$what, $quantity, $unit]) {
                    $product = $what instanceof Product ? $what : null;
                    (new QuoteItem)->forceFill([
                        'quote_request_id' => $quote->id, 'position' => $i + 1, 'product_id' => $product?->id,
                        'part_number' => $product?->part_number, 'name' => $product ? $product->name : $what, 'brand' => $product?->brand,
                        'quantity' => $quantity, 'unit_price_cents' => $unit, 'line_total_cents' => $unit === null ? null : $unit * $quantity,
                        'created_at' => $created, 'updated_at' => $created,
                    ])->save();
                }

                $from = null;
                foreach ([['submitted', $creator, 0, null], ...$chain] as [$to, $actor, $hours, $note]) {
                    (new QuoteStatusHistory)->forceFill([
                        'quote_request_id' => $quote->id, 'from_status' => $from, 'to_status' => $to,
                        'actor_user_id' => $actor?->id, 'note' => $note, 'created_at' => $created->copy()->addHours($hours),
                    ])->save();
                    $from = $to;
                }
            }
        });
    }

    /**
     * @return array{lines: list<array{Product|string, int, int|null}>, po: ?string, customer_note: ?string, staff_note: ?string, internal_note: ?string, valid_until: ?string, link_order?: Order}
     */
    private function spec(string $number, Product $catalog, Carbon $today): array
    {
        $base = ['po' => null, 'customer_note' => null, 'staff_note' => null, 'internal_note' => null, 'valid_until' => null];

        return match ($number) {
            'QUO-NWAAA-AAAA2' => ['lines' => [[$catalog, 20, null], ['Taper roller bearing 32208, SKF or equivalent', 4, null]], 'customer_note' => 'Need CE certificates please.'],
            'QUO-NWAAA-AAAA3' => ['lines' => [[$catalog, 10, 1250], ['Pillow block housing, cast iron, 40 mm bore', 6, null]], 'internal_note' => 'Check lead time with supplier'],
            'QUO-NWAAA-AAAA4' => [
                'lines' => [[$catalog, 10, 1250], ['Taper roller bearing 32208, SKF or equivalent', 4, 3875]],
                'po' => 'NW-PO-3001', 'staff_note' => 'Lead time 3 weeks, ex works.', 'valid_until' => $today->copy()->addDays(30)->toDateString(),
            ],
            'QUO-NWAAA-AAAA5' => $this->fromOrder('ORD-NWAAA-AAAA3') + ['valid_until' => '2026-12-15', 'staff_note' => 'Delivery within 10 working days.'],
            'QUO-NWAAA-AAAA6' => ['lines' => [[$catalog, 50, 990], ['Linear guide rail 1200 mm', 2, 15500]], 'staff_note' => 'Bulk price.', 'valid_until' => $today->copy()->addDays(20)->toDateString()],
            'QUO-NWAAA-AAAA7' => ['lines' => [[$catalog, 5, 1400]], 'staff_note' => 'Price valid for 3 weeks.', 'valid_until' => '2026-10-31'],
            'QUO-GXAAA-AAAA2' => ['lines' => [['Spherical roller bearing 22214 E, 70x125x31', 8, null]]],
            'QUO-GXAAA-AAAA3' => ['lines' => [[$catalog, 12, 1300], ['Seal kit for hydraulic cylinder 80 mm', 3, 4200]], 'po' => 'GX-PO-4001', 'valid_until' => $today->copy()->addDays(14)->toDateString()],
        } + $base;
    }

    /** The lines, prices, PO and the order link of an already seeded order: the accepted demo quote becomes that order's origin. */
    private function fromOrder(string $orderNumber): array
    {
        $order = Order::where('order_number', $orderNumber)->with('items')->firstOrFail();
        $products = Product::whereIn('id', $order->items->pluck('product_id'))->get()->keyBy('id');

        return [
            'lines' => $order->items->map(fn ($i) => [$products[$i->product_id], $i->quantity, $i->unit_price_cents])->all(),
            'po' => $order->po_reference,
            'link_order' => $order,
        ];
    }
}
