<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Support\Money;
use App\Support\PdfText;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Renders an invoice PDF on demand from the immutable snapshot (needs `items` loaded). The Blade view is the only
 * template; every dynamic value goes through PdfText::clean() here and {{ }} there. dompdf runs with remote files, PHP
 * and JavaScript off and a narrow chroot (config/dompdf.php). "Page X of Y" is drawn after render() because inline PHP is off.
 */
class InvoicePdf
{
    /** Rows kept together with the totals and payment block, so they never land alone on a trailing page. */
    private const TAIL_ROWS = 4;

    /** The HTML the PDF is built from (also what the tests assert on). */
    public function html(Invoice $invoice): string
    {
        $seller = $invoice->seller;
        $buyer = $invoice->buyer;
        $currency = $invoice->currency;

        return view('invoices.pdf', [
            'number' => $invoice->number,
            'issueDate' => $invoice->issue_date->toDateString(),
            'dueDate' => $invoice->due_date->toDateString(),
            'orderNumber' => $invoice->order_number,
            'poReference' => PdfText::line($invoice->po_reference, 20),
            'currency' => $currency,
            'void' => $invoice->status === InvoiceStatus::Void,
            'seller' => [
                'name' => PdfText::line($seller['name']),
                'address_lines' => $this->lines($seller['address_lines']),
                'tax_id' => PdfText::line($seller['tax_id']),
                'email' => PdfText::line($seller['email']),
                'phone' => PdfText::line($seller['phone']),
                'bank' => array_map(fn ($v) => PdfText::line($v), $seller['bank']),
                'payment_terms_text' => PdfText::line($seller['payment_terms_text']),
                'footer' => PdfText::line($seller['footer']),
            ],
            'buyer' => [
                'name' => PdfText::line($buyer['name']),
                'address_lines' => $this->lines($buyer['address_lines']),
                'tax_id' => PdfText::line($buyer['tax_id']),
            ],
            // The last rows travel with the totals and payment block (one unsplittable table row in the template).
            ...$this->splitItems($invoice->items->map(fn (InvoiceItem $i) => [
                'position' => $i->position,
                'part_number' => PdfText::line($i->part_number, 16),
                'name_lines' => PdfText::lines($i->name) ?: [''],
                'brand' => PdfText::line($i->brand, 24),
                'quantity' => $i->quantity,
                'unit_price' => Money::amount($i->unit_price_cents),
                'line_total' => Money::amount($i->line_total_cents),
            ])->all()),
            'vatLabel' => PdfText::line($seller['vat_label']).' '.Money::percent($invoice->vat_rate_bp),
            'subtotal' => Money::format($invoice->subtotal_cents, $currency),
            'tax' => Money::format($invoice->tax_cents, $currency),
            'total' => Money::format($invoice->total_cents, $currency),
        ])->render();
    }

    /** The PDF bytes. */
    public function render(Invoice $invoice): string
    {
        $pdf = Pdf::loadHTML($this->html($invoice));
        $stamp = 'D:'.$invoice->created_at->utc()->format('YmdHis').'Z';
        $pdf->addInfo([
            'Title' => 'Invoice '.$invoice->number,
            'Author' => PdfText::line($invoice->seller['name']),
            'Subject' => 'Invoice',
            'Creator' => 'B2B Customer Portal',
            'CreationDate' => $stamp,
            'ModDate' => $stamp,
        ]);
        $pdf->render();

        $dompdf = $pdf->getDomPDF();
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $label = 'Page {PAGE_NUM} of {PAGE_COUNT}';
        $width = $dompdf->getFontMetrics()->getTextWidth('Page 99 of 99', $font, 7);
        // Bottom right: 18 mm side margin, own area right of the footer text, about 10 mm from the page bottom.
        $canvas->page_text($canvas->get_width() - 51 - $width, $canvas->get_height() - 30, $label, $font, 7, [0.2, 0.2, 0.2]);

        return $pdf->output();
    }

    /**
     * Everything except the last TAIL_ROWS rows, and those last rows.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{itemsHead: list<array<string, mixed>>, itemsTail: list<array<string, mixed>>}
     */
    private function splitItems(array $rows): array
    {
        return ['itemsHead' => array_slice($rows, 0, max(0, count($rows) - self::TAIL_ROWS)), 'itemsTail' => array_slice($rows, -self::TAIL_ROWS)];
    }

    /**
     * @param  list<string>  $lines
     * @return list<string>
     */
    private function lines(array $lines): array
    {
        return array_values(array_filter(array_map(fn ($l) => PdfText::line($l), $lines), fn ($l) => $l !== ''));
    }
}
