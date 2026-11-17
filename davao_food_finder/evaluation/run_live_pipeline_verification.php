<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/config.php';

const LIVE_PIPELINE_QUERIES = [
    ['id'=>'japanese_dining', 'query'=>'Affordable Japanese restaurants in Davao City', 'controlled_violation'=>false],
    ['id'=>'study_cafes', 'query'=>'Quiet cafes for studying in Davao City', 'controlled_violation'=>false],
    ['id'=>'grilled_chicken', 'query'=>'Where can I get grilled chicken in Davao City?', 'controlled_violation'=>false],
    [
        'id'=>'impossible_price_budget',
        'query'=>'Find Japanese restaurants in Davao City with a full meal under ₱1 per person',
        'controlled_violation'=>false,
        'behavior_check'=>'impossible_budget_refusal',
        'max_price_php'=>1,
    ],
    [
        'id'=>'price_ceiling_repair',
        'query'=>'Find Japanese restaurants in Davao City with a full meal under ₱250 per person',
        'controlled_violation'=>true,
        'behavior_check'=>'price_violation_repair',
        'max_price_php'=>250,
    ],
];

function livePipelineSafeUrl(string $url): string {
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) return '';
    $safe = strtolower((string)$parts['scheme']) . '://' . (string)$parts['host'];
    if (isset($parts['port'])) $safe .= ':' . (int)$parts['port'];
    $safe .= (string)($parts['path'] ?? '');
    if (!empty($parts['query'])) {
        parse_str((string)$parts['query'], $query);
        foreach (array_keys($query) as $key) {
            if (preg_match('/token|key|auth|secret|signature|credential|password/i', (string)$key)) unset($query[$key]);
        }
        if ($query) $safe .= '?' . http_build_query($query);
    }
    return $safe;
}

function livePipelineIsSupportedDeepSeekModel(string $model): bool {
    return in_array(strtolower(trim($model)), ['deepseek-flash', 'deepseek-v4-pro'], true);
}

function liveImpossibleBudgetResponseEvidence(string $answer, float $maxPricePhp): array {
    $limit = rtrim(rtrim(number_format($maxPricePhp, 2, '.', ''), '0'), '.');
    $pricePattern = '(?:₱\s*|PHP\s*)' . preg_quote($limit, '/') . '(?!\d|\.\d)';
    $noMatchClaim = preg_match(
        '/\b(?:no|none|cannot|can\'t|unable|impossible|unrealistic)\b.{0,220}\b(?:under|below|less than)\s*' . $pricePattern . '/isu',
        $answer
    ) === 1;
    $noMatchClaim = $noMatchClaim || preg_match(
        '/\b(?:under|below|less than)\s*' . $pricePattern . '.{0,80}\b(?:is\s+not|isn\'t|not)\s+(?:realistic|feasible|possible|practical)\b/isu',
        $answer
    ) === 1;
    $aboveBudgetAlternativeContext = preg_match(
        '/\b(?:alternative|most affordable|another option|more realistic budget|higher budget|cheapest|prices?\s+(?:start|begin)|for instance|for example)\b/iu',
        $answer
    ) === 1;
    $pricesAboveBudget = [];
    if (preg_match_all('/(?:₱\s*|PHP\s*)([\d,]+(?:\.\d+)?)/iu', $answer, $matches)) {
        foreach ($matches[1] as $match) {
            $price = (float)str_replace(',', '', $match);
            if ($price > $maxPricePhp && !in_array($price, $pricesAboveBudget, true)) {
                $pricesAboveBudget[] = $price;
            }
        }
    }

    return [
        'no_match_claim'=>$noMatchClaim,
        'above_budget_alternative_context'=>$aboveBudgetAlternativeContext,
        'alternative_price_above_budget'=>count($pricesAboveBudget) > 0,
        'prices_above_budget_php'=>$pricesAboveBudget,
    ];
}

function livePipelineStageResults(array $data): array {
    $metrics = is_array($data['metrics'] ?? null) ? $data['metrics'] : [];
    $pipeline = is_array($metrics['pipeline'] ?? null) ? $metrics['pipeline'] : [];
    $trace = is_array($pipeline['stages'] ?? null) ? $pipeline['stages'] : [];
    $stageMs = is_array($metrics['timings_ms']['ai_stages_ms'] ?? null) ? $metrics['timings_ms']['ai_stages_ms'] : [];
    $models = is_array($pipeline['stage_models'] ?? null) ? $pipeline['stage_models'] : [];
    $openAiModel = static function (string $stage) use ($models): string {
        return trim((string)($models[$stage] ?? ''));
    };
    $correctOpenAiModel = static function (string $stage) use ($openAiModel): bool {
        return str_starts_with(strtolower($openAiModel($stage)), 'gpt-4o-mini');
    };
    $plannerQuery = trim((string)($pipeline['planned_query'] ?? ''));
    $playwright = is_array($metrics['playwright'] ?? null) ? $metrics['playwright'] : [];
    $cacheStatus = strtolower((string)($playwright['cache'] ?? ($metrics['cache']['playwright'] ?? 'unknown')));
    $sourceUrls = [];
    foreach (($data['sources'] ?? []) as $source) {
        if (!is_array($source)) continue;
        $url = livePipelineSafeUrl((string)($source['url'] ?? ''));
        if ($url !== '' && !in_array($url, $sourceUrls, true)) $sourceUrls[] = $url;
    }
    $reasoner = is_array($pipeline['deepseek_reasoner'] ?? null) ? $pipeline['deepseek_reasoner'] : [];
    $reasonerAttempt = is_array($pipeline['deepseek_reasoner_attempt'] ?? null) ? $pipeline['deepseek_reasoner_attempt'] : [];
    $reasonerBackend = (string)($reasoner['backend'] ?? 'not_run');
    if ($reasonerBackend === 'not_run') {
        $reasonerBackend = (string)($reasonerAttempt['backend'] ?? 'not_run');
    }
    $reasonerModel = trim((string)($reasoner['model'] ?? ''));
    $reasonerRequestedModel = trim((string)($reasonerAttempt['requested_model'] ?? ''));
    $reasonerModelMatches = $reasonerBackend === 'local_ollama'
        ? strtolower($reasonerModel) === 'deepseek-r1:8b'
        : ($reasonerBackend === 'hosted_api' && livePipelineIsSupportedDeepSeekModel($reasonerModel));
    $deepseekMetrics = is_array($metrics['deepseek'] ?? null) ? $metrics['deepseek'] : [];
    $deepseekError = is_array($deepseekMetrics['error'] ?? null)
        ? trim((string)($deepseekMetrics['error']['message'] ?? ''))
        : trim((string)($deepseekMetrics['error'] ?? ''));
    $stage = [
        '1_gpt4o_mini_planner'=>[
            'succeeded'=>($trace['1_gpt4o_query_planner'] ?? '') === 'ok'
                && ($pipeline['planner_used'] ?? false) === true
                && ($stageMs['hybrid_query_planner'] ?? 0) > 0
                && $correctOpenAiModel('hybrid_query_planner'),
            'status'=>(string)($trace['1_gpt4o_query_planner'] ?? 'not_reported'),
            'model'=>$openAiModel('hybrid_query_planner') ?: null,
            'planned_query'=>$plannerQuery !== '' ? $plannerQuery : null,
        ],
        '2_playwright_retrieval'=>[
            'succeeded'=>($trace['2_playwright_scraper'] ?? '') === 'ok'
                && ($playwright['success'] ?? false) === true
                && ($playwright['browser_used'] ?? false) === true
                && (int)($playwright['browser_result_count'] ?? 0) > 0
                && $cacheStatus === 'miss'
                && (int)($playwright['result_count'] ?? 0) > 0
                && count($sourceUrls) > 0,
            'status'=>(string)($trace['2_playwright_scraper'] ?? 'not_reported'),
            'cache_status'=>$cacheStatus,
            'browser_used'=>($playwright['browser_used'] ?? false) === true,
            'browser_result_count'=>(int)($playwright['browser_result_count'] ?? 0),
            'browser_search_errors'=>is_array($playwright['browser_search_errors'] ?? null) ? $playwright['browser_search_errors'] : [],
            'evidence_count'=>(int)($playwright['result_count'] ?? 0),
            'source_urls'=>$sourceUrls,
            'retrieval_time_ms'=>(int)($playwright['latency_ms'] ?? $metrics['timings_ms']['playwright_ms'] ?? 0),
        ],
        '3_gpt4o_mini_evidence_structurer'=>[
            'succeeded'=>($trace['3_gpt4o_structurer'] ?? '') === 'ok'
                && ($stageMs['hybrid_cleaner'] ?? 0) > 0
                && $correctOpenAiModel('hybrid_cleaner'),
            'status'=>(string)($trace['3_gpt4o_structurer'] ?? 'not_reported'),
            'model'=>$openAiModel('hybrid_cleaner') ?: null,
        ],
        '4_deepseek_r1_reasoner'=>[
            'succeeded'=>($trace['4_deepseek_r1_reasoner'] ?? '') === 'ok'
                && in_array($reasonerBackend, ['local_ollama', 'hosted_api'], true)
                && $reasonerModelMatches,
            'status'=>(string)($trace['4_deepseek_r1_reasoner'] ?? 'not_reported'),
            'backend'=>$reasonerBackend,
            'model'=>$reasonerModel !== '' ? $reasonerModel : null,
            'requested_model'=>$reasonerRequestedModel !== '' ? $reasonerRequestedModel : null,
            'model_matches'=>$reasonerModelMatches,
            'error'=>$deepseekError !== '' ? $deepseekError : null,
        ],
        '5_gpt4o_mini_final_response'=>[
            'succeeded'=>($trace['5_gpt4o_final_reply'] ?? '') === 'ok'
                && trim((string)($data['answer'] ?? '')) !== ''
                && ($stageMs['hybrid_final'] ?? 0) > 0
                && $correctOpenAiModel('hybrid_final'),
            'status'=>(string)($trace['5_gpt4o_final_reply'] ?? 'not_reported'),
            'model'=>$openAiModel('hybrid_final') ?: null,
        ],
    ];
    return $stage;
}

function liveProviderCallEvidence(string $provider, array $metrics, bool $requestSuccess, string $expectedBackend = 'auto'): array {
    $timings = is_array($metrics['timings_ms']['ai_stages_ms'] ?? null) ? $metrics['timings_ms']['ai_stages_ms'] : [];
    $models = is_array($metrics['pipeline']['stage_models'] ?? null) ? $metrics['pipeline']['stage_models'] : [];

    if ($provider === 'deepseek') {
        $deepseekMetrics = is_array($metrics['deepseek'] ?? null) ? $metrics['deepseek'] : [];
        $callModel = trim((string)($models['deepseek_api'] ?? $models['deepseek_single'] ?? $deepseekMetrics['model'] ?? ''));
        $callMs = (int)($timings['deepseek_api'] ?? $timings['deepseek_single'] ?? 0);
        $reportedSuccess = ($deepseekMetrics['success'] ?? false) === true
            && ($deepseekMetrics['error'] ?? null) === null;
        $backend = !empty($metrics['deepseek_api']['configured']) ? 'hosted_api' : 'local_ollama';
        $modelMatches = $backend === 'local_ollama'
            ? strtolower($callModel) === 'deepseek-r1:8b'
            : livePipelineIsSupportedDeepSeekModel($callModel);
        $backendMatches = $expectedBackend === 'auto'
            || ($expectedBackend === 'hosted-api' && $backend === 'hosted_api')
            || ($expectedBackend === 'local-ollama' && $backend === 'local_ollama');
    } elseif ($provider === 'gpt-4o-mini') {
        $gptMetrics = is_array($metrics['gpt4o'] ?? null) ? $metrics['gpt4o'] : [];
        $callModel = trim((string)($models['gpt4o_single'] ?? $gptMetrics['model'] ?? ''));
        $callMs = (int)($timings['gpt4o_single'] ?? 0);
        $reportedSuccess = ($gptMetrics['success'] ?? false) === true;
        $modelMatches = str_starts_with(strtolower($callModel), 'gpt-4o-mini');
        $backend = null;
        $backendMatches = true;
    } else {
        throw new InvalidArgumentException('Provider call evidence supports deepseek or gpt-4o-mini.');
    }

    return [
        'provider'=>$provider,
        'succeeded'=>$requestSuccess && $reportedSuccess && $callMs > 0 && $modelMatches && $backendMatches,
        'status'=>!$reportedSuccess || $callMs <= 0
            ? 'call_not_verified'
            : (!$modelMatches
                ? 'unexpected_model'
                : (!$backendMatches ? 'unexpected_backend' : ($requestSuccess ? 'ok' : 'request_not_ready'))),
        'model'=>$callModel !== '' ? $callModel : null,
        'model_matches'=>$modelMatches,
        'backend'=>$backend,
        'backend_matches'=>$backendMatches,
        'latency_ms'=>$callMs,
    ];
}

function livePipelineBuildResult(array $case, array $response, float $clientLatencyMs, array $runConfiguration = []): array {
    $data = is_array($response['data'] ?? null) ? $response['data'] : [];
    $metrics = is_array($data['metrics'] ?? null) ? $data['metrics'] : [];
    $stages = livePipelineStageResults($data);
    $allStagesSucceeded = count(array_filter($stages, static fn(array $stage): bool => $stage['succeeded'])) === 5;
    $requestSuccess = (int)($response['http_status'] ?? 0) >= 200
        && (int)($response['http_status'] ?? 0) < 300
        && ($data['assistantStatus'] ?? '') === 'ready'
        && trim((string)($data['answer'] ?? '')) !== '';
    $cacheStatus = (string)($metrics['playwright']['cache'] ?? $metrics['cache']['playwright'] ?? 'unknown');
    $fixtureUsed = (bool)($metrics['playwright']['fixture_used'] ?? false)
        || str_contains(strtolower($cacheStatus), 'injected')
        || str_contains(strtolower($cacheStatus), 'fixture');
    $loop = is_array($metrics['agentic_loop'] ?? null) ? $metrics['agentic_loop'] : [];
    $violations = is_array($loop['violations_detected'] ?? null) ? $loop['violations_detected'] : [];
    $violationsRepaired = (int)($loop['violations_repaired'] ?? 0);
    $loopEnabled = ($loop['enabled'] ?? false) === true;
    $loopStatus = !$loopEnabled ? 'disabled' : ((int)($loop['iterations'] ?? 0) > 0 ? 'ran' : 'enabled_but_not_observed');
    $loopOutcome = $violations
        ? ($violationsRepaired > 0 ? 'violation_detected_and_repaired' : 'violation_detected_not_repaired')
        : (!$requestSuccess || !$loopEnabled ? 'not_observed' : 'no_violation_detected');
    $controlledViolationObserved = $requestSuccess && $loopEnabled && (int)($loop['iterations'] ?? 0) > 0;
    $controlledViolationStatus = !(bool)($case['controlled_violation'] ?? false)
        ? 'not_applicable'
        : (!$controlledViolationObserved
            ? 'not_observed'
            : (count($violations) > 0
                ? ($violationsRepaired > 0 ? 'detected_and_repaired' : 'detected_not_repaired')
                : 'not_detected'));
    $behaviorCheck = (string)($case['behavior_check'] ?? '');
    $behaviorCheckEvidence = [];
    if ($behaviorCheck === 'impossible_budget_refusal') {
        $behaviorCheckEvidence = liveImpossibleBudgetResponseEvidence(
            (string)($data['answer'] ?? ''),
            (float)($case['max_price_php'] ?? 0)
        );
        $behaviorCheckStatus = !$requestSuccess
            ? 'not_observed'
            : ($behaviorCheckEvidence['no_match_claim']
                && $behaviorCheckEvidence['above_budget_alternative_context']
                && $behaviorCheckEvidence['alternative_price_above_budget']
                ? 'passed'
                : 'failed');
    } elseif ($behaviorCheck === 'price_violation_repair') {
        $behaviorCheckStatus = $controlledViolationStatus;
    } else {
        $behaviorCheckStatus = 'not_applicable';
    }
    $requestedDeepseekBackend = (string)($runConfiguration['deepseek_backend'] ?? 'auto');
    $observedDeepseekBackend = (string)($stages['4_deepseek_r1_reasoner']['backend'] ?? 'not_run');
    $backendMatches = $requestedDeepseekBackend === 'auto'
        || ($requestedDeepseekBackend === 'local-ollama' && $observedDeepseekBackend === 'local_ollama')
        || ($requestedDeepseekBackend === 'hosted-api' && $observedDeepseekBackend === 'hosted_api');
    $fullPipelineSuccess = $requestSuccess && $allStagesSucceeded && !$fixtureUsed && $backendMatches;
    $controlledViolationObserved = $requestSuccess && $loopEnabled && (int)($loop['iterations'] ?? 0) > 0;

    return [
        'case_id'=>(string)$case['id'],
        'query'=>(string)$case['query'],
        'controlled_violation_case'=>(bool)$case['controlled_violation'],
        'controlled_violation_expected'=>(bool)$case['controlled_violation'],
        'controlled_violation_status'=>$controlledViolationStatus,
        'controlled_violation_detected'=>!(bool)($case['controlled_violation'] ?? false) || !$controlledViolationObserved
            ? null
            : count($violations) > 0,
        'controlled_violation_repaired'=>!(bool)($case['controlled_violation'] ?? false) || !$controlledViolationObserved
            ? null
            : $violationsRepaired > 0,
        'behavior_check'=>[
            'type'=>$behaviorCheck !== '' ? $behaviorCheck : 'none',
            'status'=>$behaviorCheckStatus,
            'evidence'=>$behaviorCheckEvidence,
        ],
        'configuration'=>[
            'provider'=>'hybrid',
            'agentic_refinement_enabled'=>true,
            'iterative_loop_enabled'=>true,
            'cache_policy'=>'bypass',
            'temperature'=>0.2,
            'prompt_style'=>'baseline',
            'seed'=>42,
            'deepseek_backend_requested'=>$requestedDeepseekBackend,
            'deepseek_backend_request_matches_observed'=>$backendMatches,
            'requested_backend_prerequisite_met'=>(bool)($runConfiguration['backend_prerequisite_met'] ?? true),
            'evidence_override_used'=>false,
            'api_endpoint'=>(string)($response['api_url'] ?? '') === 'local'
                ? 'local_php_worker'
                : livePipelineSafeUrl((string)($response['api_url'] ?? '')),
        ],
        'stages'=>$stages,
        'all_five_stages_succeeded_on_live_request'=>$fullPipelineSuccess && $cacheStatus === 'miss',
        'execution_class'=>$fullPipelineSuccess && $cacheStatus === 'miss'
            ? 'full_live_pipeline'
            : ($requestSuccess ? 'partial_or_fallback' : 'failed_request'),
        'request_success'=>$requestSuccess,
        'http_status'=>(int)($response['http_status'] ?? 0),
        'assistant_status'=>(string)($data['assistantStatus'] ?? 'unavailable'),
        'final_answer'=>(string)($data['answer'] ?? ''),
        'model_used'=>(string)($data['modelUsed'] ?? ''),
        'client_latency_ms'=>(int)round($clientLatencyMs),
        'server_latency_ms'=>(int)($metrics['total_latency_ms'] ?? 0),
        'cache_status'=>$cacheStatus,
        'evidence_count'=>(int)($metrics['playwright']['result_count'] ?? 0),
        'source_urls'=>$stages['2_playwright_retrieval']['source_urls'],
        'retrieval_time_ms'=>(int)$stages['2_playwright_retrieval']['retrieval_time_ms'],
        'loop'=>[
            'requested_enabled'=>true,
            'enabled'=>$loopEnabled,
            'status'=>$requestSuccess ? $loopStatus : 'not_observed',
            'iterations'=>(int)($loop['iterations'] ?? 0),
            'initial_compliance_rate'=>$loop['initial_compliance_rate'] ?? null,
            'final_compliance_rate'=>$loop['final_compliance_rate'] ?? null,
            'violations_detected'=>$violations,
            'violations_repaired'=>$violationsRepaired,
            're_retrieved'=>(bool)($loop['re_retrieved'] ?? false),
            'measured_outcome'=>$loopOutcome,
        ],
        'error_category'=>$response['error_category'] ?? null,
        'fixture_used'=>$fixtureUsed,
    ];
}

function runLivePipelineVerification(string $apiUrl, string $outputDirectory, array $runConfiguration = []): array {
    $timestamp = gmdate('Ymd\THis\Z');
    $records = [];
    $safeApiUrl = livePipelineSafeUrl($apiUrl);
    $curlAvailable = function_exists('curl_init');
    foreach (LIVE_PIPELINE_QUERIES as $case) {
        $startedAt = microtime(true);
        $response = [
            'api_url'=>$apiUrl,
            'http_status'=>0,
            'data'=>[],
            'error_category'=>null,
        ];
        if (!$curlAvailable) {
            $response['error_category'] = 'curl_extension_unavailable';
        } else {
            $payload = json_encode([
                'query'=>$case['query'],
                'history'=>[],
                'shownNames'=>[],
                'evaluation'=>[
                    'provider'=>'hybrid',
                    'agentic'=>true,
                    'iterativeLoop'=>true,
                    'cachePolicy'=>'bypass',
                    'temperature'=>0.2,
                    'promptStyle'=>'baseline',
                    'seed'=>42,
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            $handle = curl_init($apiUrl);
            $requestHeaders = ['Content-Type: application/json', 'Accept: application/json'];
            $requestedBackend = (string)($runConfiguration['deepseek_backend'] ?? 'auto');
            if (in_array($requestedBackend, ['local-ollama', 'hosted-api'], true)) {
                $requestHeaders[] = 'X-DFF-DeepSeek-Backend: ' . $requestedBackend;
            }
            curl_setopt_array($handle, [
                CURLOPT_POST=>true,
                CURLOPT_POSTFIELDS=>$payload,
                CURLOPT_HTTPHEADER=>$requestHeaders,
                CURLOPT_RETURNTRANSFER=>true,
                CURLOPT_CONNECTTIMEOUT=>10,
                CURLOPT_TIMEOUT=>300,
            ]);
            $raw = curl_exec($handle);
            $response['http_status'] = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
            $transportError = curl_errno($handle);
            curl_close($handle);
            if ($raw === false || $transportError !== 0) {
                $response['error_category'] = 'api_unreachable_or_timeout';
            } else {
                $decoded = json_decode((string)$raw, true);
                if (is_array($decoded)) {
                    $response['data'] = $decoded;
                } else {
                    $response['error_category'] = 'invalid_json_response';
                }
                if ($response['http_status'] < 200 || $response['http_status'] >= 300) {
                    $response['error_category'] = 'http_status_' . $response['http_status'];
                }
            }
        }
        $records[] = livePipelineBuildResult($case, $response, (microtime(true) - $startedAt) * 1000, $runConfiguration);
    }

    if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0775, true) && !is_dir($outputDirectory)) {
        throw new RuntimeException('Unable to create the report output directory.');
    }
    $basePath = rtrim($outputDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'live_pipeline_verification_' . $timestamp;
    $report = [
        'report_type'=>'live_hybrid_agentic_pipeline_verification',
        'created_at_utc'=>gmdate('c'),
        'api_endpoint'=>$safeApiUrl,
        'run_configuration'=>[
            'provider'=>'hybrid',
            'agentic_refinement_enabled'=>true,
            'iterative_loop_enabled'=>true,
            'playwright_cache_policy'=>'bypass',
            'evidence_override_used'=>false,
            'requests_are_direct_api_posts'=>true,
            'deepseek_backend_requested'=>(string)($runConfiguration['deepseek_backend'] ?? 'auto'),
            'local_server_started'=>(bool)($runConfiguration['local_server_started'] ?? false),
            'requested_backend_prerequisite_met'=>(bool)($runConfiguration['backend_prerequisite_met'] ?? true),
        ],
        'summary'=>[
            'requests'=>count($records),
            'request_successes'=>count(array_filter($records, static fn(array $record): bool => $record['request_success'])),
            'full_live_pipeline_successes'=>count(array_filter($records, static fn(array $record): bool => $record['all_five_stages_succeeded_on_live_request'])),
            'impossible_budget_refusal_cases'=>count(array_filter($records, static fn(array $record): bool => $record['behavior_check']['type'] === 'impossible_budget_refusal')),
            'impossible_budget_refusal_passed'=>count(array_filter($records, static fn(array $record): bool => $record['behavior_check']['type'] === 'impossible_budget_refusal' && $record['behavior_check']['status'] === 'passed')),
            'price_violation_repair_cases'=>count(array_filter($records, static fn(array $record): bool => $record['behavior_check']['type'] === 'price_violation_repair')),
            'price_violation_repair_detected_and_repaired'=>count(array_filter($records, static fn(array $record): bool => $record['behavior_check']['type'] === 'price_violation_repair' && $record['behavior_check']['status'] === 'detected_and_repaired')),
            'controlled_violation_detected'=>count(array_filter($records, static fn(array $record): bool => $record['controlled_violation_detected'] === true)),
            'controlled_violation_repaired'=>count(array_filter($records, static fn(array $record): bool => $record['controlled_violation_repaired'] === true)),
        ],
        'requests'=>$records,
    ];
    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false || file_put_contents($basePath . '.json', $json . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write the JSON verification report.');
    }
    $csv = fopen($basePath . '.csv', 'wb');
    if ($csv === false) throw new RuntimeException('Unable to write the CSV verification report.');
    fputcsv($csv, ['case_id','query','provider','agentic_refinement_enabled','iterative_loop_enabled','cache_policy','temperature','prompt_style','seed','evidence_override_used','deepseek_backend_requested','deepseek_backend_request_matches_observed','requested_backend_prerequisite_met','behavior_check_type','behavior_check_status','controlled_violation_status','stages','deepseek_backend','deepseek_model','cache_status','evidence_count','source_urls','retrieval_time_ms','client_latency_ms','server_latency_ms','loop','request_success','all_five_stages_succeeded_on_live_request','execution_class','http_status','error_category']);
    foreach ($records as $record) {
        fputcsv($csv, [
            $record['case_id'],
            $record['query'],
            $record['configuration']['provider'],
            $record['configuration']['agentic_refinement_enabled'] ? 'true' : 'false',
            $record['configuration']['iterative_loop_enabled'] ? 'true' : 'false',
            $record['configuration']['cache_policy'],
            $record['configuration']['temperature'],
            $record['configuration']['prompt_style'],
            $record['configuration']['seed'],
            $record['configuration']['evidence_override_used'] ? 'true' : 'false',
            $record['configuration']['deepseek_backend_requested'],
            $record['configuration']['deepseek_backend_request_matches_observed'] ? 'true' : 'false',
            $record['configuration']['requested_backend_prerequisite_met'] ? 'true' : 'false',
            $record['behavior_check']['type'],
            $record['behavior_check']['status'],
            $record['controlled_violation_status'],
            json_encode($record['stages'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            $record['stages']['4_deepseek_r1_reasoner']['backend'] ?? '',
            $record['stages']['4_deepseek_r1_reasoner']['model'] ?? '',
            $record['cache_status'],
            $record['evidence_count'],
            json_encode($record['source_urls'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            $record['retrieval_time_ms'],
            $record['client_latency_ms'],
            $record['server_latency_ms'],
            json_encode($record['loop'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            $record['request_success'] ? 'true' : 'false',
            $record['all_five_stages_succeeded_on_live_request'] ? 'true' : 'false',
            $record['execution_class'],
            $record['http_status'],
            $record['error_category'] ?? '',
        ]);
    }
    fclose($csv);
    return ['json_path'=>$basePath . '.json', 'csv_path'=>$basePath . '.csv', 'report'=>$report];
}

function parseLivePipelineArguments(array $arguments): array {
    $options = [
        'api_url'=>'http://127.0.0.1:8080/api.php',
        'output_dir'=>__DIR__ . '/results',
        'start_local_server'=>false,
        'port'=>8080,
        'deepseek_backend'=>'auto',
    ];
    foreach ($arguments as $argument) {
        if ($argument === '--start-local-server') {
            $options['start_local_server'] = true;
        } elseif (str_starts_with($argument, '--api-url=')) {
            $options['api_url'] = trim(substr($argument, strlen('--api-url=')));
        } elseif (str_starts_with($argument, '--output-dir=')) {
            $options['output_dir'] = trim(substr($argument, strlen('--output-dir=')));
        } elseif (str_starts_with($argument, '--port=')) {
            $options['port'] = (int)substr($argument, strlen('--port='));
            if ($options['port'] < 1 || $options['port'] > 65535) {
                throw new InvalidArgumentException('--port must be between 1 and 65535.');
            }
        } elseif (str_starts_with($argument, '--deepseek-backend=')) {
            $options['deepseek_backend'] = strtolower(trim(substr($argument, strlen('--deepseek-backend='))));
            if (!in_array($options['deepseek_backend'], ['auto', 'local-ollama', 'hosted-api'], true)) {
                throw new InvalidArgumentException('--deepseek-backend must be auto, local-ollama, or hosted-api.');
            }
        } else {
            throw new InvalidArgumentException("Unknown option: {$argument}");
        }
    }
    if ($options['start_local_server']) {
        $options['api_url'] = 'http://127.0.0.1:' . $options['port'] . '/api.php';
    } elseif ($options['port'] !== 8080) {
        throw new InvalidArgumentException('--port requires --start-local-server.');
    }
    if ($options['deepseek_backend'] !== 'auto' && !$options['start_local_server']) {
        throw new InvalidArgumentException('Explicit --deepseek-backend selection requires --start-local-server.');
    }
    if ($options['api_url'] === '' || filter_var($options['api_url'], FILTER_VALIDATE_URL) === false) {
        throw new InvalidArgumentException('--api-url must be a valid HTTP(S) URL.');
    }
    $scheme = strtolower((string)parse_url($options['api_url'], PHP_URL_SCHEME));
    if (!in_array($scheme, ['http', 'https'], true)) {
        throw new InvalidArgumentException('--api-url must use HTTP or HTTPS.');
    }
    return $options;
}

function printLivePipelineUsage(): void {
    echo "Usage: php evaluation/run_live_pipeline_verification.php [options]\n";
    echo "  --start-local-server       Start a temporary local PHP API server.\n";
    echo "  --port=PORT                Local server port (requires --start-local-server).\n";
    echo "  --api-url=URL              Use an already-running api.php endpoint.\n";
    echo "  --deepseek-backend=MODE    auto, local-ollama, or hosted-api.\n";
    echo "  --output-dir=PATH          Report output directory.\n";
    echo "Provider keys must be set in environment variables, not CLI arguments.\n";
}

function configureLivePipelineDeepseekBackend(string $backend): bool {
    if ($backend === 'local-ollama') {
        putenv('DEEPSEEK_API_KEY=');
        $_ENV['DEEPSEEK_API_KEY'] = '';
        $_SERVER['DEEPSEEK_API_KEY'] = '';
        if (trim((string)getenv('DEEPSEEK_BASE_URL')) === '' || str_starts_with(strtolower((string)getenv('DEEPSEEK_BASE_URL')), 'https://')) {
            putenv('DEEPSEEK_BASE_URL=http://127.0.0.1:11434');
            $_ENV['DEEPSEEK_BASE_URL'] = 'http://127.0.0.1:11434';
            $_SERVER['DEEPSEEK_BASE_URL'] = 'http://127.0.0.1:11434';
        }
        if (trim((string)getenv('DEEPSEEK_MODEL')) === '' || strtolower((string)getenv('DEEPSEEK_MODEL')) === 'deepseek-reasoner') {
            putenv('DEEPSEEK_MODEL=deepseek-r1:8b');
            $_ENV['DEEPSEEK_MODEL'] = 'deepseek-r1:8b';
            $_SERVER['DEEPSEEK_MODEL'] = 'deepseek-r1:8b';
        }
        return true;
    } elseif ($backend === 'hosted-api') {
        $hasApiKey = trim((string)getenv('DEEPSEEK_API_KEY')) !== '';
        $baseUrl = trim((string)getenv('DEEPSEEK_API_BASE_URL')) ?: 'https://api.deepseek.com';
        putenv('DEEPSEEK_BASE_URL=' . $baseUrl);
        $_ENV['DEEPSEEK_BASE_URL'] = $baseUrl;
        $_SERVER['DEEPSEEK_BASE_URL'] = $baseUrl;
        if (trim((string)getenv('DEEPSEEK_API_MODEL')) === '') {
            $model = trim((string)getenv('DEEPSEEK_API_MODEL')) ?: 'deepseek-flash';
            putenv('DEEPSEEK_API_MODEL=' . $model);
            $_ENV['DEEPSEEK_API_MODEL'] = $model;
            $_SERVER['DEEPSEEK_API_MODEL'] = $model;
        }
        return $hasApiKey;
    }
    return true;
}

function startLivePipelinePhpServer(int $port): mixed {
    $appRoot = dirname(__DIR__);
    $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $process = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $appRoot],
        [0=>['file', $nullDevice, 'r'], 1=>['file', $nullDevice, 'a'], 2=>['file', $nullDevice, 'a']],
        $pipes,
        $appRoot
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start the local PHP API server.');
    }
    $deadline = microtime(true) + 10;
    do {
        $status = proc_get_status($process);
        if (!$status['running']) {
            proc_close($process);
            throw new RuntimeException('The local PHP API server exited during startup; check whether the selected port is available.');
        }
        $handle = curl_init('http://127.0.0.1:' . $port . '/api.php');
        curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>1, CURLOPT_TIMEOUT=>1]);
        curl_exec($handle);
        $httpStatus = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);
        if ($httpStatus === 405) return $process;
        usleep(200000);
    } while (microtime(true) < $deadline);
    proc_terminate($process);
    proc_close($process);
    throw new RuntimeException('The local API server did not become ready within 10 seconds.');
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $serverProcess = null;
    try {
        $arguments = array_slice($argv, 1);
        if (in_array('--help', $arguments, true) || in_array('-h', $arguments, true)) {
            printLivePipelineUsage();
            exit(0);
        }
        $options = parseLivePipelineArguments($arguments);
        $backendPrerequisiteMet = $options['deepseek_backend'] === 'auto'
            || configureLivePipelineDeepseekBackend($options['deepseek_backend']);
        if ($options['start_local_server']) $serverProcess = startLivePipelinePhpServer($options['port']);
        $runConfiguration = [
            'deepseek_backend'=>$options['deepseek_backend'],
            'local_server_started'=>$options['start_local_server'],
            'backend_prerequisite_met'=>$backendPrerequisiteMet,
        ];
        $result = runLivePipelineVerification($options['api_url'], $options['output_dir'], $runConfiguration);
        echo 'JSON report: ' . $result['json_path'] . PHP_EOL;
        echo 'CSV report: ' . $result['csv_path'] . PHP_EOL;
        echo 'Full live pipeline successes: ' . $result['report']['summary']['full_live_pipeline_successes'] . '/' . $result['report']['summary']['requests'] . PHP_EOL;
    } catch (Throwable $error) {
        fwrite(STDERR, $error->getMessage() . PHP_EOL);
        exit(1);
    } finally {
        if (is_resource($serverProcess)) {
            proc_terminate($serverProcess);
            proc_close($serverProcess);
        }
    }
}
