<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Enums\QuoteStatus;
use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\QuoteItem;
use App\Models\QuoteRequest;
use App\Models\QuoteStatusHistory;
use App\Models\User;
use App\Support\OrderNumber;
use App\Support\QuoteNumber;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * Valid by default: submitted, two lines (one catalog, one custom), the creation history row.
 * Status states also write the history chain and satisfy every CHECK (quoted/accepted/expired quotes are fully priced and sent;
 * accepted also creates the order). Lines: withLines([[Product|string description, quantity, unit price cents|null], ...]).
 *
 * @extends Factory<QuoteRequest>
 */
class QuoteRequestFactory extends Factory
{
    public function definition(): array
    {
        return [
            'quote_number' => app(QuoteNumber::class)->generate(),
            'company_id' => Company::factory(),
            // Resolved in afterMaking from the company when not overridden.
            'created_by_user_id' => null,
            'status' => QuoteStatus::Submitted,
            'currency' => config('catalog.currency'),
            'po_reference' => null,
            'customer_note' => null,
            'staff_note' => null,
            'internal_note' => null,
            'valid_until' => null,
            'sent_at' => null,
            'revision' => 0,
            'subtotal_cents' => null,
            'tax_cents' => 0,
            'shipping_cents' => 0,
            'total_cents' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (QuoteRequest $quote) {
            $quote->created_by_user_id ??= User::factory()->customer(Company::find($quote->company_id))->create()->id;
            $quote->created_at ??= now();
            $quote->updated_at ??= $quote->created_at;

            // withLines() smuggles its lines in through a pseudo attribute; it must not reach the INSERT.
            $lines = $quote->getAttribute('__lines') ?? [[Product::factory()->create(), 2, null], ['Custom taper roller bearing', 3, null]];
            $quote->offsetUnset('__lines');

            $sent = in_array($quote->status, [QuoteStatus::Quoted, QuoteStatus::Accepted, QuoteStatus::Expired], true);
            foreach ($lines as $i => &$line) {
                $line[2] ??= $sent ? 1000 * ($i + 1) : null;
            }
            unset($line);

            if ($lines !== [] && ! in_array(null, array_column($lines, 2), true)) {
                $subtotal = array_sum(array_map(fn ($l) => $l[1] * $l[2], $lines));
                $quote->subtotal_cents ??= $subtotal;
                $quote->total_cents ??= $subtotal;
            }
            if ($sent) {
                $quote->sent_at ??= $quote->created_at->copy()->addHours(2);
                $quote->revision = max($quote->revision, 1);
                $quote->valid_until ??= $quote->status === QuoteStatus::Expired ? now()->utc()->subDays(5) : now()->utc()->addDays(30);
            }
            $quote->setRelation('plan', $lines);
        })->afterCreating(function (QuoteRequest $quote) {
            $lines = $quote->getRelation('plan');
            $quote->unsetRelation('plan');
            $at = $quote->created_at;

            foreach ($lines as $i => [$what, $quantity, $unit]) {
                $product = $what instanceof Product ? $what : null;
                (new QuoteItem)->forceFill([
                    'quote_request_id' => $quote->id, 'position' => $i + 1, 'product_id' => $product?->id,
                    'part_number' => $product?->part_number, 'name' => $product ? $product->name : $what, 'brand' => $product?->brand,
                    'quantity' => $quantity, 'unit_price_cents' => $unit, 'line_total_cents' => $unit === null ? null : $unit * $quantity,
                    'created_at' => $at, 'updated_at' => $at,
                ])->save();
            }

            $this->writeHistory($quote);
        });
    }

    /** @param  list<array{Product|string, int, int|null}>  $lines  [catalog product or custom description, quantity, unit price cents or null] */
    public function withLines(array $lines): static
    {
        return $this->state(['__lines' => $lines]);
    }

    public function forCompany(Company $company, ?User $creator = null): static
    {
        return $this->state(['company_id' => $company->id, 'created_by_user_id' => $creator?->id]);
    }

    public function createdAt(Carbon|string $date): static
    {
        $date = Carbon::parse($date, 'UTC');

        return $this->state(['created_at' => $date, 'updated_at' => $date]);
    }

    public function validUntil(string $date): static
    {
        return $this->state(['valid_until' => $date]);
    }

    public function submitted(): static
    {
        return $this->state(['status' => QuoteStatus::Submitted]);
    }

    public function inReview(): static
    {
        return $this->state(['status' => QuoteStatus::InReview]);
    }

    /** Fully priced and sent (revision 1), valid for 30 more days unless validUntil() says otherwise. */
    public function quoted(): static
    {
        return $this->state(['status' => QuoteStatus::Quoted]);
    }

    /** Quoted, then accepted by the creator: also creates the order, linked through orders.quote_request_id. */
    public function accepted(): static
    {
        return $this->state(['status' => QuoteStatus::Accepted]);
    }

    /** Withdrawn by the customer before staff did anything. */
    public function declined(): static
    {
        return $this->state(['status' => QuoteStatus::Declined]);
    }

    /** Materialised expiry (stored status expired, system history row). */
    public function expired(): static
    {
        return $this->state(['status' => QuoteStatus::Expired]);
    }

    /** Writes the chain up to the quote's status, one hour apart. */
    private function writeHistory(QuoteRequest $quote): void
    {
        $chain = match ($quote->status) {
            QuoteStatus::Submitted => [],
            QuoteStatus::InReview => [QuoteStatus::InReview],
            QuoteStatus::Quoted => [QuoteStatus::InReview, QuoteStatus::Quoted],
            QuoteStatus::Accepted => [QuoteStatus::InReview, QuoteStatus::Quoted, QuoteStatus::Accepted],
            QuoteStatus::Declined => [QuoteStatus::Declined],
            QuoteStatus::Expired => [QuoteStatus::InReview, QuoteStatus::Quoted, QuoteStatus::Expired],
        };
        $staff = $chain ? User::factory()->staff()->create() : null;
        $at = $quote->created_at->copy();
        $from = null;

        foreach ([QuoteStatus::Submitted, ...$chain] as $to) {
            $byCustomer = $from === null || in_array($to, [QuoteStatus::Accepted, QuoteStatus::Declined], true);
            (new QuoteStatusHistory)->forceFill([
                'quote_request_id' => $quote->id, 'from_status' => $from, 'to_status' => $to,
                'actor_user_id' => $to === QuoteStatus::Expired ? null : ($byCustomer ? $quote->created_by_user_id : $staff->id),
                'note' => $to === QuoteStatus::Declined ? 'Withdrawn by the customer.' : null, 'created_at' => $at->copy(),
            ])->save();
            $from = $to;
            $at->addHour();
        }

        if ($quote->status === QuoteStatus::Accepted) {
            $this->createOrder($quote, $at->copy()->subHour());
        }
    }

    private function createOrder(QuoteRequest $quote, Carbon $at): void
    {
        $order = (new Order)->forceFill([
            'order_number' => app(OrderNumber::class)->generate(), 'company_id' => $quote->company_id, 'created_by_user_id' => $quote->created_by_user_id,
            'status' => OrderStatus::Pending, 'currency' => $quote->currency, 'po_reference' => $quote->po_reference,
            'subtotal_cents' => $quote->subtotal_cents, 'tax_cents' => 0, 'shipping_cents' => 0, 'total_cents' => $quote->total_cents,
            'quote_request_id' => $quote->id, 'created_at' => $at, 'updated_at' => $at,
        ]);
        $order->save();

        foreach (QuoteItem::where('quote_request_id', $quote->id)->orderBy('position')->get() as $item) {
            (new OrderItem)->forceFill([
                'order_id' => $order->id, 'product_id' => $item->product_id, 'part_number' => $item->part_number, 'name' => $item->name,
                'brand' => $item->brand, 'unit_price_cents' => $item->unit_price_cents, 'quantity' => $item->quantity,
                'line_total_cents' => $item->line_total_cents, 'created_at' => $at, 'updated_at' => $at,
            ])->save();
        }

        (new OrderStatusHistory)->forceFill([
            'order_id' => $order->id, 'from_status' => null, 'to_status' => OrderStatus::Pending,
            'actor_user_id' => $quote->created_by_user_id, 'created_at' => $at,
        ])->save();
    }
}
