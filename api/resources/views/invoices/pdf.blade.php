<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Invoice {{ $number }}</title>
<style>
    /* Bottom margin leaves room for a footer of up to 6 lines (a status line, a 300-character legal footer, the number). */
    @page { margin: 15mm 18mm 30mm 18mm; }
    body { font-family: "DejaVu Sans", sans-serif; font-size: 9pt; color: #111111; line-height: 1.35; }
    h1 { font-size: 20pt; margin: 0 0 6pt 0; letter-spacing: 1pt; }
    table { border-collapse: collapse; }
    td, th { vertical-align: top; }
    .layout { width: 100%; table-layout: fixed; }
    .layout td { padding: 0; }
    .right { text-align: right; }
    .muted { color: #444444; }
    .small { font-size: 8pt; }
    .seller-name { font-size: 12pt; font-weight: bold; margin-bottom: 2pt; }
    /* dompdf ignores <col> and mm widths: every column width is a percentage on the cells. */
    .meta { width: 100%; table-layout: fixed; }
    .meta td { padding: 1pt 0; }
    .meta .label { width: 38%; color: #444444; padding-right: 6pt; }
    .meta .value { width: 62%; text-align: right; }
    .items .pay td { padding: 1pt 0; border-bottom: 0; font-size: 9pt; }
    .items .pay .label { width: 30%; color: #444444; padding-right: 8pt; }
    .block-title { font-size: 8pt; font-weight: bold; text-transform: uppercase; color: #444444; margin: 14pt 0 3pt 0; }
    .void { border: 2pt solid #000000; padding: 6pt; margin: 0 0 10pt 0; text-align: center; font-size: 14pt; font-weight: bold; }
    .items { width: 100%; table-layout: fixed; margin-top: 12pt; }
    .items thead { display: table-header-group; }
    .items tr { page-break-inside: avoid; }
    .items th { border-bottom: 1pt solid #000000; padding: 4pt 3pt; font-size: 8pt; text-align: left; }
    .items td { border-bottom: 0.5pt solid #bbbbbb; padding: 4pt 3pt; font-size: 8.5pt; }
    .items .num { text-align: right; }
    .c1 { width: 5%; } .c2 { width: 18%; } .c3 { width: 36%; } .c4 { width: 8%; } .c5 { width: 16.5%; } .c6 { width: 16.5%; }
    /* The last rows, the totals and the payment block are ONE table row, which dompdf never splits: they always move to the next page together. */
    .items td.keep { border-bottom: 0; padding: 0; }
    .tail { width: 100%; table-layout: fixed; }
    .totals-wrap { margin-top: 10pt; }
    .totals { width: 55%; margin-left: 45%; }
    .totals td { padding: 3pt 3pt; font-size: 9pt; border-bottom: 0; }
    .totals .grand td { border-top: 1pt solid #000000; font-weight: bold; font-size: 10.5pt; }
    .payment { margin-top: 16pt; padding: 0 3pt; }
    /* The footer text stops at 72% of the width: the right 28% belongs to "Page X of Y", so the two can never overlap. */
    .footer { position: fixed; bottom: -25mm; left: 0; right: 0; height: 22mm; border-top: 0.5pt solid #888888; padding-top: 3pt; font-size: 7pt; line-height: 1.25; color: #333333; }
    .footer .text { width: 72%; }
</style>
</head>
<body>

<div class="footer">
    <div class="text">
        @if ($void)<div><strong>VOID - this invoice has been cancelled</strong></div>@endif
        <div>{{ $seller['footer'] }}</div>
        <div>{{ $number }}</div>
    </div>
</div>

@if ($void)
    <div class="void">VOID - this invoice has been cancelled</div>
@endif

<table class="layout">
    <tr>
        <td style="width: 55%;">
            <div class="seller-name">{{ $seller['name'] }}</div>
            @foreach ($seller['address_lines'] as $line)
                <div>{{ $line }}</div>
            @endforeach
            @if ($seller['email'] !== '')<div class="muted small">{{ $seller['email'] }}</div>@endif
            @if ($seller['phone'] !== '')<div class="muted small">{{ $seller['phone'] }}</div>@endif
            @if ($seller['tax_id'] !== '')<div class="muted small">Tax ID: {{ $seller['tax_id'] }}</div>@endif
        </td>
        <td style="width: 45%;">
            <h1 class="right">INVOICE</h1>
            <table class="meta">
                <tr><td class="label">Invoice no.</td><td class="value"><strong>{{ $number }}</strong></td></tr>
                <tr><td class="label">Issue date</td><td class="value">{{ $issueDate }}</td></tr>
                <tr><td class="label">Due date</td><td class="value">{{ $dueDate }}</td></tr>
                <tr><td class="label">Order no.</td><td class="value">{{ $orderNumber }}</td></tr>
                @if ($poReference !== '')
                    <tr><td class="label">PO reference</td><td class="value">{{ $poReference }}</td></tr>
                @endif
            </table>
        </td>
    </tr>
</table>

<div class="block-title">Billed to</div>
<div><strong>{{ $buyer['name'] }}</strong></div>
@foreach ($buyer['address_lines'] as $line)
    <div>{{ $line }}</div>
@endforeach
@if ($buyer['tax_id'] !== '')<div class="muted small">Tax ID: {{ $buyer['tax_id'] }}</div>@endif

<table class="items">
    <thead>
        <tr>
            <th class="c1">#</th>
            <th class="c2">Part no.</th>
            <th class="c3">Description</th>
            <th class="c4 num">Qty</th>
            <th class="c5 num">Unit price ({{ $currency }})</th>
            <th class="c6 num">Line total ({{ $currency }})</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($itemsHead as $item)
            <tr>
                <td class="c1">{{ $item['position'] }}</td>
                <td class="c2">{{ $item['part_number'] }}</td>
                <td class="c3">
                    @foreach ($item['name_lines'] as $line)<div>{{ $line }}</div>@endforeach
                    @if ($item['brand'] !== '')<div class="muted small">{{ $item['brand'] }}</div>@endif
                </td>
                <td class="c4 num">{{ $item['quantity'] }}</td>
                <td class="c5 num">{{ $item['unit_price'] }}</td>
                <td class="c6 num">{{ $item['line_total'] }}</td>
            </tr>
        @endforeach
        <tr>
            <td class="keep" colspan="6">
                <table class="items tail" style="margin-top: 0;">
                    @foreach ($itemsTail as $item)
                        <tr>
                            <td class="c1">{{ $item['position'] }}</td>
                            <td class="c2">{{ $item['part_number'] }}</td>
                            <td class="c3">
                                @foreach ($item['name_lines'] as $line)<div>{{ $line }}</div>@endforeach
                                @if ($item['brand'] !== '')<div class="muted small">{{ $item['brand'] }}</div>@endif
                            </td>
                            <td class="c4 num">{{ $item['quantity'] }}</td>
                            <td class="c5 num">{{ $item['unit_price'] }}</td>
                            <td class="c6 num">{{ $item['line_total'] }}</td>
                        </tr>
                    @endforeach
                </table>

                <div class="totals-wrap">
                    <table class="totals">
                        <tr><td>Subtotal</td><td class="right">{{ $subtotal }}</td></tr>
                        <tr><td>{{ $vatLabel }}</td><td class="right">{{ $tax }}</td></tr>
                        <tr class="grand"><td>Total</td><td class="right">{{ $total }}</td></tr>
                    </table>
                </div>

                <div class="payment">
                    <div class="block-title">Payment</div>
                    <div>{{ $seller['payment_terms_text'] }}</div>
                    <div>Due date: {{ $dueDate }}</div>
                    <table class="pay">
                        <tr><td class="label">Account holder</td><td>{{ $seller['bank']['account_holder'] }}</td></tr>
                        <tr><td class="label">Bank</td><td>{{ $seller['bank']['bank_name'] }}</td></tr>
                        <tr><td class="label">IBAN</td><td>{{ $seller['bank']['iban'] }}</td></tr>
                        <tr><td class="label">BIC</td><td>{{ $seller['bank']['bic'] }}</td></tr>
                    </table>
                    <div>Please quote invoice {{ $number }}</div>
                </div>
            </td>
        </tr>
    </tbody>
</table>

</body>
</html>
