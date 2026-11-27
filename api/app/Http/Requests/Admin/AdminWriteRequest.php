<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Concerns\ReadsBodyOnly;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Base of every /admin write: the body is the only input (a query string never fills a field) and only fields named in rules()
 * are ever read, through validated(). Authorization happens in the controller (policies), not here.
 */
abstract class AdminWriteRequest extends FormRequest
{
    use ReadsBodyOnly;

    public function authorize(): bool
    {
        return true;
    }
}
