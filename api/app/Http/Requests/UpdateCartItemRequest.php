<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCartItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // role:customer middleware on the route
    }

    public function rules(): array
    {
        return [
            'quantity' => ['bail', 'required', 'integer:strict', 'min:1', 'max:'.config('orders.max_quantity')],
        ];
    }
}
