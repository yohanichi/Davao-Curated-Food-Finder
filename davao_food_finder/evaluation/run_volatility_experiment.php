<?php
declare(strict_types=1);

/**
 * Live volatility runner with citation-gated automatic annotations and the controlled benchmark available on request.
 *
 * --live-experiment searches the existing query set and scores only current, citation-backed annotations.
 * Default mode captures current live evidence and pipeline execution for user-entered queries without scoring.
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
 *   php evaluation/run_volatility_experiment.php --live-experiment --local-cli --live-provider=hybrid
 *   DeepSeek defaults to the paid DeepSeek API (deepseek-flash); local Ollama is opt-in.
 *   php evaluation/run_volatility_experiment.php --controlled-scenarios --local-cli
 */

$appRoot = dirname(__DIR__);
require_once $appRoot . '/vendor/autoload.php';
require_once $appRoot . '/src/config.php';
require_once $appRoot . '/src/search.php';
require_once $appRoot . '/src/web_reasoner.php';
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
$liveExperiment = false;
$extraLiveProvider = '';
$deepseekBackend = 'hosted-api';
$deepseekBackendOptionSet = false;
$casesPath = __DIR__ . '/volatility_cases.json';
$liveLimit = 0;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--local-cli') {
        $localCli = true;
        $apiUrl = 'local';
    } elseif ($arg === '--controlled-scenarios') {
        $controlledScenarios = true;
    } elseif ($arg === '--live-experiment') {
        $liveExperiment = true;
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
    } elseif (str_starts_with($arg, '--limit=')) {
        $liveLimit = max(0, (int)substr($arg, strlen('--limit=')));
    } else {
        failVol("Unknown option {$arg}. Supported: --local-cli, --api-url=URL, --controlled-scenarios, --live-experiment, --live-provider=deepseek|gpt-4o-mini|hybrid, --deepseek-backend=local-ollama|hosted-api, --cases=PATH, --limit=N, --no-save");
    }
}

if ($controlledScenarios && $liveExperiment) {
    failVol('--controlled-scenarios and --live-experiment are separate modes.');
}

if ($liveExperiment) {
    $liveProvider = $extraLiveProvider !== '' ? $extraLiveProvider : 'hybrid';
    runLiveVolatilityExperiment($apiUrl, $liveProvider, $deepseekBackend, $casesPath, $liveLimit, $noSave);
    exit(0);
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
    string $deepseekBackend = 'auto',
    ?bool $iterativeLoop = null
): array {
    $evalBlock = [
        'provider'=>$provider,
        'temperature'=>0.0,
        'promptStyle'=>$promptStyle,
        'agentic'=>$agentic,
        'iterativeLoop'=>$iterativeLoop
            ?? ($agentic && !in_array($provider, ['ablation-no-loop', 'baseline', 'non-agentic'], true)),
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

function runLiveVolatilityCondition(
    string $apiUrl,
    string $query,
    string $conditionId,
    string $provider,
    bool $agentic,
    bool $iterativeLoop,
    string $cachePolicy,
    string $deepseekBackend,
    array $expectedInvalidated,
    array $expectedAlternatives,
    ?int $changeTimestamp
): array {
    $response = sendVolatilityApiRequest(
        $apiUrl,
        $query,
        $provider,
        $agentic,
        null,
        $cachePolicy,
        'strict_grounding',
        $deepseekBackend,
        $iterativeLoop
    );
    $data = is_array($response['data'] ?? null) ? $response['data'] : [];
    $metrics = is_array($data['metrics'] ?? null) ? $data['metrics'] : [];
    $playwright = is_array($metrics['playwright'] ?? null) ? $metrics['playwright'] : [];
    $cacheMetrics = is_array($metrics['cache'] ?? null) ? $metrics['cache'] : [];
    $cacheStatus = strtolower((string)($playwright['cache'] ?? ($cacheMetrics['playwright'] ?? 'unknown')));
    $cacheAge = isset($playwright['cache_age_seconds'])
        ? (int)$playwright['cache_age_seconds']
        : (isset($cacheMetrics['playwright_age_seconds']) ? (int)$cacheMetrics['playwright_age_seconds'] : null);
    $cachedAt = isset($cacheMetrics['playwright_cached_at']) ? (int)$cacheMetrics['playwright_cached_at'] : null;
    $browserUsed = ($playwright['browser_used'] ?? false) === true;
    $browserResultCount = (int)($playwright['browser_result_count'] ?? 0);
    $requestSuccess = ($response['failure'] ?? null) === null
        && ($data['assistantStatus'] ?? '') === 'ready'
        && trim((string)($data['answer'] ?? '')) !== '';
    $isLiveFetch = $cacheStatus === 'miss' && $browserUsed && $browserResultCount > 0;
    $isPreChangeStaleCache = $cacheStatus === 'hit'
        && $cacheAge !== null
        && $cacheAge >= 43200
        && $cachedAt !== null
        && $changeTimestamp !== null
        && $cachedAt < $changeTimestamp;
    $cacheEligible = match ($conditionId) {
        'STALE-CACHE-12H' => $isPreChangeStaleCache,
        'ABLATION-NO-LOOP-LIVE', 'AGENTIC-LOOP-LIVE' => $isLiveFetch,
        default => true,
    };

    $localResults = is_array($data['results'] ?? null) ? $data['results'] : [];
    $webResults = is_array($data['webResults'] ?? null) ? $data['webResults'] : [];
    $recommendations = array_slice(mergeRecommendations($localResults, $webResults, true), 0, 5);
    $top5Names = array_values(array_filter(array_map(
        static fn($place): string => trim((string)($place['name'] ?? '')),
        $recommendations
    )));
    $invalidatedNorm = array_fill_keys(array_map('normalizePlaceName', $expectedInvalidated), true);
    $alternativeNorm = array_fill_keys(array_map('normalizePlaceName', $expectedAlternatives), true);
    $leakedInvalid = [];
    $alternativeHits = 0;
    foreach ($top5Names as $name) {
        $normalized = normalizePlaceName($name);
        if (isset($invalidatedNorm[$normalized])) $leakedInvalid[] = $name;
        if (isset($alternativeNorm[$normalized])) $alternativeHits++;
    }
    $validPrecision = count($top5Names) > 0 ? $alternativeHits / count($top5Names) : 0.0;
    $eligible = $requestSuccess && $cacheEligible;
    $pass = $eligible && !$leakedInvalid && count($top5Names) > 0 && $validPrecision >= 0.6;
    $volatility = is_array($metrics['volatility'] ?? null) ? $metrics['volatility'] : [];
    $sources = is_array($data['sources'] ?? null) ? $data['sources'] : [];
    $sourceUrls = array_values(array_unique(array_filter(array_map(
        static fn($source): string => is_array($source)
            ? livePipelineSafeUrl(trim((string)($source['url'] ?? '')))
            : '',
        $sources
    ))));

    return [
        'condition_id'=>$conditionId,
        'provider'=>$provider,
        'cache_policy'=>$cachePolicy,
        'request_success'=>$requestSuccess,
        'evaluation_status'=>!$requestSuccess
            ? 'request_failed'
            : (!$cacheEligible
                ? ($conditionId === 'STALE-CACHE-12H' ? 'no_verified_pre_change_cache' : 'no_verified_live_web_fetch')
                : 'scored'),
        'eligible_for_score'=>$eligible,
        'resilience_pass'=>$eligible ? $pass : null,
        'invalidated_venues_suppressed'=>$eligible ? count($leakedInvalid) === 0 : null,
        'leaked_invalidated_venues'=>$leakedInvalid,
        'valid_alternative_precision_at_5'=>$eligible ? round($validPrecision, 4) : null,
        'top5_returned'=>$top5Names,
        'cache_status'=>$cacheStatus,
        'cache_age_seconds'=>$cacheAge,
        'cached_at_utc'=>$cachedAt !== null ? gmdate('c', $cachedAt) : null,
        'cache_predates_annotated_change'=>$isPreChangeStaleCache,
        'live_browser_used'=>$browserUsed,
        'live_browser_result_count'=>$browserResultCount,
        'volatility_telemetry'=>$volatility,
        'source_urls'=>$sourceUrls,
        'answer'=>(string)($data['answer'] ?? ''),
        'latency_s'=>round((float)($response['latency'] ?? 0), 4),
        'error_category'=>$response['error_category'] ?? null,
    ];
}

function selectLiveAnnotationModel(string $liveProvider): array {
    if ($liveProvider === 'deepseek' && OPENAI_API_KEY !== '') {
        return [
            'provider'=>'openai',
            'model'=>OPENAI_MODEL,
            'url'=>'https://api.openai.com/v1/chat/completions',
            'api_key'=>OPENAI_API_KEY,
        ];
    }
    if ($liveProvider === 'gpt-4o-mini' && DEEPSEEK_API_KEY !== '') {
        return [
            'provider'=>'deepseek',
            'model'=>DEEPSEEK_API_MODEL,
            'url'=>rtrim(DEEPSEEK_API_BASE_URL, '/') . '/chat/completions',
            'api_key'=>DEEPSEEK_API_KEY,
        ];
    }
    if (DEEPSEEK_API_KEY !== '') {
        return [
            'provider'=>'deepseek',
            'model'=>DEEPSEEK_API_MODEL,
            'url'=>rtrim(DEEPSEEK_API_BASE_URL, '/') . '/chat/completions',
            'api_key'=>DEEPSEEK_API_KEY,
        ];
    }
    if (OPENAI_API_KEY !== '') {
        return [
            'provider'=>'openai',
            'model'=>OPENAI_MODEL,
            'url'=>'https://api.openai.com/v1/chat/completions',
            'api_key'=>OPENAI_API_KEY,
        ];
    }
    return [];
}

function liveAnnotationEvidenceRows(array $evidence): array {
    $rows = [];
    foreach (array_slice(is_array($evidence['results'] ?? null) ? $evidence['results'] : [], 0, 15) as $result) {
        if (!is_array($result)) continue;
        $url = livePipelineSafeUrl(trim((string)($result['url'] ?? '')));
        $title = trim((string)($result['title'] ?? ''));
        $text = trim((string)($result['text'] ?? ($result['snippet'] ?? '')));
        if ($url === '' || ($title === '' && $text === '')) continue;
        $rows[] = [
            'source_id'=>'S' . (count($rows) + 1),
            'title'=>mb_substr($title, 0, 500),
            'url'=>$url,
            'text'=>mb_substr($text, 0, 1800),
        ];
    }
    return $rows;
}

function requestLiveVolatilityAnnotation(string $query, array $sources, array $annotator): array {
    $systemPrompt = <<<'PROMPT'
You are a conservative research annotator. Find only current Davao City restaurant volatility claims directly supported by the supplied live search-result text. Do not use prior knowledge, do not infer a closure or price change from absence, and do not invent facts, quotes, dates, or URLs.

Return one JSON object with exactly these keys:
{
  "volatility_type": "closure|price_drift|hours_change|rating_shift|compound_volatility|null",
  "invalidated_venues": [
    {"name":"exact venue/branch", "change_type":"closure|price_drift|hours_change|rating_shift", "quote":"verbatim source excerpt", "source_url":"exact supplied URL", "confidence":0.0, "change_effective_at":"ISO-8601 with timezone or null"}
  ],
  "valid_alternatives": [
    {"name":"exact venue/branch", "quote":"verbatim source excerpt supporting current operation and query relevance", "source_url":"exact supplied URL", "confidence":0.0}
  ]
}

Only mark a venue invalidated when the quoted, cited change makes it fail the user's query (for example closure, a verified price above the stated ceiling, or hours incompatible with an explicit time request). A rating change alone is not invalidating unless the query states a rating threshold. Alternatives must be explicitly described as operating/open and match the query's food/location/price intent. Return an empty list rather than guessing. Confidence must reflect source clarity, not model certainty.
PROMPT;
    $userPayload = json_encode([
        'query'=>$query,
        'search_date_utc'=>gmdate('c'),
        'sources'=>$sources,
        'minimum_label_rules'=>[
            'every label must quote exact supplied text',
            'source_url must exactly match a supplied URL',
            'do not use the fixed expected labels or injected fixtures from volatility_cases.json',
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($userPayload === false) throw new RuntimeException('Could not encode live annotation evidence.');

    $payload = json_encode([
        'model'=>$annotator['model'],
        'temperature'=>0,
        'max_tokens'=>2200,
        'messages'=>[
            ['role'=>'system', 'content'=>$systemPrompt],
            ['role'=>'user', 'content'=>$userPayload],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payload === false) throw new RuntimeException('Could not encode the live annotation request.');
    [$raw, $status] = deepSeekHttpPost(
        $annotator['url'],
        ['Content-Type: application/json', 'Authorization: Bearer ' . $annotator['api_key']],
        $payload,
        60
    );
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('Live annotation provider returned HTTP ' . $status . '.');
    }
    $decoded = json_decode($raw, true);
    $content = trim((string)($decoded['choices'][0]['message']['content'] ?? ''));
    $annotation = json_decode($content, true);
    if (!is_array($annotation)) {
        throw new RuntimeException('Live annotation provider returned invalid JSON.');
    }
    return $annotation;
}

function liveQuoteIsInSource(string $quote, array $source): bool {
    $normalize = static fn(string $text): string => mb_strtolower(
        trim(preg_replace('/\s+/u', ' ', $text) ?? $text),
        'UTF-8'
    );
    $normalizedQuote = $normalize($quote);
    $sourceText = $normalize((string)($source['title'] ?? '') . ' ' . (string)($source['text'] ?? ''));
    return mb_strlen($normalizedQuote) >= 24 && mb_strpos($sourceText, $normalizedQuote) !== false;
}

function validateLiveVolatilityAnnotation(array $draft, array $sources): array {
    $sourcesByUrl = [];
    foreach ($sources as $source) {
        if (is_array($source) && !empty($source['url'])) $sourcesByUrl[(string)$source['url']] = $source;
    }
    $acceptedInvalidated = [];
    $acceptedAlternatives = [];
    $rejectedItems = 0;
    $allowedTypes = ['closure', 'price_drift', 'hours_change', 'rating_shift'];
    foreach (is_array($draft['invalidated_venues'] ?? null) ? $draft['invalidated_venues'] : [] as $item) {
        if (!is_array($item)) {
            $rejectedItems++;
            continue;
        }
        $name = trim((string)($item['name'] ?? ''));
        $quote = trim((string)($item['quote'] ?? ''));
        $url = livePipelineSafeUrl(trim((string)($item['source_url'] ?? '')));
        $type = strtolower(trim((string)($item['change_type'] ?? '')));
        $confidence = (float)($item['confidence'] ?? 0);
        $source = $sourcesByUrl[$url] ?? null;
        if ($name === '' || $confidence < 0.85 || !in_array($type, $allowedTypes, true)
            || !is_array($source) || !liveQuoteIsInSource($quote, $source)) {
            $rejectedItems++;
            continue;
        }
        $typePattern = match ($type) {
            'closure'=>'/\b(closed|closure|shut|ceased operations|permanently|temporarily)\b/i',
            'price_drift'=>'/(?:₱|\bphp\b|\bpesos?\b).{0,30}\d|\d.{0,30}(?:₱|\bphp\b|\bpesos?\b)/iu',
            'hours_change'=>'/\b(hours?|operating|open|closes?|closing|schedule)\b/i',
            'rating_shift'=>'/\b(rating|rated|stars?|score)\b/i',
        };
        if (preg_match($typePattern, $quote) !== 1) {
            $rejectedItems++;
            continue;
        }
        $acceptedInvalidated[normalizePlaceName($name)] = [
            'name'=>$name,
            'change_type'=>$type,
            'quote'=>$quote,
            'source_url'=>$url,
            'confidence'=>$confidence,
            'change_effective_at'=>trim((string)($item['change_effective_at'] ?? '')) ?: null,
        ];
    }
    foreach (is_array($draft['valid_alternatives'] ?? null) ? $draft['valid_alternatives'] : [] as $item) {
        if (!is_array($item)) {
            $rejectedItems++;
            continue;
        }
        $name = trim((string)($item['name'] ?? ''));
        $quote = trim((string)($item['quote'] ?? ''));
        $url = livePipelineSafeUrl(trim((string)($item['source_url'] ?? '')));
        $confidence = (float)($item['confidence'] ?? 0);
        $source = $sourcesByUrl[$url] ?? null;
        $normalizedQuote = mb_strtolower($quote, 'UTF-8');
        if ($name === '' || $confidence < 0.85 || !is_array($source)
            || !liveQuoteIsInSource($quote, $source)
            || preg_match('/\b(open|operating|serving|currently|located|available)\b/i', $normalizedQuote) !== 1) {
            $rejectedItems++;
            continue;
        }
        $acceptedAlternatives[normalizePlaceName($name)] = [
            'name'=>$name,
            'quote'=>$quote,
            'source_url'=>$url,
            'confidence'=>$confidence,
        ];
    }
    $types = array_values(array_unique(array_column($acceptedInvalidated, 'change_type')));
    $type = trim((string)($draft['volatility_type'] ?? ''));
    if (count($types) > 1) $type = 'compound_volatility';
    elseif (count($types) === 1) $type = $types[0];
    if (!in_array($type, ['closure', 'price_drift', 'hours_change', 'rating_shift', 'compound_volatility'], true)) $type = null;
    $changeTimes = [];
    foreach ($acceptedInvalidated as $item) {
        $time = $item['change_effective_at'];
        if (!is_string($time) || !preg_match('/(?:Z|[+-]\d{2}:\d{2})$/i', $time)) continue;
        try {
            $changeTimes[] = (new DateTimeImmutable($time))->getTimestamp();
        } catch (Exception) {
            continue;
        }
    }
    $allChangesDated = count($changeTimes) === count($acceptedInvalidated) && $changeTimes;
    return [
        'volatility_type'=>$type,
        'invalidated_venues'=>array_values($acceptedInvalidated),
        'valid_alternatives'=>array_values($acceptedAlternatives),
        'rejected_item_count'=>$rejectedItems,
        'change_effective_at_utc'=>$allChangesDated ? gmdate('c', min($changeTimes)) : null,
        'citation_gate_passed'=>count($acceptedInvalidated) > 0 && count($acceptedAlternatives) >= 3 && $type !== null,
        'citation_gate_reason'=>count($acceptedInvalidated) === 0
            ? 'no_cited_invalidated_venue'
            : (count($acceptedAlternatives) < 3
                ? 'fewer_than_three_cited_valid_alternatives'
                : ($type === null ? 'volatility_type_not_supported' : 'passed')),
    ];
}

function runManualLiveVolatilityExperiment(string $apiUrl, string $provider, string $deepseekBackend, bool $noSave): void {
    $startedAtUtc = gmdate('c');
    echo "LIVE VOLATILITY EXPERIMENT ({$provider})\n";
    echo "Each case uses actual web retrieval and requires independently verified venue-change labels.\n";
    echo "Each case may make up to three provider-backed requests; the stale-cache arm uses an existing cache only.\n";
    echo "For hybrid, configure both OpenAI and DeepSeek. Enter a blank query to finish.\n";

    $caseRuns = [];
    $conditionIds = [
        'STATIC-CSV-BASELINE',
        'STALE-CACHE-12H',
        'ABLATION-NO-LOOP-LIVE',
        'AGENTIC-LOOP-LIVE',
    ];
    $volatilityTypes = ['closure', 'price_drift', 'hours_change', 'rating_shift', 'compound_volatility'];
    while (true) {
        printf("\nLive case #%d query (blank to finish): ", count($caseRuns) + 1);
        $queryLine = fgets(STDIN);
        if ($queryLine === false) break;
        $query = trim($queryLine);
        if ($query === '') break;
        if (mb_strlen($query) > 300) {
            fwrite(STDERR, "Query must be 300 characters or fewer; it was not sent.\n");
            continue;
        }

        echo 'Volatility type (' . implode('/', $volatilityTypes) . '): ';
        $typeLine = fgets(STDIN);
        if ($typeLine === false) {
            fwrite(STDERR, "Input ended before the case was complete; it was not sent.\n");
            break;
        }
        $volatilityType = strtolower(trim($typeLine));
        if (!in_array($volatilityType, $volatilityTypes, true)) {
            fwrite(STDERR, "Unknown volatility type; this case was not sent. Re-enter it.\n");
            continue;
        }

        $invalidated = readLiveCsvList('Independently verified invalidated venues (comma-separated): ');
        $alternatives = readLiveCsvList('Independently verified valid alternatives (comma-separated): ');
        $sourceUrls = readLiveCsvList('Verification source URLs (comma-separated): ');
        $invalidatedNames = array_fill_keys(array_map('normalizePlaceName', $invalidated), true);
        $overlap = array_intersect_key(
            array_fill_keys(array_map('normalizePlaceName', $alternatives), true),
            $invalidatedNames
        );
        if ($overlap) {
            fwrite(STDERR, "A venue cannot be both invalidated and a valid alternative; this case was not sent. Re-enter it.\n");
            continue;
        }
        $validSourceUrls = [];
        foreach ($sourceUrls as $url) {
            if (filter_var($url, FILTER_VALIDATE_URL) === false
                || !in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
                continue;
            }
            $validSourceUrls[] = livePipelineSafeUrl($url);
        }
        if (count($validSourceUrls) !== count($sourceUrls)) {
            fwrite(STDERR, "Every verification source must be a valid http(s) URL; this case was not sent. Re-enter it.\n");
            continue;
        }

        echo "Verified change effective/first reported at (ISO-8601 with timezone, or 'unknown'): ";
        $changeTimeLine = fgets(STDIN);
        if ($changeTimeLine === false) {
            fwrite(STDERR, "Input ended before the case was complete; it was not sent.\n");
            break;
        }
        $changeTimeInput = trim($changeTimeLine);
        $changeTimestamp = null;
        if ($changeTimeInput !== '' && strtolower($changeTimeInput) !== 'unknown') {
            if (!preg_match('/(?:Z|[+-]\d{2}:\d{2})$/i', $changeTimeInput)) {
                fwrite(STDERR, "Use an explicit timezone (for example 2026-11-17T09:00:00+08:00) or enter 'unknown'.\n");
                continue;
            }
            try {
                $changeTimestamp = (new DateTimeImmutable($changeTimeInput))->getTimestamp();
            } catch (Exception $error) {
                fwrite(STDERR, "Could not parse that date/time; this case was not sent.\n");
                continue;
            }
        }

        $conditions = [
            [
                'id'=>'STATIC-CSV-BASELINE',
                'provider'=>'baseline',
                'agentic'=>false,
                'iterative_loop'=>false,
                'cache_policy'=>'none',
            ],
            [
                'id'=>'STALE-CACHE-12H',
                'provider'=>$provider,
                'agentic'=>true,
                'iterative_loop'=>true,
                'cache_policy'=>'warm',
            ],
            [
                'id'=>'ABLATION-NO-LOOP-LIVE',
                'provider'=>$provider,
                'agentic'=>true,
                'iterative_loop'=>false,
                'cache_policy'=>'bypass',
            ],
            [
                'id'=>'AGENTIC-LOOP-LIVE',
                'provider'=>$provider,
                'agentic'=>true,
                'iterative_loop'=>true,
                'cache_policy'=>'bypass',
            ],
        ];
        $conditionRuns = [];
        foreach ($conditions as $condition) {
            printf("  Running %s ...\n", $condition['id']);
            $conditionResult = runLiveVolatilityCondition(
                $apiUrl,
                $query,
                $condition['id'],
                $condition['provider'],
                $condition['agentic'],
                $condition['iterative_loop'],
                $condition['cache_policy'],
                $deepseekBackend,
                $invalidated,
                $alternatives,
                $changeTimestamp
            );
            $conditionRuns[$condition['id']] = $conditionResult;
            $resultText = $conditionResult['resilience_pass'] === null
                ? strtoupper((string)$conditionResult['evaluation_status'])
                : ($conditionResult['resilience_pass'] ? 'PASS' : 'FAIL');
            printf(
                "    %-24s %s | cache=%s | age=%s | evidence=%d | returned=%d\n",
                $condition['id'],
                $resultText,
                $conditionResult['cache_status'],
                $conditionResult['cache_age_seconds'] === null ? 'n/a' : round($conditionResult['cache_age_seconds'] / 3600, 1) . 'h',
                $conditionResult['live_browser_result_count'],
                count($conditionResult['top5_returned'])
            );
        }
        $caseRuns[] = [
            'case_id'=>sprintf('LIVE-VOL-%03d', count($caseRuns) + 1),
            'query'=>$query,
            'volatility_type'=>$volatilityType,
            'annotation'=>[
                'expected_invalidated_venues'=>$invalidated,
                'expected_valid_alternatives'=>$alternatives,
                'verification_source_urls'=>$validSourceUrls,
                'change_effective_at_utc'=>$changeTimestamp !== null ? gmdate('c', $changeTimestamp) : null,
            ],
            'conditions'=>$conditionRuns,
        ];
    }

    if (!$caseRuns) {
        echo "No live volatility cases were submitted; no report was written.\n";
        return;
    }

    $summary = [];
    foreach ($conditionIds as $conditionId) {
        $eligibleResults = [];
        foreach ($caseRuns as $caseRun) {
            $result = $caseRun['conditions'][$conditionId] ?? null;
            if (is_array($result) && !empty($result['eligible_for_score'])) $eligibleResults[] = $result;
        }
        $passes = array_map(static fn(array $result): float => !empty($result['resilience_pass']) ? 1.0 : 0.0, $eligibleResults);
        $suppression = array_map(static fn(array $result): float => !empty($result['invalidated_venues_suppressed']) ? 1.0 : 0.0, $eligibleResults);
        $alternativePrecision = array_map(static fn(array $result): float => (float)$result['valid_alternative_precision_at_5'], $eligibleResults);
        $typeRates = [];
        foreach ($volatilityTypes as $type) {
            $typePasses = [];
            foreach ($caseRuns as $caseRun) {
                $result = $caseRun['conditions'][$conditionId] ?? null;
                if (($caseRun['volatility_type'] ?? '') === $type && is_array($result) && !empty($result['eligible_for_score'])) {
                    $typePasses[] = !empty($result['resilience_pass']) ? 1.0 : 0.0;
                }
            }
            if ($typePasses) $typeRates[$type] = round(meanVal($typePasses), 4);
        }
        $summary[] = [
            'condition_id'=>$conditionId,
            'eligible_case_count'=>count($eligibleResults),
            'resilience_pass_rate'=>$passes ? round(meanVal($passes), 4) : null,
            'invalidated_venue_suppression_rate'=>$suppression ? round(meanVal($suppression), 4) : null,
            'valid_alternative_precision_at_5'=>$alternativePrecision ? round(meanVal($alternativePrecision), 4) : null,
            'not_eligible_case_count'=>count($caseRuns) - count($eligibleResults),
            'resilience_pass_rate_by_volatility_type'=>$typeRates,
        ];
    }

    echo "\nLIVE VOLATILITY SUMMARY (scores only where evidence and annotations qualify)\n";
    foreach ($summary as $row) {
        printf(
            "  %-24s n=%d/%d | resilience=%s | suppression=%s | alternative P@5=%s\n",
            $row['condition_id'],
            $row['eligible_case_count'],
            count($caseRuns),
            $row['resilience_pass_rate'] === null ? 'n/a' : round($row['resilience_pass_rate'] * 100, 1) . '%',
            $row['invalidated_venue_suppression_rate'] === null ? 'n/a' : round($row['invalidated_venue_suppression_rate'] * 100, 1) . '%',
            $row['valid_alternative_precision_at_5'] === null ? 'n/a' : round($row['valid_alternative_precision_at_5'] * 100, 1) . '%'
        );
    }
    if ($noSave) {
        echo "Report saving disabled by --no-save.\n";
        return;
    }

    $resultsDir = __DIR__ . DIRECTORY_SEPARATOR . 'results';
    if (!is_dir($resultsDir) && !mkdir($resultsDir, 0775, true) && !is_dir($resultsDir)) {
        failVol('Could not create the live volatility result directory.');
    }
    $stamp = gmdate('Ymd_His');
    $basePath = $resultsDir . DIRECTORY_SEPARATOR . "live_volatility_experiment_{$stamp}";
    $report = [
        'report_type'=>'live_volatility_experiment',
        'trial_type'=>'live_trial',
        'started_at_utc'=>$startedAtUtc,
        'completed_at_utc'=>gmdate('c'),
        'run_configuration'=>[
            'selected_live_provider'=>$provider,
            'deepseek_backend_requested'=>$deepseekBackend,
            'evidence_overrides_used'=>false,
            'fresh_conditions_require'=>'Playwright cache miss with browser results',
            'stale_condition_requires'=>'Existing cache hit aged at least 12 hours and timestamped before the annotated real-world change',
            'resilience_pass_rule'=>'No annotated invalidated venue in top 5 and at least 60% of returned top 5 match independently verified valid alternatives.',
        ],
        'summary'=>$summary,
        'detailed_live_cases'=>$caseRuns,
    ];
    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false || file_put_contents($basePath . '.json', $json . PHP_EOL, LOCK_EX) === false) {
        failVol('Could not write the live volatility experiment JSON.');
    }
    $latestJsonPath = $resultsDir . DIRECTORY_SEPARATOR . 'live_latest_volatility_experiment.json';
    if (file_put_contents($latestJsonPath, $json . PHP_EOL, LOCK_EX) === false) {
        failVol('Could not write the latest live volatility experiment JSON.');
    }
    $csv = fopen($basePath . '_summary.csv', 'wb');
    if ($csv === false) failVol('Could not write the live volatility experiment summary CSV.');
    fputcsv($csv, ['condition_id', 'eligible_cases', 'total_cases', 'resilience_pass_rate', 'invalidated_venue_suppression_rate', 'valid_alternative_precision_at_5', 'not_eligible_cases']);
    foreach ($summary as $row) {
        fputcsv($csv, [
            $row['condition_id'],
            $row['eligible_case_count'],
            count($caseRuns),
            $row['resilience_pass_rate'] ?? '',
            $row['invalidated_venue_suppression_rate'] ?? '',
            $row['valid_alternative_precision_at_5'] ?? '',
            $row['not_eligible_case_count'],
        ]);
    }
    fclose($csv);
    $latestCsvPath = $resultsDir . DIRECTORY_SEPARATOR . 'live_latest_volatility_experiment_summary.csv';
    if (!copy($basePath . '_summary.csv', $latestCsvPath)) {
        failVol('Could not write the latest live volatility experiment summary CSV.');
    }
    echo 'Saved live volatility experiment: ' . $basePath . ".json\n";
    echo 'Saved latest live volatility experiment: ' . $latestJsonPath . "\n";
    echo 'Saved live volatility summary: ' . $basePath . "_summary.csv\n";
}

function runLiveVolatilityExperiment(
    string $apiUrl,
    string $provider,
    string $deepseekBackend,
    string $casesPath,
    int $limit,
    bool $noSave
): void {
    $startedAtUtc = gmdate('c');
    $annotator = selectLiveAnnotationModel($provider);
    if (!$annotator) {
        failVol('Automatic citation-gated labeling requires DEEPSEEK_API_KEY or OPENAI_API_KEY.');
    }
    if (!is_file($casesPath)) failVol('Live query set not found: ' . $casesPath);
    $rawSpec = file_get_contents($casesPath);
    if ($rawSpec === false) failVol('Could not read the live query set: ' . $casesPath);
    $spec = json_decode($rawSpec, true);
    $scenarios = is_array($spec['scenarios'] ?? null) ? $spec['scenarios'] : [];
    $scenarios = array_values(array_filter($scenarios, static fn($scenario): bool =>
        is_array($scenario) && trim((string)($scenario['query'] ?? '')) !== ''
    ));
    if ($limit > 0) $scenarios = array_slice($scenarios, 0, $limit);
    if (!$scenarios) failVol('The selected query set contains no live volatility queries.');

    $conditionIds = [
        'STATIC-CSV-BASELINE',
        'STALE-CACHE-12H',
        'ABLATION-NO-LOOP-LIVE',
        'AGENTIC-LOOP-LIVE',
    ];
    $volatilityTypes = ['closure', 'price_drift', 'hours_change', 'rating_shift', 'compound_volatility'];
    $caseRuns = [];
    $cacheDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . '.web-cache';
    echo "LIVE VOLATILITY EXPERIMENT ({$provider})\n";
    echo "Auto-labeling uses current search results, exact cited quotes, URL checks, and a confidence threshold; ambiguous cases are skipped.\n";
    echo "Fixture labels/evidence are ignored. Query cases: " . count($scenarios) . " | Annotator: {$annotator['provider']} / {$annotator['model']}\n";
    echo "The annotator is machine-generated, not human ground truth. Up to five web/model requests may be made per query.\n\n";

    foreach ($scenarios as $index => $scenario) {
        $caseId = trim((string)($scenario['id'] ?? sprintf('LIVE-VOL-%03d', $index + 1)));
        $query = trim((string)$scenario['query']);
        printf("[%02d/%02d] %s | Searching current sources for: %s\n", $index + 1, count($scenarios), $caseId, $query);
        $referenceQuery = $query . ' latest Davao City restaurant closure price operating hours rating update ' . gmdate('Y');
        $referenceEvidence = collectPlaywrightEvidence($referenceQuery, $cacheDir, 43200, 'bypass');
        $sourceRows = liveAnnotationEvidenceRows($referenceEvidence);
        $caseRun = [
            'case_id'=>$caseId,
            'query'=>$query,
            'volatility_type'=>null,
            'annotation_status'=>'not_scored',
            'annotation_reason'=>null,
            'annotation'=>null,
            'reference_evidence'=>[
                'cache_status'=>$referenceEvidence['cache'] ?? 'unknown',
                'browser_used'=>(bool)($referenceEvidence['browser_used'] ?? false),
                'browser_result_count'=>(int)($referenceEvidence['browser_result_count'] ?? 0),
                'source_count'=>count($sourceRows),
                'sources'=>$sourceRows,
            ],
            'conditions'=>[],
        ];
        if (($referenceEvidence['cache'] ?? '') !== 'miss'
            || empty($referenceEvidence['browser_used'])
            || (int)($referenceEvidence['browser_result_count'] ?? 0) < 1
            || count($sourceRows) < 3) {
            $caseRun['annotation_status'] = 'not_scored_no_sufficient_live_sources';
            $caseRun['annotation_reason'] = 'Need a Playwright cache miss with browser results and at least three source-linked snippets.';
            $caseRuns[] = $caseRun;
            echo "  Skipped scoring: insufficient fresh source results.\n";
            continue;
        }

        try {
            $draftAnnotation = requestLiveVolatilityAnnotation($query, $sourceRows, $annotator);
        } catch (RuntimeException $error) {
            $caseRun['annotation_status'] = 'annotation_request_failed';
            $caseRun['annotation_reason'] = $error->getMessage();
            $caseRuns[] = $caseRun;
            echo '  Skipped scoring: ' . $error->getMessage() . "\n";
            continue;
        }
        $annotation = validateLiveVolatilityAnnotation($draftAnnotation, $sourceRows);
        $caseRun['annotation'] = $annotation;
        $caseRun['volatility_type'] = $annotation['volatility_type'];
        if (!$annotation['citation_gate_passed']) {
            $caseRun['annotation_status'] = 'not_scored_citation_gate';
            $caseRun['annotation_reason'] = $annotation['citation_gate_reason'];
            $caseRuns[] = $caseRun;
            echo '  Skipped scoring: ' . $annotation['citation_gate_reason'] . " (no unsupported labels counted).\n";
            continue;
        }
        $caseRun['annotation_status'] = 'citation_gated_auto_annotation';
        $invalidated = array_column($annotation['invalidated_venues'], 'name');
        $alternatives = array_column($annotation['valid_alternatives'], 'name');
        $changeTimestamp = null;
        if (is_string($annotation['change_effective_at_utc'] ?? null)) {
            $changeTimestamp = (new DateTimeImmutable($annotation['change_effective_at_utc']))->getTimestamp();
        }

        $conditions = [
            [
                'id'=>'STATIC-CSV-BASELINE',
                'provider'=>'baseline',
                'agentic'=>false,
                'iterative_loop'=>false,
                'cache_policy'=>'none',
            ],
            [
                'id'=>'STALE-CACHE-12H',
                'provider'=>$provider,
                'agentic'=>true,
                'iterative_loop'=>true,
                'cache_policy'=>'warm',
            ],
            [
                'id'=>'ABLATION-NO-LOOP-LIVE',
                'provider'=>$provider,
                'agentic'=>true,
                'iterative_loop'=>false,
                'cache_policy'=>'bypass',
            ],
            [
                'id'=>'AGENTIC-LOOP-LIVE',
                'provider'=>$provider,
                'agentic'=>true,
                'iterative_loop'=>true,
                'cache_policy'=>'bypass',
            ],
        ];
        foreach ($conditions as $condition) {
            $result = runLiveVolatilityCondition(
                $apiUrl,
                $query,
                $condition['id'],
                $condition['provider'],
                $condition['agentic'],
                $condition['iterative_loop'],
                $condition['cache_policy'],
                $deepseekBackend,
                $invalidated,
                $alternatives,
                $changeTimestamp
            );
            $caseRun['conditions'][$condition['id']] = $result;
            $resultText = $result['resilience_pass'] === null
                ? strtoupper((string)$result['evaluation_status'])
                : ($result['resilience_pass'] ? 'PASS' : 'FAIL');
            printf(
                "  %-24s %s | evidence=%d | returned=%d\n",
                $condition['id'],
                $resultText,
                $result['live_browser_result_count'],
                count($result['top5_returned'])
            );
        }
        $caseRuns[] = $caseRun;
    }

    $summary = [];
    foreach ($conditionIds as $conditionId) {
        $eligibleResults = [];
        $typeRates = [];
        foreach ($caseRuns as $caseRun) {
            $result = $caseRun['conditions'][$conditionId] ?? null;
            if (!is_array($result) || empty($result['eligible_for_score'])) continue;
            $eligibleResults[] = $result;
            $type = (string)($caseRun['volatility_type'] ?? '');
            if (in_array($type, $volatilityTypes, true)) {
                $typeRates[$type][] = !empty($result['resilience_pass']) ? 1.0 : 0.0;
            }
        }
        $passes = array_map(static fn(array $result): float => !empty($result['resilience_pass']) ? 1.0 : 0.0, $eligibleResults);
        $suppression = array_map(static fn(array $result): float => !empty($result['invalidated_venues_suppressed']) ? 1.0 : 0.0, $eligibleResults);
        $alternativePrecision = array_map(static fn(array $result): float => (float)$result['valid_alternative_precision_at_5'], $eligibleResults);
        $typeRates = array_map(static fn(array $values): float => round(meanVal($values), 4), $typeRates);
        $summary[] = [
            'condition_id'=>$conditionId,
            'eligible_case_count'=>count($eligibleResults),
            'resilience_pass_rate'=>$passes ? round(meanVal($passes), 4) : null,
            'invalidated_venue_suppression_rate'=>$suppression ? round(meanVal($suppression), 4) : null,
            'valid_alternative_precision_at_5'=>$alternativePrecision ? round(meanVal($alternativePrecision), 4) : null,
            'not_eligible_case_count'=>count($caseRuns) - count($eligibleResults),
            'resilience_pass_rate_by_volatility_type'=>$typeRates,
        ];
    }

    echo "\nLIVE VOLATILITY SUMMARY (citation-gated auto-labeled cases only)\n";
    foreach ($summary as $row) {
        printf(
            "  %-24s n=%d/%d | resilience=%s | suppression=%s | alternative P@5=%s\n",
            $row['condition_id'],
            $row['eligible_case_count'],
            count($caseRuns),
            $row['resilience_pass_rate'] === null ? 'n/a' : round($row['resilience_pass_rate'] * 100, 1) . '%',
            $row['invalidated_venue_suppression_rate'] === null ? 'n/a' : round($row['invalidated_venue_suppression_rate'] * 100, 1) . '%',
            $row['valid_alternative_precision_at_5'] === null ? 'n/a' : round($row['valid_alternative_precision_at_5'] * 100, 1) . '%'
        );
    }
    if ($noSave) {
        echo "Report saving disabled by --no-save.\n";
        return;
    }

    $resultsDir = __DIR__ . DIRECTORY_SEPARATOR . 'results';
    if (!is_dir($resultsDir) && !mkdir($resultsDir, 0775, true) && !is_dir($resultsDir)) {
        failVol('Could not create the live volatility result directory.');
    }
    $stamp = gmdate('Ymd_His');
    $basePath = $resultsDir . DIRECTORY_SEPARATOR . "live_volatility_experiment_{$stamp}";
    $report = [
        'report_type'=>'live_volatility_experiment',
        'trial_type'=>'live_trial',
        'started_at_utc'=>$startedAtUtc,
        'completed_at_utc'=>gmdate('c'),
        'auto_annotation'=>[
            'policy'=>'citation_gated_machine_annotation',
            'provider'=>$annotator['provider'],
            'model'=>$annotator['model'],
            'confidence_threshold'=>0.85,
            'minimum_cited_valid_alternatives'=>3,
            'human_reviewed'=>false,
            'fixture_labels_used'=>false,
            'limitations'=>'Machine-generated source-grounded labels are exploratory and are not human-verified ground truth.',
        ],
        'run_configuration'=>[
            'selected_live_provider'=>$provider,
            'deepseek_backend_requested'=>$deepseekBackend,
            'evidence_overrides_used'=>false,
            'fresh_conditions_require'=>'Playwright cache miss with browser results',
            'stale_condition_requires'=>'Existing cache hit aged at least 12 hours and timestamped before the annotated real-world change',
            'resilience_pass_rule'=>'No cited invalidated venue in top 5 and at least 60% of returned top 5 match cited valid alternatives.',
        ],
        'summary'=>$summary,
        'detailed_live_cases'=>$caseRuns,
    ];
    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false || file_put_contents($basePath . '.json', $json . PHP_EOL, LOCK_EX) === false) {
        failVol('Could not write the live volatility experiment JSON.');
    }
    $latestJsonPath = $resultsDir . DIRECTORY_SEPARATOR . 'live_latest_volatility_experiment.json';
    if (file_put_contents($latestJsonPath, $json . PHP_EOL, LOCK_EX) === false) {
        failVol('Could not write the latest live volatility experiment JSON.');
    }
    $csv = fopen($basePath . '_summary.csv', 'wb');
    if ($csv === false) failVol('Could not write the live volatility experiment summary CSV.');
    fputcsv($csv, ['condition_id', 'eligible_cases', 'total_cases', 'resilience_pass_rate', 'invalidated_venue_suppression_rate', 'valid_alternative_precision_at_5', 'not_eligible_cases']);
    foreach ($summary as $row) {
        fputcsv($csv, [
            $row['condition_id'],
            $row['eligible_case_count'],
            count($caseRuns),
            $row['resilience_pass_rate'] ?? '',
            $row['invalidated_venue_suppression_rate'] ?? '',
            $row['valid_alternative_precision_at_5'] ?? '',
            $row['not_eligible_case_count'],
        ]);
    }
    fclose($csv);
    $latestCsvPath = $resultsDir . DIRECTORY_SEPARATOR . 'live_latest_volatility_experiment_summary.csv';
    if (!copy($basePath . '_summary.csv', $latestCsvPath)) {
        failVol('Could not write the latest live volatility experiment summary CSV.');
    }
    echo 'Saved live volatility experiment: ' . $basePath . ".json\n";
    echo 'Saved latest live volatility experiment: ' . $latestJsonPath . "\n";
    echo 'Saved live volatility summary: ' . $basePath . "_summary.csv\n";
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
        return attachLiveVolatilityObservation($record, $response);
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

    return attachLiveVolatilityObservation([
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
    ], $response);
}

function attachLiveVolatilityObservation(array $record, array $response): array {
    $data = is_array($response['data'] ?? null) ? $response['data'] : [];
    $metrics = is_array($data['metrics'] ?? null) ? $data['metrics'] : [];
    $localResults = is_array($data['results'] ?? null) ? $data['results'] : [];
    $webResults = is_array($data['webResults'] ?? null) ? $data['webResults'] : [];
    $recommendations = mergeRecommendations($localResults, $webResults, true);
    $returnedVenues = [];
    foreach (array_slice($recommendations, 0, 5) as $place) {
        $venue = [];
        foreach (['name', 'city', 'price_evidence', 'menu_item_price_evidence', 'rating', 'hours', 'source_url', '_live_volatility_note'] as $field) {
            if (array_key_exists($field, $place)) $venue[$field] = $place[$field];
        }
        $returnedVenues[] = $venue;
    }

    $volatilityTelemetry = is_array($metrics['volatility'] ?? null) ? $metrics['volatility'] : null;
    $updateCount = 0;
    if ($volatilityTelemetry !== null) {
        foreach (['closed_places', 'price_updates', 'menu_item_price_updates', 'hours_updates', 'rating_updates'] as $field) {
            $updateCount += count(is_array($volatilityTelemetry[$field] ?? null) ? $volatilityTelemetry[$field] : []);
        }
    }
    $evidenceItemsScanned = (int)($volatilityTelemetry['evidence_items_scanned'] ?? 0);
    $record['volatility_observation'] = [
        'score_status'=>'observational_not_scored',
        'telemetry_status'=>$volatilityTelemetry === null
            ? 'not_reported'
            : ($evidenceItemsScanned > 0 ? 'evidence_scanned' : 'no_evidence_scanned'),
        'evidence_items_scanned'=>$evidenceItemsScanned,
        'detected_update_count'=>$updateCount,
        'telemetry'=>$volatilityTelemetry,
        'returned_top5'=>$returnedVenues,
    ];
    return $record;
}

function runInteractiveLiveRequests(string $apiUrl, string $provider, string $deepseekBackend, bool $noSave): void {
    $records = [];
    echo "Live {$provider} volatility observation. Enter a request; submit an empty line to finish.\n";
    echo "Requests use agentic refinement and a live Playwright cache bypass.\n";
    echo "This records current reconciliation evidence; it does not calculate resilience accuracy without an independently labeled expected change.\n";
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
    $basePath = $resultsDir . DIRECTORY_SEPARATOR . "live_volatility_{$stamp}";
    $report = [
        'report_type'=>'interactive_live_volatility_observation',
        'trial_type'=>'live_observational_trial',
        'created_at_utc'=>gmdate('c'),
        'evaluation_scope'=>[
            'mode'=>'observational',
            'resilience_score'=>null,
            'score_limitation'=>'No independently labeled expected volatility changes were supplied.',
        ],
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
            'requests_with_live_volatility_evidence'=>count(array_filter(
                $records,
                static fn(array $record): bool => ($record['volatility_observation']['evidence_items_scanned'] ?? 0) > 0
            )),
            'requests_with_detected_volatility_updates'=>count(array_filter(
                $records,
                static fn(array $record): bool => ($record['volatility_observation']['detected_update_count'] ?? 0) > 0
            )),
        ],
        'requests'=>$records,
    ];
    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false || file_put_contents($basePath . '.json', $json . PHP_EOL, LOCK_EX) === false) {
        failVol('Could not write the live volatility JSON report.');
    }
    $latestJsonPath = $resultsDir . DIRECTORY_SEPARATOR . 'live_latest_volatility.json';
    if (file_put_contents($latestJsonPath, $json . PHP_EOL, LOCK_EX) === false) {
        failVol('Could not write the latest live volatility JSON report.');
    }
    $csv = fopen($basePath . '.csv', 'wb');
    if ($csv === false) failVol('Could not write the live volatility CSV report.');
    fputcsv($csv, [
        'request_id', 'query', 'provider', 'request_success', 'provider_call_success', 'all_five_stages_succeeded',
        'stage_1_planner', 'stage_2_playwright', 'stage_3_structurer', 'stage_4_deepseek', 'stage_5_final',
        'stage_details', 'execution_class', 'cache_status', 'deepseek_backend', 'deepseek_model',
        'volatility_telemetry_status', 'volatility_telemetry', 'returned_top5',
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
            $record['volatility_observation']['telemetry_status'],
            json_encode($record['volatility_observation']['telemetry'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            json_encode($record['volatility_observation']['returned_top5'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        ]);
    }
    fclose($csv);
    $latestCsvPath = $resultsDir . DIRECTORY_SEPARATOR . 'live_latest_volatility.csv';
    if (!copy($basePath . '.csv', $latestCsvPath)) {
        failVol('Could not write the latest live volatility CSV report.');
    }
    echo 'Saved live volatility JSON: ' . $basePath . ".json\n";
    echo 'Saved latest live volatility JSON: ' . $latestJsonPath . "\n";
    echo 'Saved live volatility CSV : ' . $basePath . ".csv\n";
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


