<?php

namespace App\Services;

use App\Models\CarrierInvoice;
use App\Models\CarrierInvoiceLine;
use Mpdf\Mpdf;

/**
 * Orchestrates one carrier invoice through: get page count (via Mpdf/FPDI's setSourceFile, the
 * same PDF-reading library already used for commercial-invoice merging — no new dependency) ->
 * extract text -> parse into candidate lines -> auto-match each line to a Shipment by tracking
 * number.
 *
 * Text extraction PREFERS the PDF's own native embedded text layer (PdfTextExtractorService —
 * correct left-to-right/top-to-bottom reading order, matches how the parser regexes were built
 * and validated against real sample invoices) and only falls back to Google Vision OCR when the
 * PDF has no usable text layer at all (e.g. a scanned/photographed invoice). Confirmed via a real
 * test upload (2026-10-02) that Vision OCR alone re-flows multi-column charge tables into a
 * different, non-matching order for born-digital PDFs — see carrier-invoice-ocr-module.md.
 */
class CarrierInvoiceOcrService
{
    public function __construct(
        private R2Service $r2Service,
        private GoogleVisionService $visionService,
        private PdfTextExtractorService $textExtractorService,
        private CarrierInvoiceParserService $parserService,
        private CarrierInvoiceMatchingService $matchingService,
    ) {
    }

    public function process(CarrierInvoice $invoice): void
    {
        $invoice->update(['status' => 'parsing', 'error_message' => null]);

        try {
            $pdfContent = $this->r2Service->download($invoice->storage_key);
            $text = $this->textExtractorService->extract($pdfContent);
            if ($text === null) {
                $pageCount = $this->countPdfPages($pdfContent);
                $text = $this->visionService->extractPdfText($pdfContent, $pageCount);
            }
            $lines = $this->parserService->parse($invoice->carrier, $text);

            $invoice->lines()->delete();
            foreach ($lines as $i => $line) {
                $saved = CarrierInvoiceLine::create([
                    'carrier_invoice_id' => $invoice->id,
                    'sort_order' => $i,
                    'tracking_number' => $line['tracking_number'],
                    'reference_text' => $line['reference_text'],
                    'description' => $line['description'],
                    'charges' => $line['charges'] ?? null,
                    'discount' => $line['discount'] ?? null,
                    'amount' => $line['amount'],
                ]);
                $this->matchingService->match($saved, $invoice->carrier);
            }

            $invoice->update(['status' => 'parsed', 'ocr_text' => $text]);
        } catch (\Throwable $e) {
            report($e);
            $invoice->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
        }
    }

    private function countPdfPages(string $pdfContent): int
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'carrier-invoice-').'.pdf';
        file_put_contents($tempPath, $pdfContent);

        try {
            $mpdf = new Mpdf(['format' => 'A4']);

            return $mpdf->setSourceFile($tempPath);
        } finally {
            @unlink($tempPath);
        }
    }
}
