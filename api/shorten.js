// api/shorten.js — Vercel Serverless Function
// วางไฟล์นี้ใน folder: api/shorten.js

export default async function handler(req, res) {
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'GET, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type');

  if (req.method === 'OPTIONS') return res.status(200).end();
  if (req.method !== 'GET')     return res.status(405).json({ error: 'Method not allowed' });

  const { url, alias } = req.query;
  if (!url) return res.status(400).json({ error: 'Missing ?url= parameter' });

  // ── ตัด tracking parameters ที่ไม่จำเป็นออกก่อน ────────────
  // เช่น ?usp=drive_link, ?usp=sharing ใน Google Drive
  // เพิ่มโอกาสให้ URL shortener ยอมรับ URL
  let cleanedUrl = url;
  try {
    const parsed = new URL(url);
    // ลบ tracking params ที่รู้จัก
    ['usp', 'utm_source', 'utm_medium', 'utm_campaign',
     'utm_term', 'utm_content', 'fbclid', 'gclid', 'ref'].forEach(p => {
      parsed.searchParams.delete(p);
    });
    // ถ้าเป็น Google Drive folder/file — ใช้ URL แบบ canonical
    if (parsed.hostname === 'drive.google.com') {
      // เก็บเฉพาะ path ที่จำเป็น
      cleanedUrl = parsed.origin + parsed.pathname;
    } else {
      cleanedUrl = parsed.toString();
    }
  } catch (e) {
    cleanedUrl = url; // ถ้า parse ไม่ได้ ใช้ URL เดิม
  }

  const errors = [];

  // ── 1. v.gd ────────────────────────────────────────────────
  try {
    let vgdUrl = `https://v.gd/create.php?format=simple&url=${encodeURIComponent(cleanedUrl)}`;
    if (alias) vgdUrl += `&shorturl=${encodeURIComponent(alias)}`;

    const vgdRes = await fetch(vgdUrl, {
      headers: { 'User-Agent': 'Mozilla/5.0 OAE-URL-Shortener/1.0' },
      signal: AbortSignal.timeout(8000)
    });
    if (vgdRes.ok) {
      const text = (await vgdRes.text()).trim();
      if (text.startsWith('http')) {
        return res.status(200).json({ shortUrl: text, service: 'v.gd' });
      }
      errors.push(`v.gd: ${text.substring(0, 80)}`);
    } else {
      errors.push(`v.gd: HTTP ${vgdRes.status}`);
    }
  } catch (e) {
    errors.push(`v.gd: ${e.message}`);
  }

  // ── 2. is.gd ───────────────────────────────────────────────
  try {
    let isgdUrl = `https://is.gd/create.php?format=simple&url=${encodeURIComponent(cleanedUrl)}`;
    if (alias) isgdUrl += `&shorturl=${encodeURIComponent(alias)}`;

    const isgdRes = await fetch(isgdUrl, {
      headers: { 'User-Agent': 'Mozilla/5.0 OAE-URL-Shortener/1.0' },
      signal: AbortSignal.timeout(8000)
    });
    if (isgdRes.ok) {
      const text = (await isgdRes.text()).trim();
      if (text.startsWith('http')) {
        return res.status(200).json({ shortUrl: text, service: 'is.gd' });
      }
      errors.push(`is.gd: ${text.substring(0, 80)}`);
    } else {
      errors.push(`is.gd: HTTP ${isgdRes.status}`);
    }
  } catch (e) {
    errors.push(`is.gd: ${e.message}`);
  }

  // ── ทุก service ล้มเหลว ─────────────────────────────────────
  console.error('All URL shorteners failed:', errors);
  return res.status(503).json({
    error: 'All URL shortening services failed',
    details: errors
  });
}
