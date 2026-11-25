<?php

namespace App\Http\Requests;

use App\Enums\OrderStatus;
use App\Models\Order;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListOrdersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // role middleware and the Order::visibleTo scope do the access control
    }

    public function rules(): array
    {
        $rules = [
            'status' => ['bail', 'nullable', 'string', $this->statusCsv()],
            'from' => ['bail', 'nullable', 'string', $this->dateRule()],
            'to' => ['bail', 'nullable', 'string', $this->dateRule()],
            'q' => ['bail', 'nullable', 'string', 'max:32', $this->cleanText()],
            'sort' => ['bail', 'nullable', 'string', Rule::in(array_keys(Order::SORTS))],
            'page' => ['bail', 'nullable', 'integer', 'min:1', 'max:10000'],
            'per_page' => ['bail', 'nullable', 'integer', 'min:1', 'max:100'],
        ];

        // Customers are always scoped to their own company: the parameter is ignored for them (never 422).
        if ($this->user()?->isStaff()) {
            $rules['company_id'] = ['bail', 'nullable', 'integer', 'min:1'];
        }

        return $rules;
    }

    /** @return array<int, Closure> */
    public function after(): array
    {
        return [function (Validator $validator) {
            [$from, $to] = [$this->input('from'), $this->input('to')];

            if (is_string($from) && is_string($to) && ! $validator->errors()->hasAny(['from', 'to']) && $from > $to) {
                $validator->errors()->add('from', 'The from date must not be after the to date.');
            }
        }];
    }

    /**
     * Typed filter criteria (call only after validation).
     *
     * @return array<string, mixed>
     */
    public function criteria(): array
    {
        $v = $this->validated();

        return [
            'status' => isset($v['status']) ? array_values(array_unique(array_map('trim', explode(',', $v['status'])))) : [],
            'from' => $v['from'] ?? null,
            'to' => $v['to'] ?? null,
            'q' => $v['q'] ?? null,
            'company_id' => isset($v['company_id']) ? (int) $v['company_id'] : null,
            'sort' => $v['sort'] ?? '-created_at',
            'page' => (int) ($v['page'] ?? 1),
            'per_page' => (int) ($v['per_page'] ?? 20),
        ];
    }

    private function statusCsv(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            $items = array_map('trim', explode(',', $value));

            if (! self::clean($value) || array_diff($items, OrderStatus::values()) !== []) {
                $fail("The {$attribute} must be a comma separated list of valid statuses.");
            }
        };
    }

    private function dateRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                $fail("The {$attribute} must be a valid date (YYYY-MM-DD).");
            }
        };
    }

    private function cleanText(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if (! self::clean($value)) {
                $fail("The {$attribute} contains invalid characters.");
            }
        };
    }

    private static function clean(string $value): bool
    {
        return ! str_contains($value, "\0") && mb_check_encoding($value, 'UTF-8');
    }
}
