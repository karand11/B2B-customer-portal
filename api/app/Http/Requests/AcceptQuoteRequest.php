<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RejectsMalformedJson;
use App\Http\Requests\Concerns\ValidatesPoReference;
use App\Support\InputRules;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/** Body of POST /quotes/{number}/accept: the revision the client saw, and an optional PO reference. Nothing else is read. */
class AcceptQuoteRequest extends FormRequest
{
    use RejectsMalformedJson, ValidatesPoReference;

    public function authorize(): bool
    {
        return true; // role:customer middleware; QuotePolicy::accept in the controller
    }

    public function rules(): array
    {
        return $this->poReferenceRules() + ['revision' => ['bail', 'required', InputRules::int(1, 2147483647)]];
    }

    /** @return array<int, Closure> */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->checkPoReference($validator)];
    }

    public function revision(): int
    {
        return $this->integer('revision');
    }
}
