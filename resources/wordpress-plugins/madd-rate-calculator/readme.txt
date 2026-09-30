=== MADD Rate Calculator ===
Requires at least: 5.8
Requires PHP: 7.4
Stable tag: 1.1.0

ฟอร์มเช็คราคาค่าส่งระหว่างประเทศ (UPS / DHL) — ราคาขายเดียวกับหน้าร้าน และฟอร์มติดตามพัสดุ จากระบบ MADD

== ติดตั้ง ==
1. อัปโหลดโฟลเดอร์ madd-rate-calculator ไปที่ wp-content/plugins/ (หรือ zip แล้วอัปโหลดที่ Plugins › Add New › Upload)
2. เปิดใช้งาน Plugin
3. ใน MADD: System Settings › Public API (Website) › สร้าง API Key — คัดลอก Key ไว้ (แสดงครั้งเดียว)
4. แนะนำ: ใส่ใน wp-config.php (Key จะไม่ถูกเก็บในฐานข้อมูล WordPress)
     define('MADD_RATE_API_URL', 'https://<madd-backend>/api');
     define('MADD_RATE_API_KEY', 'madd_...');
   หรือกรอกที่ Settings › MADD Rate Calculator
5. กด "ทดสอบการเชื่อมต่อ" ที่หน้า Settings
6. ใส่ shortcode ในหน้าที่ต้องการ
     [madd_rate_calculator]  ฟอร์มเช็คราคา
     [madd_tracking]         ฟอร์มติดตามพัสดุ — ลิงก์ตรงได้ เช่น https://your-site/tracking/?tn=5084355500
   (API Key ต้องเปิด "ใช้ติดตามพัสดุได้" ที่ MADD)

== ความปลอดภัย ==
- ติดตามได้เฉพาะ Shipment ที่จองผ่าน MADD และแสดงเฉพาะสถานะ / จุดสแกน — ไม่มีชื่อ ที่อยู่ เบอร์โทร หรือราคา
- Browser ของลูกค้าคุยกับ WordPress เท่านั้น — WordPress เรียก MADD จากฝั่ง Server, API Key ไม่ถูกส่งไปที่ Browser
- MADD จำกัดจำนวนครั้งต่อ Key และต่อ IP ลูกค้า (ตั้งค่าได้ที่ MADD)
- แนะนำเปิด Cloudflare Turnstile ที่หน้า Settings เพื่อกันบอท
- ถ้า Server WordPress มี IP คงที่ ให้ใส่ IP นั้นใน "IP ที่อนุญาต" ของ API Key ที่ MADD

== ปรับหน้าตา ==
Override CSS custom properties ใน theme เช่น
  .madd-rate { --madd-accent: #0f766e; --madd-radius: 6px; }
