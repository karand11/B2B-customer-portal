<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Stringable;
use Illuminate\Validation\NotPwnedVerifier;
use Throwable;

/**
 * Have I Been Pwned range API with a short timeout. Only the first five characters of the SHA-1 leave the server.
 * An outage, a timeout or an error answer SKIPS the check (fail open) and logs a warning without the exception: its message
 * contains the request URL, which contains the hash prefix of the password.
 */
class FailOpenPwnedVerifier extends NotPwnedVerifier
{
    protected function search($hashPrefix)
    {
        $body = '';

        try {
            $response = $this->factory->withHeaders(['Add-Padding' => true])
                ->timeout((int) config('portal.password_breach_timeout'))
                ->connectTimeout((int) config('portal.password_breach_timeout'))
                ->get('https://api.pwnedpasswords.com/range/'.$hashPrefix);

            $response->successful() ? $body = $response->body() : $this->unavailable('status');
        } catch (Throwable) {
            $this->unavailable('exception');
        }

        return (new Stringable($body))->trim()->explode("\n")->filter(fn ($line) => str_contains($line, ':'));
    }

    private function unavailable(string $reason): void
    {
        Log::warning('Password breach check unavailable', ['reason' => $reason]);
    }
}
