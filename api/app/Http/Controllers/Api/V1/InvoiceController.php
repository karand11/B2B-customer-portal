<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateInvoiceRequest;
use App\Http\Requests\ListInvoicesRequest;
use App\Http\Requests\MarkInvoicePaidRequest;
use App\Http\Requests\VoidInvoiceRequest;
use App\Http\Resources\InvoiceDetailResource;
use App\Http\Resources\InvoiceListResource;
use App\Models\Invoice;
use App\Models\Order;
use App\Services\InvoicePdf;
use App\Services\InvoiceWorkflow;
use App\Services\IssueInvoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Every invoice lookup goes through Invoice::visibleTo() and then the policy (two layers; foreign = 404). */
class InvoiceController extends Controller
{
    public function index(ListInvoicesRequest $request): InvoiceListResource
    {
        $c = $request->criteria();
        $invoices = Invoice::visibleTo($request->user())
            ->filter($c)
            ->withCount('items as line_count')
            ->paginate($c['per_page'], ['*'], 'page', $c['page']);

        return new InvoiceListResource($invoices);
    }

    public function show(Request $request, string $invoice): InvoiceDetailResource
    {
        $invoice = $this->find($request, $invoice);
        $this->authorize('view', $invoice);

        return $this->respond($invoice);
    }

    public function pdf(Request $request, string $invoice, InvoicePdf $pdf): Response
    {
        // HEAD is answered by every GET route and would run the whole dompdf render for a body nobody reads: refuse it before any lookup.
        abort_if($request->isMethod('HEAD'), 405, '', ['Allow' => 'GET']);

        $invoice = $this->find($request, $invoice, ['items']);
        $this->authorize('download', $invoice);
        $body = $pdf->render($invoice);

        // The filename comes only from the stored number (CHECK invoices_number_format); no user text ever reaches a header.
        return response($body, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$invoice->number.'.pdf"',
            'Content-Length' => (string) strlen($body),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    public function storeForOrder(CreateInvoiceRequest $request, string $number, IssueInvoice $issue): JsonResponse
    {
        $order = Order::visibleTo($request->user())->where('order_number', strtoupper($number))->firstOrFail();
        $this->authorize('create', [Invoice::class, $order]);
        $invoice = $issue->handle($request->user(), $order, $request->dueDate());

        return $this->respond($invoice)->response()->setStatusCode(201);
    }

    public function markPaid(MarkInvoicePaidRequest $request, string $invoice, InvoiceWorkflow $workflow): InvoiceDetailResource
    {
        $invoice = $this->find($request, $invoice);
        $this->authorize('markPaid', $invoice);

        return $this->respond($workflow->markPaid($invoice, $request->user(), $request->paidOn()));
    }

    public function void(VoidInvoiceRequest $request, string $invoice, InvoiceWorkflow $workflow): InvoiceDetailResource
    {
        $invoice = $this->find($request, $invoice);
        $this->authorize('void', $invoice);

        return $this->respond($workflow->void($invoice, $request->user(), $request->reason()));
    }

    /**
     * The only way this controller loads an invoice: through the company boundary (foreign, unknown = 404).
     *
     * @param  list<string>  $with
     */
    private function find(Request $request, string $publicId, array $with = []): Invoice
    {
        return Invoice::visibleTo($request->user())->with($with)->where('public_id', $publicId)->firstOrFail();
    }

    /** Everything InvoiceDetailResource reads, loaded in a constant number of queries. */
    private function respond(Invoice $invoice): InvoiceDetailResource
    {
        return new InvoiceDetailResource($invoice->load(['items', 'history.actor.roles']));
    }
}
