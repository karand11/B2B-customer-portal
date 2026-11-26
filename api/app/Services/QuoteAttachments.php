<?php

namespace App\Services;

use App\Models\Company;
use App\Models\QuoteAttachment;
use App\Models\QuoteRequest;
use App\Models\User;
use App\Support\AttachmentInspector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Upload and delete run under the quote row lock (state, count and total-size caps cannot be raced). */
class QuoteAttachments
{
    public function __construct(private QuoteWorkflow $workflow, private AttachmentInspector $inspector) {}

    public function store(QuoteRequest $quote, User $user, mixed $file): QuoteAttachment
    {
        $disk = Storage::disk(config('quotes.attachments.disk'));
        $path = null;
        $attachment = null;

        try {
            $this->workflow->run($quote, 'attach', $user, function (QuoteRequest $locked) use ($user, $file, $disk, &$path, &$attachment) {
                // The state check (above) comes before the file rules: a closed quote is a 422 status even with a bad file.
                $info = $this->inspector->inspect($file);

                $existing = $locked->attachments()->get(['id', 'size_bytes']);
                $existing->count() < config('quotes.attachments.max_files')
                    || throw ValidationException::withMessages(['file' => 'A quote can have at most '.config('quotes.attachments.max_files').' files.']);
                $existing->sum('size_bytes') + $info['size'] <= config('quotes.attachments.max_total_bytes')
                    || throw ValidationException::withMessages(['file' => 'The files of a quote must not exceed '.(config('quotes.attachments.max_total_bytes') / 1024 / 1024).' MB in total.']);

                // Company-wide quota: the company row lock (taken after the quote lock, always in this order) serialises
                // every upload of the company, so parallel uploads to different quotes cannot overshoot the cap.
                Company::whereKey($locked->company_id)->lockForUpdate()->first();
                $used = (int) DB::table('quote_attachments')->join('quote_requests', 'quote_requests.id', '=', 'quote_attachments.quote_request_id')
                    ->where('quote_requests.company_id', $locked->company_id)->sum('quote_attachments.size_bytes');
                $used + $info['size'] <= config('quotes.attachments.max_company_bytes')
                    || throw ValidationException::withMessages(['file' => 'Your company has reached its attachment storage limit ('.(config('quotes.attachments.max_company_bytes') / 1024 / 1024).' MB in total). Delete files from open quotes or contact us.']);

                // The stored name is random hex: nothing the user typed ever reaches the file system.
                $path = $disk->putFileAs(config('quotes.attachments.directory'), $file, bin2hex(random_bytes(16)));

                $attachment = (new QuoteAttachment)->forceFill([
                    'public_id' => (string) Str::uuid(),
                    'quote_request_id' => $locked->id,
                    'uploaded_by_user_id' => $user->id,
                    'uploaded_by_role' => $user->isStaff() ? 'staff' : 'customer',
                    'original_name' => $info['name'],
                    'storage_path' => $path,
                    'mime' => $info['mime'],
                    'size_bytes' => $info['size'],
                    'sha256' => $info['sha256'],
                ]);
                $attachment->save();
            });
        } catch (Throwable $e) {
            $path === null || $disk->delete($path);

            throw $e;
        }

        return $attachment;
    }

    /** Hard delete: the row inside the transaction, the file after commit (a failed removal is logged, never a 500). */
    public function delete(QuoteRequest $quote, User $user, QuoteAttachment $attachment): void
    {
        $this->workflow->run($quote, 'attach', $user, fn (QuoteRequest $locked) => QuoteAttachment::where('quote_request_id', $locked->id)
            ->whereKey($attachment->id)->delete() === 1 || abort(404));

        try {
            Storage::disk(config('quotes.attachments.disk'))->delete($attachment->storage_path);
        } catch (Throwable $e) {
            Log::error('Could not remove a quote attachment file.', ['attachment' => $attachment->public_id, 'error' => $e->getMessage()]);
        }
    }
}
