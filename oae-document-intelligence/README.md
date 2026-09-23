# OAE Document Intelligence v2.3.1 – AI PDF Engine

รุ่นนี้อัปเกรดจาก v2.3.0 โดยคง Seed Set 10 เอกสารและฐาน Document Intelligence เดิมไว้ พร้อมเพิ่ม AI PDF Engine สำหรับสภาพแวดล้อม GDCC ที่ไม่มีสิทธิ์ติดตั้ง Poppler/Tesseract.

## Workflow
PDF Upload → Local extraction (ถ้ามี) → AI PDF Engine (Gemini native PDF) → AI verification รอบที่ 2 → Needs Review / AI Verified → Human Verify & Publish → OAE AI Assistance KB

## จุดสำคัญ
- ใช้ Gemini API key จาก option `oaeaiu_gemini_api_key` ของ OAE AI Assistance เดิม
- มีเมนูย่อย `OAE Document Intelligence > AI PDF Engine`
- AI Process PDF อ่าน PDF ต้นฉบับโดยตรง รวมข้อความ ตาราง metadata และ document relationships
- ประมวลผล 2 รอบ: extraction + independent verification
- ไม่ publish เข้า KB อัตโนมัติหลัง AI; ต้อง Human Verify & Publish
- ลบไฟล์ที่ upload ไป Gemini หลังประมวลผล
- บันทึก AI processing audit และ usage metadata
- หาก AI อ่านไม่ชัด ให้เก็บ warning/flag และเข้าสถานะ Needs Review
- Seed Set 10 เอกสารเดิมยังอยู่ครบ

## การติดตั้ง
ดาวน์โหลด ZIP ของ branch `oae-document-intelligence-v2.3.1-ai-pdf-engine` แล้วแตก ZIP จากนั้น ZIP เฉพาะโฟลเดอร์ `oae-document-intelligence` เพื่อนำไปติดตั้ง/แทน v2.3.0 ใน WordPress.

หลัง Activate ตรวจว่า OAE AI Assistance มี Gemini API key และเข้า `OAE Document Intelligence > AI PDF Engine`.

## Privacy
การส่ง PDF ไป Gemini เป็น external AI processing. ใช้เฉพาะเอกสารที่หน่วยงานอนุญาตให้ส่งออกไปประมวลผลภายนอก และเจ้าหน้าที่ต้อง Verify ก่อน Publish เข้า KB.
