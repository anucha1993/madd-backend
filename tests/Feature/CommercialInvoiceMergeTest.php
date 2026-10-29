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
        $failures = [];
        $key = $method->invokeArgs(app(ShipmentController::class), ['1ZTEST', base64_encode($this->carrierPdf()), 'PDF', [['content' => base64_encode($this->xrefStreamPdf()), 'format' => 'PDF']], &$failures]);

        $this->assertNotNull($key);
        $this->assertStringStartsWith('%PDF', $this->uploaded[0]);
        // Reported back to the booking (which knows whether the carrier got the file) — not claimed here.
        $this->assertArrayHasKey('upload', $failures);
        $this->assertSame(0, SystemAlert::count());
    }

    public function test_any_pdf_is_accepted_at_upload_and_the_original_opens_from_the_shipment(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $controller = app(ShipmentController::class);

        $bad = UploadedFile::fake()->createWithContent('invoice.pdf', $this->xrefStreamPdf());
        $upload = (new ReflectionMethod(ShipmentController::class, 'uploadCommercialInvoiceFile'))->invoke($controller, request()->duplicate(files: ['file' => $bad]));
        $this->assertSame(200, $upload->getStatusCode());
        $this->assertSame($this->xrefStreamPdf(), $this->uploaded[0]);

        $this->mock(R2Service::class, fn ($m) => $m->shouldReceive('download')->with('manual-a.png')->andReturn('PNGDATA'));
        $shipment = new \App\Models\Shipment(['tracking_number' => '1ZTEST', 'commercial_invoice_upload_keys' => ['manual-x.pdf', 'manual-a.png']]);
        $response = app(ShipmentController::class)->invoiceUpload($shipment, 2);
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertSame('PNGDATA', $response->getContent());
        $this->assertSame(404, app(ShipmentController::class)->invoiceUpload($shipment, 3)->getStatusCode());
    }
}
