<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use App\Support\InputRules;
use Closure;

/** POST /admin/users: name, email, role and (for customers) the company. The email is lowercased and must be unused, case-insensitively. */
class InviteUserRequest extends RoleCompanyRequest
{
    protected function prepareForValidation(): void
    {
        $email = $this->bodyInput('email'); // not InputBag::get(): it throws (a 400) on an array

        // The body bag is what validation reads (see ReadsBodyOnly).
        is_string($email) && $this->request->set('email', mb_strtolower(trim($email)));
    }

    public function rules(): array
    {
        return [
            'name' => ['bail', 'required', 'string', 'min:1', 'max:255', InputRules::line()],
            'email' => ['bail', 'required', 'string', 'email', 'max:255', fn (string $attribute, mixed $value, Closure $fail) => User::whereRaw('lower(email) = ?', [$value])->exists()
                ? $fail('This email is already in use.') : null],
        ] + $this->roleCompanyRules('sometimes');
    }

    /** @return array{name: string, email: string, role: string, company_id: int|null} */
    public function invitation(): array
    {
        return ['name' => $this->validated('name'), 'email' => $this->validated('email'), 'role' => $this->role(), 'company_id' => $this->companyId()];
    }
}
