<?php

namespace App\Http\Requests\Admin;

/** POST /admin/products/imports/{import}/apply: the only field is the optional `skip_invalid`. */
class ApplyProductImportRequest extends AdminWriteRequest
{
    public function rules(): array
    {
        return ['skip_invalid' => ['bail', 'nullable', 'boolean']];
    }

    public function skipInvalid(): bool
    {
        return (bool) $this->validated('skip_invalid');
    }
}
