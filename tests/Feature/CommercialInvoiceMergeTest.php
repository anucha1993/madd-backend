<?php

namespace Tests\Feature;

use App\Http\Controllers\ShipmentController;
use App\Models\SystemAlert;
use App\Models\User;
use App\Services\R2Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Mpdf\Mpdf;
use ReflectionMethod;
use Tests\TestCase;

/** A PDF FPDI can't open (compressed cross-reference stream) must never cost the carrier's invoice. */
class CommercialInvoiceMergeTest extends TestCase
{
    use RefreshDatabase;

    private array $uploaded = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(R2Service::class, fn ($m) => $m->shouldReceive('upload')->andReturnUsing(function ($name, $content) {
            $this->uploaded[] = $content;

            return ['key' => 'k-'.count($this->uploaded)];
        }));
    }

    /** A one-page PDF 1.5 whose cross-reference table is an XRef stream — FPDI's free parser rejects it. */
    private function xrefStreamPdf(): string
    {
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] >>',
        ];
        $pdf = "%PDF-1.5\n";
        $offsets = [];
        foreach ($objects as $n => $body) {
            $offsets[$n] = strlen($pdf);
            $pdf .= "{$n} 0 obj\n{$body}\nendobj\n";
        }
        $offsets[4] = strlen($pdf);
        $rows = pack('CnC', 0, 0, 255);
        foreach ([1, 2, 3, 4] as $n) {
            $rows .= pack('CnC', 1, $offsets[$n], 0);
        }
        $pdf .= "4 0 obj\n<< /Type /XRef /Size 5 /W [1 2 1] /Root 1 0 R /Length ".strlen($rows)." >>\nstream\n{$rows}\nendstream\nendobj\n";

        return $pdf."startxref\n{$offsets[4]}\n%%EOF\n";
    }

    private function carrierPdf(): string
    {
        $mpdf = new Mpdf(['format' => 'A4']);
        $mpdf->WriteHTML('<p>Carrier invoice</p>');

        return $mpdf->Output('', 'S');
    }

    public function test_an_unreadable_upload_is_left_out_and_the_carrier_invoice_kept(): void
    {
        $method = new ReflectionMethod(ShipmentController::class, 'buildCombinedCommercialInvoiceStorageKey');
        $key = $method->invoke(app(ShipmentController::class), '1ZTEST', base64_encode($this->carrierPdf()), 'PDF', ['content' => base64_encode($this->xrefStreamPdf()), 'format' => 'PDF']);

        $this->assertNotNull($key);
        $this->assertStringStartsWith('%PDF', $this->uploaded[0]);
        $alert = SystemAlert::where('source', 'booking_document')->first();
        $this->assertStringContainsString('1ZTEST', $alert->message);
        $this->assertArrayHasKey('upload', $alert->context['failed']);
    }

    public function test_an_unreadable_pdf_is_refused_at_upload(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $method = new ReflectionMethod(ShipmentController::class, 'uploadCommercialInvoiceFile');

        $bad = UploadedFile::fake()->createWithContent('invoice.pdf', $this->xrefStreamPdf());
        $response = $method->invoke(app(ShipmentController::class), request()->duplicate(files: ['file' => $bad]));
        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame([], $this->uploaded);

        $good = UploadedFile::fake()->createWithContent('invoice.pdf', $this->carrierPdf());
        $this->assertSame(200, $method->invoke(app(ShipmentController::class), request()->duplicate(files: ['file' => $good]))->getStatusCode());
    }
}
