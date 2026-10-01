=== MADD Rate Quote ===
Requires at least: 5.8
Requires PHP: 7.4
Stable tag: 1.1.0

ฟอร์มเช็คราคาค่าส่งระหว่างประเทศ (UPS / DHL) — ราคาขายเดียวกับหน้าร้าน จากระบบ MADD

== ติดตั้ง ==
1. WordPress › Plugins › Add New › Upload Plugin › madd-rate-quote.zip › Install › Activate
2. MADD › System Settings › Public API (Website) › แก้ไข Key
   - ติ๊ก "ใช้เช็คราคาได้"
   - ช่อง "เว็บไซต์ที่ให้ Browser ของลูกค้าเรียกได้โดยตรง" ใส่ https://madd.co.th (และ https://www.madd.co.th)
3. ถ้าติดตั้ง MADD Tracking ไว้แล้ว ไม่ต้องตั้งค่าอะไรเพิ่ม (ใช้ URL / Key เดียวกัน) — ไม่งั้นกรอกที่ Settings › MADD Rate Quote
4. ใส่ shortcode ในหน้าที่ต้องการ
     [madd_rate_quote lang="en"]   ภาษาอังกฤษ
     [madd_rate_quote lang="th"]   ภาษาไทย (ไม่ใส่ lang = ตามภาษาของหน้า Polylang/WPML)

== การทำงาน ==
- Browser ของลูกค้าเรียก MADD โดยตรง (ไม่ผ่าน Server เว็บ) — ถ้ายังไม่ได้ลงทะเบียนเว็บไซต์ จะใช้ทางสำรองผ่าน Server + API Key
- แสดงเฉพาะราคาขาย (รวม Mark-up) — ไม่มีต้นทุน / Mark-up / เลขบัญชี Carrier
- MADD จำกัดจำนวนครั้งต่อ IP ลูกค้า และจำผลคำถามเดียวกัน 15 นาที

== ตัวนับสถิติบนหน้าเว็บ ==
  [madd_stats]                                  เช็คราคา · ติดตามพัสดุ · ผู้เข้าชม (ภาษาตามหน้า)
  [madd_stats lang=en show=quotes,tracked]      เลือกตัวเลขที่แสดง: quotes, tracked, visitors, views
  [madd_stats min=100]                          ซ่อนตัวเลขที่ยังน้อยกว่า 100

== ปรับหน้าตา ==
  .madd-quote { --mq-navy: #0b2a36; --mq-gold: #f5b400; --mq-radius: 18px; }
