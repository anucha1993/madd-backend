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

/**
 * Uploaded PDFs with a compressed cross-reference (which FPDI's free parser can't open) are rewritten
 * and merged; a file that still can't be read must never cost the carrier's invoice.
 */
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

    /** PDF 1.5 with catalog / pages / page packed in a compressed object stream (/Type /ObjStm). */
    private function objectStreamPdf(): string
    {
        $members = [1 => '<< /Type /Catalog /Pages 2 0 R >>', 2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>', 3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 300 150] /Contents 4 0 R >>'];
        $header = '';
        $data = '';
        foreach ($members as $n => $body) {
            $header .= "{$n} ".strlen($data).' ';
            $data .= $body."\n";
        }
        $packed = gzcompress($header.$data);
        $content = 'BT /F1 12 Tf 10 10 Td (Hi) Tj ET';
        $pdf = "%PDF-1.5\n";
        $offsets = [4 => strlen($pdf)];
        $pdf .= "4 0 obj\n<< /Length ".strlen($content)." >>\nstream\n{$content}\nendstream\nendobj\n";
        $offsets[5] = strlen($pdf);
        $pdf .= "5 0 obj\n<< /Type /ObjStm /N 3 /First ".strlen($header).' /Filter /FlateDecode /Length '.strlen($packed)." >>\nstream\n{$packed}\nendstream\nendobj\n";
        $offsets[6] = strlen($pdf);
        $rows = pack('CnC', 0, 0, 255);
        foreach ([1, 2, 3] as $i => $n) {
            $rows .= pack('CnC', 2, 5, $i);
        }
        $rows .= pack('CnC', 1, $offsets[4], 0).pack('CnC', 1, $offsets[5], 0).pack('CnC', 1, $offsets[6], 0);
        $pdf .= "6 0 obj\n<< /Type /XRef /Size 7 /W [1 2 1] /Root 1 0 R /Length ".strlen($rows)." >>\nstream\n{$rows}\nendstream\nendobj\n";

        return $pdf."startxref\n{$offsets[6]}\n%%EOF\n";
    }

    private function merge(array $uploads, array &$failures): ?string
    {
        $method = new ReflectionMethod(ShipmentController::class, 'buildCombinedCommercialInvoiceStorageKey');
        $files = array_map(fn ($pdf) => ['content' => base64_encode($pdf), 'format' => 'PDF'], $uploads);

        return $method->invokeArgs(app(ShipmentController::class), ['1ZTEST', base64_encode($this->carrierPdf()), 'PDF', $files, &$failures]);
    }

    private function pageCount(string $pdf): int
    {
        return preg_match_all('#/Type\s*/Page\b#', $pdf);
    }

    private function carrierPdf(): string
    {
        $mpdf = new Mpdf(['format' => 'A4']);
        $mpdf->WriteHTML('<p>Carrier invoice</p>');

        return $mpdf->Output('', 'S');
    }

    public function test_compressed_xref_uploads_are_rewritten_and_merged(): void
    {
        $failures = [];
        $key = $this->merge([$this->xrefStreamPdf(), $this->objectStreamPdf()], $failures);

        $this->assertNotNull($key);
        $this->assertSame([], $failures);
        $this->assertSame(3, $this->pageCount($this->uploaded[0])); // carrier + both uploads
    }

    public function test_a_large_photo_is_merged_on_one_page(): void
    {
        // A noisy 2000×2600 photo: far past mpdf's pcre.backtrack_limit as a data-URI <img>.
        $image = imagecreatetruecolor(2000, 2600);
        for ($i = 0; $i < 60000; $i++) {
            [$x, $y] = [random_int(0, 1990), random_int(0, 2590)];
            imagefilledrectangle($image, $x, $y, $x + random_int(1, 9), $y + random_int(1, 9), random_int(0, 0xFFFFFF));
        }
        ob_start();
        imagejpeg($image, null, 95);
        $jpeg = ob_get_clean();
        $this->assertGreaterThan(800_000, strlen($jpeg));

        $failures = [];
        $method = new ReflectionMethod(ShipmentController::class, 'buildCombinedCommercialInvoiceStorageKey');
        $method->invokeArgs(app(ShipmentController::class), ['1ZTEST', base64_encode($this->carrierPdf()), 'PDF', [['content' => base64_encode($jpeg), 'format' => 'JPG']], &$failures]);

        $this->assertSame([], $failures);
        $this->assertSame(2, $this->pageCount($this->uploaded[0])); // no blank page in between
    }

    public function test_an_unreadable_upload_is_left_out_and_the_carrier_invoice_kept(): void
    {
        $failures = [];
        $key = $this->merge(["%PDF-1.5
not really a pdf
startxref
9
%%EOF
"], $failures);

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
