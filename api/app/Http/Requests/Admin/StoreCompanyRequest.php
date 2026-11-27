<?php

namespace App\Http\Requests\Admin;

/** POST /admin/companies (admin only). */
class StoreCompanyRequest extends CompanyWriteRequest
{
    public function rules(): array
    {
        return $this->nameRules() + $this->billingRules('sometimes');
    }

    public function name(): string
    {
        return $this->validated('name');
    }
}
