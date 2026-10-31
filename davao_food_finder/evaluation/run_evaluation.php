<?php
declare(strict_types=1);

$appRoot = dirname(__DIR__);
require_once $appRoot . '/vendor/autoload.php';
require_once $appRoot . '/src/search.php';
require_once __DIR__ . '/statistics.php';
require_once __DIR__ . '/metrics.php';
require_once __DIR__ . '/usability.php';

function fail(string $message): never {
    fwrite(STDERR, "Error: {$message}\n");
    exit(1);
}

function parseArguments(array $arguments): array {
    $options = [
        'require_ai'=>false,
        'quick'=>false,
        'research'=>false,
        'live_turns'=>false,
        'no_save'=>false,
        'local_cli'=>false,
        'api_url'=>'http://127.0.0.1:8080/api.php',
        'ground_truth'=>__DIR__ . '/ground_truth.json',
        'providers'=>['deepseek'],
        'providers_explicit'=>false,
        'split'=>'all',
        'limit'=>0,
        'trials'=>1,
        'trials_explicit'=>false,
        'cache_policy'=>'default',
        'seed'=>42,
        'output'=>'',
    ];

    foreach ($arguments as $argument) {
        if ($argument === '--require-ai') {
            $options['require_ai'] = true;
        } elseif ($argument === '--quick') {
            $options['quick'] = true;
        } elseif ($argument === '--research' || $argument === '--compare-all') {
            $options['research'] = true;
        } elseif ($argument === '--live-turns') {
            $options['live_turns'] = true;
        } elseif ($argument === '--no-save') {
            $options['no_save'] = true;
        } elseif ($argument === '--local-cli') {
            $options['local_cli'] = true;
            $options['api_url'] = 'local';
        } elseif ($argument === '--local-ablation') {
            $options['local_cli'] = true;
            $options['api_url'] = 'local';
            $options['research'] = true;
            if (!$options['providers_explicit']) {
                $options['providers'] = ['baseline', 'ablation-no-loop', 'agentic-local'];
                $options['providers_explicit'] = true;
            }
        } elseif (str_starts_with($argument, '--api-url=')) {
            $options['api_url'] = substr($argument, strlen('--api-url='));
            if (strtolower(trim($options['api_url'])) === 'local') {
                $options['local_cli'] = true;
                $options['api_url'] = 'local';
            }
        } elseif (str_starts_with($argument, '--ground-truth=')) {
            $options['ground_truth'] = substr($argument, strlen('--ground-truth='));
        } elseif (str_starts_with($argument, '--provider=')) {
            $options['providers'] = [strtolower(trim(substr($argument, strlen('--provider='))))];
            $options['providers_explicit'] = true;
        } elseif (str_starts_with($argument, '--providers=')) {
            $options['providers'] = array_values(array_filter(array_map(fn($s) => strtolower(trim($s)), explode(',', substr($argument, strlen('--providers='))))));
            $options['providers_explicit'] = true;
        } elseif (str_starts_with($argument, '--split=')) {
            $options['split'] = strtolower(trim(substr($argument, strlen('--split='))));
        } elseif (str_starts_with($argument, '--limit=')) {
            $options['limit'] = max(0, (int)substr($argument, strlen('--limit=')));
        } elseif (str_starts_with($argument, '--trials=')) {
            $options['trials'] = max(1, min(20, (int)substr($argument, strlen('--trials='))));
            $options['trials_explicit'] = true;
        } elseif (str_starts_with($argument, '--cache-policy=')) {
            $options['cache_policy'] = strtolower(trim(substr($argument, strlen('--cache-policy='))));
        } elseif (str_starts_with($argument, '--seed=')) {
            $options['seed'] = (int)substr($argument, strlen('--seed='));
        } elseif (str_starts_with($argument, '--output=')) {
            $options['output'] = trim(substr($argument, strlen('--output=')));
        } else {
            fail("Unknown option {$argument}. Supported: --quick, --research, --local-cli, --local-ablation, --require-ai, --live-turns, --no-save, --api-url=URL, --ground-truth=PATH, --provider=..., --providers=baseline,ablation-no-loop,agentic-local,non-agentic,deepseek,gpt-4o-mini,hybrid, --split=all|train|dev|test, --trials=N, --cache-policy=default|cold|warm|none, --seed=N, --limit=N, --output=PATH.");
        }
    }

    if ($options['research']) {
        if (!$options['providers_explicit']) {
            $options['providers'] = ['baseline', 'ablation-no-loop', 'agentic-local', 'non-agentic', 'deepseek', 'gpt-4o-mini', 'hybrid'];
        }
        if (!$options['trials_explicit'] && !$options['quick']) {
            $options['trials'] = 3;
        }
    }

    if (!$options['local_cli']) {
        $url = filter_var($options['api_url'], FILTER_VALIDATE_URL);
        $host = strtolower((string)parse_url($options['api_url'], PHP_URL_HOST));
        if (!$url || !in_array($host, ['127.0.0.1', 'localhost', '[::1]', '::1'], true)
            || !in_array(strtolower((string)parse_url($options['api_url'], PHP_URL_SCHEME)), ['http', 'https'], true)) {
            fail('The evaluation API URL must point to a local PHP server (e.g., http://127.0.0.1:8080/api.php) or --local-cli.');
        }
    }

    $allowedProviders = ['baseline', 'ablation-no-loop', 'agentic-local', 'non-agentic', 'deepseek', 'gpt-4o-mini', 'hybrid'];
    foreach ($options['providers'] as $provider) {
        if (!in_array($provider, $allowedProviders, true)) {
            fail('Provider must be one of: ' . implode(', ', $allowedProviders) . '.');
        }
    }
    if (!in_array($options['split'], ['all', 'train', 'dev', 'test'], true)) {
        fail('Split must be one of: all, train, dev, test.');
    }
    if (!in_array($options['cache_policy'], ['default', 'cold', 'bypass', 'warm', 'none'], true)) {
        fail('Cache policy must be one of: default, cold, bypass, warm, none.');
    }
    $options['providers'] = array_values(array_unique($options['providers']));
    return $options;
}

function sendSingleApiRequest(string $apiUrl, string $query, array $history, array $shownNames, array $config, string $cachePolicy, int $seed): array {
    $body = json_encode([
        'query'=>$query,
        'history'=>$history,
        'shownNames'=>$shownNames,
        'evaluation'=>[
            'provider'=>$config['provider'],
            'temperature'=>$config['temperature'],
            'promptStyle'=>$config['prompt_style'],
            'agentic'=>$config['agentic'] ?? ($config['provider'] !== 'baseline' && $config['provider'] !== 'non-agentic'),
            'cachePolicy'=>$cachePolicy,
            'seed'=>$seed,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);

    if ($apiUrl === 'local') {
        $apiFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'api.php';
        $phpBin = PHP_BINARY ?: 'php';
        $runnerCode = '$_SERVER["REQUEST_METHOD"]="POST"; $_SERVER["REMOTE_ADDR"]="127.0.0.1"; chdir(' . var_export(dirname($apiFile), true) . '); require ' . var_export($apiFile, true) . ';';
        $startedAt = microtime(true);
        $pipes = [];
        $proc = proc_open([$phpBin, '-r', $runnerCode], [0=>['pipe', 'r'], 1=>['pipe', 'w'], 2=>['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            return ['latency'=>0.0, 'failure'=>'Could not launch local PHP CLI worker.', 'data'=>null];
        }
        fwrite($pipes[0], $body);
        fclose($pipes[0]);
        $raw = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);
        $latency = microtime(true) - $startedAt;
        if ($raw === false || trim($raw) === '') {
            return ['latency'=>$latency, 'failure'=>'Local CLI worker returned empty output: ' . trim((string)$stderr), 'data'=>null];
        }
        $status = $exitCode === 0 ? 200 : 500;
    } else {
        $handle = curl_init($apiUrl);
        if ($handle === false) throw new RuntimeException('Could not initialize cURL.');
        curl_setopt_array($handle, [
            CURLOPT_POST=>true,
            CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
            CURLOPT_POSTFIELDS=>$body,
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_TIMEOUT=>180,
        ]);

        $startedAt = microtime(true);
        $raw = curl_exec($handle);
        $latency = microtime(true) - $startedAt;
        $status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $curlError = curl_error($handle);
        curl_close($handle);

        if ($raw === false) {
            return ['latency'=>$latency, 'failure'=>'Connection failed: ' . $curlError, 'data'=>null];
        }
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return ['latency'=>$latency, 'failure'=>"Invalid JSON response (HTTP {$status}).", 'data'=>null];
    }
    if ($status < 200 || $status >= 300) {
        return ['latency'=>$latency, 'failure'=>(string)($data['error'] ?? "HTTP {$status}."), 'data'=>$data];
    }
    if (($data['assistantStatus'] ?? '') !== 'ready') {
        $provider = (string)($config['provider'] ?? 'deepseek');
        $statusMessage = match ($data['assistantStatus'] ?? '') {
            'not_configured' => in_array($provider, ['gpt-4o-mini', 'hybrid'], true)
                ? 'OpenAI is not configured: set OPENAI_API_KEY on the PHP server.'
                : 'DeepSeek R1 is not configured: run Ollama or set DEEPSEEK_API_KEY.',
            'error' => in_array($provider, ['gpt-4o-mini', 'hybrid'], true)
                ? 'GPT-4o mini / hybrid pipeline failed; check the PHP server error log.'
                : 'DeepSeek R1 failed; check Ollama or PHP server error log.',
            default => 'The API did not report a ready response.',
        };
        $providerError = $data['metrics']['gpt4o']['error'] ?? null;
        if (is_array($providerError)) {
            $http = (int)($providerError['http'] ?? 0);
            $message = trim((string)($providerError['message'] ?? ''));
            $statusMessage = 'Provider failed'
                . ($http > 0 ? ' with HTTP ' . $http : '')
                . ($message !== '' ? ': ' . mb_substr($message, 0, 240) : '')
                . '.';
        }
        return ['latency'=>$latency, 'failure'=>$statusMessage, 'data'=>$data];
    }
    return ['latency'=>$latency, 'failure'=>null, 'data'=>$data];
}

/**
 * Execute either a single-turn query or a multi-turn dialogue case.
 */
function executeCaseEvaluation(string $apiUrl, array $case, array $config, string $cachePolicy, int $seed, bool $liveTurns = false): array {
    $turns = is_array($case['turns'] ?? null) ? $case['turns'] : [];
    if (!$turns) {
        return sendSingleApiRequest($apiUrl, $case['query'], [], [], $config, $cachePolicy, $seed);
    }

    $history = [];
    $shownNames = [];
    $totalTurnLatency = 0.0;

    if ($liveTurns) {
        $lastResp = null;
        foreach ($turns as $idx => $turn) {
            $tQuery = trim((string)($turn['query'] ?? ''));
            $resp = sendSingleApiRequest($apiUrl, $tQuery, $history, $shownNames, $config, $cachePolicy, $seed + $idx);
            $totalTurnLatency += $resp['latency'];
            $lastResp = $resp;
            if ($resp['failure'] !== null) break;
            $d = $resp['data'] ?? [];
            $history[] = ['role'=>'user', 'content'=>$tQuery];
            if (!empty($d['answer'])) $history[] = ['role'=>'assistant', 'content'=>(string)$d['answer']];
            foreach (($d['webResults'] ?? []) as $wp) if (!empty($wp['name'])) $shownNames[] = (string)$wp['name'];
            foreach (array_slice($d['results'] ?? [], 0, 5) as $lp) if (!empty($lp['name'])) $shownNames[] = (string)$lp['name'];
            $shownNames = array_values(array_unique($shownNames));
        }
        if ($lastResp !== null) {
            // Report final turn latency as primary request latency, with cumulative dialogue latency recorded
            $lastResp['dialogue_latency'] = $totalTurnLatency;
            return $lastResp;
        }
    }

    // Controlled multi-turn replay: feed identical prior turn history + shownNames across all systems
    $turnCount = count($turns);
    for ($i = 0; $i < $turnCount - 1; $i++) {
        $t = $turns[$i];
        $q = trim((string)($t['query'] ?? ''));
        if ($q !== '') $history[] = ['role'=>'user', 'content'=>$q];
        $ans = trim((string)($t['assistant_summary'] ?? ''));
        if ($ans !== '') $history[] = ['role'=>'assistant', 'content'=>$ans];
        foreach (($t['shown_names'] ?? []) as $sn) {
            if (is_string($sn) && trim($sn) !== '') $shownNames[] = trim($sn);
        }
    }
    $shownNames = array_values(array_unique($shownNames));
    $finalTurn = $turns[$turnCount - 1];
    $finalQuery = trim((string)($finalTurn['query'] ?? $case['query']));
    return sendSingleApiRequest($apiUrl, $finalQuery, $history, $shownNames, $config, $cachePolicy, $seed);
}

function computeAnnotatorAgreementReport(string $evalDir, array $fallbackSamples): array {
    $annotationsDir = $evalDir . DIRECTORY_SEPARATOR . 'annotations';
    $humanFiles = is_dir($annotationsDir) ? (glob($annotationsDir . '/*.json') ?: []) : [];

    // Check if at least two independent human annotator files exist
    $byRater = [];
    foreach ($humanFiles as $file) {
        $decoded = json_decode((string)file_get_contents($file), true);
        if (!is_array($decoded) || empty($decoded['annotator_id']) || !is_array($decoded['ratings'] ?? null)) continue;
        $raterId = (string)$decoded['annotator_id'];
        foreach ($decoded['ratings'] as $r) {
            if (!is_array($r) || empty($r['item_key']) || !isset($r['relevance_grade'])) continue;
            $byRater[$raterId][(string)$r['item_key']] = (int)$r['relevance_grade'];
        }
    }

    if (count($byRater) >= 2) {
        $raterIds = array_keys($byRater);
        $commonKeys = array_keys($byRater[$raterIds[0]]);
        for ($i = 1; $i < count($raterIds); $i++) {
            $commonKeys = array_values(array_intersect($commonKeys, array_keys($byRater[$raterIds[$i]])));
        }
        sort($commonKeys);
        if (count($commonKeys) >= 2) {
            $rA = [];
            $rB = [];
            $matrix = [];
            foreach ($commonKeys as $k) {
                $rA[] = $byRater[$raterIds[0]][$k];
                $rB[] = $byRater[$raterIds[1]][$k];
                $row = [];
                foreach ($raterIds as $rid) $row[] = $byRater[$rid][$k];
                $matrix[] = $row;
            }
            return [
                'source'=>'independent_human_annotators',
                'annotator_count'=>count($raterIds),
                'overlapping_items'=>count($commonKeys),
                'cohens_kappa_unweighted'=>cohensKappa($rA, $rB, false),
                'cohens_kappa_weighted'=>cohensKappa($rA, $rB, true),
                'fleiss_kappa'=>fleissKappa($matrix),
            ];
        }
    }

    // Fallback to dual-rubric agreement samples in ground_truth.json
    if (count($fallbackSamples) >= 2) {
        $r1 = [];
        $r2 = [];
        $matrix = [];
        foreach ($fallbackSamples as $s) {
            if (!isset($s['rater_1'], $s['rater_2'])) continue;
            $r1[] = (int)$s['rater_1'];
            $r2[] = (int)$s['rater_2'];
            $row = [(int)$s['rater_1'], (int)$s['rater_2']];
            if (isset($s['rater_3'])) $row[] = (int)$s['rater_3'];
            $matrix[] = $row;
        }
        return [
            'source'=>(string)($fallbackSamples[0]['source_type'] ?? 'rubric_criteria_audit'),
            'annotator_count'=>isset($fallbackSamples[0]['rater_3']) ? 3 : 2,
            'overlapping_items'=>count($r1),
            'cohens_kappa_unweighted'=>cohensKappa($r1, $r2, false),
            'cohens_kappa_weighted'=>cohensKappa($r1, $r2, true),
            'fleiss_kappa'=>fleissKappa($matrix),
        ];
    }

    return [
        'source'=>'none',
        'annotator_count'=>0,
        'overlapping_items'=>0,
        'cohens_kappa_unweighted'=>null,
        'cohens_kappa_weighted'=>null,
        'fleiss_kappa'=>null,
    ];
}

try {
    if (PHP_SAPI !== 'cli') fail('Run this evaluator from a terminal with PHP CLI.');
    if (!extension_loaded('curl') || !extension_loaded('mbstring')) fail('Enable PHP extensions curl and mbstring before running evaluation.');

    $runStartedIso = gmdate('c');
    $options = parseArguments(array_slice($argv, 1));
    $groundTruthPath = $options['ground_truth'];
    if (!str_starts_with($groundTruthPath, DIRECTORY_SEPARATOR) && !preg_match('/^[A-Za-z]:[\\\\\/]/', $groundTruthPath)) {
        $groundTruthPath = getcwd() . DIRECTORY_SEPARATOR . $groundTruthPath;
    }
    if (!is_file($groundTruthPath)) {
        fail("Ground-truth file not found: {$groundTruthPath}. Run evaluation/build_ground_truth.php or copy evaluation/ground_truth.example.json.");
    }
    $groundTruthRaw = (string)file_get_contents($groundTruthPath);
    $groundTruthHash = hash('sha256', $groundTruthRaw);
    if (str_starts_with($groundTruthRaw, "\xEF\xBB\xBF")) $groundTruthRaw = substr($groundTruthRaw, 3);
    $groundTruthDecoded = json_decode($groundTruthRaw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($groundTruthDecoded)) fail('Ground-truth JSON must contain a top-level object with a cases array.');

    $validatedGt = validateAndNormalizeGroundTruth($groundTruthDecoded);
    $allCases = $validatedGt['cases'];
    $allScopeCases = $validatedGt['scope_cases'];

    if ($options['split'] !== 'all') {
        $allCases = array_values(array_filter($allCases, fn($c) => $c['split'] === $options['split']));
        $allScopeCases = array_values(array_filter($allScopeCases, fn($sc) => $sc['split'] === $options['split']));
    }
    if ($options['limit'] > 0) {
        $allCases = array_slice($allCases, 0, $options['limit']);
        $allScopeCases = array_slice($allScopeCases, 0, max(1, intdiv($options['limit'], 3)));
    }
    if (!$allCases) fail("No ground-truth cases matched split='{$options['split']}'.");

    // Load local Davao dataset once to validate returned factual details & review signals accurately
    $dataDir = $appRoot . DIRECTORY_SEPARATOR . 'data';
    $datasetIndex = [];
    $placesCachePath = $dataDir . DIRECTORY_SEPARATOR . '.places-cache.json';
    $loadedPlaces = [];
    if (is_file($placesCachePath)) {
        $cachedObj = json_decode((string)file_get_contents($placesCachePath), true);
        if (is_array($cachedObj['places'] ?? null) && (int)($cachedObj['schema_version'] ?? 1) === PLACES_CACHE_SCHEMA_VERSION) {
            $loadedPlaces = $cachedObj['places'];
        }
    }
    if (!$loadedPlaces) {
        $loadedPlaces = loadDavaoPlaces($dataDir);
    }
    foreach ($loadedPlaces as $lp) {
        $norm = normalizePlaceName((string)($lp['name'] ?? ''));
        if ($norm !== '') $datasetIndex[$norm] = $lp;
    }

    $datasetHashes = [];
    foreach (glob($dataDir . '/*.{csv,xlsx,xls}', GLOB_BRACE) ?: [] as $df) {
        $datasetHashes[basename($df)] = [
            'size_bytes'=>filesize($df),
            'sha256'=>hash_file('sha256', $df),
        ];
    }

    // Build evaluation configurations
    $configurations = [];
    foreach ($options['providers'] as $provider) {
        if ($provider === 'baseline') {
            $configurations[] = [
                'provider'=>'baseline',
                'id'=>'BASELINE-LOCAL',
                'temperature'=>0.0,
                'top_p'=>1.0,
                'prompt_style'=>'baseline',
                'agentic'=>false,
            ];
            continue;
        }
        if ($provider === 'ablation-no-loop') {
            $configurations[] = [
                'provider'=>'ablation-no-loop',
                'id'=>'ABLATION-NO-LOOP',
                'temperature'=>0.0,
                'top_p'=>1.0,
                'prompt_style'=>'strict_grounding',
                'agentic'=>true,
            ];
            continue;
        }
        if ($provider === 'agentic-local') {
            $configurations[] = [
                'provider'=>'agentic-local',
                'id'=>'AGENTIC-LOOP-LOCAL',
                'temperature'=>0.0,
                'top_p'=>1.0,
                'prompt_style'=>'strict_grounding',
                'agentic'=>true,
            ];
            continue;
        }
        if ($options['research']) {
            $configurations[] = [
                'provider'=>$provider,
                'id'=>strtoupper($provider) . '-T0.2-STRICT',
                'temperature'=>0.2,
                'top_p'=>1.0,
                'prompt_style'=>'strict_grounding',
                'agentic'=>!in_array($provider, ['non-agentic', 'ablation-no-loop'], true),
            ];
            continue;
        }

        $temperatures = $options['quick'] ? [0.0] : [0.0, 0.3, 0.7];
        $promptStyles = $options['quick'] ? ['baseline'] : ['baseline', 'strict_grounding'];
        foreach ($temperatures as $temperature) {
            foreach ($promptStyles as $promptStyle) {
                $configurations[] = [
                    'provider'=>$provider,
                    'id'=>sprintf('%s-T%.1f-%s', strtoupper($provider), $temperature, $promptStyle === 'baseline' ? 'BASE' : 'STRICT'),
                    'temperature'=>$temperature,
                    'top_p'=>1.0,
                    'prompt_style'=>$promptStyle,
                    'agentic'=>$provider !== 'non-agentic',
                ];
            }
        }
        if (!$options['quick']) {
            $additionalConfigurations = [
                ['temperature'=>0.1, 'prompt_style'=>'baseline'],
                ['temperature'=>0.1, 'prompt_style'=>'strict_grounding'],
                ['temperature'=>0.5, 'prompt_style'=>'baseline'],
                ['temperature'=>0.5, 'prompt_style'=>'strict_grounding'],
                ['temperature'=>0.9, 'prompt_style'=>'strict_grounding'],
            ];
            foreach ($additionalConfigurations as $additional) {
                $temperature = $additional['temperature'];
                $promptStyle = $additional['prompt_style'];
                $configurations[] = [
                    'provider'=>$provider,
                    'id'=>sprintf('%s-T%.1f-%s', strtoupper($provider), $temperature, $promptStyle === 'baseline' ? 'BASE' : 'STRICT'),
                    'temperature'=>$temperature,
                    'top_p'=>1.0,
                    'prompt_style'=>$promptStyle,
                    'agentic'=>$provider !== 'non-agentic',
                ];
            }
        }
    }

    $agreementReport = computeAnnotatorAgreementReport(__DIR__, $validatedGt['annotator_agreement_samples']);

    $inScopeRetrievalCases = array_values(array_filter($allCases, fn($c) => empty($c['expect_out_of_scope'])));
    $totalCasesPerTrial = count($allCases) + count($allScopeCases);
    $trials = $options['trials'];

    echo "====================================================================================================\n";
    echo " Davao Food Finder Comprehensive Evaluation & Agentic Intent Benchmark (v3.0)\n";
    echo "====================================================================================================\n";
    echo 'API Endpoint       : ' . $options['api_url'] . "\n";
    echo 'Ground Truth       : ' . basename($groundTruthPath) . ' (v' . $validatedGt['metadata']['version'] . ', sha256=' . substr($groundTruthHash, 0, 12) . "...)\n";
    echo 'Benchmark Split    : ' . $options['split'] . ' (' . count($allCases) . ' in-scope/multi-turn cases + ' . count($allScopeCases) . " scope cases)\n";
    echo 'Systems / Providers: ' . implode(', ', $options['providers']) . "\n";
    echo 'Configurations     : ' . count($configurations) . " | Trials/config: {$trials} | Seed: {$options['seed']} | Cache policy: {$options['cache_policy']}\n";
    echo 'Total HTTP Requests: ' . ($totalCasesPerTrial * count($configurations) * $trials) . "\n";
    if ($agreementReport['cohens_kappa_weighted'] !== null) {
        printf(
            "Annotator Agreement: source=%s (n=%d) | Cohen's k=%.3f (weighted k=%.3f, %s)%s\n",
            $agreementReport['source'],
            $agreementReport['overlapping_items'],
            $agreementReport['cohens_kappa_unweighted']['kappa'] ?? 0.0,
            $agreementReport['cohens_kappa_weighted']['kappa'] ?? 0.0,
            $agreementReport['cohens_kappa_weighted']['interpretation'] ?? '',
            $agreementReport['fleiss_kappa'] !== null ? sprintf(' | Fleiss k=%.3f', $agreementReport['fleiss_kappa']['kappa']) : ''
        );
    }

    $summary = [];
    $pairedScores = [
        'p5'=>[],
        'ndcg5'=>[],
        'mrr'=>[],
        'constraint'=>[],
    ];
    $configIds = [];
    $detailedRuns = [];
    $anyAiFailure = false;

    foreach ($configurations as $config) {
        $effectiveTrials = $config['provider'] === 'baseline' ? 1 : $trials;
        echo "\n--- CONFIG: {$config['id']} | Provider={$config['provider']} | Agentic=" . (($config['agentic'] ?? true) ? 'YES' : 'NO') . " | Temp={$config['temperature']} | Prompt={$config['prompt_style']} | Trials={$effectiveTrials} ---\n";

        $allLatencies = [];
        $coldLatencies = [];
        $warmLatencies = [];
        $datasetMsList = [];
        $playwrightMsList = [];
        $aiMsList = [];
        $costList = [];
        $totalTokensSum = 0;

        $failures = 0;
        $totalExecutedRequests = 0;
        $playwrightSuccesses = 0;
        $playwrightRequests = 0;
        $playwrightCacheHits = 0;
        $playwrightCacheMisses = 0;
        $playwrightEvidenceCount = 0;
        $providerSuccesses = 0;
        $providerRequests = 0;

        $errorTaxonomy = [
            'duplicates'=>0,
            'wrong_locations'=>0,
            'missing_sources'=>0,
            'fabricated_details'=>0,
            'unsupported_claims'=>0,
            'constraint_failures'=>0,
            'scope_violations'=>0,
            'examples'=>[],
        ];

        // Per-case accumulators across trials
        $perCaseTrialRecords = [];
        foreach ($allCases as $c) $perCaseTrialRecords[$c['id']] = [];
        $perScopeTrialRecords = [];
        foreach ($allScopeCases as $sc) $perScopeTrialRecords[$sc['id']] = [];

        for ($trial = 1; $trial <= $effectiveTrials; $trial++) {
            $trialSeed = $options['seed'] + ($trial - 1) * 1009;
            if ($effectiveTrials > 1) {
                echo "  [Trial {$trial}/{$effectiveTrials} | seed={$trialSeed}]\n";
            }

            foreach ($allCases as $index => $case) {
                $totalExecutedRequests++;
                $response = executeCaseEvaluation($options['api_url'], $case, $config, $options['cache_policy'], $trialSeed, $options['live_turns']);
                $data = $response['data'];
                $latency = (float)$response['latency'];
                $allLatencies[] = $latency;
                $failure = $response['failure'];

                $metrics = is_array($data['metrics'] ?? null) ? $data['metrics'] : [];
                $timings = is_array($metrics['timings_ms'] ?? null) ? $metrics['timings_ms'] : [];
                $cacheInfo = is_array($metrics['cache'] ?? null) ? $metrics['cache'] : [];
                $usageInfo = is_array($metrics['usage'] ?? null) ? $metrics['usage'] : [];
                $playwright = is_array($metrics['playwright'] ?? null) ? $metrics['playwright'] : [];

                $datasetMsList[] = (float)($timings['dataset_total_ms'] ?? 0);
                $playwrightMsList[] = (float)($timings['playwright_ms'] ?? ($playwright['latency_ms'] ?? 0));
                $aiMsList[] = (float)($timings['ai_inference_ms'] ?? 0);
                $costList[] = (float)($usageInfo['estimated_cost_usd'] ?? 0.0);
                $totalTokensSum += (int)($usageInfo['total_tokens'] ?? 0);

                $pwCache = (string)($cacheInfo['playwright'] ?? ($playwright['cache'] ?? 'unknown'));
                if ($pwCache === 'hit') {
                    $warmLatencies[] = $latency;
                    $playwrightCacheHits++;
                } elseif ($pwCache === 'miss') {
                    $coldLatencies[] = $latency;
                    $playwrightCacheMisses++;
                }
                if ($playwright && !in_array($pwCache, ['disabled', 'skipped_out_of_scope'], true)) {
                    $playwrightRequests++;
                    if (($playwright['success'] ?? false) === true) $playwrightSuccesses++;
                    $playwrightEvidenceCount += (int)($playwright['result_count'] ?? 0);
                }

                if ($config['provider'] !== 'baseline') {
                    $providerRequests++;
                    if ($failure === null) $providerSuccesses++;
                }

                if ($failure !== null) {
                    $failures++;
                    if ($config['provider'] !== 'baseline') $anyAiFailure = true;
                    $record = [
                        'trial'=>$trial,
                        'failed'=>true,
                        'failure'=>$failure,
                        'latency'=>$latency,
                        'combined'=>['precision_at_5'=>0.0, 'recall_at_5'=>0.0, 'recall_at_10'=>0.0, 'ndcg_at_5'=>0.0, 'mrr'=>0.0],
                        'local'=>['precision_at_5'=>0.0, 'recall_at_5'=>0.0, 'recall_at_10'=>0.0, 'ndcg_at_5'=>0.0, 'mrr'=>0.0],
                        'web'=>['precision_at_5'=>0.0, 'recall_at_5'=>0.0, 'recall_at_10'=>0.0, 'ndcg_at_5'=>0.0, 'mrr'=>0.0, 'returned_count'=>0],
                        'constraint'=>['davao_only_pass'=>false, 'strict_pass'=>false, 'item_pass_rate'=>0.0],
                        'validation'=>['factual_correctness_rate'=>0.0, 'evidence_support_rate'=>0.0, 'web_source_support_rate'=>null, 'source_fresh'=>false],
                        'scope_passed'=>false,
                        'followup_passed'=>false,
                        'top5_names'=>[],
                    ];
                    $perCaseTrialRecords[$case['id']][] = $record;
                    printf("  [%d/%d] %-6s (%-16s) | ERROR: %s (%.2fs)\n", $index + 1, count($allCases), $case['id'], $case['category'], $failure, $latency);
                    continue;
                }

                $localResults = is_array($data['results'] ?? null) ? $data['results'] : [];
                $webResults = is_array($data['webResults'] ?? null) ? $data['webResults'] : [];
                $combinedResults = mergeRecommendations($localResults, $webResults, true);

                // Special handling if a multi-turn case expects out-of-scope rejection (e.g., MT005)
                if (!empty($case['expect_out_of_scope'])) {
                    $scopePassed = ($data['outOfScope'] ?? false) === true && !$localResults && !$webResults;
                    if (!$scopePassed) {
                        $errorTaxonomy['scope_violations']++;
                        if (count($errorTaxonomy['examples']) < 15) {
                            $errorTaxonomy['examples'][] = "{$case['id']}: failed mid-dialogue out-of-scope refusal";
                        }
                    }
                    $record = [
                        'trial'=>$trial,
                        'failed'=>false,
                        'latency'=>$latency,
                        'expect_out_of_scope'=>true,
                        'scope_passed'=>$scopePassed,
                        'followup_passed'=>$scopePassed,
                        'top5_names'=>[],
                    ];
                    $perCaseTrialRecords[$case['id']][] = $record;
                    printf(
                        "  [%d/%d] %-6s (%-16s) | Dialogue Scope Check: %s | Latency %.2fs\n",
                        $index + 1,
                        count($allCases),
                        $case['id'],
                        $case['category'],
                        $scopePassed ? 'PASS (refused outside city)' : 'FAIL',
                        $latency
                    );
                    continue;
                }

                $falseOutOfScope = ($data['outOfScope'] ?? false) === true;
                $localRank = computeRankingMetrics($localResults, $case['relevance_grades'], $case['base_relevance_grades'], $case['constraints'], false);
                $webRank = computeRankingMetrics($webResults, $case['relevance_grades'], $case['base_relevance_grades'], $case['constraints'], true);
                $combinedRank = computeRankingMetrics($combinedResults, $case['relevance_grades'], $case['base_relevance_grades'], $case['constraints'], true);

                $constraintEval = evaluateConstraintSatisfaction($combinedResults, $case['constraints'], $datasetIndex);
                $validation = validateRecommendationQuality($data, $case, $datasetIndex);

                $errorTaxonomy['duplicates'] += $validation['duplicate_count'];
                $errorTaxonomy['wrong_locations'] += $validation['wrong_location_count'];
                $errorTaxonomy['missing_sources'] += $validation['missing_source_count'];
                $errorTaxonomy['fabricated_details'] += $validation['fabricated_detail_count'];
                $errorTaxonomy['unsupported_claims'] += $validation['unsupported_claim_count'];
                if (!$constraintEval['strict_pass']) {
                    $errorTaxonomy['constraint_failures']++;
                    if (count($errorTaxonomy['examples']) < 15) {
                        $errorTaxonomy['examples'][] = "{$case['id']} ({$case['category']}): failed constraints [" . implode(',', $constraintEval['failed_dimensions']) . "]";
                    }
                }
                foreach ($validation['issues'] as $iss) {
                    if (count($errorTaxonomy['examples']) < 15) {
                        $errorTaxonomy['examples'][] = "{$case['id']}: {$iss['type']} (" . ($iss['name'] ?? ($iss['excerpt'] ?? '')) . ")";
                    }
                }

                $followupPassed = $case['category'] === 'multi_turn'
                    ? (!$falseOutOfScope && $combinedRank['precision_at_5'] > 0.0 && $constraintEval['strict_pass'])
                    : null;

                $top5Names = array_values(array_filter(array_map(
                    fn($p) => normalizePlaceName((string)($p['name'] ?? '')),
                    array_slice($combinedResults, 0, 5)
                )));

                $top5Display = array_values(array_filter(array_map(
                    fn($p) => trim((string)($p['name'] ?? '')),
                    array_slice($combinedResults, 0, 5)
                )));

                $agenticLoopInfo = is_array($metrics['agentic_loop'] ?? null) ? $metrics['agentic_loop'] : [];

                $record = [
                    'trial'=>$trial,
                    'failed'=>false,
                    'false_out_of_scope'=>$falseOutOfScope,
                    'latency'=>$latency,
                    'combined'=>$combinedRank,
                    'local'=>$localRank,
                    'web'=>$webRank,
                    'constraint'=>$constraintEval,
                    'validation'=>$validation,
                    'agentic_loop'=>$agenticLoopInfo,
                    'scope_passed'=>!$falseOutOfScope && $constraintEval['davao_only_pass'],
                    'followup_passed'=>$followupPassed,
                    'top5_names'=>$top5Names,
                    'sample_answer'=>$trial === 1 ? mb_substr((string)($data['answer'] ?? ''), 0, 600) : null,
                    'sample_top5_display'=>$trial === 1 ? $top5Display : [],
                ];
                $perCaseTrialRecords[$case['id']][] = $record;

                printf(
                    "  [%d/%d] %-6s (%-16s) | P@5 %5.1f%% | NDCG@5 %5.1f%% | MRR %.2f | Constr %5.1f%% | Faith %5.1f%% | %.2fs (%s)\n",
                    $index + 1,
                    count($allCases),
                    $case['id'],
                    $case['category'],
                    $combinedRank['precision_at_5'] * 100,
                    $combinedRank['ndcg_at_5'] * 100,
                    $combinedRank['mrr'],
                    $constraintEval['item_pass_rate'] * 100,
                    $validation['evidence_support_rate'] * 100,
                    $latency,
                    $pwCache
                );
            }

            foreach ($allScopeCases as $scopeCase) {
                $totalExecutedRequests++;
                $response = sendSingleApiRequest($options['api_url'], $scopeCase['query'], [], [], $config, $options['cache_policy'], $trialSeed);
                $latency = (float)$response['latency'];
                $allLatencies[] = $latency;
                $failure = $response['failure'];
                $data = $response['data'];

                $metrics = is_array($data['metrics'] ?? null) ? $data['metrics'] : [];
                $timings = is_array($metrics['timings_ms'] ?? null) ? $metrics['timings_ms'] : [];
                $usageInfo = is_array($metrics['usage'] ?? null) ? $metrics['usage'] : [];
                $datasetMsList[] = (float)($timings['dataset_total_ms'] ?? 0);
                $playwrightMsList[] = (float)($timings['playwright_ms'] ?? 0);
                $aiMsList[] = (float)($timings['ai_inference_ms'] ?? 0);
                $costList[] = (float)($usageInfo['estimated_cost_usd'] ?? 0.0);
                $totalTokensSum += (int)($usageInfo['total_tokens'] ?? 0);

                if ($config['provider'] !== 'baseline') {
                    $providerRequests++;
                    if ($failure === null) $providerSuccesses++;
                }

                if ($failure !== null) {
                    $failures++;
                    if ($config['provider'] !== 'baseline') $anyAiFailure = true;
                    $perScopeTrialRecords[$scopeCase['id']][] = ['trial'=>$trial, 'failed'=>true, 'scope_passed'=>false, 'latency'=>$latency];
                    printf("  [Scope %-4s] ERROR: %s (%.2fs)\n", $scopeCase['id'], $failure, $latency);
                    continue;
                }

                $results = is_array($data['results'] ?? null) ? $data['results'] : [];
                $webResults = is_array($data['webResults'] ?? null) ? $data['webResults'] : [];
                $scopePassed = ($data['outOfScope'] ?? false) === true && !$results && !$webResults;
                if (!$scopePassed) {
                    $errorTaxonomy['scope_violations']++;
                    if (count($errorTaxonomy['examples']) < 15) {
                        $errorTaxonomy['examples'][] = "{$scopeCase['id']}: scope violation on '{$scopeCase['query']}'";
                    }
                }
                $perScopeTrialRecords[$scopeCase['id']][] = ['trial'=>$trial, 'failed'=>false, 'scope_passed'=>$scopePassed, 'latency'=>$latency];
                printf(
                    "  [Scope %-4s] Davao-only rejection: %-4s | Latency %.2fs | %s\n",
                    $scopeCase['id'],
                    $scopePassed ? 'PASS' : 'FAIL',
                    $latency,
                    $scopeCase['query']
                );
            }
        }

        // Aggregate per-case metrics across trials for paired statistical tests & CIs
        $caseP5 = [];
        $caseR5 = [];
        $caseR10 = [];
        $caseNdcg5 = [];
        $caseMrr = [];
        $caseLocalP5 = [];
        $caseLocalNdcg5 = [];
        $caseWebP5 = [];
        $caseWebNdcg5 = [];
        $caseConstraintRate = [];
        $caseStrictPass = [];
        $caseFactRate = [];
        $caseFaithRate = [];
        $caseWebSourceSupport = [];
        $caseConsistency = [];
        $followupOutcomes = [];
        $scopeOutcomes = [];
        $disambigPassOutcomes = [];
        $disambigTrapFreeOutcomes = [];
        $disambigBySubtype = [];
        $priceCeilingPassOutcomes = [];
        $priceCeilingNdcg5 = [];
        $loopInitialCompliance = [];
        $loopFinalCompliance = [];
        $loopIterations = [];
        $loopReRetrieved = [];
        $loopViolationsDetectedTotal = 0;
        $loopViolationsRepairedTotal = 0;

        foreach ($allCases as $case) {
            $cid = $case['id'];
            $tRecs = $perCaseTrialRecords[$cid] ?? [];
            if (!empty($case['expect_out_of_scope'])) {
                $sp = meanVal(array_map(fn($r) => !empty($r['scope_passed']) ? 1.0 : 0.0, $tRecs));
                $scopeOutcomes[] = $sp;
                $followupOutcomes[] = $sp;
                continue;
            }
            $cP5 = meanVal(array_map(fn($r) => (float)($r['combined']['precision_at_5'] ?? 0.0), $tRecs));
            $cNdcg5 = meanVal(array_map(fn($r) => (float)($r['combined']['ndcg_at_5'] ?? 0.0), $tRecs));
            $caseP5[] = $cP5;
            $caseR5[] = meanVal(array_map(fn($r) => (float)($r['combined']['recall_at_5'] ?? 0.0), $tRecs));
            $caseR10[] = meanVal(array_map(fn($r) => (float)($r['combined']['recall_at_10'] ?? 0.0), $tRecs));
            $caseNdcg5[] = $cNdcg5;
            $caseMrr[] = meanVal(array_map(fn($r) => (float)($r['combined']['mrr'] ?? 0.0), $tRecs));

            $caseLocalP5[] = meanVal(array_map(fn($r) => (float)($r['local']['precision_at_5'] ?? 0.0), $tRecs));
            $caseLocalNdcg5[] = meanVal(array_map(fn($r) => (float)($r['local']['ndcg_at_5'] ?? 0.0), $tRecs));

            $nonEmptyWeb = array_values(array_filter($tRecs, fn($r) => (int)($r['web']['returned_count'] ?? 0) > 0));
            if ($nonEmptyWeb) {
                $caseWebP5[] = meanVal(array_map(fn($r) => (float)($r['web']['precision_at_5'] ?? 0.0), $nonEmptyWeb));
                $caseWebNdcg5[] = meanVal(array_map(fn($r) => (float)($r['web']['ndcg_at_5'] ?? 0.0), $nonEmptyWeb));
            }

            $cStrict = meanVal(array_map(fn($r) => !empty($r['constraint']['strict_pass']) ? 1.0 : 0.0, $tRecs));
            $caseConstraintRate[] = meanVal(array_map(fn($r) => (float)($r['constraint']['item_pass_rate'] ?? 0.0), $tRecs));
            $caseStrictPass[] = $cStrict;
            $caseFactRate[] = meanVal(array_map(fn($r) => (float)($r['validation']['factual_correctness_rate'] ?? 0.0), $tRecs));
            $caseFaithRate[] = meanVal(array_map(fn($r) => (float)($r['validation']['evidence_support_rate'] ?? 0.0), $tRecs));

            // Objective 2: Disambiguation & Polysemy Tracking
            $dType = trim((string)($case['disambiguation_type'] ?? ''));
            if ($dType === 'none') $dType = '';
            if ($dType !== '' || in_array($case['category'], ['disambiguation', 'ambiguous'], true)) {
                $subtypeKey = $dType !== '' ? $dType : $case['category'];
                $trapFree = meanVal(array_map(fn($r) => ($r['constraint']['disambiguation_trap_free'] ?? true) ? 1.0 : 0.0, $tRecs));
                $dPass = meanVal(array_map(
                    fn($r) => (($r['combined']['precision_at_5'] ?? 0.0) > 0.0 && !empty($r['constraint']['strict_pass']) && ($r['constraint']['disambiguation_trap_free'] ?? true)) ? 1.0 : 0.0,
                    $tRecs
                ));
                $disambigTrapFreeOutcomes[] = $trapFree;
                $disambigPassOutcomes[] = $dPass;
                $disambigBySubtype[$subtypeKey][] = $dPass;
            }

            // Objective 4A: Hard Numeric Price-Ceiling Compliance Tracking
            if (isset($case['constraints']['max_price_php']) && is_numeric($case['constraints']['max_price_php'])) {
                $pricePass = meanVal(array_map(
                    fn($r) => (($r['constraint']['numeric_price_compliant'] ?? false) && ($r['combined']['precision_at_5'] ?? 0.0) > 0.0) ? 1.0 : 0.0,
                    $tRecs
                ));
                $priceCeilingPassOutcomes[] = $pricePass;
                $priceCeilingNdcg5[] = $cNdcg5;
            }

            // Objective 4B: Autonomous Iterative Loop Telemetry
            foreach ($tRecs as $tr) {
                if (isset($tr['validation']['web_source_support_rate']) && $tr['validation']['web_source_support_rate'] !== null) {
                    $caseWebSourceSupport[] = (float)$tr['validation']['web_source_support_rate'];
                }
                $al = $tr['agentic_loop'] ?? [];
                if (!empty($al['enabled'])) {
                    $loopInitialCompliance[] = (float)($al['initial_compliance_rate'] ?? 1.0);
                    $loopFinalCompliance[] = (float)($al['final_compliance_rate'] ?? 1.0);
                    $loopIterations[] = (float)($al['iterations'] ?? 1);
                    $loopReRetrieved[] = !empty($al['re_retrieved']) ? 1.0 : 0.0;
                    $loopViolationsDetectedTotal += count($al['violations_detected'] ?? []);
                    $loopViolationsRepairedTotal += (int)($al['violations_repaired'] ?? 0);
                }
            }

            if ($case['category'] === 'multi_turn') {
                $followupOutcomes[] = meanVal(array_map(fn($r) => !empty($r['followup_passed']) ? 1.0 : 0.0, $tRecs));
            }

            if (count($tRecs) >= 2) {
                $jacc = computeRepeatRunConsistency(array_map(fn($r) => $r['top5_names'] ?? [], $tRecs));
                if ($jacc !== null) $caseConsistency[] = $jacc;
            }
        }

        foreach ($allScopeCases as $sc) {
            $tRecs = $perScopeTrialRecords[$sc['id']] ?? [];
            $scopeOutcomes[] = meanVal(array_map(fn($r) => !empty($r['scope_passed']) ? 1.0 : 0.0, $tRecs));
        }

        $ciSeed = $options['seed'];
        $ciP5 = confidenceInterval95($caseP5, $ciSeed);
        $ciR5 = confidenceInterval95($caseR5, $ciSeed + 1);
        $ciR10 = confidenceInterval95($caseR10, $ciSeed + 2);
        $ciNdcg5 = confidenceInterval95($caseNdcg5, $ciSeed + 3);
        $ciMrr = confidenceInterval95($caseMrr, $ciSeed + 4);
        $ciConstr = confidenceInterval95($caseConstraintRate, $ciSeed + 5);
        $ciFaith = confidenceInterval95($caseFaithRate, $ciSeed + 6);
        $ciFact = confidenceInterval95($caseFactRate, $ciSeed + 7);

        $scopeAccuracy = meanVal($scopeOutcomes);
        $followupRate = $followupOutcomes ? meanVal($followupOutcomes) : null;
        $repeatConsistency = $caseConsistency ? meanVal($caseConsistency) : ($config['provider'] === 'baseline' ? 1.0 : null);
        $errorRate = $totalExecutedRequests > 0 ? ($failures / $totalExecutedRequests) : 0.0;

        $subtypeMeans = [];
        foreach ($disambigBySubtype as $stKey => $stVals) {
            $subtypeMeans[$stKey] = round(meanVal($stVals), 4);
        }
        $initCompMean = $loopInitialCompliance ? meanVal($loopInitialCompliance) : null;
        $finalCompMean = $loopFinalCompliance ? meanVal($loopFinalCompliance) : null;

        $rowSummary = $config + [
            'trials'=>$effectiveTrials,
            'total_requests'=>$totalExecutedRequests,
            'failures'=>$failures,
            'ai_successes'=>$totalExecutedRequests - $failures,
            'error_rate'=>$errorRate,
            'p5'=>$ciP5,
            'r5'=>$ciR5,
            'r10'=>$ciR10,
            'ndcg5'=>$ciNdcg5,
            'mrr'=>$ciMrr,
            'local_p5_mean'=>meanVal($caseLocalP5),
            'local_ndcg5_mean'=>meanVal($caseLocalNdcg5),
            'web_p5_mean'=>$caseWebP5 ? meanVal($caseWebP5) : null,
            'web_ndcg5_mean'=>$caseWebNdcg5 ? meanVal($caseWebNdcg5) : null,
            'web_active_queries'=>count($caseWebP5),
            'constraint_item_rate'=>$ciConstr,
            'constraint_strict_pass_rate'=>meanVal($caseStrictPass),
            'disambiguation_accuracy'=>$disambigPassOutcomes ? meanVal($disambigPassOutcomes) : null,
            'disambiguation_trap_free_rate'=>$disambigTrapFreeOutcomes ? meanVal($disambigTrapFreeOutcomes) : null,
            'disambiguation_by_subtype'=>$subtypeMeans,
            'price_ceiling_compliance_rate'=>$priceCeilingPassOutcomes ? meanVal($priceCeilingPassOutcomes) : null,
            'price_ceiling_ndcg5'=>$priceCeilingNdcg5 ? meanVal($priceCeilingNdcg5) : null,
            'agentic_loop_stats'=>[
                'enabled'=>(bool)$loopInitialCompliance,
                'mean_iterations'=>$loopIterations ? round(meanVal($loopIterations), 3) : 0.0,
                'initial_compliance_rate'=>$initCompMean !== null ? round($initCompMean, 4) : null,
                'final_compliance_rate'=>$finalCompMean !== null ? round($finalCompMean, 4) : null,
                'compliance_gain'=>($initCompMean !== null && $finalCompMean !== null) ? round($finalCompMean - $initCompMean, 4) : null,
                're_retrieval_rate'=>$loopReRetrieved ? round(meanVal($loopReRetrieved), 4) : 0.0,
                'violations_detected'=>$loopViolationsDetectedTotal,
                'violations_repaired'=>$loopViolationsRepairedTotal,
            ],
            'scope_rejection_accuracy'=>$scopeAccuracy,
            'scope_violations'=>$errorTaxonomy['scope_violations'],
            'followup_resolution_rate'=>$followupRate,
            'factual_correctness'=>$ciFact,
            'faithfulness'=>$ciFaith,
            'web_source_support_rate'=>$caseWebSourceSupport ? meanVal($caseWebSourceSupport) : null,
            'repeat_consistency'=>$repeatConsistency,
            'latency'=>[
                'mean_s'=>meanVal($allLatencies),
                'median_p50_s'=>percentileVal($allLatencies, 50.0),
                'p95_s'=>percentileVal($allLatencies, 95.0),
                'p99_s'=>percentileVal($allLatencies, 99.0),
                'cold_mean_s'=>$coldLatencies ? meanVal($coldLatencies) : null,
                'cold_p50_s'=>$coldLatencies ? percentileVal($coldLatencies, 50.0) : null,
                'cold_p95_s'=>$coldLatencies ? percentileVal($coldLatencies, 95.0) : null,
                'warm_mean_s'=>$warmLatencies ? meanVal($warmLatencies) : null,
                'warm_p50_s'=>$warmLatencies ? percentileVal($warmLatencies, 50.0) : null,
                'warm_p95_s'=>$warmLatencies ? percentileVal($warmLatencies, 95.0) : null,
                'dataset_mean_ms'=>meanVal($datasetMsList),
                'playwright_mean_ms'=>meanVal($playwrightMsList),
                'ai_mean_ms'=>meanVal($aiMsList),
            ],
            'playwright_successes'=>$playwrightSuccesses,
            'playwright_requests'=>$playwrightRequests,
            'playwright_cache_hits'=>$playwrightCacheHits,
            'playwright_cache_misses'=>$playwrightCacheMisses,
            'playwright_evidence_count'=>$playwrightEvidenceCount,
            'provider_successes'=>$providerSuccesses,
            'provider_requests'=>$providerRequests,
            'mean_cost_usd'=>meanVal($costList),
            'total_cost_usd'=>array_sum($costList),
            'total_tokens'=>$totalTokensSum,
            'error_taxonomy'=>$errorTaxonomy,
            // Backwards-compatible summary keys
            'mean_precision'=>$ciP5['mean'],
            'mean_latency'=>meanVal($allLatencies),
            'median_latency'=>percentileVal($allLatencies, 50.0),
        ];

        $summary[] = $rowSummary;
        $configIds[] = $config['id'];
        $pairedScores['p5'][] = $caseP5;
        $pairedScores['ndcg5'][] = $caseNdcg5;
        $pairedScores['mrr'][] = $caseMrr;
        $pairedScores['constraint'][] = $caseConstraintRate;
        $detailedRuns[$config['id']] = [
            'cases'=>$perCaseTrialRecords,
            'scope_cases'=>$perScopeTrialRecords,
        ];
    }

    // ==================== PRINT SUMMARY TABLES ====================
    echo "\n======================================================================================================================================\n";
    echo " TABLE 1: RANKING & INTENT MATCHING METRICS (Combined Web+Local, with 95% Bootstrap CIs, n=" . count($inScopeRetrievalCases) . " retrieval cases)\n";
    echo "======================================================================================================================================\n";
    printf(
        "%-22s | %-18s | %-18s | %-10s | %-18s | %-10s | %-10s | %-10s\n",
        'Config ID', 'P@5 [95% CI]', 'NDCG@5 [95% CI]', 'MRR', 'R@5 [95% CI]', 'R@10', 'Local P@5', 'Web P@5'
    );
    echo str_repeat('-', 134) . "\n";
    foreach ($summary as $row) {
        printf(
            "%-22s | %5.1f%% [%4.1f,%4.1f] | %5.1f%% [%4.1f,%4.1f] | %10.3f | %5.1f%% [%4.1f,%4.1f] | %9.1f%% | %9.1f%% | %10s\n",
            $row['id'],
            $row['p5']['mean'] * 100, $row['p5']['ci_lower'] * 100, $row['p5']['ci_upper'] * 100,
            $row['ndcg5']['mean'] * 100, $row['ndcg5']['ci_lower'] * 100, $row['ndcg5']['ci_upper'] * 100,
            $row['mrr']['mean'],
            $row['r5']['mean'] * 100, $row['r5']['ci_lower'] * 100, $row['r5']['ci_upper'] * 100,
            $row['r10']['mean'] * 100,
            $row['local_p5_mean'] * 100,
            $row['web_p5_mean'] !== null ? sprintf('%.1f%%(%d)', $row['web_p5_mean'] * 100, $row['web_active_queries']) : 'N/A'
        );
    }

    echo "\n======================================================================================================================================\n";
    echo " TABLE 2: CONSTRAINT ADHERENCE, SCOPE SAFETY, MULTI-TURN, FAITHFULNESS, RELIABILITY & COST\n";
    echo "======================================================================================================================================\n";
    printf(
        "%-22s | %-12s | %-10s | %-11s | %-12s | %-11s | %-11s | %-10s | %-12s\n",
        'Config ID', 'Constr Item%', 'Scope Acc%', 'Follow-up%', 'Faithful%', 'Fact Corr%', 'Consistency', 'Error Rate', 'Cost/Query'
    );
    echo str_repeat('-', 134) . "\n";
    foreach ($summary as $row) {
        printf(
            "%-22s | %11.1f%% | %9.1f%% | %11s | %11.1f%% | %10.1f%% | %11s | %9.1f%% | %12s\n",
            $row['id'],
            $row['constraint_item_rate']['mean'] * 100,
            $row['scope_rejection_accuracy'] * 100,
            $row['followup_resolution_rate'] !== null ? sprintf('%.1f%%', $row['followup_resolution_rate'] * 100) : 'N/A',
            $row['faithfulness']['mean'] * 100,
            $row['factual_correctness']['mean'] * 100,
            $row['repeat_consistency'] !== null ? sprintf('%.1f%%', $row['repeat_consistency'] * 100) : 'N/A (1x)',
            $row['error_rate'] * 100,
            sprintf('$%.6f', $row['mean_cost_usd'])
        );
    }

    echo "\n======================================================================================================================================\n";
    echo " TABLE 3: LATENCY DECOMPOSITION & CACHE BEHAVIOR (End-to-End S & Server Component MS)\n";
    echo "======================================================================================================================================\n";
    printf(
        "%-22s | %-8s | %-8s | %-8s | %-8s | %-10s | %-10s | %-9s | %-9s | %-9s | %-9s\n",
        'Config ID', 'Mean(s)', 'P50(s)', 'P95(s)', 'P99(s)', 'Cold P50', 'Warm P50', 'Data(ms)', 'PW(ms)', 'AI(ms)', 'Hit/Miss'
    );
    echo str_repeat('-', 136) . "\n";
    foreach ($summary as $row) {
        $lat = $row['latency'];
        printf(
            "%-22s | %7.2fs | %7.2fs | %7.2fs | %7.2fs | %10s | %10s | %8.1f | %8.1f | %8.1f | %4d/%-4d\n",
            $row['id'],
            $lat['mean_s'],
            $lat['median_p50_s'],
            $lat['p95_s'],
            $lat['p99_s'],
            $lat['cold_p50_s'] !== null ? sprintf('%.2fs', $lat['cold_p50_s']) : '-',
            $lat['warm_p50_s'] !== null ? sprintf('%.2fs', $lat['warm_p50_s']) : '-',
            $lat['dataset_mean_ms'],
            $lat['playwright_mean_ms'],
            $lat['ai_mean_ms'],
            $row['playwright_cache_hits'],
            $row['playwright_cache_misses']
        );
    }

    echo "\n======================================================================================================================================\n";
    echo " TABLE 4: QUALITATIVE ERROR & GROUNDING AUDIT COUNTS\n";
    echo "======================================================================================================================================\n";
    printf(
        "%-22s | %-10s | %-12s | %-13s | %-14s | %-14s | %-14s | %-11s\n",
        'Config ID', 'Duplicates', 'Wrong Locs', 'Missing Src', 'Fabricated', 'Unsupported', 'Constr Fails', 'Scope Fails'
    );
    echo str_repeat('-', 128) . "\n";
    foreach ($summary as $row) {
        $et = $row['error_taxonomy'];
        printf(
            "%-22s | %10d | %12d | %13d | %14d | %14d | %14d | %11d\n",
            $row['id'],
            $et['duplicates'],
            $et['wrong_locations'],
            $et['missing_sources'],
            $et['fabricated_details'],
            $et['unsupported_claims'],
            $et['constraint_failures'],
            $et['scope_violations']
        );
    }

    echo "\n======================================================================================================================================\n";
    echo " TABLE 5: STUDY OBJECTIVES BREAKDOWN — DISAMBIGUATION, NUMERIC PRICE CEILINGS & AUTONOMOUS ITERATIVE LOOP\n";
    echo "======================================================================================================================================\n";
    printf(
        "%-22s | %-12s | %-12s | %-13s | %-13s | %-15s | %-13s | %-11s\n",
        'Config ID', 'Disambig Acc', 'Trap-Free%', 'PriceCeil Acc', 'Price NDCG@5', 'Loop Init->Fin', 'Viol Repaired', 'Re-Retr%'
    );
    echo str_repeat('-', 132) . "\n";
    foreach ($summary as $row) {
        $al = $row['agentic_loop_stats'] ?? [];
        $loopStr = !empty($al['enabled'])
            ? sprintf('%4.1f%%->%5.1f%%', ($al['initial_compliance_rate'] ?? 0) * 100, ($al['final_compliance_rate'] ?? 0) * 100)
            : 'disabled';
        printf(
            "%-22s | %12s | %12s | %13s | %13s | %15s | %13s | %11s\n",
            $row['id'],
            $row['disambiguation_accuracy'] !== null ? sprintf('%.1f%%', $row['disambiguation_accuracy'] * 100) : 'N/A',
            $row['disambiguation_trap_free_rate'] !== null ? sprintf('%.1f%%', $row['disambiguation_trap_free_rate'] * 100) : 'N/A',
            $row['price_ceiling_compliance_rate'] !== null ? sprintf('%.1f%%', $row['price_ceiling_compliance_rate'] * 100) : 'N/A',
            $row['price_ceiling_ndcg5'] !== null ? sprintf('%.1f%%', $row['price_ceiling_ndcg5'] * 100) : 'N/A',
            $loopStr,
            !empty($al['enabled']) ? sprintf('%d/%d', $al['violations_repaired'] ?? 0, $al['violations_detected'] ?? 0) : '-',
            !empty($al['enabled']) ? sprintf('%.1f%%', ($al['re_retrieval_rate'] ?? 0) * 100) : '-'
        );
        if (!empty($row['disambiguation_by_subtype'])) {
            $subParts = [];
            foreach ($row['disambiguation_by_subtype'] as $sk => $sv) {
                $subParts[] = sprintf('%s=%.0f%%', $sk, $sv * 100);
            }
            echo '  └─ Subtypes: ' . implode(', ', $subParts) . "\n";
        }
    }

    // ==================== STATISTICAL TESTS ====================
    $statisticalTests = ['friedman'=>[], 'wilcoxon_posthoc'=>[]];
    echo "\n======================================================================================================================================\n";
    echo " STATISTICAL SIGNIFICANCE ANALYSIS (Friedman Omnibus + Pairwise Wilcoxon Signed-Rank with Holm-Bonferroni)\n";
    echo "======================================================================================================================================\n";

    foreach (['ndcg5'=>'NDCG@5', 'p5'=>'Precision@5', 'mrr'=>'MRR', 'constraint'=>'Constraint Pass Rate'] as $mKey => $mLabel) {
        $fTest = friedmanTest($pairedScores[$mKey], count($inScopeRetrievalCases));
        $statisticalTests['friedman'][$mKey] = $fTest;
        if ($fTest !== null) {
            printf(
                "Friedman Omnibus [%-20s]: Q = %8.4f, df = %d, p = %.6f %s\n",
                $mLabel,
                $fTest['statistic'],
                $fTest['degrees_of_freedom'],
                $fTest['p_value'],
                $fTest['p_value'] < 0.05 ? '(SIGNIFICANT at alpha=0.05)' : '(ns)'
            );
        }
    }

    if (count($configIds) >= 2) {
        foreach (['ndcg5'=>'NDCG@5', 'p5'=>'Precision@5', 'constraint'=>'Constraint Pass Rate'] as $mKey => $mLabel) {
            $pairs = pairwiseWilcoxonPostHoc($pairedScores[$mKey], $configIds);
            $statisticalTests['wilcoxon_posthoc'][$mKey] = $pairs;
            echo "\nPairwise Wilcoxon Signed-Rank Post-Hoc ({$mLabel}, Holm-Bonferroni corrected):\n";
            printf("  %-22s vs %-22s | %-8s | %-9s | %-10s | %-10s | %-8s | %-6s\n", 'Config A', 'Config B', 'MeanDiff', 'W+/W-', 'Raw p', 'Holm p', 'Effect r', 'Sig?');
            echo '  ' . str_repeat('-', 114) . "\n";
            foreach ($pairs as $pair) {
                printf(
                    "  %-22s vs %-22s | %+7.2f%% | %4.0f/%-4.0f | %10.5f | %10.5f | %+8.3f | %-6s\n",
                    $pair['config_a'],
                    $pair['config_b'],
                    $pair['mean_diff'] * 100,
                    $pair['w_plus'],
                    $pair['w_minus'],
                    $pair['p_value'],
                    $pair['p_holm'],
                    $pair['effect_size_r'],
                    $pair['significant_005'] ? 'YES*' : 'no'
                );
            }
        }
    } else {
        echo "Post-hoc pairwise tests skipped: pass at least 2 configurations (e.g., --providers=baseline,hybrid or --research).\n";
    }

    // Best configuration selection (prioritizing NDCG@5, Constraint Pass Rate, Scope Safety, Low Error Rate, Latency)
    $validSummary = array_values(array_filter($summary, fn($row) => $row['failures'] < $row['total_requests']));
    usort($validSummary, fn($a, $b) => ($b['ndcg5']['mean'] <=> $a['ndcg5']['mean'])
        ?: ($b['p5']['mean'] <=> $a['p5']['mean'])
        ?: ($b['constraint_item_rate']['mean'] <=> $a['constraint_item_rate']['mean'])
        ?: ($b['scope_rejection_accuracy'] <=> $a['scope_rejection_accuracy'])
        ?: ($a['error_rate'] <=> $b['error_rate'])
        ?: ($a['latency']['median_p50_s'] <=> $b['latency']['median_p50_s']));

    echo "\n";
    if ($validSummary) {
        echo 'Best observed configuration: ' . $validSummary[0]['id']
            . sprintf(
                " (NDCG@5=%.1f%%, P@5=%.1f%%, Constraint=%.1f%%, Scope=%.1f%%, P50=%.2fs).\n",
                $validSummary[0]['ndcg5']['mean'] * 100,
                $validSummary[0]['p5']['mean'] * 100,
                $validSummary[0]['constraint_item_rate']['mean'] * 100,
                $validSummary[0]['scope_rejection_accuracy'] * 100,
                $validSummary[0]['latency']['median_p50_s']
            );
    } else {
        echo "Best configuration not selected: every configuration failed all requests.\n";
    }

    // ==================== SAVE REPRODUCIBILITY ARTIFACTS ====================
    if (!$options['no_save']) {
        $resultsDir = __DIR__ . DIRECTORY_SEPARATOR . 'results';
        if (!is_dir($resultsDir)) @mkdir($resultsDir, 0775, true);
        $stamp = gmdate('Ymd_His');
        $jsonOutPath = $options['output'] !== ''
            ? $options['output']
            : ($resultsDir . DIRECTORY_SEPARATOR . "eval_{$stamp}.json");

        $artifact = [
            'reproducibility_manifest'=>[
                'evaluator_version'=>'3.0.0',
                'started_at_utc'=>$runStartedIso,
                'completed_at_utc'=>gmdate('c'),
                'php_version'=>PHP_VERSION,
                'os'=>PHP_OS_FAMILY,
                'api_url'=>$options['api_url'],
                'ground_truth_path'=>basename($groundTruthPath),
                'ground_truth_version'=>$validatedGt['metadata']['version'],
                'ground_truth_sha256'=>$groundTruthHash,
                'dataset_files'=>$datasetHashes,
                'split'=>$options['split'],
                'in_scope_case_count'=>count($allCases),
                'scope_case_count'=>count($allScopeCases),
                'trials'=>$options['trials'],
                'seed'=>$options['seed'],
                'cache_policy'=>$options['cache_policy'],
                'live_turns'=>$options['live_turns'],
            ],
            'annotator_agreement'=>$agreementReport,
            'usability_study'=>aggregateUsabilityStudy(),
            'summary'=>$summary,
            'statistical_tests'=>$statisticalTests,
            'detailed_runs'=>$detailedRuns,
        ];

        $encodedArtifact = json_encode($artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        @file_put_contents($jsonOutPath, $encodedArtifact, LOCK_EX);
        @file_put_contents($resultsDir . DIRECTORY_SEPARATOR . 'latest_run.json', $encodedArtifact, LOCK_EX);

        // Also write CSV summary for thesis tables / spreadsheets
        $csvPath = preg_replace('/\.json$/i', '', $jsonOutPath) . '_summary.csv';
        $fp = @fopen($csvPath, 'wb');
        if ($fp) {
            fputcsv($fp, [
                'config_id', 'provider', 'agentic', 'temperature', 'prompt_style', 'trials',
                'p5_mean', 'p5_ci_low', 'p5_ci_high',
                'r5_mean', 'r10_mean',
                'ndcg5_mean', 'ndcg5_ci_low', 'ndcg5_ci_high',
                'mrr_mean', 'local_p5_mean', 'web_p5_mean',
                'constraint_item_pass_rate', 'constraint_strict_pass_rate',
                'scope_rejection_accuracy', 'followup_resolution_rate',
                'factual_correctness_rate', 'faithfulness_rate', 'web_source_support_rate',
                'repeat_consistency', 'error_rate', 'mean_cost_usd',
                'latency_mean_s', 'latency_p50_s', 'latency_p95_s', 'latency_p99_s',
                'cold_p50_s', 'warm_p50_s',
                'dataset_mean_ms', 'playwright_mean_ms', 'ai_mean_ms',
                'duplicates', 'wrong_locations', 'missing_sources', 'fabricated_details', 'unsupported_claims'
            ]);
            foreach ($summary as $r) {
                fputcsv($fp, [
                    $r['id'], $r['provider'], ($r['agentic'] ?? true) ? '1' : '0', $r['temperature'], $r['prompt_style'], $r['trials'],
                    round($r['p5']['mean'], 4), round($r['p5']['ci_lower'], 4), round($r['p5']['ci_upper'], 4),
                    round($r['r5']['mean'], 4), round($r['r10']['mean'], 4),
                    round($r['ndcg5']['mean'], 4), round($r['ndcg5']['ci_lower'], 4), round($r['ndcg5']['ci_upper'], 4),
                    round($r['mrr']['mean'], 4), round($r['local_p5_mean'], 4), $r['web_p5_mean'] !== null ? round($r['web_p5_mean'], 4) : '',
                    round($r['constraint_item_rate']['mean'], 4), round($r['constraint_strict_pass_rate'], 4),
                    round($r['scope_rejection_accuracy'], 4), $r['followup_resolution_rate'] !== null ? round($r['followup_resolution_rate'], 4) : '',
                    round($r['factual_correctness']['mean'], 4), round($r['faithfulness']['mean'], 4), $r['web_source_support_rate'] !== null ? round($r['web_source_support_rate'], 4) : '',
                    $r['repeat_consistency'] !== null ? round($r['repeat_consistency'], 4) : '', round($r['error_rate'], 4), round($r['mean_cost_usd'], 8),
                    round($r['latency']['mean_s'], 4), round($r['latency']['median_p50_s'], 4), round($r['latency']['p95_s'], 4), round($r['latency']['p99_s'], 4),
                    $r['latency']['cold_p50_s'] !== null ? round($r['latency']['cold_p50_s'], 4) : '',
                    $r['latency']['warm_p50_s'] !== null ? round($r['latency']['warm_p50_s'], 4) : '',
                    round($r['latency']['dataset_mean_ms'], 2), round($r['latency']['playwright_mean_ms'], 2), round($r['latency']['ai_mean_ms'], 2),
                    $r['error_taxonomy']['duplicates'], $r['error_taxonomy']['wrong_locations'], $r['error_taxonomy']['missing_sources'],
                    $r['error_taxonomy']['fabricated_details'], $r['error_taxonomy']['unsupported_claims']
                ]);
            }
            fclose($fp);
        }
        echo "Saved reproducible evaluation report: {$jsonOutPath}\n";
        echo "Saved CSV thesis summary table      : {$csvPath}\n";
    }

    if ($options['require_ai'] && $anyAiFailure) {
        echo "Evaluation completed with AI failures. Check the PHP server configuration and error log; failed requests were assigned 0.\n";
        exit(2);
    }
} catch (JsonException $exception) {
    fail('Could not decode ground-truth JSON: ' . $exception->getMessage());
} catch (Throwable $exception) {
    fail($exception->getMessage());
}
