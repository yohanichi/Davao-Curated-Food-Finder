<?php
declare(strict_types=1);
ob_start();
set_time_limit(defined('TIMEOUT_PHP_MAX_EXECUTION') ? TIMEOUT_PHP_MAX_EXECUTION : 300);
@ini_set('max_execution_time', (string)(defined('TIMEOUT_PHP_MAX_EXECUTION') ? TIMEOUT_PHP_MAX_EXECUTION : 300));
if (session_status() === PHP_SESSION_NONE && PHP_SAPI !== 'cli') {
    session_start();
}
$streamSearchProgress = str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/x-ndjson');
if (!headers_sent()) {
    header($streamSearchProgress ? 'Content-Type: application/x-ndjson; charset=utf-8' : 'Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    if ($streamSearchProgress) {
        header('X-Accel-Buffering: no');
    }
}
$GLOBALS['streamSearchProgress'] = $streamSearchProgress;
$deepseekBackendHeader = strtolower(trim((string)($_SERVER['HTTP_X_DFF_DEEPSEEK_BACKEND'] ?? '')));
$isLoopbackRequest = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
if (!$isLoopbackRequest || !in_array($deepseekBackendHeader, ['local-ollama', 'hosted-api'], true)) {
    unset($_SERVER['HTTP_X_DFF_DEEPSEEK_BACKEND']);
    $deepseekBackendHeader = '';
}
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/config.php';
require_once __DIR__ . '/src/search.php';
require_once __DIR__ . '/src/web_reasoner.php';
require_once __DIR__ . '/src/nearby.php';
set_time_limit(TIMEOUT_PHP_MAX_EXECUTION);
@ini_set('max_execution_time', (string)TIMEOUT_PHP_MAX_EXECUTION);

try {
    $requestStartedAt = microtime(true);
    resetAiTelemetry();
    $GLOBALS['providerErrors'] = [];
    if (DEEPSEEK_BACKEND === 'hosted_api' && DEEPSEEK_API_KEY === '') {
        http_response_code(503);
        throw new RuntimeException('The paid DeepSeek API is the active backend, but DEEPSEEK_API_KEY is not configured.');
    }

    // Fallback: propagate API keys from incoming headers if server environment lacks them
    if (empty(getenv('DEEPSEEK_API_KEY')) && !empty($_SERVER['HTTP_X_DEEPSEEK_API_KEY'])) {
        $inDs = trim((string)$_SERVER['HTTP_X_DEEPSEEK_API_KEY']);
        putenv("DEEPSEEK_API_KEY={$inDs}");
        $_ENV['DEEPSEEK_API_KEY'] = $inDs;
        $_SERVER['DEEPSEEK_API_KEY'] = $inDs;
    }
    if (empty(getenv('OPENAI_API_KEY')) && !empty($_SERVER['HTTP_X_OPENAI_API_KEY'])) {
        $inOa = trim((string)$_SERVER['HTTP_X_OPENAI_API_KEY']);
        putenv("OPENAI_API_KEY={$inOa}");
        $_ENV['OPENAI_API_KEY'] = $inOa;
        $_SERVER['OPENAI_API_KEY'] = $inOa;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); throw new RuntimeException('Use POST for searches.'); }
    $rawInput = file_get_contents('php://input');
    if (($rawInput === '' || $rawInput === false) && PHP_SAPI === 'cli') {
        $rawInput = stream_get_contents(STDIN);
    }
    $body = json_decode((string)$rawInput, true) ?: [];
    if (($body['action'] ?? '') === 'load_nearby_results_more') {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            http_response_code(503);
            throw new RuntimeException('Nearby results are not available in this session. Run the search again.');
        }
        $token = (string)($body['token'] ?? '');
        if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
            http_response_code(422);
            throw new RuntimeException('The nearby results page token is invalid.');
        }
        $offset = filter_var($body['offset'] ?? null, FILTER_VALIDATE_INT);
        if ($offset === false || $offset < 0) {
            http_response_code(422);
            throw new RuntimeException('The nearby results page offset is invalid.');
        }
        $savedPage = $_SESSION['nearby_result_pages'][$token] ?? null;
        if (!is_array($savedPage)
            || (int)($savedPage['expires_at'] ?? 0) < time()
            || !is_array($savedPage['places'] ?? null)) {
            unset($_SESSION['nearby_result_pages'][$token]);
            http_response_code(410);
            throw new RuntimeException('These nearby results have expired. Run the search again to refresh them.');
        }
        $allPlaces = $savedPage['places'];
        if ($offset > count($allPlaces)) {
            http_response_code(422);
            throw new RuntimeException('The nearby results page offset is out of range.');
        }
        $places = array_slice($allPlaces, $offset, 20);
        $nextOffset = $offset + count($places);
        if (ob_get_level() > 0) ob_clean();
        echo json_encode([
            'places'=>$places,
            'nextOffset'=>$nextOffset,
            'hasMore'=>$nextOffset < count($allPlaces),
            'totalCount'=>count($allPlaces),
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
    $query = trim((string)($body['query'] ?? ''));
    $history = is_array($body['history'] ?? null) ? array_slice($body['history'], -12) : [];
    $shownNames = is_array($body['shownNames'] ?? null) ? array_slice($body['shownNames'], 0, 50) : [];
    if ($query === '' || mb_strlen($query) > 300) { http_response_code(422); throw new RuntimeException('Enter a search query up to 300 characters.'); }
    if (!isset($body['evaluation'])) {
        $queryCorrection = suggestSearchQueryTypo($query);
        if ($queryCorrection !== null) {
            if (ob_get_level() > 0) ob_clean();
            $correctionResponse = [
                'answer'=>'Did you mean “' . $queryCorrection['suggested_term'] . '”?',
                'assistantStatus'=>'clarification',
                'modelUsed'=>null,
                'outOfScope'=>false,
                'sources'=>[],
                'webResults'=>[],
                'mapResults'=>[],
                'results'=>[],
                'count'=>0,
                'queryCorrection'=>$queryCorrection,
                'locationSearch'=>['enabled'=>false],
                'metrics'=>['pipeline'=>['stages'=>[]]],
            ];
            echo json_encode($streamSearchProgress
                ? ['type'=>'result', 'data'=>$correctionResponse]
                : $correctionResponse, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($streamSearchProgress) echo "\n";
            exit;
        }
    }
    $nearbyLocation = null;
    $nearbySearchArea = null;
    $nearbyMapPlaces = [];
    $nearbyMapSnapshotMetadata = null;
    $nearbyRelevantCandidates = [];
    $moreNearbyResults = [];
    $mapFindResults = [];
    $mapFindSearch = [
        'status'=>'not_applicable',
        'source'=>'OpenStreetMap local snapshot',
        'search_type'=>'category',
        'snapshot_updated_at'=>null,
        'result_count'=>0,
    ];
    if (array_key_exists('location', $body)) {
        $location = $body['location'];
        if (!is_array($location)
            || !is_numeric($location['latitude'] ?? null)
            || !is_numeric($location['longitude'] ?? null)) {
            http_response_code(422);
            throw new RuntimeException('A valid browser location is required for near-me searches.');
        }
        $latitude = (float)$location['latitude'];
        $longitude = (float)$location['longitude'];
        if (!is_finite($latitude) || !is_finite($longitude)
            || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            http_response_code(422);
            throw new RuntimeException('The browser location coordinates are outside valid ranges.');
        }
        $accuracyMeters = 0.0;
        if (array_key_exists('accuracy_m', $location)) {
            if (!is_numeric($location['accuracy_m'])) {
                http_response_code(422);
                throw new RuntimeException('A valid browser location accuracy is required for near-me searches.');
            }
            $accuracyMeters = (float)$location['accuracy_m'];
            if (!is_finite($accuracyMeters) || $accuracyMeters < 0 || $accuracyMeters > 1000) {
                http_response_code(422);
                throw new RuntimeException('Your location is too imprecise to verify places within 3 km. Try again with device location enabled.');
            }
        }
        $nearbyLocation = [
            'latitude'=>$latitude,
            'longitude'=>$longitude,
            'accuracy_km'=>$accuracyMeters / 1000,
        ];
        emitSearchProgress('Checking mapped places near your location…');
        $nearbySearchArea = nearbySearchBounds($latitude, $longitude, DAVAO_NEARBY_RADIUS_KM);
        $nearbyMapSnapshotMetadata = loadLocalOsmNearbySnapshot(
            __DIR__ . '/data/osm-nearby-places.json',
            $nearbySearchArea
        );
        $nearbyMapPlaces = $nearbyMapSnapshotMetadata['places'];
    }

    $openAIOptions = ['temperature'=>0.2, 'promptStyle'=>'baseline', 'agentic'=>true, 'iterativeLoop'=>true, 'seed'=>null];
    $reasoningProvider = 'hybrid';
    $cachePolicy = 'bypass';
    $evidenceOverride = null;

    if (isset($body['evaluation'])) {
        if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
            http_response_code(403);
            throw new RuntimeException('Evaluation configuration is only available to local requests.');
        }
        $evaluation = $body['evaluation'];
        if (!is_array($evaluation)) {
            http_response_code(422);
            throw new RuntimeException('Invalid evaluation configuration.');
        }
        $temperature = $evaluation['temperature'] ?? 0.2;
        $promptStyle = $evaluation['promptStyle'] ?? 'baseline';
        $provider = strtolower(trim((string)($evaluation['provider'] ?? 'deepseek')));
        $agenticFlag = isset($evaluation['agentic']) ? (bool)$evaluation['agentic'] : !in_array($provider, ['non-agentic', 'baseline'], true);
        $iterativeFlag = isset($evaluation['iterativeLoop']) ? (bool)$evaluation['iterativeLoop'] : !in_array($provider, ['baseline', 'non-agentic', 'ablation-no-loop'], true);
        $requestedCachePolicy = strtolower(trim((string)($evaluation['cachePolicy'] ?? 'default')));
        $seed = isset($evaluation['seed']) && is_numeric($evaluation['seed']) ? (int)$evaluation['seed'] : null;
        if (isset($evaluation['evidenceOverride']) && is_array($evaluation['evidenceOverride'])) {
            $evidenceOverride = $evaluation['evidenceOverride'];
        }
        if (!empty($evaluation['deepseek_api_key'])) {
            $inDs = trim((string)$evaluation['deepseek_api_key']);
            putenv("DEEPSEEK_API_KEY={$inDs}");
            $_ENV['DEEPSEEK_API_KEY'] = $inDs;
            $_SERVER['DEEPSEEK_API_KEY'] = $inDs;
        }
        if (!empty($evaluation['openai_api_key'])) {
            $inOa = trim((string)$evaluation['openai_api_key']);
            putenv("OPENAI_API_KEY={$inOa}");
            $_ENV['OPENAI_API_KEY'] = $inOa;
            $_SERVER['OPENAI_API_KEY'] = $inOa;
        }

        if (!is_numeric($temperature) || (float)$temperature < 0 || (float)$temperature > 2
            || !in_array($promptStyle, ['baseline', 'strict_grounding'], true)
            || !in_array($provider, ['baseline', 'ablation-no-loop', 'agentic-local', 'deepseek', 'deepseek-api', 'gpt-4o-mini', 'hybrid', 'non-agentic'], true)
            || !in_array($requestedCachePolicy, ['default', 'cold', 'bypass', 'warm', 'none'], true)) {
            http_response_code(422);
            throw new RuntimeException('Invalid evaluation configuration.');
        }
        $openAIOptions = [
            'temperature'=>(float)$temperature,
            'promptStyle'=>$promptStyle,
            'agentic'=>$agenticFlag,
            'iterativeLoop'=>$iterativeFlag,
            'seed'=>$seed,
        ];
        $reasoningProvider = $provider;
        $cachePolicy = $requestedCachePolicy;
    }

    emitSearchProgress('Loading Davao restaurant listings and applying your filters…');

    // Component Timing 1: Dataset load & places cache check
    $datasetLoadStart = microtime(true);
    $cacheFile = __DIR__ . '/data/.places-cache.json';
    $signature = datasetSignature(__DIR__ . '/data');
    $places = [];
    $placesCacheState = 'miss';
    if (is_file($cacheFile)) {
        $cache = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cache)
            && ($cache['signature'] ?? '') === $signature
            && (int)($cache['schema_version'] ?? 1) === PLACES_CACHE_SCHEMA_VERSION
            && is_array($cache['places'] ?? null)) {
            $places = $cache['places'];
            $placesCacheState = 'hit';
        }
    }
    if (!$places) {
        $places = loadDavaoPlaces(__DIR__ . '/data');
        @file_put_contents($cacheFile, json_encode([
            'schema_version'=>PLACES_CACHE_SCHEMA_VERSION,
            'signature'=>$signature,
            'places'=>$places,
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), LOCK_EX);
    }
    $datasetLoadMs = (int)round((microtime(true) - $datasetLoadStart) * 1000);
    if (!$places) throw new RuntimeException('No Davao City records found. Confirm that your CSV files are in data/ and that their city field contains Davao City.');

    $requestedRestaurantCore = detectExplicitRestaurantCoreQuery($places, $query);
    if ($requestedRestaurantCore !== null) {
        $history = [];
        $shownNames = [];
    }

    // Component Timing 2: Intent parsing + Stage 1 GPT-4o mini Query Planner (in hybrid mode)
    $retrievalStart = microtime(true);
    $isAgentic = ($openAIOptions['agentic'] ?? true) === true && !in_array($reasoningProvider, ['baseline', 'non-agentic'], true);
    $useIterativeLoop = ($openAIOptions['iterativeLoop'] ?? true) === true && $isAgentic && $reasoningProvider !== 'ablation-no-loop';
    $intent = parseQueryIntent($query, $history, $shownNames);
    $nearbyExperienceRequested = nearbyIntentRequiresEvidence($intent);
    $fastFoodItems = is_array($intent['fast_food_items'] ?? null) ? $intent['fast_food_items'] : [];
    $matchesFastFoodIntent = static fn(array $place): bool =>
        !$fastFoodItems || nearbyFeatureMatchesRequest($place, $query, $intent);
    if ($requestedRestaurantCore !== null) {
        $intent['requested_restaurant_core'] = $requestedRestaurantCore;
    }
    $contextHistory = !empty($intent['is_followup']) ? $history : [];
    $contextShownNames = !empty($intent['exclude_shown']) ? $shownNames : [];
    $intentParseMs = (int)round((microtime(true) - $retrievalStart) * 1000);
    emitSearchProgress('Understanding your request and refining the search…');

    $hasFoodRequest = !empty($intent['cuisines'])
        || !empty($intent['specific_dishes'])
        || preg_match('/\b(food|restaurants?|caf[eé]s?|dining|eat|meals?|places?|spots?)\b/iu', $query) === 1;
    if (!isset($body['evaluation']) && !$intent['out_of_scope'] && $hasFoodRequest && shouldAskPriceClarification($query, $intent)) {
        if (ob_get_level() > 0) ob_clean();
        $clarificationResponse = [
            'answer'=>'Before I find places, what price range works for you? Choose budget-friendly, mid-range, or upscale—or share your budget per person (for example, ₱500).',
            'assistantStatus'=>'clarification',
            'priceClarification'=>true,
            'modelUsed'=>null,
            'outOfScope'=>false,
            'sources'=>[],
            'webResults'=>[],
            'results'=>[],
            'count'=>0,
            'metrics'=>['pipeline'=>['stages'=>[]]],
        ];
        echo json_encode($streamSearchProgress
            ? ['type'=>'result', 'data'=>$clarificationResponse]
            : $clarificationResponse, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($streamSearchProgress) echo "\n";
        exit;
    }

    if ($reasoningProvider === 'hybrid' && $isAgentic) {
        $intent = planSearchQueryWithGPT4oMini($query, $contextHistory, $contextShownNames, $intent, $openAIOptions);
    }
    if ($requestedRestaurantCore !== null) {
        $intent['requested_restaurant_core'] = $requestedRestaurantCore;
    }
    $nearbyExperienceRequested = nearbyIntentRequiresEvidence($intent);
    $nearbyAlternativePlan = $nearbyLocation !== null
        ? nearbyExperienceAlternativePlan($query, $intent)
        : null;
    $openAIOptions['intent'] = $intent;

    // Component Timing 3 / Stage 2: Playwright active web scraping (or local volatility test snapshot override)
    if (!$intent['out_of_scope']) {
        emitSearchProgress('Checking online sources; a recent saved search may be used…');
    }
    if ($evidenceOverride !== null) {
        $evidence = [
            'results'=>is_array($evidenceOverride['results'] ?? null) ? $evidenceOverride['results'] : [],
            'sources'=>is_array($evidenceOverride['sources'] ?? null) ? $evidenceOverride['sources'] : [],
            'cache'=>(string)($evidenceOverride['cache'] ?? 'injected_volatility_snapshot'),
            'cache_age_seconds'=>isset($evidenceOverride['cache_age_seconds']) ? (int)$evidenceOverride['cache_age_seconds'] : 0,
            'cached_at'=>isset($evidenceOverride['cached_at']) ? (int)$evidenceOverride['cached_at'] : time(),
            'success'=>!empty($evidenceOverride['results']),
            'latency_ms'=>(int)($evidenceOverride['latency_ms'] ?? 0),
        ];
    } elseif (($reasoningProvider === 'baseline' || $reasoningProvider === 'non-agentic' || !$isAgentic) && $nearbyLocation === null) {
        $evidence = collectPlaywrightEvidence($query, __DIR__ . '/data/.web-cache', 43200, 'none');
    } elseif ($intent['out_of_scope']) {
        $evidence = ['results'=>[], 'sources'=>[], 'cache'=>'skipped_out_of_scope', 'cache_age_seconds'=>null, 'cached_at'=>null, 'success'=>false, 'latency_ms'=>0];
    } else {
        $plannedPwQuery = trim((string)($intent['playwright_query'] ?? ''));
        $priceLookupQueries = [];
        if ($nearbyLocation !== null) {
            $mapSearchQuery = $intent['effective_query'] !== '' ? $intent['effective_query'] : $query;
            $mapSearchCandidates = shortlistPlaces($places, $mapSearchQuery, 100, $intent);
            if ($requestedRestaurantCore !== null) {
                $mapSearchCandidates = filterPlacesByRestaurantCore($mapSearchCandidates, $requestedRestaurantCore);
            }
            $nearbyRelevantCandidates = $mapSearchCandidates;
        }
        $usesItemPriceCeiling = ($intent['price_scope'] ?? '') === 'menu_item';
        if ($isAgentic
            && !isset($body['evaluation'])
            && isset($intent['max_price_php'])
            && is_numeric($intent['max_price_php'])
            && ($usesItemPriceCeiling || ($useIterativeLoop && empty($intent['online_only'])))) {
            $priceLookupQueries = buildPriceLookupQueries($places, $intent, !empty($intent['online_only']), $query);
        }
        if ($plannedPwQuery !== '') {
            $evidence = collectPlaywrightEvidence(expandMenuItemSearchQuery($plannedPwQuery, $intent), __DIR__ . '/data/.web-cache', 43200, $cachePolicy, $priceLookupQueries);
        } else {
            $pwQuery = $intent['effective_query'] !== '' ? $intent['effective_query'] : $query;
            $evidenceQuery = preg_match('/\bdavao(\s+city)?\b/i', $pwQuery) ? $pwQuery : ('Davao City ' . $pwQuery);
            $evidence = collectPlaywrightEvidence(expandMenuItemSearchQuery($evidenceQuery, $intent), __DIR__ . '/data/.web-cache', 43200, $cachePolicy, $priceLookupQueries);
        }
    }
    if ($nearbyLocation !== null && !$intent['out_of_scope']) {
        $evidence['nearby_places'] = $nearbyMapPlaces;
    }
    if (isset($GLOBALS['aiTelemetry']['pipeline_trace'])) {
        $GLOBALS['aiTelemetry']['pipeline_trace']['2_playwright_scraper'] = (($evidence['success'] ?? false) === true
            && ($evidence['browser_used'] ?? false) === true
            && (int)($evidence['browser_result_count'] ?? 0) > 0)
            ? 'ok'
            : ((int)($evidence['search_provider_result_count'] ?? 0) > 0
                ? 'search_provider_only'
                : ((string)($evidence['error'] ?? '') !== '' ? 'error' : (string)($evidence['cache'] ?? 'empty')));
    }
    if ($intent['out_of_scope']) {
        emitSearchProgress('Finishing the Davao City scope check…');
    } elseif (($evidence['cache'] ?? '') === 'hit') {
        emitSearchProgress('Using recent saved online results and matching them with local listings…');
    } elseif (($evidence['browser_used'] ?? false) === true) {
        emitSearchProgress('Online search finished; comparing sources with Davao restaurant listings…');
    } elseif (($evidence['cache'] ?? '') === 'error') {
        emitSearchProgress('Online search was unavailable; matching local listings instead…');
    } else {
        emitSearchProgress('Checking available source evidence against Davao restaurant listings…');
    }

    // Reconcile static CSV places with live web evidence for Data Volatility Resilience (when iterative/volatility guard is active)
    $rankStart = microtime(true);
    $volatilityReport = ['evidence_items_scanned'=>0, 'closed_places'=>[], 'price_updates'=>[], 'hours_updates'=>[], 'rating_updates'=>[]];
    if ($isAgentic && !empty($evidence['results']) && ($useIterativeLoop || ($intent['price_scope'] ?? '') === 'menu_item')) {
        $reconciled = reconcileVolatilityWithEvidence($places, $evidence, $intent);
        $places = $reconciled['places'];
        $volatilityReport = $reconciled['report'];
    }

    if ($reasoningProvider === 'baseline' || !$isAgentic) {
        $candidates = shortlistPlacesBaseline($places, $query, 100);
    } else {
        $searchQuery = $intent['effective_query'] !== '' ? $intent['effective_query'] : $query;
        // Interactive chat applies hard dish/volatility/polysemy masks immediately; benchmark evaluation keeps Pass 1 unmasked to measure Pass 2 loop gain
        $applyPass1Masks = !isset($body['evaluation']);
        $candidates = shortlistPlaces($places, $searchQuery, 100, $intent, $applyPass1Masks);
        if ($intent['exclude_shown'] && $shownNames && $useIterativeLoop) {
            $seenNames = [];
            foreach ($shownNames as $sn) {
                $nk = mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', (string)$sn) ?? '', 'UTF-8');
                if ($nk !== '') $seenNames[$nk] = true;
            }
            $candidates = array_values(array_filter($candidates, function ($p) use ($seenNames) {
                $pk = mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', (string)($p['name'] ?? '')) ?? '', 'UTF-8');
                return $pk === '' || !isset($seenNames[$pk]);
            }));
        }
    }
    if ($requestedRestaurantCore !== null) {
        $candidates = filterPlacesByRestaurantCore($candidates, $requestedRestaurantCore);
    }
    $nearbyAlternativeCandidates = [];
    if ($nearbyAlternativePlan !== null) {
        $categoryCandidates = array_values(array_filter(
            $places,
            fn($place) => nearbyFeatureMatchesRequest(
                $place,
                $nearbyAlternativePlan['query'],
                $nearbyAlternativePlan['intent']
            )
        ));
        $nearbyAlternativeCandidates = shortlistPlaces(
            $categoryCandidates,
            $nearbyAlternativePlan['query'],
            100,
            $nearbyAlternativePlan['intent']
        );
        if ($requestedRestaurantCore !== null) {
            $nearbyAlternativeCandidates = filterPlacesByRestaurantCore(
                $nearbyAlternativeCandidates,
                $requestedRestaurantCore
            );
        }
    }
    if ($fastFoodItems) {
        $candidates = array_values(array_filter($candidates, $matchesFastFoodIntent));
        $nearbyRelevantCandidates = array_values(array_filter($nearbyRelevantCandidates, $matchesFastFoodIntent));
    }
    if ($nearbyLocation !== null && $nearbyExperienceRequested) {
        $candidates = array_values(array_filter(
            $candidates,
            fn($place) => nearbyPlaceHasIntentEvidence($place, $intent)
        ));
        $nearbyRelevantCandidates = array_values(array_filter(
            $nearbyRelevantCandidates,
            fn($place) => nearbyPlaceHasIntentEvidence($place, $intent)
        ));
    }
    $nearbySearch = ['enabled'=>false];
    if ($nearbyLocation !== null) {
        $mapPlaces = $nearbyMapPlaces;
        if (!$nearbyRelevantCandidates) $nearbyRelevantCandidates = $candidates;
        if ($requestedRestaurantCore !== null) {
            $nearbyRelevantCandidates = filterPlacesByRestaurantCore($nearbyRelevantCandidates, $requestedRestaurantCore);
        }
        $candidates = rankNearbyCandidates(
            $candidates,
            $places,
            $mapPlaces,
            $nearbyLocation['latitude'],
            $nearbyLocation['longitude'],
            DAVAO_NEARBY_RADIUS_KM,
            $nearbyLocation['accuracy_km'],
            $nearbyExperienceRequested
        );
        $nearbyPlaceIds = [];
        foreach ($candidates as $candidate) {
            if (isset($candidate['_id'])) $nearbyPlaceIds[(string)$candidate['_id']] = $candidate;
        }
        foreach ($places as &$place) {
            $placeId = (string)($place['_id'] ?? '');
            if ($placeId !== '' && isset($nearbyPlaceIds[$placeId])) {
                $place['_nearby_distance_km'] = $nearbyPlaceIds[$placeId]['_nearby_distance_km'];
                $place['_nearby_source_url'] = $nearbyPlaceIds[$placeId]['_nearby_source_url'];
                if (!empty($nearbyPlaceIds[$placeId]['address'])) {
                    $place['address'] = $nearbyPlaceIds[$placeId]['address'];
                }
            }
        }
        unset($place);
        $nearbySearch = [
            'enabled'=>true,
            'radius_km'=>DAVAO_NEARBY_RADIUS_KM,
            'status'=>!$mapPlaces ? 'no_map_data' : ($candidates ? 'ok' : 'no_matching_places_within_radius'),
            'result_count'=>count($candidates),
            'map_source'=>'OpenStreetMap local snapshot',
            'map_snapshot_updated_at'=>$nearbyMapSnapshotMetadata['osm_base_timestamp'] ?? null,
        ];
    }
    $retrievalRankingMs = $intentParseMs + (int)round((microtime(true) - $rankStart) * 1000);

    // Component Timing 4: Reasoning / Inference dispatch + Autonomous Iterative Critique-and-Refinement Loop
    emitSearchProgress('Ranking matching places and checking recommendation details…');
    $aiDispatchStart = microtime(true);
    if ($reasoningProvider === 'baseline') {
        [$answer, $rankedIds, $assistantStatus, $sources, $webResults, $outOfScope, $modelUsed] = answerWithBaseline($query, $candidates, $intent);
    } elseif ($reasoningProvider === 'ablation-no-loop' || $reasoningProvider === 'agentic-local') {
        [$answer, $rankedIds, $assistantStatus, $sources, $webResults, $outOfScope, $modelUsed] = answerWithAgenticLocal($query, $candidates, $evidence, $intent, $volatilityReport);
        if ($reasoningProvider === 'ablation-no-loop') {
            $modelUsed = 'agentic-single-pass-no-loop';
        }
    } elseif ($reasoningProvider === 'gpt-4o-mini') {
        [$answer, $rankedIds, $assistantStatus, $sources, $webResults, $outOfScope, $modelUsed] = answerWithGPT4oMini($query, $contextHistory, $candidates, $evidence, $openAIOptions);
    } elseif ($reasoningProvider === 'deepseek') {
        [$answer, $rankedIds, $assistantStatus, $sources, $webResults, $outOfScope, $modelUsed] = answerWithDeepSeek($query, $contextHistory, $candidates, $evidence, $openAIOptions);
    } elseif ($reasoningProvider === 'deepseek-api') {
        [$answer, $rankedIds, $assistantStatus, $sources, $webResults, $outOfScope, $modelUsed] = answerWithDeepSeekApi($query, $contextHistory, $candidates, $evidence, $openAIOptions);
    } elseif ($reasoningProvider === 'non-agentic') {
        $nonAgenticOpts = $openAIOptions;
        $nonAgenticOpts['agentic'] = false;
        unset($nonAgenticOpts['intent']);
        if (OPENAI_API_KEY !== '') {
            [$answer, $rankedIds, $assistantStatus, $sources, $webResults, $outOfScope, $modelUsed] = answerWithGPT4oMini($query, [], $candidates, $evidence, $nonAgenticOpts);
        } else {
            [$answer, $rankedIds, $assistantStatus, $sources, $webResults, $outOfScope, $modelUsed] = answerWithDeepSeek($query, [], $candidates, $evidence, $nonAgenticOpts);
        }
    } else {
        // 'hybrid' (default production & agentic hybrid benchmark pipeline)
        [$answer, $rankedIds, $assistantStatus, $sources, $webResults, $outOfScope, $modelUsed] = answerWithHybridPipeline($query, $contextHistory, $candidates, $evidence, $openAIOptions);
    }

    // Agentic scope safety net: if deterministic parser found an unambiguous outside city and AI errored or missed it in strict/agentic mode
    if ($isAgentic && $intent['out_of_scope'] && !$outOfScope) {
        $loc = $intent['matched_outside_location'] ?: 'locations outside Davao City';
        $outOfScope = true;
        $rankedIds = [];
        $webResults = [];
        $sources = [];
        if ($answer === '' || $assistantStatus !== 'ready') {
            $answer = "I can only recommend food places in Davao City, so I cannot suggest options in {$loc}. Would you like similar options in Davao City instead?";
        }
    }

    if (!$outOfScope && !empty($evidence['results'])) {
        $directoryPlaces = sanitizeWebPlaces(extractYelpDirectoryVenues($evidence, $intent), $evidence);
        $knownOnlineNames = [];
        foreach ($webResults as $webPlace) {
            $key = mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', (string)($webPlace['name'] ?? '')) ?? '', 'UTF-8');
            if ($key !== '') $knownOnlineNames[$key] = true;
        }
        foreach ($directoryPlaces as $directoryPlace) {
            $key = mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', (string)($directoryPlace['name'] ?? '')) ?? '', 'UTF-8');
            if ($key !== '' && !isset($knownOnlineNames[$key])) {
                $webResults[] = $directoryPlace;
                $knownOnlineNames[$key] = true;
            }
        }
        if (!$outOfScope && ($intent['price_scope'] ?? '') === 'menu_item') {
            $webResults = attachMenuItemPriceEvidenceToPlaces($webResults, $evidence, $intent);
        }
    }

    // Autonomous Iterative Critique-and-Refinement Loop (Pass 2 Verification & Self-Correction)
    $loopTelemetry = [
        'enabled'=>false,
        'iterations'=>0,
        'skipped_reason'=>$assistantStatus !== 'ready'
            ? 'recommendation_failed'
            : (!$useIterativeLoop ? 'disabled' : 'out_of_scope'),
        'initial_compliance_rate'=>null,
        'final_compliance_rate'=>null,
        'violations_detected'=>[],
        'violations_repaired'=>0,
        're_retrieved'=>false,
    ];
    $isInteractiveLlm = !isset($body['evaluation'])
        && $assistantStatus === 'ready'
        && in_array($reasoningProvider, ['deepseek', 'deepseek-api', 'gpt-4o-mini', 'hybrid'], true);
    if ($useIterativeLoop && !$outOfScope && $assistantStatus === 'ready') {
        emitSearchProgress('Verifying the shortlist against your preferences…');
        [$answer, $rankedIds, $webResults, $loopTelemetry] = runAutonomousRefinementLoop(
            $query,
            $answer,
            $rankedIds,
            $webResults,
            $places,
            $intent,
            $volatilityReport,
            $isInteractiveLlm
        );
    }

    $aiWallMs = (int)round((microtime(true) - $aiDispatchStart) * 1000);
    $telemetry = $GLOBALS['aiTelemetry'] ?? [];
    $aiInferenceMs = (int)($telemetry['ai_inference_ms'] ?? 0) ?: (in_array($reasoningProvider, ['baseline', 'ablation-no-loop', 'agentic-local'], true) ? 0 : $aiWallMs);

    if ($outOfScope) {
        $candidates = [];
        $webResults = [];
        $sources = [];
    }
    if ($nearbyLocation !== null && !$outOfScope) {
        $rankedIds = array_values(array_map(
            fn($candidate) => (string)$candidate['_id'],
            array_slice($candidates, 0, 20)
        ));
        if ($nearbyExperienceRequested) {
            $webResults = array_values(array_filter(
                $webResults,
                fn($place) => is_array($place) && onlinePlaceHasIntentEvidence($place, $evidence, $intent)
            ));
        }
        $webResults = rankNearbyWebPlaces(
            $webResults,
            $nearbyMapPlaces,
            $places,
            $nearbyLocation['latitude'],
            $nearbyLocation['longitude'],
            DAVAO_NEARBY_RADIUS_KM,
            $nearbyLocation['accuracy_km'],
            $nearbyExperienceRequested
        );
    }
    if ($requestedRestaurantCore !== null && !$outOfScope) {
        $allowedIds = [];
        foreach ($places as $place) {
            if (restaurantNameMatchesCore((string)($place['name'] ?? ''), $requestedRestaurantCore)) {
                $allowedIds[(string)($place['_id'] ?? '')] = true;
            }
        }
        $rankedIds = array_values(array_filter(
            $rankedIds,
            fn($id) => isset($allowedIds[(string)$id])
        ));
        $candidates = filterPlacesByRestaurantCore($candidates, $requestedRestaurantCore);
        $nearbyRelevantCandidates = filterPlacesByRestaurantCore($nearbyRelevantCandidates, $requestedRestaurantCore);
        $webResults = filterPlacesByRestaurantCore($webResults, $requestedRestaurantCore);
        $sources = [];
    }
    if (!$outOfScope && !isset($body['evaluation']) && !empty($intent['strict_dishes'])) {
        $strictDishes = $intent['strict_dishes'];
        $rankedIds = [];
        $candidates = [];
        $nearbyRelevantCandidates = [];
        $supportedWebResults = [];
        $verifiedDishSources = [];
        foreach ($webResults as $place) {
            if (!is_array($place) || !placeMatchesSpecificDishes($place, $strictDishes)) continue;
            $evidenceStatus = onlinePlaceSpecificDishEvidenceStatus($place, $evidence, $strictDishes);
            if ($evidenceStatus === null) continue;
            $place['dish_evidence_status'] = $evidenceStatus;
            $place['dish_availability_note'] = $evidenceStatus === 'menu_source'
                ? 'A linked menu or ordering page lists this dish; confirm availability at this branch before visiting.'
                : ($evidenceStatus === 'listing_mention'
                    ? 'A business or map listing mentions this dish, but current branch menu availability is not confirmed.'
                    : 'A web source connects this place with the dish, but does not confirm its current menu; check before visiting.');
            $sourceKey = normalizeEvidenceUrlKey((string)($place['source_url'] ?? ''));
            if ($sourceKey !== '') $verifiedDishSources[$sourceKey] = true;
            $supportedWebResults[] = $place;
        }
        $webResults = $supportedWebResults;
        $sources = array_values(array_filter(
            $sources,
            fn($source) => is_array($source)
                && isset($verifiedDishSources[normalizeEvidenceUrlKey((string)($source['url'] ?? ''))])
        ));
    }
    if (!$outOfScope && $fastFoodItems) {
        $eligibleIds = [];
        foreach ($places as $place) {
            $placeId = trim((string)($place['_id'] ?? ''));
            if ($placeId !== '' && $matchesFastFoodIntent($place)) $eligibleIds[$placeId] = true;
        }
        $rankedIds = array_values(array_filter(
            $rankedIds,
            fn($id) => isset($eligibleIds[(string)$id])
        ));
        $candidates = array_values(array_filter($candidates, $matchesFastFoodIntent));
        $nearbyRelevantCandidates = array_values(array_filter($nearbyRelevantCandidates, $matchesFastFoodIntent));
        $webResults = array_values(array_filter(
            $webResults,
            fn($place) => is_array($place) && $matchesFastFoodIntent($place)
        ));
    }
    if (!isset($body['evaluation'])) {
        $retrievedIds = [];
        foreach ($candidates as $candidate) {
            $candidateId = trim((string)($candidate['_id'] ?? ''));
            if ($candidateId !== '') $retrievedIds[$candidateId] = true;
        }
        $rankedIds = array_values(array_filter(
            $rankedIds,
            fn($id) => isset($retrievedIds[(string)$id])
        ));
    }

    $byId = [];
    foreach ($places as $p) $byId[$p['_id']] = $p;
    $results = [];
    $seenResultNames = [];
    foreach ($rankedIds as $id) {
        if (!isset($byId[$id])) continue;
        $normName = mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', (string)$byId[$id]['name']) ?? '', 'UTF-8');
        if ($normName !== '' && isset($seenResultNames[$normName])) continue;
        if ($normName !== '') $seenResultNames[$normName] = true;
        $results[] = publicPlace($byId[$id]);
    }
    $strictEmptyAllowed = (($openAIOptions['promptStyle'] ?? 'baseline') === 'strict_grounding' || !empty($intent['online_only'])) && $assistantStatus === 'ready';
    $usedLocalDatasetFallback = false;
    if (shouldAllowLocalDatasetFallback($reasoningProvider, isset($body['evaluation']), $assistantStatus)
        && !$outOfScope
        && !$results
        && !$webResults
        && !$strictEmptyAllowed
        && empty($intent['online_only'])) {
        $fallbackCap = $isInteractiveLlm || (!isset($body['evaluation']) && $reasoningProvider === 'hybrid') ? 5 : 10;
        foreach (array_slice($candidates, 0, $fallbackCap) as $cand) {
            if (($intent['price_scope'] ?? '') === 'menu_item'
                && (isset($intent['max_price_php']) || isset($intent['min_price_php']))) {
                $itemPrice = getVerifiedMenuItemPricePhp($cand);
                if ($itemPrice === null
                    || (isset($intent['max_price_php']) && $itemPrice['max'] > (float)$intent['max_price_php'])
                    || (isset($intent['min_price_php']) && $itemPrice['max'] < (float)$intent['min_price_php'])) {
                    continue;
                }
            }
            $normName = mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', (string)$cand['name']) ?? '', 'UTF-8');
            if ($normName !== '' && isset($seenResultNames[$normName])) continue;
            if ($normName !== '') $seenResultNames[$normName] = true;
            $results[] = publicPlace($cand);
        }
        $usedLocalDatasetFallback = count($results) > 0;
    }
    if ($usedLocalDatasetFallback && $assistantStatus === 'error' && $reasoningProvider === 'hybrid') {
        $answer = 'Recommendation processing could not complete. These are ranked matches from the local Davao City dataset, not live-verified recommendations.';
    }
    if (!empty($intent['online_only'])) {
        $results = [];
    }

    if ($nearbyLocation !== null) {
        $nearbyItems = array_merge($results, $webResults);
        $nearbySearch['result_count'] = count($nearbyItems);
        if (!$nearbyItems && ($nearbySearch['status'] ?? '') === 'ok') {
            $nearbySearch['status'] = 'no_matching_places_within_radius';
        }
        if (!$nearbyItems) {
            $answer = ($nearbySearch['status'] ?? '') === 'no_map_data'
                ? 'The local OpenStreetMap snapshot contains no mapped food places near you, so no locations can be verified within 3 km. Try another area or refresh the OSM snapshot.'
                : 'I could not verify any restaurants matching your request within 3 km. I have not included distant or unverified places.';
        } else {
            $summaryItems = [];
            foreach (array_slice($nearbyItems, 0, 6) as $nearbyItem) {
                if (!isset($nearbyItem['distance_km']) || !is_numeric($nearbyItem['distance_km'])) continue;
                $summaryItems[] = trim((string)($nearbyItem['name'] ?? 'Restaurant'))
                    . ' (' . number_format((float)$nearbyItem['distance_km'], 1) . ' km)';
            }
            $answer = 'Matching restaurants within 3 km, ordered by approximate distance: '
                . implode(', ', $summaryItems)
                . '. Distances are straight-line estimates; OpenStreetMap coverage may be incomplete.';
        }
    }
    $results = array_slice($results, 0, 100);
    foreach ($results as &$r) {
        if (empty($r['record_id'])) {
            $r['record_id'] = $r['_id'] ?? $r['store_id'] ?? '';
        }
        $r['source_reference'] = $r['record_id'];
        $r['unsupported'] = empty($r['record_id']) && empty($r['source_url']);
        unset($r['_id'], $r['store_id'], $r['source']);
    }
    unset($r);

    $normAlnum = static fn(string $s): string => mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', $s) ?? '', 'UTF-8');
    $normCoreName = static function (string $name) use ($normAlnum): string {
        $core = preg_replace('/\s*(?:[-–|]\s*[^-–|]+|\([^)]*\))\s*$/u', '', trim($name)) ?? trim($name);
        return $normAlnum($core !== '' ? $core : $name);
    };
    $venuesMatch = static function (array $webPlace, array $localPlace, int $localCoreCount = 1) use ($normAlnum, $normCoreName): bool {
        $wName = trim((string)($webPlace['name'] ?? ''));
        $lName = trim((string)($localPlace['name'] ?? ''));
        if ($wName === '' || $lName === '') return false;
        $wFull = $normAlnum($wName);
        $lFull = $normAlnum($lName);
        if ($wFull !== '' && $wFull === $lFull) return true;

        $wCore = $normCoreName($wName);
        $lCore = $normCoreName($lName);
        if ($wCore === '' || mb_strlen($wCore, 'UTF-8') < 4 || $wCore !== $lCore) return false;

        $wBranch = $normAlnum(extractBranchArea($wName));
        $lBranch = $normAlnum(extractBranchArea($lName));
        if ($wBranch !== '' && $lBranch !== '') {
            return $wBranch === $lBranch || str_contains($wBranch, $lBranch) || str_contains($lBranch, $wBranch);
        }
        if ($lBranch === '') return true;
        $wContext = $normAlnum(($webPlace['address'] ?? '') . ' ' . ($webPlace['description'] ?? ''));
        if ($wContext !== '' && str_contains($wContext, $lBranch)) return true;
        return $wBranch === '' && $localCoreCount === 1;
    };

    // Deduplicate local places by normalized name + branch/location so no venue appears twice
    $dedupedResults = [];
    $seenLocalVenues = [];
    foreach ($results as $lr) {
        $name = (string)($lr['name'] ?? '');
        $branch = extractBranchArea($name);
        $core = $normCoreName($name);
        $vKey = $core . '::' . $normAlnum($branch);
        if ($vKey !== '::' && isset($seenLocalVenues[$vKey])) continue;
        if ($vKey !== '::') $seenLocalVenues[$vKey] = true;
        $dedupedResults[] = $lr;
    }
    $results = $dedupedResults;
    if ($nearbyLocation !== null) {
        usort($results, fn($a, $b) => ((float)($a['distance_km'] ?? PHP_FLOAT_MAX))
            <=> ((float)($b['distance_km'] ?? PHP_FLOAT_MAX)));
    }

    if ($webResults) {
        $localCoreCounts = [];
        foreach ($results as $lr) {
            $ck = $normCoreName((string)($lr['name'] ?? ''));
            if ($ck !== '') $localCoreCounts[$ck] = ($localCoreCounts[$ck] ?? 0) + 1;
        }
        $allCoreCounts = [];
        foreach ($places as $ap) {
            $ck = $normCoreName((string)($ap['name'] ?? ''));
            if ($ck !== '') $allCoreCounts[$ck] = ($allCoreCounts[$ck] ?? 0) + 1;
        }

        // Enrich blank metadata on online cards when they correspond to a verified Davao local record
        foreach ($webResults as &$wp) {
            foreach ($places as $lp) {
                $ck = $normCoreName((string)($lp['name'] ?? ''));
                if ($venuesMatch($wp, $lp, $allCoreCounts[$ck] ?? 1)) {
                    $pubLocal = publicPlace($lp);
                    if (trim((string)($wp['rating'] ?? '')) === '' && !empty($pubLocal['rating'])) {
                        $wp['rating'] = $pubLocal['rating'];
                    }
                    if (trim((string)($wp['review_count'] ?? '')) === '' && !empty($pubLocal['review_count'])) {
                        $wp['review_count'] = $pubLocal['review_count'];
                    }
                    if (trim((string)($wp['price_range'] ?? '')) === '' && !empty($pubLocal['price_range'])) {
                        $wp['price_range'] = $pubLocal['price_range'];
                    }
                    if ((trim((string)($wp['address'] ?? '')) === '' || mb_strtolower(trim((string)($wp['address'] ?? '')), 'UTF-8') === 'davao city') && !empty($pubLocal['address'])) {
                        $wp['address'] = $pubLocal['address'];
                    }
                    if ((trim((string)($wp['food_type'] ?? '')) === '' || stripos((string)($wp['food_type'] ?? ''), 'food information') !== false) && !empty($pubLocal['food_type'])) {
                        $wp['food_type'] = $pubLocal['food_type'];
                    }
                    if (empty($wp['maps_url']) && !empty($pubLocal['maps_url'])) {
                        $wp['maps_url'] = $pubLocal['maps_url'];
                    }
                    if (empty($wp['record_id']) && !empty($lp['_id'])) {
                        $wp['record_id'] = $lp['_id'];
                    }
                    break;
                }
            }
        }
        unset($wp);

        // Keep verified online finds in their own list so the UI can show them as online cards.
        $dedupedWeb = [];
        $seenWebVenues = [];
        foreach ($webResults as $wp) {
            $wName = trim((string)($wp['name'] ?? ''));
            if ($wName === '') continue;
            $wBranch = extractBranchArea($wName);
            $wCore = $normCoreName($wName);
            $wKey = $wCore . '::' . $normAlnum($wBranch);

            // Map-only entries are proximity metadata, not online finds; keep their existing local merge.
            if (!empty($wp['map_only'])) {
                foreach ($results as $idx => $lr) {
                    $ck = $normCoreName((string)($lr['name'] ?? ''));
                    if (!$venuesMatch($wp, $lr, $localCoreCounts[$ck] ?? 1)) continue;
                    if (!empty($wp['source_url']) && empty($results[$idx]['source_url'])) {
                        $results[$idx]['source_url'] = $wp['source_url'];
                    }
                    if (!empty($wp['maps_url']) && empty($results[$idx]['maps_url'])) {
                        $results[$idx]['maps_url'] = $wp['maps_url'];
                    }
                    if (!empty($wp['description']) && empty($results[$idx]['description'])) {
                        $results[$idx]['description'] = $wp['description'];
                    }
                    continue 2;
                }
            }

            if ($wKey !== '::' && isset($seenWebVenues[$wKey])) continue;
            if ($wKey !== '::') $seenWebVenues[$wKey] = true;

            // Every returned place must carry at least one source reference (URL or dataset record id)
            $hasUrl = !empty($wp['source_url']) && filter_var($wp['source_url'], FILTER_VALIDATE_URL) !== false;
            $hasId = !empty($wp['record_id']) || !empty($wp['dataset_id']) || !empty($wp['id']);
            if (!$hasUrl && !$hasId) {
                $wp['unsupported'] = true;
                $wp['source_status'] = 'unsupported';
            } else {
                $wp['unsupported'] = false;
                $wp['source_status'] = 'supported';
                if ($hasUrl) {
                    $wp['source_reference'] = $wp['source_url'];
                } elseif ($hasId) {
                    $wp['source_reference'] = (string)($wp['record_id'] ?? $wp['dataset_id'] ?? $wp['id']);
                }
            }
            $dedupedWeb[] = $wp;
        }
        $webResults = $dedupedWeb;
    }

    if (!empty($intent['online_only'])) {
        $results = [];
    }

    if ($nearbyLocation === null
        && !isset($body['evaluation'])
        && !$outOfScope
        && empty($intent['strict_dishes'])
        && mapFindIntentIsSupported($intent, $requestedRestaurantCore)) {
        try {
            $mapSnapshot = loadLocalOsmNearbySnapshot(__DIR__ . '/data/osm-nearby-places.json');
            $mapFindSearch['search_type'] = $requestedRestaurantCore !== null
                ? 'brand_branches'
                : ($fastFoodItems ? 'fast_food_items' : 'category');
            $mapFindSearch['snapshot_updated_at'] = $mapSnapshot['updated_at'];
            $mapFindResults = buildMapFindResults(
                $mapSnapshot['places'],
                (string)($intent['effective_query'] !== '' ? $intent['effective_query'] : $query),
                $intent,
                $requestedRestaurantCore,
                100
            );

            $existingPlaces = array_merge($results, $webResults);
            $supplementalMapResults = [];
            foreach ($mapFindResults as $mapResult) {
                $duplicate = false;
                foreach ($existingPlaces as $existingPlace) {
                    if (mapBranchMatchesPlace($mapResult, $existingPlace)) {
                        $duplicate = true;
                        break;
                    }
                }
                if (!$duplicate && !empty($intent['exclude_shown'])) {
                    foreach ($shownNames as $shownName) {
                        if (mapBranchMatchesPlace($mapResult, ['name'=>(string)$shownName])) {
                            $duplicate = true;
                            break;
                        }
                    }
                }
                if (!$duplicate) $supplementalMapResults[] = $mapResult;
            }
            $mapFindResults = array_slice($supplementalMapResults, 0, 100);
            $mapFindSearch['status'] = $mapFindResults ? 'ok' : 'no_matching_map_places';
            $mapFindSearch['result_count'] = count($mapFindResults);
        } catch (RuntimeException $error) {
            $mapFindSearch['status'] = 'unavailable';
            error_log('OpenStreetMap map finds unavailable: ' . $error->getMessage());
        }
    }

    if ($nearbyLocation !== null && !$outOfScope) {
        $mapPlaces = $nearbyMapPlaces;
        $mapResults = ($nearbyExperienceRequested || !empty($intent['strict_dishes']))
            ? []
            : buildNearbyMapResults(
                $nearbyRelevantCandidates,
                $mapPlaces,
                $nearbyLocation['latitude'],
                $nearbyLocation['longitude'],
                DAVAO_NEARBY_RADIUS_KM,
                (string)($intent['effective_query'] !== '' ? $intent['effective_query'] : $query),
                $intent,
                $nearbyLocation['accuracy_km']
            );
        $seenMapUrls = [];
        foreach (array_merge($results, $webResults) as $existingPlace) {
            $mapUrl = trim((string)($existingPlace['location_source_url'] ?? ''));
            if ($mapUrl !== '') $seenMapUrls[$mapUrl] = true;
        }
        foreach ($mapResults as $mapResult) {
            $mapUrl = (string)($mapResult['location_source_url'] ?? '');
            if ($mapUrl !== '' && !isset($seenMapUrls[$mapUrl])) {
                $webResults[] = $mapResult;
                $seenMapUrls[$mapUrl] = true;
            }
        }
        if ($fastFoodItems) {
            foreach ($webResults as &$webPlace) {
                $webPlace['fast_food_chain'] = fastFoodChainMatchesItems($webPlace, $fastFoodItems);
            }
            unset($webPlace);
        }
        if (!$nearbyExperienceRequested) {
            usort($webResults, fn($a, $b) => ((float)($a['distance_km'] ?? PHP_FLOAT_MAX)) <=> ((float)($b['distance_km'] ?? PHP_FLOAT_MAX)));
        }
        $allNearbyWebResults = $webResults;
        $webResults = array_slice($webResults, 0, 20);
        $moreNearbyResults = array_slice($allNearbyWebResults, 20);
        if ($requestedRestaurantCore !== null) {
            $moreNearbyResults = filterPlacesByRestaurantCore($moreNearbyResults, $requestedRestaurantCore);
        }
        if ($moreNearbyResults && session_status() === PHP_SESSION_ACTIVE) {
            $savedPages = is_array($_SESSION['nearby_result_pages'] ?? null) ? $_SESSION['nearby_result_pages'] : [];
            foreach ($savedPages as $savedToken => $savedPage) {
                if (!is_array($savedPage) || (int)($savedPage['expires_at'] ?? 0) < time()) {
                    unset($savedPages[$savedToken]);
                }
            }
            $pageToken = bin2hex(random_bytes(16));
            $savedPages[$pageToken] = [
                'expires_at'=>time() + 600,
                'places'=>$moreNearbyResults,
            ];
            $_SESSION['nearby_result_pages'] = array_slice($savedPages, -5, null, true);
            $nearbySearch['page_token'] = $pageToken;
            $nearbySearch['more_count'] = count($moreNearbyResults);
        }
        if ($nearbyAlternativePlan !== null
            && !$outOfScope
            && empty($intent['online_only'])) {
            $exactNearbyPlaces = [];
            foreach (array_merge($results, $webResults) as $place) {
                $duplicate = false;
                foreach ($exactNearbyPlaces as $existingPlace) {
                    if (mapBranchMatchesPlace($place, $existingPlace)) {
                        $duplicate = true;
                        break;
                    }
                }
                if (!$duplicate) $exactNearbyPlaces[] = $place;
            }
            $alternativeLimit = nearbyAlternativeResultLimit(count($exactNearbyPlaces));
            if ($alternativeLimit > 0) {
                $isPartialFallback = count($exactNearbyPlaces) > 0;
                $alternativeNote = $nearbyAlternativePlan['note'];
                if ($isPartialFallback) {
                    $alternativeNote = $nearbyExperienceRequested
                        ? 'These additional nearby ' . $nearbyAlternativePlan['label']
                            . ' are alternatives; the requested '
                            . nearbyIntentEvidenceLabel($intent)
                            . ' preference is unverified for them.'
                        : 'These additional nearby ' . $nearbyAlternativePlan['label']
                            . ' are alternatives; check linked details to see which are local favorites.';
                }
                $alternativeMatches = rankNearbyCandidates(
                    $nearbyAlternativeCandidates,
                    $places,
                    $nearbyMapPlaces,
                    $nearbyLocation['latitude'],
                    $nearbyLocation['longitude'],
                    DAVAO_NEARBY_RADIUS_KM,
                    $nearbyLocation['accuracy_km'],
                    true,
                    $nearbyAlternativePlan['query'],
                    $nearbyAlternativePlan['intent']
                );
                $alternativePlaces = [];
                foreach ($alternativeMatches as $alternativeMatch) {
                    $alternative = publicPlace($alternativeMatch);
                    $duplicate = false;
                    foreach (array_merge($exactNearbyPlaces, $alternativePlaces) as $existingPlace) {
                        if (mapBranchMatchesPlace($alternative, $existingPlace)) {
                            $duplicate = true;
                            break;
                        }
                    }
                    if ($duplicate) continue;
                    $alternative['nearby_match_tier'] = 'alternative';
                    $alternative['nearby_match_note'] = $alternativeNote;
                    $alternative['description'] = trim(
                        (string)($alternative['description'] ?? $alternative['food_type'] ?? 'Nearby place.')
                        . ' '
                        . $alternativeNote
                    );
                    $alternativePlaces[] = $alternative;
                }
                $mapAlternatives = buildNearbyMapResults(
                    $nearbyAlternativeCandidates,
                    $nearbyMapPlaces,
                    $nearbyLocation['latitude'],
                    $nearbyLocation['longitude'],
                    DAVAO_NEARBY_RADIUS_KM,
                    $nearbyAlternativePlan['query'],
                    $nearbyAlternativePlan['intent'],
                    $nearbyLocation['accuracy_km'],
                    100
                );
                foreach ($mapAlternatives as $mapAlternative) {
                    $duplicate = false;
                    foreach (array_merge($exactNearbyPlaces, $alternativePlaces) as $existingPlace) {
                        if (mapBranchMatchesPlace($mapAlternative, $existingPlace)) {
                            $duplicate = true;
                            break;
                        }
                    }
                    if ($duplicate) continue;
                    $mapAlternative['nearby_match_tier'] = 'alternative';
                    $mapAlternative['nearby_match_note'] = $alternativeNote;
                    $mapAlternative['description'] = trim(
                        (string)($mapAlternative['description'] ?? $mapAlternative['food_type'] ?? 'Nearby place.')
                        . ' '
                        . $alternativeNote
                    );
                    $alternativePlaces[] = $mapAlternative;
                }
                $results = array_merge(
                    $results,
                    array_slice($alternativePlaces, 0, $alternativeLimit)
                );
                $alternativeCount = min(count($alternativePlaces), $alternativeLimit);
                if ($alternativeCount > 0) {
                    $nearbySearch['matching_mode'] = $isPartialFallback
                        ? 'nearby_partial_alternatives'
                        : 'nearby_alternatives';
                    $nearbySearch['exact_result_count'] = count($exactNearbyPlaces);
                    $nearbySearch['alternative_result_count'] = $alternativeCount;
                    $nearbySearch['fallback_note'] = $alternativeNote;
                }
            }
        }
        $nearbyItems = $nearbyExperienceRequested
            ? []
            : array_merge($results, $webResults);
        if ($nearbyExperienceRequested) {
            foreach (array_merge($webResults, $results) as $nearbyItem) {
                $duplicate = false;
                foreach ($nearbyItems as $existingItem) {
                    if (mapBranchMatchesPlace($nearbyItem, $existingItem)) {
                        $duplicate = true;
                        break;
                    }
                }
                if (!$duplicate) $nearbyItems[] = $nearbyItem;
            }
        }
        if (!$nearbyExperienceRequested) {
            usort($nearbyItems, fn($a, $b) => ((float)($a['distance_km'] ?? PHP_FLOAT_MAX)) <=> ((float)($b['distance_km'] ?? PHP_FLOAT_MAX)));
        }
        $nearbySearch['status'] = !$mapPlaces ? 'no_map_data' : ($nearbyItems ? 'ok' : 'no_matching_places_within_radius');
        $nearbySearch['result_count'] = count($nearbyItems);
        $nearbySearch['suggestions'] = $nearbySearch['status'] === 'no_matching_places_within_radius'
            ? nearbyBroadSearchSuggestions($intent)
            : [];
        if (!$nearbyItems) {
            if ($nearbyExperienceRequested || $nearbyAlternativePlan !== null) {
                $answer = $nearbyExperienceRequested
                    ? 'I could not verify any nearby restaurants matching your requested '
                        . nearbyIntentEvidenceLabel($intent) . ' within 3 km.'
                    : 'I could not find nearby ' . ($nearbyAlternativePlan['label'] ?? 'matching') . ' options within 3 km.';
                $answer .= $nearbyAlternativePlan !== null
                    ? ' No nearby places in that category were found either; try “Anywhere in Davao City” for more choices.'
                    : ' I have not filled the list with unrelated places; choose “Anywhere in Davao City” to see broader source-backed recommendations.';
            } else {
                $answer = $nearbySearch['status'] === 'no_map_data'
                    ? 'The local OpenStreetMap snapshot contains no mapped food places near you, so no locations can be verified within 3 km. Try another area or refresh the OSM snapshot.'
                    : 'I could not verify any restaurants matching your request within 3 km. I have not included distant or unverified places.';
            }
        } elseif (($nearbySearch['matching_mode'] ?? '') === 'nearby_alternatives') {
            $answer = ($nearbyExperienceRequested
                ? 'I could not verify a nearby match for your requested '
                    . nearbyIntentEvidenceLabel($intent)
                    . ' within 3 km. Here are '
                : 'No exact local-favorites match was verified. Here are ')
                . (int)($nearbySearch['alternative_result_count'] ?? count($nearbyItems))
                . ' nearby '
                . $nearbyAlternativePlan['label']
                . ((int)($nearbySearch['alternative_result_count'] ?? count($nearbyItems)) === 1 ? ' alternative' : ' alternatives')
                . '. '
                . $nearbyAlternativePlan['note'];
        } elseif (($nearbySearch['matching_mode'] ?? '') === 'nearby_partial_alternatives') {
            $answer = 'I found '
                . (int)$nearbySearch['exact_result_count']
                . ' nearby match'
                . ((int)$nearbySearch['exact_result_count'] === 1 ? '' : 'es')
                . ' and added '
                . (int)$nearbySearch['alternative_result_count']
                . ' nearby '
                . $nearbyAlternativePlan['label']
                . ((int)$nearbySearch['alternative_result_count'] === 1 ? ' alternative' : ' alternatives')
                . '. '
                . ($nearbySearch['fallback_note'] ?? $nearbyAlternativePlan['note']);
        } elseif ($nearbyExperienceRequested) {
            $nearbySearch['matching_mode'] = 'evidence_backed_experience';
            $answer = 'I found ' . count($nearbyItems) . ' nearby '
                . nearbyIntentEvidenceLabel($intent)
                . (count($nearbyItems) === 1 ? ' match' : ' matches')
                . ' within 3 km, ordered by recommendation relevance. The cards below include the supporting source or review evidence and approximate distance.';
        } else {
            $summaryItems = [];
            foreach (array_slice($nearbyItems, 0, 6) as $nearbyItem) {
                if (!isset($nearbyItem['distance_km']) || !is_numeric($nearbyItem['distance_km'])) continue;
                $summaryItems[] = trim((string)($nearbyItem['name'] ?? 'Restaurant'))
                    . ' (' . number_format((float)$nearbyItem['distance_km'], 1) . ' km)';
            }
            $answer = 'Matching restaurants within 3 km, ordered by approximate distance: '
                . implode(', ', $summaryItems)
                . '. Distances are straight-line estimates; OpenStreetMap coverage may be incomplete.';
        }
    }
    if ($requestedRestaurantCore !== null && !$outOfScope) {
        $results = filterPlacesByRestaurantCore($results, $requestedRestaurantCore);
        $webResults = filterPlacesByRestaurantCore($webResults, $requestedRestaurantCore);
        $sources = [];
        if ($nearbyLocation === null) {
            $brandName = restaurantCoreDisplayName($places, $requestedRestaurantCore);
            $matchingNames = [];
            foreach (array_merge($results, $webResults, $mapFindResults) as $place) {
                $name = trim((string)($place['name'] ?? ''));
                if ($name !== '' && !in_array($name, $matchingNames, true)) $matchingNames[] = $name;
            }
            $answer = $matchingNames
                ? 'Here are matching ' . $brandName . ' locations found in Davao City: ' . implode(', ', array_slice($matchingNames, 0, 8)) . '.'
                    . (count($matchingNames) > 8 ? ' Additional known locations are listed below.' : '')
                    . ($mapFindResults ? ' OpenStreetMap-only locations are not live-verified.' : '')
                : 'I could not find a verified ' . $brandName . ' location in the Davao City dataset for this search.';
        }
    }
    $searchSuggestions = [];
    if (!$outOfScope && !empty($intent['strict_dishes'])) {
        $matchedNames = [];
        foreach (array_merge($results, $webResults, $mapFindResults) as $place) {
            $name = trim((string)($place['name'] ?? ''));
            if ($name !== '' && !in_array($name, $matchedNames, true)) $matchedNames[] = $name;
        }
        $dishLabel = implode(', ', array_map('strval', $intent['strict_dishes']));
        if (!$matchedNames) {
            $searchSuggestions = nearbyBroadSearchSuggestions($intent);
            $sources = [];
            $answer = $nearbyLocation !== null
                ? "I did not find a venue-linked online listing for {$dishLabel} within 3 km in this search. That does not mean no nearby places serve it."
                : "I did not find a venue-linked online listing for {$dishLabel} in Davao City in this search. That does not mean no Davao places serve it.";
        } elseif ($nearbyLocation === null) {
            $answer = 'Places with available evidence for ' . $dishLabel . ': '
                . implode(', ', array_slice($matchedNames, 0, 8))
                . '. Check linked sources for current menu details.';
        }
    }
    if (!$outOfScope && $fastFoodItems) {
        $itemLabels = implode(' and ', $fastFoodItems);
        foreach (['results', 'webResults', 'mapFindResults'] as $resultKey) {
            $$resultKey = array_values(array_filter(
                $$resultKey,
                fn($place) => is_array($place) && $matchesFastFoodIntent($place)
            ));
            foreach ($$resultKey as &$place) {
                $place['fast_food_chain'] = fastFoodChainMatchesItems($place, $fastFoodItems);
            }
            unset($place);
        }
        $matchingNames = [];
        $chainNames = [];
        $mapCategoryNames = [];
        foreach (array_merge($results, $webResults, $mapFindResults) as $place) {
            $name = trim((string)($place['name'] ?? ''));
            if ($name === '') continue;
            if (!empty($place['fast_food_chain'])) {
                if (!in_array($name, $chainNames, true)) $chainNames[] = $name;
            } elseif (!empty($place['map_only'])) {
                if (!in_array($name, $mapCategoryNames, true)) $mapCategoryNames[] = $name;
            } elseif (!in_array($name, $matchingNames, true)) {
                $matchingNames[] = $name;
            }
        }
        $scopeLabel = $nearbyLocation !== null ? 'within 3 km' : 'in Davao City';
        if ($matchingNames) {
            $answer = 'Places matching ' . $itemLabels . ' ' . $scopeLabel . ': '
                . implode(', ', array_slice($matchingNames, 0, 8))
                . '. Check linked sources for current menu details.';
        } elseif ($mapCategoryNames) {
            $answer = 'OpenStreetMap category matches to check for ' . $itemLabels . ' ' . $scopeLabel . ': '
                . implode(', ', array_slice($mapCategoryNames, 0, 8))
                . '. OpenStreetMap does not verify menu availability.';
        } elseif ($chainNames) {
            $answer = 'I could not confirm places serving ' . $itemLabels . ' from menu or online evidence ' . $scopeLabel . '.';
        } else {
            $answer = 'I could not find supported places for ' . $itemLabels . ' ' . $scopeLabel . '.';
        }
        if ($chainNames) {
            $answer .= ' Additional fast-food chain locations to check: '
                . implode(', ', array_slice($chainNames, 0, 8))
                . '. Branch-specific menu availability may vary and has not been verified.';
        }
    }

    emitSearchProgress('Putting your recommendations together…');
    if (ob_get_level() > 0) ob_clean();
    $playwrightSuccess = ($evidence['success'] ?? false) === true
        && ($evidence['browser_used'] ?? false) === true
        && (int)($evidence['browser_result_count'] ?? 0) > 0;
    $totalLatencyMs = (int)round((microtime(true) - $requestStartedAt) * 1000);
    $playwrightMs = (int)($evidence['latency_ms'] ?? 0);

    $metrics = [
        'total_latency_ms'=>$totalLatencyMs,
        'timings_ms'=>[
            'total_ms'=>$totalLatencyMs,
            'dataset_load_ms'=>$datasetLoadMs,
            'retrieval_ranking_ms'=>$retrievalRankingMs,
            'dataset_total_ms'=>$datasetLoadMs + $retrievalRankingMs,
            'playwright_ms'=>$playwrightMs,
            'ai_inference_ms'=>$aiInferenceMs,
            'ai_stages_ms'=>$telemetry['stages_ms'] ?? [],
        ],
        'cache'=>[
            'places'=>$placesCacheState,
            'playwright'=>$evidence['cache'] ?? 'unknown',
            'playwright_age_seconds'=>$evidence['cache_age_seconds'] ?? null,
            'playwright_cached_at'=>$evidence['cached_at'] ?? null,
        ],
        'usage'=>[
            'llm_calls'=>(int)($telemetry['llm_calls'] ?? 0),
            'prompt_tokens'=>(int)($telemetry['prompt_tokens'] ?? 0),
            'completion_tokens'=>(int)($telemetry['completion_tokens'] ?? 0),
            'total_tokens'=>(int)($telemetry['total_tokens'] ?? 0),
            'estimated_cost_usd'=>round((float)($telemetry['estimated_cost_usd'] ?? 0.0), 8),
        ],
        'reproducibility'=>[
            'dataset_signature'=>$signature,
            'provider'=>$reasoningProvider,
            'agentic'=>$isAgentic,
            'iterative_loop'=>$useIterativeLoop,
            'model_used'=>$modelUsed,
            'temperature'=>$openAIOptions['temperature'] ?? null,
            'prompt_style'=>$openAIOptions['promptStyle'] ?? 'baseline',
            'seed'=>$openAIOptions['seed'] ?? null,
            'cache_policy'=>$cachePolicy,
            'timestamp_utc'=>gmdate('c'),
        ],
        'agentic_loop'=>$loopTelemetry,
        'volatility'=>$volatilityReport,
        'nearby_search'=>$nearbySearch['enabled'] ?? false,
        'map_finds'=>$mapFindSearch,
        'parsed_intent'=>[
            'disambiguation_type'=>$intent['disambiguation_type'] ?? 'none',
            'max_price_php'=>$intent['max_price_php'] ?? null,
            'price_scope'=>$intent['price_scope'] ?? null,
            'min_price_php'=>$intent['min_price_php'] ?? null,
            'price_preference'=>$intent['price_preference'] ?? null,
            'specific_dishes'=>$intent['specific_dishes'] ?? [],
            'fast_food_items'=>$intent['fast_food_items'] ?? [],
            'cuisines'=>$intent['cuisines'] ?? [],
            'areas'=>$intent['areas'] ?? [],
            'thematic'=>$intent['thematic'] ?? [],
            'is_followup'=>$intent['is_followup'] ?? false,
            'user_correction'=>$intent['user_correction'] ?? false,
        ],
        'playwright'=>[
            'success'=>$playwrightSuccess,
            'browser_used'=>($evidence['browser_used'] ?? false) === true,
            'browser_result_count'=>(int)($evidence['browser_result_count'] ?? 0),
            'search_provider_result_count'=>(int)($evidence['search_provider_result_count'] ?? 0),
            'browser_search_count'=>(int)($evidence['browser_search_count'] ?? 0),
            'browser_search_errors'=>is_array($evidence['browser_search_errors'] ?? null) ? $evidence['browser_search_errors'] : [],
            'error'=>$evidence['error'] ?? null,
            'cache'=>$evidence['cache'] ?? 'unknown',
            'cache_age_seconds'=>$evidence['cache_age_seconds'] ?? null,
            'result_count'=>count($evidence['results'] ?? []),
            'source_count'=>count($sources ?? []),
            'latency_ms'=>$playwrightMs,
        ],
        'pipeline'=>[
            'stages'=>$telemetry['pipeline_trace'] ?? [],
            'stage_models'=>$telemetry['stage_models'] ?? [],
            'planner_used'=>($intent['planner_used'] ?? false) === true,
            'planned_query'=>$intent['playwright_query'] ?? null,
            'deepseek_reasoner'=>$telemetry['hybrid_reasoner'] ?? ['backend'=>'not_run', 'model'=>null],
            'deepseek_reasoner_attempt'=>$telemetry['hybrid_reasoner_attempt'] ?? ['backend'=>'not_run', 'requested_model'=>null],
        ],
        'deepseek'=>[
            'configured'=>DEEPSEEK_BACKEND === 'hosted_api'
                ? DEEPSEEK_API_KEY !== ''
                : DEEPSEEK_BASE_URL !== '',
            'success'=>$assistantStatus === 'ready' && ($reasoningProvider === 'deepseek' || str_contains((string)$modelUsed, DEEPSEEK_MODEL)),
            'model'=>str_contains((string)$modelUsed, DEEPSEEK_MODEL) ? DEEPSEEK_MODEL : '',
            'error'=>$GLOBALS['providerErrors']['deepseek'] ?? (!empty($GLOBALS['last_deepseek_error']) ? ['http'=>0, 'message'=>$GLOBALS['last_deepseek_error']] : null),
            'retries'=>$GLOBALS['aiTelemetry']['deepseek_retries'] ?? 0,
            'done_reason'=>$GLOBALS['aiTelemetry']['deepseek_done_reason'] ?? 'unknown',
        ],
        'deepseek_api'=>[
            'configured'=>DEEPSEEK_API_KEY !== '',
            'success'=>$assistantStatus === 'ready' && $reasoningProvider === 'deepseek-api',
            'model'=>$reasoningProvider === 'deepseek-api' ? (string)($modelUsed ?: DEEPSEEK_API_MODEL) : '',
            'error'=>$GLOBALS['providerErrors']['deepseek_api'] ?? $GLOBALS['providerErrors']['deepseek-api'] ?? null,
        ],
        'gpt4o'=>[
            'configured'=>OPENAI_API_KEY !== '',
            'success'=>$assistantStatus === 'ready' && ($reasoningProvider === 'gpt-4o-mini' || str_contains((string)$modelUsed, OPENAI_MODEL)),
            'model'=>OPENAI_MODEL,
            'error'=>$GLOBALS['providerErrors']['gpt4o'] ?? null,
        ],
        'deepseek_curation'=>$telemetry['hybrid_curation'] ?? [
            'invoked'=>false,
            'trigger'=>'none',
        ],
    ];
    if (ob_get_level() > 0) ob_clean();
    $searchResponse = [
        'answer'=>$answer,
        'assistantStatus'=>$assistantStatus,
        'modelUsed'=>$modelUsed,
        'outOfScope'=>$outOfScope,
        'sources'=>$sources,
        'webResults'=>$webResults,
        'mapResults'=>$mapFindResults,
        'results'=>$results,
        'searchSuggestions'=>$searchSuggestions,
        'count'=>count($results),
        'locationSearch'=>$nearbySearch,
        'metrics'=>$metrics,
    ];
    echo json_encode($streamSearchProgress
        ? ['type'=>'result', 'data'=>$searchResponse]
        : $searchResponse, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($streamSearchProgress) echo "\n";
} catch (SearchClientDisconnectedException $e) {
    exit;
} catch (Throwable $e) {
    if (http_response_code() < 400) http_response_code(500);
    if (ob_get_level() > 0) ob_clean();
    $errorResponse = ['error'=>$e->getMessage()];
    echo json_encode(!empty($GLOBALS['streamSearchProgress'])
        ? ['type'=>'error', 'error'=>$e->getMessage()]
        : $errorResponse, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!empty($GLOBALS['streamSearchProgress'])) echo "\n";
}

function emitSearchProgress(string $message): void {
    if (empty($GLOBALS['streamSearchProgress'])) return;
    while (ob_get_level() > 0) {
        if (!ob_end_flush()) {
            throw new RuntimeException('Search progress cannot be streamed because output buffering is active.');
        }
    }
    echo json_encode(['type'=>'progress', 'message'=>$message], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
    flush();
    assertSearchClientConnected();
}

function assertSearchClientConnected(): void {
    if (PHP_SAPI !== 'cli' && !empty($GLOBALS['streamSearchProgress']) && connection_aborted() === 1) {
        throw new SearchClientDisconnectedException();
    }
}

class SearchClientDisconnectedException extends RuntimeException {}

function datasetSignature(string $dir): string {
    $items = [];
    foreach (glob($dir . '/*.{csv,xlsx,xls}', GLOB_BRACE) ?: [] as $f) $items[] = basename($f) . ':' . filesize($f) . ':' . filemtime($f);
    sort($items);
    return hash('sha256', implode('|', $items));
}
