<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AddCartItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // role:customer middleware on the route
    }

    public function rules(): array
    {
        return [
            'product_id' => ['bail', 'required', 'integer:strict', 'min:1'],
            'quantity' => ['bail', 'required', 'integer:strict', 'min:1', 'max:'.config('orders.max_quantity')],
        ];
    }
}
