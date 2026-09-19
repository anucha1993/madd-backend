<?php

namespace App\Services;

use App\Models\DocumentNumberSequence;
use Illuminate\Support\Facades\DB;

/**
 * Renders/increments the configurable Vol.No ("เล่มที่") and No. ("เลขที่") document numbers for
 * Receipts/Tax Invoices — one independent auto-incrementing counter per (branch, document_type,
 * field). Pattern placeholders: {YYYY}/{YY}/{MM}/{DD} substitute the current date; a run of zeros
 * in braces (e.g. "{00001}") is the counter itself, zero-padded to that width.
 */
class DocumentNumberService
{
    private const DEFAULT_PATTERNS = [
        'vol_no' => '001',
        'no' => '{00001}',
    ];

    /**
     * Atomically renders the NEXT number for this sequence and advances the counter — call only
     * when actually issuing a document (not for previews), since this permanently consumes one.
     */
    public function next(int $branchId, string $documentType, string $field): string
    {
        return DB::transaction(function () use ($branchId, $documentType, $field) {
            $sequence = DocumentNumberSequence::where('branch_id', $branchId)
                ->where('document_type', $documentType)
                ->where('field', $field)
                ->lockForUpdate()
                ->first();

            if (! $sequence) {
                $sequence = DocumentNumberSequence::create([
                    'branch_id' => $branchId,
                    'document_type' => $documentType,
                    'field' => $field,
                    'pattern' => self::DEFAULT_PATTERNS[$field],
                    'next_number' => 1,
                ]);
            }

            $rendered = $this->render($sequence->pattern, $sequence->next_number);
            $sequence->increment('next_number');

            return $rendered;
        });
    }

    /**
     * Read-only look at what the NEXT number would render as, without consuming it — used by the
     * issue-receipt UI to show a live preview before Submit.
     */
    public function preview(int $branchId, string $documentType, string $field): string
    {
        $sequence = DocumentNumberSequence::where('branch_id', $branchId)
            ->where('document_type', $documentType)
            ->where('field', $field)
            ->first();

        return $this->render(
            $sequence->pattern ?? self::DEFAULT_PATTERNS[$field],
            $sequence->next_number ?? 1,
        );
    }

    private function render(string $pattern, int $number): string
    {
        $now = now();
        $rendered = str_replace(
            ['{YYYY}', '{YY}', '{MM}', '{DD}'],
            [$now->format('Y'), $now->format('y'), $now->format('m'), $now->format('d')],
            $pattern,
        );

        // A run of digits inside braces is the counter placeholder — its LENGTH (not its
        // literal digits) sets the zero-padded width, e.g. both "{00001}" and "{00000}" mean
        // "pad to 5 digits" (matches how staff naturally write the first real number as the
        // example pattern, even though it isn't literally all zeros).
        return preg_replace_callback('/\{(\d+)\}/', function ($matches) use ($number) {
            return str_pad((string) $number, strlen($matches[1]), '0', STR_PAD_LEFT);
        }, $rendered);
    }
}
