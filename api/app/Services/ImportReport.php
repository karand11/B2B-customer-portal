<?php

namespace App\Services;

/** Result of one ProductImporter run. In a dry run `created` and `updated` are what WOULD happen. */
final readonly class ImportReport
{
    /**
     * @param  list<array{line: int, messages: list<string>}>  $errors  at most ProductImporter::MAX_LISTED bad rows, in file order
     * @param  list<string>  $warnings  e.g. `unknown column "foo" ignored`
     * @param  list<array{string, string, array<string, mixed>, array<string, mixed>}>  $changes  only when the run was asked to collect them: [product id, part number, audited values before, after] of every UPDATED product with a real difference
     */
    public function __construct(
        public int $read,
        public int $created,
        public int $updated,
        public int $bad,
        public array $errors,
        public array $warnings,
        public array $changes = [],
    ) {}
}
