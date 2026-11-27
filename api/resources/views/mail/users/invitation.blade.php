<x-mail::message>
# You have been invited

An account has been created for you. Choose a password to finish setting it up.

<x-mail::button :url="$url">
Set your password
</x-mail::button>

This link works once and expires in {{ $validDays }} days. If you did not expect this email, ignore it.

{{ config('app.name') }}
</x-mail::message>
