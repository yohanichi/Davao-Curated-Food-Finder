const https = require('https');
const { chromium } = require('playwright');

const PLAYWRIGHT_STEP_TIMEOUT = parseInt(process.env.PLAYWRIGHT_STEP_TIMEOUT, 10) || 10000;
const HTTP_TIMEOUT = Math.min(8000, PLAYWRIGHT_STEP_TIMEOUT);

function decodeRedirectUrl(rawUrl) {
  if (!rawUrl) return '';
  try {
    const full = rawUrl.startsWith('//') ? 'https:' + rawUrl : rawUrl;
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

async function collectDuckDuckGoBrowser(context, searchQuery) {
  const page = await context.newPage();
  await page.setDefaultTimeout(PLAYWRIGHT_STEP_TIMEOUT);
  try {
    const ddgUrl = 'https://html.duckduckgo.com/html/?q=' + encodeURIComponent(searchQuery) + '&kl=ph-en';
    await page.goto(ddgUrl, { waitUntil: 'domcontentloaded', timeout: PLAYWRIGHT_STEP_TIMEOUT });
    const rawItems = await page.locator('.result, .results_links').evaluateAll(nodes =>
      nodes.slice(0, 12).map(node => {
        const a = node.querySelector('a.result__a, h2 a');
        const snip = node.querySelector('.result__snippet, .result__extras__url');
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
  } catch {
    return [];
  } finally {
    await page.close().catch(() => {});
  }
}

function fetchBingHttp(searchQuery) {
  return new Promise((resolve) => {
    const url = 'https://www.bing.com/search?q=' + encodeURIComponent(searchQuery);
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
      res.on('end', () => {
        const items = [];
        const regex = /<li[^>]*class="b_algo"[^>]*>[\s\S]*?<h2><a[^>]*href="([^"]+)"[^>]*>([\s\S]*?)<\/a><\/h2>[\s\S]*?<p[^>]*>([\s\S]*?)<\/p>/gi;
        let m;
        while ((m = regex.exec(html)) !== null && items.length < 10) {
          const rawUrl = m[1];
          const decoded = decodeRedirectUrl(rawUrl);
          const title = m[2].replace(/<[^>]+>/g, '').trim();
          const snippet = m[3].replace(/<[^>]+>/g, '').trim();
          const blob = `${title} ${snippet} ${decoded}`;
          if (title && decoded && !isLowValueNonFoodUrl(decoded, title) && /\bdavao\b/i.test(blob)) {
            items.push({ title, url: decoded, snippet });
          }
        }
        resolve(items);
      });
    });
    req.on('error', () => resolve([]));
    req.on('timeout', () => { req.destroy(); resolve([]); });
  });
}

function fetchPhotonPlaces(searchQuery, resultLimit = 8) {
  const placeQuery = searchQuery
    .replace(/\b(?:quiet|study|studying|work|working|best|good|nearby|near\s+me)\b/gi, '')
    .replace(/\bcafes\b/gi, 'cafe')
    .replace(/\s+/g, ' ')
    .trim();
  if (!placeQuery) return Promise.resolve([]);

  const limit = Math.max(1, Math.min(50, Math.trunc(Number(resultLimit) || 8)));
  const url = 'https://photon.komoot.io/api/?q=' + encodeURIComponent(placeQuery) + '&limit=' + limit + '&lang=en';
  return new Promise((resolve) => {
    const req = https.get(url, {
      headers: {
        'User-Agent': 'DavaoFoodFinder/1.0',
        'Accept': 'application/json'
      },
      timeout: HTTP_TIMEOUT
    }, res => {
      if (res.statusCode < 200 || res.statusCode >= 300) return resolve([]);
      let body = '';
      res.on('data', chunk => {
        body += chunk;
        if (body.length > 500000) {
          req.destroy();
          resolve([]);
        }
      });
      res.on('end', () => {
        let payload;
        try {
          payload = JSON.parse(body);
        } catch {
          return resolve([]);
        }

        if (!payload || typeof payload !== 'object' || Array.isArray(payload)) return resolve([]);
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
        resolve(items);
      });
    });
    req.on('error', () => resolve([]));
    req.on('timeout', () => { req.destroy(); resolve([]); });
  });
}

function fetchBraveHttp(searchQuery) {
  return new Promise((resolve) => {
    const url = 'https://search.brave.com/search?q=' + encodeURIComponent(searchQuery) + '&source=web';
    const req = https.get(url, {
      headers: {
        'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
        'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language': 'en-US,en;q=0.9'
      },
      timeout: HTTP_TIMEOUT
    }, res => {
      if (res.statusCode < 200 || res.statusCode >= 300) return resolve([]);
      let html = '';
      res.on('data', chunk => {
        html += chunk;
        if (html.length > 900000) {
          req.destroy();
          resolve(parseBraveHtml(html));
        }
      });
      res.on('end', () => resolve(parseBraveHtml(html)));
    });
    req.on('error', () => resolve([]));
    req.on('timeout', () => { req.destroy(); resolve([]); });
  });
}

function parseBraveHtml(html) {
  const decodeHtml = value => String(value || '')
    .replace(/<[^>]+>/g, ' ')
    .replace(/&amp;/g, '&')
    .replace(/&#039;|&apos;/g, "'")
    .replace(/&quot;/g, '"')
    .replace(/&nbsp;/g, ' ')
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/\s+/g, ' ')
    .trim();
  const items = [];
  const blockRegex = /<div class="snippet svelte-[^"]*"[^>]*data-pos="(\d+)"[^>]*data-type="web"[\s\S]*?(?=<div class="snippet svelte-[^"]*"[^>]*(?:data-pos="\d+"[^>]*data-type="web"|id="))/gi;
  let match;
  while ((match = blockRegex.exec(html)) !== null && items.length < 10) {
    const block = match[0];
    const linkMatch = block.match(/<a\s[^>]*href="(https?:\/\/[^"]+)"/i);
    const titleMatch = block.match(/class="title search-snippet-title[^"]*"[^>]*title="([^"]+)"/i);
    const snippetMatch = block.match(/class="generic-snippet[^"]*"[\s\S]*?<div class="content[^>]*>([\s\S]*?)<\/div>/i);
    if (!linkMatch) continue;
    const url = decodeHtml(linkMatch[1]);
    const title = decodeHtml(titleMatch ? titleMatch[1] : '');
    const snippet = decodeHtml(snippetMatch ? snippetMatch[1] : '');
    const blob = `${title} ${snippet} ${url}`;
    if (title && url && !isLowValueNonFoodUrl(url, title) && /\bdavao\b/i.test(blob)) {
      items.push({ title, url, snippet });
    }
  }
  return items;
}

function buildReviewSearchQuery(searchQuery) {
  return /\b(reviews?|ratings?)\b/i.test(searchQuery)
    ? searchQuery
    : `${searchQuery} customer reviews`;
}

async function collectBingBrowser(context, searchQuery) {
  const page = await context.newPage();
  await page.setDefaultTimeout(PLAYWRIGHT_STEP_TIMEOUT);
  try {
    const bingUrl = 'https://www.bing.com/search?q=' + encodeURIComponent(searchQuery);
    await page.goto(bingUrl, { waitUntil: 'domcontentloaded', timeout: PLAYWRIGHT_STEP_TIMEOUT });
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
  } catch {
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
  const nearbyPlacesPromise = includeNearby
    ? Promise.all([
      fetchPhotonPlaces(searchQuery, 50),
      ...nearbyQueries.map(query => fetchPhotonPlaces(`${query} Davao City`, 50))
    ]).then(placeGroups => {
      const seenPlaces = new Set();
      return placeGroups.flat().filter(place => {
        if (!place.url || seenPlaces.has(place.url)) return false;
        seenPlaces.add(place.url);
        return true;
      });
    })
    : null;
  const collectSearchResults = async query => {
    let found = await fetchBraveHttp(query);
    if (found.length < 3) {
      found = found.concat(await fetchDuckDuckGoHttp(query));
    }
    if (found.length < 3) {
      found = found.concat(await fetchBingHttp(query));
    }
    return found;
  };

  // Keep general discovery and food-specific review evidence from competing for one query's results.
  const [generalCandidates, reviewCandidates] = await Promise.all([
    collectSearchResults(searchQuery),
    collectSearchResults(reviewSearchQuery)
  ]);
  let candidates = [];
  const maxSearchResults = Math.max(generalCandidates.length, reviewCandidates.length);
  for (let i = 0; i < maxSearchResults; i++) {
    if (generalCandidates[i]) candidates.push({ ...generalCandidates[i], reviewSearch: false });
    if (reviewCandidates[i]) candidates.push({ ...reviewCandidates[i], reviewSearch: true });
  }
  if (candidates.length < 3) {
    const photonPlaces = nearbyPlacesPromise ? await nearbyPlacesPromise : await fetchPhotonPlaces(searchQuery);
    candidates.push(...photonPlaces.slice(0, 8).map(item => ({ ...item, reviewSearch: false })));
  }

  const priceCandidates = [];
  const priceSearchResults = await Promise.all(priceQueries.map(async priceQuery => {
    const targetedQuery = /\bdavao\b/i.test(priceQuery) ? priceQuery : `${priceQuery} Davao City`;
    const [brave, duckDuckGo, bing] = await Promise.all([
      fetchBraveHttp(targetedQuery),
      fetchDuckDuckGoHttp(targetedQuery),
      fetchBingHttp(targetedQuery)
    ]);
    const seen = new Set();
    return [...brave, ...duckDuckGo, ...bing].filter(item => {
      const key = String(item.url || '').split('#')[0].replace(/\/+$/, '');
      if (!key || seen.has(key)) return false;
      seen.add(key);
      return true;
    }).slice(0, 2).map(item => ({ ...item, priceLookup: true }));
  }));
  priceCandidates.push(...priceSearchResults.flat());
  candidates = priceCandidates.concat(candidates);

  // 2. Browser fallback if direct HTTPS returned fewer than 3 results
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

  if (candidates.length < 3) {
    try {
      const ctx = await getContext();
      const bingBrowser = await collectBingBrowser(ctx, searchQuery);
      candidates.push(...bingBrowser.map(item => ({ ...item, reviewSearch: false })));
      if (candidates.length < 3) {
        const ddgBrowser = await collectDuckDuckGoBrowser(ctx, searchQuery);
        candidates.push(...ddgBrowser.map(item => ({ ...item, reviewSearch: false })));
      }
    } catch {}
  }

  if (priceCandidates.length > 0) {
    try {
      await getContext();
    } catch {}
  }

  const results = [];
  const seenUrls = new Set();
  const deduped = [];
  for (const item of candidates) {
    const normUrl = item.url.split('#')[0].replace(/\/+$/, '');
    if (!normUrl || seenUrls.has(normUrl)) continue;
    seenUrls.add(normUrl);
    deduped.push(item);
    if (deduped.length >= 16) break;
  }

  // 3. For candidates that are social media (Facebook/TikTok/Instagram) or already have rich snippets,
  // the snippet is already fresh and grounded. For regular web domains, do a quick parallel fetch if browser is active.
  let fetchedPricePages = 0;
  for (const link of deduped) {
    if (results.length >= 12) break;
    const isSocialOrReview = /facebook\.com|tiktok\.com|instagram\.com|foodpanda\.ph|grab\.com/i.test(link.url);
    let pageTitle = link.title;
    let bodyText = '';

    const shouldFetchPricePage = link.priceLookup && fetchedPricePages < 3;
    const shouldFetchPage = !link.placeLookup && (priceCandidates.length > 0 ? shouldFetchPricePage : Boolean(browser));
    if (!isSocialOrReview && shouldFetchPage) {
      try {
        const ctx = await getContext();
        if (shouldFetchPricePage) fetchedPricePages++;
        const newPage = await ctx.newPage();
        await newPage.setDefaultTimeout(Math.min(PLAYWRIGHT_STEP_TIMEOUT, shouldFetchPricePage ? 3000 : 5000));
        await newPage.goto(link.url, { waitUntil: 'domcontentloaded', timeout: Math.min(PLAYWRIGHT_STEP_TIMEOUT, shouldFetchPricePage ? 3500 : 6000) });
        const fetchedTitle = await newPage.title().catch(() => '');
        if (fetchedTitle && !/^(loading|just a moment|attention required|access denied|suspicious activity|security check|cloudflare|robot|verify)/i.test(fetchedTitle.trim())) {
          pageTitle = fetchedTitle.trim();
        }
        const rawBody = await newPage.locator('body').innerText().catch(() => '');
        if (!/unusual traffic|verify that you are human|turnstile|recaptcha|access denied|suspicious activity/i.test(rawBody)) {
          bodyText = rawBody;
        }
        await newPage.close().catch(() => {});
      } catch {}
    }

    const cleanedBody = String(bodyText || '').replace(/\s+/g, ' ').trim().slice(0, 4500);
    const combinedText = [
      link.snippet ? `Search snippet: ${link.snippet}` : '',
      cleanedBody
    ].filter(Boolean).join(' | ').slice(0, 5000);

    const checkBlob = `${pageTitle} ${link.title} ${link.snippet || ''} ${cleanedBody} ${link.url}`;
    if (combinedText && !isLowValueNonFoodUrl(link.url, pageTitle) && /\bdavao\b/i.test(checkBlob)) {
      results.push({
        title: pageTitle || link.title,
        url: link.url,
        text: combinedText,
        review_search: Boolean(link.reviewSearch),
        review_query: link.reviewSearch ? reviewSearchQuery : '',
        food_query: searchQuery,
        price_lookup: Boolean(link.priceLookup)
      });
    }
  }

  if (browser) {
    await browser.close().catch(() => {});
  }

  const nearbyPlaces = nearbyPlacesPromise ? await nearbyPlacesPromise : [];
  process.stdout.write(JSON.stringify({ query: finalTerm, results, nearby_places: nearbyPlaces }));
}

if (require.main === module) {
  run().catch(error => {
    process.stderr.write(String(error && error.message ? error.message : error));
    process.exit(1);
  });
}

module.exports = { buildReviewSearchQuery, fetchPhotonPlaces };
