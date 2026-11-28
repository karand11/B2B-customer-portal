<?php

namespace Tests\Support;

use App\Models\Company;
use App\Models\Order;
use App\Models\QuoteAttachment;
use App\Models\QuoteRequest;
use App\Models\User;
use App\Services\ProductImportService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;

/**
 * The fixture of the Phase 7 gate tests (CrossCompanyMatrixTest, MassAssignmentSweepTest): two companies with the same kinds of rows,
 * and for EVERY write route a minimal VALID request (RouteMatrix::BODY is built here), so a 422 can never hide a leak or an ignored field.
 *
 * $w->A and $w->B each hold: order (confirmed), pendingOrder, submitted, review (in review, fully priced), quoted, att (customer file on
 * `submitted`), staffAtt (staff file on `quoted`), invoice (open). Users: a1, a2 (company A), b1 (company B), loner (a customer without a company), staff, admin.
 */
class MatrixWorld
{
    public static function build(): object
    {
        Storage::fake('quotes');
        Storage::fake('imports');

        $w = quoteWorld();
        $w->loner = makeUser('customer');
        User::whereKey($w->loner->id)->update(['company_id' => null]);
        $w->loner->refresh();

        foreach (['A' => [$w->a, $w->a1], 'B' => [$w->b, $w->b1]] as $label => [$company, $creator]) {
            $set = (object) [
                'company' => $company,
                'order' => orderWith($company, [[1000, 2], [2500, 1]], 'confirmed'),
                'pendingOrder' => orderWith($company, [[1500, 1]], 'pending'),
                'invoice' => makeInvoice($company),
            ];
            $lines = [[$w->priced, 10, null], ['Taper roller bearing 32208, SKF or equivalent', 4, null]];
            $set->submitted = QuoteRequest::factory()->forCompany($company, $creator)->submitted()->withLines($lines)->create();
            $set->review = QuoteRequest::factory()->forCompany($company, $creator)->inReview()->withLines([[$w->priced, 10, 1250], ['Custom line', 4, 500]])
                ->validUntil(now()->utc()->addDays(30)->toDateString())->create();
            $set->quoted = QuoteRequest::factory()->forCompany($company, $creator)->quoted()->withLines($lines)->create();
            $set->att = QuoteAttachment::factory()->create(['quote_request_id' => $set->submitted->id]);
            $set->staffAtt = QuoteAttachment::factory()->byStaff($w->staff)->create(['quote_request_id' => $set->quoted->id]);
            $w->{$label} = $set;
        }

        return $w;
    }

    /** A pending invited user (no token yet, so a resend is allowed at once). */
    public static function pendingUser(object $w): User
    {
        return makeUser('customer', $w->a, ['invited_at' => now()->subDays(1), 'invitation_accepted_at' => null]);
    }

    /**
     * The minimal valid request of every write route, as [actor, uri, body] for company set $s (A or B).
     * Setup that a request consumes (an import preview, a reset token) is created here, so call it INSIDE the savepoint of the run.
     *
     * @return array{User|null, string, array<string, mixed>}
     */
    public static function spec(string $key, object $w, string $set = 'A'): array
    {
        $s = $w->{$set};
        $customer = $set === 'A' ? $w->a1 : $w->b1;
        $q = $s->quoted->quote_number;
        $pdf = fn () => UploadedFile::fake()->createWithContent('drawing.pdf', pdfBytes());
        $uri = fn (string $u) => '/api/v1/'.$u;

        return match ($key) {
            'POST api/v1/auth/login' => [null, $uri('auth/login'), ['email' => $w->a1->email, 'password' => TEST_PASSWORD]],
            'POST api/v1/auth/forgot-password' => [null, $uri('auth/forgot-password'), ['email' => $w->a1->email]],
            'POST api/v1/auth/reset-password' => [null, $uri('auth/reset-password'), [
                'token' => Password::createToken($w->a2), 'email' => $w->a2->email, 'password' => 'Another-Passw0rd-9', 'password_confirmation' => 'Another-Passw0rd-9',
            ]],
            'POST api/v1/auth/accept-invitation' => (function () use ($w, $uri) {
                $user = self::pendingUser($w);
                $token = Password::broker('invitations')->createToken($user);

                return [null, $uri('auth/accept-invitation'), ['token' => $token, 'email' => $user->email, 'password' => 'Another-Passw0rd-9', 'password_confirmation' => 'Another-Passw0rd-9']];
            })(),
            'POST api/v1/auth/logout' => [$customer, $uri('auth/logout'), []],

            'DELETE api/v1/cart' => [$customer, $uri('cart'), []],
            'POST api/v1/cart/items' => [$customer, $uri('cart/items'), ['product_id' => $w->priced->id, 'quantity' => 2]],
            'PATCH api/v1/cart/items/{productId}' => (function () use ($w, $customer, $uri) {
                fillCart($customer, [[$w->priced, 1]]);

                return [$customer, $uri("cart/items/{$w->priced->id}"), ['quantity' => 3]];
            })(),
            'DELETE api/v1/cart/items/{productId}' => (function () use ($w, $customer, $uri) {
                fillCart($customer, [[$w->priced, 1]]);

                return [$customer, $uri("cart/items/{$w->priced->id}"), []];
            })(),
            'POST api/v1/orders' => (function () use ($w, $customer, $uri) {
                fillCart($customer, [[$w->priced, 2]]);

                return [$customer, $uri('orders'), ['po_reference' => 'PO-MATRIX-1']];
            })(),
            'POST api/v1/orders/{number}/reorder' => [$customer, $uri("orders/{$s->order->order_number}/reorder"), []],
            'POST api/v1/orders/{number}/cancel' => [$customer, $uri("orders/{$s->pendingOrder->order_number}/cancel"), []],
            'PATCH api/v1/orders/{number}/status' => [$w->staff, $uri("orders/{$s->pendingOrder->order_number}/status"), ['status' => 'confirmed']],
            'POST api/v1/orders/{number}/invoice' => [$w->staff, $uri("orders/{$s->order->order_number}/invoice"), []],

            'POST api/v1/quotes' => [$customer, $uri('quotes'), ['customer_note' => 'Please quote.', 'lines' => [['product_id' => $w->priced->id, 'quantity' => 5]]]],
            'POST api/v1/quotes/{number}/accept' => [$customer, $uri("quotes/{$q}/accept"), ['revision' => $s->quoted->revision]],
            'POST api/v1/quotes/{number}/decline' => [$customer, $uri("quotes/{$s->submitted->quote_number}/decline"), ['reason' => 'Not needed']],
            'POST api/v1/quotes/{number}/review' => [$w->staff, $uri("quotes/{$s->submitted->quote_number}/review"), []],
            'PUT api/v1/quotes/{number}/pricing' => [$w->staff, $uri("quotes/{$s->review->quote_number}/pricing"), [
                'lines' => [['position' => 1, 'unit_price_cents' => 1300], ['position' => 2, 'unit_price_cents' => 600]],
            ]],
            'POST api/v1/quotes/{number}/send' => [$w->staff, $uri("quotes/{$s->review->quote_number}/send"), ['staff_note' => 'Valid for 30 days.']],
            'POST api/v1/quotes/{number}/reject' => [$w->staff, $uri("quotes/{$s->submitted->quote_number}/reject"), ['reason' => 'Out of range']],
            'POST api/v1/quotes/{number}/reopen' => [$w->staff, $uri("quotes/{$q}/reopen"), []],
            'PUT api/v1/quotes/{number}/internal-note' => [$w->staff, $uri("quotes/{$q}/internal-note"), ['internal_note' => 'Check margin.']],
            'POST api/v1/quotes/{number}/attachments' => [$customer, $uri("quotes/{$s->submitted->quote_number}/attachments"), ['file' => $pdf()]],
            'DELETE api/v1/quotes/{number}/attachments/{attachment}' => [$customer, $uri("quotes/{$s->submitted->quote_number}/attachments/{$s->att->public_id}"), []],

            'POST api/v1/invoices/{invoice}/mark-paid' => [$w->staff, $uri("invoices/{$s->invoice->public_id}/mark-paid"), ['paid_on' => now()->utc()->toDateString()]],
            'POST api/v1/invoices/{invoice}/void' => [$w->staff, $uri("invoices/{$s->invoice->public_id}/void"), ['reason' => 'Issued in error']],

            'POST api/v1/admin/products' => [$w->staff, $uri('admin/products'), adminProductBody(['part_number' => 'MATRIX-NEW-1'])],
            'PUT api/v1/admin/products/{product}' => [$w->staff, $uri("admin/products/{$w->priced->id}"), adminProductBody(['part_number' => $w->priced->part_number, 'version' => $w->priced->fresh()->version])],
            'POST api/v1/admin/products/{product}/activate' => [$w->staff, $uri("admin/products/{$w->inactive->id}/activate"), []],
            'POST api/v1/admin/products/{product}/deactivate' => [$w->staff, $uri("admin/products/{$w->priced->id}/deactivate"), []],
            'POST api/v1/admin/products/imports' => [$w->staff, $uri('admin/products/imports'), ['file' => self::csv()]],
            'POST api/v1/admin/products/imports/{import}/apply' => (function () use ($w, $uri) {
                $import = app(ProductImportService::class)->preview($w->staff, self::csv());

                return [$w->staff, $uri("admin/products/imports/{$import->id}/apply"), ['skip_invalid' => true]];
            })(),
            'PUT api/v1/admin/companies/{company}/billing' => [$w->staff, $uri("admin/companies/{$w->a->id}/billing"), [
                'billing_address' => "1 Test Road\nTestville 00000\nTestland", 'tax_id' => 'TAX-123', 'version' => $w->a->fresh()->version,
            ]],
            'POST api/v1/admin/companies' => [$w->admin, $uri('admin/companies'), ['name' => 'Matrix New Co']],
            'PUT api/v1/admin/companies/{company}' => [$w->admin, $uri("admin/companies/{$w->a->id}"), ['name' => 'Northwind Renamed', 'billing_address' => null, 'tax_id' => null, 'version' => $w->a->fresh()->version]],
            'POST api/v1/admin/users' => [$w->admin, $uri('admin/users'), ['name' => 'Nina Newhire', 'email' => 'nina@matrix.test', 'role' => 'customer', 'company_id' => $w->a->id]],
            'PATCH api/v1/admin/users/{user}' => [$w->admin, $uri("admin/users/{$w->a2->id}"), ['name' => 'Renamed User']],
            'PUT api/v1/admin/users/{user}/assignment' => [$w->admin, $uri("admin/users/{$w->a2->id}/assignment"), ['role' => 'customer', 'company_id' => $w->b->id]],
            'POST api/v1/admin/users/{user}/deactivate' => [$w->admin, $uri("admin/users/{$w->a2->id}/deactivate"), []],
            'POST api/v1/admin/users/{user}/reactivate' => [$w->admin, $uri('admin/users/'.makeUser('customer', $w->a, ['active' => false])->id.'/reactivate'), []],
            'POST api/v1/admin/users/{user}/password-reset' => [$w->admin, $uri("admin/users/{$w->a2->id}/password-reset"), []],
            'POST api/v1/admin/users/{user}/invitation' => [$w->admin, $uri('admin/users/'.self::pendingUser($w)->id.'/invitation'), []],
            'DELETE api/v1/admin/users/{user}/invitation' => [$w->admin, $uri('admin/users/'.self::pendingUser($w)->id.'/invitation'), []],
        };
    }

    private static function csv(): UploadedFile
    {
        $header = 'part_number,name,brand,category,inner_diameter_mm,outer_diameter_mm,width_mm,material,seal_type,stock_status,price_cents';

        return UploadedFile::fake()->createWithContent('catalog.csv', $header."\nMTX-1,Matrix bearing,SKF,deep-groove-ball,10,30,9,Chrome steel,2rs,in_stock,1500\n");
    }
}
