<?php

namespace Database\Factories;

use App\Models\QuoteAttachment;
use App\Models\QuoteRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A row plus a tiny real file on the `quotes` disk (call Storage::fake('quotes') first in tests). Customer-uploaded by default.
 *
 * @extends Factory<QuoteAttachment>
 */
class QuoteAttachmentFactory extends Factory
{
    public const CONTENT = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";

    public function definition(): array
    {
        $path = 'quote-attachments/'.bin2hex(random_bytes(16));

        return [
            'public_id' => (string) Str::uuid(),
            'quote_request_id' => QuoteRequest::factory(),
            'uploaded_by_user_id' => null,
            'uploaded_by_role' => 'customer',
            'original_name' => 'drawing.pdf',
            'storage_path' => $path,
            'mime' => 'application/pdf',
            'size_bytes' => strlen(self::CONTENT),
            'sha256' => hash('sha256', self::CONTENT),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (QuoteAttachment $attachment) {
            $attachment->uploaded_by_user_id ??= QuoteRequest::find($attachment->quote_request_id)->created_by_user_id;
            Storage::disk('quotes')->put($attachment->storage_path, self::CONTENT);
        });
    }

    /** Uploaded by this staff or admin user. */
    public function byStaff(User $staff): static
    {
        return $this->state(['uploaded_by_user_id' => $staff->id, 'uploaded_by_role' => 'staff']);
    }

    public function named(string $name): static
    {
        return $this->state(['original_name' => $name]);
    }
}
