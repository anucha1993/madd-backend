<?php

namespace Database\Seeders;

use App\Models\ManifestOption;
use Illuminate\Database\Seeder;

class ManifestOptionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Seed data source: "MADD sytems 2026 OK_9sep2026.xlsx" sheet "คำที่ใช้ในฟอร์ม manifest".
     * `provider` is null when an option applies to both UPS and DHL manifests.
     */
    public function run(): void
    {
        $rows = [
            // Customer Type — "Shop CR" uses a different short code per carrier (UPS=CR, DHL=SCR).
            ['group' => 'customer_type', 'provider' => null, 'name' => 'DAILY', 'code' => 'DAILY'],
            ['group' => 'customer_type', 'provider' => 'UPS', 'name' => 'Shop CR', 'code' => 'CR'],
            ['group' => 'customer_type', 'provider' => 'DHL', 'name' => 'Shop CR', 'code' => 'SCR'],
            ['group' => 'customer_type', 'provider' => null, 'name' => 'WI', 'code' => 'WI'],

            // Payment Option — same codes across both carriers.
            ['group' => 'payment_option', 'provider' => null, 'name' => 'Daily', 'code' => 'DAY'],
            ['group' => 'payment_option', 'provider' => null, 'name' => 'CR', 'code' => 'CR'],
            ['group' => 'payment_option', 'provider' => null, 'name' => 'QR', 'code' => 'QR'],
            ['group' => 'payment_option', 'provider' => null, 'name' => 'Transfer', 'code' => 'TR'],
            ['group' => 'payment_option', 'provider' => null, 'name' => 'Card', 'code' => 'CARD'],
            ['group' => 'payment_option', 'provider' => null, 'name' => 'Cheque', 'code' => 'CHQ'],
            ['group' => 'payment_option', 'provider' => null, 'name' => 'Pending', 'code' => 'PEND'],
            ['group' => 'payment_option', 'provider' => null, 'name' => 'Others', 'code' => 'OTHER'],

            // API UPS charge codes (short "ชื่อเรียก" abbreviations used on the manifest, not the
            // numeric provider codes already tracked in charge_codes).
            ['group' => 'charge_code', 'provider' => 'UPS', 'name' => 'Extended Area Surcharge Destination', 'code' => 'EAS'],
            ['group' => 'charge_code', 'provider' => 'UPS', 'name' => 'Delivery Area Surcharge', 'code' => 'DAS'],
            ['group' => 'charge_code', 'provider' => 'UPS', 'name' => 'Delivery Area Surcharge - Extended', 'code' => 'DAS-E'],
            ['group' => 'charge_code', 'provider' => 'UPS', 'name' => 'Remote Area Surcharge', 'code' => 'Remote'],
            ['group' => 'charge_code', 'provider' => 'UPS', 'name' => 'Residential Surcharge', 'code' => 'Residential'],
            ['group' => 'charge_code', 'provider' => 'UPS', 'name' => 'Girth', 'code' => 'Girth'],
            ['group' => 'charge_code', 'provider' => 'UPS', 'name' => 'AHC', 'code' => 'AHC'],
            ['group' => 'charge_code', 'provider' => 'UPS', 'name' => 'Signature', 'code' => 'Sig'],
            ['group' => 'charge_code', 'provider' => 'UPS', 'name' => 'Adult Signature', 'code' => 'A-Sig'],
            ['group' => 'charge_code', 'provider' => 'UPS', 'name' => 'Surge Fee', 'code' => 'Surge'],
            ['group' => 'charge_code', 'provider' => 'UPS', 'name' => 'International Processing Fee', 'code' => 'Inter'],

            // API DHL charge codes.
            ['group' => 'charge_code', 'provider' => 'DHL', 'name' => 'Remote Area Delivery', 'code' => 'Remote', 'amount' => 86],
            ['group' => 'charge_code', 'provider' => 'DHL', 'name' => 'Overweight Piece', 'code' => 'Overweight'],
            ['group' => 'charge_code', 'provider' => 'DHL', 'name' => 'Non-Conveyable Piece', 'code' => 'NonConveyable'],
            ['group' => 'charge_code', 'provider' => 'DHL', 'name' => 'Direct Signature', 'code' => 'D-Sig'],
            ['group' => 'charge_code', 'provider' => 'DHL', 'name' => 'Demand Surcharge', 'code' => 'Demand'],

            // Declared Value / Insurance.
            ['group' => 'insurance_code', 'provider' => 'UPS', 'name' => 'ICDV', 'code' => 'ICDV'],
            ['group' => 'insurance_code', 'provider' => 'UPS', 'name' => 'UPSC', 'code' => 'UPSC'],
            ['group' => 'insurance_code', 'provider' => 'DHL', 'name' => 'Shipment Insurance', 'code' => 'DHL'],

            // Form — fixed baht service fee per carrier, not a selectable code.
            ['group' => 'form_charge', 'provider' => 'UPS', 'name' => 'FORM', 'code' => 'FORM', 'amount' => 535],
            ['group' => 'form_charge', 'provider' => 'UPS', 'name' => 'OT', 'code' => 'OT', 'amount' => 214],
            ['group' => 'form_charge', 'provider' => 'DHL', 'name' => 'FORM', 'code' => 'FORM', 'amount' => 343],

            // Bill Transportation to / Bill Duty and Tax to — who pays the freight vs. duty/tax (Payment Info step).
            ['group' => 'bill_transportation_to', 'provider' => null, 'name' => 'Shipper', 'code' => 'SHIPPER'],
            ['group' => 'bill_transportation_to', 'provider' => null, 'name' => 'Receiver', 'code' => 'RECEIVER'],
            ['group' => 'bill_transportation_to', 'provider' => null, 'name' => 'Third Party', 'code' => 'THIRD_PARTY'],
            ['group' => 'bill_duty_tax_to', 'provider' => null, 'name' => 'Shipper', 'code' => 'SHIPPER'],
            ['group' => 'bill_duty_tax_to', 'provider' => null, 'name' => 'Receiver', 'code' => 'RECEIVER'],
            ['group' => 'bill_duty_tax_to', 'provider' => null, 'name' => 'Third Party', 'code' => 'THIRD_PARTY'],
        ];

        foreach (range(1, 10) as $zone) {
            $rows[] = ['group' => 'zone', 'provider' => null, 'name' => "Zone {$zone}", 'code' => (string) $zone];
        }

        $sortOrders = [];
        foreach ($rows as $row) {
            $group = $row['group'];
            $sortOrders[$group] = ($sortOrders[$group] ?? -1) + 1;

            ManifestOption::firstOrCreate(
                ['group' => $group, 'provider' => $row['provider'], 'code' => $row['code']],
                $row + ['sort_order' => $sortOrders[$group], 'status' => true],
            );
        }
    }
}
