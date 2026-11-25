<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class PlaceOrderRequest extends FormRequest
{
    private const PATTERN = '/^[A-Za-z0-9][A-Za-z0-9 ._\/#-]*$/D';

    /** The whitespace trimmed at both ends: exactly what JavaScript's String.trim() strips, like the SPA. Control characters (NUL, DEL, U+0085) and U+200B are NOT whitespace here, so they stay and fail the pattern. */
    private const TRIM = '/^[\t\n\x0B\f\r\p{Zs}\x{2028}\x{2029}\x{FEFF}]+|[\t\n\x0B\f\r\p{Zs}\x{2028}\x{2029}\x{FEFF}]+$/u';

    public function authorize(): bool
    {
        return true; // role:customer middleware; OrderPolicy::create in the controller
    }

    /**
     * Contract order: missing/null -> NULL; string; clean UTF-8 without NUL on the RAW value; trim, empty -> NULL; max 64; pattern.
     * The framework's TrimStrings skips this field (bootstrap/app.php) so a trailing NUL is still visible here.
     * The raw checks run in after() because Laravel skips custom rules for a value that PHP's trim() reduces to ''
     * (a NUL-only value would otherwise be accepted as empty). Every other body field is ignored.
     */
    public function rules(): array
    {
        return ['po_reference' => ['bail', 'nullable', 'string']];
    }

    /** @return array<int, Closure> */
    public function after(): array
    {
        return [function (Validator $validator) {
            $value = $this->input('po_reference');

            if (! is_string($value) || $validator->errors()->has('po_reference')) {
                return;
            }

            $trimmed = self::trimmed($value);
            $error = match (true) {
                str_contains($value, "\0") || ! mb_check_encoding($value, 'UTF-8') => 'The PO reference contains invalid characters.',
                $trimmed === null => null,
                mb_strlen($trimmed) > 64 => 'The PO reference must not be longer than 64 characters.',
                preg_match(self::PATTERN, $trimmed) !== 1 => 'The PO reference must start with a letter or digit and contain only letters, digits, spaces and . - _ / #.',
                default => null,
            };

            $error === null || $validator->errors()->add('po_reference', $error);
        }];
    }

    /** The trimmed value to store, or null when none (call after validation). */
    public function poReference(): ?string
    {
        $value = $this->input('po_reference');

        return is_string($value) ? self::trimmed($value) : null;
    }

    /** Trims leading and trailing whitespace; '' becomes null. Invalid UTF-8 never gets here (rejected first). */
    private static function trimmed(string $value): ?string
    {
        $trimmed = mb_check_encoding($value, 'UTF-8') ? preg_replace(self::TRIM, '', $value) : $value;

        return $trimmed === '' ? null : $trimmed;
    }
}
