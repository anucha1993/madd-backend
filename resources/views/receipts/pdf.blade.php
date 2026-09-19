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
    @include('receipts.tax-invoice-page', ['receipt' => $receipt, 'headOffice' => $headOffice, 'issuingBranch' => $issuingBranch])
    {{-- RECEIPT page is always printed at half A4 (210 x 148.5mm), with tighter margins so the
         whole compact layout fits on the one short page --}}
    <pagebreak sheet-size="210mm 148.5mm" margin-top="2mm" margin-bottom="2mm" />
    @include('receipts.receipt-page', ['receipt' => $receipt, 'headOffice' => $headOffice, 'issuingBranch' => $issuingBranch, 'isCombinedLine' => true])
@else
    {{-- Standalone Cash Receipt IS the "RECEIPT page" layout from the reference sample, which
         always collapses to ONE combined line (grand total), never itemized like the Tax Invoice. --}}
    @include('receipts.receipt-page', ['receipt' => $receipt, 'headOffice' => $headOffice, 'issuingBranch' => $issuingBranch, 'isCombinedLine' => true])
@endif
</body>
</html>
