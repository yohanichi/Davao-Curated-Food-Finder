<?php
declare(strict_types=1);

$appRoot = dirname(__DIR__);
require_once $appRoot . '/vendor/autoload.php';
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
    && $nearbyMapResults[0]['rating'] === null,
    'Near-me results identify distinct chain branches by their OSM street and district without borrowing ratings');
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
assertApprox(nearbyDistanceKm(0.0, 0.0, 0.0, 1.0), 111.195, 0.01, 'Haversine distance uses kilometers');
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
        ['title'=>'KFC - SM City Davao', 'url'=>'https://example.com/kfc', 'text'=>'Davao City chicken restaurant'],
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

echo "Unit & Integration Suite: {$passed} passed, {$failed} failed.\n";
exit($failed > 0 ? 1 : 0);
