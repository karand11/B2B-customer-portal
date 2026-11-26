<?php

namespace App\Http\Requests\Concerns;

/**
 * FormRequest::input() and validationData() merge the query string into the data. The invoice write endpoints take their
 * (optional) fields from the request BODY only, so ?due_date=, ?paid_on= and ?reason= are ignored.
 * For a JSON request `$this->request` is the decoded JSON body; otherwise it is the form body.
 */
trait ReadsBodyOnly
{
    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return $this->request->all();
    }

    protected function bodyInput(string $key): mixed
    {
        return $this->request->all()[$key] ?? null;
    }
}
