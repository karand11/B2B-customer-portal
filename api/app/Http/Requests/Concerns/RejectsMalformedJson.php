<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\ValidationException;

/**
 * Laravel silently turns a malformed JSON body into an empty one, so an endpoint whose fields are all optional would
 * answer 200 to garbage. A non-empty JSON body that is not a JSON object (or array) is a 422 on `body` for every quote endpoint with a body.
 */
trait RejectsMalformedJson
{
    protected function prepareForValidation(): void
    {
        $content = $this->getContent();

        if ($this->isJson() && is_string($content) && trim($content) !== '' && ! is_array(json_decode($content, true))) {
            throw ValidationException::withMessages(['body' => 'The request body must be valid JSON.']);
        }
    }
}
