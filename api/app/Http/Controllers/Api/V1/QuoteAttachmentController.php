<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\FindsQuote;
use App\Http\Controllers\Controller;
use App\Http\Resources\QuoteAttachmentResource;
use App\Services\QuoteAttachments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Attachments are always addressed through their own visible quote, never by their own id alone. */
class QuoteAttachmentController extends Controller
{
    use FindsQuote;

    public function store(Request $request, string $number, QuoteAttachments $attachments): JsonResponse
    {
        // Lookup and state come before the file rules: a foreign quote is 404 and a closed quote 422, whatever the file is.
        $quote = $this->findQuote($request, $number);
        $this->authorize('attach', $quote);
        $attachment = $attachments->store($quote, $request->user(), $request->file('file'));

        return QuoteAttachmentResource::make($attachment->load('uploadedBy'))->response()->setStatusCode(201);
    }

    public function show(Request $request, string $number, string $attachment): StreamedResponse
    {
        $quote = $this->findQuote($request, $number);
        $file = $quote->attachments()->where('public_id', $attachment)->firstOrFail();
        $this->authorize('viewAttachment', [$quote, $file]);

        $disk = Storage::disk(config('quotes.attachments.disk'));
        if (! $disk->exists($file->storage_path)) {
            Log::error('A quote attachment row has no file on disk.', ['attachment' => $file->public_id]);
            abort(404);
        }

        // download() forces Content-Disposition: attachment; the canonical MIME comes from our row, never from the upload.
        return $disk->download($file->storage_path, $file->original_name, [
            'Content-Type' => $file->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            // Belt and braces: even if a browser rendered the file, it would get no script, no network and a unique origin.
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    public function destroy(Request $request, string $number, string $attachment, QuoteAttachments $attachments): Response
    {
        $quote = $this->findQuote($request, $number);
        $file = $quote->attachments()->where('public_id', $attachment)->firstOrFail();
        $this->authorize('deleteAttachment', [$quote, $file]);
        $attachments->delete($quote, $request->user(), $file);

        return response()->noContent();
    }
}
