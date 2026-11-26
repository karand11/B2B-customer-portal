<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ReadsBodyOnly;
use App\Http\Requests\Concerns\RejectsMalformedJson;
use App\Support\InputRules;
use Illuminate\Foundation\Http\FormRequest;

/** Body of POST /invoices/{invoice}/void: the reason is required and staff-only (hidden from customers). */
class VoidInvoiceRequest extends FormRequest
{
    use ReadsBodyOnly, RejectsMalformedJson;

    public function authorize(): bool
    {
        return true; // role:staff|admin middleware; InvoicePolicy::void in the controller
    }

    public function rules(): array
    {
        return ['reason' => ['bail', 'required', 'string', 'min:1', 'max:500', InputRules::text()]];
    }

    public function reason(): string
    {
        return (string) $this->bodyInput('reason');
    }
}
