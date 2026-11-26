<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceStatusHistory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Support\InvoiceTotals;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Builds everything an invoice stores as a copy: seller (config) and buyer (company) snapshots, item rows, and the invoice
 * columns. Used by IssueInvoice, InvoiceDemoSeeder and the factory so all three produce identical, consistent rows.
 */
class InvoiceSnapshotBuilder
{
    /** Seller fields that must be filled in (and free of placeholders) everywhere except local and testing. */
    private const REQUIRED_SELLER_FIELDS = ['name', 'tax_id', 'email', 'footer', 'payment_terms_text', 'bank.account_holder', 'bank.iban', 'bank.bic'];

    /** Other seller fields that are optional, but must not carry a placeholder when filled. */
    private const OPTIONAL_SELLER_FIELDS = ['phone', 'bank.bank_name'];

    /**
     * 422 `invoice` when invoicing is not configured. The basic checks (VAT rate, terms, a seller name, address and footer
     * sizes) apply everywhere. In every environment except local and testing (production AND staging) the seller must also be
     * real: no field may contain "PLACEHOLDER" (any case), the email must not end in ".invalid", and every required field is filled.
     * A VAT rate of 0 stays allowed but is logged as a warning (once per issue) in those environments.
     *
     * @throws ValidationException
     */
    public function assertConfigured(): void
    {
        $seller = config('invoicing.seller');
        $rate = config('invoicing.vat_rate_bp');
        $terms = config('invoicing.payment_terms_days');
        $lines = $seller['address_lines'] ?? null;

        $valid = is_int($rate) && $rate >= 0 && $rate <= InvoiceTotals::MAX_RATE_BP
            && is_int($terms) && $terms >= 0 && $terms <= 120
            && is_string($seller['name'] ?? null) && trim($seller['name']) !== ''
            && is_array($lines) && count($lines) <= 6 && collect($lines)->every(fn ($l) => is_string($l) && mb_strlen($l) <= 80)
            && is_string($seller['footer'] ?? null) && mb_strlen($seller['footer']) <= 300;

        if ($valid && ! app()->environment(['local', 'testing'])) {
            $valid = $this->sellerIsReal($seller);
            $valid && $rate === 0 && Log::warning('Invoicing VAT rate is 0: set INVOICING_VAT_RATE_BP if invoices must carry VAT.');
        }

        $valid || throw ValidationException::withMessages(['invoice' => 'Invoicing is not configured.']);
    }

    /** @param  array<string, mixed>  $seller */
    private function sellerIsReal(array $seller): bool
    {
        $text = fn (mixed $v) => is_string($v) ? trim($v) : '';
        $lines = array_map($text, $seller['address_lines']);

        foreach (self::REQUIRED_SELLER_FIELDS as $path) {
            if ($text(data_get($seller, $path)) === '') {
                return false;
            }
        }

        $values = [...array_map(fn ($p) => $text(data_get($seller, $p)), [...self::REQUIRED_SELLER_FIELDS, ...self::OPTIONAL_SELLER_FIELDS]), ...$lines];

        return $lines !== [] && ! in_array('', $lines, true)
            && ! collect($values)->contains(fn ($v) => stripos($v, 'PLACEHOLDER') !== false)
            && ! str_ends_with(strtolower($text($seller['email'])), '.invalid');
    }

    /** @return array<string, mixed> */
    public function seller(): array
    {
        $s = config('invoicing.seller');

        return [
            'name' => $s['name'],
            'address_lines' => array_values($s['address_lines']),
            'tax_id' => $s['tax_id'],
            'email' => $s['email'],
            'phone' => $s['phone'],
            'bank' => [
                'account_holder' => $s['bank']['account_holder'],
                'bank_name' => $s['bank']['bank_name'],
                'iban' => $s['bank']['iban'],
                'bic' => $s['bank']['bic'],
            ],
            'payment_terms_text' => $s['payment_terms_text'],
            'footer' => $s['footer'],
            'vat_label' => (string) config('invoicing.vat_label'),
        ];
    }

    /**
     * @return array{name: string, address_lines: list<string>, tax_id: string|null}
     *
     * @throws ValidationException 422 `company` without a usable billing address
     */
    public function buyer(Company $company): array
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) $company->billing_address) ?: []), fn ($l) => $l !== ''));

        if ($lines === [] || count($lines) > 6) {
            throw ValidationException::withMessages(['company' => 'The company needs a billing address (1 to 6 lines) before it can be invoiced.']);
        }

        return ['name' => $company->name, 'address_lines' => $lines, 'tax_id' => $company->tax_id];
    }

    /**
     * The invoice columns (no id, no status history). Status defaults to open; the factory and seeder pass the final
     * state so it is written in ONE insert (the immutability triggers forbid later edits).
     *
     * @param  Collection<int, OrderItem>  $items
     * @param  array<string, mixed>  $final  status, paid_on, created_at overrides
     * @return array<string, mixed>
     */
    public function attributes(Order $order, Collection $items, AllocatedNumber $number, string $dueDate, int $rateBp, int $actorId, array $seller, array $buyer, array $final = []): array
    {
        $subtotal = (int) $items->sum('line_total_cents');
        $totals = InvoiceTotals::compute($subtotal, $rateBp);
        $now = now()->utc();

        return $final + [
            'public_id' => (string) Str::uuid(),
            'number' => $number->number,
            'number_year' => $number->year,
            'number_seq' => $number->seq,
            'company_id' => $order->company_id,
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'created_by_user_id' => $actorId,
            'status' => 'open',
            'issue_date' => $number->issueDate->toDateString(),
            'due_date' => $dueDate,
            'paid_on' => null,
            'currency' => $order->currency,
            'po_reference' => $order->po_reference,
            'vat_rate_bp' => $rateBp,
            'subtotal_cents' => $subtotal,
            'tax_cents' => $totals['tax'],
            'total_cents' => $totals['total'],
            'seller' => $seller,
            'buyer' => $buyer,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * One row per order line, in order-line order, positions 1..n. NULL part number and brand stay NULL (free-text quote lines).
     *
     * @param  Collection<int, OrderItem>  $items
     * @return list<array<string, mixed>>
     */
    public function itemRows(Collection $items, DateTimeInterface $createdAt): array
    {
        return $items->values()->map(fn ($item, $i) => [
            'position' => $i + 1,
            'part_number' => $item->part_number,
            'name' => $item->name,
            'brand' => $item->brand,
            'quantity' => $item->quantity,
            'unit_price_cents' => $item->unit_price_cents,
            'line_total_cents' => $item->line_total_cents,
            'created_at' => $createdAt,
        ])->all();
    }

    /**
     * Insert the invoice, its items (one bulk statement) and its history rows. Call inside the transaction that allocated the number.
     *
     * @param  array<string, mixed>  $attributes  from attributes()
     * @param  list<array<string, mixed>>  $itemRows  from itemRows()
     * @param  list<array<string, mixed>>  $history  from_status, to_status, actor_user_id, note, created_at
     */
    public function write(array $attributes, array $itemRows, array $history): Invoice
    {
        $invoice = (new Invoice)->forceFill($attributes);
        $invoice->save();
        $this->writeChildren($invoice, $itemRows, $history);

        return $invoice;
    }

    /**
     * @param  list<array<string, mixed>>  $itemRows
     * @param  list<array<string, mixed>>  $history
     */
    public function writeChildren(Invoice $invoice, array $itemRows, array $history): void
    {
        DB::table('invoice_items')->insert(array_map(fn ($r) => $r + ['invoice_id' => $invoice->id], $itemRows));

        foreach ($history as $row) {
            (new InvoiceStatusHistory)->forceFill($row + ['invoice_id' => $invoice->id])->save();
        }
    }
}
