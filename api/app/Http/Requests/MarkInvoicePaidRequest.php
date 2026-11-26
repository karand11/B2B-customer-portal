<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ReadsBodyOnly;
use App\Http\Requests\Concerns\RejectsMalformedJson;
use App\Support\InputRules;
use Illuminate\Foundation\Http\FormRequest;

/** Body of POST /invoices/{invoice}/mark-paid: only the optional paid_on is read. Its lower bound (issue date) is checked under the row lock. */
class MarkInvoicePaidRequest extends FormRequest
{
    use ReadsBodyOnly, RejectsMalformedJson;

    public function authorize(): bool
    {
        return true; // role:staff|admin middleware; InvoicePolicy::markPaid in the controller
    }

    public function rules(): array
    {
        return ['paid_on' => ['bail', 'nullable', InputRules::date(null, now()->utc()->toDateString())]];
    }

    public function paidOn(): ?string
    {
        $value = $this->bodyInput('paid_on');

        return is_string($value) ? $value : null;
    }
}
