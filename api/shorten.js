// api/shorten.js — Vercel Serverless Function
// Free providers (in failover order): is.gd -> cleanuri.com -> v.gd

const REQUEST_TIMEOUT_MS = 3000;
const MAX_URL_LENGTH = 8192;
const ALIAS_PATTERN = /^[A-Za-z0-9_]{5,30}$/;

function firstValue(value) {
  return Array.isArray(value) ? value[0] : value;
}

function setCorsHeaders(req, res) {
  const requestOrigin = req.headers?.origin || '';
  const configuredOrigins = String(process.env.ALLOWED_ORIGINS || '')
    .split(',')
    .map(origin => origin.trim())
    .filter(Boolean);

  // Backward-compatible default. For production, set ALLOWED_ORIGINS to the
  // GitHub Pages URL, e.g. https://your-account.github.io
  if (configuredOrigins.length === 0) {
    res.setHeader('Access-Control-Allow-Origin', '*');
    return true;
  }

  if (!requestOrigin || configuredOrigins.includes(requestOrigin)) {
    res.setHeader('Access-Control-Allow-Origin', requestOrigin || configuredOrigins[0]);
    res.setHeader('Vary', 'Origin');
    return true;
  }

  return false;
}

function cleanLongUrl(rawUrl) {
  const input = String(rawUrl || '').trim();
  if (!input || input.length > MAX_URL_LENGTH) {
    throw new Error(input ? 'URL is too long' : 'Missing ?url= parameter');
  }

  const parsed = new URL(input);
  if (!['http:', 'https:'].includes(parsed.protocol)) {
    throw new Error('Only http:// and https:// URLs are supported');
  }
  if (parsed.username || parsed.password) {
    throw new Error('URLs containing credentials are not supported');
  }

  // Remove only well-known advertising parameters. Do not remove `ref`,
  // Google Drive query strings, or other parameters that may be functional.
  const exactTrackingParams = new Set([
    'fbclid', 'gclid', 'dclid', 'msclkid', 'igshid',
    'mc_cid', 'mc_eid', 'yclid', '_hsenc', '_hsmi'
  ]);

  for (const key of [...parsed.searchParams.keys()]) {
    if (/^utm_/i.test(key) || exactTrackingParams.has(key.toLowerCase())) {
      parsed.searchParams.delete(key);
    }
  }

  return parsed.toString();
}

async function fetchWithTimeout(url, options = {}) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS);
  try {
    return await fetch(url, { ...options, signal: controller.signal });
  } finally {
    clearTimeout(timer);
  }
}

function validateShortUrl(candidate, expectedHost) {
  const parsed = new URL(String(candidate || '').trim());
  if (parsed.protocol !== 'https:' || parsed.hostname !== expectedHost) {
    throw new Error('Provider returned an invalid short URL');
  }
  return parsed.toString();
}

async function shortenWithIsGdFamily(service, longUrl, alias) {
  const endpoint = new URL(`https://${service}/create.php`);
  endpoint.searchParams.set('format', 'json');
  endpoint.searchParams.set('url', longUrl);
  if (alias) endpoint.searchParams.set('shorturl', alias);

  const response = await fetchWithTimeout(endpoint, {
    method: 'GET',
    headers: {
      'Accept': 'application/json',
      'User-Agent': 'OAE-URL-Shortener/2.0'
    }
  });

  const body = await response.text();
  let data;
  try {
    data = JSON.parse(body);
  } catch {
    throw new Error(`HTTP ${response.status}: invalid JSON response`);
  }

  if (!response.ok || !data.shorturl) {
    const message = data.errormessage || `HTTP ${response.status}`;
    const error = new Error(message);
    error.providerCode = data.errorcode;
    throw error;
  }

  return validateShortUrl(data.shorturl, service);
}

async function shortenWithCleanUri(longUrl) {
  const response = await fetchWithTimeout('https://cleanuri.com/api/v1/shorten', {
    method: 'POST',
    headers: {
      'Accept': 'application/json',
      'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
      'User-Agent': 'OAE-URL-Shortener/2.0'
    },
    body: new URLSearchParams({ url: longUrl }).toString()
  });

  const body = await response.text();
  let data;
  try {
    data = JSON.parse(body);
  } catch {
    throw new Error(`HTTP ${response.status}: invalid JSON response`);
  }

  if (!response.ok || !data.result_url) {
    throw new Error(data.error || `HTTP ${response.status}`);
  }

  return validateShortUrl(data.result_url, 'cleanuri.com');
}

function safeErrorMessage(error) {
  if (error?.name === 'AbortError') return `timeout after ${REQUEST_TIMEOUT_MS}ms`;
  return String(error?.message || 'unknown error').slice(0, 180);
}

export default async function handler(req, res) {
  const corsAllowed = setCorsHeaders(req, res);
  res.setHeader('Access-Control-Allow-Methods', 'GET, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type');
  res.setHeader('Cache-Control', 'no-store');

  if (req.method === 'OPTIONS') {
    return corsAllowed ? res.status(204).end() : res.status(403).end();
  }
  if (!corsAllowed) {
    return res.status(403).json({ error: 'Origin not allowed' });
  }
  if (req.method !== 'GET') {
    return res.status(405).json({ error: 'Method not allowed' });
  }

  const rawUrl = firstValue(req.query?.url);
  const rawAlias = firstValue(req.query?.alias);
  const alias = String(rawAlias || '').trim();

  let cleanedUrl;
  try {
    cleanedUrl = cleanLongUrl(rawUrl);
  } catch (error) {
    return res.status(400).json({ error: safeErrorMessage(error) });
  }

  if (alias && !ALIAS_PATTERN.test(alias)) {
    return res.status(400).json({
      error: 'Invalid alias',
      details: ['Alias must contain 5-30 letters, numbers, or underscores']
    });
  }

  // cleanuri.com does not support a custom alias. When alias is requested,
  // try only providers that can preserve it. The frontend may retry without
  // alias if it wants a random fallback.
  const providers = alias
    ? [
        { name: 'is.gd', run: () => shortenWithIsGdFamily('is.gd', cleanedUrl, alias) },
        { name: 'v.gd', run: () => shortenWithIsGdFamily('v.gd', cleanedUrl, alias) }
      ]
    : [
        { name: 'is.gd', run: () => shortenWithIsGdFamily('is.gd', cleanedUrl) },
        { name: 'cleanuri.com', run: () => shortenWithCleanUri(cleanedUrl) },
        { name: 'v.gd', run: () => shortenWithIsGdFamily('v.gd', cleanedUrl) }
      ];

  const errors = [];
  for (const provider of providers) {
    try {
      const shortUrl = await provider.run();
      return res.status(200).json({
        shortUrl,
        service: provider.name,
        cleanedUrl
      });
    } catch (error) {
      errors.push(`${provider.name}: ${safeErrorMessage(error)}`);
    }
  }

  console.error('All URL shorteners failed:', errors);
  return res.status(503).json({
    error: alias
      ? 'Custom alias could not be created'
      : 'All URL shortening services failed',
    details: errors
  });
}
