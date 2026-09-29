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

    /**
     * Mass Print — renders each Receipt individually (via render() above, so every document keeps
     * its own correct page size: half-A4 Cash Receipt vs full-A4 Tax Invoice) then imports every
     * resulting page into ONE combined PDF (FPDI import, same technique as
     * ShipmentController::buildDhlDiyWaybill) so staff get a single print job instead of opening
     * a tab per document.
     *
     * @param  \Illuminate\Support\Collection<int, Receipt>  $receipts
     */
    public function renderBatch($receipts): string
    {
        $mpdf = new Mpdf(['mode' => 'th', 'default_font' => 'garuda']);
        $tempFiles = [];

        try {
            foreach ($receipts as $receipt) {
                $tempPath = tempnam(sys_get_temp_dir(), 'receipt-batch-').'.pdf';
                file_put_contents($tempPath, $this->render($receipt));
                $tempFiles[] = $tempPath;

                $pageCount = $mpdf->setSourceFile($tempPath);
                for ($page = 1; $page <= $pageCount; $page++) {
                    $templateId = $mpdf->importPage($page);
                    $size = $mpdf->getTemplateSize($templateId);
                    // orientation MUST always be 'P' here — mpdf's own _setPageSize() SWAPS the
                    // given sheet-size width/height whenever orientation is 'L' (it expects a
                    // "base portrait" format + a separate landscape flag, not a literal final
                    // width/height pair). Since $size is already the exact final page size we
                    // want, passing 'L' here silently flips a landscape page (e.g. this Cash
                    // Receipt's 210x148.5mm half-A4) into an unintended 148.5x210mm portrait,
                    // clipping content off the right edge — confirmed live from a Mass Print PDF.
                    $mpdf->AddPageByArray([
                        'orientation' => 'P',
                        'sheet-size' => [$size['width'], $size['height']],
                        'margin-top' => 0,
                        'margin-bottom' => 0,
                        'margin-left' => 0,
                        'margin-right' => 0,
                    ]);
                    $mpdf->useTemplate($templateId, 0, 0, $size['width'], $size['height']);
                }
            }

            return $mpdf->Output('', 'S');
        } finally {
            foreach ($tempFiles as $tempFile) {
                @unlink($tempFile);
            }
        }
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
