<?php

namespace App\Http\Requests\Admin;

use App\Models\Company;
use App\Support\InputRules;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

/** `role` and `company_id` with the invariant of User::assign: a customer needs an existing company, staff and admin must not have one. */
abstract class RoleCompanyRequest extends AdminWriteRequest
{
    /** @return array<string, array<int, mixed>> */
    protected function roleCompanyRules(string $companyPresence): array
    {
        return [
            'role' => ['bail', 'required', 'string', Rule::in(['customer', 'staff', 'admin'])],
            'company_id' => ['bail', $companyPresence, 'nullable', InputRules::int(1, PHP_INT_MAX)],
        ];
    }

    /** @return array<int, Closure> */
    public function after(): array
    {
        return [function (Validator $validator) {
            $errors = $validator->errors();
            $v = $this->request->all();

            if ($errors->hasAny(['role', 'company_id'])) {
                return;
            }

            $company = $v['company_id'] ?? null;

            if ($v['role'] === 'customer') {
                if ($company === null || ! Company::whereKey($company)->exists()) {
                    $errors->add('company_id', $company === null ? 'A customer needs a company.' : 'The selected company does not exist.');
                }
            } elseif ($company !== null) {
                $errors->add('company_id', 'Staff and administrators do not belong to a company.');
            }
        }];
    }

    public function role(): string
    {
        return $this->validated('role');
    }

    public function companyId(): ?int
    {
        $id = $this->validated('company_id');

        return $this->role() === 'customer' && $id !== null ? (int) $id : null;
    }
}
