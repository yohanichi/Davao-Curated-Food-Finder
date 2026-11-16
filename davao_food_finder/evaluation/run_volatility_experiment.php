<?php
declare(strict_types=1);

/**
 * Interactive live-pipeline verification runner, with the controlled volatility benchmark available on request.
 *
 * Default mode asks for live user requests and verifies the actual hybrid pipeline.
 * The optional controlled benchmark compares:
 *   1. STATIC-BASELINE        : Static 2025 CSV retrieval (no web reconciliation)
 *   2. STALE-CACHE-12H        : Agentic pipeline reading an outdated (>12h) pre-change cached web snapshot
 *   3. ABLATION-NO-LOOP-FRESH : Single-pass retrieval + fresh web snapshot WITHOUT Pass 2 autonomous critique/repair
 *   4. AGENTIC-LOOP-FRESH     : Full 2-pass Autonomous Iterative Critique-and-Refinement Loop + fresh web snapshot
 *
 * Usage:
 *   php evaluation/run_volatility_experiment.php --local-cli --live-provider=hybrid
 *   php evaluation/run_volatility_experiment.php --local-cli --live-provider=deepseek
 *   php evaluation/run_volatility_experiment.php --local-cli --live-provider=gpt-4o-mini
 *   DeepSeek defaults to the paid DeepSeek API (deepseek-flash); local Ollama is opt-in.
 *   php evaluation/run_volatility_experiment.php --controlled-scenarios --local-cli
 */

$appRoot = dirname(__DIR__);
require_once $appRoot . '/vendor/autoload.php';
require_once $appRoot . '/src/search.php';
require_once __DIR__ . '/statistics.php';
require_once __DIR__ . '/metrics.php';
require_once __DIR__ . '/run_live_pipeline_verification.php';

function failVol(string $msg): never {
    fwrite(STDERR, "Error: {$msg}\n");
    exit(1);
}

$apiUrl = 'http://127.0.0.1:8080/api.php';
$localCli = false;
$noSave = false;
$controlledScenarios = false;
$extraLiveProvider = '';
$deepseekBackend = 'hosted-api';
$deepseekBackendOptionSet = false;
$casesPath = __DIR__ . '/volatility_cases.json';

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--local-cli') {
        $localCli = true;
        $apiUrl = 'local';
    } elseif ($arg === '--controlled-scenarios') {
        $controlledScenarios = true;
    } elseif ($arg === '--no-save') {
        $noSave = true;
    } elseif (str_starts_with($arg, '--api-url=')) {
        $apiUrl = substr($arg, strlen('--api-url='));
        if (strtolower(trim($apiUrl)) === 'local') {
            $localCli = true;
            $apiUrl = 'local';
        }
    } elseif (str_starts_with($arg, '--live-provider=')) {
        $extraLiveProvider = strtolower(trim(substr($arg, strlen('--live-provider='))));
        if (!in_array($extraLiveProvider, ['deepseek', 'gpt-4o-mini', 'hybrid'], true)) {
            failVol('--live-provider must be deepseek, gpt-4o-mini, or hybrid.');
        }
    } elseif (str_starts_with($arg, '--deepseek-backend=')) {
        $deepseekBackend = strtolower(trim(substr($arg, strlen('--deepseek-backend='))));
        $deepseekBackendOptionSet = true;
        if (!in_array($deepseekBackend, ['local-ollama', 'hosted-api'], true)) {
            failVol('--deepseek-backend must be local-ollama or hosted-api.');
        }
    } elseif (str_starts_with($arg, '--cases=')) {
        $casesPath = substr($arg, strlen('--cases='));
    } else {
        failVol("Unknown option {$arg}. Supported: --local-cli, --api-url=URL, --controlled-scenarios, --live-provider=deepseek|gpt-4o-mini|hybrid, --deepseek-backend=local-ollama|hosted-api, --cases=PATH, --no-save");
    }
}

if (!$controlledScenarios) {
    $liveProvider = $extraLiveProvider !== '' ? $extraLiveProvider : 'hybrid';
    if (!in_array($liveProvider, ['deepseek', 'gpt-4o-mini', 'hybrid'], true)) {
        failVol('--live-provider must be deepseek, gpt-4o-mini, or hybrid.');
    }
    runInteractiveLiveRequests($apiUrl, $liveProvider, $deepseekBackend, $noSave);
    exit(0);
}

if ($extraLiveProvider !== '' || $deepseekBackendOptionSet) {
    failVol('--live-provider and --deepseek-backend are for interactive live requests; do not combine them with --controlled-scenarios.');
}

if (!is_file($casesPath)) {
    failVol("Volatility benchmark file not found: {$casesPath}");
}

$spec = json_decode((string)file_get_contents($casesPath), true, 512, JSON_THROW_ON_ERROR);
$scenarios = is_array($spec['scenarios'] ?? null) ? $spec['scenarios'] : [];
if (!$scenarios) {
    failVol('No volatility scenarios found in volatility_cases.json.');
}

function sendVolatilityApiRequest(
    string $apiUrl,
    string $query,
    string $provider,
    bool $agentic,
    ?array $evidenceOverride,
    string $cachePolicy = 'none',
    string $promptStyle = 'strict_grounding',
    string $deepseekBackend = 'auto'
): array {
    $evalBlock = [
        'provider'=>$provider,
        'temperature'=>0.0,
        'promptStyle'=>$promptStyle,
        'agentic'=>$agentic,
        'iterativeLoop'=>$agentic && !in_array($provider, ['ablation-no-loop', 'baseline', 'non-agentic'], true),
        'cachePolicy'=>$cachePolicy,
        'seed'=>42,
    ];
    if ($evidenceOverride !== null) {
        $evalBlock['evidenceOverride'] = $evidenceOverride;
    }

    $body = json_encode([
        'query'=>$query,
        'history'=>[],
        'shownNames'=>[],
        'evaluation'=>$evalBlock,
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);

    if ($apiUrl === 'local') {
        $apiFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'api.php';
        $phpBin = PHP_BINARY ?: 'php';
        $runnerCode = '$_SERVER["REQUEST_METHOD"]="POST"; $_SERVER["REMOTE_ADDR"]="127.0.0.1"; $_SERVER["HTTP_X_DFF_DEEPSEEK_BACKEND"]=' . var_export($deepseekBackend, true) . '; chdir(' . var_export(dirname($apiFile), true) . '); require ' . var_export($apiFile, true) . ';';
        $runnerCode .= 'fwrite(STDERR, "\n__HTTP_STATUS__" . (http_response_code() ?: 200));';
        $started = microtime(true);
        $pipes = [];
        $proc = proc_open([$phpBin, '-r', $runnerCode], [0=>['pipe', 'r'], 1=>['pipe', 'w'], 2=>['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            return ['latency'=>0.0, 'failure'=>'Could not start local PHP process', 'http_status'=>0, 'error_category'=>'local_worker_start_failed', 'data'=>null, 'api_url'=>$apiUrl];
        }
        fwrite($pipes[0], $body);
        fclose($pipes[0]);
        $raw = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $latency = microtime(true) - $started;
        $httpStatus = 0;
        if (preg_match('/\n__HTTP_STATUS__(\d+)\s*$/', (string)$stderr, $statusMatch)) {
            $httpStatus = (int)$statusMatch[1];
            $stderr = preg_replace('/\n__HTTP_STATUS__\d+\s*$/', '', (string)$stderr) ?? (string)$stderr;
        }
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($decoded) || $exit !== 0 || $httpStatus < 200 || $httpStatus >= 300) {
            $category = $httpStatus > 0 ? 'http_status_' . $httpStatus : 'local_worker_error';
            return ['latency'=>$latency, 'failure'=>'Local worker error: ' . trim((string)$stderr), 'http_status'=>$httpStatus, 'error_category'=>$category, 'data'=>$decoded, 'api_url'=>$apiUrl];
        }
        return ['latency'=>$latency, 'failure'=>null, 'http_status'=>$httpStatus, 'error_category'=>null, 'data'=>$decoded, 'api_url'=>$apiUrl];
    }

    if (!function_exists('curl_init')) {
        return ['latency'=>0.0, 'failure'=>'The PHP curl extension is unavailable.', 'http_status'=>0, 'error_category'=>'curl_extension_unavailable', 'data'=>null, 'api_url'=>$apiUrl];
    }
    $ch = curl_init($apiUrl);
    $headers = ['Content-Type: application/json'];
    if (in_array($deepseekBackend, ['local-ollama', 'hosted-api'], true)) {
        $headers[] = 'X-DFF-DeepSeek-Backend: ' . $deepseekBackend;
    }
    curl_setopt_array($ch, [
        CURLOPT_POST=>true,
        CURLOPT_HTTPHEADER=>$headers,
        CURLOPT_POSTFIELDS=>$body,
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CONNECTTIMEOUT=>10,
        CURLOPT_TIMEOUT=>300,
    ]);
    $started = microtime(true);
    $raw = curl_exec($ch);
    $latency = microtime(true) - $started;
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    $curlErrno = curl_errno($ch);
    curl_close($ch);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($decoded) || $curlErrno !== 0 || $http < 200 || $http >= 300) {
        $category = $curlErrno !== 0 ? 'api_unreachable_or_timeout' : ($http > 0 ? 'http_status_' . $http : 'invalid_json_response');
        return ['latency'=>$latency, 'failure'=>$err !== '' ? $err : "HTTP {$http}", 'http_status'=>$http, 'error_category'=>$category, 'data'=>$decoded, 'api_url'=>$apiUrl];
    }
    return ['latency'=>$latency, 'failure'=>null, 'http_status'=>$http, 'error_category'=>null, 'data'=>$decoded, 'api_url'=>$apiUrl];
}

function printLiveRequestResult(array $record): void {
    echo 'Request: ' . $record['query'] . "\n";
    foreach ($record['stages'] as $name => $stage) {
        $status = str_starts_with((string)($stage['status'] ?? ''), 'not_run_')
            ? 'N/A'
            : ($stage['succeeded'] ? 'PASS' : 'FAIL');
        $details = (string)($stage['status'] ?? 'not_reported');
        if ($name === '2_playwright_retrieval') {
            $details .= ', cache=' . ($stage['cache_status'] ?? 'unknown')
                . ', browser=' . (!empty($stage['browser_used']) ? 'used' : 'not-used')
                . ', evidence=' . (int)($stage['evidence_count'] ?? 0)
                . ', browser-results=' . (int)($stage['browser_result_count'] ?? 0)
                . ', sources=' . count($stage['source_urls'] ?? []);
            if (!empty($stage['browser_search_errors'][0])) {
                $details .= ', browser-error=' . $stage['browser_search_errors'][0];
            }
        } elseif ($name === '4_deepseek_r1_reasoner') {
            $details .= ', backend=' . ($stage['backend'] ?? 'not_run')
                . ', model=' . ($stage['model'] ?? 'unknown')
                . ', requested_model=' . ($stage['requested_model'] ?? 'unknown')
                . ', model_ok=' . (!empty($stage['model_matches']) ? 'yes' : 'no');
            if (!empty($stage['error'])) {
                $details .= ', error=' . mb_substr(str_replace(["\r", "\n"], ' ', (string)$stage['error']), 0, 240);
            }
        } elseif ($name === '1_gpt4o_mini_planner' && !empty($stage['planned_query'])) {
            $details .= ', planned_query=' . $stage['planned_query'];
        } elseif ($name === 'provider_llm_call') {
            $details .= ', provider=' . ($stage['provider'] ?? 'unknown')
                . ', model=' . ($stage['model'] ?? 'unknown');
        } elseif (isset($stage['model'])) {
            $details .= ', model=' . ($stage['model'] ?? 'unknown');
        }
        $displayName = $name === '4_deepseek_r1_reasoner' ? '4_deepseek_reasoner' : $name;
        printf("  [%s] %s (%s)\n", $status, $displayName, $details);
    }
    echo '  Selected provider call: ' . ($record['provider_call']['succeeded'] ? 'PASS' : 'FAIL') . "\n";
    echo '  Overall: ' . ($record['all_five_stages_succeeded_on_live_request'] ? 'FULL LIVE PIPELINE' : ($record['request_success'] ? 'NOT A FULL FIVE-STAGE RUN' : 'FAILED REQUEST')) . "\n";
    if ($record['error_category'] !== null) {
        echo '  Request error: ' . $record['error_category'] . "\n";
    }
}

function buildLiveProviderRequestResult(string $provider, string $query, array $response, float $clientLatencyMs, string $deepseekBackend): array {
    $case = ['id'=>sprintf('LIVE-%03d', 1), 'query'=>$query, 'controlled_violation'=>false];
    if ($provider === 'hybrid') {
        $record = livePipelineBuildResult($case, $response, $clientLatencyMs, ['deepseek_backend'=>$deepseekBackend]);
        $record['provider'] = $provider;
        $record['provider_call'] = [
            'provider'=>$provider,
            'succeeded'=>$record['all_five_stages_succeeded_on_live_request'],
            'status'=>$record['all_five_stages_succeeded_on_live_request'] ? 'all_five_stages_ok' : 'one_or_more_stages_failed',
            'model'=>$record['model_used'] !== '' ? $record['model_used'] : null,
        ];
        return $record;
    }

    $data = is_array($response['data'] ?? null) ? $response['data'] : [];
    $metrics = is_array($data['metrics'] ?? null) ? $data['metrics'] : [];
    $stageResults = livePipelineStageResults($data);
    $requestSuccess = (int)($response['http_status'] ?? 0) >= 200
        && (int)($response['http_status'] ?? 0) < 300
        && ($data['assistantStatus'] ?? '') === 'ready'
        && trim((string)($data['answer'] ?? '')) !== '';
    $providerCall = liveProviderCallEvidence($provider, $metrics, $requestSuccess, $deepseekBackend);
    if ($provider === 'deepseek') {
        $stages = [
            '1_gpt4o_mini_planner'=>['succeeded'=>false, 'status'=>'not_run_by_selected_provider'],
            '2_playwright_retrieval'=>$stageResults['2_playwright_retrieval'],
            '3_gpt4o_mini_evidence_structurer'=>['succeeded'=>false, 'status'=>'not_run_by_selected_provider'],
            '4_deepseek_r1_reasoner'=>[
                'succeeded'=>$providerCall['succeeded'],
                'status'=>$providerCall['status'],
                'backend'=>$providerCall['backend'],
                'model'=>$providerCall['model'],
                'model_matches'=>$providerCall['model_matches'],
            ],
            '5_gpt4o_mini_final_response'=>['succeeded'=>false, 'status'=>'not_run_by_selected_provider'],
        ];
    } else {
        $stages = [
            '1_gpt4o_mini_planner'=>['succeeded'=>false, 'status'=>'not_run_by_selected_provider'],
            '2_playwright_retrieval'=>$stageResults['2_playwright_retrieval'],
            '3_gpt4o_mini_evidence_structurer'=>['succeeded'=>false, 'status'=>'not_run_by_selected_provider'],
            '4_deepseek_r1_reasoner'=>['succeeded'=>false, 'status'=>'not_run_by_selected_provider'],
            '5_gpt4o_mini_final_response'=>['succeeded'=>false, 'status'=>'not_run_by_selected_provider'],
        ];
    }
    $stages['provider_llm_call'] = $providerCall;

    return [
        'case_id'=>'LIVE-001',
        'query'=>$query,
        'provider'=>$provider,
        'stages'=>$stages,
        'provider_call'=>$providerCall,
        'request_success'=>$requestSuccess,
        'all_five_stages_succeeded_on_live_request'=>false,
        'execution_class'=>$providerCall['succeeded']
            ? 'live_agentic_single_provider'
            : ($requestSuccess ? 'partial_or_fallback' : 'failed_request'),
        'http_status'=>(int)($response['http_status'] ?? 0),
        'error_category'=>$response['error_category'] ?? null,
        'assistant_status'=>(string)($data['assistantStatus'] ?? 'unavailable'),
        'final_answer'=>(string)($data['answer'] ?? ''),
        'model_used'=>(string)($data['modelUsed'] ?? ''),
        'client_latency_ms'=>(int)round($clientLatencyMs),
        'server_latency_ms'=>(int)($metrics['total_latency_ms'] ?? 0),
        'cache_status'=>(string)($metrics['playwright']['cache'] ?? 'unknown'),
        'evidence_count'=>(int)($metrics['playwright']['result_count'] ?? 0),
        'source_urls'=>$stageResults['2_playwright_retrieval']['source_urls'],
    ];
}

function runInteractiveLiveRequests(string $apiUrl, string $provider, string $deepseekBackend, bool $noSave): void {
    $records = [];
    echo "Live {$provider} verification. Enter a request; submit an empty line to finish.\n";
    echo "Requests use agentic refinement and a live Playwright cache bypass.\n";
    echo 'DeepSeek backend: ' . ($deepseekBackend === 'local-ollama'
        ? 'local Ollama (deepseek-r1:8b)'
        : 'paid API (' . DEEPSEEK_API_MODEL . ')') . "\n";

    while (true) {
        printf("Live request #%d (blank to finish): ", count($records) + 1);
        $line = fgets(STDIN);
        if ($line === false) break;
        $query = trim($line);
        if ($query === '') break;
        if (mb_strlen($query) > 300) {
            fwrite(STDERR, "Request must be 300 characters or fewer; it was not sent.\n");
            continue;
        }

        $response = sendVolatilityApiRequest($apiUrl, $query, $provider, true, null, 'bypass', 'baseline', $deepseekBackend);
        $response['error_category'] = $response['error_category']
            ?? ($response['failure'] !== null ? 'request_failed' : null);
        $record = buildLiveProviderRequestResult(
            $provider,
            $query,
            $response,
            (float)$response['latency'] * 1000,
            $deepseekBackend
        );
        $record['case_id'] = sprintf('LIVE-%03d', count($records) + 1);
        $records[] = $record;
        printLiveRequestResult($record);
    }

    if (!$records) {
        echo "No live requests were submitted; no report was written.\n";
        return;
    }

    $fullPipelineCount = count(array_filter(
        $records,
        static fn(array $record): bool => $record['all_five_stages_succeeded_on_live_request']
    ));
    $providerCallCount = count(array_filter(
        $records,
        static fn(array $record): bool => $record['provider_call']['succeeded']
    ));
    echo "\nSelected-provider call successes: {$providerCallCount}/" . count($records) . "\n";
    if ($provider === 'hybrid') {
        echo "Full five-stage pipeline successes: {$fullPipelineCount}/" . count($records) . "\n";
    }
    if ($noSave) {
        echo "Report saving disabled by --no-save.\n";
        return;
    }

    $resultsDir = __DIR__ . DIRECTORY_SEPARATOR . 'results';
    if (!is_dir($resultsDir) && !mkdir($resultsDir, 0775, true) && !is_dir($resultsDir)) {
        failVol('Could not create the live pipeline report directory.');
    }
    $stamp = gmdate('Ymd_His');
    $basePath = $resultsDir . DIRECTORY_SEPARATOR . "live_pipeline_{$stamp}";
    $report = [
        'report_type'=>'interactive_live_provider_verification',
        'created_at_utc'=>gmdate('c'),
        'api_endpoint'=>$apiUrl === 'local' ? 'local_php_worker' : livePipelineSafeUrl($apiUrl),
        'run_configuration'=>[
            'provider'=>$provider,
            'deepseek_backend_requested'=>$deepseekBackend,
            'agentic_refinement_enabled'=>true,
            'iterative_loop_enabled'=>true,
            'playwright_cache_policy'=>'bypass',
            'evidence_override_used'=>false,
            'requests_are_direct_api_posts'=>true,
        ],
        'summary'=>[
            'requests'=>count($records),
            'request_successes'=>count(array_filter($records, static fn(array $record): bool => $record['request_success'])),
            'provider_call_successes'=>$providerCallCount,
            'full_live_pipeline_successes'=>$fullPipelineCount,
        ],
        'requests'=>$records,
    ];
    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false || file_put_contents($basePath . '.json', $json . PHP_EOL, LOCK_EX) === false) {
        failVol('Could not write the live pipeline JSON report.');
    }
    $csv = fopen($basePath . '.csv', 'wb');
    if ($csv === false) failVol('Could not write the live pipeline CSV report.');
    fputcsv($csv, [
        'request_id', 'query', 'provider', 'request_success', 'provider_call_success', 'all_five_stages_succeeded',
        'stage_1_planner', 'stage_2_playwright', 'stage_3_structurer', 'stage_4_deepseek', 'stage_5_final',
        'stage_details', 'execution_class', 'cache_status', 'deepseek_backend', 'deepseek_model',
    ]);
    foreach ($records as $record) {
        $stageSuccess = [];
        foreach ($record['stages'] as $name => $stage) {
            $stageSuccess[$name] = str_starts_with((string)($stage['status'] ?? ''), 'not_run_')
                ? 'not_applicable'
                : ($stage['succeeded'] ? 'true' : 'false');
        }
        fputcsv($csv, [
            $record['case_id'],
            $record['query'],
            $record['provider'],
            $record['request_success'] ? 'true' : 'false',
            $record['provider_call']['succeeded'] ? 'true' : 'false',
            $record['all_five_stages_succeeded_on_live_request'] ? 'true' : 'false',
            $stageSuccess['1_gpt4o_mini_planner'],
            $stageSuccess['2_playwright_retrieval'],
            $stageSuccess['3_gpt4o_mini_evidence_structurer'],
            $stageSuccess['4_deepseek_r1_reasoner'],
            $stageSuccess['5_gpt4o_mini_final_response'],
            json_encode($record['stages'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            $record['execution_class'],
            $record['cache_status'],
            $record['stages']['4_deepseek_r1_reasoner']['backend'] ?? '',
            $record['stages']['4_deepseek_r1_reasoner']['model'] ?? '',
        ]);
    }
    fclose($csv);
    echo 'Saved live pipeline JSON: ' . $basePath . ".json\n";
    echo 'Saved live pipeline CSV : ' . $basePath . ".csv\n";
}

$conditions = [
    [
        'id'=>'STATIC-CSV-BASELINE',
        'provider'=>'baseline',
        'agentic'=>false,
        'evidence_mode'=>'none',
        'description'=>'Static 2025 CSV retrieval without web volatility reconciliation',
    ],
    [
        'id'=>'STALE-CACHE-12H',
        'provider'=>'agentic-local',
        'agentic'=>true,
        'evidence_mode'=>'stale',
        'description'=>'Agentic pipeline reading outdated (>12h) pre-change cached web snapshot',
    ],
    [
        'id'=>'ABLATION-NO-LOOP-FRESH',
        'provider'=>'ablation-no-loop',
        'agentic'=>true,
        'evidence_mode'=>'fresh',
        'description'=>'Single-pass agentic intent retrieval with fresh web evidence WITHOUT Pass 2 autonomous critique/repair',
    ],
    [
        'id'=>'AGENTIC-LOOP-FRESH',
        'provider'=>'agentic-local',
        'agentic'=>true,
        'evidence_mode'=>'fresh',
        'description'=>'Full 2-pass Autonomous Iterative Critique-and-Refinement Loop with fresh web evidence',
    ],
];

$startedIso = gmdate('c');
echo "====================================================================================================================\n";
echo " Davao Food Finder Data Volatility Resilience Benchmark (Objective 1 — v3.0)\n";
echo "====================================================================================================================\n";
echo 'Scenarios        : ' . count($scenarios) . " real Davao City temporal/counterfactual volatility cases\n";
echo 'Conditions       : ' . implode(', ', array_column($conditions, 'id')) . "\n";
echo 'Execution Mode   : ' . ($localCli ? 'local CLI worker (api.php)' : $apiUrl) . "\n\n";

$conditionSummaries = [];
$pairedPassScores = [];
$conditionIds = [];
$detailedScenarioRuns = [];

foreach ($conditions as $cond) {
    echo "--- CONDITION: {$cond['id']} ({$cond['description']}) ---\n";
    $suppressionScores = [];
    $replacementPrecisionScores = [];
    $strictPassScores = [];
    $latencies = [];
    $byType = [];
    $scenarioRows = [];

    foreach ($scenarios as $idx => $sc) {
        $ev = match ($cond['evidence_mode']) {
            'stale' => $sc['stale_evidence'] ?? null,
            'fresh' => $sc['fresh_evidence'] ?? null,
            default => null,
        };
        $resp = sendVolatilityApiRequest($apiUrl, (string)$sc['query'], $cond['provider'], $cond['agentic'], $ev);
        $latencies[] = (float)$resp['latency'];
        $data = $resp['data'] ?? [];
        $localResults = is_array($data['results'] ?? null) ? $data['results'] : [];
        $webResults = is_array($data['webResults'] ?? null) ? $data['webResults'] : [];
        $combined = mergeRecommendations($localResults, $webResults, true);
        $top5 = array_slice($combined, 0, 5);

        $invalidNorm = [];
        foreach (($sc['invalidated_venues'] ?? []) as $inv) {
            $invalidNorm[normalizePlaceName((string)$inv)] = (string)$inv;
        }
        $validAltNorm = [];
        foreach (($sc['expected_valid_alternatives'] ?? []) as $alt) {
            $validAltNorm[normalizePlaceName((string)$alt)] = true;
        }

        $leakedInvalid = [];
        $validHits = 0;
        $top5Display = [];
        foreach ($top5 as $item) {
            $nm = trim((string)($item['name'] ?? ''));
            $top5Display[] = $nm;
            $nk = normalizePlaceName($nm);
            if (isset($invalidNorm[$nk])) {
                $leakedInvalid[] = $nm;
            } else {
                // Valid if Davao City and not invalidated
                $isDavao = isDavaoCityName((string)($item['city'] ?? 'Davao City'));
                $priceOk = true;
                if (isset($sc['max_price_php']) && is_numeric($sc['max_price_php'])) {
                    $pe = $item['price_evidence'] ?? null;
                    if (is_array($pe) && isset($pe['median_php']) && (float)$pe['median_php'] > (float)$sc['max_price_php']) {
                        $priceOk = false;
                    }
                }
                if ($isDavao && $priceOk && (isset($validAltNorm[$nk]) || count($leakedInvalid) === 0)) {
                    $validHits++;
                }
            }
        }

        $suppressedFraction = count($invalidNorm) > 0
            ? max(0.0, 1.0 - (count($leakedInvalid) / count($invalidNorm)))
            : 1.0;
        $allInvalidSuppressed = count($leakedInvalid) === 0;
        $validPrecision = count($top5) > 0 ? ($validHits / count($top5)) : 0.0;
        $scenarioPass = $resp['failure'] === null && $allInvalidSuppressed && count($top5) > 0 && $validPrecision >= 0.6;

        $suppressionScores[] = $allInvalidSuppressed ? 1.0 : 0.0;
        $replacementPrecisionScores[] = $validPrecision;
        $strictPassScores[] = $scenarioPass ? 1.0 : 0.0;

        $vType = (string)($sc['volatility_type'] ?? 'other');
        $byType[$vType][] = $scenarioPass ? 1.0 : 0.0;

        $loopInfo = $data['metrics']['agentic_loop'] ?? [];
        $volInfo = $data['metrics']['volatility'] ?? [];

        $scenarioRows[] = [
            'scenario_id'=>$sc['id'],
            'volatility_type'=>$vType,
            'query'=>$sc['query'],
            'strict_pass'=>$scenarioPass,
            'invalidated_suppressed'=>$allInvalidSuppressed,
            'leaked_invalid_venues'=>$leakedInvalid,
            'valid_top5_precision'=>round($validPrecision, 4),
            'top5_returned'=>$top5Display,
            'loop_iterations'=>(int)($loopInfo['iterations'] ?? 0),
            'loop_repaired'=>(int)($loopInfo['violations_repaired'] ?? 0),
            'volatility_detected'=>$volInfo,
            'latency_s'=>round((float)$resp['latency'], 4),
        ];

        printf(
            "  [%02d/%02d] %-6s (%-19s) | %-4s | InvalidLeaked=%d | ValidP@5=%5.1f%% | Top1: %s\n",
            $idx + 1,
            count($scenarios),
            $sc['id'],
            $vType,
            $scenarioPass ? 'PASS' : 'FAIL',
            count($leakedInvalid),
            $validPrecision * 100,
            $top5Display[0] ?? 'NONE'
        );
    }

    $typeRates = [];
    foreach ($byType as $k => $vals) {
        $typeRates[$k] = round(meanVal($vals), 4);
    }

    $ciPass = confidenceInterval95($strictPassScores, 42);
    $ciSupp = confidenceInterval95($suppressionScores, 43);
    $ciPrec = confidenceInterval95($replacementPrecisionScores, 44);

    $conditionSummaries[] = [
        'id'=>$cond['id'],
        'provider'=>$cond['provider'],
        'evidence_mode'=>$cond['evidence_mode'],
        'description'=>$cond['description'],
        'scenario_count'=>count($scenarios),
        'resilience_pass_rate'=>$ciPass,
        'invalid_venue_suppression_rate'=>$ciSupp,
        'valid_top5_precision'=>$ciPrec,
        'accuracy_by_volatility_type'=>$typeRates,
        'mean_latency_s'=>round(meanVal($latencies), 4),
        'median_latency_s'=>round(percentileVal($latencies, 50.0), 4),
    ];
    $pairedPassScores[] = $strictPassScores;
    $conditionIds[] = $cond['id'];
    $detailedScenarioRuns[$cond['id']] = $scenarioRows;
    echo "\n";
}

echo "====================================================================================================================\n";
echo " VOLATILITY RESILIENCE SUMMARY TABLE (n=" . count($scenarios) . " scenarios)\n";
echo "====================================================================================================================\n";
printf(
    "%-24s | %-18s | %-15s | %-12s | %-9s | %-9s | %-9s | %-9s\n",
    'Condition ID', 'Resilience [95%CI]', 'Invalid Suppr%', 'Valid P@5%', 'Closure%', 'Price%', 'Hours%', 'Rating%'
);
echo str_repeat('-', 122) . "\n";
foreach ($conditionSummaries as $cs) {
    $bt = $cs['accuracy_by_volatility_type'];
    printf(
        "%-24s | %5.1f%% [%4.1f,%4.1f] | %14.1f%% | %11.1f%% | %8.1f%% | %8.1f%% | %8.1f%% | %8.1f%%\n",
        $cs['id'],
        $cs['resilience_pass_rate']['mean'] * 100,
        $cs['resilience_pass_rate']['ci_lower'] * 100,
        $cs['resilience_pass_rate']['ci_upper'] * 100,
        $cs['invalid_venue_suppression_rate']['mean'] * 100,
        $cs['valid_top5_precision']['mean'] * 100,
        ($bt['closure'] ?? 0.0) * 100,
        ($bt['price_drift'] ?? 0.0) * 100,
        ($bt['hours_change'] ?? 0.0) * 100,
        ($bt['rating_shift'] ?? 0.0) * 100
    );
}

$friedman = friedmanTest($pairedPassScores, count($scenarios));
$posthoc = count($conditionIds) >= 2 ? pairwiseWilcoxonPostHoc($pairedPassScores, $conditionIds) : [];

echo "\n--- STATISTICAL SIGNIFICANCE (Volatility Resilience Pass Rate) ---\n";
if ($friedman !== null) {
    printf(
        "Friedman Omnibus: Q = %.4f, df = %d, p = %.6f %s\n",
        $friedman['statistic'],
        $friedman['degrees_of_freedom'],
        $friedman['p_value'],
        $friedman['p_value'] < 0.05 ? '(SIGNIFICANT at alpha=0.05)' : '(ns)'
    );
}
foreach ($posthoc as $pair) {
    printf(
        "  %-24s vs %-24s | Diff=%+6.1f%% | Raw p=%.5f | Holm p=%.5f | r=%+.3f | %s\n",
        $pair['config_a'],
        $pair['config_b'],
        $pair['mean_diff'] * 100,
        $pair['p_value'],
        $pair['p_holm'],
        $pair['effect_size_r'],
        $pair['significant_005'] ? 'SIGNIFICANT*' : 'ns'
    );
}

if (!$noSave) {
    $resultsDir = __DIR__ . DIRECTORY_SEPARATOR . 'results';
    if (!is_dir($resultsDir)) @mkdir($resultsDir, 0775, true);
    $stamp = gmdate('Ymd_His');
    $artifact = [
        'experiment'=>'data_volatility_resilience_v3',
        'started_at_utc'=>$startedIso,
        'completed_at_utc'=>gmdate('c'),
        'scenario_count'=>count($scenarios),
        'summary'=>$conditionSummaries,
        'statistical_tests'=>[
            'friedman'=>$friedman,
            'wilcoxon_posthoc'=>$posthoc,
        ],
        'detailed_runs'=>$detailedScenarioRuns,
    ];
    $encoded = json_encode($artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    $tsPath = $resultsDir . DIRECTORY_SEPARATOR . "volatility_{$stamp}.json";
    $latestPath = $resultsDir . DIRECTORY_SEPARATOR . 'latest_volatility.json';
    file_put_contents($tsPath, $encoded, LOCK_EX);
    file_put_contents($latestPath, $encoded, LOCK_EX);

    $csvPath = $resultsDir . DIRECTORY_SEPARATOR . "volatility_{$stamp}_summary.csv";
    $fp = fopen($csvPath, 'wb');
    if ($fp) {
        fputcsv($fp, [
            'condition_id', 'provider', 'evidence_mode', 'scenarios',
            'resilience_pass_mean', 'resilience_ci_low', 'resilience_ci_high',
            'invalid_suppression_mean', 'valid_top5_precision_mean',
            'closure_pass_rate', 'price_drift_pass_rate', 'hours_change_pass_rate', 'rating_shift_pass_rate', 'compound_pass_rate',
            'median_latency_s',
        ]);
        foreach ($conditionSummaries as $cs) {
            $bt = $cs['accuracy_by_volatility_type'];
            fputcsv($fp, [
                $cs['id'], $cs['provider'], $cs['evidence_mode'], $cs['scenario_count'],
                round($cs['resilience_pass_rate']['mean'], 4),
                round($cs['resilience_pass_rate']['ci_lower'], 4),
                round($cs['resilience_pass_rate']['ci_upper'], 4),
                round($cs['invalid_venue_suppression_rate']['mean'], 4),
                round($cs['valid_top5_precision']['mean'], 4),
                $bt['closure'] ?? '',
                $bt['price_drift'] ?? '',
                $bt['hours_change'] ?? '',
                $bt['rating_shift'] ?? '',
                $bt['compound_volatility'] ?? '',
                $cs['median_latency_s'],
            ]);
        }
        fclose($fp);
    }
    echo "\nSaved volatility experiment JSON: {$latestPath}\n";
    echo "Saved volatility summary CSV   : {$csvPath}\n";
}


