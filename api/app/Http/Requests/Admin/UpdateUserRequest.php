<?php

namespace App\Http\Requests\Admin;

use App\Support\InputRules;

/** PATCH /admin/users/{user}: the name only. The email is immutable and every other key is ignored. */
class UpdateUserRequest extends AdminWriteRequest
{
    public function rules(): array
    {
        return ['name' => ['bail', 'required', 'string', 'min:1', 'max:255', InputRules::line()]];
    }

    public function name(): string
    {
        return $this->validated('name');
    }
}
