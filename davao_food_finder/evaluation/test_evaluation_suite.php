<?php
declare(strict_types=1);

$appRoot = dirname(__DIR__);
require_once $appRoot . '/vendor/autoload.php';
require_once $appRoot . '/src/search.php';
require_once $appRoot . '/src/web_reasoner.php';
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

echo "Unit & Integration Suite: {$passed} passed, {$failed} failed.\n";
exit($failed > 0 ? 1 : 0);
