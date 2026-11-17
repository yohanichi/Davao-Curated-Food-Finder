const fs = require('node:fs');
const path = require('node:path');
const https = require('node:https');

const OUTPUT_PATH = path.join(__dirname, '..', 'data', 'osm-nearby-places.json');
const OVERPASS_URL = process.env.OSM_OVERPASS_URL || 'https://overpass.openstreetmap.fr/api/interpreter';
const OVERPASS_QUERY = '[out:json][timeout:60];'
  + 'area["ISO3166-1"="PH"]["admin_level"="2"]->.country;'
  + 'area["name"="Davao City"]["boundary"="administrative"]["admin_level"="6"](area.country)->.city;'
  + '(nwr["amenity"~"^(restaurant|cafe|fast_food|food_court|ice_cream|bar|pub)$"](area.city);'
  + 'nwr["shop"~"^(bakery|confectionery|deli)$"](area.city);)->.places;'
  + 'way["highway"~"^(motorway|trunk|primary|secondary|tertiary|unclassified|residential|living_street)$"]["name"](area.city)->.roads;'
  + '.places out center tags;'
  + '.roads out geom;';

const NAMED_ROAD_TYPES = new Set([
  'motorway',
  'trunk',
  'primary',
  'secondary',
  'tertiary',
  'unclassified',
  'residential',
  'living_street'
]);
const ROAD_GRID_SIZE_DEGREES = 0.002;
const MAX_NEARBY_STREET_DISTANCE_METERS = 200;

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
  const branch = String(tags.branch || '').trim();
  const district = String(
    tags['addr:suburb']
    || tags['addr:district']
    || tags['addr:neighbourhood']
    || tags['addr:quarter']
    || tags['addr:city_district']
    || ''
  ).trim();
  const address = [...new Set([street || branch, district, 'Davao City'].filter(Boolean))].join(', ');
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
    branch,
    district,
    street,
    food_type: foodType,
    cuisine: String(tags.cuisine || '').trim(),
    dietary_tags: dietaryTags,
    osm_type: element.type,
    osm_id: osmId
  };
}

function normalizeOsmRoad(element) {
  if (!element || element.type !== 'way' || !Array.isArray(element.geometry)) return null;
  const tags = element.tags && typeof element.tags === 'object' ? element.tags : {};
  const name = String(tags.name || tags['name:en'] || '').trim();
  const highway = String(tags.highway || '').trim();
  if (!name || !NAMED_ROAD_TYPES.has(highway) || ['no', 'private'].includes(String(tags.access || '').trim())) {
    return null;
  }

  const geometry = element.geometry
    .map(point => [Number(point.lat), Number(point.lon)])
    .filter(([latitude, longitude]) => Number.isFinite(latitude) && latitude >= -90 && latitude <= 90
      && Number.isFinite(longitude) && longitude >= -180 && longitude <= 180);
  return geometry.length >= 2 ? { name, geometry } : null;
}

function buildRoadSegmentIndex(roads) {
  const grid = new Map();
  let segmentId = 0;
  for (const road of roads) {
    for (let index = 1; index < road.geometry.length; index++) {
      const start = road.geometry[index - 1];
      const end = road.geometry[index];
      const segment = { id: segmentId++, name: road.name, start, end };
      const minLatitudeCell = Math.floor(Math.min(start[0], end[0]) / ROAD_GRID_SIZE_DEGREES);
      const maxLatitudeCell = Math.floor(Math.max(start[0], end[0]) / ROAD_GRID_SIZE_DEGREES);
      const minLongitudeCell = Math.floor(Math.min(start[1], end[1]) / ROAD_GRID_SIZE_DEGREES);
      const maxLongitudeCell = Math.floor(Math.max(start[1], end[1]) / ROAD_GRID_SIZE_DEGREES);
      for (let latitudeCell = minLatitudeCell; latitudeCell <= maxLatitudeCell; latitudeCell++) {
        for (let longitudeCell = minLongitudeCell; longitudeCell <= maxLongitudeCell; longitudeCell++) {
          const key = `${latitudeCell}:${longitudeCell}`;
          if (!grid.has(key)) grid.set(key, []);
          grid.get(key).push(segment);
        }
      }
    }
  }
  return grid;
}

function distanceToRoadSegmentMeters(latitude, longitude, segment) {
  const metersPerLongitudeDegree = 111320 * Math.cos(latitude * Math.PI / 180);
  const metersPerLatitudeDegree = 110574;
  const startX = (segment.start[1] - longitude) * metersPerLongitudeDegree;
  const startY = (segment.start[0] - latitude) * metersPerLatitudeDegree;
  const endX = (segment.end[1] - longitude) * metersPerLongitudeDegree;
  const endY = (segment.end[0] - latitude) * metersPerLatitudeDegree;
  const deltaX = endX - startX;
  const deltaY = endY - startY;
  const lengthSquared = deltaX * deltaX + deltaY * deltaY;
  const projection = lengthSquared === 0
    ? 0
    : Math.max(0, Math.min(1, -(startX * deltaX + startY * deltaY) / lengthSquared));
  return Math.hypot(startX + projection * deltaX, startY + projection * deltaY);
}

function findNearestNamedRoad(place, roadIndex) {
  const latitude = Number(place.latitude);
  const longitude = Number(place.longitude);
  const latitudePadding = MAX_NEARBY_STREET_DISTANCE_METERS / 110574;
  const longitudePadding = MAX_NEARBY_STREET_DISTANCE_METERS
    / (111320 * Math.max(0.01, Math.cos(latitude * Math.PI / 180)));
  const minLatitudeCell = Math.floor((latitude - latitudePadding) / ROAD_GRID_SIZE_DEGREES) - 1;
  const maxLatitudeCell = Math.floor((latitude + latitudePadding) / ROAD_GRID_SIZE_DEGREES) + 1;
  const minLongitudeCell = Math.floor((longitude - longitudePadding) / ROAD_GRID_SIZE_DEGREES) - 1;
  const maxLongitudeCell = Math.floor((longitude + longitudePadding) / ROAD_GRID_SIZE_DEGREES) + 1;
  const seen = new Set();
  let nearest = null;

  for (let latitudeCell = minLatitudeCell; latitudeCell <= maxLatitudeCell; latitudeCell++) {
    for (let longitudeCell = minLongitudeCell; longitudeCell <= maxLongitudeCell; longitudeCell++) {
      for (const segment of roadIndex.get(`${latitudeCell}:${longitudeCell}`) || []) {
        if (seen.has(segment.id)) continue;
        seen.add(segment.id);
        const distanceMeters = distanceToRoadSegmentMeters(latitude, longitude, segment);
        if (distanceMeters <= MAX_NEARBY_STREET_DISTANCE_METERS
          && (nearest === null || distanceMeters < nearest.distanceMeters)) {
          nearest = { name: segment.name, distanceMeters };
        }
      }
    }
  }
  return nearest;
}

function buildOsmSnapshot(payload, updatedAt = new Date().toISOString()) {
  if (!payload || !Array.isArray(payload.elements) || !payload.osm3s?.timestamp_osm_base) {
    throw new Error('Overpass response is missing OSM elements or its data timestamp.');
  }
  const places = [];
  const roads = [];
  const seen = new Set();
  for (const element of payload.elements) {
    const place = normalizeOsmElement(element);
    if (place && !seen.has(place.url)) {
      seen.add(place.url);
      places.push(place);
    }
    const road = normalizeOsmRoad(element);
    if (road) roads.push(road);
  }
  if (!places.length) throw new Error('The Davao City OSM extract contained no named food venues.');
  const roadIndex = buildRoadSegmentIndex(roads);
  for (const place of places) {
    if (place.street) continue;
    const nearestRoad = findNearestNamedRoad(place, roadIndex);
    if (!nearestRoad) continue;
    place.nearby_street = nearestRoad.name;
    place.nearby_street_distance_m = Math.round(nearestRoad.distanceMeters);
  }
  return {
    schema_version: 1,
    source: 'OpenStreetMap',
    attribution: '© OpenStreetMap contributors',
    osm_base_timestamp: payload.osm3s.timestamp_osm_base,
    updated_at: updatedAt,
    place_count: places.length,
    road_count: roads.length,
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

module.exports = { buildOsmSnapshot, normalizeOsmElement, normalizeOsmRoad };
