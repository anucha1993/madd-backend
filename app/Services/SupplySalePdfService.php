<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\SupplySale;
use Mpdf\Mpdf;

class SupplySalePdfService
{
    private const WIDTH_MM = 80;

    /**
     * 80 mm thermal-paper receipt. Rendered once on a tall sheet to measure where the content ends,
     * then again on a page cut to that length — so the printer doesn't feed blank paper.
     */
    public function render(SupplySale $sale): string
    {
        $sale->loadMissing('lines', 'branch', 'createdBy:id,name');
        $html = view('supply-sales.receipt', [
            'sale' => $sale,
            'branch' => $sale->branch,
            'headOffice' => Branch::where('is_head_office', true)->first() ?? $sale->branch,
        ])->render();

        $measure = $this->mpdf(1000);
        $measure->WriteHTML($html);
        $height = (int) ceil($measure->y) + 6;

        $mpdf = $this->mpdf(max(60, $height));
        $mpdf->WriteHTML($html);

        return $mpdf->Output('', 'S');
    }

    private function mpdf(int $heightMm): Mpdf
    {
        return new Mpdf([
            'mode' => 'th',
            'format' => [self::WIDTH_MM, $heightMm],
            'default_font' => 'garuda',
            'default_font_size' => 8,
            'margin_top' => 3,
            'margin_bottom' => 0,
            'margin_left' => 4,
            'margin_right' => 4,
            'margin_header' => 0,
            'margin_footer' => 0,
        ]);
    }
}
