<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RejectsMalformedJson;
use App\Support\InputRules;
use Illuminate\Foundation\Http\FormRequest;

/** Body of POST /quotes/{number}/send: an optional customer-visible staff_note (absent keeps the existing one, null clears it). */
class SendQuoteRequest extends FormRequest
{
    use RejectsMalformedJson;

    public function authorize(): bool
    {
        return true; // role:staff|admin middleware; QuotePolicy::send in the controller
    }

    public function rules(): array
    {
        return ['staff_note' => ['bail', 'nullable', 'string', 'max:'.config('quotes.max_note_length'), InputRules::text()]];
    }

    public function hasStaffNote(): bool
    {
        return $this->exists('staff_note');
    }

    public function staffNote(): ?string
    {
        $note = $this->input('staff_note');

        return is_string($note) && $note !== '' ? $note : null;
    }
}
