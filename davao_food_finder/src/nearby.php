<?php

function nearbyPlaceCore(string $name): string {
    $core = preg_replace('/\s*(?:[-–|]\s*[^-–|]+|\([^)]*\))\s*$/u', '', trim($name)) ?? trim($name);
    return mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', $core) ?? '', 'UTF-8');
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

function detectExplicitRestaurantCoreQuery(array $places, string $query): ?string {
    $cleaned = trim(preg_replace(
        '/^(?:please\s+)?(?:i\s+want|show\s+(?:me\s+)?|find\s+|look\s+for\s+|search\s+for\s+|recommend(?:\s+me)?\s+)/iu',
        '',
        trim($query)
    ) ?? trim($query));
    $cleaned = preg_replace(
        '/\s+(?:near|in|around)\s+(?:me|davao(?:\s+city)?|lanang|matina(?:\s+crossing|\s+aplaya)?|obrero|bajada|ecoland|toril|buhangin|poblacion|downtown|agdao|talomo|maa)\b.*$/iu',
        '',
        $cleaned
    ) ?? $cleaned;
    $cleaned = preg_replace('/\b(?:restaurants?|branches?|locations?|stores?)\b/iu', ' ', $cleaned) ?? $cleaned;
    $queryCore = nearbyPlaceCore(trim(preg_replace('/\s+/u', ' ', $cleaned) ?? $cleaned));
    if ($queryCore === '') return null;

    foreach ($places as $place) {
        $core = nearbyPlaceCore((string)($place['name'] ?? ''));
        if ($core !== '' && $core === $queryCore) return $core;
    }
    return null;
}

function filterPlacesByRestaurantCore(array $places, string $core): array {
    return array_values(array_filter($places, fn($place) =>
        is_array($place) && nearbyPlaceCore((string)($place['name'] ?? '')) === $core
    ));
}

function restaurantCoreDisplayName(array $places, string $core): string {
    foreach ($places as $place) {
        $name = trim((string)($place['name'] ?? ''));
        if ($name === '' || nearbyPlaceCore($name) !== $core) continue;
        if (extractBranchArea($name) !== '') {
            return trim(substr($name, 0, (int)strrpos($name, ' - ')));
        }
        return $name;
    }
    return mb_convert_case($core, MB_CASE_TITLE, 'UTF-8');
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

function rankNearbyCandidates(
    array $candidates,
    array $allPlaces,
    array $mapPlaces,
    float $latitude,
    float $longitude,
    float $radiusKm
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
    foreach ($candidates as $candidate) {
        $core = nearbyPlaceCore((string)($candidate['name'] ?? ''));
        $features = $featuresByCore[$core] ?? [];
        $nearest = null;
        foreach ($features as $feature) {
            if (!nearbyFeatureMatchesVenue($candidate, $feature, $venueCoreCounts[$core] ?? 1, count($features))) {
                continue;
            }
            $distance = nearbyDistanceKm($latitude, $longitude, (float)$feature['latitude'], (float)$feature['longitude']);
            if ($distance > $radiusKm || ($nearest !== null && $distance >= $nearest['distance'])) continue;
            $nearest = ['feature'=>$feature, 'distance'=>$distance];
        }
        if ($nearest === null) continue;

        $candidate['_nearby_distance_km'] = $nearest['distance'];
        $candidate['_nearby_source_url'] = (string)$nearest['feature']['url'];
        if (trim((string)($candidate['address'] ?? '')) === '' || mb_strtolower(trim((string)$candidate['address']), 'UTF-8') === 'davao city') {
            $candidate['address'] = trim((string)($nearest['feature']['address'] ?? '')) ?: 'Davao City';
        }
        $nearby[] = $candidate;
    }

    usort($nearby, fn($a, $b) => ($a['_nearby_distance_km'] <=> $b['_nearby_distance_km'])
        ?: (reviewBasedRankingScore($b) <=> reviewBasedRankingScore($a)));
    return $nearby;
}

function rankNearbyWebPlaces(
    array $webPlaces,
    array $mapPlaces,
    array $allPlaces,
    float $latitude,
    float $longitude,
    float $radiusKm
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
    foreach ($webPlaces as $webPlace) {
        if (!is_array($webPlace)) continue;
        $core = nearbyPlaceCore((string)($webPlace['name'] ?? ''));
        $features = $featuresByCore[$core] ?? [];
        $nearest = null;
        foreach ($features as $feature) {
            if (!nearbyFeatureMatchesVenue($webPlace, $feature, $venueCoreCounts[$core] ?? 1, count($features))) {
                continue;
            }
            $distance = nearbyDistanceKm($latitude, $longitude, (float)$feature['latitude'], (float)$feature['longitude']);
            if ($distance > $radiusKm || ($nearest !== null && $distance >= $nearest['distance'])) continue;
            $nearest = ['feature'=>$feature, 'distance'=>$distance];
        }
        if ($nearest === null) continue;

        $webPlace['distance_km'] = round($nearest['distance'], 2);
        $webPlace['location_source_url'] = (string)$nearest['feature']['url'];
        if (trim((string)($webPlace['address'] ?? '')) === '' || mb_strtolower(trim((string)$webPlace['address']), 'UTF-8') === 'davao city') {
            $webPlace['address'] = trim((string)($nearest['feature']['address'] ?? '')) ?: 'Davao City';
        }
        $ranked[] = $webPlace;
    }

    usort($ranked, fn($a, $b) => ($a['distance_km'] <=> $b['distance_km']));
    return $ranked;
}

function buildNearbyMapResults(
    array $relevantCandidates,
    array $mapPlaces,
    float $latitude,
    float $longitude,
    float $radiusKm
): array {
    $relevantCores = [];
    foreach ($relevantCandidates as $candidate) {
        $core = nearbyPlaceCore((string)($candidate['name'] ?? ''));
        if ($core === '') continue;
        $score = reviewBasedRankingScore($candidate);
        $relevantCores[$core] = max($relevantCores[$core] ?? 0.0, $score);
    }

    $results = [];
    foreach ($mapPlaces as $feature) {
        if (!is_array($feature) || !isValidNearbyMapPlace($feature)) continue;
        $core = nearbyPlaceCore((string)$feature['name']);
        if ($core === '' || !isset($relevantCores[$core])) continue;
        $distance = nearbyDistanceKm($latitude, $longitude, (float)$feature['latitude'], (float)$feature['longitude']);
        if ($distance > $radiusKm) continue;

        $address = trim((string)($feature['address'] ?? '')) ?: 'Davao City';
        $url = (string)$feature['url'];
        $foodType = trim((string)($feature['food_type'] ?? 'Restaurant')) ?: 'Restaurant';
        $results[] = [
            'name'=>trim((string)$feature['name']),
            'food_type'=>$foodType,
            'description'=>"OpenStreetMap lists this {$foodType} at {$address}. Current hours and business status are not verified.",
            'address'=>$address,
            'city'=>'Davao City',
            'rating'=>null,
            'review_count'=>null,
            'source_url'=>$url,
            'source_reference'=>$url,
            'source_status'=>'supported',
            'unsupported'=>false,
            'map_only'=>true,
            'location_source'=>'OpenStreetMap',
            'location_source_url'=>$url,
            'distance_km'=>round($distance, 2),
            '_nearby_core_key'=>$core,
            '_nearby_sort_score'=>$relevantCores[$core],
        ];
    }

    usort($results, fn($a, $b) => ($a['distance_km'] <=> $b['distance_km'])
        ?: ($b['_nearby_sort_score'] <=> $a['_nearby_sort_score']));
    $deduped = [];
    $seenCores = [];
    foreach ($results as $result) {
        $core = (string)$result['_nearby_core_key'];
        if (isset($seenCores[$core])) continue;
        $seenCores[$core] = true;
        unset($result['_nearby_core_key'], $result['_nearby_sort_score']);
        $deduped[] = $result;
        if (count($deduped) >= 8) break;
    }
    return $deduped;
}
