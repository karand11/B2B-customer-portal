<?php

namespace App\Http\Requests\Admin;

/** PUT /admin/users/{user}/assignment: both keys are required (company_id may be null for staff and admin). */
class AssignUserRequest extends RoleCompanyRequest
{
    public function rules(): array
    {
        return $this->roleCompanyRules('present');
    }
}
