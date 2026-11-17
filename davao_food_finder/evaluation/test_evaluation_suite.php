<?php
declare(strict_types=1);

$appRoot = dirname(__DIR__);
require_once $appRoot . '/vendor/autoload.php';
require_once $appRoot . '/src/config.php';
require_once $appRoot . '/src/search.php';
require_once $appRoot . '/src/web_reasoner.php';
require_once $appRoot . '/src/nearby.php';
require_once __DIR__ . '/statistics.php';
require_once __DIR__ . '/metrics.php';
require_once __DIR__ . '/usability.php';

$passed = 0;
$failed = 0;

function assertCheck(bool $condition, string $label): void {
    global $passed, $failed;
    if ($condition) {
        $passed++;
    } else {
        $failed++;
        fwrite(STDERR, "FAIL: {$label}\n");
    }
}

function assertApprox(float $actual, float $expected, float $tol, string $label): void {
    assertCheck(abs($actual - $expected) <= $tol, "{$label} (got {$actual}, expected {$expected})");
}

assertCheck(
    shouldRetryDeepSeekHybridJson(null, 'stop')
    && shouldRetryDeepSeekHybridJson(['answer'=>'partial'], 'length')
    && !shouldRetryDeepSeekHybridJson(['answer'=>'complete'], 'stop'),
    'Retries malformed or length-truncated DeepSeek JSON, but not complete output'
);

// 1. Test Ranking Metrics (P@5, R@5, R@10, NDCG@5, MRR)
$gradeMap = [
    normalizePlaceName('Resto A - Lanang') => 3,
    normalizePlaceName('Resto B - Matina') => 2,
    normalizePlaceName('Resto C - Obrero') => 1,
    normalizePlaceName('Resto D - Bajada') => 2,
];
$baseMap = [
    normalizeBaseRestaurantName('Resto A - Lanang') => 3,
    normalizeBaseRestaurantName('Resto B - Matina') => 2,
];
$sampleResults = [
    ['name'=>'Resto X - Toril', 'city'=>'Davao City'],       // rank 1: non-relevant (0)
    ['name'=>'Resto A - Lanang', 'city'=>'Davao City'],      // rank 2: grade 3 -> RR = 1/2 = 0.5
    ['name'=>'Resto A - Lanang', 'city'=>'Davao City'],      // rank 3: duplicate -> ignored!
    ['name'=>'Resto C - Obrero', 'city'=>'Davao City'],      // rank 4: grade 1
    ['name'=>'Resto Y - Buhangin', 'city'=>'Davao City'],    // rank 5: non-relevant (0)
    ['name'=>'Resto D - Bajada', 'city'=>'Davao City'],      // rank 6: grade 2 (in R@10, not P@5)
];
$rk = computeRankingMetrics($sampleResults, $gradeMap, $baseMap, [], false);
assertApprox($rk['precision_at_5'], 2 / 5, 1e-6, 'Precision@5 with duplicate handling');
assertApprox($rk['recall_at_5'], 2 / 4, 1e-6, 'Recall@5');
assertApprox($rk['recall_at_10'], 3 / 4, 1e-6, 'Recall@10');
assertApprox($rk['mrr'], 0.5, 1e-6, 'MRR first hit at rank 2');
assertCheck($rk['ndcg_at_5'] > 0.3 && $rk['ndcg_at_5'] < 0.8, 'NDCG@5 bounded properly');

// 2. Test Recommendation Validation (duplicates, wrong locations, missing sources, fabricated ratings)
$dsIdx = [
    normalizePlaceName('Resto A - Lanang') => ['name'=>'Resto A - Lanang', 'city'=>'davao city davao del sur', 'rating'=>4.9, 'review_count'=>120, 'price_range'=>''],
];
$badResponse = [
    'answer'=>'Try Resto A or places in Cebu City!',
    'outOfScope'=>false,
    'sources'=>[['title'=>'Verified Blog', 'url'=>'https://example.com/davao-food']],
    'results'=>[
        ['name'=>'Resto A - Lanang', 'city'=>'Davao City', 'rating'=>3.1, 'price_range'=>'₱₱₱'], // altered rating + fabricated price
        ['name'=>'Resto A - Lanang', 'city'=>'Davao City', 'rating'=>4.9, 'price_range'=>''],    // duplicate
    ],
    'webResults'=>[
        ['name'=>'Outside Spot', 'city'=>'Cebu City', 'source_url'=>''],                         // wrong location + missing source
        ['name'=>'Unverified Link Cafe', 'city'=>'Davao City', 'source_url'=>'https://fake.org/not-in-sources', 'rating'=>'6.5'], // unverified source + impossible rating
    ],
];
$val = validateRecommendationQuality($badResponse, ['relevance_grades'=>$gradeMap, 'base_relevance_grades'=>$baseMap, 'constraints'=>[]], $dsIdx);
assertCheck($val['duplicate_count'] >= 1, 'Detects duplicate local results');
assertCheck($val['wrong_location_count'] >= 1, 'Detects wrong city locations');
assertCheck($val['missing_source_count'] >= 1, 'Detects missing web sources');
assertCheck($val['fabricated_detail_count'] >= 2, 'Detects altered local rating / fabricated price / unverified URL');
assertCheck($val['unsupported_claim_count'] >= 2, 'Detects unsupported claims');

$nearbyKfcPlaces = [
    ['_id'=>'kfc-lanang', 'name'=>'KFC - Lanang', 'address'=>'Davao City', 'city'=>'Davao City', 'rating'=>4.4, 'review_count'=>500],
    ['_id'=>'kfc-quirino', 'name'=>'KFC - Quirino Davao', 'address'=>'Davao City', 'city'=>'Davao City', 'rating'=>4.2, 'review_count'=>400],
    ['_id'=>'toril-burgers', 'name'=>'Backyard Burgers - Toril', 'address'=>'Davao City', 'city'=>'Davao City', 'rating'=>4.8, 'review_count'=>100],
];
$nearbyMapPlaces = [
    ['name'=>'KFC', 'city'=>'Davao City', 'district'=>'Lanang', 'street'=>'Damosa Road', 'address'=>'Damosa Road, Lanang, Davao City', 'latitude'=>7.105, 'longitude'=>125.635, 'url'=>'https://www.openstreetmap.org/node/101'],
    ['name'=>'KFC', 'city'=>'Davao City', 'district'=>'Bajada', 'street'=>'J.P. Laurel Avenue', 'latitude'=>7.107, 'longitude'=>125.636, 'url'=>'https://www.openstreetmap.org/node/103'],
    ['name'=>'Backyard Burgers', 'city'=>'Davao City', 'district'=>'Toril', 'latitude'=>7.006, 'longitude'=>125.501, 'url'=>'https://www.openstreetmap.org/node/102'],
];
$rankedNearbyPlaces = rankNearbyCandidates($nearbyKfcPlaces, $nearbyKfcPlaces, $nearbyMapPlaces, 7.105, 125.635, 5.0);
assertCheck(count($rankedNearbyPlaces) === 1 && $rankedNearbyPlaces[0]['_id'] === 'kfc-lanang', 'Near-me search matches the right chain branch and excludes distant map points');
$nearbyMapResults = buildNearbyMapResults($nearbyKfcPlaces, $nearbyMapPlaces, 7.105, 125.635, 5.0);
assertCheck(count($nearbyMapResults) === 2
    && count(array_unique(array_column($nearbyMapResults, 'location_source_url'))) === 2
    && $nearbyMapResults[0]['name'] === 'KFC - Lanang (Damosa Road)'
    && $nearbyMapResults[1]['name'] === 'KFC - Bajada (J.P. Laurel Avenue)'
    && str_contains($nearbyMapResults[0]['address'], 'Damosa Road')
    && $nearbyMapResults[0]['latitude'] === 7.105
    && $nearbyMapResults[0]['longitude'] === 125.635
    && $nearbyMapResults[0]['rating'] === null,
    'Near-me results identify distinct chain branches by their OSM street and district without borrowing ratings');
$branchOnlyMapResult = buildNearbyMapResults(
    [],
    [[
        'name'=>'KFC',
        'branch'=>'SM Lanang Premier',
        'city'=>'Davao City',
        'district'=>'Lanang',
        'address'=>'Lanang, Davao City',
        'latitude'=>7.105,
        'longitude'=>125.635,
        'url'=>'https://www.openstreetmap.org/node/104',
    ]],
    7.105,
    125.635,
    5.0
);
assertCheck(
    count($branchOnlyMapResult) === 1
    && $branchOnlyMapResult[0]['name'] === 'KFC - SM Lanang Premier'
    && str_contains($branchOnlyMapResult[0]['address'], 'SM Lanang Premier'),
    'Near-me results use the OSM branch name when no street is available'
);
$branchOnlyLocalResult = rankNearbyCandidates(
    [['name'=>'KFC - SM Lanang Premier', 'address'=>'Davao City', 'rating'=>4.4]],
    [['name'=>'KFC - SM Lanang Premier', 'address'=>'Davao City', 'rating'=>4.4]],
    [[
        'name'=>'KFC',
        'branch'=>'SM Lanang Premier',
        'city'=>'Davao City',
        'address'=>'Davao City',
        'nearby_street'=>'Damosa Road',
        'latitude'=>7.105,
        'longitude'=>125.635,
        'url'=>'https://www.openstreetmap.org/node/105',
    ]],
    7.105,
    125.635,
    5.0
);
assertCheck(
    count($branchOnlyLocalResult) === 1
    && $branchOnlyLocalResult[0]['name'] === 'KFC - SM Lanang Premier (Damosa Road)'
    && str_contains($branchOnlyLocalResult[0]['address'], 'SM Lanang Premier')
    && $branchOnlyLocalResult[0]['nearby_street'] === 'Damosa Road',
    'Near-me local results retain the OSM branch and append the inferred street'
);
$nearbyStreetMapResult = buildNearbyMapResults(
    [],
    [[
        'name'=>'Jollibee',
        'city'=>'Davao City',
        'address'=>'Davao City',
        'nearby_street'=>'Carlos P. Garcia Highway',
        'latitude'=>7.1151129,
        'longitude'=>125.6242913,
        'url'=>'https://www.openstreetmap.org/way/958361470',
    ]],
    7.1151129,
    125.6242913,
    5.0,
    'Jollibee'
);
assertCheck(
    count($nearbyStreetMapResult) === 1
    && $nearbyStreetMapResult[0]['name'] === 'Jollibee - (Carlos P. Garcia Highway)'
    && $nearbyStreetMapResult[0]['nearby_street'] === 'Carlos P. Garcia Highway'
    && str_contains($nearbyStreetMapResult[0]['description'], 'near Carlos P. Garcia Highway'),
    'Near-me results append the nearest named OSM road when no street or branch is listed'
);
$branchAndStreetMapResult = buildNearbyMapResults(
    [],
    [[
        'name'=>'KFC',
        'branch'=>'SM Lanang Premier',
        'city'=>'Davao City',
        'address'=>'SM Lanang Premier, Davao City',
        'nearby_street'=>'Damosa Road',
        'latitude'=>7.105,
        'longitude'=>125.635,
        'url'=>'https://www.openstreetmap.org/node/106',
    ]],
    7.105,
    125.635,
    5.0,
    'KFC'
);
assertCheck(
    count($branchAndStreetMapResult) === 1
    && $branchAndStreetMapResult[0]['name'] === 'KFC - SM Lanang Premier (Damosa Road)',
    'Near-me names retain the OSM branch and append its nearby street'
);
$manyNearbyMapPlaces = [];
for ($i = 1; $i <= 25; $i++) {
    $manyNearbyMapPlaces[] = [
        'name'=>"Sample Cafe {$i}",
        'city'=>'Davao City',
        'food_type'=>'cafe',
        'latitude'=>7.105 + ($i / 100000),
        'longitude'=>125.635,
        'url'=>"https://www.openstreetmap.org/node/{$i}",
    ];
}
$manyNearbyMapResults = buildNearbyMapResults(
    [],
    $manyNearbyMapPlaces,
    7.105,
    125.635,
    5.0,
    'coffee shops',
    ['cuisines'=>['Coffee'], 'specific_dishes'=>[]]
);
$limitedNearbyMapResults = buildNearbyMapResults(
    [],
    $manyNearbyMapPlaces,
    7.105,
    125.635,
    5.0,
    'coffee shops',
    ['cuisines'=>['Coffee'], 'specific_dishes'=>[]],
    0.0,
    20
);
assertCheck(
    count($manyNearbyMapResults) === 25
    && count($limitedNearbyMapResults) === 20
    && count(array_unique(array_column($manyNearbyMapResults, 'location_source_url'))) === 25,
    'Nearby map matching supports more than 20 distinct places while honoring the requested page limit'
);
$starbucksMapPlaces = [
    ['name'=>'Starbucks', 'city'=>'Davao City', 'food_type'=>'cafe', 'district'=>'Poblacion', 'street'=>'J.P. Laurel Avenue', 'address'=>'J.P. Laurel Avenue, Poblacion, Davao City', 'latitude'=>7.105, 'longitude'=>125.635, 'url'=>'https://www.openstreetmap.org/node/201'],
];
$coffeeMapResults = buildNearbyMapResults(
    [],
    $starbucksMapPlaces,
    7.105,
    125.635,
    5.0,
    'coffee shops',
    ['cuisines'=>['Coffee'], 'specific_dishes'=>[]]
);
assertCheck(
    count($coffeeMapResults) === 1
    && str_starts_with($coffeeMapResults[0]['name'], 'Starbucks')
    && str_contains($coffeeMapResults[0]['name'], 'Poblacion')
    && str_contains($coffeeMapResults[0]['address'], 'J.P. Laurel Avenue'),
    'Coffee map results include online-only Starbucks locations with a branch label and mapped address'
);
$coffeeAfterBurgerIntent = parseQueryIntent('coffeeshop', [
    ['role'=>'user', 'content'=>'burger'],
    ['role'=>'assistant', 'content'=>'Here are some nearby burger places.'],
]);
$coffeeAfterBurgerResults = buildNearbyMapResults(
    [],
    [
        ['name'=>'Local Coffee House', 'city'=>'Davao City', 'food_type'=>'cafe', 'latitude'=>7.105, 'longitude'=>125.635, 'url'=>'https://www.openstreetmap.org/node/211'],
        ['name'=>'Angel Burger', 'city'=>'Davao City', 'food_type'=>'fast_food', 'latitude'=>7.106, 'longitude'=>125.636, 'url'=>'https://www.openstreetmap.org/node/212'],
    ],
    7.105,
    125.635,
    5.0,
    'coffeeshop',
    $coffeeAfterBurgerIntent
);
assertCheck(
    $coffeeAfterBurgerIntent['cuisines'] === ['Coffee']
    && !str_contains(mb_strtolower($coffeeAfterBurgerIntent['effective_query'], 'UTF-8'), 'burger')
    && count($coffeeAfterBurgerResults) === 1
    && $coffeeAfterBurgerResults[0]['name'] === 'Local Coffee House',
    'A coffeeshop query pivots away from prior burger context and excludes fast-food map results'
);
$progressiveHistory = [
    ['role'=>'user', 'content'=>'burger'],
    ['role'=>'assistant', 'content'=>'Here are some nearby burger places.'],
    ['role'=>'user', 'content'=>'coffeeshop'],
    ['role'=>'assistant', 'content'=>'Here are some nearby coffee places.'],
];
$friesAfterProgressiveHistory = parseQueryIntent('fries', $progressiveHistory);
$halalAfterProgressiveHistory = parseQueryIntent('halal', array_merge($progressiveHistory, [
    ['role'=>'user', 'content'=>'fries'],
    ['role'=>'assistant', 'content'=>'Here are places nearby.'],
]));
$friesFollowupAfterBurger = parseQueryIntent('what about fries?', $progressiveHistory);
$halalFollowupAfterFries = parseQueryIntent('what about halal?', array_merge($progressiveHistory, [
    ['role'=>'user', 'content'=>'fries'],
    ['role'=>'assistant', 'content'=>'Here are fries places.'],
]));
assertCheck(
    !$friesAfterProgressiveHistory['is_followup']
    && $friesAfterProgressiveHistory['cuisines'] === []
    && $friesAfterProgressiveHistory['specific_dishes'] === ['fries']
    && !$halalAfterProgressiveHistory['is_followup']
    && $halalAfterProgressiveHistory['cuisines'] === []
    && $halalAfterProgressiveHistory['specific_dishes'] === []
    && $halalAfterProgressiveHistory['dietary'] === ['halal'],
    'Standalone dish and dietary searches start fresh after unrelated searches'
);
assertCheck(
    $friesFollowupAfterBurger['is_followup']
    && $friesFollowupAfterBurger['cuisines'] === []
    && $friesFollowupAfterBurger['specific_dishes'] === ['fries']
    && $halalFollowupAfterFries['is_followup']
    && $halalFollowupAfterFries['specific_dishes'] === ['fries']
    && $halalFollowupAfterFries['dietary'] === ['halal'],
    'Explicit follow-ups retain relevant context while a new dish pivots away from the previous cuisine'
);
$fastFoodMapPlaces = [
    ['name'=>'Quick Bites', 'city'=>'Davao City', 'food_type'=>'fast_food', 'district'=>'Poblacion', 'street'=>'Roxas Avenue', 'latitude'=>7.105, 'longitude'=>125.635, 'url'=>'https://www.openstreetmap.org/node/202'],
];
$explicitBurgerMapPlaces = [
    $fastFoodMapPlaces[0],
    ['name'=>'Burger King - Davao', 'city'=>'Davao City', 'food_type'=>'fast_food', 'latitude'=>7.106, 'longitude'=>125.636, 'url'=>'https://www.openstreetmap.org/node/203'],
];
$burgerIntent = parseQueryIntent('burger');
$chickenIntent = parseQueryIntent('chicken');
$chickenPluralIntent = parseQueryIntent('chickens');
$friesIntent = parseQueryIntent('fries');
$sundaeIntent = parseQueryIntent('sundae');
$mixedBurgerSushiIntent = parseQueryIntent('burgers and sushi');
$burgerSteakIntent = parseQueryIntent('burgersteak');
$filipinoBurgerSteakIntent = parseQueryIntent('Filipino burger steak');
$mixedFriesBurgerIntent = parseQueryIntent('fries and burgers');
$chickenBiryaniIntent = parseQueryIntent('chicken biryani');
$dateNightIntent = parseQueryIntent('date night restaurants');
$quietCafeIntent = parseQueryIntent('quiet cafes to study');
$localFavoritesIntent = parseQueryIntent('local favorites in Davao');
$dateNightAlternativePlan = nearbyExperienceAlternativePlan('date night restaurants', $dateNightIntent);
$quietCafeAlternativePlan = nearbyExperienceAlternativePlan('quiet cafes to study', $quietCafeIntent);
$localFavoritesAlternativePlan = nearbyExperienceAlternativePlan('local favorites in Davao', $localFavoritesIntent);
$nearbyAlternativeLimits = [
    nearbyAlternativeResultLimit(0),
    nearbyAlternativeResultLimit(1),
    nearbyAlternativeResultLimit(3),
    nearbyAlternativeResultLimit(4),
    nearbyAlternativeResultLimit(5),
];
$dateNightArticleUrl = 'https://example.com/davao-date-night';
$dateNightEvidence = ['results'=>[[
    'url'=>$dateNightArticleUrl,
    'title'=>'Best Romantic Restaurants in Davao for the Perfect Date Night',
    'text'=>'The White House Fusion Cuisine & Wine Lounge - An intimate heritage-home dining experience for couples. Jacks Ridge offers scenic city views.',
]]];
$jollibeeReviewEvidence = ['results'=>[[
    'url'=>'https://www.tripadvisor.com/Restaurant_Review-jollibee',
    'title'=>'Jollibee in Davao City',
    'text'=>'Customers say Jollibee serves Palabok Fiesta.',
]]];
$whiteHouseOnlinePlace = [
    'name'=>'The White House Fusion Cuisine & Wine Lounge',
    'source_url'=>$dateNightArticleUrl,
];
$burgerSteakNearbyResults = buildNearbyMapResults(
    [],
    [
        ['name'=>'J&K Yogopop - Buhangin', 'city'=>'Davao City', 'food_type'=>'fast_food', 'latitude'=>7.105, 'longitude'=>125.635, 'url'=>'https://www.openstreetmap.org/node/218'],
        ['name'=>'Pickup Coffee', 'city'=>'Davao City', 'food_type'=>'cafe', 'latitude'=>7.1051, 'longitude'=>125.6351, 'url'=>'https://www.openstreetmap.org/node/219'],
        ['name'=>'Burger Steak House', 'city'=>'Davao City', 'food_type'=>'restaurant', 'description'=>'Serves burger steak', 'latitude'=>7.106, 'longitude'=>125.636, 'url'=>'https://www.openstreetmap.org/node/220'],
    ],
    7.105,
    125.635,
    5.0,
    'burgersteak',
    $burgerSteakIntent
);
assertCheck(
    $burgerSteakIntent['specific_dishes'] === ['burger steak']
    && $burgerSteakIntent['strict_dishes'] === ['burger steak']
    && $burgerSteakIntent['cuisines'] === []
    && $burgerSteakIntent['effective_query'] === parseQueryIntent('burger steak')['effective_query']
    && $filipinoBurgerSteakIntent['strict_dishes'] === ['burger steak']
    && !in_array('Burgers', $filipinoBurgerSteakIntent['cuisines'], true)
    && !in_array('BBQ', $filipinoBurgerSteakIntent['cuisines'], true)
    && in_array('Burgers', parseQueryIntent('burger places')['cuisines'], true)
    && count($burgerSteakNearbyResults) === 1
    && $burgerSteakNearbyResults[0]['name'] === 'Burger Steak House'
    && nearbyBroadSearchSuggestions($burgerSteakIntent) === ['Filipino restaurants', 'burger places']
    && nearbyBroadSearchSuggestions($burgerIntent) === [],
    'Burger-steak searches require dish evidence and offer broader options only for a specific dish'
);
assertCheck(
    $dateNightIntent['thematic'] === ['date_romantic']
    && onlinePlaceHasIntentEvidence($whiteHouseOnlinePlace, $dateNightEvidence, $dateNightIntent)
    && !onlinePlaceHasIntentEvidence(
        ['name'=>'J&K Yogopop - Buhangin District', 'source_url'=>$dateNightArticleUrl],
        $dateNightEvidence,
        $dateNightIntent
    )
    && nearbyPlaceHasIntentEvidence(['_review_signals'=>['date_romantic'=>1]], $dateNightIntent)
    && !nearbyPlaceHasIntentEvidence(['name'=>'J&K Yogopop', 'food_type'=>'Fast food'], $dateNightIntent)
    && !nearbyFeatureMatchesRequest(
        ['name'=>'Nearby Generic Eatery', 'food_type'=>'restaurant'],
        'date night restaurants',
        $dateNightIntent
    ),
    'Date-night nearby recommendations require positive venue-level review or linked-source evidence'
);
assertCheck(
    $dateNightAlternativePlan !== null
    && $dateNightAlternativePlan['intent']['thematic'] === []
    && $dateNightAlternativePlan['intent']['nearby_venue_type'] === 'restaurant'
    && $quietCafeAlternativePlan !== null
    && in_array('Coffee', $quietCafeAlternativePlan['intent']['cuisines'], true)
    && $quietCafeAlternativePlan['intent']['ambiance'] === []
    && $localFavoritesAlternativePlan !== null
    && in_array('Filipino', $localFavoritesAlternativePlan['intent']['cuisines'], true)
    && nearbyExperienceAlternativePlan('burger steak date night', $burgerSteakIntent + ['thematic'=>['date_romantic']]) === null
    && nearbyExperienceAlternativePlan(
        'date night restaurants under PHP 500',
        array_merge($dateNightIntent, ['max_price_php'=>500])
    ) === null,
    'Nearby fallback removes only soft experience constraints and preserves cuisine or strict dish requirements'
);
assertCheck(
    $nearbyAlternativeLimits === [20, 4, 2, 1, 0],
    'Nearby alternatives fill only the shortfall to five while preserving the zero-match fallback'
);
assertCheck(
    nearbyFeatureMatchesRequest(
        ['name'=>'Nearby Cafe', 'food_type'=>'cafe'],
        $quietCafeAlternativePlan['query'],
        $quietCafeAlternativePlan['intent']
    )
    && nearbyFeatureMatchesRequest(
        ['name'=>'Nearby Restaurant', 'food_type'=>'restaurant'],
        $dateNightAlternativePlan['query'],
        $dateNightAlternativePlan['intent']
    )
    && !nearbyFeatureMatchesRequest(
        ['name'=>'Generic Fast Food', 'food_type'=>'fast_food'],
        $dateNightAlternativePlan['query'],
        $dateNightAlternativePlan['intent']
    ),
    'Nearby alternatives retain the requested cafe or restaurant category and exclude unrelated map types'
);
$nearbyCafeAlternativeRanked = rankNearbyCandidates(
    [
        ['_id'=>'coffee-place', 'name'=>'Nearby Cafe', 'food_type'=>'Coffee', 'address'=>'Davao City'],
        ['_id'=>'restaurant-place', 'name'=>'Nearby Restaurant', 'food_type'=>'Restaurant', 'address'=>'Davao City'],
    ],
    [
        ['_id'=>'coffee-place', 'name'=>'Nearby Cafe', 'food_type'=>'Coffee', 'address'=>'Davao City'],
        ['_id'=>'restaurant-place', 'name'=>'Nearby Restaurant', 'food_type'=>'Restaurant', 'address'=>'Davao City'],
    ],
    [
        ['name'=>'Nearby Cafe', 'city'=>'Davao City', 'food_type'=>'cafe', 'latitude'=>7.105, 'longitude'=>125.635, 'url'=>'https://www.openstreetmap.org/node/910'],
        ['name'=>'Nearby Restaurant', 'city'=>'Davao City', 'food_type'=>'restaurant', 'latitude'=>7.105, 'longitude'=>125.635, 'url'=>'https://www.openstreetmap.org/node/911'],
    ],
    7.105,
    125.635,
    3.0,
    0.0,
    true,
    $quietCafeAlternativePlan['query'],
    $quietCafeAlternativePlan['intent']
);
assertCheck(
    count($nearbyCafeAlternativeRanked) === 1
    && $nearbyCafeAlternativeRanked[0]['_id'] === 'coffee-place',
    'Nearby fallback maps only matching venue categories and never relaxes the location radius'
);
assertCheck(
    buildNearbyMapResults(
        [],
        [['name'=>'Nearby Generic Eatery', 'city'=>'Davao City', 'food_type'=>'restaurant', 'latitude'=>7.105, 'longitude'=>125.635, 'url'=>'https://www.openstreetmap.org/node/900']],
        7.105,
        125.635,
        3.0,
        'date night restaurants',
        $dateNightIntent
    ) === [],
    'Generic OSM proximity matches are not added as date-night recommendations'
);
$unverifiedBurgerSteakResult = [
    'name'=>'Backyard Burgers',
    'food_type'=>'Burgers',
    'description'=>'Burger-focused Davao venue; burger steak availability not explicitly verified.',
];
assertCheck(
    !placeMatchesSpecificDishes($unverifiedBurgerSteakResult, ['burger steak'])
    && !placeMatchesSpecificDishes([
        'name'=>'Burger Chef',
        'food_type'=>'Burgers',
        'description'=>'This restaurant might serve burger steak, but its menu has not been confirmed.',
    ], ['burger steak'])
    && placeMatchesSpecificDishes([
        'name'=>'Burger Steak House',
        'description'=>'Serves burger steak daily.',
    ], ['burger steak']),
    'Generic burger venues with unverified burger-steak mentions are not treated as dish matches'
);
$billyjeansPlace = ['name'=>'BillyJean\'s Bistro & Restaurant', 'source_url'=>'https://example.com/billyjeans'];
$billyjeansCategoryEvidence = ['results'=>[[
    'url'=>'https://example.com/billyjeans',
    'title'=>'Steak, Mexican, Thai, Burgers, & More | BillyJean\'s Bistro & Restaurant',
    'text'=>'Steak, Mexican, Thai, Burgers, & More in Davao City.',
]]];
$billyjeansMenuEvidence = ['results'=>[[
    'url'=>'https://example.com/billyjeans',
    'title'=>'BillyJean\'s Bistro & Restaurant menu',
    'text'=>'BillyJean\'s Bistro & Restaurant menu includes burger steak for PHP 150.',
]]];
assertCheck(
    !onlinePlaceHasSpecificDishEvidence($billyjeansPlace, $billyjeansCategoryEvidence, ['burger steak'])
    && onlinePlaceHasSpecificDishEvidence($billyjeansPlace, $billyjeansMenuEvidence, ['burger steak']),
    'A venue category page is not burger-steak evidence, while a venue-linked menu item is'
);
$palabokIntent = parseQueryIntent('palabok');
$expandedPalabokQuery = expandMenuItemSearchQuery('palabok Davao City', $palabokIntent);
$jollibeePalabokPlace = [
    'name'=>'Jollibee',
    'source_url'=>'https://example.com/menu/palabok-fiesta',
];
$jollibeePalabokMenuEvidence = ['results'=>[[
    'url'=>'https://example.com/menu/palabok-fiesta',
    'title'=>'Palabok Fiesta - Jollibee',
    'text'=>'Jollibee Palabok Fiesta in Davao City.',
]]];
$jollibeeCategoryEvidence = ['results'=>[[
    'url'=>'https://example.com/menu/palabok-fiesta',
    'title'=>'Jollibee menu in Davao City',
    'text'=>'Jollibee offers burgers and fried chicken in Davao City.',
]]];
$razonsListingPlace = [
    'name'=>'The Original Razon\'s Pancit Palabok and Halo-halo',
    'source_url'=>'https://restaurantguru.com/The-Original-Razons-Pancit-Palabok-and-Halo-halo-Davao-City',
];
$razonsListingEvidence = ['results'=>[[
    'url'=>'https://restaurantguru.com/The-Original-Razons-Pancit-Palabok-and-Halo-halo-Davao-City',
    'title'=>'The Original Razon\'s Pancit Palabok and Halo-halo, Davao City - Restaurant menu, prices and reviews',
    'text'=>'Tucked away in Davao City, The Original Razon\'s Pancit Palabok and Halo-halo offers traditional Filipino cuisine.',
]]];
$razonsSearchSnippetEvidence = ['results'=>[[
    'url'=>'https://restaurantguru.com/The-Original-Razons-Pancit-Palabok-and-Halo-halo-Davao-City',
    'title'=>'The Original Razon\'s Pancit Palabok and Halo-halo, Davao City - Restaurant menu, prices and reviews',
    'text'=>'The Original Razon\'s Pancit Palabok and Halo-halo in Davao City.',
    'source_access'=>'search_engine_snippet',
]]];
$pansitSaBilaoMapPlace = [
    'name'=>'Pansit Sa Bilao',
    'source_url'=>'https://www.google.com/maps/place/Pansit+Sa+Bilao',
];
$pansitSaBilaoMapEvidence = ['results'=>[[
    'url'=>'https://www.google.com/maps/place/Pansit+Sa+Bilao',
    'title'=>'Pansit Sa Bilao, Davao City',
    'text'=>'Menu highlight: palabok. Davao City.',
]]];
assertCheck(
    $palabokIntent['specific_dishes'] === ['palabok']
    && $palabokIntent['strict_dishes'] === ['palabok']
    && $expandedPalabokQuery === 'palabok Davao City'
    && !str_contains($expandedPalabokQuery, 'Jollibee')
    && !str_contains($expandedPalabokQuery, 'Mang Inasal')
    && extractSpecificDishes('Pancit Palabok Fiesta') === ['palabok']
    && !in_array('Pizza', $palabokIntent['cuisines'], true)
    && in_array('Filipino', $palabokIntent['cuisines'], true)
    && !placeMatchesSpecificDishes([
        'name'=>'Shakey\'s Pizza - SM Lanang',
        'food_type'=>'Pizza',
        'description'=>'Pizza and pasta.',
    ], ['palabok'])
    && !placeMatchesSpecificDishes([
        'name'=>'Jollibee',
        'food_type'=>'Fast Food',
        'description'=>'Palabok availability is not verified.',
    ], ['palabok'])
    && !nearbyFeatureMatchesRequest([
        'name'=>'Jollibee - Lanang',
        'food_type'=>'fast_food',
    ], 'palabok', $palabokIntent)
    && placeMatchesSpecificDishes([
        'name'=>'Jollibee',
        'food_type'=>'Fast Food',
        'description'=>'Menu offers Palabok Fiesta.',
    ], ['palabok']),
    'Palabok is a strict Filipino dish query, not a pizza-category match'
);
assertCheck(
    !onlinePlaceHasSpecificDishEvidence($jollibeePalabokPlace, $jollibeeCategoryEvidence, ['palabok'])
    && onlinePlaceHasSpecificDishEvidence($jollibeePalabokPlace, $jollibeePalabokMenuEvidence, ['palabok'])
    && !onlinePlaceHasSpecificDishEvidence($jollibeePalabokPlace, $jollibeeReviewEvidence, ['palabok'])
    && onlinePlaceSpecificDishEvidenceStatus($razonsListingPlace, $razonsListingEvidence, ['palabok']) === 'dish_mention'
    && onlinePlaceSpecificDishEvidenceStatus($razonsListingPlace, $razonsSearchSnippetEvidence, ['palabok']) === 'search_result_mention'
    && onlinePlaceSpecificDishEvidenceStatus($pansitSaBilaoMapPlace, $pansitSaBilaoMapEvidence, ['palabok']) === 'listing_mention'
    && onlinePlaceSpecificDishEvidenceStatus($jollibeePalabokPlace, $jollibeePalabokMenuEvidence, ['palabok']) === 'menu_source'
    && !onlinePlaceHasSpecificDishEvidence([
        'name'=>'Cecil\'s Snack Inn and Bakeshop',
        'source_url'=>'https://www.tripadvisor.com.ph/ShowUserReviews-g298459-d2012509-Cecil_s_Snack_Inn_and_Bakeshop-Davao_City.html',
    ], ['results'=>[[
        'url'=>'https://www.tripadvisor.com.ph/ShowUserReviews-g298459-d2012509-Cecil_s_Snack_Inn_and_Bakeshop-Davao_City.html',
        'title'=>'Best pancit palabok in Davao! - Review of Cecil\'s Snack Inn and Bakeshop',
        'text'=>'Customer review mentions pancit palabok.',
        'source_access'=>'search_engine_snippet',
    ]]], ['palabok'])
    && !onlinePlaceHasSpecificDishEvidence([
        'name'=>'Cecil\'s Snack Inn and Bakeshop',
        'source_url'=>'https://example.com/jollibee-menu',
    ], $jollibeePalabokMenuEvidence, ['palabok']),
    'Online palabok evidence is venue-linked and classified by source strength'
);
assertCheck(
    isLikelyRestaurantArticleTitle('Best of Davao 2020: Pancit Palabok'),
    'Dish-focused news headlines are not shown as restaurant venues'
);
$chickenBiryaniLocalResults = shortlistPlaces(
    [
        ['_id'=>'chicken-only', 'name'=>'Chicken House', 'food_type'=>'Chicken', 'description'=>'Fried chicken menu', 'address'=>'Davao City', 'rating'=>4.5, 'review_count'=>20],
        ['_id'=>'chicken-biryani', 'name'=>'Biryani House', 'food_type'=>'Indian', 'description'=>'Chicken biryani menu', 'address'=>'Davao City', 'rating'=>4.6, 'review_count'=>30],
    ],
    'chicken biryani',
    10,
    $chickenBiryaniIntent
);
$chickenBiryaniNearbyResults = buildNearbyMapResults(
    [],
    [
        ['name'=>'Chicken House', 'city'=>'Davao City', 'food_type'=>'chicken', 'latitude'=>7.105, 'longitude'=>125.635, 'url'=>'https://www.openstreetmap.org/node/216'],
        ['name'=>'Biryani House', 'city'=>'Davao City', 'food_type'=>'Indian', 'description'=>'Chicken biryani', 'latitude'=>7.106, 'longitude'=>125.636, 'url'=>'https://www.openstreetmap.org/node/217'],
    ],
    7.105,
    125.635,
    5.0,
    'chicken biryani',
    $chickenBiryaniIntent
);
$fastFoodResults = [];
foreach ([
    ['burger', $burgerIntent],
    ['chicken', $chickenIntent],
    ['fries', $friesIntent],
] as [$query, $intent]) {
    $fastFoodResults[$query] = buildNearbyMapResults([], $fastFoodMapPlaces, 7.105, 125.635, 5.0, $query, $intent);
}
$chainMapPlaces = [
    ['name'=>"McDonald's - Bajada", 'city'=>'Davao City', 'food_type'=>'fast_food', 'latitude'=>7.105, 'longitude'=>125.635, 'url'=>'https://www.openstreetmap.org/node/301'],
    ['name'=>'KFC - Buhangin', 'city'=>'Davao City', 'food_type'=>'fast_food', 'latitude'=>7.105, 'longitude'=>125.635, 'url'=>'https://www.openstreetmap.org/node/302'],
    ['name'=>'Jollibee - Lanang', 'city'=>'Davao City', 'food_type'=>'fast_food', 'latitude'=>7.105, 'longitude'=>125.635, 'url'=>'https://www.openstreetmap.org/node/303'],
    ['name'=>'Burger King - Matina', 'city'=>'Davao City', 'food_type'=>'fast_food', 'latitude'=>7.105, 'longitude'=>125.635, 'url'=>'https://www.openstreetmap.org/node/304'],
    ['name'=>'Minute Burger - Obrero', 'city'=>'Davao City', 'food_type'=>'fast_food', 'latitude'=>7.105, 'longitude'=>125.635, 'url'=>'https://www.openstreetmap.org/node/305'],
    ['name'=>'Mang Inasal - Ecoland', 'city'=>'Davao City', 'food_type'=>'fast_food', 'latitude'=>7.105, 'longitude'=>125.635, 'url'=>'https://www.openstreetmap.org/node/306'],
    ['name'=>'Burger Stop', 'city'=>'Davao City', 'food_type'=>'fast_food', 'cuisine'=>'burger', 'latitude'=>7.105, 'longitude'=>125.635, 'url'=>'https://www.openstreetmap.org/node/307'],
];
$chainNearbyResults = [];
foreach ([
    ['burger', $burgerIntent],
    ['chicken', $chickenIntent],
    ['fries', $friesIntent],
    ['sundae', $sundaeIntent],
] as [$query, $intent]) {
    $chainNearbyResults[$query] = buildNearbyMapResults([], $chainMapPlaces, 7.105, 125.635, 5.0, $query, $intent);
}
$explicitBurgerResults = buildNearbyMapResults(
    [],
    $explicitBurgerMapPlaces,
    7.105,
    125.635,
    5.0,
    'burger',
    $burgerIntent
);
assertCheck(
    $friesIntent['specific_dishes'] === ['fries']
    && $friesIntent['strict_dishes'] === []
    && $friesIntent['fast_food_items'] === ['fries']
    && $sundaeIntent['fast_food_items'] === ['sundaes']
    && $burgerIntent['fast_food_items'] === ['burgers']
    && $chickenIntent['fast_food_items'] === ['chicken']
    && $chickenPluralIntent['fast_food_items'] === ['chicken']
    && $burgerSteakIntent['fast_food_items'] === []
    && $chickenBiryaniIntent['fast_food_items'] === []
    && $mixedBurgerSushiIntent['fast_food_items'] === []
    && nearbyCategoryQueryTerms($burgerIntent) === ['fast food']
    && nearbyCategoryQueryTerms($chickenIntent) === ['fast food']
    && nearbyCategoryQueryTerms($friesIntent) === ['fast food']
    && count($fastFoodResults['burger']) === 0
    && count($fastFoodResults['chicken']) === 0
    && count($fastFoodResults['fries']) === 0
    && count($explicitBurgerResults) === 1
    && $explicitBurgerResults[0]['name'] === 'Burger King - Davao'
    && array_column($chainNearbyResults['burger'], 'name') === [
        "McDonald's - Bajada", 'Jollibee - Lanang', 'Burger King - Matina', 'Minute Burger - Obrero', 'Burger Stop'
    ]
    && !fastFoodChainMatchesItems(['name'=>'Minute Burger - Obrero'], ['burgers'])
    && !fastFoodChainMatchesItems(['name'=>'Minute Burger - Obrero'], ['fries'])
    && nearbyFeatureMatchesRequest(['name'=>'Minute Burger - Obrero', 'food_type'=>'fast_food'], 'burger', $burgerIntent)
    && !nearbyFeatureMatchesRequest(['name'=>'Minute Burger - Obrero', 'food_type'=>'fast_food'], 'fries', $friesIntent)
    && array_column($chainNearbyResults['chicken'], 'name') === [
        "McDonald's - Bajada", 'KFC - Buhangin', 'Jollibee - Lanang', 'Mang Inasal - Ecoland'
    ]
    && array_column($chainNearbyResults['fries'], 'name') === [
        "McDonald's - Bajada", 'KFC - Buhangin', 'Jollibee - Lanang', 'Burger King - Matina'
    ]
    && array_column($chainNearbyResults['sundae'], 'name') === [
        "McDonald's - Bajada", 'Jollibee - Lanang', 'Burger King - Matina'
    ]
    && nearbyFeatureMatchesRequest(['name'=>'Burger Stop', 'food_type'=>'fast_food'], 'burger', $burgerIntent)
    && buildNearbyMapResults([], $fastFoodMapPlaces, 7.105, 125.635, 5.0, 'salad', parseQueryIntent('salad')) === [],
    'Generic item map searches require item evidence or a compatible chain and keep specific dishes distinct'
);
$mapFindFixtures = [
    ['name'=>'Burger Stop', 'city'=>'Davao City', 'food_type'=>'fast food', 'cuisine'=>'burger', 'district'=>'Lanang', 'address'=>'Lanang, Davao City', 'latitude'=>7.105, 'longitude'=>125.635, 'url'=>'https://www.openstreetmap.org/node/801'],
    ['name'=>'Burger Stop', 'city'=>'Davao City', 'food_type'=>'fast food', 'cuisine'=>'burger', 'district'=>'Poblacion', 'address'=>'Poblacion, Davao City', 'latitude'=>7.069, 'longitude'=>125.605, 'url'=>'https://www.openstreetmap.org/node/802'],
    ['name'=>'Burger King - Lanang', 'city'=>'Davao City', 'food_type'=>'fast food', 'address'=>'Lanang, Davao City', 'latitude'=>7.105, 'longitude'=>125.635, 'url'=>'https://www.openstreetmap.org/node/811'],
    ['name'=>'Generic Fast Food', 'city'=>'Davao City', 'food_type'=>'fast food', 'latitude'=>7.106, 'longitude'=>125.636, 'url'=>'https://www.openstreetmap.org/node/803'],
    ['name'=>'Coffee Stop', 'city'=>'Davao City', 'food_type'=>'cafe', 'cuisine'=>'coffee', 'latitude'=>7.107, 'longitude'=>125.637, 'url'=>'https://www.openstreetmap.org/node/804'],
    ['name'=>'KFC', 'branch'=>'Quirino', 'city'=>'Davao City', 'food_type'=>'fast food', 'address'=>'Quirino Avenue, Davao City', 'latitude'=>7.105, 'longitude'=>125.635, 'url'=>'https://www.openstreetmap.org/node/805'],
    ['name'=>'KFC', 'branch'=>'Lanang', 'city'=>'Davao City', 'food_type'=>'fast food', 'address'=>'Lanang, Davao City', 'latitude'=>7.106, 'longitude'=>125.636, 'url'=>'https://www.openstreetmap.org/node/806'],
    ['name'=>'KFC', 'city'=>'Davao City', 'food_type'=>'fast food', 'address'=>'Davao City', 'latitude'=>7.10602, 'longitude'=>125.63602, 'url'=>'https://www.openstreetmap.org/way/808'],
    ['name'=>'Jollibee', 'branch'=>'Matina', 'city'=>'Davao City', 'food_type'=>'fast food', 'address'=>'Matina, Davao City', 'latitude'=>7.069, 'longitude'=>125.605, 'url'=>'https://www.openstreetmap.org/node/807'],
];
$burgerMapFinds = buildMapFindResults($mapFindFixtures, 'burger', $burgerIntent);
$lanangBurgerIntent = $burgerIntent;
$lanangBurgerIntent['areas'] = ['Lanang'];
$lanangMapFinds = buildMapFindResults($mapFindFixtures, 'burger Lanang', $lanangBurgerIntent);
$budgetBurgerIntent = $burgerIntent;
$budgetBurgerIntent['max_price_php'] = 250;
assertCheck(
    count($burgerMapFinds) === 4
    && count($lanangMapFinds) === 2
    && array_column($lanangMapFinds, 'name') === ['Burger King - Lanang', 'Burger Stop - Lanang']
    && !empty($burgerMapFinds[0]['fast_food_chain'])
    && $burgerMapFinds[0]['map_only'] === true
    && $burgerMapFinds[0]['rating'] === null
    && $burgerMapFinds[0]['review_count'] === null
    && buildMapFindResults($mapFindFixtures, 'burger under PHP 250', $budgetBurgerIntent) === [],
    'Map finds require category evidence, honor requested areas, and never imply unverified ratings or prices'
);
$brandIntent = parseQueryIntent('KFC');
$brandMapFinds = buildMapFindResults($mapFindFixtures, 'KFC', $brandIntent, 'kfc', 100);
$anywhereKfcCore = detectExplicitRestaurantCoreQuery([['name'=>'KFC']], 'KFC anywhere in Davao');
$duplicateKfcMapFinds = buildMapFindResults([
    ['name'=>'KFC - Buhangin Airport', 'city'=>'Davao City', 'food_type'=>'fast food', 'nearby_street'=>'Carlos P. Garcia Highway', 'address'=>'Davao City', 'latitude'=>7.1, 'longitude'=>125.6, 'url'=>'https://www.openstreetmap.org/node/809'],
    ['name'=>'KFC', 'city'=>'Davao City', 'food_type'=>'fast food', 'nearby_street'=>'Carlos P. Garcia Highway', 'address'=>'Davao City', 'latitude'=>7.10001, 'longitude'=>125.60001, 'url'=>'https://www.openstreetmap.org/way/810'],
], 'KFC', $brandIntent, 'kfc', 100);
assertCheck(
    !empty($brandIntent['is_ambiguous'])
    && mapFindIntentIsSupported($brandIntent, 'kfc')
    && count($brandMapFinds) === 2
    && count(array_unique(array_column($brandMapFinds, 'location_source_url'))) === 2
    && $anywhereKfcCore === 'kfc'
    && count($duplicateKfcMapFinds) === 1
    && str_contains($duplicateKfcMapFinds[0]['name'], 'Buhangin Airport')
    && !mapBranchMatchesPlace($brandMapFinds[0], ['name'=>'KFC', 'address'=>'Davao City'])
    && mapBranchMatchesPlace($brandMapFinds[0], ['name'=>$brandMapFinds[0]['name'], 'address'=>$brandMapFinds[0]['address']])
    && buildMapFindResults($mapFindFixtures, 'KFC', $brandIntent) === [],
    'Known brand-only requests show distinct mapped branches without hiding them behind a generic brand match'
);
assertCheck(
    !nearbyFeatureMatchesRequest(
        ['name'=>'Fast Fresh', 'food_type'=>'restaurant', 'place_search_query'=>'fast food Davao City'],
        'fries',
        $friesIntent
    ),
    'A fast-food lookup query is not evidence that a restaurant serves fries'
);
$unverifiedFriesMapResults = buildNearbyMapResults(
    [['name'=>'Angel Burger', 'food_type'=>'Burgers', 'description'=>'Burger restaurant']],
    [['name'=>'Angel Burger', 'city'=>'Davao City', 'food_type'=>'fast_food', 'latitude'=>7.105, 'longitude'=>125.635, 'url'=>'https://www.openstreetmap.org/node/213']],
    7.105,
    125.635,
    5.0,
    'fries',
    $friesIntent
);
assertCheck($unverifiedFriesMapResults === [], 'Locally relevant burger candidates cannot bypass a fries-evidence check');
$verifiedFriesMapResults = buildNearbyMapResults(
    [['name'=>'Potato Corner', 'food_type'=>'Fries', 'description'=>'Serves fries']],
    [['name'=>'Potato Corner', 'city'=>'Davao City', 'food_type'=>'fast_food', 'latitude'=>7.105, 'longitude'=>125.635, 'url'=>'https://www.openstreetmap.org/node/214']],
    7.105,
    125.635,
    5.0,
    'fries',
    $friesIntent
);
assertCheck(
    count($verifiedFriesMapResults) === 1
    && $verifiedFriesMapResults[0]['name'] === 'Potato Corner'
    && empty($verifiedFriesMapResults[0]['fast_food_chain']),
    'An independently matching fries shop keeps its mapped location as a primary item match'
);
$independentFoodMapPlaces = [
    ['name'=>'Independent Burger Shop', 'city'=>'Davao City', 'food_type'=>'fast_food', 'cuisine'=>'burger', 'latitude'=>7.105, 'longitude'=>125.635, 'url'=>'https://www.openstreetmap.org/node/218'],
    ['name'=>'Chicken House', 'city'=>'Davao City', 'food_type'=>'restaurant', 'description'=>'Fried chicken', 'latitude'=>7.106, 'longitude'=>125.636, 'url'=>'https://www.openstreetmap.org/node/219'],
    ['name'=>'Uncategorized Fast Food', 'city'=>'Davao City', 'food_type'=>'fast_food', 'latitude'=>7.107, 'longitude'=>125.637, 'url'=>'https://www.openstreetmap.org/node/220'],
];
$independentBurgerMapResults = buildMapFindResults(
    $independentFoodMapPlaces,
    'burger',
    $burgerIntent
);
$independentChickenMapResults = buildMapFindResults(
    $independentFoodMapPlaces,
    'chicken',
    $chickenIntent
);
assertCheck(
    array_column($independentBurgerMapResults, 'name') === ['Independent Burger Shop']
    && empty($independentBurgerMapResults[0]['fast_food_chain'])
    && array_column($independentChickenMapResults, 'name') === ['Chicken House']
    && empty($independentChickenMapResults[0]['fast_food_chain']),
    'Generic burger and chicken searches accept non-chain map categories but reject unclassified fast food'
);
$friesCandidates = [
    ['_id'=>'potato-corner', 'name'=>'Potato Corner - Maa', 'food_type'=>'Fries', 'description'=>'', 'address'=>'Davao City', 'rating'=>4.8, 'review_count'=>100],
    ['_id'=>'fries-b4-guys', 'name'=>'Fries b4 Guys - Buhangin', 'food_type'=>'Chicken', 'description'=>'', 'address'=>'Davao City', 'rating'=>4.5, 'review_count'=>20],
    ['_id'=>'unrelated-pizza', 'name'=>'Pizza Place', 'food_type'=>'Pizza', 'description'=>'', 'address'=>'Davao City', 'rating'=>5.0, 'review_count'=>500],
];
$rankedFries = shortlistPlaces($friesCandidates, 'fries', 10, $friesIntent);
$mixedMenuPlaces = shortlistPlaces([
    $friesCandidates[0],
    ['_id'=>'burger-king', 'name'=>'Burger King - Davao', 'food_type'=>'Burgers', 'description'=>'', 'address'=>'Davao City', 'rating'=>4.5, 'review_count'=>100],
    $friesCandidates[2],
], 'fries burgers', 10, $mixedFriesBurgerIntent);
assertCheck(
    $friesIntent['strict_dishes'] === []
    && array_column($rankedFries, '_id') === ['potato-corner', 'fries-b4-guys']
    && nearbyFeatureMatchesRequest($friesCandidates[0], 'fries', $friesIntent)
    && $mixedFriesBurgerIntent['strict_dishes'] === []
    && count($mixedMenuPlaces) === 2
    && !str_contains(expandMenuItemSearchQuery('fries Davao City', $friesIntent), '"Potato Corner"')
    && !str_contains(expandMenuItemSearchQuery('fries Davao City', $friesIntent), '"Minute Burger"')
    && str_contains(expandMenuItemSearchQuery('fries Davao City', $friesIntent), 'McDonald')
    && str_contains(expandMenuItemSearchQuery('chicken Davao City', $chickenIntent), 'KFC'),
    'Menu-item searches keep exact fries matches, preserve mixed food searches, and expand Playwright queries with relevant brands'
);
assertCheck(
    $chickenBiryaniIntent['specific_dishes'] === ['biryani']
    && $chickenBiryaniIntent['strict_dishes'] === ['biryani']
    && array_column($chickenBiryaniLocalResults, '_id') === ['chicken-biryani']
    && count($chickenBiryaniNearbyResults) === 1
    && $chickenBiryaniNearbyResults[0]['name'] === 'Biryani House'
    && !nearbyFeatureMatchesRequest(
        ['name'=>'Chicken House', 'food_type'=>'chicken'],
        'chicken biryani',
        $chickenBiryaniIntent
    ),
    'Chicken biryani searches keep the dish constraint and reject generic chicken-only map matches'
);
$coffeeWebResults = rankNearbyWebPlaces(
    [['name'=>'Starbucks', 'address'=>'Davao City']],
    $starbucksMapPlaces,
    [],
    7.105,
    125.635,
    5.0
);
assertCheck(
    count($coffeeWebResults) === 1
    && str_contains($coffeeWebResults[0]['name'], 'Poblacion (J.P. Laurel Avenue)'),
    'Web-found near-me branches also receive their mapped district and street in the display name'
);
$relevanceFirstMapPlaces = [
    ['name'=>'Far Bistro', 'city'=>'Davao City', 'latitude'=>7.12, 'longitude'=>125.635, 'url'=>'https://www.openstreetmap.org/node/901'],
    ['name'=>'Close Cafe', 'city'=>'Davao City', 'latitude'=>7.106, 'longitude'=>125.635, 'url'=>'https://www.openstreetmap.org/node/902'],
];
$relevanceFirstCandidates = [
    ['_id'=>'far-bistro', 'name'=>'Far Bistro', 'address'=>'Davao City'],
    ['_id'=>'close-cafe', 'name'=>'Close Cafe', 'address'=>'Davao City'],
];
$relevanceFirstLocal = rankNearbyCandidates(
    $relevanceFirstCandidates,
    $relevanceFirstCandidates,
    $relevanceFirstMapPlaces,
    7.105,
    125.635,
    3.0,
    0.0,
    true
);
$relevanceFirstWeb = rankNearbyWebPlaces(
    [['name'=>'Far Bistro'], ['name'=>'Close Cafe']],
    $relevanceFirstMapPlaces,
    [],
    7.105,
    125.635,
    3.0,
    0.0,
    true
);
assertCheck(
    array_column($relevanceFirstLocal, 'name') === ['Far Bistro', 'Close Cafe']
    && array_column($relevanceFirstWeb, 'name') === ['Far Bistro', 'Close Cafe']
    && $relevanceFirstLocal[0]['_nearby_distance_km'] > $relevanceFirstLocal[1]['_nearby_distance_km']
    && $relevanceFirstWeb[0]['distance_km'] > $relevanceFirstWeb[1]['distance_km'],
    'Experience-aware nearby results preserve recommendation relevance before using distance as a tie-breaker'
);
assertApprox(nearbyDistanceKm(0.0, 0.0, 0.0, 1.0), 111.195, 0.01, 'Haversine distance uses kilometers');
assertCheck(
    DAVAO_NEARBY_RADIUS_KM === 3.0
    && nearbyDistanceWithinRadius(3.0, DAVAO_NEARBY_RADIUS_KM)
    && !nearbyDistanceWithinRadius(3.01, DAVAO_NEARBY_RADIUS_KM),
    'Near-me radius includes places up to 3 km and excludes more distant places'
);
$nearbySearchBounds = nearbySearchBounds(7.105, 125.635);
assertCheck(
    $nearbySearchBounds['west'] <= 125.635
    && $nearbySearchBounds['east'] >= 125.635
    && $nearbySearchBounds['south'] <= 7.105
    && $nearbySearchBounds['north'] >= 7.105
    && !isset($nearbySearchBounds['latitude'], $nearbySearchBounds['longitude']),
    'Local OSM lookup receives rounded bounds instead of raw near-me coordinates'
);
$localOsmSnapshot = loadLocalOsmNearbySnapshot($appRoot . '/data/osm-nearby-places.json', $nearbySearchBounds);
assertCheck(
    $localOsmSnapshot['place_count'] > 0
    && $localOsmSnapshot['osm_base_timestamp'] !== ''
    && count($localOsmSnapshot['places']) > 0
    && count(array_filter($localOsmSnapshot['places'], 'isValidNearbyMapPlace')) === count($localOsmSnapshot['places']),
    'Loads Davao OpenStreetMap nearby places locally and filters them to the rounded search bounds'
);
$fullLocalOsmSnapshot = loadLocalOsmNearbySnapshot($appRoot . '/data/osm-nearby-places.json');
assertCheck(
    count($fullLocalOsmSnapshot['places']) > count($localOsmSnapshot['places']),
    'Loads the full local Davao OSM snapshot for non-location map supplements'
);
$jollibeeMapFinds = buildMapFindResults(
    $fullLocalOsmSnapshot['places'],
    'Jollibee',
    parseQueryIntent('Jollibee'),
    'jollibee',
    100
);
assertCheck(
    count($jollibeeMapFinds) > 1
    && count(array_unique(array_column($jollibeeMapFinds, 'location_source_url'))) === count($jollibeeMapFinds)
    && count(array_filter($jollibeeMapFinds, fn($place) => !empty($place['map_only']))) === count($jollibeeMapFinds),
    'A brand-only search returns multiple distinct, explicitly map-only branches from the local OSM snapshot'
);
$fullSnapshotBurgerFinds = buildMapFindResults(
    $fullLocalOsmSnapshot['places'],
    'burger',
    parseQueryIntent('burger')
);
assertCheck(
    count($fullSnapshotBurgerFinds) > 0
    && count(array_filter($fullSnapshotBurgerFinds, fn($place) =>
        !empty($place['map_only']) && $place['rating'] === null && $place['review_count'] === null
    )) === count($fullSnapshotBurgerFinds),
    'The general-search map supplement returns category-matched, non-rated OSM listings'
);
$localHalalResults = buildNearbyMapResults(
    [],
    $localOsmSnapshot['places'],
    7.105,
    125.635,
    5.0,
    'halal dishes',
    parseQueryIntent('halal dishes')
);
assertCheck(
    count($localHalalResults) > 0
    && count(array_filter($localHalalResults, fn($place) => !empty($place['location_source_url']))) === count($localHalalResults),
    'Retrieves mapped halal matches from the local OSM index within the exact 5 km radius'
);
$localCoffeeResults = buildNearbyMapResults(
    [],
    $localOsmSnapshot['places'],
    7.105,
    125.635,
    5.0,
    'coffeeshop',
    $coffeeAfterBurgerIntent
);
$localBurgerResults = buildNearbyMapResults(
    [],
    $localOsmSnapshot['places'],
    7.105,
    125.635,
    5.0,
    'burger',
    parseQueryIntent('burger')
);
assertCheck(
    count($localCoffeeResults) > 0
    && count(array_filter($localCoffeeResults, fn($place) =>
        preg_match('/\b(?:j&k yogopop|angel\'s burger|chicken namit fried chicken)\b/iu', (string)$place['name']) === 1
    )) === 0
    && count($localBurgerResults) > 0
    && count(array_filter($localBurgerResults, fn($place) =>
        preg_match('/\b(?:j&k yogopop|ilonggo mandaya eatery|jenny\'s lechon|balbacua sa buhangin|kambingan sa buhangin)\b/iu', (string)$place['name']) === 1
    )) === 0,
    'The local OSM snapshot returns food-specific burger matches without unrelated fast-food venues'
);
assertCheck(
    !nearbyFeatureMatchesRequest(
        ['name'=>'Fast Fresh', 'food_type'=>'fast food'],
        'fries',
        ['specific_dishes'=>['fries']]
    ),
    'Near-me fries queries reject mapped fast-food venues without fries evidence'
);
assertCheck(
    !nearbyFeatureMatchesRequest(
        ['name'=>'Potato Corner', 'food_type'=>'fast food', 'place_search_query'=>'halal restaurant Davao City'],
        'halal',
        ['dietary'=>['halal']]
    ),
    'A halal search query does not prove a mapped venue is halal'
);
$unverifiedHalalMapResults = buildNearbyMapResults(
    [['name'=>'Potato Corner', 'food_type'=>'Fast Food', 'description'=>'Fries and snacks']],
    [['name'=>'Potato Corner', 'city'=>'Davao City', 'food_type'=>'fast_food', 'place_search_query'=>'halal restaurant Davao City', 'latitude'=>7.105, 'longitude'=>125.635, 'url'=>'https://www.openstreetmap.org/node/215']],
    7.105,
    125.635,
    5.0,
    'halal',
    ['dietary'=>['halal']]
);
assertCheck($unverifiedHalalMapResults === [], 'Locally relevant candidates cannot bypass a halal-evidence check');
assertCheck(
    nearbyFeatureMatchesRequest(
        ['name'=>'Basilio Halal Restaurant', 'food_type'=>'restaurant'],
        'halal dishes',
        []
    )
    && !nearbyFeatureMatchesRequest(
        ['name'=>'Unlabeled Restaurant', 'food_type'=>'restaurant'],
        'halal dishes',
        []
    )
    && nearbyFeatureMatchesRequest(
        ['name'=>'Tagged Restaurant', 'food_type'=>'restaurant', 'dietary_tags'=>['halal'=>'yes']],
        'halal dishes',
        []
    ),
    'Near-me dietary searches require a matching OSM name or dietary tag'
);
$brandQueries = nearbyBrandQueryTerms([
    ['name'=>"McDonald's - Davao Bajada"],
    ['name'=>"McDonald's - SPMC"],
    ['name'=>'KFC - Lanang Davao'],
    ['name'=>'Jollibee - Phoenix Lanang'],
]);
assertCheck($brandQueries === ["McDonald's", 'KFC', 'Jollibee'], 'Near-me map lookup queries unique restaurant brands without branch ambiguity');
$invalidNearbyFeature = $nearbyMapPlaces[0];
$invalidNearbyFeature['city'] = 'Tagum City';
assertCheck(!isValidNearbyMapPlace($invalidNearbyFeature), 'Near-me search rejects map features outside Davao City');

// 3. Test Scope Detection & Multi-Turn Intent Resolution
assertCheck(detectOutOfScopeLocation('Japanese restaurants in Tagum City')['out_of_scope'] === true, 'Tagum City rejected');
assertCheck(detectOutOfScopeLocation('Quiet cafes in Digos City')['out_of_scope'] === true, 'Digos City rejected');
assertCheck(detectOutOfScopeLocation('Seafood in Panabo City')['out_of_scope'] === true, 'Panabo City rejected');
assertCheck(detectOutOfScopeLocation('Resorts in Island Garden City of Samal')['out_of_scope'] === true, 'Samal rejected');
assertCheck(detectOutOfScopeLocation('Restaurants in Cebu City')['out_of_scope'] === true, 'Cebu City rejected');
assertCheck(detectOutOfScopeLocation('Cebu boneless lechon in Davao City')['out_of_scope'] === false, 'Cebu food style in Davao City allowed');
assertCheck(detectOutOfScopeLocation('Coffee shops in Obrero Davao City')['out_of_scope'] === false, 'Obrero Davao City allowed');
$firesTypo = suggestSearchQueryTypo('fires');
$coffeeTypo = suggestSearchQueryTypo('quiet cofee near Matina');
$italianTypo = suggestSearchQueryTypo('italain restaurants');
$chainTypo = suggestSearchQueryTypo('jollibe');
$restaurantTypo = suggestSearchQueryTypo('restuarant');
$burgerSteakTypo = suggestSearchQueryTypo('burgrsteak');
assertCheck(
    ($firesTypo['suggested_term'] ?? null) === 'fries'
    && ($firesTypo['corrected_query'] ?? null) === 'fries'
    && ($coffeeTypo['corrected_query'] ?? null) === 'quiet coffee near Matina'
    && ($italianTypo['corrected_query'] ?? null) === 'Italian restaurants'
    && ($chainTypo['corrected_query'] ?? null) === 'Jollibee'
    && ($restaurantTypo['corrected_query'] ?? null) === 'restaurant'
    && ($burgerSteakTypo['corrected_query'] ?? null) === 'burger steak'
    && suggestSearchQueryTypo('briyani') === null
    && suggestSearchQueryTypo('xyzzy') === null,
    'Suggests unique high-confidence spelling fixes while preserving query context and recognized aliases'
);

$mtIntent = parseQueryIntent(
    'What about cheaper options in Lanang?',
    [['role'=>'user', 'content'=>'Japanese restaurants in Davao City'], ['role'=>'assistant', 'content'=>'Try Nonki.']],
    ['Nonki Japanese Restaurant - AutoVille Building']
);
assertCheck($mtIntent['is_followup'] === true, 'Multi-turn follow-up detected');
assertCheck(in_array('Japanese', $mtIntent['cuisines'], true), 'Inherited Japanese cuisine from Turn 1');
assertCheck($mtIntent['budget'] === 'affordable', 'Extracted budget refinement from Turn 2');
assertCheck(!empty($mtIntent['areas']), 'Extracted Lanang area refinement from Turn 2');
assertCheck($mtIntent['exclude_shown'] === true && count($mtIntent['exclude_names']) === 1, 'Tracked shownNames exclusion');

// 4. Test Statistics (Percentiles, Seeded Bootstrap CI, Friedman, Wilcoxon + Holm, Cohen's & Fleiss' Kappa)
$vals = [1.0, 2.0, 3.0, 4.0, 5.0, 6.0, 7.0, 8.0, 9.0, 10.0];
assertApprox(percentileVal($vals, 50.0), 5.5, 1e-6, 'Median P50');
assertApprox(percentileVal($vals, 95.0), 9.55, 1e-6, 'P95');
assertApprox(percentileVal($vals, 99.0), 9.91, 1e-6, 'P99');

$ci1 = confidenceInterval95($vals, 42, 1000);
$ci2 = confidenceInterval95($vals, 42, 1000);
assertApprox($ci1['ci_lower'], $ci2['ci_lower'], 1e-12, 'Bootstrap CI deterministic reproducibility');
assertCheck($ci1['ci_lower'] < $ci1['mean'] && $ci1['ci_upper'] > $ci1['mean'], 'Bootstrap CI brackets mean');

$sysA = [0.8, 0.9, 0.85, 0.95, 0.9, 0.8, 0.88, 0.92, 0.85, 0.9];
$sysB = [0.2, 0.3, 0.25, 0.35, 0.3, 0.2, 0.28, 0.32, 0.25, 0.3];
$sysC = [0.5, 0.5, 0.55, 0.45, 0.5, 0.5, 0.48, 0.52, 0.5, 0.5];
$fr = friedmanTest([$sysA, $sysB, $sysC], 10);
assertCheck($fr !== null && $fr['p_value'] < 0.001, 'Friedman detects clear 3-system separation');

$posthoc = pairwiseWilcoxonPostHoc([$sysA, $sysB, $sysC], ['Agentic', 'Baseline', 'Mid']);
assertCheck(count($posthoc) === 3 && $posthoc[0]['significant_005'] === true, 'Wilcoxon + Holm-Bonferroni detects significant pair');

$r1 = [3, 3, 2, 1, 0, 0, 2, 3, 1, 0];
$r2 = [3, 2, 2, 1, 0, 0, 2, 3, 1, 1];
$r3 = [3, 3, 2, 1, 0, 0, 2, 2, 1, 0];
$ck = cohensKappa($r1, $r2, true);
assertCheck($ck !== null && $ck['kappa'] > 0.75, 'Weighted Cohen Kappa substantial/almost-perfect agreement');
$fkMat = [];
for ($i = 0; $i < count($r1); $i++) $fkMat[] = [$r1[$i], $r2[$i], $r3[$i]];
$fk = fleissKappa($fkMat);
assertCheck($fk !== null && $fk['kappa'] > 0.60, 'Fleiss Kappa across 3 raters');

// 5. Validate full v3.0 ground_truth.json & volatility_cases.json benchmarks
$gt = json_decode((string)file_get_contents(__DIR__ . '/ground_truth.json'), true);
$normGt = validateAndNormalizeGroundTruth($gt);
assertCheck(count($normGt['cases']) === 62, 'Ground truth v3.0 has 62 in-scope/multi-turn cases');
assertCheck(count($normGt['scope_cases']) === 10, 'Ground truth v3.0 has 10 scope cases (72 total)');
assertCheck(count($normGt['disambiguation_schema'] ?? []) >= 5, 'Ground truth includes formal disambiguation_schema');

$volSpec = json_decode((string)file_get_contents(__DIR__ . '/volatility_cases.json'), true);
assertCheck(count($volSpec['scenarios'] ?? []) === 15, 'Volatility benchmark has 15 real Davao City scenarios');

// 6. Test Objective 2 — Davao Bisaya Synonyms & Anti-Polysemy Guards
$synCheck = normalizeDavaoQuerySynonyms('Affordable kan-anan sinugba and sangyup or shwarma in Davao');
assertCheck(count($synCheck['matched_synonyms']) >= 3, 'Normalizes Davao Bisaya & phonetic culinary variants');

$saladIntent = parseQueryIntent('Fresh green salad and healthy vegetarian food in Davao City');
assertCheck(matchesAntiPolysemyGuard(['name'=>'Greenwich - Abreeza', 'food_type'=>'Pizza'], $saladIntent)['valid'] === false, 'Rejects Greenwich polysemy trap on green salad query');
assertCheck(matchesAntiPolysemyGuard(['name'=>'Green Coffee - Marfori', 'food_type'=>'Coffee'], $saladIntent)['valid'] === false, 'Rejects Green Coffee polysemy trap on green salad query');
assertCheck(matchesAntiPolysemyGuard(['name'=>'The Green Bounty Kitchen - Ecoland', 'food_type'=>'Healthy'], $saladIntent)['valid'] === true, 'Allows genuine healthy salad restaurant');

$lateIntent = parseQueryIntent('24 hours open late night food in Davao City');
assertCheck(matchesAntiPolysemyGuard(['name'=>'24 Chicken - Bajada', 'food_type'=>'Fried Chicken', '_review_signals'=>['late_24'=>0]], $lateIntent)['valid'] === false, 'Rejects 24 Chicken brand-number trap on 24-hour query');

$jpIntent = parseQueryIntent('Authentic Tokyo sushi and Japanese ramen in Davao City');
assertCheck(matchesAntiPolysemyGuard(['name'=>'Tokyo Waffle - SM Lanang', 'food_type'=>'Pancakes & Waffles'], $jpIntent)['valid'] === false, 'Rejects Tokyo Waffle dessert trap on Japanese query');

// 7. Test Objective 4A — Peso Extraction & Hard Numeric Price Ceilings
$pesos = extractPesoAmountsFromText('Solo pasta meal is ₱145 while family platter is PHP 420 in 2025.');
assertCheck($pesos === [145, 420], 'Extracts genuine PHP prices while ignoring calendar year 2025');

$priceIntent = parseQueryIntent('Burgers under ₱200 in Davao City');
assertApprox((float)$priceIntent['max_price_php'], 200.0, 1e-6, 'Extracts hard max_price_php = 200');
$menuItemPriceIntent = parseQueryIntent('sisig under ₱300');
assertCheck(($menuItemPriceIntent['price_scope'] ?? null) === 'menu_item', 'Treats a dish-specific ceiling as a menu-item price');
$burgerCeilingIntent = parseQueryIntent('burger under ₱300 per person');
assertCheck(
    expandMenuItemSearchQuery('burger under 300 Davao City', $burgerCeilingIntent) === 'burger under 300 Davao City',
    'Preserves a price-constrained search query instead of adding a broad OR clause'
);
$perPersonPriceIntent = parseQueryIntent('sisig under ₱300 per person');
assertCheck(($perPersonPriceIntent['price_scope'] ?? null) === 'per_person', 'Keeps an explicit per-person ceiling restaurant-scoped');
$menuItemPriceFollowup = parseQueryIntent('actually under ₱250', [
    ['role'=>'user', 'content'=>'sisig under ₱300'],
    ['role'=>'assistant', 'content'=>'Here are sisig places.'],
]);
assertCheck(($menuItemPriceFollowup['price_scope'] ?? null) === 'menu_item'
    && (float)$menuItemPriceFollowup['max_price_php'] === 250.0,
    'Preserves menu-item price scope when a follow-up tightens the ceiling');
assertApprox((float)(extractMenuItemPriceFromText('Sisig - ₱240; halo-halo - ₱120', 'sisig')['median'] ?? 0), 240.0, 1e-6, 'Extracts a price adjacent to the requested menu item');
assertCheck(extractMenuItemPriceFromText('Sisig available; halo-halo - ₱120', 'sisig') === null, 'Does not attribute an adjacent unrelated dish price to the requested menu item');
$menuPriceQueries = buildPriceLookupQueries([], $menuItemPriceIntent, true);
assertCheck($menuPriceQueries === ['"sisig" menu price PHP Davao City'], 'Builds a dish-specific online price query without relying on dataset venues');
$plainFoodIntent = parseQueryIntent('Japanese restaurants in Davao City');
assertCheck(!shouldAskPriceClarification('Japanese restaurants in Davao City', $plainFoodIntent), 'Does not ask about price unless the user raises it');
$unspecifiedPriceIntent = parseQueryIntent('What price range do Japanese restaurants have in Davao City?');
assertCheck(shouldAskPriceClarification('What price range do Japanese restaurants have in Davao City?', $unspecifiedPriceIntent), 'Asks for clarification when price is raised without a usable range');
assertCheck(!shouldAskPriceClarification('Japanese restaurants under ₱200 in Davao City', $priceIntent), 'Does not ask again when a price ceiling is specified');
$upscaleIntent = parseQueryIntent('Upscale Japanese restaurants in Davao City');
assertCheck($upscaleIntent['price_preference'] === 'upscale', 'Extracts an upscale price preference');
$midRangeIntent = parseQueryIntent('Mid-range Japanese restaurants in Davao City');
assertCheck($midRangeIntent['price_preference'] === 'mid_range', 'Extracts a mid-range price preference');
$budgetAmountIntent = parseQueryIntent('My budget is ₱500 per person');
assertApprox((float)$budgetAmountIntent['max_price_php'], 500.0, 1e-6, 'Extracts a per-person peso budget ceiling');
$bareBudgetAmountIntent = parseQueryIntent('₱500');
assertApprox((float)$bareBudgetAmountIntent['max_price_php'], 500.0, 1e-6, 'Accepts a bare peso amount as a budget ceiling');
$priceFollowupIntent = parseQueryIntent('Upscale', [
    ['role'=>'user', 'content'=>'Japanese restaurants in Davao City'],
    ['role'=>'assistant', 'content'=>'What price range works for you?'],
]);
assertCheck($priceFollowupIntent['price_preference'] === 'upscale' && in_array('Japanese', $priceFollowupIntent['cuisines'], true), 'Applies an upscale follow-up to the original cuisine request');
$expensiveBurger = ['name'=>'Harleys Burgers', 'food_type'=>'Burgers', '_observed_prices_php'=>['min'=>270, 'median'=>330, 'max'=>377, 'mentions'=>3]];
$budgetBurger = ['name'=>'Jollibee Magsaysay', 'food_type'=>'Burgers', '_observed_prices_php'=>['min'=>95, 'median'=>135, 'max'=>175, 'mentions'=>3]];
assertCheck(enforceNumericPriceCeiling([$expensiveBurger, $budgetBurger], 200.0) === [$budgetBurger], 'Enforces hard numeric peso price ceiling');
$cheapRestaurantExpensiveSisig = [
    'name'=>'Sisig House',
    '_observed_prices_php'=>['min'=>100, 'median'=>150, 'max'=>200, 'mentions'=>3],
    '_menu_item_price_php'=>350,
    '_menu_item_price_max_php'=>350,
    '_menu_item_price_item'=>'sisig',
];
$expensiveRestaurantCheapSisig = [
    'name'=>'Upscale Sisig',
    '_observed_prices_php'=>['min'=>600, 'median'=>800, 'max'=>1000, 'mentions'=>3],
    '_menu_item_price_php'=>250,
    '_menu_item_price_max_php'=>270,
    '_menu_item_price_item'=>'sisig',
];
assertCheck(
    enforceNumericPriceCeiling([$cheapRestaurantExpensiveSisig, $expensiveRestaurantCheapSisig], 300.0, null, true, 'menu_item') === [$expensiveRestaurantCheapSisig],
    'Applies a menu-item ceiling to the requested item price, not venue-wide spending'
);

// 8. Test Objective 1 & 4B — Live Volatility Reconciliation & Autonomous Iterative Critique-and-Refinement Loop
$mockPlaces = [
    ['_id'=>'p1', 'name'=>'Resto Closed - Aurora', 'food_type'=>'Japanese', 'city'=>'Davao City', 'rating'=>5.0, 'review_count'=>200, '_branch_area'=>'Aurora', '_search'=>'resto closed aurora japanese', '_review_signals'=>[]],
    ['_id'=>'p2', 'name'=>'Resto Hiked - Matina', 'food_type'=>'Japanese', 'city'=>'Davao City', 'rating'=>4.9, 'review_count'=>180, '_branch_area'=>'Matina', '_search'=>'resto hiked matina japanese', '_review_signals'=>[]],
    ['_id'=>'p3', 'name'=>'Resto Open Budget - Lanang', 'food_type'=>'Japanese', 'city'=>'Davao City', 'rating'=>4.9, 'review_count'=>160, '_branch_area'=>'Lanang', '_search'=>'resto open budget lanang japanese', '_observed_prices_php'=>['min'=>150, 'median'=>175, 'max'=>190, 'mentions'=>2], '_review_signals'=>['affordable'=>3]],
];
$mockEvidence = [
    'results'=>[
        ['title'=>'Resto Hiked - Matina menu prices', 'url'=>'https://example.com/resto-hiked-menu', 'text'=>'Resto Closed - Aurora is permanently closed as of 2026. Resto Hiked - Matina updated menu prices now ₱350 to ₱420.'],
    ],
];
$reconciled = reconcileVolatilityWithEvidence($mockPlaces, $mockEvidence);
assertCheck(in_array('Resto Closed - Aurora', $reconciled['report']['closed_places'], true), 'Volatility engine detects closed restaurant');
assertCheck(($reconciled['places'][1]['_live_price_php'] ?? 0) >= 350, 'Volatility engine updates drifted live menu price');
assertCheck(($reconciled['places'][1]['_live_price_source_url'] ?? '') === 'https://example.com/resto-hiked-menu', 'Web price evidence retains its source URL');
assertCheck((publicPlace($reconciled['places'][1])['price_evidence']['source_url'] ?? '') === 'https://example.com/resto-hiked-menu', 'Public result exposes the price source for details');
assertCheck(enforceNumericPriceCeiling([$reconciled['places'][1]], 500.0) === [$reconciled['places'][1]], 'Web-derived price under the ceiling remains eligible');
assertCheck(enforceNumericPriceCeiling([$reconciled['places'][1]], 400.0) === [], 'Web-derived price above the ceiling is excluded');

$menuPricePlace = [
    '_id'=>'sisig-house-matina',
    'name'=>'Sisig House - Matina',
    'food_type'=>'Filipino',
    'city'=>'Davao City',
    '_branch_area'=>'Matina',
    '_review_signals'=>[],
];
$menuPriceEvidence = [
    'results'=>[[
        'title'=>'Sisig House - Matina menu prices',
        'url'=>'https://example.com/sisig-house-menu',
        'text'=>'Sisig House - Matina menu: sisig ₱250 per serving.',
        'price_lookup'=>true,
    ]],
];
$reconciledMenuPrice = reconcileVolatilityWithEvidence([$menuPricePlace], $menuPriceEvidence, $menuItemPriceIntent);
assertApprox((float)($reconciledMenuPrice['places'][0]['_menu_item_price_php'] ?? 0), 250.0, 1e-6, 'Reconciles exact menu-item price evidence to the matching restaurant');
assertCheck(!isset($reconciledMenuPrice['places'][0]['_live_price_php']), 'Does not mistake a dish price for venue-wide price evidence');
assertCheck(
    (publicPlace($reconciledMenuPrice['places'][0])['menu_item_price_evidence']['source_url'] ?? '') === 'https://example.com/sisig-house-menu',
    'Exposes source-backed menu-item price evidence to the results UI'
);
$attachedWebMenuPrice = attachMenuItemPriceEvidenceToPlaces(
    [['name'=>'Sisig House - Matina', 'city'=>'Davao City']],
    $menuPriceEvidence,
    $menuItemPriceIntent
);
assertApprox(
    (float)($attachedWebMenuPrice[0]['menu_item_price_evidence']['observed_median_php'] ?? 0),
    250.0,
    1e-6,
    'Attaches exact menu-item price evidence to online results'
);
$unverifiedMenuPlace = [
    '_id'=>'sisig-unknown',
    'name'=>'Sisig House - Downtown',
    'food_type'=>'Filipino',
    'city'=>'Davao City',
    '_review_signals'=>[],
];
assertCheck(
    in_array('menu_item_price_unverified', critiqueSinglePlace($unverifiedMenuPlace, $menuItemPriceIntent), true),
    'Does not count an unverified menu-item price as ceiling compliant'
);
[, , , $unverifiedMenuPriceStats] = runAutonomousRefinementLoop(
    'sisig under ₱300',
    'Sisig House - Downtown may be relevant.',
    [],
    [['name'=>'Sisig House - Downtown', 'city'=>'Davao City']],
    [],
    $menuItemPriceIntent
);
assertCheck(
    ($unverifiedMenuPriceStats['unverified_menu_item_prices'] ?? 0) === 1,
    'Tracks online results without verified menu-item prices separately'
);

$loopIntent = parseQueryIntent('Japanese restaurants in Davao City under ₱200');
[, $repairedIds, , $loopStats] = runAutonomousRefinementLoop(
    'Japanese restaurants in Davao City under ₱200',
    'Draft recommending Resto Closed - Aurora and Resto Hiked - Matina.',
    ['p1', 'p2', 'p3'],
    [],
    $reconciled['places'],
    $loopIntent,
    $reconciled['report']
);
assertCheck($repairedIds[0] === 'p3' && !in_array('p1', $repairedIds, true) && !in_array('p2', $repairedIds, true), 'Autonomous iterative loop prunes closed & price-violating venues');
assertCheck($loopStats['final_compliance_rate'] > $loopStats['initial_compliance_rate'], 'Autonomous loop measurably improves compliance rate from Pass 1 to Pass 2');

$chickenIntent = parseQueryIntent('chicken');
$popularChickenPlaces = [
    [
        '_id'=>'chicken-kfc',
        'name'=>'KFC - Quirino Davao',
        'food_type'=>'Fast Food',
        'description'=>'',
        'address'=>'Davao City',
        'city'=>'Davao City',
        'price_range'=>'',
        'rating'=>4.9,
        'review_count'=>17913,
        '_review_signals'=>[],
        '_review_snippets'=>[],
        '_branch_area'=>'',
    ],
    [
        '_id'=>'chicken-mcdonalds',
        'name'=>'McDonald\'s - Davao Bajada',
        'food_type'=>'Fast Food',
        'description'=>'',
        'address'=>'Davao City',
        'city'=>'Davao City',
        'price_range'=>'',
        'rating'=>5.0,
        'review_count'=>21541,
        '_review_signals'=>[],
        '_review_snippets'=>[],
        '_branch_area'=>'',
    ],
    [
        '_id'=>'chicken-jollibee',
        'name'=>'Jollibee - Davao Magsaysay',
        'food_type'=>'Burgers',
        'description'=>'',
        'address'=>'Davao City',
        'city'=>'Davao City',
        'price_range'=>'',
        'rating'=>5.0,
        'review_count'=>1182,
        '_review_signals'=>[],
        '_review_snippets'=>[],
        '_branch_area'=>'',
    ],
    [
        '_id'=>'chicken-low-review',
        'name'=>'Chicken Place With Few Reviews',
        'food_type'=>'Chicken',
        'description'=>'',
        'address'=>'Davao City',
        'city'=>'Davao City',
        'price_range'=>'',
        'rating'=>2.5,
        'review_count'=>2,
        '_review_signals'=>[],
        '_review_snippets'=>[],
        '_branch_area'=>'',
    ],
];
$requestedCore = detectExplicitRestaurantCoreQuery($popularChickenPlaces, 'Jollibee');
$jollibeeOnlyPlaces = $requestedCore !== null
    ? filterPlacesByRestaurantCore($popularChickenPlaces, $requestedCore)
    : [];
$jollibeeBranchVariants = filterPlacesByRestaurantCore([
    ['name'=>'Jollibee - Davao Magsaysay'],
    ['name'=>'Jollibee Magsaysay'],
    ['name'=>'Jollibeefy Magsaysay'],
], 'jollibee');
$standaloneJollibeeIntent = parseQueryIntent('jollibee', [], []);
assertCheck(
    $requestedCore === 'jollibee'
    && detectExplicitRestaurantCoreQuery($popularChickenPlaces, 'Show me Jollibee branches near me') === 'jollibee'
    && count($jollibeeOnlyPlaces) === 1
    && $jollibeeOnlyPlaces[0]['_id'] === 'chicken-jollibee'
    && count($jollibeeBranchVariants) === 2
    && empty($standaloneJollibeeIntent['cuisines'])
    && empty($standaloneJollibeeIntent['specific_dishes']),
    'Explicit Jollibee search resets inherited chicken intent and excludes other chains'
);
$rankedChickenPlaces = shortlistPlaces($popularChickenPlaces, 'chicken', 10, $chickenIntent);
$rankedChickenIds = array_column($rankedChickenPlaces, '_id');
$expandedChickenIds = supplementRankedPlaceIds(['chicken-jollibee'], $rankedChickenPlaces, 4);
$topChickenIds = array_slice($rankedChickenIds, 0, 3);
$lowReviewChickenRank = array_search('chicken-low-review', $rankedChickenIds, true);
assertCheck(
    count(array_intersect(['chicken-kfc', 'chicken-mcdonalds', 'chicken-jollibee'], $topChickenIds)) === 3
    && $lowReviewChickenRank !== false
    && $lowReviewChickenRank > 2
    && critiqueSinglePlace($popularChickenPlaces[0], $chickenIntent, [], false, true) === [],
    'Chicken results include well-reviewed fast-food chains and down-rank low-review venues'
);
$bulcachongIntent = parseQueryIntent('bulcachong');
$unmatchedBulcachongPlaces = shortlistPlaces($popularChickenPlaces, 'bulcachong', 10, $bulcachongIntent);
$verifiedBulcachong = array_merge($popularChickenPlaces, [[
    '_id'=>'bulcachong-local',
    'name'=>'Bulca Chong - Bankerohan',
    'food_type'=>'Filipino & Fast Food',
    'description'=>'',
    'address'=>'Davao City',
    'city'=>'Davao City',
    'rating'=>4.1,
    'review_count'=>383,
    '_review_signals'=>[],
    '_review_snippets'=>[],
    '_branch_area'=>'',
]]);
$verifiedBulcachongPlaces = shortlistPlaces($verifiedBulcachong, 'bulcachong', 10, $bulcachongIntent);
assertCheck(
    $bulcachongIntent['specific_dishes'] === ['bulcachong']
    && $bulcachongIntent['strict_dishes'] === ['bulcachong']
    && $unmatchedBulcachongPlaces === []
    && count($verifiedBulcachongPlaces) === 1
    && $verifiedBulcachongPlaces[0]['_id'] === 'bulcachong-local',
    'Bulcachong search excludes popular chains unless their records verify the requested dish'
);
$balbacuaIntent = parseQueryIntent('balbakwa');
$unmatchedBalbacuaPlaces = shortlistPlaces($popularChickenPlaces, 'balbakwa', 10, $balbacuaIntent);
$verifiedBalbacuaPlaces = shortlistPlaces(array_merge($popularChickenPlaces, [[
    '_id'=>'balbacua-local',
    'name'=>'Balbacua House - Bankerohan',
    'food_type'=>'Filipino',
    'description'=>'',
    'address'=>'Davao City',
    'city'=>'Davao City',
    'rating'=>4.1,
    'review_count'=>80,
    '_review_signals'=>[],
    '_review_snippets'=>[],
    '_branch_area'=>'',
]]), 'balbakwa', 10, $balbacuaIntent);
assertCheck(
    $balbacuaIntent['specific_dishes'] === ['balbacua']
    && $balbacuaIntent['strict_dishes'] === ['balbacua']
    && $unmatchedBalbacuaPlaces === []
    && count($verifiedBalbacuaPlaces) === 1
    && $verifiedBalbacuaPlaces[0]['_id'] === 'balbacua-local',
    'Balbakwa is matched as Balbacua and excludes unrelated popular chains'
);
$unknownDishIntent = parseQueryIntent('xyzzy');
assertCheck(
    !empty($unknownDishIntent['is_ambiguous'])
    && shortlistPlaces($popularChickenPlaces, 'xyzzy', 10, $unknownDishIntent) === [],
    'Unresolved single-term searches do not fall back to unrelated popular local places'
);
$burgerKing = $popularChickenPlaces[0];
$burgerKing['_id'] = 'burger-king';
$burgerKing['name'] = 'Burger King - Davao';
$burgerKing['food_type'] = 'Burgers';
$burgerPlaces = shortlistPlaces(
    array_merge($popularChickenPlaces, [$burgerKing]),
    'burgers',
    10,
    parseQueryIntent('burgers')
);
$burgerPlaceIds = array_column($burgerPlaces, '_id');
assertCheck(
    in_array('burger-king', $burgerPlaceIds, true)
    && in_array('chicken-jollibee', $burgerPlaceIds, true)
    && !in_array('chicken-kfc', $burgerPlaceIds, true),
    'Burger requests retain burger chains without broadly adding chicken chains'
);
assertCheck(
    count($expandedChickenIds) === 4
    && $expandedChickenIds[0] === 'chicken-jollibee'
    && count(array_unique($expandedChickenIds)) === 4,
    'Short recommendation lists are supplemented from relevance-ranked candidates without duplicates'
);
$chickenPlaces = [];
for ($i = 1; $i <= 10; $i++) {
    $chickenPlaces[] = [
        '_id'=>'chicken-' . $i,
        'name'=>'Chicken Place ' . $i,
        'food_type'=>'Chicken',
        'description'=>'',
        'address'=>'Davao City',
        'city'=>'Davao City',
        'price_range'=>'',
        'rating'=>4.5,
        'review_count'=>100,
        '_review_signals'=>[],
        '_review_snippets'=>[],
        '_branch_area'=>'',
    ];
}
[$expandedChickenAnswer, $expandedChickenIds] = runAutonomousRefinementLoop(
    'chicken',
    'Here are chicken places.',
    array_column(array_slice($chickenPlaces, 0, 4), '_id'),
    [],
    $chickenPlaces,
    $chickenIntent,
    [],
    true
);
assertCheck(
    count($expandedChickenIds) === 8 && count(array_unique($expandedChickenIds)) === 8,
    'Interactive food recommendations consistently fill up to eight grounded local matches'
);

// 9. Test Objective 4C — Standard 10-Item System Usability Scale (SUS) Formula
$perfectSus = computeSusScore([1=>5, 2=>1, 3=>5, 4=>1, 5=>5, 6=>1, 7=>5, 8=>1, 9=>5, 10=>1]);
$neutralSus = computeSusScore([1=>3, 2=>3, 3=>3, 4=>3, 5=>3, 6=>3, 7=>3, 8=>3, 9=>3, 10=>3]);
$worstSus   = computeSusScore([1=>1, 2=>5, 3=>1, 4=>5, 5=>1, 6=>5, 7=>1, 8=>5, 9=>1, 10=>5]);
assertApprox($perfectSus['sus_score'], 100.0, 1e-6, 'SUS max score = 100.0');
assertApprox($neutralSus['sus_score'], 50.0, 1e-6, 'SUS neutral score = 50.0');
assertApprox($worstSus['sus_score'], 0.0, 1e-6, 'SUS min score = 0.0');
assertCheck($perfectSus['grade'] === 'A+' && $perfectSus['acceptable'] === true, 'SUS curved grade mapping');

// 10. Test Specific Dish Verification, Street Proximity Clusters, and Multi-Turn Layered Corrections
$sisigTurn1 = parseQueryIntent('sisigan near jacinto street');
assertCheck(in_array('sisig', $sisigTurn1['specific_dishes'], true) && in_array('jacinto', $sisigTurn1['areas'], true), 'Filipino -an venue query extracts specific dish (sisig) and street (jacinto)');
assertCheck(
    placeMatchesSpecificDishes(['name'=>'Dabaw\'s Sisig Corner - Roxas Ave.', 'food_type'=>'Sisig', 'description'=>''], ['sisig']) === true
    && placeMatchesSpecificDishes(['name'=>'HYGGE Coffee - Jacinto', 'food_type'=>'Coffee', 'description'=>'Specialty cafe'], ['sisig']) === false,
    'Specific dish verifier distinguishes sisigans from non-sisig Jacinto cafes'
);
assertCheck(
    evaluateDavaoAreaProximity(['name'=>'HYGGE Coffee - Jacinto', 'address'=>'Emilio Jacinto St'], ['jacinto']) === 'exact'
    && evaluateDavaoAreaProximity(['name'=>'Dabaw\'s Sisig Corner - Roxas Ave.', 'address'=>'Roxas Ave, Poblacion'], ['jacinto']) === 'nearby'
    && evaluateDavaoAreaProximity(['name'=>'Kapitan Sisig - Toril', 'address'=>'Toril, Davao City'], ['jacinto']) === 'none',
    'Davao urban proximity distinguishes exact street, adjacent Downtown street, and distant Toril district'
);
$sisigHistory = [
    ['role'=>'user', 'content'=>'sisigan near jacinto street'],
    ['role'=>'assistant', 'content'=>'Check out HYGGE Coffee - Jacinto and Urban Pizza - Jacinto.'],
];
$sisigTurn2 = parseQueryIntent("those places don't reallty have sisig in them reportedly", $sisigHistory, ['HYGGE Coffee - Jacinto', 'Urban Pizza - Jacinto']);
assertCheck(
    $sisigTurn2['is_followup'] === true
    && ($sisigTurn2['user_correction'] ?? false) === true
    && $sisigTurn2['exclude_shown'] === true
    && in_array('jacinto', $sisigTurn2['areas'], true)
    && in_array('sisig', $sisigTurn2['specific_dishes'], true),
    'Multi-turn user correction preserves prior street and dish layers while excluding rejected places'
);
$sisigHistory3 = array_merge($sisigHistory, [
    ['role'=>'user', 'content'=>"those places don't reallty have sisig in them reportedly"],
    ['role'=>'assistant', 'content'=>'Try Dabaw\'s Sisig Corner on Roxas Ave right beside Jacinto.'],
]);
$sisigTurn3 = parseQueryIntent('any under ₱150?', $sisigHistory3, ['HYGGE Coffee - Jacinto']);
assertCheck(
    in_array('jacinto', $sisigTurn3['areas'], true)
    && in_array('sisig', $sisigTurn3['specific_dishes'], true)
    && (float)($sisigTurn3['max_price_php'] ?? 0) === 150.0,
    'Three-turn conversation retains street, dish, and budget ceiling simultaneously'
);

$unfilteredReviewFallback = evidencePlacesFallback(['results'=>[
    [
        'title'=>'KFC chicken reviews Davao City',
        'url'=>'https://example.com/kfc-reviews',
        'text'=>'Customers discuss chicken at this Davao City restaurant.',
        'review_search'=>true,
    ],
]]);
assertCheck(
    $unfilteredReviewFallback === [],
    'Raw review-focused results are not recommended when sentiment analysis is unavailable'
);

$articleEvidence = [
    'results'=>[
        ['title'=>'Order from the best Chicken restaurants', 'url'=>'https://example.com/order-chicken', 'text'=>'Davao City chicken delivery'],
        ['title'=>'The 19 best spots for fried chicken', 'url'=>'https://example.com/best-fried-chicken', 'text'=>'Davao City fried chicken guide'],
        ['title'=>'KFC - SM City Davao', 'url'=>'https://example.com/kfc', 'text'=>'Davao City chicken restaurant', 'source_type'=>'blog_search_result', 'source_access'=>'source_page'],
    ],
];
assertCheck(
    isLikelyRestaurantArticleTitle('Order from the best Chicken restaurants')
    && isLikelyRestaurantArticleTitle('The 19 best spots for fried chicken')
    && isLikelyRestaurantArticleTitle('THE BEST 10 BURGERS')
    && isLikelyRestaurantArticleTitle('Best Burgers in Davao City Diversion Rd')
    && isLikelyRestaurantArticleTitle('10 Restaurants to Try in Davao City')
    && isLikelyRestaurantArticleTitle('A Guide to the Best Burger Spots in Davao')
    && !isLikelyRestaurantArticleTitle('KFC - SM City Davao'),
    'Article title filter distinguishes listicle headlines from restaurant names'
);
assertCheck(
    array_column(evidencePlacesFallback($articleEvidence), 'name') === ['KFC - SM City Davao'],
    'Raw web fallback returns the restaurant, not article headlines'
);
$onlineFallbackEvidence = [
    'sources'=>[['title'=>'Davao Burger Results', 'url'=>'https://example.com/davao-burgers']],
    'results'=>[
        ['title'=>'Burger Stop Davao City', 'url'=>'https://example.com/davao-burgers', 'text'=>'A Davao City restaurant serving burgers and cheeseburgers.'],
        ['title'=>'Chicken House Davao City', 'url'=>'https://example.com/davao-chicken', 'text'=>'A Davao City restaurant serving fried chicken.'],
    ],
];
$burgerOnlineFallback = sourceBackedOnlineFallback($onlineFallbackEvidence, parseQueryIntent('cheese burger'));
$burgerSteakOnlineFallback = sourceBackedOnlineFallback([
    'sources'=>[['title'=>'Burger steak places in Davao City', 'url'=>'https://www.yelp.com/search?find_desc=burger+steak&find_loc=Davao+City']],
    'results'=>[[
        'title'=>'Burger steak places in Davao City - Yelp',
        'url'=>'https://www.yelp.com/search?find_desc=burger+steak&find_loc=Davao+City',
        'text'=>'Philippines - Yelp - Backyard Burgers, Burger Chef, Red Corner Burger Cafe',
    ]],
], parseQueryIntent('burger steak'));
$failedHybridFallback = answerWithHybridPipeline(
    'cheese burger',
    [],
    [],
    $onlineFallbackEvidence,
    ['intent'=>array_merge(parseQueryIntent('cheese burger'), ['planner_used'=>false])]
);
$biryaniOnlineFallback = sourceBackedOnlineFallback([
    'sources'=>[['title'=>'Chicken restaurant Davao', 'url'=>'https://example.com/chicken']],
    'results'=>[
        ['title'=>'Chicken House Davao City', 'url'=>'https://example.com/chicken', 'text'=>'A Davao City chicken restaurant.'],
    ],
], parseQueryIntent('chicken biryani'));
assertCheck(
    array_column($burgerOnlineFallback, 'name') === ['Burger Stop']
    && $burgerOnlineFallback[0]['source_url'] === 'https://example.com/davao-burgers'
    && $burgerSteakOnlineFallback === []
    && $failedHybridFallback[2] === 'error'
    && array_column($failedHybridFallback[4], 'name') === ['Burger Stop']
    && $biryaniOnlineFallback === [],
    'Hybrid pipeline failure preserves source-backed online finds relevant to the requested food'
);
assertCheck(
    sanitizeWebPlaces([
        ['name'=>'Order from the best Chicken restaurants', 'city'=>'Davao City', 'source_url'=>'https://example.com/order-chicken'],
        ['name'=>'The 19 best spots for fried chicken', 'city'=>'Davao City', 'source_url'=>'https://example.com/best-fried-chicken'],
        ['name'=>'KFC - SM City Davao', 'city'=>'Davao City', 'source_url'=>'https://example.com/kfc'],
    ], $articleEvidence) === [
        [
            'name'=>'KFC - SM City Davao',
            'food_type'=>'',
            'rating'=>'',
            'review_count'=>'',
            'price_range'=>'',
            'address'=>'',
            'city'=>'Davao City',
            'description'=>'',
            'source_url'=>'https://example.com/kfc',
            'source_type'=>'blog_search_result',
            'source_access'=>'source_page',
            'unsupported'=>false,
            'source_status'=>'supported',
        ],
    ],
    'AI-structured web results reject listicle titles while retaining venue listings'
);

$burgerSearchEvidence = [
    'results'=>[
        [
            'title'=>'THE BEST 10 BURGERS in DAVAO CITY - Yelp',
            'url'=>'https://www.yelp.com/search?cflt=burgers&find_loc=Davao+City',
            'text'=>'Search snippet: Best Burgers in Davao City - Backyard Burgers, Burger Chef',
        ],
    ],
];
$burgerSearchPlaces = sanitizeWebPlaces([
    ['name'=>'THE BEST 10 BURGERS', 'food_type'=>'Coffee / Cafe', 'city'=>'Davao City', 'source_url'=>'https://www.yelp.com/search?cflt=burgers&find_loc=Davao+City'],
    ['name'=>'Backyard Burgers', 'food_type'=>'Burgers', 'city'=>'Davao City', 'source_url'=>'https://www.yelp.com/search?cflt=burgers&find_loc=Davao+City'],
    ['name'=>'Burger Chef', 'food_type'=>'Burgers', 'city'=>'Davao City', 'source_url'=>'https://www.yelp.com/search?cflt=burgers&find_loc=Davao+City'],
], $burgerSearchEvidence);
assertCheck(
    array_column($burgerSearchPlaces, 'name') === ['Backyard Burgers', 'Burger Chef'],
    'Yelp category headlines are rejected while named restaurant results are retained'
);

$reviewProfileEvidence = [
    'results'=>[
        [
            'title'=>'CONCHING\'S NATIVE CHICKEN, Davao City - Restaurant Reviews - Tripadvisor',
            'url'=>'https://www.tripadvisor.com/Restaurant_Review-g298459-d8465622-Reviews-Conching_s_Native_Chicken-Davao_City_Davao_del_Sur.html',
            'text'=>'Search snippet: I really love the fact that we are eating not only chicken, but the best native chicken grilled in town.',
            'review_search'=>true,
            'review_query'=>'Davao City chicken restaurants customer reviews',
        ],
        [
            'title'=>'Negative Chicken Restaurant, Davao City - Restaurant Reviews - Tripadvisor',
            'url'=>'https://www.tripadvisor.com/Restaurant_Review-negative-chicken',
            'text'=>'Search snippet: The chicken was dry and disappointing.',
            'review_search'=>true,
            'review_query'=>'Davao City chicken restaurants customer reviews',
        ],
        [
            'title'=>'THE BEST 10 CHICKEN SHOPS in DAVAO CITY - Yelp',
            'url'=>'https://www.yelp.com/search?cflt=chickenshop&find_loc=Davao+City',
            'text'=>'Search snippet: The chicken was delicious.',
            'review_search'=>true,
            'review_query'=>'Davao City chicken restaurants customer reviews',
        ],
    ],
];
assertCheck(
    array_column(evidencePlacesFallback($reviewProfileEvidence), 'name') === ['CONCHING\'S NATIVE CHICKEN'],
    'Fallback includes positive food-specific venue reviews, excluding negative reviews and review listicles'
);

$yelpDirectoryEvidence = [
    'results'=>[
        [
            'title'=>'THE BEST 10 CHICKEN SHOPS IN DAVAO CITY - Yelp',
            'url'=>'https://www.yelp.com/search?cflt=chickenshop&find_loc=Davao+City',
            'text'=>'Search snippet: Best Chicken Shop in Davao City, Philippines - Last Updated November 2026 - Rophe\'s Chicken, BonChon, Wings and Dips, Max\'s Restaurant, KFC, Jollibee',
        ],
        [
            'title'=>'TOP 10 BEST Cafes in Davao City - Yelp',
            'url'=>'https://www.yelp.com/search?find_desc=Cafes&find_loc=Davao+City',
            'text'=>'Search snippet: Top 10 Best Cafes in Davao City, Philippines - Last Updated November 2026 - Yelp - Coffee Cat, Starbucks Coffee, Bo\'s Coffee',
        ],
    ],
];
$chickenDirectoryPlaces = extractYelpDirectoryVenues($yelpDirectoryEvidence, parseQueryIntent('chicken'));
$coffeeDirectoryPlaces = extractYelpDirectoryVenues($yelpDirectoryEvidence, parseQueryIntent('coffee'));
assertCheck(
    array_column($chickenDirectoryPlaces, 'name') === ['Rophe\'s Chicken', 'BonChon', 'Wings and Dips', 'Max\'s Restaurant', 'KFC', 'Jollibee']
    && array_column($coffeeDirectoryPlaces, 'name') === ['Coffee Cat', 'Starbucks Coffee', 'Bo\'s Coffee'],
    'Category directory snippets produce individual restaurant matches for different food requests'
);
assertCheck(
    count(sanitizeWebPlaces($chickenDirectoryPlaces, $yelpDirectoryEvidence)) === 6
    && ($chickenDirectoryPlaces[4]['rating'] ?? null) === ''
    && str_contains($chickenDirectoryPlaces[4]['description'], 'does not verify'),
    'Directory matches remain source-linked without invented ratings or menu claims'
);

$hybridIntent = ['planner_used'=>true];
$hybridEvidence = [
    'success'=>true,
    'browser_used'=>true,
    'browser_result_count'=>2,
    'results'=>[['url'=>'https://example.com/davao-food']],
];
$hybridStages = ['3_gpt4o_structurer'=>'ok', '4_deepseek_r1_reasoner'=>'ok'];
assertCheck(
    hybridPipelineReadyForFinalResponse($hybridIntent, $hybridEvidence, $hybridStages, true),
    'permits GPT final response only after planner, Playwright, structurer, and DeepSeek R1 stages'
);
$hybridStages['4_deepseek_r1_reasoner'] = 'unavailable';
assertCheck(
    !hybridPipelineReadyForFinalResponse($hybridIntent, $hybridEvidence, $hybridStages, false),
    'blocks GPT final response when DeepSeek R1 did not reason successfully'
);
$hybridEvidence['browser_used'] = false;
assertCheck(
    !hybridPipelineReadyForFinalResponse($hybridIntent, $hybridEvidence, $hybridStages, false),
    'blocks the final response when retrieval did not run in a Playwright browser'
);
assertCheck(
    shouldAllowLocalDatasetFallback('hybrid', false, 'error')
    && !shouldAllowLocalDatasetFallback('hybrid', true, 'error')
    && shouldAllowLocalDatasetFallback('hybrid', true, 'ready'),
    'shows local fallback for interactive hybrid failures while preserving strict evaluation failures'
);
assertCheck(
    isExpectedHybridReasonerModel('deepseek-r1:8b', 'local_ollama')
    && !isExpectedHybridReasonerModel('deepseek-r1:1.5b', 'local_ollama')
    && isExpectedHybridReasonerModel('deepseek-flash', 'hosted_api')
    && !isExpectedHybridReasonerModel('deepseek-reasoner', 'hosted_api'),
    'accepts local R1 8B or a supported paid DeepSeek model for the reasoner stage'
);
assertCheck(
    defined('DEEPSEEK_NUM_CTX') && DEEPSEEK_NUM_CTX >= 4096,
    'uses Ollama default context unless a larger value is explicitly configured'
);
$compactReasonerEvidence = buildHybridReasonerEvidence([[
    'name'=>'Test Burger Place',
    'description'=>str_repeat('Davao burger evidence ', 20),
    'source_url'=>'https://example.com/place',
]]);
assertCheck(
    count($compactReasonerEvidence) === 1
    && strlen($compactReasonerEvidence[0]['description']) <= 180
    && $compactReasonerEvidence[0]['source_url'] === 'https://example.com/place'
    && $compactReasonerEvidence[0]['city'] === '',
    'bounds local reasoner evidence fields while retaining exact source links'
);

echo "Unit & Integration Suite: {$passed} passed, {$failed} failed.\n";
exit($failed > 0 ? 1 : 0);
