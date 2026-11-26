<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RejectsMalformedJson;
use App\Models\Product;
use App\Support\InputRules;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/** Body of PUT /quotes/{number}/pricing: { lines?: [{position, unit_price_cents|null}], valid_until?: date|null }. */
class PricingRequest extends FormRequest
{
    use RejectsMalformedJson;

    public function authorize(): bool
    {
        return true; // role:staff|admin middleware; QuotePolicy::price in the controller
    }

    public function rules(): array
    {
        $rules = [
            'lines' => ['bail', 'sometimes', 'nullable', 'array', 'list', 'max:'.config('quotes.max_lines')],
            'valid_until' => ['bail', 'sometimes', 'nullable', 'string', $this->validUntilRule()],
        ];

        foreach ($this->arrayLines() as $i => $line) {
            $rules["lines.{$i}.position"] = ['bail', 'required', InputRules::int(1, config('quotes.max_lines'))];
            $rules["lines.{$i}.unit_price_cents"] = ['bail', 'present', 'nullable', InputRules::int(0, Product::MAX_PRICE_CENTS)];
        }

        return $rules;
    }

    /** @return array<int, Closure> */
    public function after(): array
    {
        return [function (Validator $validator) {
            $seen = [];
            $lines = $this->input('lines');
            foreach (is_array($lines) ? $lines : [] as $i => $line) {
                if (! is_array($line)) {
                    $validator->errors()->add('lines', 'Each line must be an object with a position and a unit_price_cents.');
                } elseif (is_int($line['position'] ?? null) && ! $validator->errors()->has("lines.{$i}.position")) {
                    isset($seen[$line['position']])
                        ? $validator->errors()->add("lines.{$i}.position", 'This position appears twice.')
                        : $seen[$line['position']] = true;
                }
            }
        }];
    }

    /** @return list<array{position: int, unit_price_cents: int|null}> */
    public function priceLines(): array
    {
        $lines = $this->input('lines');

        return is_array($lines)
            ? array_map(fn (array $l) => ['position' => $l['position'], 'unit_price_cents' => $l['unit_price_cents']], array_values($lines))
            : [];
    }

    public function hasValidUntil(): bool
    {
        return $this->exists('valid_until');
    }

    public function validUntil(): ?string
    {
        return $this->input('valid_until') ?: null;
    }

    /** A real YYYY-MM-DD date after today (UTC) and at most max_validity_days ahead. */
    public function validUntilRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                $fail("The {$attribute} must be a valid date (YYYY-MM-DD).");

                return;
            }

            $today = now()->utc();
            if ($value <= $today->toDateString() || $value > $today->copy()->addDays(config('quotes.max_validity_days'))->toDateString()) {
                $fail("The {$attribute} must be after today and at most ".config('quotes.max_validity_days').' days ahead (UTC).');
            }
        };
    }

    /** @return array<int, array<string, mixed>> */
    private function arrayLines(): array
    {
        $lines = $this->input('lines');

        return is_array($lines) ? array_filter($lines, 'is_array') : [];
    }
}
