<?php

namespace App\Http\Requests;

use App\Enums\OrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrderStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // role:staff|admin middleware on the route; OrderPolicy::transition in the controller
    }

    public function rules(): array
    {
        // pending is never a target; the matrix itself is enforced in OrderStatusChanger.
        return ['status' => ['bail', 'required', 'string', Rule::in(array_diff(OrderStatus::values(), ['pending']))]];
    }
}
