{{-- Vol.No / No. / Date + Purchaser + Address + head-office/branch checkbox row.
     Variables: $receipt (Receipt, with buyer_* snapshot fields) --}}
<table style="width:100%; border-collapse:collapse; font-size:10pt; margin-bottom:6px;">
    <tr>
        <td style="width:20%; vertical-align:top;">
            <div>เล่มที่ <span style="color:#00008B; font-weight:bold;">{{ $receipt->vol_no }}</span></div>
            <div style="font-size:8pt;">Vol.No</div>
        </td>
        <td style="width:35%; vertical-align:top;">
            <div>เลขที่ <span style="color:#00008B; font-weight:bold;">{{ $receipt->no }}</span></div>
            <div style="font-size:8pt;">No.</div>
        </td>
        <td style="width:45%; vertical-align:top;">
            <div>วันที่ <span style="color:#00008B; font-weight:bold;">{{ $receipt->issued_date->format('d-M-y') }}</span></div>
            <div style="font-size:8pt;">Date</div>
        </td>
    </tr>
</table>
<table style="width:100%; border-collapse:collapse; font-size:10pt;">
    <tr>
        <td style="width:12%; vertical-align:top;">
            <div>นามผู้ซื้อ</div>
            <div style="font-size:8pt;">Purchaser</div>
        </td>
        {{-- English buyer fields render in FreeSerif (Times-like), not the Thai Garuda font. --}}
        <td style="width:48%; border-bottom:1px dotted #000; vertical-align:bottom; color:#00008B; font-weight:bold; font-family:freeserif;">
            {{ $receipt->buyer_name }}
        </td>
        <td style="width:18%; vertical-align:top; white-space:nowrap;">
            <div>เลขประจำตัวผู้เสียภาษี</div>
            <div style="font-size:8pt;">Tax ID.</div>
        </td>
        <td style="width:22%; border-bottom:1px dotted #000; vertical-align:bottom; color:#00008B; font-weight:bold; font-family:freeserif;">
            {{ $receipt->buyer_tax_id }}
        </td>
    </tr>
    <tr>
        <td style="vertical-align:top;">
            <div>ที่อยู่</div>
            <div style="font-size:8pt;">Address</div>
        </td>
        <td colspan="3" style="border-bottom:1px dotted #000; vertical-align:bottom; color:#00008B; font-weight:bold; font-family:freeserif;">
            {{ $receipt->buyer_address }}
        </td>
    </tr>
</table>
<table style="width:100%; border-collapse:collapse; font-size:10pt; margin:2px 0 4px;">
    <tr>
        <td style="width:50%;">
            {{-- Garuda (Thai font) has no checkmark glyph — dejavusans does, so switch font just for this glyph. --}}
            <span style="border:1.5px solid #000; display:inline-block; width:11px; height:11px; text-align:center; line-height:11px; font-size:9pt; font-weight:bold; font-family:dejavusans;">{!! $receipt->buyer_is_head_office ? '&#10003;' : '' !!}</span>
            &nbsp;สำนักงานใหญ่
        </td>
        <td style="width:50%;">
            <span style="border:1.5px solid #000; display:inline-block; width:11px; height:11px; text-align:center; line-height:11px; font-size:9pt; font-weight:bold; font-family:dejavusans;">{!! ! $receipt->buyer_is_head_office ? '&#10003;' : '' !!}</span>
            &nbsp;สาขาลำดับที่ {{ $receipt->buyer_branch_no ?: '.....' }}
        </td>
    </tr>
</table>
