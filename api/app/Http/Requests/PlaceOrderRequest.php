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

    /** Only po_reference and expected_subtotal_cents are read (PO rules in App\Support\PoReference); every other body field is ignored. */
    public function rules(): array
    {
        return $this->poReferenceRules() + [
            // The subtotal of the cart the customer is looking at: a strict JSON integer (no string, no bool, no float). Absent or null = no check.
            'expected_subtotal_cents' => ['bail', 'nullable', 'integer:strict', 'min:0', 'max:99999999999999'],
        ];
    }

    public function expectedSubtotalCents(): ?int
    {
        $value = $this->validated('expected_subtotal_cents');

        return $value === null ? null : (int) $value;
    }

    /** @return array<int, Closure> */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->checkPoReference($validator)];
    }
}
