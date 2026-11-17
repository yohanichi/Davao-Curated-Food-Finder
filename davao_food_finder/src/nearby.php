<?php

const DAVAO_NEARBY_RADIUS_KM = 3.0;

function nearbyPlaceCore(string $name): string {
    $core = preg_replace('/\s*(?:[-–|]\s*[^-–|]+|\([^)]*\))\s*$/u', '', trim($name)) ?? trim($name);
    return mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', $core) ?? '', 'UTF-8');
}

function fastFoodChainMatchesItems(array $place, array $items): bool {
    $core = nearbyPlaceCore((string)($place['name'] ?? ''));
    if ($core === '' || !$items) return false;

    $chainItems = [
        'mcdonalds'=>['burgers', 'chicken', 'fries', 'sundaes'],
        'kfc'=>['chicken', 'fries'],
        'jollibee'=>['burgers', 'chicken', 'fries', 'sundaes'],
        'burgerking'=>['burgers', 'fries', 'sundaes'],
        'manginasal'=>['chicken'],
    ];
    foreach ($chainItems as $chain => $supportedItems) {
        if (($core === $chain || str_starts_with($core, $chain))
            && !array_diff($items, $supportedItems)) {
            return true;
        }
    }
    return false;
}

function nearbyIntentRequiresEvidence(array $intent): bool {
    return !empty($intent['thematic']) || !empty($intent['ambiance']);
}

function nearbyIntentEvidenceLabel(array $intent): string {
    if (in_array('date_romantic', $intent['thematic'] ?? [], true)) return 'date-night or romantic atmosphere';
    if (in_array('quiet_study', $intent['ambiance'] ?? [], true)) return 'quiet or study-friendly atmosphere';
    if (in_array('family_group', $intent['ambiance'] ?? [], true)) return 'family or group-friendly atmosphere';
    return 'requested dining experience';
}

function nearbyAlternativeResultLimit(int $exactResultCount): int {
    return $exactResultCount <= 0 ? 20 : max(0, 5 - $exactResultCount);
}

function nearbyExperienceAlternativePlan(string $query, array $intent): ?array {
    $hasExperienceConstraint = nearbyIntentRequiresEvidence($intent);
    $isLocalFavoritesSearch = preg_match('/\b(local\s+(?:food|favorites?)|(?:davao|local)\s+(?:food|favorites?))\b/iu', $query) === 1;
    if ((!$hasExperienceConstraint && !$isLocalFavoritesSearch)
        || !empty($intent['strict_dishes'])
        || !empty($intent['online_only'])
        || !empty($intent['requested_restaurant_core'])
        || !empty($intent['areas'])
        || !empty($intent['hours'])
        || ($intent['min_rating'] ?? null) !== null
        || ($intent['max_price_php'] ?? null) !== null
        || ($intent['min_price_php'] ?? null) !== null
        || !empty($intent['budget'])
        || !empty($intent['price_preference'])) {
        return null;
    }

    $alternativeIntent = $intent;
    $alternativeIntent['ambiance'] = [];
    $alternativeIntent['thematic'] = [];
    $cuisines = is_array($alternativeIntent['cuisines'] ?? null) ? $alternativeIntent['cuisines'] : [];
    $dishes = is_array($alternativeIntent['specific_dishes'] ?? null) ? $alternativeIntent['specific_dishes'] : [];
    $venueType = null;

    if (!$cuisines && !$dishes && $isLocalFavoritesSearch) {
        $cuisines = ['Filipino'];
        $alternativeIntent['cuisines'] = $cuisines;
    } elseif (!$cuisines && !$dishes) {
        if (preg_match('/\b(caf[eé]s?|coffee\s+shops?)\b/iu', $query) === 1) {
            $venueType = 'cafe';
        } elseif (preg_match('/\b(restaurants?|dining|dinner|eat(?:ery|eries)?|food\s+spots?)\b/iu', $query) === 1) {
            $venueType = 'restaurant';
        } else {
            return null;
        }
    }
    if ($isLocalFavoritesSearch && !$cuisines && !$dishes) {
        return null;
    }
    if ($venueType !== null) $alternativeIntent['nearby_venue_type'] = $venueType;
    else unset($alternativeIntent['nearby_venue_type']);

    $queryParts = $dishes ?: $cuisines;
    if (!$queryParts && $venueType !== null) {
        $queryParts[] = $venueType === 'cafe' ? 'cafes' : 'restaurants';
    }
    if (!empty($alternativeIntent['areas'])) {
        $queryParts = array_merge($queryParts, array_map('strval', $alternativeIntent['areas']));
    }
    $alternativeQuery = trim(implode(' ', $queryParts));
    if ($alternativeQuery === '') return null;
    $alternativeIntent['effective_query'] = $alternativeQuery;

    if ($cuisines) {
        $categoryLabel = strtolower(implode(' and ', array_map('strval', $cuisines))) . ' restaurants';
        if (in_array('Coffee', $cuisines, true)) $categoryLabel = 'cafes';
    } else {
        $categoryLabel = $venueType === 'cafe' ? 'cafes' : 'restaurants';
    }
    $experienceLabel = nearbyIntentEvidenceLabel($intent);
    $note = $hasExperienceConstraint
        ? "No nearby place had enough evidence to confirm a {$experienceLabel}. These are nearby {$categoryLabel} alternatives; that preference is unverified."
        : "These are nearby {$categoryLabel} options; check the linked details to see which are local favorites.";

    return [
        'intent'=>$alternativeIntent,
        'query'=>$alternativeQuery,
        'label'=>$categoryLabel,
        'note'=>$note,
    ];
}

function nearbyFeatureMatchesVenueType(array $feature, string $venueType): bool {
    $featureText = mb_strtolower(implode(' ', array_filter([
        $feature['name'] ?? '',
        $feature['food_type'] ?? '',
        $feature['cuisine'] ?? '',
    ])), 'UTF-8');
    $featureText = str_replace('_', ' ', $featureText);
    if ($venueType === 'cafe') {
        return preg_match('/\b(caf[eé]s?|coffee|espresso|roastery)\b/iu', $featureText) === 1;
    }
    if ($venueType === 'restaurant') {
        return preg_match('/\brestaurant\b/iu', $featureText) === 1
            && preg_match('/\b(fast\s*food|food\s*court|kiosk)\b/iu', $featureText) !== 1;
    }
    return false;
}

function nearbyPlaceHasIntentEvidence(array $place, array $intent): bool {
    $evidenceRules = [
        'date_romantic'=>[
            'signal'=>'date_romantic',
            'pattern'=>'/\b(date[\s-]?night|romantic|couples?|intimate|anniversary|candlelit|candlelight|proposal|scenic\s+views?|city\s+views?|mountain\s+views?)\b/iu',
        ],
        'quiet_study'=>[
            'signal'=>'quiet_study',
            'pattern'=>'/\b(quiet|study|studying|peaceful|cozy|chill|reading|work[- ]friendly)\b/iu',
        ],
        'family_group'=>[
            'signal'=>'family_group',
            'pattern'=>'/\b(family[- ]friendly|families|groups?|barkada|kids|children|celebrations?|sharing|platter|buffet)\b/iu',
        ],
        'wifi_outlet'=>[
            'signal'=>'wifi_outlet',
            'pattern'=>'/\b(wi-?fi|outlets?|sockets?|power\s+plugs?)\b/iu',
        ],
        'solo_quick'=>[
            'signal'=>'solo_quick',
            'pattern'=>'/\b(solo|quick\s+bite|fast\s+service|on\s+the\s+go|lunch\s+break)\b/iu',
        ],
        'pasalubong'=>[
            'signal'=>'pasalubong',
            'pattern'=>'/\b(pasalubong|souvenirs?|take[- ]?home|gift\s+boxes?)\b/iu',
        ],
    ];
    $requested = array_merge(
        is_array($intent['thematic'] ?? null) ? $intent['thematic'] : [],
        is_array($intent['ambiance'] ?? null) ? $intent['ambiance'] : []
    );
    if (!$requested) return true;

    $signals = is_array($place['_review_signals'] ?? null) ? $place['_review_signals'] : [];
    $reviewText = is_array($place['_review_snippets'] ?? null) ? implode(' ', $place['_review_snippets']) : '';
    $description = is_scalar($place['description'] ?? null) ? (string)$place['description'] : '';
    $evidenceText = $reviewText . ' ' . $description;
    foreach ($requested as $key) {
        $rule = $evidenceRules[(string)$key] ?? null;
        if ($rule === null) return false;
        if ((int)($signals[$rule['signal']] ?? 0) > 0) continue;
        if (preg_match($rule['pattern'], $evidenceText) !== 1) return false;
    }
    return true;
}

function nearbyBrandQueryTerms(array $candidates, int $limit = 3): array {
    $terms = [];
    foreach ($candidates as $candidate) {
        $name = trim((string)($candidate['name'] ?? ''));
        if ($name === '') continue;
        $branch = extractBranchArea($name);
        $core = $branch !== '' ? trim(substr($name, 0, strrpos($name, ' - '))) : trim(preg_replace('/\s*\([^)]*\)\s*$/u', '', $name) ?? $name);
        $key = nearbyPlaceCore($core);
        if ($key === '' || isset($terms[$key])) continue;
        $terms[$key] = $core;
        if (count($terms) >= max(1, $limit)) break;
    }
    return array_values($terms);
}

function nearbyCategoryQueryTerms(array $intent): array {
    if (!empty($intent['fast_food_items'])) return ['fast food'];

    $cuisines = array_map(
        fn($cuisine) => mb_strtolower(trim((string)$cuisine), 'UTF-8'),
        $intent['cuisines'] ?? []
    );
    $dishes = array_map(
        fn($dish) => mb_strtolower(trim((string)$dish), 'UTF-8'),
        $intent['specific_dishes'] ?? []
    );

    return array_intersect(['burgers', 'chicken', 'fried chicken'], $cuisines)
        || in_array('fries', $dishes, true)
        ? ['fast food']
        : [];
}

function nearbyBroadSearchSuggestions(array $intent): array {
    $suggestionsByDish = [
        'burger steak'=>['Filipino restaurants', 'burger places'],
        'biryani'=>['Indian restaurants'],
        'sisig'=>['Filipino restaurants'],
        'bulalo'=>['Filipino restaurants'],
        'balbacua'=>['Filipino restaurants'],
        'bulcachong'=>['Filipino restaurants'],
        'satti'=>['Filipino restaurants'],
        'pares'=>['Filipino restaurants'],
        'silog'=>['Filipino restaurants'],
        'kinilaw'=>['Filipino restaurants'],
        'lugaw'=>['Filipino restaurants'],
        'ramen'=>['Japanese restaurants'],
        'takoyaki'=>['Japanese restaurants'],
        'samgyupsal'=>['Korean restaurants'],
        'shawarma'=>['Middle Eastern restaurants'],
        'fries'=>['fast food', 'burger places'],
        'halo-halo'=>['Filipino dessert shops'],
    ];
    $suggestions = [];
    foreach (($intent['specific_dishes'] ?? []) as $dish) {
        $dish = mb_strtolower(trim((string)$dish), 'UTF-8');
        foreach ($suggestionsByDish[$dish] ?? [] as $suggestion) {
            if (!in_array($suggestion, $suggestions, true)) $suggestions[] = $suggestion;
            if (count($suggestions) >= 3) return $suggestions;
        }
    }
    return $suggestions;
}

function restaurantQueryCore(string $query): string {
    $cleaned = trim(preg_replace(
        '/^(?:please\s+)?(?:i\s+want|show\s+(?:me\s+)?|find\s+|look\s+for\s+|search\s+for\s+|recommend(?:\s+me)?\s+)/iu',
        '',
        trim($query)
    ) ?? trim($query));
    $cleaned = preg_replace(
        '/\s+(?:(?:anywhere\s+)?(?:near|in|around)\s+)?(?:me|davao(?:\s+city)?|lanang|matina(?:\s+crossing|\s+aplaya)?|obrero|bajada|ecoland|toril|buhangin|poblacion|downtown|agdao|talomo|maa)\b.*$/iu',
        '',
        $cleaned
    ) ?? $cleaned;
    $cleaned = preg_replace('/\b(?:restaurants?|branches?|locations?|stores?)\b/iu', ' ', $cleaned) ?? $cleaned;
    return nearbyPlaceCore(trim(preg_replace('/\s+/u', ' ', $cleaned) ?? $cleaned));
}

function detectExplicitRestaurantCoreQuery(array $places, string $query): ?string {
    $queryCore = restaurantQueryCore($query);
    if ($queryCore !== '') {
        foreach ($places as $place) {
            $core = nearbyPlaceCore((string)($place['name'] ?? ''));
            if ($core !== '' && $core === $queryCore) return $core;
        }
    }

    $queryTokens = preg_split(
        '/[^\p{L}\p{N}]+/u',
        mb_strtolower($query, 'UTF-8'),
        -1,
        PREG_SPLIT_NO_EMPTY
    ) ?: [];
    $genericTokens = array_fill_keys([
        'and', 'barbecue', 'bbq', 'burger', 'burgers', 'cafe', 'cafes', 'chicken',
        'coffee', 'dessert', 'desserts', 'dining', 'food', 'fried', 'restaurant',
        'restaurants', 'seafood', 'sushi', 'tea',
    ], true);
    $matches = [];

    foreach ($places as $place) {
        $name = trim((string)($place['name'] ?? ''));
        if ($name === '') continue;
        $baseName = preg_replace('/\s*(?:[-–|]\s*[^-–|]+|\([^)]*\))\s*$/u', '', $name) ?? $name;
        $core = nearbyPlaceCore($baseName);
        $nameTokens = preg_split(
            '/[^\p{L}\p{N}]+/u',
            mb_strtolower($baseName, 'UTF-8'),
            -1,
            PREG_SPLIT_NO_EMPTY
        ) ?: [];
        if ($core === '' || !$nameTokens) continue;

        $matched = false;
        $minimumLength = count($nameTokens) > 1 ? 2 : 1;
        for ($length = count($nameTokens); $length >= $minimumLength && !$matched; $length--) {
            for ($start = 0; $start + $length <= count($queryTokens); $start++) {
                if (array_slice($queryTokens, $start, $length) === array_slice($nameTokens, 0, $length)) {
                    $matched = true;
                    break;
                }
            }
        }
        if (!$matched && count($nameTokens) === 1) {
            $token = $nameTokens[0];
            if (mb_strlen($token, 'UTF-8') >= 6
                && !isset($genericTokens[$token])
                && in_array($token, $queryTokens, true)) {
                $matched = true;
            }
        }
        if ($matched) $matches[$core] = true;
    }

    return count($matches) === 1 ? (string)array_key_first($matches) : null;
}

function correctModelOutOfScopeForRecognizedDavaoArea(bool $modelOutOfScope, array $intent): bool {
    if ($modelOutOfScope && empty($intent['out_of_scope']) && !empty($intent['areas'])) {
        return false;
    }
    return $modelOutOfScope;
}

function detectApproximateRestaurantCoreQuery(array $places, string $query): ?string {
    $queryCore = restaurantQueryCore($query);
    $queryLength = strlen($queryCore);
    $genericFoodQueries = [
        'burger','burgers','chicken','coffee','dessert','desserts','fastfood','filipino',
        'fries','icecream','japanese','korean','milktea','pasta','pizza','ramen',
        'restaurant','restaurants','sandwich','seafood','sushi','tea','wings',
    ];
    if ($queryLength < 7
        || preg_match('/^[a-z0-9]+$/D', $queryCore) !== 1
        || in_array($queryCore, $genericFoodQueries, true)) {
        return null;
    }

    $cores = [];
    foreach ($places as $place) {
        $core = nearbyPlaceCore((string)($place['name'] ?? ''));
        if (strlen($core) >= 7 && preg_match('/^[a-z0-9]+$/D', $core) === 1) {
            $cores[$core] = true;
        }
    }

    $bestCore = null;
    $bestDistance = PHP_INT_MAX;
    $bestIsAmbiguous = false;
    foreach (array_keys($cores) as $core) {
        $distance = levenshtein($queryCore, $core);
        $maxDistance = max(1, intdiv(min($queryLength, strlen($core)), 5));
        if ($distance > $maxDistance) continue;

        if ($distance < $bestDistance) {
            $bestCore = $core;
            $bestDistance = $distance;
            $bestIsAmbiguous = false;
        } elseif ($distance === $bestDistance && $core !== $bestCore) {
            $bestIsAmbiguous = true;
        }
    }

    return $bestIsAmbiguous ? null : $bestCore;
}

function filterPlacesByRestaurantCore(array $places, string $core): array {
    return array_values(array_filter($places, fn($place) =>
        is_array($place) && restaurantNameMatchesCore((string)($place['name'] ?? ''), $core)
    ));
}

function restaurantNameMatchesCore(string $name, string $core): bool {
    if ($core === '') return false;
    if (nearbyPlaceCore($name) === $core) return true;

    $nameWords = mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', ' ', trim($name)) ?? '', 'UTF-8');
    return preg_match('/^' . preg_quote($core, '/') . '\s+[\p{L}\p{N}]/u', $nameWords) === 1;
}

function restaurantCoreDisplayName(array $places, string $core): string {
    foreach ($places as $place) {
        $name = trim((string)($place['name'] ?? ''));
        if ($name === '' || !restaurantNameMatchesCore($name, $core)) continue;
        if (extractBranchArea($name) !== '') {
            return trim(substr($name, 0, (int)strrpos($name, ' - ')));
        }
        return $name;
    }
    return mb_convert_case($core, MB_CASE_TITLE, 'UTF-8');
}

function nearbyRestaurantCoreSummary(array $places, string $core): string {
    $matchingPlaces = filterPlacesByRestaurantCore($places, $core);
    $summaryItems = [];
    foreach ($matchingPlaces as $place) {
        $name = trim((string)($place['name'] ?? ''));
        if ($name === '') continue;
        if (isset($place['distance_km']) && is_numeric($place['distance_km'])) {
            $name .= ' (' . number_format((float)$place['distance_km'], 1) . ' km)';
        }
        $summaryItems[] = $name;
    }

    $brandName = restaurantCoreDisplayName($matchingPlaces, $core);
    if (!$summaryItems) {
        return 'I could not verify a ' . $brandName
            . ' location within 3 km. Nearby places from other chains were excluded.';
    }

    return 'Verified ' . $brandName . ' locations within 3 km: '
        . implode(', ', array_slice($summaryItems, 0, 6))
        . '. Distances are approximate straight-line estimates; map coverage may be incomplete.';
}

function nearbyBranchTokens(array $place): array {
    $branch = extractBranchArea((string)($place['name'] ?? ''));
    $text = trim($branch . ' ' . (string)($place['address'] ?? ''));
    $ignored = array_fill_keys([
        'davao','city','philippines','branch','store','restaurant','restaurants',
        'sm','gmall','mall','food','court','center','centre','shopping','plaza',
        'building','bldg','the','at','and','of','in',
    ], true);
    return array_values(array_unique(array_filter(
        preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text, 'UTF-8')) ?: [],
        fn($token) => mb_strlen($token, 'UTF-8') >= 3 && !isset($ignored[$token])
    )));
}

function nearbyFeatureAddress(array $feature): string {
    $street = trim((string)($feature['street'] ?? ''));
    $branch = trim((string)($feature['branch'] ?? ''));
    $area = trim((string)($feature['district'] ?? '')) ?: trim((string)($feature['locality'] ?? ''));
    $address = trim((string)($feature['address'] ?? ''));
    $cityOnly = mb_strtolower($address, 'UTF-8') === 'davao city';

    if ($address !== '' && !$cityOnly) {
        if ($street === '' && $branch !== ''
            && mb_stripos($address, $branch, 0, 'UTF-8') === false) {
            return $branch . ', ' . $address;
        }
        return $address;
    }

    return implode(', ', array_filter([
        $street ?: $branch ?: $area,
        ($street || $branch) ? $area : '',
        'Davao City',
    ]));
}

function nearbyFeatureInferredStreet(array $feature): string {
    if (trim((string)($feature['street'] ?? '')) !== '') {
        return '';
    }
    return trim((string)($feature['nearby_street'] ?? ''));
}

function isValidNearbyMapPlace(array $place): bool {
    if (trim((string)($place['name'] ?? '')) === '' || !isDavaoCityName((string)($place['city'] ?? ''))) {
        return false;
    }
    if (!is_numeric($place['latitude'] ?? null) || !is_numeric($place['longitude'] ?? null)) {
        return false;
    }
    $latitude = (float)$place['latitude'];
    $longitude = (float)$place['longitude'];
    if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
        return false;
    }
    $url = trim((string)($place['url'] ?? ''));
    $path = (string)parse_url($url, PHP_URL_PATH);
    return filter_var($url, FILTER_VALIDATE_URL) !== false
        && strtolower((string)parse_url($url, PHP_URL_HOST)) === 'www.openstreetmap.org'
        && preg_match('#^/(?:node|way|relation)/\d+$#', $path) === 1;
}

function loadLocalOsmNearbySnapshot(string $snapshotPath, ?array $bounds = null): array {
    if (!is_file($snapshotPath) || !is_readable($snapshotPath)) {
        throw new RuntimeException('The local OpenStreetMap nearby index is missing or unreadable. Run node scripts/update-osm-places.js to build it.');
    }
    $contents = file_get_contents($snapshotPath);
    if ($contents === false) {
        throw new RuntimeException('The local OpenStreetMap nearby index could not be read.');
    }
    try {
        $snapshot = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new RuntimeException('The local OpenStreetMap nearby index contains invalid JSON.', 0, $error);
    }
    if (!is_array($snapshot)
        || ($snapshot['schema_version'] ?? null) !== 1
        || ($snapshot['source'] ?? '') !== 'OpenStreetMap'
        || !is_array($snapshot['places'] ?? null)
        || trim((string)($snapshot['osm_base_timestamp'] ?? '')) === '') {
        throw new RuntimeException('The local OpenStreetMap nearby index has an unsupported or incomplete format.');
    }
    if ($bounds !== null) {
        foreach (['west', 'south', 'east', 'north'] as $key) {
            if (!is_numeric($bounds[$key] ?? null) || !is_finite((float)$bounds[$key])) {
                throw new InvalidArgumentException('Local OpenStreetMap filtering requires valid rounded search bounds.');
            }
        }
    }

    $places = [];
    foreach ($snapshot['places'] as $place) {
        if (!is_array($place) || !isValidNearbyMapPlace($place)) continue;
        if ($bounds !== null) {
            $latitude = (float)$place['latitude'];
            $longitude = (float)$place['longitude'];
            if ($longitude < (float)$bounds['west'] || $longitude > (float)$bounds['east']
                || $latitude < (float)$bounds['south'] || $latitude > (float)$bounds['north']) continue;
        }
        $places[] = $place;
    }
    return [
        'places'=>$places,
        'place_count'=>(int)($snapshot['place_count'] ?? count($snapshot['places'])),
        'updated_at'=>(string)($snapshot['updated_at'] ?? ''),
        'osm_base_timestamp'=>(string)$snapshot['osm_base_timestamp'],
    ];
}

function nearbyFeatureMatchesVenue(array $venue, array $feature, int $venueCoreCount, int $featureCoreCount): bool {
    $venueCore = nearbyPlaceCore((string)($venue['name'] ?? ''));
    if ($venueCore === '' || $venueCore !== nearbyPlaceCore((string)($feature['name'] ?? ''))) {
        return false;
    }

    $branchTokens = nearbyBranchTokens($venue);
    if (!$branchTokens) {
        return $venueCoreCount === 1 && $featureCoreCount === 1;
    }

    $featureText = mb_strtolower(implode(' ', array_filter([
        $feature['address'] ?? '',
        $feature['branch'] ?? '',
        $feature['district'] ?? '',
        $feature['street'] ?? '',
        $feature['locality'] ?? '',
    ])), 'UTF-8');
    foreach ($branchTokens as $token) {
        if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($token, '/') . '(?![\p{L}\p{N}])/iu', $featureText) === 1) {
            return true;
        }
    }
    return false;
}

function nearbyDistanceKm(float $latitude, float $longitude, float $placeLatitude, float $placeLongitude): float {
    $earthRadiusKm = 6371.0088;
    $latDelta = deg2rad($placeLatitude - $latitude);
    $lonDelta = deg2rad($placeLongitude - $longitude);
    $a = sin($latDelta / 2) ** 2
        + cos(deg2rad($latitude)) * cos(deg2rad($placeLatitude)) * sin($lonDelta / 2) ** 2;
    return $earthRadiusKm * 2 * asin(min(1.0, sqrt($a)));
}

function nearbySearchBounds(float $latitude, float $longitude, float $radiusKm = DAVAO_NEARBY_RADIUS_KM): array {
    if (!is_finite($latitude) || $latitude < -90 || $latitude > 90
        || !is_finite($longitude) || $longitude < -180 || $longitude > 180
        || !is_finite($radiusKm) || $radiusKm <= 0) {
        throw new InvalidArgumentException('Nearby search bounds require valid coordinates and a positive radius.');
    }

    $latitudeDelta = $radiusKm / 111.32;
    $longitudeDelta = $radiusKm / (111.32 * max(0.01, abs(cos(deg2rad($latitude)))));
    $precision = 100.0;
    return [
        'west'=>max(-180.0, floor(($longitude - $longitudeDelta) * $precision) / $precision),
        'south'=>max(-90.0, floor(($latitude - $latitudeDelta) * $precision) / $precision),
        'east'=>min(180.0, ceil(($longitude + $longitudeDelta) * $precision) / $precision),
        'north'=>min(90.0, ceil(($latitude + $latitudeDelta) * $precision) / $precision),
    ];
}

function nearbyDistanceWithinRadius(float $distanceKm, float $radiusKm, float $locationAccuracyKm = 0.0): bool {
    return is_finite($distanceKm)
        && is_finite($radiusKm)
        && is_finite($locationAccuracyKm)
        && $distanceKm >= 0
        && $radiusKm >= 0
        && $locationAccuracyKm >= 0
        && $distanceKm + $locationAccuracyKm <= $radiusKm;
}

function nearbyFeatureDisplayName(array $feature, string $baseName = ''): string {
    $name = trim($baseName) ?: trim((string)($feature['name'] ?? ''));
    $branch = trim((string)($feature['branch'] ?? ''));
    $district = trim((string)($feature['district'] ?? ''));
    $locality = trim((string)($feature['locality'] ?? ''));
    $street = trim((string)($feature['street'] ?? '')) ?: nearbyFeatureInferredStreet($feature);
    $branchArea = $branch ?: $district ?: $locality;
    $nameHasArea = $branchArea !== '' && mb_stripos($name, $branchArea, 0, 'UTF-8') !== false;
    $nameHasStreet = $street !== '' && mb_stripos($name, $street, 0, 'UTF-8') !== false;
    $streetAddsDetail = $branchArea === '' || mb_strtolower($street, 'UTF-8') !== mb_strtolower($branchArea, 'UTF-8');
    if ($street !== '' && $streetAddsDetail && !$nameHasStreet) {
        $name .= $nameHasArea ? " ({$street})" : ' - ' . ($branchArea !== '' ? $branchArea . ' ' : '') . "({$street})";
    } elseif ($branchArea !== '' && !$nameHasArea) {
        $name .= ' - ' . $branchArea;
    }
    return $name;
}

function rankNearbyCandidates(
    array $candidates,
    array $allPlaces,
    array $mapPlaces,
    float $latitude,
    float $longitude,
    float $radiusKm,
    float $locationAccuracyKm = 0.0,
    bool $relevanceFirst = false,
    string $requestQuery = '',
    array $requestIntent = []
): array {
    $venueCoreCounts = [];
    foreach ($allPlaces as $place) {
        $core = nearbyPlaceCore((string)($place['name'] ?? ''));
        if ($core !== '') $venueCoreCounts[$core] = ($venueCoreCounts[$core] ?? 0) + 1;
    }

    $featuresByCore = [];
    foreach ($mapPlaces as $feature) {
        if (!is_array($feature) || !isValidNearbyMapPlace($feature)) continue;
        $core = nearbyPlaceCore((string)$feature['name']);
        if ($core !== '') $featuresByCore[$core][] = $feature;
    }

    $nearby = [];
    foreach ($candidates as $candidateIndex => $candidate) {
        $core = nearbyPlaceCore((string)($candidate['name'] ?? ''));
        $features = $featuresByCore[$core] ?? [];
        $nearest = null;
        foreach ($features as $feature) {
            if (!nearbyFeatureMatchesRequest($feature, $requestQuery, $requestIntent)) continue;
            if (!nearbyFeatureMatchesVenue($candidate, $feature, $venueCoreCounts[$core] ?? 1, count($features))) {
                continue;
            }
            $distance = nearbyDistanceKm($latitude, $longitude, (float)$feature['latitude'], (float)$feature['longitude']);
            if (!nearbyDistanceWithinRadius($distance, $radiusKm, $locationAccuracyKm)
                || ($nearest !== null && $distance >= $nearest['distance'])) continue;
            $nearest = ['feature'=>$feature, 'distance'=>$distance];
        }
        if ($nearest === null) continue;

        $candidate['_nearby_distance_km'] = $nearest['distance'];
        $candidate['_nearby_source_url'] = (string)$nearest['feature']['url'];
        $candidate['name'] = nearbyFeatureDisplayName($nearest['feature'], (string)($candidate['name'] ?? ''));
        if (trim((string)($candidate['address'] ?? '')) === '' || mb_strtolower(trim((string)$candidate['address']), 'UTF-8') === 'davao city') {
            $candidate['address'] = nearbyFeatureAddress($nearest['feature']);
            $candidate['nearby_street'] = nearbyFeatureInferredStreet($nearest['feature']);
        }
        $candidate['_nearby_relevance_order'] = $candidateIndex;
        $nearby[] = $candidate;
    }

    usort($nearby, fn($a, $b) => ($relevanceFirst
        ? ($a['_nearby_relevance_order'] <=> $b['_nearby_relevance_order'])
        : ($a['_nearby_distance_km'] <=> $b['_nearby_distance_km']))
        ?: ($a['_nearby_distance_km'] <=> $b['_nearby_distance_km'])
        ?: (reviewBasedRankingScore($b) <=> reviewBasedRankingScore($a)));
    foreach ($nearby as &$place) unset($place['_nearby_relevance_order']);
    unset($place);
    return $nearby;
}

function rankNearbyWebPlaces(
    array $webPlaces,
    array $mapPlaces,
    array $allPlaces,
    float $latitude,
    float $longitude,
    float $radiusKm,
    float $locationAccuracyKm = 0.0,
    bool $relevanceFirst = false
): array {
    $venueCoreCounts = [];
    foreach ($allPlaces as $place) {
        $core = nearbyPlaceCore((string)($place['name'] ?? ''));
        if ($core !== '') $venueCoreCounts[$core] = ($venueCoreCounts[$core] ?? 0) + 1;
    }
    $featuresByCore = [];
    foreach ($mapPlaces as $feature) {
        if (!is_array($feature) || !isValidNearbyMapPlace($feature)) continue;
        $core = nearbyPlaceCore((string)$feature['name']);
        if ($core !== '') $featuresByCore[$core][] = $feature;
    }

    $ranked = [];
    foreach ($webPlaces as $relevanceOrder => $webPlace) {
        if (!is_array($webPlace)) continue;
        $core = nearbyPlaceCore((string)($webPlace['name'] ?? ''));
        $features = $featuresByCore[$core] ?? [];
        $nearest = null;
        foreach ($features as $feature) {
            if (!nearbyFeatureMatchesVenue($webPlace, $feature, $venueCoreCounts[$core] ?? 1, count($features))) {
                continue;
            }
            $distance = nearbyDistanceKm($latitude, $longitude, (float)$feature['latitude'], (float)$feature['longitude']);
            if (!nearbyDistanceWithinRadius($distance, $radiusKm, $locationAccuracyKm)
                || ($nearest !== null && $distance >= $nearest['distance'])) continue;
            $nearest = ['feature'=>$feature, 'distance'=>$distance];
        }
        if ($nearest === null) continue;

        $webPlace['name'] = nearbyFeatureDisplayName($nearest['feature'], (string)($webPlace['name'] ?? ''));
        $webPlace['distance_km'] = round($nearest['distance'], 2);
        $webPlace['location_source_url'] = (string)$nearest['feature']['url'];
        $webPlace['location_label'] = nearbyFeatureAddress($nearest['feature']);
        $webPlace['location_verified'] = true;
        $webPlace['location_source'] = 'OpenStreetMap';
        $webPlace['_nearby_relevance_order'] = $relevanceOrder;
        if (trim((string)($webPlace['address'] ?? '')) === '' || mb_strtolower(trim((string)$webPlace['address']), 'UTF-8') === 'davao city') {
            $webPlace['address'] = nearbyFeatureAddress($nearest['feature']);
            $webPlace['nearby_street'] = nearbyFeatureInferredStreet($nearest['feature']);
        }
        $ranked[] = $webPlace;
    }

    usort($ranked, fn($a, $b) => ($relevanceFirst
        ? ($a['_nearby_relevance_order'] <=> $b['_nearby_relevance_order'])
        : ($a['distance_km'] <=> $b['distance_km']))
        ?: ($a['distance_km'] <=> $b['distance_km']));
    foreach ($ranked as &$place) unset($place['_nearby_relevance_order']);
    unset($place);
    return $ranked;
}

function nearbyFeatureMatchesRequest(array $feature, string $query, array $intent): bool {
    $strictDishes = is_array($intent['strict_dishes'] ?? null) ? $intent['strict_dishes'] : [];
    if ($strictDishes && !placeMatchesSpecificDishes($feature, $strictDishes)) return false;
    $fastFoodItems = is_array($intent['fast_food_items'] ?? null) ? $intent['fast_food_items'] : [];
    $nearbyVenueType = trim((string)($intent['nearby_venue_type'] ?? ''));
    if ($nearbyVenueType !== '' && !nearbyFeatureMatchesVenueType($feature, $nearbyVenueType)) return false;
    if (!nearbyPlaceHasIntentEvidence($feature, $intent)) return false;

    $hasDietaryConstraint = !empty($intent['dietary'])
        || preg_match('/\b(halal|vegan|vegetarian|muslim[- ]friendly|no\s+pork|pork[- ]free|lactose[- ]intoleran\w*|lactose[- ]free|dairy[- ]free|milk[- ]free|no\s+dairy|without\s+dairy|(?:milk|dairy)\s+allerg\w*|allerg\w*\s+to\s+(?:milk|dairy))\b/iu', $query) === 1;
    if (!$hasDietaryConstraint && detectExplicitRestaurantCoreQuery([$feature], $query) !== null) {
        return true;
    }

    $intentTerms = array_map(
        fn($value) => mb_strtolower(trim((string)$value), 'UTF-8'),
        array_merge($intent['cuisines'] ?? [], $intent['specific_dishes'] ?? [], $intent['dietary'] ?? [])
    );
    $requestedDietaryTerms = array_values(array_filter(
        ['halal', 'vegan', 'vegetarian', 'dairy_free'],
        fn($term) => preg_match('/(?<![\p{L}\p{N}])' . $term . '(?![\p{L}\p{N}])/iu', $query) === 1
            || in_array($term, $intentTerms, true)
    ));

    $cuisineTerms = [
        'coffee'=>['cafe','cafes','café','cafés','coffee','espresso','roastery'],
        'japanese'=>['japanese','sushi','ramen','udon','tempura','sashimi'],
        'korean'=>['korean','samgyup','samgyeopsal'],
        'chinese'=>['chinese','dim sum'],
        'filipino'=>['filipino'],
        'pizza'=>['pizza','italian'],
        'seafood'=>['seafood','fish','shrimp'],
        'burgers'=>['burger','burgers','hamburger','hamburgers'],
        'chicken'=>['chicken','fried chicken'],
        'fried chicken'=>['chicken','fried chicken'],
        'milk tea'=>['milk tea','bubble tea','tea'],
    ];
    $dishTerms = [
        'fries'=>['fries','french fries'],
    ];
    $requestedTerms = [];
    foreach (($intent['cuisines'] ?? []) as $cuisine) {
        $key = mb_strtolower(trim((string)$cuisine), 'UTF-8');
        $requestedTerms = array_merge($requestedTerms, $cuisineTerms[$key] ?? [$key]);
    }
    foreach (($intent['specific_dishes'] ?? []) as $dish) {
        $dish = mb_strtolower(trim((string)$dish), 'UTF-8');
        $requestedTerms = array_merge($requestedTerms, $dishTerms[$dish] ?? [$dish]);
    }

    $requestedTerms = array_values(array_unique(array_filter($requestedTerms)));
    if (!$requestedTerms && !$requestedDietaryTerms && !$fastFoodItems) return true;

    $featureText = mb_strtolower(implode(' ', array_filter([
        $feature['name'] ?? '',
        $feature['food_type'] ?? '',
        $feature['cuisine'] ?? '',
        $feature['description'] ?? '',
    ])), 'UTF-8');
    $dietaryTags = is_array($feature['dietary_tags'] ?? null) ? $feature['dietary_tags'] : [];
    foreach ($requestedDietaryTerms as $dietaryTerm) {
        $dietaryValue = mb_strtolower(trim((string)($dietaryTags[$dietaryTerm] ?? '')), 'UTF-8');
        if (in_array($dietaryValue, ['no', 'none', 'false', '0'], true)) return false;
        if ($dietaryValue !== '') $featureText .= ' ' . $dietaryTerm;
    }
    $featureText = str_replace('_', ' ', $featureText);
    foreach ($requestedDietaryTerms as $term) {
        if (preg_match(
            '/(?<![\p{L}\p{N}])' . preg_quote($term, '/') . '(?![\p{L}\p{N}])/iu',
            $featureText
        ) !== 1) {
            return false;
        }
    }
    if ($fastFoodItems) {
        if (fastFoodChainMatchesItems($feature, $fastFoodItems)) return true;
        $itemTerms = [
            'burgers'=>['burger', 'burgers', 'hamburger', 'hamburgers'],
            'chicken'=>['chicken', 'fried chicken'],
            'fries'=>['fries', 'french fries'],
            'sundaes'=>['sundae', 'sundaes'],
        ];
        foreach ($fastFoodItems as $item) {
            $terms = $itemTerms[$item] ?? [$item];
            $itemFound = false;
            foreach ($terms as $term) {
                if (preg_match(
                    '/(?<![\p{L}\p{N}])' . preg_quote($term, '/') . '(?![\p{L}\p{N}])/iu',
                    $featureText
                ) === 1) {
                    $itemFound = true;
                    break;
                }
            }
            if (!$itemFound) return false;
        }
        return true;
    }
    if (!$requestedTerms) return true;
    foreach ($requestedTerms as $term) {
        if (preg_match(
            '/(?<![\p{L}\p{N}])' . preg_quote($term, '/') . '(?![\p{L}\p{N}])/iu',
            $featureText
        ) === 1) {
            return true;
        }
    }
    return false;
}

function mapFindIntentIsSupported(array $intent, ?string $requestedRestaurantCore = null): bool {
    $areas = is_array($intent['areas'] ?? null) ? $intent['areas'] : [];
    $cuisines = is_array($intent['cuisines'] ?? null) ? $intent['cuisines'] : [];
    $dishes = is_array($intent['specific_dishes'] ?? null) ? $intent['specific_dishes'] : [];
    $dietary = is_array($intent['dietary'] ?? null) ? $intent['dietary'] : [];
    if (!empty($intent['out_of_scope'])
        || (!empty($intent['is_ambiguous']) && $requestedRestaurantCore === null)
        || !empty($intent['online_only'])
        || !empty($intent['min_rating'])
        || !empty($intent['min_price_php'])
        || !empty($intent['max_price_php'])
        || !empty($intent['budget'])
        || !empty($intent['price_preference'])
        || !empty($intent['ambiance'])
        || !empty($intent['thematic'])
        || !empty($intent['hours'])
        || count($areas) > 1) {
        return false;
    }

    $supportedDietaryTerms = ['halal', 'vegan', 'vegetarian'];
    $dietary = array_map(
        fn($term) => mb_strtolower(trim((string)$term), 'UTF-8'),
        $dietary
    );
    if (array_diff($dietary, $supportedDietaryTerms)) return false;

    $foodTermCount = count($cuisines) + count($dishes);
    if (!empty($intent['fast_food_items'])) $foodTermCount = 1;
    return $requestedRestaurantCore !== null
        || $foodTermCount === 1
        || ($foodTermCount === 0 && count($dietary) === 1);
}

function buildMapFindResults(
    array $mapPlaces,
    string $query,
    array $intent,
    ?string $requestedRestaurantCore = null,
    int $limit = 20
): array {
    if (!mapFindIntentIsSupported($intent, $requestedRestaurantCore)) return [];

    $requestedAreas = is_array($intent['areas'] ?? null) ? $intent['areas'] : [];
    $hasAdditionalFoodConstraints = !empty($intent['cuisines'])
        || !empty($intent['specific_dishes'])
        || !empty($intent['dietary']);
    $results = [];
    $seenUrls = [];
    foreach ($mapPlaces as $feature) {
        if (!is_array($feature) || !isValidNearbyMapPlace($feature)) continue;
        $name = nearbyFeatureDisplayName($feature);
        if ($requestedRestaurantCore !== null) {
            if (!restaurantNameMatchesCore($name, $requestedRestaurantCore)) continue;
            if ($hasAdditionalFoodConstraints && !nearbyFeatureMatchesRequest($feature, $query, $intent)) continue;
        } elseif (!nearbyFeatureMatchesRequest($feature, $query, $intent)) continue;
        $address = nearbyFeatureAddress($feature);
        if ($requestedAreas
            && evaluateDavaoAreaProximity(['name'=>$name, 'address'=>$address], $requestedAreas) !== 'exact') {
            continue;
        }

        $url = trim((string)$feature['url']);
        if (isset($seenUrls[$url])) continue;
        $seenUrls[$url] = true;
        $foodType = trim((string)($feature['food_type'] ?? 'Restaurant')) ?: 'Restaurant';
        $cuisine = trim(str_replace(';', ', ', (string)($feature['cuisine'] ?? '')));
        $category = $cuisine !== '' ? $foodType . ' (' . $cuisine . ')' : $foodType;
        $nearbyStreet = nearbyFeatureInferredStreet($feature);
        $locationDescription = $nearbyStreet !== '' ? "near {$nearbyStreet}" : "at {$address}";
        $isFastFoodChain = !empty($intent['fast_food_items'])
            && fastFoodChainMatchesItems($feature, $intent['fast_food_items']);
        $results[] = [
            'name'=>$name,
            'food_type'=>$category,
            'description'=>$isFastFoodChain
                ? "OpenStreetMap lists this fast-food chain {$locationDescription}. Branch-specific menu availability is not verified."
                : "OpenStreetMap lists this {$category} {$locationDescription}. Menu, prices, reviews, hours, and current business status are not verified.",
            'address'=>$address,
            'nearby_street'=>$nearbyStreet,
            'city'=>'Davao City',
            'rating'=>null,
            'review_count'=>null,
            'price_range'=>'',
            'source_url'=>$url,
            'source_reference'=>$url,
            'source_status'=>'supported',
            'unsupported'=>false,
            'map_only'=>true,
            'fast_food_chain'=>$isFastFoodChain,
            'location_source'=>'OpenStreetMap',
            'location_source_url'=>$url,
            'latitude'=>(float)$feature['latitude'],
            'longitude'=>(float)$feature['longitude'],
        ];
    }

    usort($results, fn($a, $b) => strnatcasecmp((string)$a['name'], (string)$b['name']));
    $deduped = [];
    $duplicateDistanceKm = 0.03;
    foreach ($results as $result) {
        $duplicateIndex = null;
        foreach ($deduped as $index => $existing) {
            $existingCore = $requestedRestaurantCore ?? nearbyPlaceCore((string)$existing['name']);
            $resultCore = $requestedRestaurantCore ?? nearbyPlaceCore((string)$result['name']);
            if ($existingCore !== $resultCore) continue;
            $distance = nearbyDistanceKm(
                (float)$existing['latitude'],
                (float)$existing['longitude'],
                (float)$result['latitude'],
                (float)$result['longitude']
            );
            if ($distance <= $duplicateDistanceKm) {
                $duplicateIndex = $index;
                break;
            }
        }
        if ($duplicateIndex !== null) {
            $existing = $deduped[$duplicateIndex];
            $detailScore = static fn(array $place): int =>
                ((mb_strtolower(trim((string)$place['address']), 'UTF-8') !== 'davao city') ? 1000 : 0)
                + mb_strlen((string)$place['name'], 'UTF-8');
            if ($detailScore($result) > $detailScore($existing)) $deduped[$duplicateIndex] = $result;
            continue;
        }
        $deduped[] = $result;
    }
    return array_slice($deduped, 0, max(1, min(100, $limit)));
}

function mapBranchMatchesPlace(array $mapPlace, array $place): bool {
    $mapName = trim((string)($mapPlace['name'] ?? ''));
    $placeName = trim((string)($place['name'] ?? ''));
    $mapCore = nearbyPlaceCore($mapName);
    if ($mapCore === '' || $mapCore !== nearbyPlaceCore($placeName)) return false;

    $mapUrl = trim((string)($mapPlace['location_source_url'] ?? $mapPlace['source_url'] ?? ''));
    $placeUrl = trim((string)($place['location_source_url'] ?? $place['source_url'] ?? ''));
    if ($mapUrl !== '' && $mapUrl === $placeUrl) return true;

    $normalize = static fn(string $value): string => mb_strtolower(
        preg_replace('/[^\p{L}\p{N}]+/u', '', $value) ?? '',
        'UTF-8'
    );
    $mapAddress = $normalize(trim((string)($mapPlace['address'] ?? '')));
    $placeAddress = $normalize(trim((string)($place['address'] ?? '')));
    if ($mapAddress !== '' && $mapAddress !== 'davaocity'
        && $placeAddress !== '' && $placeAddress !== 'davaocity'
        && ($mapAddress === $placeAddress
            || str_contains($mapAddress, $placeAddress)
            || str_contains($placeAddress, $mapAddress))) {
        return true;
    }

    $mapBranch = $normalize(extractBranchArea($mapName));
    $placeBranch = $normalize(extractBranchArea($placeName));
    if ($mapBranch !== '' && $placeBranch !== '') {
        return $mapBranch === $placeBranch
            || str_contains($mapBranch, $placeBranch)
            || str_contains($placeBranch, $mapBranch);
    }
    if ($mapBranch !== '' && $placeAddress !== '' && $placeAddress !== 'davaocity') {
        return str_contains($placeAddress, $mapBranch);
    }
    if ($placeBranch !== '' && $mapAddress !== '' && $mapAddress !== 'davaocity') {
        return str_contains($mapAddress, $placeBranch);
    }
    return false;
}

function buildNearbyMapResults(
    array $relevantCandidates,
    array $mapPlaces,
    float $latitude,
    float $longitude,
    float $radiusKm,
    string $query = '',
    array $intent = [],
    float $locationAccuracyKm = 0.0,
    int $limit = 400
): array {
    $relevantCores = [];
    $relevantCandidatesByCore = [];
    foreach ($relevantCandidates as $candidate) {
        $core = nearbyPlaceCore((string)($candidate['name'] ?? ''));
        if ($core === '') continue;
        $score = reviewBasedRankingScore($candidate);
        $relevantCores[$core] = max($relevantCores[$core] ?? 0.0, $score);
        $relevantCandidatesByCore[$core][] = $candidate;
    }

    $results = [];
    foreach ($mapPlaces as $feature) {
        if (!is_array($feature) || !isValidNearbyMapPlace($feature)) continue;
        $core = nearbyPlaceCore((string)$feature['name']);
        if ($core === '') continue;
        $matchesRequest = nearbyFeatureMatchesRequest($feature, $query, $intent);
        if (!$matchesRequest) {
            foreach ($relevantCandidatesByCore[$core] ?? [] as $candidate) {
                if (nearbyFeatureMatchesRequest($candidate, $query, $intent)) {
                    $matchesRequest = true;
                    break;
                }
            }
        }
        if (!$matchesRequest) continue;
        $distance = nearbyDistanceKm($latitude, $longitude, (float)$feature['latitude'], (float)$feature['longitude']);
        if (!nearbyDistanceWithinRadius($distance, $radiusKm, $locationAccuracyKm)) continue;

        $street = trim((string)($feature['street'] ?? ''));
        $address = nearbyFeatureAddress($feature);
        $name = nearbyFeatureDisplayName($feature);
        $url = (string)$feature['url'];
        $foodType = trim((string)($feature['food_type'] ?? 'Restaurant')) ?: 'Restaurant';
        $nearbyStreet = nearbyFeatureInferredStreet($feature);
        $locationDescription = $nearbyStreet !== '' ? "near {$nearbyStreet}" : "at {$address}";
        $isFastFoodChain = !empty($intent['fast_food_items'])
            && fastFoodChainMatchesItems($feature, $intent['fast_food_items']);
        $results[] = [
            'name'=>$name,
            'food_type'=>$foodType,
            'description'=>$isFastFoodChain
                ? "OpenStreetMap lists this fast-food chain {$locationDescription}. Branch-specific menu availability is not verified."
                : "OpenStreetMap lists this {$foodType} {$locationDescription}. Current hours and business status are not verified.",
            'address'=>$address,
            'nearby_street'=>$nearbyStreet,
            'city'=>'Davao City',
            'rating'=>null,
            'review_count'=>null,
            'source_url'=>$url,
            'source_reference'=>$url,
            'source_status'=>'supported',
            'unsupported'=>false,
            'map_only'=>true,
            'fast_food_chain'=>$isFastFoodChain,
            'location_source'=>'OpenStreetMap',
            'location_source_url'=>$url,
            'latitude'=>(float)$feature['latitude'],
            'longitude'=>(float)$feature['longitude'],
            'distance_km'=>round($distance, 2),
            '_nearby_core_key'=>$core,
            '_nearby_sort_score'=>$relevantCores[$core] ?? 0.0,
        ];
    }

    usort($results, fn($a, $b) => ($a['distance_km'] <=> $b['distance_km'])
        ?: ($b['_nearby_sort_score'] <=> $a['_nearby_sort_score']));
    $deduped = [];
    $seenLocations = [];
    foreach ($results as $result) {
        $locationKey = (string)$result['location_source_url'];
        if ($locationKey === '' || isset($seenLocations[$locationKey])) continue;
        $seenLocations[$locationKey] = true;
        unset($result['_nearby_core_key'], $result['_nearby_sort_score']);
        $deduped[] = $result;
        if (count($deduped) >= max(1, min(400, $limit))) break;
    }
    return $deduped;
}
