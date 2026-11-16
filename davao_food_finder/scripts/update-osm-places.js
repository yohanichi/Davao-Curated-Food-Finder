const fs = require('node:fs');
const path = require('node:path');
const https = require('node:https');

const OUTPUT_PATH = path.join(__dirname, '..', 'data', 'osm-nearby-places.json');
const OVERPASS_URL = process.env.OSM_OVERPASS_URL || 'https://overpass.openstreetmap.fr/api/interpreter';
const OVERPASS_QUERY = '[out:json][timeout:60];'
  + 'area["ISO3166-1"="PH"]["admin_level"="2"]->.country;'
  + 'area["name"="Davao City"]["boundary"="administrative"]["admin_level"="6"](area.country)->.city;'
  + '(nwr["amenity"~"^(restaurant|cafe|fast_food|food_court|ice_cream|bar|pub)$"](area.city);'
  + 'nwr["shop"~"^(bakery|confectionery|deli)$"](area.city););'
  + 'out center tags;';

const FOOD_TYPES = {
  restaurant: 'restaurant',
  cafe: 'cafe',
  fast_food: 'fast food',
  food_court: 'food court',
  ice_cream: 'ice cream',
  bar: 'bar',
  pub: 'pub',
  bakery: 'bakery',
  confectionery: 'confectionery',
  deli: 'deli'
};

function normalizeOsmElement(element) {
  if (!element || !['node', 'way', 'relation'].includes(element.type) || !/^\d+$/.test(String(element.id || ''))) {
    return null;
  }
  const tags = element.tags && typeof element.tags === 'object' ? element.tags : {};
  const name = String(tags.name || tags['name:en'] || '').trim();
  const osmCategory = String(tags.amenity || tags.shop || '').trim();
  const foodType = FOOD_TYPES[osmCategory];
  const latitude = Number(element.lat ?? element.center?.lat);
  const longitude = Number(element.lon ?? element.center?.lon);
  if (!name || !foodType
    || !Number.isFinite(latitude) || latitude < -90 || latitude > 90
    || !Number.isFinite(longitude) || longitude < -180 || longitude > 180) {
    return null;
  }

  const osmId = String(element.id);
  const street = String(tags['addr:street'] || tags['addr:place'] || '').trim();
  const district = String(
    tags['addr:suburb']
    || tags['addr:district']
    || tags['addr:neighbourhood']
    || tags['addr:quarter']
    || tags['addr:city_district']
    || ''
  ).trim();
  const address = [...new Set([street, district, 'Davao City'].filter(Boolean))].join(', ');
  const dietaryTags = {};
  for (const key of ['halal', 'vegan', 'vegetarian']) {
    const value = String(tags[`diet:${key}`] || tags[key] || '').trim();
    if (value) dietaryTags[key] = value;
  }
  const url = `https://www.openstreetmap.org/${element.type}/${osmId}`;

  return {
    name,
    title: name,
    url,
    snippet: `OpenStreetMap lists this ${foodType}${address ? ` at ${address}` : ''}. OpenStreetMap contributors (https://www.openstreetmap.org/copyright).`,
    placeLookup: true,
    place_provider: 'OpenStreetMap local snapshot',
    latitude,
    longitude,
    city: 'Davao City',
    address,
    district,
    street,
    food_type: foodType,
    cuisine: String(tags.cuisine || '').trim(),
    dietary_tags: dietaryTags,
    osm_type: element.type,
    osm_id: osmId
  };
}

function buildOsmSnapshot(payload, updatedAt = new Date().toISOString()) {
  if (!payload || !Array.isArray(payload.elements) || !payload.osm3s?.timestamp_osm_base) {
    throw new Error('Overpass response is missing OSM elements or its data timestamp.');
  }
  const places = [];
  const seen = new Set();
  for (const element of payload.elements) {
    const place = normalizeOsmElement(element);
    if (!place || seen.has(place.url)) continue;
    seen.add(place.url);
    places.push(place);
  }
  if (!places.length) throw new Error('The Davao City OSM extract contained no named food venues.');
  return {
    schema_version: 1,
    source: 'OpenStreetMap',
    attribution: '© OpenStreetMap contributors',
    osm_base_timestamp: payload.osm3s.timestamp_osm_base,
    updated_at: updatedAt,
    place_count: places.length,
    places
  };
}

function fetchOverpass(url) {
  return new Promise((resolve, reject) => {
    let parsedUrl;
    try {
      parsedUrl = new URL(url);
    } catch (error) {
      reject(new Error(`OSM_OVERPASS_URL is invalid: ${error.message}`));
      return;
    }
    if (parsedUrl.protocol !== 'https:') {
      reject(new Error('OSM_OVERPASS_URL must use HTTPS.'));
      return;
    }

    const body = new URLSearchParams({ data: OVERPASS_QUERY }).toString();
    const request = https.request(parsedUrl, {
      method: 'POST',
      headers: {
        'User-Agent': 'DavaoFoodFinder/1.0 (OpenStreetMap snapshot updater)',
        'Accept': 'application/json',
        'Content-Type': 'application/x-www-form-urlencoded',
        'Content-Length': Buffer.byteLength(body)
      }
    }, response => {
      let responseBody = '';
      response.on('data', chunk => {
        responseBody += chunk;
        if (responseBody.length > 20000000) {
          request.destroy(new Error('Overpass response exceeded 20 MB.'));
        }
      });
      response.on('end', () => {
        if (response.statusCode !== 200) {
          reject(new Error(`Overpass returned HTTP ${response.statusCode || 'unknown'}: ${responseBody.slice(0, 300)}`));
          return;
        }
        try {
          resolve(JSON.parse(responseBody));
        } catch (error) {
          reject(new Error(`Overpass returned invalid JSON: ${error.message}`));
        }
      });
    });
    request.setTimeout(90000, () => request.destroy(new Error('Overpass request timed out after 90 seconds.')));
    request.on('error', reject);
    request.end(body);
  });
}

function writeSnapshot(snapshot, outputPath = OUTPUT_PATH) {
  fs.mkdirSync(path.dirname(outputPath), { recursive: true });
  const temporaryPath = `${outputPath}.${process.pid}.tmp`;
  fs.writeFileSync(temporaryPath, `${JSON.stringify(snapshot)}\n`, 'utf8');
  fs.renameSync(temporaryPath, outputPath);
}

async function main() {
  const inputIndex = process.argv.indexOf('--input');
  const payload = inputIndex >= 0
    ? JSON.parse(fs.readFileSync(process.argv[inputIndex + 1], 'utf8').replace(/^\uFEFF/, ''))
    : await fetchOverpass(OVERPASS_URL);
  const snapshot = buildOsmSnapshot(payload);
  writeSnapshot(snapshot);
  process.stdout.write(
    `Saved ${snapshot.place_count} Davao food places from OSM snapshot ${snapshot.osm_base_timestamp} to ${OUTPUT_PATH}\n`
  );
}

if (require.main === module) {
  main().catch(error => {
    process.stderr.write(`${error.message}\n`);
    process.exitCode = 1;
  });
}

module.exports = { buildOsmSnapshot, normalizeOsmElement };
