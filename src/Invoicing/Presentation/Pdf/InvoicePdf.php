<?php

declare(strict_types=1);

namespace Metered\Invoicing\Presentation\Pdf;

use Dompdf\Dompdf;
use Dompdf\Options;
use Metered\Invoicing\Infrastructure\Eloquent\Invoice;

/**
 * Rendered with dompdf (pure PHP, no browser). Remote resources are disabled.
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
