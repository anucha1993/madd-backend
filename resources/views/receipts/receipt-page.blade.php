{{-- Full "ใบเสร็จรับเงิน / RECEIPT" page — used standalone for CASH_RECEIPT, and as page 2 of a
     TAX_INVOICE (see resources/views/receipts/pdf.blade.php). Variables: $receipt, $headOffice,
     $issuingBranch, $isCombinedLine (bool — collapse all lines into one row showing grand_total,
     matching the sample where the Receipt page always shows ONE line = the invoice's grand total). --}}
@include('receipts.partials.header', [
    'docTypeThai' => 'ใบเสร็จรับเงิน',
    'docTypeEnglish' => 'RECEIPT',
    'showFax' => true,
])
@include('receipts.partials.buyer')

@php
    // A standalone Cash Receipt itemizes every charge line (unlike the Tax Invoice's combined
    // Receipt page, always 1 row) — the more lines, the less room is left on this fixed half-A4
    // sheet. Scale the item table + surrounding spacing down as line count grows so it always
    // fits on one page instead of spilling the signature/footer block onto a 3rd page.
    $lineCount = ($isCombinedLine ?? false) ? 1 : max(1, $receipt->lines->count());
    if ($lineCount <= 3) {
        [$rowFont, $rowPad, $gapSm, $gapMd] = ['8.5pt', '3px', '4px', '5px'];
    } elseif ($lineCount <= 6) {
        [$rowFont, $rowPad, $gapSm, $gapMd] = ['8pt', '2px', '4px', '5px'];
    } else {
        [$rowFont, $rowPad, $gapSm, $gapMd] = ['7pt', '1px', '2px', '3px'];
    }
@endphp

<table style="width:100%; border-collapse:collapse; font-size:{{ $rowFont }}; margin-top:{{ $gapSm }};">
    <thead>
        <tr>
            <td style="border:1px solid #000; padding:{{ $rowPad }}; text-align:center; width:8%;">ลำดับที่<br/><span style="font-size:7pt;">ITEM</span></td>
            <td style="border:1px solid #000; padding:{{ $rowPad }}; text-align:center;">รายการสินค้า<br/><span style="font-size:7pt;">DESCRIPTION</span></td>
            <td style="border:1px solid #000; padding:{{ $rowPad }}; text-align:center; width:22%;">AMOUNT</td>
        </tr>
    </thead>
    <tbody>
        @if ($isCombinedLine ?? false)
            {{-- Sample reference shows this single combined row roughly double height (~2 normal
                 rows worth), text top-aligned with blank space below before the closing border. --}}
            <tr>
                <td style="border-left:1px solid #000; border-right:1px solid #000; border-bottom:1px solid #000; padding:{{ $rowPad }} {{ $rowPad }} 14px {{ $rowPad }}; text-align:center; vertical-align:top;">1</td>
                <td style="border-right:1px solid #000; border-bottom:1px solid #000; padding:{{ $rowPad }} {{ $rowPad }} 14px {{ $rowPad }}; vertical-align:top;">{{ $receipt->lines->first()?->description ?? 'FREIGHT SERVICE' }}</td>
                <td style="border-right:1px solid #000; border-bottom:1px solid #000; padding:{{ $rowPad }} {{ $rowPad }} 14px {{ $rowPad }}; text-align:right; vertical-align:top;">{{ number_format($receipt->grand_total, 2) }}</td>
            </tr>
        @else
            @foreach ($receipt->lines as $index => $line)
                <tr>
                    <td style="border-left:1px solid #000; border-right:1px solid #000; border-bottom:1px solid #000; padding:{{ $rowPad }}; text-align:center;">{{ $index + 1 }}</td>
                    <td style="border-right:1px solid #000; border-bottom:1px solid #000; padding:{{ $rowPad }};">{{ $line->description }}</td>
                    <td style="border-right:1px solid #000; border-bottom:1px solid #000; padding:{{ $rowPad }}; text-align:right;">{{ number_format($line->amount, 2) }}</td>
                </tr>
            @endforeach
        @endif
    </tbody>
</table>

<table style="width:100%; border-collapse:collapse; font-size:{{ $rowFont }};">
    <tr>
        <td style="width:78%; text-align:right; padding:{{ $rowPad }}; font-weight:bold;">รวมราคาทั้งสิ้น ( GRAND TOTAL )</td>
        <td style="width:22%; border:1px solid #000; padding:{{ $rowPad }}; text-align:right; font-weight:bold;">{{ number_format($receipt->grand_total, 2) }}</td>
    </tr>
</table>

<table style="width:100%; border-collapse:collapse; font-size:8pt; margin-top:{{ $gapMd }};">
    <tr>
        <td style="width:22%; vertical-align:top;">จำนวนเงินเป็นตัวอักษร<br/><span style="font-size:7pt;">GRAND TOTAL IN WORDS</span></td>
        <td style="border:1px solid #000; text-align:center; padding:6px; color:#00008B; font-weight:bold;">{{ $receipt->grand_total_words }}</td>
    </tr>
</table>

<div style="font-size:8.5pt; margin-top:{{ $gapSm }};">
    ชำระโดยเงินสด/เช็ค ธนาคาร......{{ $receipt->payment_method }}......เลขที่......{{ $receipt->payment_reference }}......ลงวันที่......................
    <div style="font-size:7pt;">By Cash/Cheque Bank</div>
</div>

<table style="width:100%; border-collapse:collapse; font-size:8pt; margin-top:{{ $gapMd }};">
    <tr>
        <td style="width:48%;">
            <table align="center" style="width:80%; border-collapse:collapse;"><tr><td style="border-bottom:1px dotted #000;">&nbsp;</td></tr></table>
        </td>
        <td style="width:4%;"></td>
        <td style="width:48%;">
            <table align="center" style="width:80%; border-collapse:collapse;"><tr><td style="border-bottom:1px dotted #000;">&nbsp;</td></tr></table>
        </td>
    </tr>
    <tr>
        <td style="text-align:center; padding-top:8px;">ผู้รับเงิน<br/><span style="font-size:7pt;">Bill Collector</span></td>
        <td></td>
        <td style="text-align:center; padding-top:8px;">ลายเซ็นต์ผู้มีอำนาจ<br/><span style="font-size:7pt;">Authorized Signature</span></td>
    </tr>
</table>

<table style="width:100%; border-collapse:collapse; font-size:7.5pt; margin-top:{{ $gapSm }};">
    <tr>
        <td style="border:1px solid #000; text-align:center; padding:3px;">
            ใบเสร็จรับเงินนี้จะสมบูรณ์เมื่อมีลายเซ็นต์ของผู้ได้รับมอบอำนาจ และเช็คฉบับนี้เข้าบัญชีได้เรียบร้อยแล้ว<br/>
            <span style="font-size:8pt;">THE RECEIPT IS VALID ONLY WHEN SIGNED BY THE AUTHORIZED AND THE CHEQUE DULY CLEARED.</span>
        </td>
    </tr>
</table>
