<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\User;
use App\Support\OrderNumber;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * Valid by default: pending, 1 to 3 snapshot lines, matching totals, the creation history row.
 * Status states also write the history chain (a staff actor for every step after creation).
 *
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_number' => app(OrderNumber::class)->generate(),
            'company_id' => Company::factory(),
            // Resolved in afterMaking from the company when not overridden.
            'created_by_user_id' => null,
            'status' => OrderStatus::Pending,
            'currency' => config('catalog.currency'),
            'po_reference' => null,
            'subtotal_cents' => 0,
            'tax_cents' => 0,
            'shipping_cents' => 0,
            'total_cents' => 0,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Order $order) {
            $order->created_by_user_id ??= User::factory()->customer(Company::find($order->company_id))->create()->id;
        })->afterCreating(function (Order $order) {
            $lines = $order->relationLoaded('seed_lines')
                ? $order->getRelation('seed_lines')
                : array_map(fn () => [Product::factory()->create(), fake()->numberBetween(1, 5)], range(1, fake()->numberBetween(1, 3)));
            $order->unsetRelation('seed_lines');

            $subtotal = 0;
            foreach ($lines as [$product, $quantity]) {
                (new OrderItem)->forceFill([
                    'order_id' => $order->id, 'product_id' => $product->id, 'part_number' => $product->part_number, 'name' => $product->name,
                    'brand' => $product->brand, 'unit_price_cents' => $product->price_cents, 'quantity' => $quantity,
                    'line_total_cents' => $product->price_cents * $quantity, 'created_at' => $order->created_at, 'updated_at' => $order->created_at,
                ])->save();
                $subtotal += $product->price_cents * $quantity;
            }

            $order->forceFill(['subtotal_cents' => $subtotal, 'total_cents' => $subtotal, 'updated_at' => $this->writeHistory($order)])->saveQuietly();
        });
    }

    /** @param  list<array{Product, int}>  $lines  [product, quantity] pairs, snapshotted at the product's current price */
    public function withLines(array $lines): static
    {
        return $this->afterMaking(fn (Order $order) => $order->setRelation('seed_lines', $lines));
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

    public function pending(): static
    {
        return $this->state(['status' => OrderStatus::Pending]);
    }

    public function confirmed(): static
    {
        return $this->state(['status' => OrderStatus::Confirmed]);
    }

    public function shipped(): static
    {
        return $this->state(['status' => OrderStatus::Shipped]);
    }

    public function delivered(): static
    {
        return $this->state(['status' => OrderStatus::Delivered]);
    }

    public function cancelled(): static
    {
        return $this->state(['status' => OrderStatus::Cancelled]);
    }

    /** Writes the chain up to the order's status, one hour apart; returns the time of the last row. */
    private function writeHistory(Order $order): Carbon
    {
        $chain = match ($order->status) {
            OrderStatus::Pending => [],
            OrderStatus::Confirmed => [OrderStatus::Confirmed],
            OrderStatus::Shipped => [OrderStatus::Confirmed, OrderStatus::Shipped],
            OrderStatus::Delivered => [OrderStatus::Confirmed, OrderStatus::Shipped, OrderStatus::Delivered],
            OrderStatus::Cancelled => [OrderStatus::Cancelled],
        };
        $staff = $chain ? User::factory()->staff()->create() : null;
        $at = $order->created_at->copy();
        $from = null;

        foreach ([OrderStatus::Pending, ...$chain] as $to) {
            (new OrderStatusHistory)->forceFill([
                'order_id' => $order->id, 'from_status' => $from, 'to_status' => $to,
                'actor_user_id' => $from === null ? $order->created_by_user_id : $staff->id, 'created_at' => $at->copy(),
            ])->save();
            $from = $to;
            $at->addHour();
        }

        return $at->subHour();
    }
}
