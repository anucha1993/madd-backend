<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8" />
<style>
    body { font-family: garuda, sans-serif; font-size: 10pt; color: #000; }
    table { font-family: garuda, sans-serif; }
</style>
</head>
<body>
@if ($receipt->type === 'TAX_INVOICE')
    {{-- Tax Invoice is now issued standalone (1 page only) — the user must choose EITHER Cash
         Receipt OR Tax Invoice, never both bundled together, per their explicit instruction to
         remove the previously-combined Receipt page (2026-09-21). --}}
    @include('receipts.tax-invoice-page', ['receipt' => $receipt, 'headOffice' => $headOffice, 'issuingBranch' => $issuingBranch])
@else
    {{-- Standalone Cash Receipt IS the "RECEIPT page" layout from the reference sample, which
         always collapses to ONE combined line (grand total), never itemized like the Tax Invoice. --}}
    @include('receipts.receipt-page', ['receipt' => $receipt, 'headOffice' => $headOffice, 'issuingBranch' => $issuingBranch, 'isCombinedLine' => true])
@endif
</body>
</html>
