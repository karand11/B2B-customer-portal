<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Contracts\Validation\Validator;

/**
 * The result window of every list endpoint: `page * per_page` may not exceed config('portal.max_window') (10,000). Deep OFFSETs are
 * expensive for the database and nobody pages through 10,000 rows by hand: the error asks the caller to narrow the search instead.
 * Runs after the individual `page` and `per_page` rules, so a malformed value keeps its own error.
 */
trait ChecksPageWindow
{
    protected function checkPageWindow(Validator $validator, int $defaultPerPage): void
    {
        if ($validator->errors()->hasAny(['page', 'per_page'])) {
            return;
        }

        $page = (int) ($this->input('page') ?? 1);
        $perPage = (int) ($this->input('per_page') ?? $defaultPerPage);
        $max = (int) config('portal.max_window');

        if ($page * $perPage > $max) {
            $message = 'Only the first '.number_format($max).' results can be paged through. Narrow the search with filters.';
            $validator->errors()->add('page', $message);
        }
    }
}
