{{-- 80 mm thermal receipt for an over-the-counter box / supply sale (SupplySalePdfService). --}}
@php
    $voided = $sale->status === 'VOIDED';
@endphp
{{-- No company / branch header and no sale number — the receipt starts at its title (as asked). --}}
<div style="text-align:center;">
    <div style="font-size:11pt; font-weight:bold;">ใบเสร็จรับเงิน</div>
    @if ($voided)<div style="font-size:12pt; font-weight:bold;">*** ยกเลิก (VOID) ***</div>@endif
</div>

<table style="width:100%; font-size:7.5pt; margin-top:3px;">
    <tr><td style="text-align:right;">{{ $sale->sold_at->timezone('Asia/Bangkok')->format('d/m/Y H:i') }}</td></tr>
    @if ($sale->customer_name)<tr><td>ลูกค้า {{ $sale->customer_name }}</td></tr>@endif
    @if ($sale->customer_address)<tr><td>ที่อยู่ {{ $sale->customer_address }}</td></tr>@endif
    @if ($sale->customer_phone)<tr><td>โทร {{ $sale->customer_phone }}</td></tr>@endif
</table>

<div style="border-top:1px dashed #000; margin:3px 0;"></div>
<table style="width:100%; border-collapse:collapse; font-size:8pt;">
    @foreach ($sale->lines as $line)
        <tr><td colspan="2">{{ $line->name }}</td></tr>
        <tr>
            <td style="padding-left:6px; color:#333;">{{ $line->quantity }} x {{ number_format($line->unit_price, 2) }}</td>
            <td style="text-align:right;">{{ number_format($line->amount, 2) }}</td>
        </tr>
    @endforeach
</table>
<div style="border-top:1px dashed #000; margin:3px 0;"></div>

<table style="width:100%; font-size:10pt; font-weight:bold;">
    <tr><td>รวมทั้งสิ้น</td><td style="text-align:right;">{{ number_format($sale->total, 2) }}</td></tr>
</table>
<table style="width:100%; font-size:7.5pt;">
    <tr><td>จำนวน {{ $sale->lines->sum('quantity') }} ชิ้น</td><td style="text-align:right;">ราคารวมภาษีมูลค่าเพิ่มแล้ว</td></tr>
    @if ($sale->payment_method)<tr><td colspan="2">ชำระโดย {{ $sale->payment_method }}</td></tr>@endif
    @if ($sale->createdBy)<tr><td colspan="2">พนักงาน {{ $sale->createdBy->name }}</td></tr>@endif
</table>

<div style="text-align:center; font-size:8pt; margin-top:6px;">ขอบคุณที่ใช้บริการ</div>
