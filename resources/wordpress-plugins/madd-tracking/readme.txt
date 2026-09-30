=== MADD Tracking ===
Requires at least: 5.8
Requires PHP: 7.4
Stable tag: 1.0.1

ฟอร์มติดตามพัสดุ (UPS / DHL) สำหรับ Shipment ที่จองผ่านระบบ MADD

== ติดตั้ง ==
1. WordPress Admin › Plugins › Add New › Upload Plugin › เลือก madd-tracking.zip › Install › Activate
2. MADD: System Settings › Public API (Website) › สร้าง API Key
   - เปิด "ใช้ติดตามพัสดุได้" (ปิด "ใช้เช็คราคาได้" ได้ถ้ายังไม่ใช้)
   - คัดลอก Key ไว้ทันที (แสดงครั้งเดียว)
3. ใส่ใน wp-config.php (แนะนำ — Key จะไม่ถูกเก็บในฐานข้อมูล WordPress) เหนือบรรทัด "That's all, stop editing!"
     define('MADD_RATE_API_URL', 'https://<madd-backend>/api');
     define('MADD_RATE_API_KEY', 'madd_...');
   หรือกรอกที่ Settings › MADD Tracking
4. Settings › MADD Tracking › กด "ทดสอบ" (เว้นว่าง = ทดสอบการเชื่อมต่อ, หรือใส่เลข Tracking จริง)
5. สร้างหน้า เช่น "ติดตามพัสดุ" แล้วใส่ shortcode:
     [madd_tracking]
     [madd_tracking title="ติดตามพัสดุของคุณ"]
6. ลิงก์ตรงส่งให้ลูกค้า: https://your-site/ติดตามพัสดุ/?tn=5084355500

== ความปลอดภัย ==
- Browser ลูกค้าคุยกับ WordPress เท่านั้น — WordPress เรียก MADD จากฝั่ง Server, API Key ไม่ถูกส่งไป Browser
- ค้นได้เฉพาะ Shipment ที่จองผ่าน MADD และแสดงเฉพาะสถานะ / จุดสแกน — ไม่มีชื่อ ที่อยู่ เบอร์โทร หรือราคา
- MADD จำกัดจำนวนครั้งต่อ Key และต่อ IP ลูกค้า
- แนะนำเปิด Cloudflare Turnstile (ฟรี) ที่ Settings › MADD Tracking

== หมายเหตุ ==
- ถ้าติดตั้ง MADD Rate Calculator (ตัวเต็ม) ภายหลัง ให้ปิด MADD Tracking — ตัวเต็มมี [madd_tracking] อยู่แล้ว
- ผู้ดูแลเว็บ (Admin) ที่ Login อยู่จะเห็นข้อความ Error จริงเวลาเชื่อมต่อไม่ได้ ลูกค้าทั่วไปจะเห็นแค่ "ไม่พร้อมใช้งานชั่วคราว"

== ปรับหน้าตา ==
Appearance › Customize › Additional CSS
  .madd-tracking { --madd-accent: #0f766e; --madd-radius: 8px; }
