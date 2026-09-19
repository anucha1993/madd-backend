{{-- Shared header block for both Tax Invoice and Receipt pages.
     Variables expected: $headOffice (Branch|null), $issuingBranch (Branch|null),
     $docTypeThai, $docTypeEnglish, $showFax (bool), $receipt (Receipt) --}}
<table style="width:100%; border-collapse:collapse;">
    <tr>
        <td style="width:65%; vertical-align:top; padding-bottom:4px;">
            <table style="border-collapse:collapse;">
                <tr>
                    <td style="width:110px; vertical-align:middle;">
                        @if($logoDataUri ?? null)
                            <img src="{{ $logoDataUri }}" style="width:130px;" />
                        @else
                            <div style="border:2px solid #000; padding:4px 8px; font-weight:bold; font-size:13pt; text-align:center;">
                                {{ $issuingBranch->code ?? 'MADD' }}
                            </div>
                        @endif
                    </td>
                    <td style="vertical-align:middle; padding-left:8px; font-size:12pt;">
                        <div style="font-weight:bold;">{{ $headOffice->company_name ?? '-' }}</div>
                        <div style="font-weight:bold;">{{ mb_strtoupper($headOffice->name ?? '-') }}</div>
                    </td>
                </tr>
            </table>
            <div style="font-size:9pt; margin-top:3px;">
                สำนักงานใหญ่ : {{ $headOffice->address ?? '-' }}
            </div>
            <div style="font-size:9pt;">
                &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;โทร : {{ $headOffice->phone ?? '-' }}
                @if($showFax && ($headOffice->fax ?? null))
                    &nbsp;&nbsp;โทรสาร : {{ $headOffice->fax }}
                @endif
            </div>
            @if($issuingBranch && ! $issuingBranch->is_head_office)
                <div style="font-size:9pt;">
                    สำนักงานสาขา : {{ $issuingBranch->address }}
                </div>
                <div style="font-size:9pt;">
                    &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;โทร : {{ $issuingBranch->phone }}
                    @if($showFax && $issuingBranch->fax)
                        &nbsp;&nbsp;โทรสาร : {{ $issuingBranch->fax }}
                    @endif
                </div>
            @endif
        </td>
        <td style="width:35%; vertical-align:top;">
            <table style="width:100%; border-collapse:collapse;">
                <tr>
                    <td style="border:1.5px solid #000; text-align:center; padding:8px; font-weight:bold; font-size:13pt;">
                        {{ $docTypeThai }}<br/>{{ $docTypeEnglish }}
                    </td>
                </tr>
            </table>
            <div style="font-size:9pt; text-align:center; margin-top:30px;">
                <br/>
                
                เลขประจำตัวผู้เสียภาษีอากร<br/>
                REGISTRATION No.<br/>
                <b>{{ $issuingBranch->tax_id ?? '-' }}</b>
            </div>
        </td>
    </tr>
</table>
<div style="text-align:center; color:#00008B; font-weight:bold; font-size:10pt; margin:3px 0;">
    สาขาที่ออกใบกำกับภาษี คือ สาขาที่ {{ $issuingBranch->code ?? '-' }}
</div>
<hr style="border:none; border-top:1px solid #000; margin:2px 0 4px;" />
