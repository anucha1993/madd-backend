{{-- 80 mm thermal receipt for an over-the-counter box / supply sale (SupplySalePdfService). --}}
@php
    $company = $branch->company_name ?: $headOffice?->company_name;
    $taxId = $branch->tax_id ?: $headOffice?->tax_id;
    $voided = $sale->status === 'VOIDED';
    // Branch `name` holds the company's Thai legal name; the branch itself is its code + nickname.
    $code = in_array($branch->code, [null, '', 'None'], true) ? null : $branch->code;
    $branchLine = $branch->is_head_office
        ? 'สำนักงานใหญ่'
        : trim(($code ? "สาขาที่ {$code}" : 'สาขา').($branch->nickname ? " ({$branch->nickname})" : ''));
@endphp
<div style="text-align:center;">
    @if ($company)<div style="font-size:10pt; font-weight:bold;">{{ $company }}</div>@endif
    @if ($branch->name && $branch->name !== $company)<div style="font-size:8pt;">{{ $branch->name }}</div>@endif
    <div style="font-size:8pt;">{{ $branchLine }}</div>
    @if ($branch->address)<div style="font-size:7pt;">{{ $branch->address }}</div>@endif
    @if ($branch->phone)<div style="font-size:7pt;">โทร {{ $branch->phone }}</div>@endif
    @if ($taxId)<div style="font-size:7pt;">เลขประจำตัวผู้เสียภาษี {{ $taxId }}</div>@endif
    <div style="font-size:11pt; font-weight:bold; margin-top:4px;">ใบเสร็จรับเงิน</div>
    @if ($voided)<div style="font-size:12pt; font-weight:bold;">*** ยกเลิก (VOID) ***</div>@endif
</div>

<table style="width:100%; font-size:7.5pt; margin-top:3px;">
    <tr><td>เลขที่ {{ $sale->sale_no }}</td><td style="text-align:right;">{{ $sale->sold_at->timezone('Asia/Bangkok')->format('d/m/Y H:i') }}</td></tr>
    @if ($sale->customer_name)<tr><td colspan="2">ลูกค้า {{ $sale->customer_name }}</td></tr>@endif
    @if ($sale->customer_address)<tr><td colspan="2">ที่อยู่ {{ $sale->customer_address }}</td></tr>@endif
    @if ($sale->customer_phone)<tr><td colspan="2">โทร {{ $sale->customer_phone }}</td></tr>@endif
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
