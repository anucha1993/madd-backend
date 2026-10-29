<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\User;
use App\Services\DhlShipmentService;
use App\Services\R2Service;
use App\Services\RateQuoteVault;
use App\Services\UpsShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Purpose of shipment, invoice currency, unit of measure, receiver Tax ID and several uploaded
 * invoice files — booked through POST /shipments (carrier mocked), then the exact shipment array
 * the controller hands over is run through the REAL DHL and UPS request builders.
 */
class CommercialInvoiceDetailsTest extends TestCase
{
    use RefreshDatabase;

    private ?array $sent = null;

    private ?array $warnings = null;

    private User $staff;

    private int $account;

    protected function setUp(): void
    {
        parent::setUp();
        $branch = Branch::create(['name' => 'Palladium', 'code' => 'PLD', 'status' => true]);
        $agent = DB::table('agents')->insertGetId(['agent_name' => 'DHL', 'agent_code' => 'DHL', 'created_at' => now(), 'updated_at' => now()]);
        $this->account = DB::table('agent_accounts')->insertGetId(['agent_id' => $agent, 'username_acc' => '560634572', 'status' => true, 'is_api_enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->staff = User::factory()->create(['branch_id' => $branch->id]);
        $this->staff->roles()->sync(Role::where('key', 'staff')->pluck('id'));
        $this->staff->branches()->sync([$branch->id]);
        Sanctum::actingAs($this->staff);

        $this->mock(R2Service::class, function ($m) {
            $m->shouldReceive('upload')->andReturn(['key' => 'k']);
            $m->shouldReceive('download')->andReturn('%PDF-1.4 fake');
        });
        $this->mock(DhlShipmentService::class, fn ($m) => $m->shouldReceive('createShipment')->andReturnUsing(function (array $account, array $shipment) {
            $this->sent = $shipment;

            return ['trackingNumber' => 'TEST123', 'pieces' => [['trackingNumber' => 'P1']], 'raw' => [], 'documentResults' => [['sent' => true, 'error' => null], ['sent' => true, 'error' => null]]];
        }));
    }

    private function book(): Shipment
    {
        $quote = ['carrier' => 'DHL', 'accountId' => $this->account, 'serviceCode' => 'P', 'currency' => 'THB', 'negotiated' => 1000, 'costNegotiated' => 900, 'chargeBreakdown' => [['code' => 'BASE', 'amount' => 1000]]];
        $quote['quoteId'] = app(RateQuoteVault::class)->remember($quote, $this->staff);

        $response = $this->postJson('/api/shipments', [
            'agent_account_id' => $this->account, 'carrier' => 'DHL', 'service_code' => 'P',
            'origin' => ['contact_name' => 'Somchai', 'postcode' => '10110', 'city' => 'Bangkok', 'address' => '123 Test Road', 'phone' => '0800000000'],
            'destination' => ['contact_name' => 'Receiver', 'country' => 'GB', 'city' => 'LONDON', 'postcode' => 'SW1A 1AA', 'address' => '1 Test St', 'phone' => '2000000000', 'tax_id' => 'GB123456789000', 'tax_id_type' => 'EOR'],
            'packages' => [['weight' => 2, 'length' => 10, 'width' => 10, 'height' => 10, 'quantity' => 1, 'is_document' => false]],
            'invoice_lines' => [['description' => 'Silk scarf', 'quantity' => 3, 'unit_value' => 20, 'unit' => 'PRS', 'weight' => 0.5, 'country_of_origin' => 'TH']],
            'export_reason' => 'GIFT',
            'invoice_currency' => 'usd',
            'commercial_invoice_upload_keys' => ['inv-a.pdf', 'inv-b.png'],
            'freight_amount' => 1000, 'addon_total' => 0, 'order_total' => 1000, 'currency' => 'THB',
            'rate_quote' => $quote,
        ])->assertOk();
        $this->warnings = $response->json('upload_warnings');

        return Shipment::find($response->json('id'));
    }

    public function test_the_details_reach_both_carriers_requests_and_are_kept_on_the_shipment(): void
    {
        $shipment = $this->book();
        $this->assertSame('GIFT', $shipment->export_reason);
        $this->assertSame('USD', $shipment->invoice_currency);
        $this->assertSame(['inv-a.pdf', 'inv-b.png'], $shipment->commercial_invoice_upload_keys);
        $this->assertSame('EOR', $shipment->destination['tax_id_type']);
        $this->assertCount(2, $this->sent['uploadedInvoices']);
        // Both reached DHL; the fake files aren't real PDFs, so neither is in our merged copy — and it says so.
        $this->assertSame([true, true], array_column($shipment->invoice_upload_status, 'sent_to_carrier'));
        $this->assertSame([false, false], array_column($shipment->invoice_upload_status, 'in_merged_pdf'));
        $this->assertCount(2, $this->warnings);
        $this->assertStringContainsString('ไฟล์แนบ 1 ถึง DHL แล้ว แต่รวมเข้า PDF', $this->warnings[0]);

        $dhl = (new ReflectionMethod(DhlShipmentService::class, 'buildShipmentRequest'))->invoke(app()->make(DhlShipmentService::class, []), ['username_acc' => '560634572'], $this->sent);
        $this->assertSame('gift', $dhl['content']['exportDeclaration']['exportReasonType']);
        $this->assertSame('USD', $dhl['content']['declaredValueCurrency']);
        $this->assertEquals(60, $dhl['content']['declaredValue']);
        $this->assertSame('PRS', $dhl['content']['exportDeclaration']['lineItems'][0]['quantity']['unitOfMeasurement']);
        $this->assertSame('USD', $dhl['content']['exportDeclaration']['lineItems'][0]['priceCurrency']);
        $this->assertSame([['typeCode' => 'EOR', 'number' => 'GB123456789000', 'issuerCountryCode' => 'GB']], $dhl['customerDetails']['receiverDetails']['registrationNumbers']);
        $this->assertCount(2, $dhl['documentImages']);
        $this->assertSame('PNG', $dhl['documentImages'][1]['imageFormat']);

        $ups = (new ReflectionMethod(UpsShipmentService::class, 'buildShipmentRequest'))->invoke(new UpsShipmentService(), ['username_acc' => 'AX3173'], $this->sent);
        $node = $ups['ShipmentRequest']['Shipment'];
        $forms = $node['ShipmentServiceOptions']['InternationalForms'];
        $this->assertSame('GIFT', $forms['ReasonForExport']);
        $this->assertSame('USD', $forms['CurrencyCode']);
        $this->assertSame('PRS', $forms['Product'][0]['Unit']['UnitOfMeasurement']['Code']);
        $this->assertSame(['CurrencyCode' => 'USD', 'MonetaryValue' => '60'], $node['InvoiceLineTotal']);
        $this->assertSame('GB123456789000', $node['ShipTo']['TaxIdentificationNumber']);
    }

    public function test_a_ups_paperless_failure_is_reported_per_file_not_hidden(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'paperlessdocuments/v2/upload')) {
                return Http::response(['response' => ['errors' => [['message' => 'Invalid document']]]], 400);
            }

            return Http::response(['ShipmentResponse' => ['ShipmentResults' => [
                'ShipmentIdentificationNumber' => '1ZTEST',
                'PackageResults' => ['TrackingNumber' => '1ZTEST', 'ShippingLabel' => ['GraphicImage' => 'R0lG', 'ImageFormat' => ['Code' => 'GIF']]],
            ]]]);
        });
        $shipment = [
            'from' => ['contactName' => 'A', 'company' => null, 'country' => 'TH', 'city' => 'Bangkok', 'postcode' => '10110', 'address' => 'x', 'phone' => '1'],
            'to' => ['contactName' => 'B', 'company' => null, 'country' => 'US', 'city' => 'NY', 'stateCode' => 'NY', 'postcode' => '10001', 'address' => 'y', 'phone' => '2'],
            'packages' => [['weight' => 1, 'length' => 10, 'width' => 10, 'height' => 10, 'quantity' => 1, 'isDocument' => false]],
            'declaredValueCurrency' => 'THB', 'serviceCode' => '65', 'refInvoiceNo' => null, 'description' => null,
            'invoiceLines' => [['description' => 'Shirt', 'quantity' => 1, 'unit_value' => 100, 'country_of_origin' => 'TH']],
            'uploadedInvoices' => [['content' => base64_encode('%PDF'), 'format' => 'PDF']],
        ];

        $result = (new UpsShipmentService())->createShipment('token', ['username_acc' => 'AX3173'], $shipment, 'test');

        $this->assertSame('1ZTEST', $result['trackingNumber']);
        $this->assertFalse($result['documentResults'][0]['sent']);
        $this->assertStringContainsString('Invalid document', $result['documentResults'][0]['error']);
    }

    public function test_without_the_new_fields_the_requests_stay_as_before(): void
    {
        $shipment = [
            'from' => ['contactName' => 'A', 'company' => null, 'country' => 'TH', 'city' => 'Bangkok', 'postcode' => '10110', 'address' => 'x', 'phone' => '1'],
            'to' => ['contactName' => 'B', 'company' => null, 'country' => 'US', 'city' => 'NY', 'stateCode' => 'NY', 'postcode' => '10001', 'address' => 'y', 'phone' => '2'],
            'packages' => [['weight' => 1, 'length' => 10, 'width' => 10, 'height' => 10, 'quantity' => 1, 'isDocument' => false, 'declaredValue' => 500, 'useCarrierInsurance' => true]],
            'declaredValueCurrency' => 'THB', 'serviceCode' => 'P', 'refInvoiceNo' => 'INV1', 'description' => null,
            'invoiceLines' => [['description' => 'Shirt', 'quantity' => 1, 'unit_value' => 100, 'country_of_origin' => 'TH']],
        ];
        $dhl = (new ReflectionMethod(DhlShipmentService::class, 'buildShipmentRequest'))->invoke(app()->make(DhlShipmentService::class, []), ['username_acc' => '1'], $shipment);
        $this->assertSame('permanent', $dhl['content']['exportDeclaration']['exportReasonType']);
        $this->assertSame('THB', $dhl['content']['declaredValueCurrency']);
        $this->assertSame('PCS', $dhl['content']['exportDeclaration']['lineItems'][0]['quantity']['unitOfMeasurement']);
        $this->assertArrayNotHasKey('registrationNumbers', $dhl['customerDetails']['receiverDetails']);
        $this->assertArrayNotHasKey('documentImages', $dhl);
        $this->assertContains(['serviceCode' => 'II', 'value' => 500.0, 'currency' => 'THB'], $dhl['valueAddedServices']);

        $ups = (new ReflectionMethod(UpsShipmentService::class, 'buildShipmentRequest'))->invoke(new UpsShipmentService(), ['username_acc' => 'AX3173'], $shipment);
        $this->assertSame('SALE', $ups['ShipmentRequest']['Shipment']['ShipmentServiceOptions']['InternationalForms']['ReasonForExport']);
        $this->assertArrayNotHasKey('TaxIdentificationNumber', $ups['ShipmentRequest']['Shipment']['ShipTo']);
    }
}
