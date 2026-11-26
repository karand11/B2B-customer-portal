<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\FindsQuote;
use App\Http\Controllers\Controller;
use App\Http\Requests\AcceptQuoteRequest;
use App\Http\Requests\CreateQuoteRequest;
use App\Http\Requests\DeclineQuoteRequest;
use App\Http\Requests\InternalNoteRequest;
use App\Http\Requests\ListQuotesRequest;
use App\Http\Requests\PricingRequest;
use App\Http\Requests\RejectQuoteRequest;
use App\Http\Requests\SendQuoteRequest;
use App\Http\Resources\QuoteDetailResource;
use App\Http\Resources\QuoteListResource;
use App\Models\QuoteRequest;
use App\Services\AcceptQuote;
use App\Services\CreateQuote;
use App\Services\QuotePricing;
use App\Services\QuoteWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Every quote lookup goes through QuoteRequest::visibleTo() and then the policy (two layers; foreign = 404). */
class QuoteController extends Controller
{
    use FindsQuote;

    public function index(ListQuotesRequest $request): QuoteListResource
    {
        $c = $request->criteria();
        $quotes = QuoteRequest::visibleTo($request->user())
            ->filter($c)
            ->with(['company:id,name', 'createdBy:id,name'])
            ->withCount('items as line_count')
            ->withMax('history as last_history_at', 'created_at')
            ->paginate($c['per_page'], ['*'], 'page', $c['page']);

        return new QuoteListResource($quotes);
    }

    public function show(Request $request, string $number): QuoteDetailResource
    {
        $quote = $this->findQuote($request, $number);
        $this->authorize('view', $quote);

        return $this->respond($quote);
    }

    public function store(CreateQuoteRequest $request, CreateQuote $create): JsonResponse
    {
        $this->authorize('create', QuoteRequest::class);
        $quote = $create->handle($request->user(), $request->lines(), $request->poReference(), $request->customerNote());

        return $this->respond($quote)->response()->setStatusCode(201);
    }

    public function review(Request $request, string $number, QuoteWorkflow $workflow): QuoteDetailResource
    {
        $quote = $this->findQuote($request, $number);
        $this->authorize('review', $quote);

        return $this->respond($workflow->run($quote, 'review', $request->user()));
    }

    public function pricing(PricingRequest $request, string $number, QuotePricing $pricing): QuoteDetailResource
    {
        $quote = $this->findQuote($request, $number);
        $this->authorize('price', $quote);

        return $this->respond($pricing->save($quote, $request->user(), $request->priceLines(), $request->hasValidUntil(), $request->validUntil()));
    }

    public function send(SendQuoteRequest $request, string $number, QuotePricing $pricing): QuoteDetailResource
    {
        $quote = $this->findQuote($request, $number);
        $this->authorize('send', $quote);

        return $this->respond($pricing->send($quote, $request->user(), $request->hasStaffNote(), $request->staffNote()));
    }

    public function reject(RejectQuoteRequest $request, string $number, QuoteWorkflow $workflow): QuoteDetailResource
    {
        $quote = $this->findQuote($request, $number);
        $this->authorize('reject', $quote);

        return $this->respond($workflow->run($quote, 'reject', $request->user(), null, $request->reason()));
    }

    /** Clears sent_at: prices, valid_until, staff note and staff files are hidden from the customer again. */
    public function reopen(Request $request, string $number, QuoteWorkflow $workflow): QuoteDetailResource
    {
        $quote = $this->findQuote($request, $number);
        $this->authorize('reopen', $quote);

        return $this->respond($workflow->run($quote, 'reopen', $request->user(), fn (QuoteRequest $locked) => $locked->forceFill(['sent_at' => null])));
    }

    public function internalNote(InternalNoteRequest $request, string $number, QuoteWorkflow $workflow): QuoteDetailResource
    {
        $quote = $this->findQuote($request, $number);
        $this->authorize('note', $quote);

        return $this->respond($workflow->run($quote, 'note', $request->user(), fn (QuoteRequest $locked) => $locked->forceFill(['internal_note' => $request->note()])));
    }

    public function accept(AcceptQuoteRequest $request, string $number, AcceptQuote $accept): QuoteDetailResource
    {
        $quote = $this->findQuote($request, $number);
        $this->authorize('accept', $quote);

        return $this->respond($accept->handle($quote, $request->user(), $request->revision(), $request->poReference()));
    }

    public function decline(DeclineQuoteRequest $request, string $number, QuoteWorkflow $workflow): QuoteDetailResource
    {
        $quote = $this->findQuote($request, $number);
        $this->authorize('decline', $quote);

        return $this->respond($workflow->run($quote, 'decline', $request->user(), null, $request->reason()));
    }

    /** Everything QuoteDetailResource reads, loaded in a constant number of queries. */
    private function respond(QuoteRequest $quote): QuoteDetailResource
    {
        return new QuoteDetailResource($quote->load([
            'items', 'history.actor.roles', 'attachments.uploadedBy', 'order:id,quote_request_id,order_number',
            'company:id,name', 'createdBy:id,name',
        ]));
    }
}
