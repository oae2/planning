// api/shorten.js — Vercel Serverless Function

export default async function handler(req, res) {
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'GET, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type');

  if (req.method === 'OPTIONS') return res.status(200).end();
  if (req.method !== 'GET')     return res.status(405).json({ error: 'Method not allowed' });

  const { url, alias } = req.query;
  if (!url) return res.status(400).json({ error: 'Missing ?url= parameter' });

  // ── ตัด tracking parameters ─────────────────────────────────
  let cleanedUrl = url;
  try {
    const parsed = new URL(url);
    ['usp','utm_source','utm_medium','utm_campaign',
     'utm_term','utm_content','fbclid','gclid','ref'].forEach(p => {
      parsed.searchParams.delete(p);
    });
    cleanedUrl = parsed.hostname === 'drive.google.com'
      ? parsed.origin + parsed.pathname   // Google Drive → canonical
      : parsed.toString();
  } catch (e) { cleanedUrl = url; }

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
      errors.push(`v.gd: ${text.substring(0, 120)}`);
    } else { errors.push(`v.gd: HTTP ${vgdRes.status}`); }
  } catch (e) { errors.push(`v.gd: ${e.message}`); }

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
      errors.push(`is.gd: ${text.substring(0, 120)}`);
    } else { errors.push(`is.gd: HTTP ${isgdRes.status}`); }
  } catch (e) { errors.push(`is.gd: ${e.message}`); }

  // ── 3. da.gd (fallback เงียบ — ไม่มีโฆษณา ไม่ต้องสมัคร) ───
  // หมายเหตุ: เพิ่มเป็น fallback เฉพาะเมื่อ v.gd/is.gd บล็อก URL เช่น Google Drive
  try {
    const dagdRes = await fetch(
      `https://da.gd/shorten?url=${encodeURIComponent(cleanedUrl)}`,
      {
        headers: { 'User-Agent': 'Mozilla/5.0 OAE-URL-Shortener/1.0' },
        signal: AbortSignal.timeout(8000)
      }
    );
    if (dagdRes.ok) {
      const text = (await dagdRes.text()).trim();
      if (text.startsWith('http')) {
        return res.status(200).json({ shortUrl: text, service: 'da.gd' });
      }
      errors.push(`da.gd: ${text.substring(0, 120)}`);
    } else { errors.push(`da.gd: HTTP ${dagdRes.status}`); }
  } catch (e) { errors.push(`da.gd: ${e.message}`); }

  // ── ทุก service ล้มเหลว ─────────────────────────────────────
  console.error('All URL shorteners failed:', errors);
  return res.status(503).json({
    error: 'All URL shortening services failed',
    details: errors
  });
}
