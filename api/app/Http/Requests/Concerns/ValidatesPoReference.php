<?php

namespace App\Http\Requests\Concerns;

use App\Support\PoReference;
use Illuminate\Contracts\Validation\Validator;

/**
 * Optional po_reference for a Form Request (checkout, quote create, quote accept). Add poReferenceRules() to rules()
 * and call checkPoReference() from after(). The raw checks run in after() because Laravel skips custom rules for a
 * value that PHP's trim() reduces to '' (a NUL-only value would otherwise be accepted as empty).
 */
trait ValidatesPoReference
{
    /** @return array<string, list<string>> */
    protected function poReferenceRules(): array
    {
        return ['po_reference' => ['bail', 'nullable', 'string']];
    }

    protected function checkPoReference(Validator $validator): void
    {
        $value = $this->input('po_reference');

        if (! is_string($value) || $validator->errors()->has('po_reference')) {
            return;
        }

        $error = PoReference::error($value);
        $error === null || $validator->errors()->add('po_reference', $error);
    }

    /** The trimmed value to store, or null when none (call after validation). */
    public function poReference(): ?string
    {
        $value = $this->input('po_reference');

        return is_string($value) ? PoReference::trimmed($value) : null;
    }
}
