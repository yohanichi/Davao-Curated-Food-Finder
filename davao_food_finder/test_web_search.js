const test = require('node:test');
const assert = require('node:assert/strict');
const https = require('node:https');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { EventEmitter } = require('node:events');
const { buildOsmSnapshot } = require('./scripts/update-osm-places');
const {
  collectBraveBrowser,
  collectPhotonPlaces,
  buildDiscoveryQueries,
  classifyEvidenceSource,
  fetchBraveSearchApi,
  fetchPhotonPlaces,
  nominatimSearchUrl,
  parseBraveBrowserResults,
  parseBraveSearchApiResults,
  parseNominatimPlaces,
  photonPlaceSearchUrl,
  parseBingHtml,
  retryAfterTimestamp,
  shouldFetchSourcePage,
  waitForSearchResults
} = require('./scripts/web-search');

test('adds bounded blog and public community discovery queries with source labels', () => {
  const queries = buildDiscoveryQueries('KFC restaurants Davao City');
  assert.equal(queries.length, 5);
  assert.match(queries.find(query => query.discoveryType === 'blog').query, /food blog recommendations/);
  assert.match(queries.find(query => query.discoveryType === 'community').query, /site:reddit\.com/);
  assert.match(queries.find(query => query.discoveryType === 'community').query, /site:facebook\.com/);
  assert.match(queries.find(query => query.discoveryType === 'community').query, /site:tiktok\.com/);
  assert.equal(classifyEvidenceSource('https://www.facebook.com/posts/123'), 'community_post');
  assert.equal(classifyEvidenceSource('https://old.reddit.com/r/food/123'), 'community_post');
  assert.equal(classifyEvidenceSource('https://example.com/davao-food', 'blog'), 'blog_search_result');
  assert.equal(classifyEvidenceSource('https://example.com/davao-food', 'web'), 'web_page');
});

test('uses menu and ordering searches instead of review-only queries for exact dishes', () => {
  const queries = buildDiscoveryQueries('Davao City palabok restaurants food');
  assert.equal(queries.length, 5);
  assert.match(queries[0].query, /\brestaurant\b/i);
  assert.match(queries[1].query, /"Pancit Palabok".*\bmenu\b/i);
  assert.match(queries[2].query, /"Palabok Fiesta".*\bmenu\b/i);
  assert.match(queries[3].query, /\border online delivery menu\b/i);
  assert.equal(queries.some(query => /\bOR\b/.test(query.query)), false);
  assert.equal(queries.some(query => query.reviewSearch), false);
});

test('recognizes every halo-halo spelling as a menu-item search', () => {
  for (const spelling of ['halo-halo', 'halo halo', 'halohalo']) {
    const queries = buildDiscoveryQueries(`${spelling} Davao City`);
    assert.equal(queries.length, 8);
    assert.equal(queries.some(query => query.discoveryType === 'menu'), true);
    assert.equal(queries.some(query => query.reviewSearch), false);
    assert.match(queries.find(query => query.query.includes('"Mang Inasal"')).query, /"halo-halo".*Davao City.*"Mang Inasal".*menu/i);
    assert.match(queries.find(query => query.query.includes('Chowking')).query, /"halo-halo".*Davao City.*Chowking.*menu/i);
    assert.match(queries.find(query => query.query.includes('Jollibee')).query, /"halo-halo".*Davao City.*Jollibee.*menu/i);
  }
});

test('builds a normalized local OSM snapshot from nodes and centered ways', () => {
  const snapshot = buildOsmSnapshot({
    osm3s: { timestamp_osm_base: '2026-11-16T12:00:00Z' },
    elements: [
      {
        type: 'node',
        id: 101,
        lat: 7.1,
        lon: 125.6,
        tags: {
          name: 'Sample Halal Cafe',
          amenity: 'cafe',
          cuisine: 'filipino',
          'diet:halal': 'yes',
          'addr:street': 'Roxas Avenue',
          'addr:suburb': 'Poblacion'
        }
      },
      {
        type: 'way',
        id: 102,
        center: { lat: 7.11, lon: 125.61 },
        tags: { name: 'Sample Bakery', shop: 'bakery' }
      },
      {
        type: 'node',
        id: 103,
        lat: 7.1,
        lon: 125.6,
        tags: { amenity: 'restaurant' }
      }
    ]
  }, '2026-11-16T12:05:00Z');

  assert.equal(snapshot.place_count, 2);
  assert.equal(snapshot.updated_at, '2026-11-16T12:05:00Z');
  assert.equal(snapshot.places[0].city, 'Davao City');
  assert.equal(snapshot.places[0].food_type, 'cafe');
  assert.equal(snapshot.places[0].dietary_tags.halal, 'yes');
  assert.equal(snapshot.places[0].address, 'Roxas Avenue, Poblacion, Davao City');
  assert.match(snapshot.places[1].url, /\/way\/102$/);
  assert.equal(snapshot.places[1].latitude, 7.11);
});

test('reuses Playwright search snippets instead of revisiting source pages', () => {
  assert.equal(shouldFetchSourcePage({
    playwright_search_result: true,
    snippet: 'A venue that serves fries in Davao City.'
  }, {
    browserActive: true,
    hasPriceCandidates: false,
    fetchedPricePages: 0
  }), false);

  assert.equal(shouldFetchSourcePage({
    playwright_search_result: true,
    snippet: ''
  }, {
    browserActive: true,
    hasPriceCandidates: false,
    fetchedPricePages: 0
  }), true);

  assert.equal(shouldFetchSourcePage({
    title: 'Burger King Philippines',
    playwright_search_result: true,
    snippet: 'A venue that serves burgers.'
  }, {
    browserActive: true,
    hasPriceCandidates: false,
    fetchedPricePages: 0
  }), true);
});

test('retains page fetches for price lookups and non-browser search results', () => {
  assert.equal(shouldFetchSourcePage({
    priceLookup: true,
    snippet: 'Menu prices'
  }, {
    browserActive: true,
    hasPriceCandidates: true,
    fetchedPricePages: 2
  }), true);

  assert.equal(shouldFetchSourcePage({
    priceLookup: true
  }, {
    browserActive: true,
    hasPriceCandidates: true,
    fetchedPricePages: 3
  }), false);

  assert.equal(shouldFetchSourcePage({
    playwright_search_result: false,
    snippet: 'Search engine snippet'
  }, {
    browserActive: true,
    hasPriceCandidates: false,
    fetchedPricePages: 0
  }), true);
});

test('does not fetch pages for mapped places or when the browser is inactive', () => {
  assert.equal(shouldFetchSourcePage({
    placeLookup: true,
    playwright_search_result: true,
    snippet: 'Mapped place'
  }, {
    browserActive: true,
    hasPriceCandidates: false,
    fetchedPricePages: 0
  }), false);

  assert.equal(shouldFetchSourcePage({
    playwright_search_result: false,
    snippet: 'Search engine snippet'
  }, {
    browserActive: false,
    hasPriceCandidates: false,
    fetchedPricePages: 0
  }), false);
});

test('retrieves and parses mapped venues through the Photon HTTP client', async () => {
  const originalGet = https.get;
  https.get = (url, options, onResponse) => {
    assert.match(url, /^https:\/\/photon\.komoot\.io\/api\//);
    const request = new EventEmitter();
    request.destroy = () => {};
    process.nextTick(() => {
      const response = new EventEmitter();
      response.statusCode = 200;
      onResponse(response);
      response.emit('data', JSON.stringify({
        features: [{
          properties: {
            name: 'Sample Cafe',
            osm_value: 'cafe',
            city: 'Davao City',
            country: 'Philippines',
            osm_type: 'N',
            osm_id: '12345',
            street: 'Roxas Avenue'
          },
          geometry: { coordinates: [125.6, 7.1] }
        }]
      }));
      response.emit('end');
    });
    return request;
  };

  try {
    const places = await fetchPhotonPlaces('cafes Davao City', 8);
    assert.equal(places.length, 1);
    assert.equal(places[0].name, 'Sample Cafe');
    assert.equal(places[0].placeLookup, true);
    assert.equal(places[0].latitude, 7.1);
    assert.equal(places[0].longitude, 125.6);
  } finally {
    https.get = originalGet;
  }
});

test('strips boolean menu expansions from Photon place queries', () => {
  const url = photonPlaceSearchUrl(
    'burger Davao City (burger OR burgers OR hamburger OR "Burger King") restaurants',
    8
  );
  assert.equal(new URL(url).searchParams.get('q'), 'burger Davao City');
});

test('builds a single Nominatim lookup for the normalized place query', () => {
  const url = new URL(nominatimSearchUrl('burger Davao City (burger OR burgers)', 8));
  assert.equal(url.origin, 'https://nominatim.openstreetmap.org');
  assert.equal(url.pathname, '/search');
  assert.equal(url.searchParams.get('q'), 'burger Davao City');
  assert.equal(url.searchParams.get('format'), 'jsonv2');
  assert.equal(url.searchParams.get('addressdetails'), '1');
  assert.equal(url.searchParams.get('limit'), '8');

  const palabokUrl = new URL(nominatimSearchUrl('palabok Davao City', 8));
  assert.equal(palabokUrl.searchParams.get('q'), 'restaurant Davao City');

  const dishUrl = new URL(nominatimSearchUrl('fries Obrero Davao City', 8));
  assert.equal(dishUrl.searchParams.get('q'), 'fast food Obrero Davao City');

  const halalUrl = new URL(nominatimSearchUrl('halal dishes Davao City restaurants', 40));
  assert.equal(halalUrl.searchParams.get('q'), 'halal restaurant Davao City');
});

test('bounds near-me Nominatim lookups to the rounded search area', () => {
  const bounds = { west: 125.58, south: 7.06, east: 125.69, north: 7.16 };
  const url = new URL(nominatimSearchUrl('fries Davao City', 40, bounds));
  assert.equal(url.searchParams.get('q'), 'fast food Davao City');
  assert.equal(url.searchParams.get('limit'), '40');
  assert.equal(url.searchParams.get('viewbox'), '125.58,7.06,125.69,7.16');
  assert.equal(url.searchParams.get('bounded'), '1');
});

test('parses only identified Davao City food venues and includes OSM attribution', () => {
  const places = parseNominatimPlaces([
    {
      name: 'Minute Burger',
      type: 'fast_food',
      lat: '7.084',
      lon: '125.612',
      osm_type: 'node',
      osm_id: '12345',
      address: {
        city: 'Davao City',
        country: 'Philippines',
        road: 'Roxas Avenue',
        country_code: 'ph'
      }
    },
    {
      name: 'Wrong City Cafe',
      type: 'cafe',
      lat: '7.084',
      lon: '125.612',
      osm_type: 'node',
      osm_id: '12346',
      address: { city: 'Tagum City', country: 'Philippines' }
    },
    {
      name: 'Unknown Type',
      type: 'shop',
      lat: '7.084',
      lon: '125.612',
      osm_type: 'node',
      osm_id: '12347',
      address: { city: 'Davao City', country: 'Philippines' }
    }
  ]);
  assert.equal(places.length, 1);
  assert.equal(places[0].name, 'Minute Burger');
  assert.equal(places[0].placeLookup, true);
  assert.equal(places[0].place_provider, 'OpenStreetMap Nominatim');
  assert.match(places[0].url, /openstreetmap\.org\/node\/12345$/);
  assert.match(places[0].snippet, /OpenStreetMap contributors/);
});

test('prefers Playwright Photon results when the HTTP fallback fails', async () => {
  const originalGet = https.get;
  https.get = () => { throw new Error('HTTP fallback unavailable'); };

  const browserPage = {
    setDefaultTimeout: () => {},
    goto: async url => {
      assert.equal(new URL(url).searchParams.get('q'), 'burger Davao City');
      return {
        ok: () => true,
        status: () => 200,
        text: async () => JSON.stringify({
          features: [{
            properties: {
              name: 'Minute Burger',
              osm_value: 'fast_food',
              city: 'Davao City',
              country: 'Philippines',
              osm_type: 'N',
              osm_id: '12345'
            },
            geometry: { coordinates: [125.6, 7.1] }
          }]
        })
      };
    },
    close: async () => {}
  };

  try {
    const places = await collectPhotonPlaces({
      newPage: async () => browserPage
    }, 'burger Davao City (burger OR burgers)', 8, [], true);
    assert.equal(places.length, 1);
    assert.equal(places[0].name, 'Minute Burger');
    assert.equal(places[0].playwright_search_result, true);
  } finally {
    https.get = originalGet;
  }
});

test('allows bounded Photon browser retries without making an unbounded Nominatim lookup', async () => {
  const originalGet = https.get;
  https.get = () => { throw new Error('HTTP fallback unavailable'); };
  const errors = [];
  const browserPage = {
    setDefaultTimeout: () => {},
    goto: async () => ({
      ok: () => true,
      status: () => 200,
      text: async () => JSON.stringify({ features: [] })
    }),
    close: async () => {}
  };

  try {
    const places = await collectPhotonPlaces({
      newPage: async () => browserPage
    }, 'restaurants Davao City', 8, errors, true, false);
    assert.deepEqual(places, []);
    assert.equal(errors.some(error => error.startsWith('Nominatim')), false);
  } finally {
    https.get = originalGet;
  }
});

test('waits for the Photon HTTP attempt before retrying through Chromium', async () => {
  const originalGet = https.get;
  let httpResponseFinished = false;
  https.get = (url, options, onResponse) => {
    const request = new EventEmitter();
    request.destroy = () => {};
    setTimeout(() => {
      const response = new EventEmitter();
      response.statusCode = 429;
      onResponse(response);
      httpResponseFinished = true;
    }, 5);
    return request;
  };
  let browserRetriedAfterHttp = false;
  const browserPage = {
    setDefaultTimeout: () => {},
    goto: async () => {
      browserRetriedAfterHttp = httpResponseFinished;
      return {
        ok: () => true,
        status: () => 200,
        text: async () => JSON.stringify({ features: [] })
      };
    },
    close: async () => {}
  };

  try {
    await collectPhotonPlaces({
      newPage: async () => browserPage
    }, 'restaurants Davao City', 8, [], true, false);
    assert.equal(browserRetriedAfterHttp, true);
  } finally {
    https.get = originalGet;
  }
});

test('reports Photon transport failures for diagnostics', async () => {
  const originalGet = https.get;
  https.get = () => {
    const request = new EventEmitter();
    request.destroy = () => {};
    process.nextTick(() => request.emit('error', new Error('connection refused')));
    return request;
  };

  try {
    const errors = [];
    assert.deepEqual(await fetchPhotonPlaces('cafes Davao City', 8, errors), []);
    assert.match(errors.join(' '), /OpenStreetMap Photon: connection refused/);
  } finally {
    https.get = originalGet;
  }
});

test('allows partially loaded search pages to continue after a result wait timeout', async () => {
  const errors = [];
  const page = {
    locator: () => ({
      first: () => ({
        waitFor: async () => {
          const error = new Error('timed out');
          error.name = 'TimeoutError';
          throw error;
        }
      })
    })
  };

  await waitForSearchResults(page, 'a.result__a', 'DuckDuckGo', errors);
  assert.match(errors.join(' '), /DuckDuckGo: no result elements appeared/);
});

test('does not suppress unexpected search-page wait errors', async () => {
  const page = {
    locator: () => ({
      first: () => ({
        waitFor: async () => {
          throw new Error('page closed');
        }
      })
    })
  };

  await assert.rejects(
    waitForSearchResults(page, 'a.result__a', 'DuckDuckGo', []),
    /page closed/
  );
});

test('decodes Bing click URLs and retains candidates for page-level location checks', () => {
  const destination = 'https://example.com/burger-menu';
  const encodedDestination = Buffer.from(destination).toString('base64')
    .replace(/\+/g, '-')
    .replace(/\//g, '_')
    .replace(/=+$/, '');
  const results = parseBingHtml(
    `<li class="b_algo"><h2><a href="https://www.bing.com/ck/a?u=a1${encodedDestination}&amp;x=1">Sample Burger</a></h2><p>Try our burger menu.</p></li>`
  );

  assert.equal(results.length, 1);
  assert.equal(results[0].url, destination);
  assert.equal(results[0].title, 'Sample Burger');
});

test('parses Brave browser results as Playwright evidence and rejects low-value pages', () => {
  const results = parseBraveBrowserResults([
    {
      title: 'Quiet cafes in Obrero, Davao City',
      url: 'https://example.com/obrero-cafe',
      snippet: 'Study-friendly cafe in Davao City.'
    },
    {
      title: 'Davao City - Wikipedia',
      url: 'https://en.wikipedia.org/wiki/Davao',
      snippet: 'General city information.'
    }
  ]);

  assert.equal(results.length, 1);
  assert.equal(results[0].title, 'Quiet cafes in Obrero, Davao City');
  assert.equal(results[0].snippet, 'Study-friendly cafe in Davao City.');
  assert.equal(results[0].playwright_search_result, true);
});

test('parses Brave API results as discovery candidates, not Playwright evidence', () => {
  const results = parseBraveSearchApiResults({
    web: {
      results: [
        {
          title: 'Burger cafe in Davao City',
          url: 'https://example.com/davao-burger',
          description: 'Burgers and fries in Davao City.'
        },
        {
          title: 'Davao City - Wikipedia',
          url: 'https://en.wikipedia.org/wiki/Davao',
          description: 'General city information.'
        }
      ]
    }
  });

  assert.equal(results.length, 1);
  assert.equal(results[0].title, 'Burger cafe in Davao City');
  assert.equal(results[0].playwright_search_result, false);
  assert.equal(results[0].discovery_provider, 'Brave Search API');
});

test('uses the Brave Search API and parses source candidates without treating them as scraped evidence', async () => {
  const originalGet = https.get;
  const originalApiKey = process.env.BRAVE_SEARCH_API_KEY;
  const cacheDir = fs.mkdtempSync(path.join(os.tmpdir(), 'dff-brave-api-'));
  process.env.BRAVE_SEARCH_API_KEY = 'test-token';
  https.get = (url, options, onResponse) => {
    assert.equal(new URL(url).origin, 'https://api.search.brave.com');
    assert.equal(new URL(url).pathname, '/res/v1/web/search');
    assert.equal(new URL(url).searchParams.get('q'), 'burger Davao City');
    assert.equal(options.headers['X-Subscription-Token'], 'test-token');
    const request = new EventEmitter();
    request.destroy = () => {};
    process.nextTick(() => {
      const response = new EventEmitter();
      response.statusCode = 200;
      response.headers = {};
      response.resume = () => {};
      onResponse(response);
      response.emit('data', JSON.stringify({
        web: {
          results: [{
            title: 'Burger cafe in Davao City',
            url: 'https://example.com/davao-burger',
            description: 'Burgers in Davao City.'
          }]
        }
      }));
      response.emit('end');
    });
    return request;
  };

  try {
    const errors = [];
    const results = await fetchBraveSearchApi('burger Davao City', 8, errors, cacheDir);
    assert.equal(results.length, 1);
    assert.equal(results[0].playwright_search_result, false);
    assert.deepEqual(errors, []);
  } finally {
    https.get = originalGet;
    if (originalApiKey === undefined) delete process.env.BRAVE_SEARCH_API_KEY;
    else process.env.BRAVE_SEARCH_API_KEY = originalApiKey;
    fs.rmSync(cacheDir, { recursive: true, force: true });
  }
});

test('records Retry-After on Brave API rate limits and skips calls during cooldown', async () => {
  const originalGet = https.get;
  const originalApiKey = process.env.BRAVE_SEARCH_API_KEY;
  const cacheDir = fs.mkdtempSync(path.join(os.tmpdir(), 'dff-brave-cooldown-'));
  process.env.BRAVE_SEARCH_API_KEY = 'test-token';
  let requestCount = 0;
  https.get = (url, options, onResponse) => {
    requestCount++;
    const request = new EventEmitter();
    request.destroy = () => {};
    process.nextTick(() => {
      const response = new EventEmitter();
      response.statusCode = 429;
      response.headers = { 'retry-after': '120' };
      response.resume = () => {};
      onResponse(response);
    });
    return request;
  };

  try {
    const errors = [];
    const before = Date.now();
    assert.deepEqual(await fetchBraveSearchApi('burger Davao City', 8, errors, cacheDir), []);
    assert.match(errors.join(' '), /HTTP 429 \(Retry-After: 120\)/);
    const cooldown = JSON.parse(fs.readFileSync(path.join(cacheDir, 'brave-api-cooldown.json'), 'utf8'));
    assert.ok(cooldown.until >= before + 119000);
    assert.deepEqual(await fetchBraveSearchApi('burger branches Davao City', 8, errors, cacheDir), []);
    assert.equal(requestCount, 1);
    assert.match(errors.join(' '), /skipped during rate-limit cooldown/);
  } finally {
    https.get = originalGet;
    if (originalApiKey === undefined) delete process.env.BRAVE_SEARCH_API_KEY;
    else process.env.BRAVE_SEARCH_API_KEY = originalApiKey;
    fs.rmSync(cacheDir, { recursive: true, force: true });
  }
});

test('records Retry-After on Brave browser rate limits and skips future browser searches', async () => {
  const cacheDir = fs.mkdtempSync(path.join(os.tmpdir(), 'dff-brave-browser-cooldown-'));
  let pageCount = 0;
  const context = {
    newPage: async () => {
      pageCount++;
      return {
        setDefaultTimeout: () => {},
        goto: async () => ({
          ok: () => false,
          status: () => 429,
          headers: () => ({ 'retry-after': '120' })
        }),
        close: async () => {}
      };
    }
  };

  try {
    const errors = [];
    assert.deepEqual(await collectBraveBrowser(context, 'burger Davao City', errors, cacheDir), []);
    assert.match(errors.join(' '), /Brave: HTTP 429/);
    const cooldown = JSON.parse(fs.readFileSync(path.join(cacheDir, 'brave-browser-cooldown.json'), 'utf8'));
    assert.ok(cooldown.until > Date.now());
    assert.deepEqual(await collectBraveBrowser(context, 'burger branches Davao City', errors, cacheDir), []);
    assert.equal(pageCount, 1);
    assert.match(errors.join(' '), /Brave: skipped during rate-limit cooldown/);
  } finally {
    fs.rmSync(cacheDir, { recursive: true, force: true });
  }
});

test('parses both Retry-After seconds and HTTP dates', () => {
  const now = Date.now();
  const retryDate = new Date(now + 8000).toUTCString();
  assert.equal(retryAfterTimestamp('5', now), now + 5000);
  assert.equal(retryAfterTimestamp(retryDate, now), Date.parse(retryDate));
  assert.equal(retryAfterTimestamp('', now), null);
});
