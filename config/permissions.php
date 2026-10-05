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
            // One action per button, in the order the buttons appear on the Shipments pages.
            'actions' => [
                'view' => 'ดูรายการ Shipment',
                'detail' => 'View Shipment Details',
                'create' => 'Create Shipment (เช็คราคา + จอง)',
                'draft' => 'Drafts (ฉบับร่าง)',
                'assign_branch' => 'กำหนดสาขาให้ Shipment',
                'label' => 'Open Label',
                'label_all' => 'Print All Labels',
                'waybill' => "Open Waybill (Shipper's Copy)",
                'waybill_original' => 'Download DHL original waybill',
                'invoice' => 'Open Commercial Invoice',
                'issue_receipt' => 'Issue Receipt / Tax Invoice',
                'mark_picked_up' => 'ยืนยันรถรับแล้ว',
                'void' => 'Void (แจ้งยกเลิก DHL)',
                'cancel_copy' => 'คัดลอกข้อความแจ้งยกเลิก DHL',
                'cancel_notified' => 'บันทึกว่าแจ้ง DHL แล้ว',
                'cancel_confirmed' => 'DHL ยืนยันยกเลิกแล้ว',
                'unvoid' => 'ยกเลิก Void (กด Void ผิด)',
                'delete' => 'Delete TEST shipment',
                'timeline' => 'Timeline',
            ],
            'action_hints' => [
                'view' => 'หน้า Shipments (รายการ)',
                'detail' => 'เปิดหน้ารายละเอียดของ Shipment',
                'draft' => 'บันทึก / เปิด / ลบ ฉบับร่างการจอง (หน้า Drafts)',
                'assign_branch' => 'เลือกสาขาให้ Shipment ที่ยังไม่มีสาขา',
                'label' => 'เปิด Label ใบปะหน้า (ทั้ง Shipment หรือรายกล่อง)',
                'label_all' => 'พิมพ์ Label ทุกกล่องรวมไฟล์เดียว (Shipment หลายกล่อง)',
                'waybill' => 'Waybill ที่ MADD สร้าง พร้อมส่วน Payment of Charges',
                'waybill_original' => 'ไฟล์ Waybill Doc จาก DHL โดยตรง',
                'issue_receipt' => 'ปุ่มไปหน้าออกใบเสร็จจาก Shipment (ต้องมีสิทธิ์ "ใบเสร็จ → ออก" ด้วย)',
                'mark_picked_up' => 'ยืนยันว่า Courier มารับ Shipment นี้แล้ว ก่อน Tracking scan จะเข้ามา',
                'void' => 'ยกเลิก Shipment (UPS ยกเลิกกับ Carrier จริง, DHL ต้องแจ้งเอง)',
                'cancel_copy' => 'คัดลอกข้อความสำหรับส่งแจ้ง DHL ให้ยกเลิก',
                'cancel_notified' => 'บันทึกว่าแจ้ง DHL แล้ว / แก้ไขบันทึกการแจ้ง',
                'cancel_confirmed' => 'บันทึกว่า DHL ยืนยันการยกเลิกแล้ว',
                'unvoid' => 'คืนสถานะ Shipment ที่กด Void ผิด (ก่อน DHL ยืนยันยกเลิก)',
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
            'actions' => ['view' => 'ดู', 'create' => 'นัด', 'cancel' => 'ยกเลิก', 'reschedule' => 'นัดใหม่', 'confirm' => 'ยืนยันรถรับแล้ว', 'timeline' => 'Timeline'],
            'action_hints' => ['reschedule' => 'ยกเลิกนัดเดิมแล้วนัดวันใหม่ในขั้นตอนเดียว', 'confirm' => 'กดยืนยันว่า Courier มารับของแล้ว ก่อน Tracking scan จะเข้ามา', 'timeline' => 'ประวัติการนัด / ยกเลิก / ยืนยันรับของของ Pickup'],
            'scope' => true,
        ],
        'shipment_kpi' => [
            'label' => 'การ์ดสรุปหน้า Shipments',
            'category' => 'reports',
            'actions' => [
                'today' => 'Shipments Today',
                'in_transit' => 'In Transit',
                'month' => 'Booked This Month',
                'revenue' => 'Revenue This Month',
                'cancelled' => 'Cancelled/Failed This Month',
            ],
            'action_hints' => [
                'revenue' => 'ยอดขายเดือนนี้ — ต้องเห็นช่อง "ราคาขาย" ของ Shipment ด้วย',
                'in_transit' => 'Shipment ที่ยังไม่ส่งถึง (ตามขอบเขตสาขาของ Role)',
            ],
        ],
        'receipt' => [
            'label' => 'ใบเสร็จ / ใบกำกับภาษี',
            'category' => 'operations',
            'actions' => ['view' => 'ดู', 'print' => 'พิมพ์ / เปิด PDF', 'create' => 'ออก', 'edit' => 'แก้ไข', 'void' => 'Void', 'delete' => 'ลบ (Test)', 'timeline' => 'Timeline'],
            'action_hints' => [
                'view' => 'รายการเอกสาร',
                'print' => 'เปิด / พิมพ์ PDF ทีละใบ และ Mass Print',
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
        'carrier_invoice' => [
            'label' => 'Carrier Invoice Reconcile (UPS/DHL)',
            'category' => 'operations',
            'actions' => ['view' => 'ดู', 'upload' => 'อัพโหลด / อ่านใหม่ (OCR)', 'edit' => 'แก้ไขรายการ', 'delete' => 'ลบ Invoice'],
            'action_hints' => [
                'view' => 'รายการ Invoice ที่อัพโหลด + เทียบ Rate Quote ตอน book กับยอดจริงจากใบแจ้งหนี้',
                'upload' => 'อัพโหลดไฟล์ PDF ใบแจ้งหนี้ UPS/DHL ให้ระบบ OCR อ่านอัตโนมัติ (Google Vision)',
                'edit' => 'แก้ไข/จับคู่รายการที่ OCR อ่านมาไม่ตรง',
            ],
        ],
        'supply_stock' => [
            'label' => 'Stock วัสดุห่อ (Packing Supplies)',
            'category' => 'operations',
            'actions' => ['view' => 'ดู', 'receive' => 'รับเข้า', 'adjust' => 'ปรับยอด (นับจริง)', 'settings' => 'ตั้ง Min / Max', 'report' => 'รายงาน', 'export' => 'Export Excel'],
            'action_hints' => [
                'view' => 'ยอดคงเหลือแยกสาขา + ประวัติการเคลื่อนไหว (การจองตัด Stock ให้อัตโนมัติ)',
                'receive' => 'รับของเข้า Stock',
                'adjust' => 'ปรับยอดคงเหลือให้ตรงกับที่นับได้จริง',
                'settings' => 'จุดแจ้งเตือนใกล้หมด (Min) และยอดสูงสุด (Max) ของแต่ละสาขา',
                'report' => 'สรุปยกมา / รับเข้า / ใช้ไป / คงเหลือ',
                'export' => 'ดาวน์โหลดรายงาน Stock เป็น Excel',
            ],
            'scope' => true,
        ],
        'billing_customer' => [
            'label' => 'ลูกค้าใบกำกับภาษี',
            'category' => 'operations',
            'actions' => ['view' => 'ดู', 'create' => 'เพิ่ม', 'edit' => 'แก้ไข', 'delete' => 'ลบ'],
            'action_hints' => [
                'view' => 'หน้า Billing Customers (ฟอร์มออกใบเสร็จค้นหาลูกค้าได้เสมอ)',
                'create' => 'รวมถึงเพิ่มลูกค้าใหม่จากฟอร์มออกใบเสร็จ',
            ],
        ],
        'customer' => [
            'label' => 'ลูกค้า / สมุดที่อยู่',
            'category' => 'operations',
            'actions' => ['view' => 'ดู', 'create' => 'เพิ่ม', 'edit' => 'แก้ไข', 'delete' => 'ลบ'],
            'action_hints' => [
                'view' => 'หน้า Customers (ฟอร์มจอง Shipment ค้นหาที่อยู่ได้เสมอ)',
                'create' => 'เพิ่มลูกค้า / ที่อยู่ (ผู้ที่จอง Shipment ได้ บันทึกที่อยู่ใหม่จากฟอร์มจองได้เสมอ)',
                'edit' => 'แก้ไขข้อมูลลูกค้าและที่อยู่',
                'delete' => 'ลบลูกค้า / ที่อยู่',
            ],
        ],
        'ai' => [
            'label' => 'AI Assistant',
            'category' => 'operations',
            'actions' => ['rate_chat' => 'ถาม AI เรื่องราคา', 'parse_address' => 'AI กรอกที่อยู่'],
            'action_hints' => [
                'rate_chat' => 'ปุ่มแชท AI มุมจอ ประเมินราคาค่าส่ง (ต้นทุนยังซ่อนตามสิทธิ์ข้อมูลราคา)',
                'parse_address' => 'วางข้อความแล้วให้ AI แยกเป็นช่องที่อยู่ในฟอร์มจอง',
            ],
        ],
        'tracking' => [
            'label' => 'Tracking',
            'category' => 'operations',
            'actions' => ['view' => 'ค้นหา', 'pod' => 'Proof of Delivery / ลายเซ็น'],
            'action_hints' => ['pod' => 'ขอและดาวน์โหลดหลักฐานการส่งถึง (POD) และลายเซ็นผู้รับ จาก UPS'],
        ],
        'report' => [
            'label' => 'Reports',
            'category' => 'reports',
            'actions' => [
                'manifest' => 'Manifest',
                'manifest_export' => 'Manifest: Export Excel',
                'summary' => 'Shipment Summary',
                'summary_export' => 'Shipment Summary: Export Excel',
                'finance' => 'Revenue & Expense',
                'key_billing' => 'Key Billing Report',
            ],
            'action_hints' => ['manifest' => 'ดูรายงาน Manifest', 'summary' => 'Dashboard วิเคราะห์ Shipment', 'key_billing' => 'ดูยอดขายเทียบกับต้นทุนจาก Carrier Invoice'],
        ],
        'branch' => [
            'label' => 'สาขา',
            'category' => 'admin',
            'actions' => ['view' => 'ดู', 'create' => 'เพิ่ม', 'edit' => 'แก้ไข', 'delete' => 'ลบ', 'carrier_accounts' => 'บัญชี Carrier ของสาขา', 'doc_numbers' => 'เลขที่เอกสาร'],
            'action_hints' => [
                'view' => 'หน้า Branches (รายชื่อสาขาใช้ในฟอร์มต่างๆ ได้เสมอ)',
                'edit' => 'แก้ไขข้อมูลสาขา, เปิด/ปิดสาขา',
                'carrier_accounts' => 'เลือกบัญชี UPS / DHL ที่สาขาใช้จองได้',
                'doc_numbers' => 'รูปแบบและเลขรันใบเสร็จ / ใบกำกับภาษีของสาขา',
            ],
        ],
        'config' => [
            'label' => 'Management / System Settings',
            'category' => 'admin',
            'actions' => [
                'markup' => 'Mark-up',
                'charge_names' => 'Charge Display Names',
                'addon' => 'Add-on',
                'insurance' => 'Insurance UPSC',
                'supplies' => 'Packaging Supplies',
                'weight_bands' => 'Weight Bands',
                'manifest_options' => 'Manifest Options',
                'receipt_templates' => 'Receipt Templates',
                'insurance_import' => 'Insurance UPSC: นำเข้าไฟล์',
                'countries' => 'Countries',
                'countries_sync' => 'Countries: Sync รายชื่อ',
                'zone_prices' => 'Countries: Zone & ราคา Manual',
                'agent_accounts' => 'Agent Accounts',
                'agent_accounts_test' => 'Agent Accounts: ทดสอบเชื่อมต่อ',
                'thai_address' => 'Thai Address DB',
                'integrations' => 'API Integrations',
                'shipment_fields' => 'Shipment: บังคับกรอกตาม Carrier',
                'tracking_sync' => 'Tracking Sync',
                'tracking_sync_run' => 'Tracking Sync: สั่ง Sync ทันที',
                'report_schedules' => 'Report Schedules',
                'report_schedules_send' => 'Report Schedules: ส่งทันที',
                'smtp' => 'SMTP',
                'smtp_test' => 'SMTP: ส่งอีเมลทดสอบ',
                'column_profiles' => 'Column Profiles',
                'system_alerts' => 'System Alerts',
                'system_alerts_resolve' => 'System Alerts: ปิดรายการ',
                'api_clients' => 'Public API',
                'api_clients_test' => 'Public API: ทดสอบ Key',
                'api_clients_regenerate' => 'Public API: สร้าง Key ใหม่',
                'wordpress_plugin' => 'Public API: ดาวน์โหลด WordPress Plugin',
            ],
            'action_hints' => [
                'markup' => 'Mark-up / Charge Codes / สูตรค่าบริการ',
                'zone_prices' => 'ตั้ง Zone ของแต่ละประเทศ (UPS / DHL) และราคาตาม Zone ที่ใช้เป็น {ZONE_PRICE} ในสูตร Fixed Charges / Mark-up',
                'charge_names' => 'ตั้งชื่อแสดงของ Charge Code (เช่น BASE → ค่าขนส่ง) — มีผลทุกหน้าและใบเสร็จ / ใบกำกับภาษีที่ออกใหม่',
                'agent_accounts' => 'บัญชี UPS / DHL (Fixed Charges ของบัญชีต้องมีสิทธิ์ Mark-up ด้วย)',
                'agent_accounts_test' => 'เรียก API ของ Carrier จริงเพื่อทดสอบบัญชี',
                'shipment_fields' => 'กำหนดช่องที่ต้องกรอกก่อนจอง แยก UPS / DHL',
                'insurance_import' => 'นำเข้าไฟล์แทนข้อมูลเดิมทั้งชุด',
                'report_schedules_send' => 'ส่งอีเมลรายงานให้ผู้รับจริงทันที',
                'api_clients_regenerate' => 'Key เดิมจะใช้งานไม่ได้ทันที',
                'system_alerts_resolve' => 'ปิดทีละรายการ / ปิดทั้งหมด',
                'integrations' => 'AI, Cloudflare R2',
                'column_profiles' => 'กำหนดชุดคอลัมน์ของหน้ารายการ และ Role ที่ใช้ได้ (ผู้ใช้อื่นทำได้แค่เลื่อนลำดับ)',
                'api_clients' => 'API Key สำหรับเว็บไซต์ภายนอก (เช็คราคาขายจาก WordPress ฯลฯ) และสถิติการเรียกใช้',
                'system_alerts' => 'ดู / ปิดรายการแจ้งเตือนเมื่อระบบทำงานพลาด (อัปโหลดเอกสาร, Tracking Sync, ยกเลิก Pickup, Error 500)',
            ],
        ],
        'user' => [
            'label' => 'ผู้ใช้งานและสิทธิ์',
            'category' => 'admin',
            'actions' => ['view' => 'ดูผู้ใช้', 'create' => 'เพิ่มผู้ใช้', 'edit' => 'แก้ไขผู้ใช้', 'delete' => 'ลบผู้ใช้', 'roles' => 'Role & สิทธิ์', 'roles_delete' => 'ลบ Role', 'audit' => 'Audit Log'],
            'action_hints' => [
                'edit' => 'แก้ไขข้อมูล, รหัสผ่าน, Role และสาขาของผู้ใช้ (Role Super Admin ให้ได้เฉพาะ Super Admin)',
                'roles' => 'สร้าง / แก้ไขสิทธิ์ของแต่ละ Role',
                'audit' => 'ดูประวัติว่าใครแก้ไขอะไร เมื่อไร (Role, ผู้ใช้, ราคา, Shipment, ใบเสร็จ)',
            ],
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
