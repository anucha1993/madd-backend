<?php

namespace App\Services;

use Smalot\PdfParser\Parser;

/**
 * Extracts a PDF's native embedded text layer (correct reading order, unlike Google Vision OCR
 * which re-flows table columns based on visual block detection — see GoogleVisionService).
 * Returns null when the PDF has no usable text layer (e.g. a scanned/photographed invoice),
 * so the caller can fall back to real OCR for those.
 */
class PdfTextExtractorService
{
    private const MIN_USABLE_LENGTH = 200;

    // smalot/pdfparser has to decompress every embedded content stream/image to walk the PDF
    // object tree, even though we only want the text — a real 17-page invoice confirmed to need
    // well over the default 128M during testing (2026-10-02). An uncaught "Allowed memory size
    // exhausted" is a true fatal error (not a catchable \Throwable), which would otherwise crash
    // the whole persistent queue:work process, not just fail this one job — so raise the limit
    // just for this call (only if the current limit is lower and not already unlimited) and
    // restore it immediately after.
    private const MEMORY_LIMIT_BYTES = 512 * 1024 * 1024;

    public function extract(string $pdfContent): ?string
    {
        $previousLimit = $this->raiseMemoryLimitIfNeeded();

        try {
            $document = (new Parser())->parseContent($pdfContent);
            $text = trim($document->getText());
        } catch (\Throwable $e) {
            return null;
        } finally {
            // Restoring to the previous (lower) limit can fail with a PHP warning if current
            // memory usage already exceeds it (expected/harmless after a large PDF parse) — @
            // -suppressed since that's not an actionable error; memory_limit simply stays raised
            // for the rest of this request/job, which is fine.
            if ($previousLimit !== null) {
                @ini_set('memory_limit', $previousLimit);
            }
        }

        return strlen($text) >= self::MIN_USABLE_LENGTH ? $text : null;
    }

    /**
     * @return string|null the previous memory_limit ini value if it was changed (so the caller
     *                      can restore it), or null if no change was made (already high enough).
     */
    private function raiseMemoryLimitIfNeeded(): ?string
    {
        $current = ini_get('memory_limit');

        // "-1" means unlimited — never override that.
        if ($current === '-1') {
            return null;
        }

        $currentBytes = (int) $current === 0 ? 0 : $this->toBytes($current);
        if ($currentBytes >= self::MEMORY_LIMIT_BYTES) {
            return null;
        }

        $previous = ini_set('memory_limit', (string) self::MEMORY_LIMIT_BYTES);

        return $previous === false ? null : $previous;
    }

    private function toBytes(string $value): int
    {
        $value = trim($value);
        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => (int) $value,
        };
    }
}
