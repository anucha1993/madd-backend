<?php

namespace App\Services;

/**
 * Turns the extracted text of a carrier invoice into a flat list of candidate line items —
 * best-effort text parsing, NOT guaranteed 100% accurate (invoice table layouts vary). Every
 * line produced here is still editable/correctable by staff afterward
 * (CarrierInvoiceLine.override_*), per the explicit requirement that mismatches must be fixable
 * by hand, not just auto-trusted.
 *
 * These regexes are built against PdfTextExtractorService's native PDF text-layer output (the
 * primary extraction source — see CarrierInvoiceOcrService), which preserves each invoice's own
 * left-to-right/top-to-bottom reading order. Google Vision OCR (the fallback for scanned PDFs
 * with no embedded text) reads tables as visual blocks and can re-flow/scramble multi-column
 * tables into a different order — confirmed via a real test invoice (2026-10-02) where OCR text
 * contained every tracking number and amount but in a jumbled, non-row-major sequence that these
 * regexes could not parse. If OCR-sourced text ever needs to be parsed again, these patterns may
 * need separate handling.
 *
 * UPS: each shipment block has one line per charge ("<description> <charge> <discount>
 * <net charges>"), ending with a "Total Charges For Shipment <tracking>..." line repeating the
 * tracking number — each charge line becomes its own CarrierInvoiceLine (amount = net charges).
 * DHL: each shipment starts with a line "<AWB number> <description...>" and its own
 * "Total amount (incl. VAT)" figure is the LAST money value appearing before the next AWB line
 * (or before a few known footer/summary markers for the very last shipment on the page) — only
 * the per-shipment NET TOTAL is extracted reliably this way, as one line per shipment; staff can
 * split it into sub-charges manually if needed.
 */
class CarrierInvoiceParserService
{
    /**
     * @return array<int, array{tracking_number: ?string, reference_text: ?string, description: ?string, charges: ?float, discount: ?float, amount: ?float}>
     */
    public function parse(string $carrier, string $text): array
    {
        $text = str_replace("\r\n", "\n", $text);

        return match (strtoupper($carrier)) {
            'UPS' => $this->parseUps($text),
            'DHL' => $this->parseDhl($text),
            default => [],
        };
    }

    private function parseUps(string $text): array
    {
        $lines = [];

        // Header row is a single line: "Charges Description Charges DiscountNet Charges" (no
        // space between "Discount" and "Net Charges" — that's how the PDF's own text layer
        // draws those two adjacent column headers). Block ends at the repeated tracking number
        // on the "Total Charges For Shipment <tracking>" line.
        $pattern = '/(1Z[0-9A-Z]{16}).*?Charges\s*Description\s*Charges\s*DiscountNet\s*Charges\s*\n(.*?)Total Charges For Shipment\s*\1/s';
        if (! preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
            return [];
        }

        // Each charge is one text line: "<description> <charges> <discount> <net charges>".
        $rowPattern = '/^(.*?)\s+([\d,]+\.\d{2})\s+([\d,]+\.\d{2})\s+([\d,]+\.\d{2})\s*$/m';

        foreach ($matches as $m) {
            $tracking = $m[1];
            if (! preg_match_all($rowPattern, trim($m[2]), $rows, PREG_SET_ORDER)) {
                continue;
            }

            foreach ($rows as $row) {
                $lines[] = [
                    'tracking_number' => $tracking,
                    'reference_text' => null,
                    'description' => trim($row[1]),
                    'charges' => $this->parseAmount($row[2]),
                    'discount' => $this->parseAmount($row[3]),
                    'amount' => $this->parseAmount($row[4]),
                ];
            }
        }

        return $lines;
    }

    private function parseDhl(string $text): array
    {
        $lines = [];

        // Each shipment block starts with a line beginning with the AWB number followed directly
        // by its shipper-reference text on the same line (e.g. "6751098970 MADD-BKP/DHL ENVEL").
        if (! preg_match_all('/^(\d{8,12})\s+\S.*$/m', $text, $trackingMatches, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        // Markers that only ever appear in the page footer / closing summary, never inside a
        // real shipment's own charge block — truncate a block here before reading its "last
        // amount", otherwise the LAST shipment block on a page would swallow the whole invoice's
        // closing totals (Service Sub Total/grand total) as if they were its own amount.
        $footerPattern = '/Payment due date|DHL EXPRESS INTERNATIONAL|Digitally signed|Service Sub Total|Total THB|SHIPMENT DETAILS/';

        $offsets = $trackingMatches[1];
        for ($i = 0; $i < count($offsets); $i++) {
            $tracking = $offsets[$i][0];
            $start = $offsets[$i][1];
            $end = $offsets[$i + 1][1] ?? strlen($text);
            $block = substr($text, $start, $end - $start);

            if (preg_match($footerPattern, $block, $fm, PREG_OFFSET_CAPTURE)) {
                $block = substr($block, 0, $fm[0][1]);
            }

            $blockLines = array_values(array_filter(array_map('trim', preg_split('/\n/', trim($block))), fn ($r) => $r !== ''));
            $reference = $blockLines[1] ?? null;

            preg_match_all('/([\d,]+\.\d{2})/', $block, $amountMatches);
            $amount = ! empty($amountMatches[1]) ? $this->parseAmount(end($amountMatches[1])) : null;

            // A candidate AWB-start line whose block has no money figure (e.g. a bank account
            // number printed at the start of a line in the payment-instructions section) is
            // almost always a false-positive match, not a real shipment — skip it.
            if ($amount === null) {
                continue;
            }

            $lines[] = [
                'tracking_number' => $tracking,
                'reference_text' => $reference,
                'description' => 'Shipment Total (DHL — รายการย่อยไม่ได้แยกอัตโนมัติ กรุณาตรวจสอบ/แก้ไขเอง)',
                'charges' => null,
                'discount' => null,
                'amount' => $amount,
            ];
        }

        return $lines;
    }

    private function parseAmount(string $raw): ?float
    {
        $clean = preg_replace('/[^0-9.\-]/', '', $raw);

        return $clean === '' || $clean === null ? null : (float) $clean;
    }
}
