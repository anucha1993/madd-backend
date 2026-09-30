<?php

/*
|--------------------------------------------------------------------------
| Permission registry
|--------------------------------------------------------------------------
|
| The single source of truth for what a Role can be granted — the Roles UI renders straight from
| this (GET /access/registry) and AccessService/middleware enforce against it. A Role stores only
| its selections (roles.permissions / field_access / data_scopes); adding a new action, field
| group or scoped module here makes it grantable immediately, no migration needed.
|
| - category: which section of the Roles UI the module is listed under (see `categories`).
| - actions: "<module>.<action>" permission keys, checked by the `perm:` route middleware. The
|            value is the short name shown in the UI summary ("ดู, จอง, Void"); `action_hints`
|            optionally explains each one in the picker.
| - fields:  field groups — hidden from that module's JSON output unless the Role grants at least
|            'view'. A group with `inputs` also has an 'edit' level: submitting any of those
|            request keys (with a non-default value) without 'edit' is rejected with 403.
|            `default` is the level for a Role that never set that group explicitly.
| - scopes:  which records the module's list/detail endpoints expose — 'own' (created_by),
|            'branch' (user's branches, see branch_user / can_access_all_branches) or 'all'.
|
*/

return [
    'modules' => [
        'shipment' => [
            'label' => 'Shipments',
            'category' => 'operations',
            'actions' => ['view' => 'ดู', 'create' => 'จอง', 'void' => 'Void', 'delete' => 'ลบ (Test)', 'timeline' => 'Timeline'],
            'action_hints' => [
                'view' => 'รายการ / รายละเอียด / เอกสาร (Label, Waybill, Invoice)',
                'create' => 'เช็คราคา + จองจริงกับ Carrier, Draft',
                'void' => 'ยกเลิก Shipment (UPS ยกเลิกกับ Carrier จริง)',
                'delete' => 'ลบถาวร เฉพาะ Shipment ที่จองด้วยบัญชีโหมด Test',
                'timeline' => 'ประวัติของ Shipment: ใครจอง / แก้ไข / เปิดเอกสาร / Void / แจ้ง DHL, สถานะจากระบบ, Pickup, ใบเสร็จ และ Stock ที่ใช้ (ช่องที่ถูกซ่อนจะไม่แสดง)',
            ],
            'fields' => [
                'cost' => [
                    'label' => 'ต้นทุน Carrier ที่บันทึกไว้',
                    'columns' => ['cost_amount', 'cost_currency'],
                    'default' => 'hidden',
                ],
                'pricing' => [
                    'label' => 'ราคาขาย (freight, add-on, total)',
                    'columns' => ['freight_amount', 'addon_total', 'order_total', 'addon_lines'],
                    'default' => 'view',
                ],
                'billing' => [
                    'label' => 'การเรียกเก็บ (Bill Transportation / Duty & Tax)',
                    'columns' => [
                        'bill_transportation_to', 'bill_transportation_account_number',
                        'bill_transportation_third_party_country', 'bill_transportation_third_party_postal_code',
                        'bill_duty_tax_to', 'bill_duty_tax_account_number',
                        'bill_duty_tax_third_party_country', 'bill_duty_tax_third_party_postal_code',
                    ],
                    // Omitted / these values = the normal "we pay transport, receiver pays duty"
                    // case, which never needs the edit level.
                    'inputs' => [
                        'bill_transportation_to' => 'SHIPPER',
                        'bill_transportation_account_number' => null,
                        'bill_transportation_third_party_country' => null,
                        'bill_transportation_third_party_postal_code' => null,
                        'bill_duty_tax_to' => 'RECEIVER',
                        'bill_duty_tax_account_number' => null,
                        'bill_duty_tax_third_party_country' => null,
                        'bill_duty_tax_third_party_postal_code' => null,
                    ],
                    'default' => 'edit',
                ],
                'references' => [
                    'label' => 'เลขอ้างอิง (Invoice / Insurance / PO No.)',
                    'columns' => ['ref_invoice_no', 'ref_insurance_no', 'ref_purchase_no'],
                    'inputs' => ['ref_invoice_no' => null, 'ref_insurance_no' => null, 'ref_purchase_no' => null],
                    'default' => 'edit',
                ],
                'carrier_raw' => [
                    'label' => 'ข้อมูลดิบจาก API Carrier',
                    'columns' => ['raw_response', 'raw_request', 'carrier_http_status', 'error_message'],
                    'default' => 'hidden',
                ],
            ],
            'scope' => true,
        ],
        // Rate quotes — the check-rate cards, the AI rate chat, and the quote snapshot saved on
        // every Shipment (shipments.rate_quote). `columns` here are keys INSIDE each quote (and
        // inside each chargeBreakdown line), stripped by AccessService::sanitizeRateQuote(); the
        // sell price and its line-by-line breakdown always stay visible.
        'rate' => [
            'label' => 'ใบเสนอราคา (Rate Quote)',
            'description' => 'การ์ดเช็คราคา, AI Rate Chat และ Rate Quote ที่บันทึกกับ Shipment — ราคาขายแสดงเสมอ',
            'category' => 'pricing',
            'fields' => [
                'breakdown' => [
                    'label' => 'รายการค่าใช้จ่ายแยกบรรทัด',
                    'hint' => 'Base Freight, Fuel Surcharge, ค่าบริการเสริม ฯลฯ — ถ้าปิด จะเห็นแค่ยอดรวม',
                    // Special-cased in AccessService::sanitizeRateQuote(): the carrier's own
                    // insurance lines (ChargeMarkupService::COST_ONLY_CODES) are always kept,
                    // because the booking form prices the Insurance Add-on and nets them out of
                    // Freight from them — the page just doesn't list them.
                    'columns' => ['chargeBreakdown'],
                    'default' => 'view',
                ],
                'weight' => [
                    'label' => 'น้ำหนักที่คิดเงิน',
                    'hint' => 'Billed Weight / Volumetric Weight',
                    'columns' => ['billedWeight', 'billedWeightUnit', 'volumetricWeight'],
                    'default' => 'view',
                ],
                'transit' => [
                    'label' => 'ระยะเวลาจัดส่ง',
                    'hint' => 'Transit days / วันที่คาดว่าจะถึง',
                    'columns' => ['transitDays', 'estimatedDelivery'],
                    'default' => 'view',
                ],
                'account' => [
                    'label' => 'บัญชี Carrier / Zone',
                    'hint' => 'เลขบัญชี UPS/DHL ที่ใช้เสนอราคา และ Zone ปลายทาง',
                    'columns' => ['username', 'zone'],
                    'default' => 'view',
                ],
                'cost' => [
                    'label' => 'ต้นทุน Carrier',
                    'hint' => 'ราคาทุนก่อน Mark-up',
                    'columns' => ['costPublished', 'costNegotiated', 'costBreakdown'],
                    'default' => 'hidden',
                ],
                'markup' => [
                    'label' => 'รายละเอียด Mark-up',
                    'hint' => 'สูตร, % × ฐานคำนวณ, ยอด Mark-up รวม',
                    'columns' => ['markupTotal', 'chargeBreakdown.*.markupUnit', 'chargeBreakdown.*.markupValue', 'chargeBreakdown.*.markupBase', 'chargeBreakdown.*.markupFormula'],
                    'default' => 'hidden',
                ],
                'carrier_raw' => [
                    'label' => 'ข้อมูลดิบ (Raw)',
                    'hint' => 'Response ดิบจาก API ของ Carrier',
                    'columns' => ['raw'],
                    'default' => 'hidden',
                ],
            ],
        ],
        'pickup' => [
            'label' => 'Pickups',
            'category' => 'operations',
            'actions' => ['view' => 'ดู', 'create' => 'นัด', 'cancel' => 'ยกเลิก / นัดใหม่', 'confirm' => 'ยืนยันรถรับแล้ว', 'timeline' => 'Timeline'],
            'action_hints' => ['confirm' => 'กดยืนยันว่า Courier มารับของแล้ว ก่อน Tracking scan จะเข้ามา', 'timeline' => 'ประวัติการนัด / ยกเลิก / ยืนยันรับของของ Pickup'],
            'scope' => true,
        ],
        'receipt' => [
            'label' => 'ใบเสร็จ / ใบกำกับภาษี',
            'category' => 'operations',
            'actions' => ['view' => 'ดู', 'create' => 'ออก', 'edit' => 'แก้ไข', 'void' => 'Void', 'delete' => 'ลบ (Test)', 'timeline' => 'Timeline'],
            'action_hints' => [
                'view' => 'รายการ / พิมพ์ PDF',
                'delete' => 'ลบถาวร เฉพาะเอกสารของ Shipment โหมด Test',
                'timeline' => 'ประวัติของเอกสาร: ออก / แก้ไขรายการ / พิมพ์ / Void',
            ],
            'fields' => [
                'buyer' => [
                    'label' => 'ข้อมูลผู้ซื้อ (ชื่อ, เลขผู้เสียภาษี, ที่อยู่)',
                    'columns' => ['buyer_name', 'buyer_tax_id', 'buyer_address', 'buyer_is_head_office', 'buyer_branch_no'],
                    'default' => 'view',
                ],
                'variance' => [
                    'label' => 'ส่วนต่างจากราคา Shipment',
                    'columns' => ['shipment_total_snapshot', 'variance_amount'],
                    'default' => 'view',
                ],
            ],
            'scope' => true,
        ],
        'supply_stock' => [
            'label' => 'Stock วัสดุห่อ (Packing Supplies)',
            'category' => 'operations',
            'actions' => ['view' => 'ดู', 'receive' => 'รับเข้า / ปรับยอด', 'settings' => 'ตั้ง Min / Max', 'report' => 'รายงาน'],
            'action_hints' => [
                'view' => 'ยอดคงเหลือแยกสาขา + ประวัติการเคลื่อนไหว (การจองตัด Stock ให้อัตโนมัติ)',
                'receive' => 'รับของเข้า Stock และปรับยอดหลังนับจริง',
                'settings' => 'จุดแจ้งเตือนใกล้หมด (Min) และยอดสูงสุด (Max) ของแต่ละสาขา',
                'report' => 'สรุปยกมา / รับเข้า / ใช้ไป / คงเหลือ + Export Excel',
            ],
            'scope' => true,
        ],
        'billing_customer' => [
            'label' => 'ลูกค้าใบกำกับภาษี',
            'category' => 'operations',
            'actions' => ['manage' => 'จัดการ'],
            'action_hints' => ['manage' => 'เพิ่ม / แก้ไข / ลบ'],
        ],
        'customer' => [
            'label' => 'ลูกค้า / สมุดที่อยู่',
            'category' => 'operations',
            'actions' => ['manage' => 'จัดการ'],
            'action_hints' => ['manage' => 'เพิ่ม / แก้ไข / ลบ'],
        ],
        'tracking' => [
            'label' => 'Tracking',
            'category' => 'operations',
            'actions' => ['view' => 'ค้นหา'],
        ],
        'report' => [
            'label' => 'Reports',
            'category' => 'reports',
            'actions' => [
                'manifest' => 'Manifest',
                'summary' => 'Shipment Summary',
                'finance' => 'Revenue & Expense',
            ],
            'action_hints' => ['manifest' => 'ดู + Export Excel'],
        ],
        'branch' => [
            'label' => 'สาขา',
            'category' => 'admin',
            'actions' => ['manage' => 'จัดการ'],
            'action_hints' => ['manage' => 'เพิ่ม / แก้ไข สาขา, บัญชี Carrier ของสาขา, เลขที่เอกสาร'],
        ],
        'config' => [
            'label' => 'Management / System Settings',
            'category' => 'admin',
            'actions' => [
                'markup' => 'Mark-up',
                'addon' => 'Add-on',
                'insurance' => 'Insurance UPSC',
                'supplies' => 'Packaging Supplies',
                'weight_bands' => 'Weight Bands',
                'manifest_options' => 'Manifest Options',
                'receipt_templates' => 'Receipt Templates',
                'countries' => 'Countries',
                'agent_accounts' => 'Agent Accounts',
                'thai_address' => 'Thai Address DB',
                'integrations' => 'API Integrations',
                'tracking_sync' => 'Tracking Sync',
                'report_schedules' => 'Report Schedules',
                'smtp' => 'SMTP',
                'column_profiles' => 'Column Profiles',
                'system_alerts' => 'System Alerts',
            ],
            'action_hints' => [
                'markup' => 'Mark-up / Charge Codes / สูตรค่าบริการ',
                'agent_accounts' => 'บัญชี UPS / DHL',
                'integrations' => 'AI, Cloudflare R2',
                'column_profiles' => 'กำหนดชุดคอลัมน์ของหน้ารายการ และ Role ที่ใช้ได้ (ผู้ใช้อื่นทำได้แค่เลื่อนลำดับ)',
                'system_alerts' => 'ดู / ปิดรายการแจ้งเตือนเมื่อระบบทำงานพลาด (อัปโหลดเอกสาร, Tracking Sync, ยกเลิก Pickup, Error 500)',
            ],
        ],
        'user' => [
            'label' => 'ผู้ใช้งานและสิทธิ์',
            'category' => 'admin',
            'actions' => ['manage' => 'ผู้ใช้งาน', 'roles' => 'Role & สิทธิ์', 'audit' => 'Audit Log'],
            'action_hints' => ['audit' => 'ดูประวัติว่าใครแก้ไขอะไร เมื่อไร (Role, ผู้ใช้, ราคา, Shipment, ใบเสร็จ)'],
        ],
    ],

    // Roles UI sections, in display order.
    'categories' => [
        'operations' => ['label' => 'งานประจำ', 'description' => 'เมนูที่ใช้ทำงานหน้าร้านทุกวัน'],
        'pricing' => ['label' => 'ข้อมูลราคา / ต้นทุน', 'description' => 'สิ่งที่เห็นเพิ่มจากราคาขาย — ปิดไว้สำหรับพนักงานหน้าร้าน'],
        'reports' => ['label' => 'รายงาน', 'description' => null],
        'admin' => ['label' => 'ตั้งค่าระบบ', 'description' => 'สำหรับผู้จัดการ / ผู้ดูแลระบบ'],
    ],

    // List pages whose "Manage Columns" supports Column Profiles — key = the page's
    // useManageColumns storageKey (see ColumnProfileController).
    'column_pages' => [
        'shipment-list' => 'Shipments',
        'receipts-list' => 'Receipts & Tax Invoices',
    ],

    'scopes' => [
        'own' => 'เฉพาะที่ตัวเองสร้าง',
        'branch' => 'เฉพาะสาขาของตัวเอง',
        'all' => 'ทุกสาขา',
    ],

    'field_levels' => [
        'hidden' => 'ซ่อน',
        'view' => 'ดูได้',
        'edit' => 'ดู + แก้ไขได้',
    ],
];
