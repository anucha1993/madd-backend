<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Receipt;
use Mpdf\Mpdf;

class ReceiptPdfService
{
    /**
     * Renders a Receipt (CASH_RECEIPT = 1 half-A4 page, TAX_INVOICE = 1 A4 page) to a PDF binary
     * string — staff choose EITHER document type per shipment, never both bundled together (see
     * /memories/repo/receipt-tax-invoice-module.md for history/spec).
     */
    public function render(Receipt $receipt): string
    {
        $receipt->loadMissing('lines', 'branch');

        $issuingBranch = $receipt->branch;
        $headOffice = Branch::where('is_head_office', true)->first() ?? $issuingBranch;

        // A standalone Cash Receipt IS the Receipt page layout, printed at half A4 with tighter
        // margins so the compact layout fits on the one short page. Tax Invoice is its own
        // standalone A4 page (no bundled Receipt page — see pdf.blade.php, 2026-09-21).
        $isCashReceipt = $receipt->type === 'CASH_RECEIPT';
        $format = $isCashReceipt ? [210, 148.5] : 'A4';

        $mpdf = new Mpdf([
            'mode' => 'th',
            'format' => $format,
            'default_font' => 'garuda',
            'margin_top' => $isCashReceipt ? 2 : 12,
            'margin_bottom' => $isCashReceipt ? 2 : 12,
            'margin_left' => 14,
            'margin_right' => 14,
        ]);

        $html = view('receipts.pdf', [
            'receipt' => $receipt,
            'headOffice' => $headOffice,
            'issuingBranch' => $issuingBranch,
            'logoDataUri' => $this->logoDataUri(),
        ])->render();

        $mpdf->WriteHTML($html);

        return $mpdf->Output('', 'S');
    }

    // Embedded as a base64 data URI (not a plain file path) so mpdf never has to resolve a
    // filesystem/relative path itself — always reliable regardless of mpdf's working directory.
    private function logoDataUri(): ?string
    {
        $path = public_path('logo/logo.png');
        if (! is_file($path)) {
            return null;
        }

        return 'data:image/png;base64,'.base64_encode(file_get_contents($path));
    }
}
