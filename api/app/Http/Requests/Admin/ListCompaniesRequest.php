<?php

namespace App\Http\Requests\Admin;

/** GET /admin/companies. */
class ListCompaniesRequest extends AdminListRequest
{
    protected function sorts(): array
    {
        return ['name', 'created_at', '-name', '-created_at'];
    }

    public function rules(): array
    {
        return ['q' => $this->qRules()] + $this->pagingRules();
    }

    /** @return array<string, mixed> */
    public function criteria(): array
    {
        return ['q' => $this->q(), 'sort' => $this->sort(), 'page' => $this->page(), 'per_page' => $this->perPage()];
    }
}
