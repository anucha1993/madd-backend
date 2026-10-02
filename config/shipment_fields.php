<?php

/*
 * Optional booking fields an admin can make mandatory per carrier (/config/shipment-fields).
 * The key is the path in the POST /shipments payload (`*` = every package / invoice line);
 * `step` is the /shipment/create step the field lives on. Fields the carriers always need
 * (origin address, destination country/city, weights…) are already required and aren't listed.
 */
return [
    'carriers' => ['UPS', 'DHL'],

    'groups' => [
        'origin' => 'ผู้ส่ง (Ship From)',
        'destination' => 'ผู้รับ (Ship To)',
        'package' => 'พัสดุ',
        'invoice' => 'Commercial Invoice',
        'payment' => 'Payment / Reference',
    ],

    'fields' => [
        'origin.contact_name' => ['label' => 'ชื่อผู้ติดต่อ (ผู้ส่ง)', 'group' => 'origin', 'step' => 1],
        'origin.company' => ['label' => 'ชื่อบริษัท (ผู้ส่ง)', 'group' => 'origin', 'step' => 1],
        'origin.tax_id' => ['label' => 'เลขผู้เสียภาษี (ผู้ส่ง)', 'group' => 'origin', 'step' => 1],
        'origin.phone' => ['label' => 'เบอร์โทร (ผู้ส่ง)', 'group' => 'origin', 'step' => 1],

        'destination.contact_name' => ['label' => 'ชื่อผู้ติดต่อ (ผู้รับ)', 'group' => 'destination', 'step' => 1],
        'destination.company' => ['label' => 'ชื่อบริษัท (ผู้รับ)', 'group' => 'destination', 'step' => 1],
        'destination.tax_id' => ['label' => 'เลขผู้เสียภาษี / EORI (ผู้รับ)', 'group' => 'destination', 'step' => 1],
        'destination.phone' => ['label' => 'เบอร์โทร (ผู้รับ)', 'group' => 'destination', 'step' => 1],
        'destination.email' => ['label' => 'อีเมล (ผู้รับ)', 'group' => 'destination', 'step' => 1],
        'destination.address' => ['label' => 'ที่อยู่ (ผู้รับ)', 'group' => 'destination', 'step' => 1],
        'destination.postcode' => ['label' => 'รหัสไปรษณีย์ (ผู้รับ)', 'group' => 'destination', 'step' => 1],
        'destination.state' => ['label' => 'รัฐ / จังหวัด (ผู้รับ)', 'group' => 'destination', 'step' => 1],

        'packages.*.description' => ['label' => 'รายละเอียดสินค้าของทุกกล่อง', 'group' => 'package', 'step' => 2],

        'invoice_lines.*.hs_code' => ['label' => 'HS Code ของทุกรายการ', 'group' => 'invoice', 'step' => 3],
        'invoice_lines.*.weight' => ['label' => 'น้ำหนักของทุกรายการ', 'group' => 'invoice', 'step' => 3],
        'commercial_invoice_upload_key' => ['label' => 'ไฟล์ Commercial Invoice แนบ', 'group' => 'invoice', 'step' => 3],

        'payment_method' => ['label' => 'วิธีชำระเงิน', 'group' => 'payment', 'step' => 5],
        'ref_invoice_no' => ['label' => 'Ref: Invoice No.', 'group' => 'payment', 'step' => 5],
        'ref_insurance_no' => ['label' => 'Ref: Insurance No.', 'group' => 'payment', 'step' => 5],
        'ref_purchase_no' => ['label' => 'Ref: Purchase No.', 'group' => 'payment', 'step' => 5],
    ],
];
