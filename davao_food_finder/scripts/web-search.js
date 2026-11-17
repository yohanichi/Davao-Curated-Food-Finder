const https = require('https');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { chromium } = require('playwright');

const PLAYWRIGHT_STEP_TIMEOUT = parseInt(process.env.PLAYWRIGHT_STEP_TIMEOUT, 10) || 10000;
const HTTP_TIMEOUT = Math.min(8000, PLAYWRIGHT_STEP_TIMEOUT);
const SEARCH_NAVIGATION_TIMEOUT = Math.min(10000, PLAYWRIGHT_STEP_TIMEOUT);
const SEARCH_RESULT_WAIT_TIMEOUT = Math.min(2000, PLAYWRIGHT_STEP_TIMEOUT);
const NOMINATIM_BASE_URL = process.env.NOMINATIM_BASE_URL === undefined
  ? 'https://nominatim.openstreetmap.org'
  : process.env.NOMINATIM_BASE_URL.trim();
const NOMINATIM_CACHE_DIR = path.join(__dirname, '..', 'data', '.web-cache');
const NOMINATIM_CACHE_TTL = 12 * 60 * 60 * 1000;
const NOMINATIM_MIN_INTERVAL = 1000;

async function waitForSearchResults(page, selector, source, errors) {
  try {
    await page.locator(selector).first().waitFor({
      state: 'attached',
      timeout: SEARCH_RESULT_WAIT_TIMEOUT
    });
  } catch (error) {
    if (error.name !== 'TimeoutError') throw error;
    errors.push(`${source}: no result elements appeared within ${SEARCH_RESULT_WAIT_TIMEOUT} ms.`);
  }
}

function decodeRedirectUrl(rawUrl) {
  if (!rawUrl) return '';
  try {
    const normalizedUrl = String(rawUrl).replace(/&amp;/gi, '&').trim();
    const full = normalizedUrl.startsWith('//') ? 'https:' + normalizedUrl : normalizedUrl;
    const parsed = new URL(full, 'https://html.duckduckgo.com');
    // Decode DuckDuckGo redirect: //duckduckgo.com/l/?uddg=https%3A%2F%2F...
    const uddg = parsed.searchParams.get('uddg');
    if (uddg && /^https?:\/\//i.test(uddg)) {
      return uddg;
    }
    // Decode Bing click-tracking redirect: https://www.bing.com/ck/a?...&u=a1<base64url>
    if (/bing\.com\/ck\/a/i.test(parsed.href)) {
      const uParam = parsed.searchParams.get('u') || '';
      if (uParam.startsWith('a1')) {
        const b64 = uParam.slice(2).replace(/-/g, '+').replace(/_/g, '/');
        const padded = b64 + '='.repeat((4 - (b64.length % 4)) % 4);
        const decoded = Buffer.from(padded, 'base64').toString('utf8').trim();
        if (/^https?:\/\//i.test(decoded)) {
          return decoded;
        }
      }
    }
    return parsed.href;
  } catch {
    return rawUrl;
  }
}

function isLowValueNonFoodUrl(url, title) {
  const u = String(url || '').toLowerCase();
  const t = String(title || '').toLowerCase();
  if (!u.startsWith('http')) return true;
  if (/wikipedia\.org\/wiki\/(davao|mindanao|philippines|list_of)/i.test(u)) return true;
  if (/^(davao city|davao region|mindanao)\s*[-|–]\s*wikipedia/i.test(t)) return true;
  if (/britannica\.com|merriam-webster\.com|dictionary\.com|wikidata\.org|philatlas\.com/i.test(u)) return true;
  if (/must-visit-davao-tourist-spot|things-to-do-in-davao(?!.*(food|eat|restaur|cafe))/i.test(u)) return true;
  // Reject home cooking recipe blogs
  if (/panlasangpinoy\.com|kawalingpinoy\.com|yummykitchentv\.com|foxyfolksy\.com|kusinasecrets\.com|allrecipes\.com|recipetineats\.com|mamasguiderecipes\.com|angsarap\.net|lutongbahayrecipe\.com/i.test(u)) return true;
  if (/\b(?:recipe|how to cook|ingredients|instructions)\b/i.test(t) && !/\b(?:restaurant|cafe|bistro|grill|eatery|food)\b/i.test(t)) return true;
  // Reject search engine map queries and non-venue area pins
  if (/google\.com\/maps\/search\//i.test(u)) return true;
  if (/maps\.apple\.com\/place\?auid=/i.test(u)) return true;
  if (/^google$/i.test(t) || /explore in google maps/i.test(t)) return true;
  // Reject non-food government and community announcement pages
  if (/barangay\b.*(announcement|information|proper|official)|city government of davao/i.test(t)) return true;
  if (/philippines\.travel\/destinations|davaocity\.gov\.ph/i.test(u)) return true;
  return false;
}

function fetchDuckDuckGoHttp(searchQuery) {
  return new Promise((resolve) => {
    const url = 'https://html.duckduckgo.com/html/?q=' + encodeURIComponent(searchQuery);
    const req = https.get(url, {
      headers: {
        'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language': 'en-US,en;q=0.9'
      },
      timeout: HTTP_TIMEOUT
    }, res => {
      if (res.statusCode < 200 || res.statusCode >= 300) {
        return resolve([]);
      }
      let html = '';
      res.on('data', chunk => {
        html += chunk;
        if (html.length > 250000) {
          req.destroy();
          resolve(parseDuckDuckGoHtml(html));
        }
      });
      res.on('end', () => resolve(parseDuckDuckGoHtml(html)));
    });
    req.on('error', () => resolve([]));
    req.on('timeout', () => { req.destroy(); resolve([]); });
  });
}

function parseDuckDuckGoHtml(html) {
  const regex = /<a[^>]*class="result__a"[^>]*href="([^"]+)"[^>]*>([\s\S]*?)<\/a>[\s\S]*?<a[^>]*class="result__snippet"[^>]*>([\s\S]*?)<\/a>/gi;
  const items = [];
  let m;
  while ((m = regex.exec(html)) !== null) {
    const rawUrl = m[1];
    const decoded = decodeRedirectUrl(rawUrl);
    const title = m[2].replace(/<[^>]+>/g, '').trim();
    const snippet = m[3].replace(/<[^>]+>/g, '').trim();
    if (title && decoded && !isLowValueNonFoodUrl(decoded, title)) {
      items.push({ title, url: decoded, snippet });
    }
    if (items.length >= 10) break;
  }
  return items;
}

function parseBraveBrowserResults(rawItems) {
  return rawItems
    .map(item => ({
      title: String(item.title || '').trim(),
      url: decodeRedirectUrl(item.url),
      snippet: String(item.snippet || '').trim(),
      playwright_search_result: true
    }))
    .filter(item => item.title && !isLowValueNonFoodUrl(item.url, item.title));
}

function parseBraveSearchApiResults(payload, resultLimit = 10) {
  const limit = Math.max(1, Math.min(20, Math.trunc(Number(resultLimit) || 10)));
  const results = payload && payload.web && Array.isArray(payload.web.results)
    ? payload.web.results
    : [];
  return results.slice(0, limit)
    .map(item => ({
      title: String(item.title || '').trim(),
      url: decodeRedirectUrl(item.url),
      snippet: String(item.description || '').trim(),
      playwright_search_result: false,
      discovery_provider: 'Brave Search API'
    }))
    .filter(item => item.title && !isLowValueNonFoodUrl(item.url, item.title));
}

function retryAfterTimestamp(retryAfter, now = Date.now()) {
  const value = String(retryAfter || '').trim();
  if (/^\d+(?:\.\d+)?$/.test(value)) {
    return now + Math.max(0, Number(value) * 1000);
  }
  const parsed = Date.parse(value);
  return Number.isFinite(parsed) ? Math.max(now, parsed) : null;
}

function braveCooldownFile(cacheDir, source) {
  return path.join(cacheDir, `brave-${source}-cooldown.json`);
}

function readBraveCooldown(cacheDir, source, errors = []) {
  try {
    const value = JSON.parse(fs.readFileSync(braveCooldownFile(cacheDir, source), 'utf8'));
    return Number.isFinite(value.until) ? value.until : 0;
  } catch (error) {
    if (error.code !== 'ENOENT') errors.push(`Brave ${source} cooldown: ${error.message}`);
    return 0;
  }
}

function writeBraveCooldown(cacheDir, source, retryAfter, errors = []) {
  try {
    fs.mkdirSync(cacheDir, { recursive: true });
    const until = retryAfterTimestamp(retryAfter) || Date.now() + 60000;
    fs.writeFileSync(braveCooldownFile(cacheDir, source), JSON.stringify({ until }), 'utf8');
    return until;
  } catch (error) {
    errors.push(`Brave ${source} cooldown could not be saved: ${error.message}`);
    return 0;
  }
}

function fetchBraveSearchApi(searchQuery, resultLimit = 8, errors = [], cacheDir = NOMINATIM_CACHE_DIR) {
  const apiKey = String(process.env.BRAVE_SEARCH_API_KEY || '').trim();
  if (!apiKey) return Promise.resolve([]);

  const cooldownUntil = readBraveCooldown(cacheDir, 'api', errors);
  if (cooldownUntil > Date.now()) {
    errors.push(`Brave Search API: skipped during rate-limit cooldown until ${new Date(cooldownUntil).toISOString()}.`);
    return Promise.resolve([]);
  }

  const url = new URL('https://api.search.brave.com/res/v1/web/search');
  url.searchParams.set('q', searchQuery);
  url.searchParams.set('count', String(Math.max(1, Math.min(20, Math.trunc(Number(resultLimit) || 8)))));
  url.searchParams.set('country', 'PH');
  url.searchParams.set('search_lang', 'en');

  return new Promise(resolve => {
    let settled = false;
    const finish = results => {
      if (settled) return;
      settled = true;
      resolve(results);
    };
    const req = https.get(url, {
      headers: {
        'X-Subscription-Token': apiKey,
        'Accept': 'application/json'
      },
      timeout: HTTP_TIMEOUT
    }, res => {
      if (res.statusCode < 200 || res.statusCode >= 300) {
        if (res.statusCode === 429) writeBraveCooldown(cacheDir, 'api', res.headers['retry-after'], errors);
        const retryAfter = res.headers['retry-after'];
        errors.push(`Brave Search API: HTTP ${res.statusCode || 'unknown status'}${retryAfter ? ` (Retry-After: ${retryAfter})` : ''}`);
        res.resume();
        return finish([]);
      }

      let body = '';
      res.on('data', chunk => {
        body += chunk;
        if (body.length > 500000) {
          errors.push('Brave Search API: response exceeded 500 KB.');
          req.destroy();
          finish([]);
        }
      });
      res.on('end', () => {
        if (settled) return;
        try {
          finish(parseBraveSearchApiResults(JSON.parse(body), resultLimit));
        } catch (error) {
          errors.push(`Brave Search API: invalid JSON response (${error.message}).`);
          finish([]);
        }
      });
    });
    req.on('error', error => {
      if (!settled) errors.push(`Brave Search API: ${error.message}`);
      finish([]);
    });
    req.on('timeout', () => {
      if (!settled) errors.push(`Brave Search API: request timed out after ${HTTP_TIMEOUT} ms.`);
      req.destroy();
      finish([]);
    });
  });
}

async function collectBraveBrowser(context, searchQuery, errors, cacheDir = NOMINATIM_CACHE_DIR) {
  const cooldownUntil = readBraveCooldown(cacheDir, 'browser', errors);
  if (cooldownUntil > Date.now()) {
    errors.push(`Brave: skipped during rate-limit cooldown until ${new Date(cooldownUntil).toISOString()}.`);
    return [];
  }
  const page = await context.newPage();
  await page.setDefaultTimeout(PLAYWRIGHT_STEP_TIMEOUT);
  try {
    const braveUrl = 'https://search.brave.com/search?q=' + encodeURIComponent(searchQuery) + '&source=web';
    const response = await page.goto(braveUrl, { waitUntil: 'commit', timeout: SEARCH_NAVIGATION_TIMEOUT });
    if (response && !response.ok()) {
      if (response.status() === 429) {
        writeBraveCooldown(cacheDir, 'browser', response.headers()['retry-after'], errors);
      }
      errors.push(`Brave: HTTP ${response.status()}`);
      return [];
    }
    await waitForSearchResults(page, 'div.snippet[data-type="web"]', 'Brave', errors);
    const rawItems = await page.locator('div.snippet[data-type="web"]').evaluateAll(nodes =>
      nodes.slice(0, 12).map(node => {
        const a = node.querySelector('a[href^="http"]');
        const title = node.querySelector('.title.search-snippet-title');
        const snippet = node.querySelector('.generic-snippet .content');
        return {
          title: title
            ? (title.getAttribute('title') || title.textContent || '').trim()
            : a ? (a.textContent || '').trim() : '',
          url: a ? (a.getAttribute('href') || a.href || '') : '',
          snippet: snippet ? (snippet.textContent || '').trim() : ''
        };
      })
    );
    return parseBraveBrowserResults(rawItems);
  } catch (error) {
    errors.push(`Brave: ${error.message}`);
    return [];
  } finally {
    await page.close().catch(() => {});
  }
}

function fetchBingHttp(searchQuery) {
  return new Promise((resolve) => {
    const url = 'https://www.bing.com/search?q=' + encodeURIComponent(searchQuery) + '&cc=PH&setlang=en-PH';
    const req = https.get(url, {
      headers: {
        'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
        'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language': 'en-US,en;q=0.9'
      },
      timeout: HTTP_TIMEOUT
    }, res => {
      if (res.statusCode !== 200) return resolve([]);
      let html = '';
      res.on('data', chunk => html += chunk);
      res.on('end', () => resolve(parseBingHtml(html)));
    });
    req.on('error', () => resolve([]));
    req.on('timeout', () => { req.destroy(); resolve([]); });
  });
}

function parseBingHtml(html) {
  const items = [];
  const regex = /<li[^>]*class="b_algo"[^>]*>[\s\S]*?<h2><a[^>]*href="([^"]+)"[^>]*>([\s\S]*?)<\/a><\/h2>[\s\S]*?<p[^>]*>([\s\S]*?)<\/p>/gi;
  let match;
  while ((match = regex.exec(html)) !== null && items.length < 10) {
    const url = decodeRedirectUrl(match[1]);
    const title = match[2].replace(/<[^>]+>/g, '').replace(/&amp;/gi, '&').trim();
    const snippet = match[3].replace(/<[^>]+>/g, '').replace(/&amp;/gi, '&').trim();
    const blob = `${title} ${snippet} ${url}`;
    if (title
      && url
      && !isLowValueNonFoodUrl(url, title)
      && /\b(?:restaurant|cafe|food|burger|hamburger|chicken|pizza|coffee|menu|dining|eatery)\b/i.test(blob)) {
      items.push({ title, url, snippet });
    }
  }
  return items;
}

function photonPlaceSearchUrl(searchQuery, resultLimit) {
  const queryWithoutAlternatives = searchQuery
    .replace(/\s*\([^()]*\bOR\b[^()]*\)\s*/gi, ' ');
  const hasSpecificFoodTerm = /\b(?:burger|hamburger|fries|chicken|wings|sisig|bulalo|pares|silog|satti|ramen|pizza|shawarma|cafe|cafes|coffee|tea|dessert|cake|seafood)\b/i.test(queryWithoutAlternatives);
  const placeQuery = (hasSpecificFoodTerm
    ? queryWithoutAlternatives.replace(/\brestaurants?\b/gi, ' ')
    : queryWithoutAlternatives)
    .replace(/\b(?:quiet|study|studying|work|working|best|good|nearby|near\s+me)\b/gi, '')
    .replace(/\bcafes\b/gi, 'cafe')
    .replace(/\s+/g, ' ')
    .trim();
  const limit = Math.max(1, Math.min(50, Math.trunc(Number(resultLimit) || 8)));
  return placeQuery
    ? 'https://photon.komoot.io/api/?q=' + encodeURIComponent(placeQuery) + '&limit=' + limit + '&lang=en'
    : '';
}

function nominatimSearchUrl(searchQuery, resultLimit, nearbySearchArea = null) {
  const photonUrl = photonPlaceSearchUrl(searchQuery, resultLimit);
  if (!photonUrl || !NOMINATIM_BASE_URL) return '';
  const baseUrl = new URL(NOMINATIM_BASE_URL);
  if (baseUrl.protocol !== 'https:') {
    throw new Error('NOMINATIM_BASE_URL must use HTTPS.');
  }
  const url = new URL('search', `${baseUrl.href.replace(/\/+$/, '')}/`);
  let placeQuery = new URL(photonUrl).searchParams.get('q') || '';
  const dishTerms = /\b(?:fries|wings|chicken|sisig|bulalo|pares|silog|satti|ramen|pizza|shawarma|seafood|dessert|cake)\b/i;
  const fastFoodTerms = /\b(?:fries|wings|chicken)\b/i;
  const dietaryTerm = placeQuery.match(/\b(?:halal|vegan|vegetarian)\b/i);
  if (dietaryTerm) {
    placeQuery = [dietaryTerm[0], 'restaurant', 'Davao City'].join(' ');
  }
  if (dishTerms.test(placeQuery) && !/\b(?:restaurants?|cafes?|coffee|bar|pub|eatery|dining|food court)\b/i.test(placeQuery)) {
    const area = placeQuery
      .replace(dishTerms, '')
      .replace(/\bdavao city\b/gi, '')
      .replace(/\b(?:quiet|study|studying|work|working|best|good|nearby|near\s+me)\b/gi, '')
      .replace(/\s+/g, ' ')
      .trim();
    placeQuery = [fastFoodTerms.test(new URL(photonUrl).searchParams.get('q') || '') ? 'fast food' : 'restaurant', area, 'Davao City']
      .filter(Boolean)
      .join(' ');
  }
  url.searchParams.set('q', placeQuery);
  url.searchParams.set('format', 'jsonv2');
  url.searchParams.set('addressdetails', '1');
  url.searchParams.set('limit', String(Math.max(1, Math.min(40, Math.trunc(Number(resultLimit) || 8)))));
  if (nearbySearchArea) {
    const { west, south, east, north } = nearbySearchArea;
    if (![west, south, east, north].every(Number.isFinite)
      || west < -180 || east > 180 || south < -90 || north > 90
      || west >= east || south >= north) {
      throw new Error('Nominatim nearby search bounds are invalid.');
    }
    url.searchParams.set('viewbox', [west, south, east, north].join(','));
    url.searchParams.set('bounded', '1');
  }
  return url.href;
}

function parseNominatimPlaces(payload, resultLimit = 8) {
  const limit = Math.max(1, Math.min(50, Math.trunc(Number(resultLimit) || 8)));
  if (!Array.isArray(payload)) return [];
  const items = [];
  for (const result of payload) {
    if (!result || !/^(?:cafe|restaurant|fast_food|food_court|bar|pub)$/i.test(String(result.type || ''))) continue;
    const addressData = result.address && typeof result.address === 'object' ? result.address : {};
    const city = String(addressData.city || addressData.town || addressData.municipality || '').trim();
    const country = String(addressData.country || '').trim();
    if (!/^davao city$/i.test(city) || (!/^philippines$/i.test(country) && String(addressData.country_code || '').toLowerCase() !== 'ph')) continue;
    const name = String(result.name || '').trim();
    const osmType = String(result.osm_type || '').toLowerCase();
    const osmId = String(result.osm_id || '');
    const type = ({ node: 'node', way: 'way', relation: 'relation' })[osmType];
    const latitude = Number(result.lat);
    const longitude = Number(result.lon);
    if (!name || !type || !/^\d+$/.test(osmId)
      || !Number.isFinite(latitude) || latitude < -90 || latitude > 90
      || !Number.isFinite(longitude) || longitude < -180 || longitude > 180) continue;

    const address = [
      addressData.road,
      addressData.neighbourhood,
      addressData.suburb,
      city
    ].filter((part, index, parts) => part && parts.indexOf(part) === index).join(', ');
    items.push({
      name,
      title: name,
      url: `https://www.openstreetmap.org/${type}/${osmId}`,
      snippet: `OpenStreetMap lists this ${String(result.type).replace(/_/g, ' ')}${address ? ` at ${address}` : ''}. OpenStreetMap contributors (https://www.openstreetmap.org/copyright).`,
      placeLookup: true,
      place_provider: 'OpenStreetMap Nominatim',
      latitude,
      longitude,
      city,
      address,
      district: String(addressData.suburb || '').trim(),
      street: String(addressData.road || '').trim(),
      food_type: String(result.type).replace(/_/g, ' ')
    });
    if (items.length >= limit) break;
  }
  return items;
}

function readNominatimCache(cacheFile, errors) {
  try {
    const cached = JSON.parse(fs.readFileSync(cacheFile, 'utf8'));
    if (!Number.isFinite(cached.cached_at) || Date.now() - cached.cached_at >= NOMINATIM_CACHE_TTL || !Array.isArray(cached.places)) {
      return null;
    }
    return cached.places;
  } catch (error) {
    if (error.code !== 'ENOENT') errors.push(`Nominatim cache: ${error.message}`);
    return null;
  }
}

async function reserveNominatimRequest(errors) {
  const lockFile = path.join(NOMINATIM_CACHE_DIR, 'nominatim-rate.lock');
  const lockDeadline = Date.now() + 5000;
  let lockFd;
  try {
    fs.mkdirSync(NOMINATIM_CACHE_DIR, { recursive: true });
    while (lockFd === undefined) {
      try {
        lockFd = fs.openSync(lockFile, 'wx');
      } catch (error) {
        if (error.code !== 'EEXIST') throw error;
        try {
          if (Date.now() - fs.statSync(lockFile).mtimeMs > 30000) {
            fs.unlinkSync(lockFile);
            continue;
          }
        } catch (lockError) {
          if (lockError.code !== 'ENOENT' && lockError.code !== 'EEXIST') throw lockError;
        }
        if (Date.now() >= lockDeadline) {
          errors.push('Nominatim rate limiter: timed out waiting for the application-wide request queue.');
          return false;
        }
        await new Promise(resolve => setTimeout(resolve, 25));
      }
    }

    let requestAt;
    try {
      const rateFile = path.join(NOMINATIM_CACHE_DIR, 'nominatim-next-request-at.txt');
      let nextAllowedAt = 0;
      try {
        nextAllowedAt = Number(fs.readFileSync(rateFile, 'utf8'));
      } catch (error) {
        if (error.code !== 'ENOENT') throw error;
        const legacyFile = path.join(NOMINATIM_CACHE_DIR, 'nominatim-last-request.txt');
        try {
          const lastRequestAt = Number(fs.readFileSync(legacyFile, 'utf8'));
          if (Number.isFinite(lastRequestAt) && Date.now() - lastRequestAt < NOMINATIM_MIN_INTERVAL) {
            nextAllowedAt = lastRequestAt + NOMINATIM_MIN_INTERVAL;
          }
        } catch (legacyError) {
          if (legacyError.code !== 'ENOENT') throw legacyError;
        }
      }
      if (!Number.isFinite(nextAllowedAt)) throw new Error('Stored request schedule is invalid.');
      requestAt = Math.max(Date.now(), nextAllowedAt);
      const tempFile = `${rateFile}.${process.pid}.tmp`;
      fs.writeFileSync(tempFile, String(requestAt + NOMINATIM_MIN_INTERVAL));
      fs.renameSync(tempFile, rateFile);
    } finally {
      fs.closeSync(lockFd);
      fs.unlinkSync(lockFile);
    }
    const waitMs = requestAt - Date.now();
    if (waitMs > 0) await new Promise(resolve => setTimeout(resolve, waitMs));
    return true;
  } catch (error) {
    errors.push(`Nominatim rate limiter: ${error.message}`);
    return false;
  }
}

async function fetchNominatimPlacesBrowser(context, searchQuery, resultLimit, errors, nearbySearchArea = null) {
  let url;
  try {
    url = nominatimSearchUrl(searchQuery, resultLimit, nearbySearchArea);
  } catch (error) {
    errors.push(`Nominatim configuration: ${error.message}`);
    return [];
  }
  if (!url) return [];

  const placeSearchQuery = new URL(url).searchParams.get('q') || '';
  const markSearchResults = places => places.map(place => ({
    ...place,
    place_search_query: placeSearchQuery,
    place_provider: 'OpenStreetMap Nominatim',
    playwright_search_result: true
  }));
  const cacheKey = crypto.createHash('sha256').update(url).digest('hex');
  const cacheFile = path.join(NOMINATIM_CACHE_DIR, `nominatim-${cacheKey}.json`);
  const cachedPlaces = readNominatimCache(cacheFile, errors);
  if (cachedPlaces) {
    return markSearchResults(cachedPlaces);
  }
  if (!await reserveNominatimRequest(errors)) return [];
  const latestCachedPlaces = readNominatimCache(cacheFile, errors);
  if (latestCachedPlaces) {
    return markSearchResults(latestCachedPlaces);
  }

  let page;
  let nominatimContext;
  try {
    const browser = context.browser();
    if (!browser) throw new Error('Chromium is not available for the Nominatim fallback.');
    nominatimContext = await browser.newContext({
      userAgent: 'DavaoFoodFinder/1.0',
      locale: 'en-PH',
      extraHTTPHeaders: {
        'Accept': 'application/json'
      }
    });
    page = await nominatimContext.newPage();
    await page.setDefaultTimeout(PLAYWRIGHT_STEP_TIMEOUT);
    const response = await page.goto(url, { waitUntil: 'commit', timeout: SEARCH_NAVIGATION_TIMEOUT });
    if (!response || !response.ok()) {
      errors.push(`Nominatim: HTTP ${response ? response.status() : 'no response'}`);
      return [];
    }
    const places = parseNominatimPlaces(await response.json(), resultLimit);
    const tempFile = `${cacheFile}.${process.pid}.tmp`;
    fs.writeFileSync(tempFile, JSON.stringify({ cached_at: Date.now(), places }), 'utf8');
    fs.renameSync(tempFile, cacheFile);
    return markSearchResults(places);
  } catch (error) {
    errors.push(`Nominatim browser fallback: ${error.message}`);
    return [];
  } finally {
    if (nominatimContext) await nominatimContext.close().catch(() => {});
  }
}

function parsePhotonPlaces(payload, resultLimit = 8) {
  const limit = Math.max(1, Math.min(50, Math.trunc(Number(resultLimit) || 8)));
  if (!payload || typeof payload !== 'object' || Array.isArray(payload)) return [];
  const features = Array.isArray(payload.features) ? payload.features : [];
  const items = [];
  for (const feature of features) {
    const place = feature && feature.properties;
    if (!place || !place.name || !/^(?:cafe|restaurant|fast_food|food_court|bar|pub)$/i.test(String(place.osm_value || ''))) continue;
    if (!/^davao city$/i.test(String(place.city || '')) || !/^philippines$/i.test(String(place.country || ''))) continue;
    if (!/^[NWR]$/i.test(String(place.osm_type || '')) || !/^\d+$/.test(String(place.osm_id || ''))) continue;

    const osmType = ({ N: 'node', W: 'way', R: 'relation' })[String(place.osm_type).toUpperCase()];
    if (!osmType) continue;
    const address = [place.street, place.district, place.city].filter(Boolean).join(', ');
    const coordinates = feature.geometry && Array.isArray(feature.geometry.coordinates)
      ? feature.geometry.coordinates
      : [];
    const longitude = Number(coordinates[0]);
    const latitude = Number(coordinates[1]);
    if (!Number.isFinite(latitude) || latitude < -90 || latitude > 90 || !Number.isFinite(longitude) || longitude < -180 || longitude > 180) continue;
    items.push({
      name: String(place.name).trim(),
      title: String(place.name).trim(),
      url: `https://www.openstreetmap.org/${osmType}/${place.osm_id}`,
      snippet: `OpenStreetMap lists this ${String(place.osm_value).replace(/_/g, ' ')} at ${address}. OpenStreetMap contributors (https://www.openstreetmap.org/copyright).`,
      placeLookup: true,
      latitude,
      longitude,
      city: String(place.city).trim(),
      address,
      district: String(place.district || '').trim(),
      street: String(place.street || '').trim(),
      food_type: String(place.cuisine || place.osm_value).replace(/_/g, ' ')
    });
    if (items.length >= limit) break;
  }
  return items;
}

function fetchPhotonPlaces(searchQuery, resultLimit = 8, errors = []) {
  const url = photonPlaceSearchUrl(searchQuery, resultLimit);
  if (!url) return Promise.resolve([]);
  return new Promise((resolve) => {
    let settled = false;
    const finish = places => {
      if (settled) return;
      settled = true;
      resolve(places);
    };
    const req = https.get(url, {
      headers: {
        'User-Agent': 'DavaoFoodFinder/1.0',
        'Accept': 'application/json'
      },
      timeout: HTTP_TIMEOUT
    }, res => {
      if (res.statusCode < 200 || res.statusCode >= 300) {
        errors.push(`OpenStreetMap Photon: HTTP ${res.statusCode || 'unknown status'}`);
        return finish([]);
      }
      let body = '';
      res.on('data', chunk => {
        body += chunk;
        if (body.length > 500000) {
          errors.push('OpenStreetMap Photon: response exceeded 500 KB.');
          req.destroy();
          finish([]);
        }
      });
      res.on('end', () => {
        if (settled) return;
        let payload;
        try {
          payload = JSON.parse(body);
        } catch (error) {
          errors.push(`OpenStreetMap Photon: invalid JSON response (${error.message}).`);
          return finish([]);
        }

        finish(parsePhotonPlaces(payload, resultLimit));
      });
    });
    req.on('error', error => {
      if (!settled) errors.push(`OpenStreetMap Photon: ${error.message}`);
      finish([]);
    });
    req.on('timeout', () => {
      if (!settled) errors.push(`OpenStreetMap Photon: request timed out after ${HTTP_TIMEOUT} ms.`);
      req.destroy();
      finish([]);
    });
  });
}

async function fetchPhotonPlacesBrowser(context, searchQuery, resultLimit, errors) {
  const url = photonPlaceSearchUrl(searchQuery, resultLimit);
  if (!url) return [];
  const page = await context.newPage();
  await page.setDefaultTimeout(PLAYWRIGHT_STEP_TIMEOUT);
  try {
    const response = await page.goto(url, {
      waitUntil: 'commit',
      timeout: SEARCH_NAVIGATION_TIMEOUT
    });
    if (!response || !response.ok()) {
      errors.push(`OpenStreetMap Photon: HTTP ${response ? response.status() : 'no response'}`);
      return [];
    }
    const payload = JSON.parse(await response.text());
    return parsePhotonPlaces(payload, resultLimit)
      .map(place => ({ ...place, playwright_search_result: true }));
  } catch (error) {
    errors.push(`OpenStreetMap Photon browser fallback: ${error.message}`);
    return [];
  } finally {
    await page.close().catch(() => {});
  }
}

async function collectPhotonPlaces(context, searchQuery, resultLimit, errors, allowBrowserFallback = false, allowNominatimFallback = true) {
  if (!allowBrowserFallback) return fetchPhotonPlaces(searchQuery, resultLimit, errors);
  let httpPlaces = [];
  try {
    httpPlaces = await fetchPhotonPlaces(searchQuery, resultLimit, errors);
  } catch (error) {
    errors.push(`OpenStreetMap Photon HTTP fallback: ${error.message}`);
  }
  if (httpPlaces.length >= resultLimit) return httpPlaces;
  let browserPlaces = [];
  try {
    browserPlaces = await fetchPhotonPlacesBrowser(context, searchQuery, resultLimit, errors);
  } catch (error) {
    errors.push(`OpenStreetMap Photon browser fallback: ${error.message}`);
  }
  const placesByUrl = new Map();
  for (const place of [...httpPlaces, ...browserPlaces]) {
    if (!place.url) continue;
    const existing = placesByUrl.get(place.url);
    if (!existing || place.playwright_search_result === true) placesByUrl.set(place.url, place);
  }
  const places = [...placesByUrl.values()];
  if (places.length) return places.slice(0, resultLimit);
  if (!allowNominatimFallback) return [];
  const nominatimPlaces = await fetchNominatimPlacesBrowser(context, searchQuery, resultLimit, errors);
  if (nominatimPlaces.length) return nominatimPlaces;
  return [];
}

function buildReviewSearchQuery(searchQuery) {
  return /\b(reviews?|ratings?)\b/i.test(searchQuery)
    ? searchQuery
    : `${searchQuery} customer reviews`;
}

function buildDiscoveryQueries(searchQuery, reviewSearchQuery = buildReviewSearchQuery(searchQuery)) {
  return [
    { query: searchQuery, reviewSearch: false, useBraveBrowser: true, discoveryType: 'web' },
    { query: `${searchQuery} branches locations`, reviewSearch: false, discoveryType: 'web' },
    { query: reviewSearchQuery, reviewSearch: true, discoveryType: 'review' },
    { query: `${searchQuery} food blog recommendations`, reviewSearch: false, discoveryType: 'blog' },
    {
      query: `(${searchQuery}) (site:reddit.com OR site:facebook.com OR site:tiktok.com)`,
      reviewSearch: false,
      discoveryType: 'community'
    }
  ];
}

function classifyEvidenceSource(url, discoveryType = 'web') {
  let hostname = '';
  try {
    hostname = new URL(String(url || '')).hostname.toLowerCase();
  } catch {
    return 'web_page';
  }
  if (/(^|\.)((reddit|facebook|tiktok|instagram)\.com)$/.test(hostname)) return 'community_post';
  if (discoveryType === 'blog') return 'blog_search_result';
  return 'web_page';
}

function shouldFetchSourcePage(link, { browserActive, fetchedPricePages }) {
  if (link.placeLookup) return false;
  if (link.priceLookup) return fetchedPricePages < 3;
  if (!browserActive) return false;
  const hasLocalBrowserSnippet = link.playwright_search_result === true
    && Boolean(link.snippet)
    && /\bdavao\b/i.test(`${link.title || ''} ${link.snippet} ${link.url || ''}`);
  return !hasLocalBrowserSnippet;
}

async function collectBingBrowser(context, searchQuery, errors) {
  const page = await context.newPage();
  await page.setDefaultTimeout(PLAYWRIGHT_STEP_TIMEOUT);
  try {
    const bingUrl = 'https://www.bing.com/search?q=' + encodeURIComponent(searchQuery);
    await page.goto(bingUrl, { waitUntil: 'commit', timeout: SEARCH_NAVIGATION_TIMEOUT });
    await waitForSearchResults(page, 'li.b_algo h2 a', 'Bing', errors);
    const rawItems = await page.locator('li.b_algo').evaluateAll(nodes =>
      nodes.slice(0, 12).map(node => {
        const a = node.querySelector('h2 a');
        const snip = node.querySelector('.b_caption p, p');
        return {
          title: a ? (a.textContent || '').trim() : '',
          url: a ? (a.getAttribute('href') || a.href || '') : '',
          snippet: snip ? (snip.textContent || '').trim() : ''
        };
      })
    );
    return rawItems
      .map(item => ({ ...item, url: decodeRedirectUrl(item.url) }))
      .filter(item => item.title && !isLowValueNonFoodUrl(item.url, item.title));
  } catch (error) {
    errors.push(`Bing: ${error.message}`);
    return [];
  } finally {
    await page.close().catch(() => {});
  }
}

async function run() {
  const searchArgs = process.argv.slice(2);
  const finalQuery = String(searchArgs[0] || '').trim();
  if (!finalQuery) throw new Error('Search query is required');
  const includeNearby = searchArgs[2] === '--nearby';
  let nearbyQueries = [];
  if (includeNearby && searchArgs[3]) {
    let decodedNearbyQueries;
    try {
      decodedNearbyQueries = JSON.parse(Buffer.from(searchArgs[3], 'base64').toString('utf8'));
    } catch (error) {
      throw new Error(`Photon place lookup queries could not be decoded: ${error.message}`);
    }
    if (!Array.isArray(decodedNearbyQueries) || decodedNearbyQueries.some(query => typeof query !== 'string')) {
      throw new Error('Photon place lookup queries must be a JSON array of strings');
    }
    nearbyQueries = decodedNearbyQueries.slice(0, 3).map(query => query.trim()).filter(Boolean);
  }
  let nearbySearchArea = null;
  if (includeNearby && searchArgs[4]) {
    let decodedNearbySearchArea;
    try {
      decodedNearbySearchArea = JSON.parse(Buffer.from(searchArgs[4], 'base64').toString('utf8'));
    } catch (error) {
      throw new Error(`Nominatim nearby search bounds could not be decoded: ${error.message}`);
    }
    if (!decodedNearbySearchArea || typeof decodedNearbySearchArea !== 'object' || Array.isArray(decodedNearbySearchArea)) {
      throw new Error('Nominatim nearby search bounds must be an object.');
    }
    const bounds = ['west', 'south', 'east', 'north'].map(key => Number(decodedNearbySearchArea[key]));
    if (!bounds.every(Number.isFinite)
      || bounds[0] < -180 || bounds[2] > 180 || bounds[1] < -90 || bounds[3] > 90
      || bounds[0] >= bounds[2] || bounds[1] >= bounds[3]) {
      throw new Error('Nominatim nearby search bounds must be valid west, south, east, and north coordinates.');
    }
    nearbySearchArea = {
      west: bounds[0],
      south: bounds[1],
      east: bounds[2],
      north: bounds[3]
    };
  }
  let priceQueries = [];
  if (searchArgs[1]) {
    let decodedPriceQueries;
    try {
      decodedPriceQueries = Buffer.from(searchArgs[1], 'base64').toString('utf8');
    } catch (error) {
      throw new Error(`Price lookup queries could not be decoded: ${error.message}`);
    }
    let parsedPriceQueries;
    try {
      parsedPriceQueries = JSON.parse(decodedPriceQueries);
    } catch (error) {
      throw new Error(`Price lookup queries must be valid JSON: ${error.message}`);
    }
    if (!Array.isArray(parsedPriceQueries) || parsedPriceQueries.some(query => typeof query !== 'string')) {
      throw new Error('Price lookup queries must be a JSON array of strings');
    }
    priceQueries = parsedPriceQueries.slice(0, 3).map(query => query.trim()).filter(Boolean);
  }

  // Strip conversational / meta directives from the raw command
  const cleanQuery = finalQuery
    .replace(/\b(?:dont|do\s+not)\s+give\s+me\s+suggestions?\s+(?:from\s+the\s+dataset|from\s+dataset)\b/gi, '')
    .replace(/\b(?:from\s+the\s+dataset|from\s+dataset|local\s+dataset|not\s+from\s+the\s+dataset|no\s+dataset)\b/gi, '')
    .replace(/\b(?:find\s+it\s+online|search\s+it\s+online|find\s+online|search\s+online|online\s+only|only\s+online|from\s+the\s+web)\b/gi, '')
    .replace(/^(?:search\s+for|find|look\s+for|show\s+me)\s+/i, '')
    .replace(/\s+/g, ' ')
    .trim();

  const finalTerm = cleanQuery || 'food restaurants Davao City';
  let searchQuery = finalTerm;
  if (!/\bdavao\b/i.test(searchQuery)) {
    searchQuery += ' Davao City';
  }
  if (!/\b(restaurant|restaurants|cafe|cafes|food|eatery|place|places|dining|bar|grill|bbq|kitchen|spot|spots)\b/i.test(searchQuery)) {
    searchQuery += ' restaurants';
  }

  const reviewSearchQuery = buildReviewSearchQuery(searchQuery);
  let browser = null;
  let context = null;
  const getContext = async () => {
    if (!context) {
      browser = await chromium.launch({
        headless: true,
        args: ['--disable-blink-features=AutomationControlled', '--no-sandbox']
      });
      context = await browser.newContext({
        userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
        locale: 'en-PH'
      });
      await context.addInitScript(() => {
        Object.defineProperty(navigator, 'webdriver', { get: () => false });
      });
    }
    return context;
  };
  const playwrightErrors = [];
  const browserContext = await getContext();
  let browserSearchCount = 0;
  const braveApiConfigured = String(process.env.BRAVE_SEARCH_API_KEY || '').trim() !== '';
  let braveApiRateLimited = false;
  let braveApiQueue = Promise.resolve();
  const queueBraveApiSearch = query => {
    const queuedSearch = braveApiQueue.then(async () => {
      if (braveApiRateLimited) return [];
      const errorStart = playwrightErrors.length;
      const results = await fetchBraveSearchApi(query, 8, playwrightErrors);
      if (playwrightErrors.slice(errorStart).some(error =>
        error.includes('Brave Search API: HTTP 429')
        || error.includes('Brave Search API: skipped during rate-limit cooldown')
      )) {
        braveApiRateLimited = true;
      }
      return results;
    });
    braveApiQueue = queuedSearch.then(() => undefined, () => undefined);
    return queuedSearch;
  };
  const collectPhotonSearch = async (query, limit, allowBrowserFallback = false, allowNominatimFallback = true) => {
    const places = await collectPhotonPlaces(
      browserContext,
      query,
      limit,
      playwrightErrors,
      allowBrowserFallback,
      allowNominatimFallback
    );
    if (places.some(place => place.playwright_search_result === true)) browserSearchCount++;
    return places;
  };
  const nearbyPhotonQuery = nearbyQueries[0] || searchQuery;
  const nearbyPhotonSearchQuery = /\bdavao(?:\s+city)?\b/i.test(nearbyPhotonQuery)
    ? nearbyPhotonQuery
    : `${nearbyPhotonQuery} Davao City`;
  const nearbyPlacesPromise = includeNearby
    ? collectPhotonSearch(nearbyPhotonSearchQuery, 50, true, false).then(async fetchedPlaces => {
      const uniquePlaces = places => {
        const seenPlaces = new Set();
        return places.filter(place => {
          if (!place.url || seenPlaces.has(place.url)) return false;
          seenPlaces.add(place.url);
          return true;
        });
      };
      let places = uniquePlaces(fetchedPlaces);
      if (nearbySearchArea) {
        places = places.filter(place =>
          Number.isFinite(place.latitude) && Number.isFinite(place.longitude)
          && place.longitude >= nearbySearchArea.west && place.longitude <= nearbySearchArea.east
          && place.latitude >= nearbySearchArea.south && place.latitude <= nearbySearchArea.north
        );
        if (places.length < 8) {
          const nominatimPlaces = await fetchNominatimPlacesBrowser(
            browserContext,
            nearbyPhotonSearchQuery,
            40,
            playwrightErrors,
            nearbySearchArea
          );
          if (nominatimPlaces.length) {
            browserSearchCount++;
            places = uniquePlaces([...places, ...nominatimPlaces]);
          }
        }
      }
      return places;
    })
    : null;
  const photonPlacesPromise = includeNearby
    ? Promise.resolve([])
    : collectPhotonSearch(searchQuery, 8, true);
  const collectSearchResults = async (query, useBraveBrowser = false) => {
    browserSearchCount += 1;
    let braveResultsPromise;
    if (braveApiConfigured) {
      braveResultsPromise = queueBraveApiSearch(query).then(async results => {
        if (results.length > 0 || !useBraveBrowser || braveApiRateLimited) return results;
        browserSearchCount += 1;
        return collectBraveBrowser(browserContext, query, playwrightErrors);
      });
    } else if (useBraveBrowser) {
      browserSearchCount += 1;
      braveResultsPromise = collectBraveBrowser(browserContext, query, playwrightErrors);
    } else {
      braveResultsPromise = Promise.resolve([]);
    }
    const [braveResults, bingBrowser, duckDuckGoHttp, bingHttp] = await Promise.all([
      braveResultsPromise,
      collectBingBrowser(browserContext, query, playwrightErrors),
      fetchDuckDuckGoHttp(query),
      fetchBingHttp(query)
    ]);
    const byUrl = new Map();
    for (const item of braveResults) {
      const key = String(item.url || '').split('#')[0].replace(/\/+$/, '');
      if (key) byUrl.set(key, {
        ...item,
        playwright_search_result: item.playwright_search_result === true
      });
    }
    for (const item of bingBrowser) {
      const key = String(item.url || '').split('#')[0].replace(/\/+$/, '');
      if (key) byUrl.set(key, { ...item, playwright_search_result: true });
    }
    for (const item of [...duckDuckGoHttp, ...bingHttp]) {
      const key = String(item.url || '').split('#')[0].replace(/\/+$/, '');
      if (!key) continue;
      const existing = byUrl.get(key);
      if (existing) {
        if (!existing.snippet && item.snippet) existing.snippet = item.snippet;
      } else {
        byUrl.set(key, { ...item, playwright_search_result: false });
      }
    }
    return [...byUrl.values()].slice(0, 12);
  };

  const discoveryQueries = buildDiscoveryQueries(searchQuery, reviewSearchQuery);
  const searchGroups = await Promise.all(discoveryQueries.map(async group => ({
    ...group,
    results: await collectSearchResults(group.query, group.useBraveBrowser === true)
  })));
  let candidates = [];
  const maxSearchResults = Math.max(...searchGroups.map(group => group.results.length));
  for (let i = 0; i < maxSearchResults; i++) {
    for (const group of searchGroups) {
      if (group.results[i]) candidates.push({
        ...group.results[i],
        reviewSearch: group.reviewSearch,
        discoveryType: group.discoveryType
      });
    }
  }
  const photonPlaces = await photonPlacesPromise;
  const mappedCandidates = photonPlaces.map(item => ({ ...item, reviewSearch: false }));
  const nominatimCandidates = mappedCandidates.filter(item => item.place_provider === 'OpenStreetMap Nominatim');
  const hasEnoughNominatimResults = nominatimCandidates.length >= 3;
  candidates.unshift(...nominatimCandidates);
  candidates.push(...mappedCandidates.filter(item => item.place_provider !== 'OpenStreetMap Nominatim'));
  let browserResultCount = 0;
  if (candidates.length < 3) {
    const additionalPlaces = nearbyPlacesPromise ? await nearbyPlacesPromise : [];
    candidates.push(...additionalPlaces.slice(0, 8).map(item => ({ ...item, reviewSearch: false })));
  }

  const priceCandidates = [];
  const priceSearchResults = await Promise.all(priceQueries.map(async priceQuery => {
    const targetedQuery = /\bdavao\b/i.test(priceQuery) ? priceQuery : `${priceQuery} Davao City`;
    return (await collectSearchResults(targetedQuery)).slice(0, 2).map(item => ({ ...item, priceLookup: true }));
  }));
  priceCandidates.push(...priceSearchResults.flat());
  browserResultCount += priceCandidates.length;
  candidates = priceCandidates.concat(candidates);

  const results = [];
  const seenUrls = new Set();
  const deduped = [];
  for (const item of candidates) {
    const normUrl = item.url.split('#')[0].replace(/\/+$/, '');
    if (!normUrl || seenUrls.has(normUrl)) continue;
    seenUrls.add(normUrl);
    deduped.push(item);
    if (deduped.length >= 24) break;
  }

  // 3. Fetch a bounded set of source pages concurrently rather than serially.
  let fetchedPricePages = 0;
  let fetchedSourcePages = 0;
  const pageFetches = new Map();
  const linksToFetch = [];
  for (const link of deduped) {
    const isSocialOrReview = classifyEvidenceSource(link.url) === 'community_post';
    const shouldFetchPricePage = link.priceLookup && fetchedPricePages < 3;
    const shouldFetchPage = (!hasEnoughNominatimResults || link.priceLookup) && shouldFetchSourcePage(link, {
      browserActive: Boolean(browser),
      fetchedPricePages
    });
    if (isSocialOrReview || !shouldFetchPage || (!shouldFetchPricePage && fetchedSourcePages >= 3)) continue;
    if (shouldFetchPricePage) fetchedPricePages++;
    else fetchedSourcePages++;
    linksToFetch.push({ link, shouldFetchPricePage });
  }
  await Promise.all(linksToFetch.map(async ({ link, shouldFetchPricePage }) => {
    let pageTitle = link.title;
    let bodyText = '';
    try {
      const ctx = await getContext();
      const newPage = await ctx.newPage();
      try {
        await newPage.setDefaultTimeout(Math.min(PLAYWRIGHT_STEP_TIMEOUT, shouldFetchPricePage ? 3000 : 5000));
        await newPage.goto(link.url, { waitUntil: 'commit', timeout: Math.min(PLAYWRIGHT_STEP_TIMEOUT, shouldFetchPricePage ? 3000 : 3000) });
        const fetchedTitle = await newPage.title().catch(() => '');
        if (fetchedTitle && !/^(loading|just a moment|attention required|access denied|suspicious activity|security check|cloudflare|robot|verify)/i.test(fetchedTitle.trim())) {
          pageTitle = fetchedTitle.trim();
        }
        const rawBody = await newPage.locator('body').innerText().catch(() => '');
        if (!/unusual traffic|verify that you are human|turnstile|recaptcha|access denied|suspicious activity/i.test(rawBody)) {
          bodyText = rawBody;
        }
      } finally {
        await newPage.close().catch(() => {});
      }
    } catch (error) {
      playwrightErrors.push(`Source page ${link.url}: ${error.message}`);
    }
    pageFetches.set(link, {
      pageTitle,
      bodyText,
      browserFetchedPage: bodyText.trim() !== ''
    });
  }));

  for (const link of deduped) {
    if (results.length >= 12) break;
    const fetchedPage = pageFetches.get(link) || {};
    const pageTitle = fetchedPage.pageTitle || link.title;
    const bodyText = fetchedPage.bodyText || '';
    const browserFetchedPage = fetchedPage.browserFetchedPage || false;

    const cleanedBody = String(bodyText || '').replace(/\s+/g, ' ').trim().slice(0, 4500);
    const combinedText = [
      link.snippet ? `Search snippet: ${link.snippet}` : '',
      cleanedBody
    ].filter(Boolean).join(' | ').slice(0, 5000);

    const checkBlob = `${pageTitle} ${link.title} ${link.snippet || ''} ${cleanedBody} ${link.url}`;
    const playwrightRetrieved = browserFetchedPage || (link.playwright_search_result === true && Boolean(link.snippet));
    if (combinedText && playwrightRetrieved && !isLowValueNonFoodUrl(link.url, pageTitle) && /\bdavao\b/i.test(checkBlob)) {
      results.push({
        title: pageTitle || link.title,
        url: link.url,
        text: combinedText,
        playwright_retrieved: true,
        review_search: Boolean(link.reviewSearch),
        review_query: link.reviewSearch ? reviewSearchQuery : '',
        food_query: searchQuery,
        price_lookup: Boolean(link.priceLookup),
        source_type: classifyEvidenceSource(link.url, link.discoveryType),
        source_access: browserFetchedPage ? 'source_page' : 'search_result_snippet'
      });
    }
  }

  browserResultCount = results.filter(result => result.playwright_retrieved).length;
  const nearbyPlaces = nearbyPlacesPromise ? await nearbyPlacesPromise : [];
  browserResultCount = Math.max(
    browserResultCount,
    nearbyPlaces.filter(place => place.playwright_search_result === true).length
  );
  if (browser) {
    await browser.close().catch(() => {});
  }

  process.stdout.write(JSON.stringify({
    query: finalTerm,
    results,
    nearby_places: nearbyPlaces,
    browser_used: Boolean(browser),
    browser_result_count: browserResultCount,
    browser_search_count: browserSearchCount,
    browser_search_errors: playwrightErrors
  }));
}

if (require.main === module) {
  run().catch(error => {
    process.stderr.write(String(error && error.message ? error.message : error));
    process.exit(1);
  });
}

module.exports = {
  collectBraveBrowser,
  buildReviewSearchQuery,
  collectPhotonPlaces,
  fetchBraveSearchApi,
  fetchPhotonPlaces,
  nominatimSearchUrl,
  parseBraveBrowserResults,
  parseBraveSearchApiResults,
  parseNominatimPlaces,
  photonPlaceSearchUrl,
  buildDiscoveryQueries,
  classifyEvidenceSource,
  parseBingHtml,
  retryAfterTimestamp,
  shouldFetchSourcePage,
  waitForSearchResults
};
