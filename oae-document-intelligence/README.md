# OAE AI Agent Unified v2.3.0 — Document Intelligence

สถานะ: companion module สำหรับ OAE AI Assistance / OAE AI Agent Unified

## เป้าหมาย
เพิ่มชั้น Document Intelligence ก่อนข้อมูล PDF ถูกนำไปใช้ตอบใน RAG เพื่อให้เอกสารราชการไทยที่เป็น Native PDF, Scan PDF, Legacy Font และ Mixed PDF ถูกจัดการอย่างปลอดภัยและตรวจสอบย้อนกลับได้

## Pipeline

```text
PDF Upload
  -> classify / hash / deduplicate
  -> native pdftotext
  -> local OCR fallback (Poppler + Tesseract tha+eng)
  -> Unicode/Thai normalization
  -> quality flags
  -> metadata + document relationships
  -> needs_review
  -> human verification
  -> legal-aware chunking
  -> sync to wp_oaeaiu_kb_docs / wp_oaeaiu_kb_chunks
```

หลักการสำคัญ: **Fail closed** — PDF ใหม่ไม่ถูก Publish ไป Production KB จนกว่าจะ Verify

## Database
โมดูลนี้เพิ่มตารางใหม่โดยไม่ลบข้อมูลระบบเดิม
- `wp_oaeodi_docs` — canonical document / metadata / status / quality / source hash
- `wp_oaeodi_pages` — page-level normalized text + quality
- `wp_oaeodi_audit` — ประวัติ upload/process/review/verify/sync

เมื่อพบตารางเดิมของ OAE AI Agent (`wp_oaeaiu_kb_docs`, `wp_oaeaiu_kb_chunks`) จะ Sync เฉพาะเอกสารสถานะ `verified` และตรวจ column ที่มีอยู่จริงก่อนเขียน เพื่อรองรับ schema ต่างรุ่น

## Seed Set 1 — 10 เอกสาร
รวมข้อมูลตั้งต้นด้าน:
- การฝึกอบรม/สัมมนา
- การศึกษาในประเทศ/ต่างประเทศ
- การลา
- การทำสัญญาและการชดใช้เงิน
- การเทียบตำแหน่ง
- การมอบอำนาจ/อำนาจอนุมัติ

ไฟล์ `data/seed-manifest.json` ระบุ provenance, document number/date, extraction method, coverage, quality flags และ relationships

### Governance สำคัญ
เอกสารปี 2549 เรื่องค่าใช้จ่ายฝึกอบรมถูกเก็บเป็น `needs_review` เพราะบางส่วนถูกแก้ไข/แทนที่โดยฉบับที่ 3 พ.ศ. 2555 จึง **ไม่ Auto-publish** ไป Production KB

เอกสารที่เป็น `VERIFIED KEY EXTRACT` มีข้อความเตือนในเนื้อหาอย่างชัดเจนว่าไม่ใช่ full-text และห้ามอนุมาน provisions ที่ไม่ได้อยู่ใน extract

## การติดตั้ง
1. Backup WordPress database และ plugin OAE AI Agent รุ่นปัจจุบัน
2. ติดตั้งโมดูล `oae-document-intelligence` เป็น plugin เพิ่มเติม (ไม่ต้องลบ OAE AI Agent เดิม)
3. Activate — ระบบสร้างตารางและ Import Seed Set
4. เข้า `OAE Document Intelligence`
5. ตรวจ Environment diagnostics
6. สำหรับ Server ที่ต้อง OCR scan PDF ควรมี:
   - `pdftotext`
   - `pdfinfo`
   - `pdftoppm`
   - `tesseract` + Thai language data (`tha+eng`)
7. ตรวจรายการ Seed และเอกสาร `needs_review`
8. กด `Verify & Publish to KB` เฉพาะเอกสารที่ตรวจแล้ว
9. กลับไป OAE AI Agent > RAG Diagnostics ทดสอบชุดคำถาม Acceptance Test

## PDF ใหม่หลังจากนี้
เข้า `OAE Document Intelligence > เพิ่ม PDF` แล้วลากไฟล์ PDF ได้หลายไฟล์พร้อมกัน

ระบบจะ:
1. ตรวจ `%PDF-` signature และจำกัดขนาด 50MB
2. ทำ SHA-256
3. Extract native text ก่อน
4. ถ้าข้อความไม่พอ ใช้ local OCR
5. Normalize Unicode/Thai
6. สร้าง quality score/flags และ metadata
7. ตั้งสถานะ `needs_review`
8. เจ้าหน้าที่ตรวจ/แก้ normalized text
9. กด `Verify & Publish to KB`

ไฟล์เดิม/ชื่อ Seed เดิมจะอัปเดต canonical document แทนการสร้างซ้ำ

## Acceptance Tests ที่แนะนำ
- ถาม `ค่ากระเป๋าในการฝึกอบรมเบิกได้เท่าไร` → ต้อง retrieve ฉบับ 2555 และพบไม่เกิน 300 บาท
- ถาม `วิทยากรที่ไม่ใช่บุคลากรของรัฐ ประเภท ก ได้เท่าไรต่อชั่วโมง` → ต้องพบ 1,600 บาท/ชั่วโมง
- ถาม `ลาพักผ่อนประจำปีได้กี่วัน` → ต้องพบ 10 วันทำการ และแยกเรื่องสะสม 20/30 วันได้
- ถาม `แบบฟอร์มกลางฝึกอบรมของ สศก. มีกี่แบบ` → ต้องพบ 4 แบบ
- ถาม `คำสั่ง 227/2560 เกี่ยวกับอะไร` → ต้อง retrieve เอกสารมอบอำนาจ
- ถาม `ระเบียบฝึกอบรม 2549 ข้อ 8 ใช้ตอบปัจจุบันหรือไม่` → ระบบต้องไม่เลือก historical text ที่ถูกแทนที่เป็นหลักฐานปัจจุบัน
- ถามข้อมูลที่ไม่มีหลักฐาน → Strict KB ต้องตอบว่าไม่พบข้อมูลเพียงพอ ไม่เดา

## Non-claims / ข้อจำกัด
- ไม่อ้างว่า OCR ถูกต้อง 100%
- เป้าหมายคือ 100% traceability + human verification สำหรับข้อมูลสำคัญ
- Seed `key_extract` ไม่ใช่ full-text replacement; ต้องใช้ต้นฉบับเมื่อถามรายละเอียดที่อยู่นอก extract
- การติดตั้ง/Run บน WordPress GDCC จริงและการเชื่อมกับ production DB ต้องทำบน staging/production ของหน่วยงานและตรวจ Acceptance Test ก่อนเปิดใช้จริง
