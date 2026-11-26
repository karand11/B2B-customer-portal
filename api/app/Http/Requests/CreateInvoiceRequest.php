<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ReadsBodyOnly;
use App\Http\Requests\Concerns\RejectsMalformedJson;
use App\Support\InputRules;
use Illuminate\Foundation\Http\FormRequest;

/** Body of POST /orders/{number}/invoice: only the optional due_date override is read; every other field is ignored. */
class CreateInvoiceRequest extends FormRequest
{
    use ReadsBodyOnly, RejectsMalformedJson;

    public function authorize(): bool
    {
        return true; // role:staff|admin middleware; InvoicePolicy::create in the controller
    }

    public function rules(): array
    {
        $today = now()->utc();

        return ['due_date' => ['bail', 'nullable', InputRules::date($today->toDateString(), $today->copy()->addDays(config('invoicing.max_due_days'))->toDateString())]];
    }

    public function dueDate(): ?string
    {
        $value = $this->bodyInput('due_date');

        return is_string($value) ? $value : null;
    }
}
