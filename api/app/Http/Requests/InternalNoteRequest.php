<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RejectsMalformedJson;
use App\Support\InputRules;
use Illuminate\Foundation\Http\FormRequest;

/** Body of PUT /quotes/{number}/internal-note: the key is required, null clears the note. */
class InternalNoteRequest extends FormRequest
{
    use RejectsMalformedJson;

    public function authorize(): bool
    {
        return true; // role:staff|admin middleware; QuotePolicy::note in the controller
    }

    public function rules(): array
    {
        return ['internal_note' => ['bail', 'present', 'nullable', 'string', 'max:'.config('quotes.max_note_length'), InputRules::text()]];
    }

    public function note(): ?string
    {
        $note = $this->input('internal_note');

        return is_string($note) && $note !== '' ? $note : null;
    }
}
