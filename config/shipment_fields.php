<?php

/*
 * Booking form fields set up in /config/shipment-fields: which ones an admin can make mandatory per
 * carrier, and the label each shows on /shipment/create (form_label — editable there).
 * The key is the path in the POST /shipments payload (`*` = every package / invoice line);
 * `step` is the /shipment/create step the field lives on. `requirable` false = a label-only entry:
 * the field is always required (or never), so there's nothing to switch on.
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
        'origin.contact_name' => ['label' => 'ชื่อผู้ติดต่อ (ผู้ส่ง)', 'form_label' => 'Contact Name', 'group' => 'origin', 'step' => 1],
        'origin.company' => ['label' => 'ชื่อบริษัท (ผู้ส่ง)', 'form_label' => 'Company', 'group' => 'origin', 'step' => 1],
        'origin.tax_id' => ['label' => 'เลขผู้เสียภาษี (ผู้ส่ง)', 'form_label' => 'Tax ID No.', 'group' => 'origin', 'step' => 1],
        'origin.postcode' => ['label' => 'ตำบล / อำเภอ / จังหวัด / รหัสไปรษณีย์ (ผู้ส่ง)', 'form_label' => 'Subdistrict / District / Province / Postcode', 'group' => 'origin', 'step' => 1, 'requirable' => false, 'always' => true],
        'origin.address' => ['label' => 'ที่อยู่ บรรทัด 1 (ผู้ส่ง)', 'form_label' => 'Address 1', 'group' => 'origin', 'step' => 1, 'requirable' => false, 'always' => true],
        'origin.address2' => ['label' => 'ที่อยู่ บรรทัด 2 (ผู้ส่ง)', 'form_label' => 'Address 2', 'group' => 'origin', 'step' => 1, 'requirable' => false],
        'origin.address3' => ['label' => 'ที่อยู่ บรรทัด 3 (ผู้ส่ง)', 'form_label' => 'Address 3', 'group' => 'origin', 'step' => 1, 'requirable' => false],
        'origin.phone' => ['label' => 'เบอร์โทร (ผู้ส่ง)', 'form_label' => 'Telephone', 'group' => 'origin', 'step' => 1],
        'origin.notes' => ['label' => 'หมายเหตุ (ผู้ส่ง)', 'form_label' => 'Notes', 'group' => 'origin', 'step' => 1, 'requirable' => false],

        'destination.contact_name' => ['label' => 'ชื่อผู้ติดต่อ (ผู้รับ)', 'form_label' => 'Contact Name', 'group' => 'destination', 'step' => 1],
        'destination.company' => ['label' => 'ชื่อบริษัท (ผู้รับ)', 'form_label' => 'Company', 'group' => 'destination', 'step' => 1],
        'destination.tax_id' => ['label' => 'เลขผู้เสียภาษี / EORI (ผู้รับ)', 'form_label' => 'Tax ID No.', 'group' => 'destination', 'step' => 1],
        'destination.country' => ['label' => 'ประเทศ (ผู้รับ)', 'form_label' => 'Country', 'group' => 'destination', 'step' => 1, 'requirable' => false, 'always' => true],
        'destination.city' => ['label' => 'เมือง (ผู้รับ)', 'form_label' => 'City', 'group' => 'destination', 'step' => 1, 'requirable' => false, 'always' => true],
        'destination.state' => ['label' => 'รัฐ / จังหวัด (ผู้รับ)', 'form_label' => 'State / Province', 'group' => 'destination', 'step' => 1],
        'destination.postcode' => ['label' => 'รหัสไปรษณีย์ (ผู้รับ)', 'form_label' => 'Postal Code', 'group' => 'destination', 'step' => 1],
        'destination.address' => ['label' => 'ที่อยู่ (ผู้รับ)', 'form_label' => 'Address 1', 'group' => 'destination', 'step' => 1],
        'destination.address2' => ['label' => 'ที่อยู่ บรรทัด 2 (ผู้รับ)', 'form_label' => 'Address 2', 'group' => 'destination', 'step' => 1, 'requirable' => false],
        'destination.address3' => ['label' => 'ที่อยู่ บรรทัด 3 (ผู้รับ)', 'form_label' => 'Address 3', 'group' => 'destination', 'step' => 1, 'requirable' => false],
        'destination.phone' => ['label' => 'เบอร์โทร (ผู้รับ)', 'form_label' => 'Telephone', 'group' => 'destination', 'step' => 1],
        'destination.email' => ['label' => 'อีเมล (ผู้รับ)', 'form_label' => 'Email', 'group' => 'destination', 'step' => 1],
        'destination.notes' => ['label' => 'หมายเหตุ (ผู้รับ)', 'form_label' => 'Notes', 'group' => 'destination', 'step' => 1, 'requirable' => false],

        'packages.*.description' => ['label' => 'รายละเอียดสินค้าของทุกกล่อง', 'form_label' => 'Description of Goods', 'group' => 'package', 'step' => 2],

        'invoice_lines.*.hs_code' => ['label' => 'HS Code ของทุกรายการ', 'form_label' => 'HS Code', 'group' => 'invoice', 'step' => 3],
        'invoice_lines.*.weight' => ['label' => 'น้ำหนักของทุกรายการ', 'form_label' => 'Weight (kg)', 'group' => 'invoice', 'step' => 3],
        'commercial_invoice_upload_key' => ['label' => 'ไฟล์ Commercial Invoice แนบ', 'form_label' => 'ไฟล์ Commercial Invoice (เสริม — PDF/JPG/PNG)', 'group' => 'invoice', 'step' => 3],

        'payment_method' => ['label' => 'วิธีชำระเงิน', 'form_label' => 'Payment Method', 'group' => 'payment', 'step' => 5],
        'ref_invoice_no' => ['label' => 'Ref: Invoice No.', 'form_label' => 'Ref. Invoice No.', 'group' => 'payment', 'step' => 5],
        'ref_insurance_no' => ['label' => 'Ref: Insurance No.', 'form_label' => 'Ref. Insurance No.', 'group' => 'payment', 'step' => 5],
        'ref_purchase_no' => ['label' => 'Ref: Purchase No.', 'form_label' => 'Ref. Purchase No.', 'group' => 'payment', 'step' => 5],
    ],
];
