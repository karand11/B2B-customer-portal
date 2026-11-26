<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RejectsMalformedJson;
use App\Support\InputRules;
use Illuminate\Foundation\Http\FormRequest;

/** Optional reason for a customer decline or withdraw (history note, visible to staff). */
class DeclineQuoteRequest extends FormRequest
{
    use RejectsMalformedJson;

    public function authorize(): bool
    {
        return true; // role:customer middleware; QuotePolicy::decline in the controller
    }

    public function rules(): array
    {
        return ['reason' => ['bail', 'nullable', 'string', 'max:500', InputRules::text()]];
    }

    public function reason(): ?string
    {
        $reason = $this->input('reason');

        return is_string($reason) && $reason !== '' ? $reason : null;
    }
}
