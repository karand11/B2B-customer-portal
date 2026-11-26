<x-mail::message>
# Your quote is ready

Quote {{ $number }} has been priced.

- Total: {{ $total }}
- Valid through {{ $validUntil }} (end of day, UTC)
- Lines: {{ $lineCount }}

<x-mail::button :url="$url">
Review and accept the quote
</x-mail::button>

{{ config('app.name') }}
</x-mail::message>
