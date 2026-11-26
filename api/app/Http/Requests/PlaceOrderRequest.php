<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesPoReference;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class PlaceOrderRequest extends FormRequest
{
    use ValidatesPoReference;

    public function authorize(): bool
    {
        return true; // role:customer middleware; OrderPolicy::create in the controller
    }

    /** Only po_reference is read (rules in App\Support\PoReference); every other body field is ignored. */
    public function rules(): array
    {
        return $this->poReferenceRules();
    }

    /** @return array<int, Closure> */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->checkPoReference($validator)];
    }
}
