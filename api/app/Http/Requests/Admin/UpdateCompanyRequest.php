<?php

namespace App\Http\Requests\Admin;

/** PUT /admin/companies/{company} (admin only): every key is required, null is allowed for the two optional ones. */
class UpdateCompanyRequest extends CompanyWriteRequest
{
    public function rules(): array
    {
        return $this->nameRules() + $this->billingRules('present') + $this->versionRules();
    }

    public function name(): string
    {
        return $this->validated('name');
    }
}
