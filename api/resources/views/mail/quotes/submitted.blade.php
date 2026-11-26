<x-mail::message>
# New quote request

Quote {{ $number }} was submitted by {{ $requesterName }} ({{ $companyName }}).

- Lines: {{ $lineCount }}
- PO reference: {{ $poReference ?? 'none' }}

<x-mail::button :url="$url">
Open the quote
</x-mail::button>

{{ config('app.name') }}
</x-mail::message>
