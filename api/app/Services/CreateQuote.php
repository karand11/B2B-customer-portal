<?php

namespace App\Services;

use App\Enums\QuoteStatus;
use App\Models\Company;
use App\Models\Product;
use App\Models\QuoteItem;
use App\Models\QuoteRequest;
use App\Models\User;
use App\Support\QuoteNumber;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Customer submits an RFQ. Company and creator come from the session user; the client supplies only lines, note and PO. */
class CreateQuote
{
    public function __construct(private QuoteWorkflow $workflow, private QuoteNotifier $notifier) {}

    /**
     * @param  list<array{product_id?: int, description?: string, quantity: int}>  $lines  already validated
     */
    public function handle(User $user, array $lines, ?string $poReference, ?string $customerNote): QuoteRequest
    {
        $quote = DB::transaction(function () use ($user, $lines, $poReference, $customerNote) {
            // Cap on open quotes per company; the company row lock makes parallel creates count each other.
            Company::whereKey($user->company_id)->lockForUpdate()->first();
            $today = now()->utc()->toDateString();
            $open = QuoteRequest::where('company_id', $user->company_id)->where(fn ($q) => $q->whereIn('status', [QuoteStatus::Submitted, QuoteStatus::InReview])
                ->orWhere(fn ($w) => $w->where('status', QuoteStatus::Quoted)->where('valid_until', '>=', $today)))->count();
            $open < config('quotes.max_open_per_company')
                || throw ValidationException::withMessages(['quote' => 'Your company already has '.config('quotes.max_open_per_company').' open quote requests. Wait for an answer or withdraw one first.']);

            $ids = array_values(array_filter(array_column($lines, 'product_id')));
            $products = Product::active()->whereIn('id', $ids)->get()->keyBy('id');

            $problems = [];
            foreach ($lines as $i => $line) {
                if (isset($line['product_id']) && ! $products->has($line['product_id'])) {
                    $problems["lines.{$i}.product_id"] = 'The selected product is not available.';
                }
            }
            if ($problems) {
                throw ValidationException::withMessages($problems);
            }

            $quote = $this->insertQuote([
                'company_id' => $user->company_id,
                'created_by_user_id' => $user->id,
                'status' => QuoteStatus::Submitted,
                'currency' => config('catalog.currency'),
                'po_reference' => $poReference,
                'customer_note' => $customerNote,
                'tax_cents' => 0,
                'shipping_cents' => 0,
            ]);

            $now = now();
            QuoteItem::insert(array_map(function (array $line, int $i) use ($quote, $products, $now) {
                $product = isset($line['product_id']) ? $products[$line['product_id']] : null;

                return [
                    'quote_request_id' => $quote->id,
                    'position' => $i + 1,
                    'product_id' => $product?->id,
                    'part_number' => $product?->part_number,
                    'name' => $product ? $product->name : $line['description'],
                    'brand' => $product?->brand,
                    'quantity' => $line['quantity'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }, $lines, array_keys($lines)));

            $this->workflow->history($quote, null, QuoteStatus::Submitted, $user, null);

            return $quote;
        });

        $this->notifier->submitted($quote, $user, count($lines));

        return $quote;
    }

    /** Retry with a fresh random number on a (practically impossible) number collision; the savepoint keeps the transaction usable. */
    private function insertQuote(array $attributes): QuoteRequest
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(function () use ($attributes) {
                    $quote = (new QuoteRequest)->forceFill($attributes + ['quote_number' => app(QuoteNumber::class)->generate()]);
                    $quote->save();

                    return $quote;
                });
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= 5) {
                    throw $e;
                }
            }
        }
    }
}
