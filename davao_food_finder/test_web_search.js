const test = require('node:test');
const assert = require('node:assert/strict');
const https = require('node:https');
const { EventEmitter } = require('node:events');
const { buildOsmSnapshot } = require('./scripts/update-osm-places');
const {
  collectPhotonPlaces,
  fetchPhotonPlaces,
  nominatimSearchUrl,
  parseBraveBrowserResults,
  parseNominatimPlaces,
  photonPlaceSearchUrl,
  parseBingHtml,
  shouldFetchSourcePage,
  waitForSearchResults
} = require('./scripts/web-search');

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
