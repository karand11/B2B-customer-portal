<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RejectsMalformedJson;
use App\Http\Requests\Concerns\ValidatesPoReference;
use App\Support\InputRules;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Body of POST /quotes: po_reference?, customer_note?, lines[] (each exactly one of product_id or description, plus quantity).
 * Every other key (prices, status, company...) is simply never read. Product existence is checked by CreateQuote under the transaction.
 */
class CreateQuoteRequest extends FormRequest
{
    use RejectsMalformedJson, ValidatesPoReference;

    public function authorize(): bool
    {
        return true; // role:customer middleware; QuotePolicy::create in the controller
    }

    public function rules(): array
    {
        $rules = $this->poReferenceRules() + [
            'customer_note' => ['bail', 'nullable', 'string', 'max:'.config('quotes.max_note_length'), InputRules::text()],
            'lines' => ['bail', 'required', 'array', 'list', 'min:1', 'max:'.config('quotes.max_lines')],
        ];

        foreach ($this->arrayLines() as $i => $line) {
            $rules["lines.{$i}.quantity"] = ['bail', 'required', InputRules::int(1, config('orders.max_quantity'))];

            if (isset($line['product_id']) && ! isset($line['description'])) {
                $rules["lines.{$i}.product_id"] = ['bail', InputRules::int(1, PHP_INT_MAX)];
            }
            if (isset($line['description']) && ! isset($line['product_id'])) {
                $rules["lines.{$i}.description"] = ['bail', 'string', 'max:255', InputRules::line()];
            }
        }

        return $rules;
    }

    /** @return array<int, Closure> */
    public function after(): array
    {
        return [function (Validator $validator) {
            $this->checkPoReference($validator);

            $seen = [];
            $lines = $this->input('lines');
            foreach (is_array($lines) ? $lines : [] as $i => $line) {
                if (! is_array($line)) {
                    $validator->errors()->add('lines', 'Each line must be an object with a quantity and a product_id or a description.');

                    continue;
                }

                $product = isset($line['product_id']);
                $description = isset($line['description']);

                if ($product && $description) {
                    $validator->errors()->add("lines.{$i}.description", 'Give either a product_id or a description, not both.');
                } elseif (! $product && ! $description) {
                    $validator->errors()->add(array_key_exists('description', $line) ? "lines.{$i}.description" : "lines.{$i}.product_id", 'Each line needs a product_id or a description.');
                } elseif ($product && ! $validator->errors()->has("lines.{$i}.product_id")) {
                    isset($seen[$line['product_id']])
                        ? $validator->errors()->add("lines.{$i}.product_id", 'This product is already in the request.')
                        : $seen[$line['product_id']] = true;
                }
            }
        }];
    }

    /** @return list<array{product_id?: int, description?: string, quantity: int}> clean lines (call after validation) */
    public function lines(): array
    {
        return array_map(fn (array $line) => (isset($line['product_id']) ? ['product_id' => $line['product_id']] : ['description' => $line['description']])
            + ['quantity' => $line['quantity']], array_values($this->input('lines')));
    }

    public function customerNote(): ?string
    {
        $note = $this->input('customer_note');

        return is_string($note) && $note !== '' ? $note : null;
    }

    /** @return array<int, array<string, mixed>> */
    private function arrayLines(): array
    {
        $lines = $this->input('lines');

        return is_array($lines) ? array_filter($lines, 'is_array') : [];
    }
}
