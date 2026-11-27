<?php

namespace App\Http\Requests\Admin;

/** GET /admin/users. */
class ListUsersRequest extends AdminListRequest
{
    protected function sorts(): array
    {
        return ['name', 'email', 'created_at', '-name', '-email', '-created_at'];
    }

    public function rules(): array
    {
        return [
            'q' => $this->qRules(),
            'role' => ['bail', 'nullable', 'string', $this->inList(['customer', 'staff', 'admin'], 3)],
            'status' => ['bail', 'nullable', 'string', $this->inList(['pending', 'active', 'inactive'], 3)],
            'company_id' => ['bail', 'nullable', 'integer', 'min:1', 'max:9223372036854775807'],
        ] + $this->pagingRules();
    }

    /** @return array<string, mixed> */
    public function criteria(): array
    {
        $company = $this->validated('company_id');

        return [
            'q' => $this->q(), 'role' => $this->csv('role'), 'status' => $this->csv('status'),
            'company_id' => $company === null ? null : (int) $company,
            'sort' => $this->sort(), 'page' => $this->page(), 'per_page' => $this->perPage(),
        ];
    }
}
