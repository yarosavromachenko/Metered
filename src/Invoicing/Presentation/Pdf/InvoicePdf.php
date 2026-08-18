<?php

declare(strict_types=1);

namespace Metered\Invoicing\Presentation\Pdf;

use Dompdf\Dompdf;
use Dompdf\Options;
use Metered\Invoicing\Infrastructure\Eloquent\Invoice;

/**
 * An invoice as a PDF document: the lines, each with its working, the total,
 * and — for a voided one — the credit note that reversed it.
 *
 * Rendered from HTML by dompdf, which is PHP all the way down: no browser, no
 * binary in the image. Remote resources are off, so a document never fetches
 * anything while it is being drawn.
 */
final class InvoicePdf
{
    public static function render(Invoice $invoice): string
    {
        $invoice->loadMissing(['lines', 'creditNote']);

        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('DejaVu Sans');

        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('invoicing::pdf.invoice', ['invoice' => $invoice])->render());
        $pdf->setPaper('A4');
        $pdf->render();

        return (string) $pdf->output();
    }

    public static function filename(Invoice $invoice): string
    {
        return sprintf('%s.pdf', $invoice->printedNumber() ?? 'draft-' . $invoice->id);
    }
}
