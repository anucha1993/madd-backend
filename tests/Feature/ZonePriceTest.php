<?php

namespace Tests\Feature;

use App\Models\ChargeCode;
use App\Models\ChargeFixedOverride;
use App\Models\Country;
use App\Models\Role;
use App\Models\User;
use App\Models\ZonePrice;
use App\Services\ChargeMarkupService;
use App\Support\SimpleSheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ZonePriceTest extends TestCase
{
    use RefreshDatabase;

    private function actingWith(array $permissions): void
    {
        $role = Role::create(['key' => 'r'.random_int(1, 99999), 'name' => 'R', 'permissions' => $permissions, 'field_access' => [], 'data_scopes' => []]);
        $user = User::factory()->create();
        $user->roles()->sync([$role->id]);
        Sanctum::actingAs($user);
    }

    private function xlsx(array $rows): UploadedFile
    {
        $book = new Spreadsheet();
        $book->getActiveSheet()->fromArray($rows, null, 'A1', true);
        $path = tempnam(sys_get_temp_dir(), 'zp').'.xlsx';
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, 'upload.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function countries(): void
    {
        foreach ([['JP', 'Japan'], ['TW', 'Taiwan'], ['KR', 'South Korea'], ['US', 'United States']] as [$iso2, $name]) {
            Country::create(['iso2' => $iso2, 'name' => $name, 'status' => true]);
        }
    }

    public function test_zone_and_price_uploads_need_the_permission(): void
    {
        $this->actingWith(['config.countries']);
        $this->getJson('/api/zone-prices')->assertForbidden();
        $this->postJson('/api/country-zones/import')->assertForbidden();
    }

    public function test_zone_upload_updates_only_listed_columns_and_reports_unknown_countries(): void
    {
        $this->countries();
        Country::where('iso2', 'US')->update(['dhl_zone' => '7']);
        $this->actingWith(['config.zone_prices']);

        $this->post('/api/country-zones/import', ['file' => $this->xlsx([
            ['iso2', 'country', 'ups_zone'],
            ['JP', 'Japan', 1],
            ['tw', 'Taiwan', '1'],
            ['KR', 'South Korea', ''],
            ['XX', 'Nowhere', '3'],
        ])])->assertOk()->assertJsonPath('updated', 2)->assertJsonPath('unknown', ['XX']);

        $this->assertSame('1', Country::where('iso2', 'JP')->value('ups_zone')); // 1.0 from Excel → "1"
        $this->assertSame('1', Country::where('iso2', 'TW')->value('ups_zone'));
        $this->assertNull(Country::where('iso2', 'KR')->value('ups_zone'));
        $this->assertSame('7', Country::where('iso2', 'US')->value('dhl_zone')); // no dhl_zone column → untouched

        $this->get('/api/country-zones/export')->assertOk()->assertDownload('country-zones.xlsx');
    }

    public function test_price_upload_validates_rows_and_replaces_that_carriers_prices(): void
    {
        $this->countries();
        ZonePrice::create(['carrier' => 'UPS', 'charge_code' => '999', 'zone' => '9', 'price' => 1]);
        ZonePrice::create(['carrier' => 'DHL', 'charge_code' => 'OF', 'zone' => '1', 'price' => 50]);
        $this->actingWith(['config.zone_prices']);

        $header = ['carrier', 'charge_code', 'zone', 'country_iso2', 'price', 'note'];
        $this->post('/api/zone-prices/import', ['file' => $this->xlsx([$header, ['UPS', 190, 1, 'JP', 100, null]])])
            ->assertStatus(422); // both zone and country on one row

        $this->post('/api/zone-prices/import', ['file' => $this->xlsx([
            $header,
            ['UPS', 190, 1, null, 300, 'Asia'],
            ['ups', '190', null, 'tw', 450, 'ไต้หวันแพงกว่า'],
        ])])->assertOk()->assertJsonPath('imported', 2);

        $this->assertSame(0, ZonePrice::where('charge_code', '999')->count()); // replaced
        $this->assertSame(1, ZonePrice::where('carrier', 'DHL')->count()); // other carrier untouched
        Country::whereIn('iso2', ['JP', 'TW'])->update(['ups_zone' => '1']);
        $this->assertSame(['zone' => '1', 'prices' => ['190' => 300.0]], ZonePrice::lookup('UPS', 'JP'));
        $this->assertSame(['zone' => '1', 'prices' => ['190' => 450.0]], ZonePrice::lookup('UPS', 'TW'));
    }

    public function test_fixed_charge_uses_zone_price_and_falls_back_to_the_api_amount(): void
    {
        $this->countries();
        Country::whereIn('iso2', ['JP', 'TW'])->update(['ups_zone' => '1']);
        ZonePrice::create(['carrier' => 'UPS', 'charge_code' => '434', 'zone' => '1', 'price' => 300]);
        ZonePrice::create(['carrier' => 'UPS', 'charge_code' => '434', 'country_iso2' => 'TW', 'price' => 450]);

        $agentId = DB::table('agents')->insertGetId(['agent_name' => 'UPS', 'agent_code' => 'UPS', 'created_at' => now(), 'updated_at' => now()]);
        $accountId = DB::table('agent_accounts')->insertGetId(['agent_id' => $agentId, 'username_acc' => '1', 'created_at' => now(), 'updated_at' => now()]);
        $surge = ChargeCode::updateOrCreate(['provider' => 'UPS', 'code' => '434'], ['label' => 'SURGE FEE COMMERCIAL']);
        ChargeFixedOverride::create(['agent_account_id' => $accountId, 'charge_code_id' => $surge->id, 'override_type' => 'FORMULA', 'formula' => '{ZONE_PRICE}', 'fixed_amount' => 0, 'status' => true]);

        $quote = ['accountId' => $accountId, 'carrier' => 'UPS', 'negotiated' => 1037.0, 'chargeBreakdown' => [
            ['code' => 'BASE', 'description' => 'Base Freight', 'amount' => 1000.0, 'currency' => 'THB'],
            ['code' => '434', 'description' => 'Surge Fee Commercial', 'amount' => 37.0, 'currency' => 'THB'],
        ]];
        $surgeFor = fn (?string $country) => collect(app(ChargeMarkupService::class)->applyToResults([$quote], [], $country)[0]['chargeBreakdown'])->firstWhere('code', '434')['amount'];

        $this->assertSame(300.0, $surgeFor('JP')); // zone 1 price
        $this->assertSame(450.0, $surgeFor('TW')); // its own country price beats the zone
        $this->assertSame(37.0, $surgeFor('US')); // no zone / price → carrier's API amount
        $this->assertSame(37.0, $surgeFor(null));
    }

    public function test_sheet_reader_keeps_header_mapping(): void
    {
        $file = $this->xlsx([[' ISO2 ', 'UPS_ZONE'], ['JP', 2], [null, null]]);
        [$header, $rows] = SimpleSheet::read($file->getRealPath());

        $this->assertSame(['iso2', 'ups_zone'], $header);
        $this->assertCount(1, $rows);
    }
}
