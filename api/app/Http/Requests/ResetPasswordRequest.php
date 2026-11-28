<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesEmail;
use App\Support\PasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;

class ResetPasswordRequest extends FormRequest
{
    use NormalizesEmail;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['bail', 'required', 'string', 'confirmed', PasswordPolicy::rule()],
        ];
    }
}
