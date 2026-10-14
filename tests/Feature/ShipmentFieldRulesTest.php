<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShipmentFieldRulesTest extends TestCase
{
    use RefreshDatabase;

    private function as(array $permissions): void
    {
        $role = Role::create(['key' => 'r'.random_int(1, 99999), 'name' => 'R', 'permissions' => $permissions, 'field_access' => [], 'data_scopes' => ['shipment' => 'all']]);
        $user = User::factory()->create(['can_access_all_branches' => true]);
        $user->roles()->sync([$role->id]);
        Sanctum::actingAs($user);
    }

    private function booking(string $carrier, array $destination = []): array
    {
        return [
            'agent_account_id' => 999, 'carrier' => $carrier, 'service_code' => 'P',
            'origin' => ['postcode' => '10110', 'city' => 'Bangkok', 'address' => '1 Sukhumvit'],
            'destination' => $destination + ['country' => 'SG', 'city' => 'Singapore'],
            'packages' => [['weight' => 1, 'is_document' => true]],
            'invoice_lines' => [['description' => 'Gift', 'quantity' => 1, 'unit_value' => 10]],
            'freight_amount' => 100, 'addon_total' => 0, 'order_total' => 100,
        ];
    }

    public function test_admin_sets_required_fields_per_carrier(): void
    {
        $this->as(['config.shipment_fields']);
        $this->putJson('/api/shipment-field-rules', ['rules' => ['DHL' => ['destination.phone', 'destination.email'], 'UPS' => ['destination.phone']]])
            ->assertOk()
            ->assertJsonPath('rules.DHL', ['destination.phone', 'destination.email'])
            ->assertJsonPath('rules.UPS', ['destination.phone']);
        $this->putJson('/api/shipment-field-rules', ['rules' => ['UPS' => ['not.a.field']]])->assertStatus(422);

        $this->as(['shipment.create']); // the booking form can read them, not change them
        $this->getJson('/api/shipment-field-rules')->assertOk()->assertJsonPath('rules.UPS', ['destination.phone']);
        $this->putJson('/api/shipment-field-rules', ['rules' => ['UPS' => []]])->assertForbidden();
    }

    public function test_form_labels_can_be_renamed_and_reset(): void
    {
        $this->as(['config.shipment_fields']);
        $label = fn ($res, $key) => collect($res->json('fields'))->firstWhere('key', $key);

        $res = $this->putJson('/api/shipment-field-rules', ['rules' => [], 'labels' => ['destination.contact_name' => 'ชื่อผู้รับ', 'destination.city' => 'เมือง / City']])->assertOk();
        $this->assertSame('ชื่อผู้รับ', $label($res, 'destination.contact_name')['form_label']);
        $this->assertSame('Contact Name', $label($res, 'destination.contact_name')['default_form_label']);
        $this->assertSame('เมือง / City', $label($res, 'destination.city')['form_label']);   // label-only field
        $this->assertFalse($label($res, 'destination.city')['requirable']);

        // A label-only field can't be made required; blank label = back to the default.
        $this->putJson('/api/shipment-field-rules', ['rules' => ['UPS' => ['destination.city']]])->assertStatus(422);
        $res = $this->putJson('/api/shipment-field-rules', ['rules' => [], 'labels' => ['destination.contact_name' => '']])->assertOk();
        $this->assertSame('Contact Name', $label($res, 'destination.contact_name')['form_label']);
    }

    public function test_booking_is_rejected_until_the_carriers_fields_are_filled(): void
    {
        app(\App\Services\ShipmentFieldRules::class)->save(['DHL' => ['destination.email', 'packages.*.description'], 'UPS' => []]);
        $this->as(['shipment.create']);

        $res = $this->postJson('/api/shipments', $this->booking('DHL'))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['destination.email', 'packages.0.description']);
        $this->assertSame('DHL กำหนดให้กรอก: อีเมล (ผู้รับ)', $res->json('errors')['destination.email'][0]);

        // UPS has no extra rules: passes validation and only fails on the (missing) account.
        $this->postJson('/api/shipments', $this->booking('UPS'))->assertStatus(400);
    }
}
