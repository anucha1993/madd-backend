<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Receipt;
use Mpdf\Mpdf;

class ReceiptPdfService
{
    /**
     * Renders a Receipt (CASH_RECEIPT = 1 page, TAX_INVOICE = 2 pages: Tax Invoice then Receipt)
     * to a PDF binary string, matching the reference "Tax invoice_รวม (FREIGHT SERVICE).pdf"
     * template layout (see /memories/repo/receipt-tax-invoice-module.md for the exact spec).
     */
    public function render(Receipt $receipt): string
    {
        $receipt->loadMissing('lines', 'branch');

        $issuingBranch = $receipt->branch;
        $headOffice = Branch::where('is_head_office', true)->first() ?? $issuingBranch;

        // A standalone Cash Receipt IS the Receipt page (never preceded by a Tax Invoice page),
        // so the whole document starts at half A4 — a Tax Invoice's own Receipt page (page 2)
        // is instead shrunk mid-document via a `<pagebreak sheet-size="...">` (see pdf.blade.php).
        // Half A4 uses tighter margins too, so the compact layout still fits on the one short page.
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
