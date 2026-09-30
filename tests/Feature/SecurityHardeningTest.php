<?php

namespace Tests\Feature;

use App\Casts\CarrierSecret;
use App\Http\Controllers\ShipmentController;
use App\Models\AgentAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function account(): AgentAccount
    {
        $agentId = DB::table('agents')->insertGetId(['agent_name' => 'DHL', 'agent_code' => 'DHL', 'created_at' => now(), 'updated_at' => now()]);

        return AgentAccount::create(['agent_id' => $agentId, 'username_acc' => '1', 'basic_auth_username' => 'u', 'basic_auth_password' => 'plain-pass', 'client_secret' => 'plain-secret']);
    }

    public function test_carrier_secrets_stay_plaintext_until_encryption_is_enabled(): void
    {
        config(['services.carrier_secrets.encrypt' => false]);
        $account = $this->account();

        $this->assertSame('plain-pass', DB::table('agent_accounts')->value('basic_auth_password'));
        $this->assertSame('plain-pass', $account->fresh()->basic_auth_password);
        $this->artisan('agent-accounts:encrypt-secrets')->assertFailed();
    }

    public function test_encrypt_command_and_cast_read_both_formats(): void
    {
        config(['services.carrier_secrets.encrypt' => false]);
        $account = $this->account(); // legacy row: written as plaintext
        config(['services.carrier_secrets.encrypt' => true]);

        $this->artisan('agent-accounts:encrypt-secrets')->expectsOutput('Encrypted 2 value(s).')->assertSuccessful();
        $raw = DB::table('agent_accounts')->first();
        $this->assertTrue(CarrierSecret::isEncrypted($raw->basic_auth_password));
        $this->assertSame('plain-pass', Crypt::decryptString($raw->basic_auth_password));
        $this->assertSame('plain-pass', $account->fresh()->basic_auth_password);
        $this->assertSame('plain-secret', $account->fresh()->client_secret);

        // Re-running is a no-op; new writes are encrypted too.
        $this->artisan('agent-accounts:encrypt-secrets')->expectsOutput('Encrypted 0 value(s).');
        $account->update(['basic_auth_password' => 'new-pass']);
        $this->assertNotSame('new-pass', DB::table('agent_accounts')->value('basic_auth_password'));
        $this->assertSame('new-pass', $account->fresh()->basic_auth_password);
        // Never serialized.
        $this->assertArrayNotHasKey('basic_auth_password', $account->fresh()->toArray());
    }

    public function test_invoice_line_quantity_must_be_a_whole_number(): void
    {
        $admin = \App\Models\User::factory()->create();
        $admin->roles()->sync(\App\Models\Role::where('key', 'admin')->pluck('id'));
        \Laravel\Sanctum\Sanctum::actingAs($admin);

        $line = ['description' => 'T-shirt', 'unit_value' => 50];
        $this->postJson('/api/shipments', ['invoice_lines' => [$line + ['quantity' => 1.5]]])
            ->assertJsonValidationErrors('invoice_lines.0.quantity');
        $this->postJson('/api/shipments', ['invoice_lines' => [$line + ['quantity' => 2]]])
            ->assertJsonMissingValidationErrors('invoice_lines.0.quantity');
    }

    public function test_locked_addon_and_supply_prices_are_checked_on_booking(): void
    {
        $check = new ReflectionMethod(ShipmentController::class, 'checkSellTotals');
        $controller = app(ShipmentController::class);
        $categoryId = DB::table('addon_categories')->insertGetId(['name' => 'Service', 'created_at' => now(), 'updated_at' => now()]);
        $item = \App\Models\AddonItem::create(['addon_category_id' => $categoryId, 'name' => 'Signature', 'carriers' => ['DHL'], 'price_type' => 'FIXED', 'price' => 50, 'markup_percent' => 10, 'trigger_type' => 'MANUAL', 'status' => true]);
        $supply = \App\Models\Supply::create(['name' => 'Box S', 'type' => 'BOX', 'sale_price' => 30, 'status' => true]);
        $base = ['carrier' => 'DHL', 'freight_amount' => 0];
        $booking = fn (array $lines) => $base + ['addon_lines' => $lines, 'addon_total' => collect($lines)->sum(fn ($l) => $l['quantity'] * $l['unit_price']), 'order_total' => collect($lines)->sum(fn ($l) => $l['quantity'] * $l['unit_price'])];

        $this->assertNull($check->invoke($controller, $booking([['name' => 'Signature', 'addon_item_id' => $item->id, 'quantity' => 1, 'unit_price' => 50]]), null));
        $this->assertNull($check->invoke($controller, $booking([['name' => 'Signature', 'addon_item_id' => $item->id, 'quantity' => 1, 'unit_price' => 55]]), null)); // + markup
        $this->assertNotNull($check->invoke($controller, $booking([['name' => 'Signature', 'addon_item_id' => $item->id, 'quantity' => 1, 'unit_price' => 1]]), null));
        $this->assertNull($check->invoke($controller, $booking([['name' => 'Box S', 'supply_id' => $supply->id, 'quantity' => 2, 'unit_price' => 30]]), null));
        $this->assertNotNull($check->invoke($controller, $booking([['name' => 'Box S', 'supply_id' => $supply->id, 'quantity' => 2, 'unit_price' => 5]]), null));
        // Custom / MANUAL lines (no catalog id) stay free-priced.
        $this->assertNull($check->invoke($controller, $booking([['name' => 'Other', 'quantity' => 1, 'unit_price' => 123]]), null));
    }

    public function test_sell_totals_are_rederived_on_booking(): void
    {
        $check = new ReflectionMethod(ShipmentController::class, 'checkSellTotals');
        $controller = app(ShipmentController::class);
        $quote = ['carrier' => 'DHL', 'negotiated' => 1200.0, 'chargeBreakdown' => [['code' => 'BASE', 'amount' => 1000], ['code' => 'II', 'amount' => 200]]];
        $lines = [['quantity' => 1, 'unit_price' => 250], ['quantity' => 2, 'unit_price' => 25]];
        $ok = ['carrier' => 'DHL', 'addon_lines' => $lines, 'freight_amount' => 1000, 'addon_total' => 300, 'order_total' => 1300];

        $this->assertNull($check->invoke($controller, $ok, $quote));
        // Freight lowered in the browser.
        $this->assertNotNull($check->invoke($controller, ['freight_amount' => 900, 'order_total' => 1200] + $ok, $quote));
        // Add-on total not matching its lines.
        $this->assertNotNull($check->invoke($controller, ['addon_total' => 100, 'order_total' => 1100] + $ok, $quote));
        // Order total not freight + add-on.
        $this->assertNotNull($check->invoke($controller, ['order_total' => 1000] + $ok, $quote));
        // No server-side quote (expired, cost-visible user): only internal consistency is checked.
        $this->assertNull($check->invoke($controller, ['freight_amount' => 900, 'order_total' => 1200] + $ok, null));
    }
}
