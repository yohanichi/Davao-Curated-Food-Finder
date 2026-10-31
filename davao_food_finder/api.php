<?php
declare(strict_types=1);
ob_start();
set_time_limit(defined('TIMEOUT_PHP_MAX_EXECUTION') ? TIMEOUT_PHP_MAX_EXECUTION : 300);
@ini_set('max_execution_time', (string)(defined('TIMEOUT_PHP_MAX_EXECUTION') ? TIMEOUT_PHP_MAX_EXECUTION : 300));
if (session_status() === PHP_SESSION_NONE && PHP_SAPI !== 'cli') {
    session_start();
}
if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, no-cache, must-revalidate');
}
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/config.php';
require_once __DIR__ . '/src/search.php';
require_once __DIR__ . '/src/web_reasoner.php';

try {
    $requestStartedAt = microtime(true);
    resetAiTelemetry();
    $GLOBALS['providerErrors'] = [];

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
    $query = trim((string)($body['query'] ?? ''));
    $history = is_array($body['history'] ?? null) ? array_slice($body['history'], -12) : [];
    $shownNames = is_array($body['shownNames'] ?? null) ? array_slice($body['shownNames'], 0, 50) : [];
    if ($query === '' || mb_strlen($query) > 300) { http_response_code(422); throw new RuntimeException('Enter a search query up to 300 characters.'); }

    $openAIOptions = ['temperature'=>0.2, 'promptStyle'=>'baseline', 'agentic'=>true, 'iterativeLoop'=>true, 'seed'=>null];
    $reasoningProvider = 'hybrid';
    $cachePolicy = 'default';
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

    // Component Timing 2: Intent parsing + Stage 1 GPT-4o mini Query Planner (in hybrid mode)
    $retrievalStart = microtime(true);
    $isAgentic = ($openAIOptions['agentic'] ?? true) === true && !in_array($reasoningProvider, ['baseline', 'non-agentic'], true);
    $useIterativeLoop = ($openAIOptions['iterativeLoop'] ?? true) === true && $isAgentic && $reasoningProvider !== 'ablation-no-loop';
    $intent = parseQueryIntent($query, $history, $shownNames);
    $intentParseMs = (int)round((microtime(true) - $retrievalStart) * 1000);

    if ($reasoningProvider === 'hybrid' && $isAgentic) {
        $intent = planSearchQueryWithGPT4oMini($query, $history, $shownNames, $intent, $openAIOptions);
    }
    $openAIOptions['intent'] = $intent;

    // Component Timing 3 / Stage 2: Playwright active web scraping (or local volatility test snapshot override)
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
    } elseif ($reasoningProvider === 'baseline' || $reasoningProvider === 'non-agentic' || !$isAgentic) {
        $evidence = collectPlaywrightEvidence($query, __DIR__ . '/data/.web-cache', 43200, 'none');
    } elseif ($intent['out_of_scope']) {
        $evidence = ['results'=>[], 'sources'=>[], 'cache'=>'skipped_out_of_scope', 'cache_age_seconds'=>null, 'cached_at'=>null, 'success'=>false, 'latency_ms'=>0];
    } else {
        $plannedPwQuery = trim((string)($intent['playwright_query'] ?? ''));
        if ($plannedPwQuery !== '') {
            $evidence = collectPlaywrightEvidence($plannedPwQuery, __DIR__ . '/data/.web-cache', 43200, $cachePolicy);
        } else {
            $pwQuery = $intent['effective_query'] !== '' ? $intent['effective_query'] : $query;
            $evidenceQuery = preg_match('/\bdavao(\s+city)?\b/i', $pwQuery) ? $pwQuery : ('Davao City ' . $pwQuery);
            $evidence = collectPlaywrightEvidence($evidenceQuery, __DIR__ . '/data/.web-cache', 43200, $cachePolicy);
        }
    }
    if (isset($GLOBALS['aiTelemetry']['pipeline_trace'])) {
        $GLOBALS['aiTelemetry']['pipeline_trace']['2_playwright_scraper'] = ($evidence['success'] ?? false)
            ? 'ok'
            : (string)($evidence['cache'] ?? 'empty');
    }

    // Reconcile static CSV places with live web evidence for Data Volatility Resilience (when iterative/volatility guard is active)
    $rankStart = microtime(true);
    $volatilityReport = ['evidence_items_scanned'=>0, 'closed_places'=>[], 'price_updates'=>[], 'hours_updates'=>[], 'rating_updates'=>[]];
    if ($isAgentic && $useIterativeLoop && !empty($evidence['results'])) {
        $reconciled = reconcileVolatilityWithEvidence($places, $evidence);
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
    $retrievalRankingMs = $intentParseMs + (int)round((microtime(true) - $rankStart) * 1000);

    // Component Timing 4: Reasoning / Inference dispatch + Autonomous Iterative Critique-and-Refinement Loop
    $aiDispatchStart = microtime(true);
    if ($reasoningProvider === 'baseline') {
        [$answer, $rankedIds, $assistantStatus, $sources, $webResults, $outOfScope, $modelUsed] = answerWithBaseline($query, $candidates, $intent);
    } elseif ($reasoningProvider === 'ablation-no-loop' || $reasoningProvider === 'agentic-local') {
        [$answer, $rankedIds, $assistantStatus, $sources, $webResults, $outOfScope, $modelUsed] = answerWithAgenticLocal($query, $candidates, $evidence, $intent, $volatilityReport);
        if ($reasoningProvider === 'ablation-no-loop') {
            $modelUsed = 'agentic-single-pass-no-loop';
        }
    } elseif ($reasoningProvider === 'gpt-4o-mini') {
        [$answer, $rankedIds, $assistantStatus, $sources, $webResults, $outOfScope, $modelUsed] = answerWithGPT4oMini($query, $history, $candidates, $evidence, $openAIOptions);
    } elseif ($reasoningProvider === 'deepseek') {
        [$answer, $rankedIds, $assistantStatus, $sources, $webResults, $outOfScope, $modelUsed] = answerWithDeepSeek($query, $history, $candidates, $evidence, $openAIOptions);
    } elseif ($reasoningProvider === 'deepseek-api') {
        [$answer, $rankedIds, $assistantStatus, $sources, $webResults, $outOfScope, $modelUsed] = answerWithDeepSeekApi($query, $history, $candidates, $evidence, $openAIOptions);
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
        [$answer, $rankedIds, $assistantStatus, $sources, $webResults, $outOfScope, $modelUsed] = answerWithHybridPipeline($query, $history, $candidates, $evidence, $openAIOptions);
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

    // Autonomous Iterative Critique-and-Refinement Loop (Pass 2 Verification & Self-Correction)
    $loopTelemetry = [
        'enabled'=>$useIterativeLoop,
        'iterations'=>1,
        'initial_compliance_rate'=>null,
        'final_compliance_rate'=>null,
        'violations_detected'=>[],
        'violations_repaired'=>0,
        're_retrieved'=>false,
    ];
    $isInteractiveLlm = !isset($body['evaluation'])
        && $assistantStatus === 'ready'
        && in_array($reasoningProvider, ['deepseek', 'deepseek-api', 'gpt-4o-mini', 'hybrid'], true);
    if ($useIterativeLoop && !$outOfScope && ($assistantStatus === 'ready' || !in_array($reasoningProvider, ['deepseek', 'deepseek-api', 'gpt-4o-mini', 'hybrid'], true) || !isset($body['evaluation']))) {
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
    if (!$outOfScope && !$results && !$webResults && !$strictEmptyAllowed && empty($intent['online_only'])) {
        $fallbackCap = $isInteractiveLlm ? 5 : 10;
        foreach (array_slice($candidates, 0, $fallbackCap) as $cand) {
            $normName = mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', (string)$cand['name']) ?? '', 'UTF-8');
            if ($normName !== '' && isset($seenResultNames[$normName])) continue;
            if ($normName !== '') $seenResultNames[$normName] = true;
            $results[] = publicPlace($cand);
        }
    }
    if (!empty($intent['online_only'])) {
        $results = [];
    }
    $results = array_slice($results, 0, 20);
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

        // Deduplicate web results and merge web results that repeat a local record into it
        $dedupedWeb = [];
        $seenWebVenues = [];
        foreach ($webResults as $wp) {
            $wName = trim((string)($wp['name'] ?? ''));
            if ($wName === '') continue;
            $wBranch = extractBranchArea($wName);
            $wCore = $normCoreName($wName);
            $wKey = $wCore . '::' . $normAlnum($wBranch);

            // Check if this web result repeats a local record in $results
            $matchedLocalIdx = null;
            foreach ($results as $idx => $lr) {
                $ck = $normCoreName((string)($lr['name'] ?? ''));
                if ($venuesMatch($wp, $lr, $localCoreCounts[$ck] ?? 1)) {
                    $matchedLocalIdx = $idx;
                    break;
                }
            }

            if ($matchedLocalIdx !== null) {
                // Web result repeats a local record: merge into it!
                if (!empty($wp['source_url']) && empty($results[$matchedLocalIdx]['source_url'])) {
                    $results[$matchedLocalIdx]['source_url'] = $wp['source_url'];
                }
                if (!empty($wp['maps_url']) && empty($results[$matchedLocalIdx]['maps_url'])) {
                    $results[$matchedLocalIdx]['maps_url'] = $wp['maps_url'];
                }
                if (!empty($wp['description']) && empty($results[$matchedLocalIdx]['description'])) {
                    $results[$matchedLocalIdx]['description'] = $wp['description'];
                }
                // Do not keep in $webResults to prevent duplicate cross-list appearance
                continue;
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

    ob_clean();
    $playwrightSuccess = ($evidence['success'] ?? false) === true;
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
        'parsed_intent'=>[
            'disambiguation_type'=>$intent['disambiguation_type'] ?? 'none',
            'max_price_php'=>$intent['max_price_php'] ?? null,
            'min_price_php'=>$intent['min_price_php'] ?? null,
            'specific_dishes'=>$intent['specific_dishes'] ?? [],
            'cuisines'=>$intent['cuisines'] ?? [],
            'areas'=>$intent['areas'] ?? [],
            'thematic'=>$intent['thematic'] ?? [],
            'is_followup'=>$intent['is_followup'] ?? false,
            'user_correction'=>$intent['user_correction'] ?? false,
        ],
        'playwright'=>[
            'success'=>$playwrightSuccess,
            'cache'=>$evidence['cache'] ?? 'unknown',
            'cache_age_seconds'=>$evidence['cache_age_seconds'] ?? null,
            'result_count'=>count($evidence['results'] ?? []),
            'source_count'=>count($sources ?? []),
            'latency_ms'=>$playwrightMs,
        ],
        'pipeline'=>[
            'stages'=>$telemetry['pipeline_trace'] ?? [],
            'planned_query'=>$intent['playwright_query'] ?? null,
        ],
        'deepseek'=>[
            'configured'=>DEEPSEEK_API_KEY !== '' || DEEPSEEK_BASE_URL !== '',
            'success'=>$assistantStatus === 'ready' && ($reasoningProvider === 'deepseek' || str_contains((string)$modelUsed, DEEPSEEK_MODEL)),
            'model'=>str_contains((string)$modelUsed, DEEPSEEK_MODEL) ? DEEPSEEK_MODEL : '',
            'error'=>$GLOBALS['providerErrors']['deepseek'] ?? (!empty($GLOBALS['last_deepseek_error']) ? ['http'=>0, 'message'=>$GLOBALS['last_deepseek_error']] : null),
            'retries'=>$GLOBALS['aiTelemetry']['deepseek_retries'] ?? 0,
            'done_reason'=>$GLOBALS['aiTelemetry']['deepseek_done_reason'] ?? 'unknown',
        ],
        'deepseek_api'=>[
            'configured'=>getenv('DEEPSEEK_API_KEY') !== false && trim((string)getenv('DEEPSEEK_API_KEY')) !== '',
            'success'=>$assistantStatus === 'ready' && $reasoningProvider === 'deepseek-api',
            'model'=>$reasoningProvider === 'deepseek-api' ? (string)($modelUsed ?: 'deepseek-reasoner') : '',
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
    echo json_encode([
        'answer'=>$answer,
        'assistantStatus'=>$assistantStatus,
        'modelUsed'=>$modelUsed,
        'outOfScope'=>$outOfScope,
        'sources'=>$sources,
        'webResults'=>$webResults,
        'results'=>$results,
        'count'=>count($results),
        'metrics'=>$metrics,
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    if (http_response_code() < 400) http_response_code(500);
    ob_clean();
    echo json_encode(['error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}

function datasetSignature(string $dir): string {
    $items = [];
    foreach (glob($dir . '/*.{csv,xlsx,xls}', GLOB_BRACE) ?: [] as $f) $items[] = basename($f) . ':' . filesize($f) . ':' . filemtime($f);
    sort($items);
    return hash('sha256', implode('|', $items));
}
