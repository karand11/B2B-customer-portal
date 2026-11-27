<?php

namespace App\Http\Requests\Admin;

/** PUT /admin/companies/{company}/billing (staff and admin): billing address, tax id and the version. The name cannot be sent here. */
class UpdateCompanyBillingRequest extends CompanyWriteRequest
{
    public function rules(): array
    {
        return $this->billingRules('present') + $this->versionRules();
    }
}
