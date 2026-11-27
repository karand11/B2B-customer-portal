<?php

namespace App\Http\Requests\Admin;

use App\Support\BillingAddress;
use App\Support\InputRules;

/** Fields of POST/PUT /admin/companies and PUT /admin/companies/{company}/billing. Read only through validated(). */
abstract class CompanyWriteRequest extends AdminWriteRequest
{
    /** @return array<string, array<int, mixed>> */
    protected function billingRules(string $presence): array
    {
        return [
            'billing_address' => ['bail', $presence, 'nullable', 'string', BillingAddress::rule()],
            'tax_id' => ['bail', $presence, 'nullable', 'string', 'max:32', 'regex:/^[A-Za-z0-9][A-Za-z0-9 .\/-]*$/D'],
        ];
    }

    /** @return array<string, array<int, mixed>> */
    protected function nameRules(): array
    {
        return ['name' => ['bail', 'required', 'string', 'min:1', 'max:255', InputRules::line()]];
    }

    /** @return array<string, array<int, mixed>> */
    protected function versionRules(): array
    {
        return ['version' => ['bail', 'required', InputRules::int(1, PHP_INT_MAX)]];
    }

    /** @return array{billing_address: string|null, tax_id: string|null} the normalised billing columns */
    public function billing(): array
    {
        $address = $this->validated('billing_address');

        return [
            'billing_address' => is_string($address) ? BillingAddress::normalise($address)[0] : null,
            'tax_id' => $this->validated('tax_id'),
        ];
    }

    public function version(): int
    {
        return (int) $this->validated('version');
    }
}
