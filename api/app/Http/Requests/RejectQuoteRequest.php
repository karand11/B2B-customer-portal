<?php

namespace App\Http\Requests;

use App\Support\InputRules;

/** Staff reject: the reason is required and shown to the customer. */
class RejectQuoteRequest extends DeclineQuoteRequest
{
    public function rules(): array
    {
        return ['reason' => ['bail', 'required', 'string', 'min:1', 'max:500', InputRules::text()]];
    }
}
