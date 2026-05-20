// api/shorten.js — Vercel Serverless Function
// วางไฟล์นี้ใน folder: api/shorten.js
// Vercel จะ deploy เป็น endpoint: https://your-app.vercel.app/api/shorten

export default async function handler(req, res) {
  // CORS headers
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'GET, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type');

  if (req.method === 'OPTIONS') return res.status(200).end();
  if (req.method !== 'GET') return res.status(405).json({ error: 'Method not allowed' });

  const { url, alias } = req.query;
  if (!url) return res.status(400).json({ error: 'Missing ?url= parameter' });

  const errors = [];

  // ── 1. ลอง v.gd ────────────────────────────────────────────
  try {
    let vgdUrl = `https://v.gd/create.php?format=simple&url=${encodeURIComponent(url)}`;
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

  // ── 2. ลอง is.gd (พี่สาวของ v.gd) ─────────────────────────
  try {
    let isgdUrl = `https://is.gd/create.php?format=simple&url=${encodeURIComponent(url)}`;
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

  // ── 3. ลอง cleanuri.com ─────────────────────────────────────
  try {
    const cleanRes = await fetch('https://cleanuri.com/api/v1/shorten', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        'User-Agent': 'Mozilla/5.0 OAE-URL-Shortener/1.0'
      },
      body: `url=${encodeURIComponent(url)}`,
      signal: AbortSignal.timeout(8000)
    });

    if (cleanRes.ok) {
      const data = await cleanRes.json();
      if (data && data.result_url) {
        return res.status(200).json({ shortUrl: data.result_url, service: 'cleanuri.com' });
      }
      errors.push(`cleanuri.com: ${JSON.stringify(data).substring(0, 80)}`);
    } else {
      const errText = await cleanRes.text().catch(() => '');
      errors.push(`cleanuri.com: HTTP ${cleanRes.status} ${errText.substring(0, 60)}`);
    }
  } catch (e) {
    errors.push(`cleanuri.com: ${e.message}`);
  }

  // ── ทุก service ล้มเหลว ─────────────────────────────────────
  console.error('All URL shorteners failed:', errors);
  return res.status(503).json({
    error: 'All URL shortening services failed',
    details: errors
  });
}
