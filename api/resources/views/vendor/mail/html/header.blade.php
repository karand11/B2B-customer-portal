@props(['url'])
{{-- Portal: the header text links to the web app (config app.web_url), never to the API host passed in as $url, and shows no logo image. --}}
<tr>
<td class="header">
<a href="{{ config('app.web_url') }}" style="display: inline-block;">
{{ $slot }}
</a>
</td>
</tr>
