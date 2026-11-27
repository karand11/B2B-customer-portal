<?php

namespace App\Http\Requests\Admin;

use App\Services\AuditLog;
use App\Support\InputRules;
use Closure;
use Illuminate\Contracts\Validation\Validator;

/** GET /admin/audit-log. */
class ListAuditLogRequest extends AdminListRequest
{
    protected function sorts(): array
    {
        return ['-occurred_at', 'occurred_at'];
    }

    public function rules(): array
    {
        $date = ['bail', 'nullable', 'string', InputRules::date()];

        return [
            'actor_id' => ['bail', 'nullable', 'integer', 'min:1', 'max:9223372036854775807'],
            'action' => ['bail', 'nullable', 'string', $this->inList(AuditLog::ACTIONS, 10)],
            'subject_type' => ['bail', 'nullable', 'string', 'in:order,quote,invoice,product,company,user,import'],
            'subject_id' => ['bail', 'nullable', 'string', 'min:1', 'max:64', fn (string $attribute, mixed $value, Closure $fail) => self::clean($value)
                ? null : $fail("The {$attribute} contains invalid characters.")],
            'company_id' => ['bail', 'nullable', 'integer', 'min:1', 'max:9223372036854775807'],
            'from' => $date,
            'to' => $date,
        ] + $this->pagingRules();
    }

    /** @return array<int, Closure> */
    public function after(): array
    {
        return [function (Validator $validator) {
            [$from, $to] = [$this->query('from'), $this->query('to')];

            if (is_string($from) && is_string($to) && ! $validator->errors()->hasAny(['from', 'to']) && $from > $to) {
                $validator->errors()->add('from', 'The from date must not be after the to date.');
            }
        }];
    }

    /** @return array<string, mixed> */
    public function criteria(): array
    {
        $v = $this->validated();

        return [
            'actor_id' => isset($v['actor_id']) ? (int) $v['actor_id'] : null,
            'action' => $this->csv('action'),
            'subject_type' => $v['subject_type'] ?? null,
            'subject_id' => $v['subject_id'] ?? null,
            'company_id' => isset($v['company_id']) ? (int) $v['company_id'] : null,
            'from' => $v['from'] ?? null,
            'to' => $v['to'] ?? null,
            'sort' => $this->sort(), 'page' => $this->page(), 'per_page' => $this->perPage(),
        ];
    }
}
