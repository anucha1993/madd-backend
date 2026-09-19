{{-- Full "ใบกำกับภาษี / TAX INVOICE" page. Variables: $receipt (with lines, vat totals),
     $headOffice, $issuingBranch --}}
@include('receipts.partials.header', [
    'docTypeThai' => 'ใบกำกับภาษี',
    'docTypeEnglish' => 'TAX INVOICE',
    'showFax' => false,
])
@include('receipts.partials.buyer')

<table style="width:100%; border-collapse:collapse; font-size:9.5pt; margin-top:6px;">
    <thead>
        <tr>
            <td style="border:1px solid #000; padding:4px; text-align:center; width:8%;">ลำดับที่<br/><span style="font-size:8pt;">ITEM</span></td>
            <td style="border:1px solid #000; padding:4px; text-align:center; width:14%;">ใบแจ้งหนี้เลขที่<br/><span style="font-size:8pt;">INVOICE No.</span></td>
            <td style="border:1px solid #000; padding:4px; text-align:center;">รายการสินค้า<br/><span style="font-size:8pt;">DESCRIPTION</span></td>
            <td style="border:1px solid #000; padding:4px; text-align:center; width:16%;">AMOUNT<br/>( NON VAT )</td>
            <td style="border:1px solid #000; padding:4px; text-align:center; width:16%;">AMOUNT<br/>( VAT )</td>
        </tr>
    </thead>
    <tbody>
        @foreach ($receipt->lines as $index => $line)
            <tr>
                <td style="border-left:1px solid #000; border-right:1px solid #000; padding:3px; text-align:center;">{{ $index + 1 }}</td>
                <td style="border-right:1px solid #000; padding:3px; text-align:center;">{{ $line->invoice_no ?: '' }}</td>
                <td style="border-right:1px solid #000; padding:3px;">{{ $line->description }}</td>
                <td style="border-right:1px solid #000; padding:3px; text-align:right;">{{ $line->is_non_vat ? number_format($line->amount, 2) : '' }}</td>
                <td style="border-right:1px solid #000; padding:3px; text-align:right;">{{ $line->is_non_vat ? '' : number_format($line->amount, 2) }}</td>
            </tr>
        @endforeach
        @for ($i = $receipt->lines->count(); $i < 11; $i++)
            <tr>
                <td style="border-left:1px solid #000; border-right:1px solid #000; padding:3px;">&nbsp;</td>
                <td style="border-right:1px solid #000; padding:3px;">&nbsp;</td>
                <td style="border-right:1px solid #000; padding:3px;">&nbsp;</td>
                <td style="border-right:1px solid #000; padding:3px;">&nbsp;</td>
                <td style="border-right:1px solid #000; padding:3px;">&nbsp;</td>
            </tr>
        @endfor
        <tr>
            {{-- 3 separate cells (not colspan) so the ITEM/INVOICE No./DESCRIPTION column divider
                 lines continue all the way down to the closing border instead of a gap. --}}
            <td style="border-left:1px solid #000; border-right:1px solid #000; border-bottom:1px solid #000; padding:3px;">&nbsp;</td>
            <td style="border-right:1px solid #000; border-bottom:1px solid #000; padding:3px;">&nbsp;</td>
            <td style="border-right:1px solid #000; border-bottom:1px solid #000; padding:3px;">&nbsp;</td>
            <td style="border:1px solid #000; padding:3px; text-align:right; font-weight:bold;">{{ number_format($receipt->subtotal_non_vat, 2) }}</td>
            <td style="border:1px solid #000; padding:3px; text-align:right; font-weight:bold;">{{ number_format($receipt->subtotal_vat, 2) }}</td>
        </tr>
    </tbody>
</table>

<table style="width:100%; border-collapse:collapse; font-size:9.5pt;">
    <tr>
        <td style="width:68%; text-align:right; padding:3px; font-weight:bold;">จำนวนภาษีมูลค่าเพิ่ม ( VAT {{ rtrim(rtrim(number_format($receipt->vat_rate, 2), '0'), '.') }}% )</td>
        <td style="width:32%; border:1px solid #000; padding:3px; text-align:right; font-weight:bold;">{{ number_format($receipt->vat_amount, 2) }}</td>
    </tr>
    <tr>
        <td style="text-align:right; padding:3px; font-weight:bold;">รวมราคาทั้งสิ้น ( GRAND TOTAL )</td>
        <td style="border:1px solid #000; padding:3px; text-align:right; font-weight:bold;">{{ number_format($receipt->grand_total, 2) }}</td>
    </tr>
</table>

<div style="font-size:9pt; margin-top:10px;">
    <div>หมายเหตุ</div>
    <div style="margin-top:6px;">1.ในกรณีชำระโดยเช็ค กรุณาสั่งจ่ายเช็คในนามบริษัท เอ็มเอดีดี จำกัด</div>
    <div>2.บริษัทฯ ขอสงวนสิทธิ์ในการแก้ไขใบกำกับ ภายใน 15 วัน นับจากวันที่ระบุในใบกำกับภาษี ( ผิด ตก ยกเว้น E. &amp; OE. )</div>
    <div>3.รายการข้างต้นยังเป็นกรรมสิทธิ์ของ บริษัท เอ็มเอดีดี จำกัด จนกว่าผู้ซื้อจะชำระเป็นเงินเรียบร้อยแล้ว</div>
</div>

<table style="width:100%; border-collapse:collapse; font-size:9pt; margin-top:12px;">
    <tr>
        <td style="width:22%; vertical-align:top;">จำนวนเงินเป็นตัวอักษร<br/><span style="font-size:8pt;">GRAND TOTAL IN WORDS</span></td>
        <td style="border:1px solid #000; text-align:center; padding:6px; color:#00008B; font-weight:bold;">{{ $receipt->grand_total_words }}</td>
    </tr>
</table>

<table style="width:100%; border-collapse:collapse; font-size:9pt; margin-top:40px;">
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
        <td style="text-align:center; padding-top:8px;">ลายเซ็นต์ผู้ลงบัญชี<br/><span style="font-size:8pt;">Account Signature</span></td>
        <td></td>
        <td style="text-align:center; padding-top:8px;">ลายเซ็นต์ผู้มีอำนาจ<br/><span style="font-size:8pt;">Authorized Signature</span></td>
    </tr>
</table>

<table style="width:100%; border-collapse:collapse; font-size:8.5pt; margin-top:16px;">
    <tr>
        <td style="border:1px solid #000; text-align:center; padding:6px;">
            ใบกำกับภาษีนี้จะสมบูรณ์เมื่อมีลายเซ็นต์ของผู้ได้รับมอบอำนาจ และเช็คฉบับนี้เข้าบัญชีได้เรียบร้อยแล้ว<br/>
            <span style="font-size:8pt;">TAX INVOICE IS VALID ONLY WHEN SIGNED BY THE AUTHORIZED AND THE CHEQUE DULY CLEARED.</span>
        </td>
    </tr>
</table>
