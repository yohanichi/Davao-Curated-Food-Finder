<?php
declare(strict_types=1);

/**
 * Controlled Temporal & Counterfactual Data Volatility Experiment Runner (Objective 1).
 *
 * Compares:
 *   1. STATIC-BASELINE        : Static 2025 CSV retrieval (no web reconciliation)
 *   2. STALE-CACHE-12H        : Agentic pipeline reading an outdated (>12h) pre-change cached web snapshot
 *   3. ABLATION-NO-LOOP-FRESH : Single-pass retrieval + fresh web snapshot WITHOUT Pass 2 autonomous critique/repair
 *   4. AGENTIC-LOOP-FRESH     : Full 2-pass Autonomous Iterative Critique-and-Refinement Loop + fresh web snapshot
 *
 * Usage:
 *   php evaluation/run_volatility_experiment.php --local-cli
 *   php evaluation/run_volatility_experiment.php --api-url=http://127.0.0.1:8080/api.php
 */

$appRoot = dirname(__DIR__);
require_once $appRoot . '/vendor/autoload.php';
require_once $appRoot . '/src/search.php';
require_once __DIR__ . '/statistics.php';
require_once __DIR__ . '/metrics.php';

function failVol(string $msg): never {
    fwrite(STDERR, "Error: {$msg}\n");
    exit(1);
}

$apiUrl = 'http://127.0.0.1:8080/api.php';
$localCli = false;
$noSave = false;
$extraLiveProvider = '';
$casesPath = __DIR__ . '/volatility_cases.json';

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--local-cli') {
        $localCli = true;
        $apiUrl = 'local';
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
    } elseif (str_starts_with($arg, '--cases=')) {
        $casesPath = substr($arg, strlen('--cases='));
    } else {
        failVol("Unknown option {$arg}. Supported: --local-cli, --api-url=URL, --live-provider=deepseek|gpt-4o-mini|hybrid, --cases=PATH, --no-save");
    }
}

if (!is_file($casesPath)) {
    failVol("Volatility benchmark file not found: {$casesPath}");
}

$spec = json_decode((string)file_get_contents($casesPath), true, 512, JSON_THROW_ON_ERROR);
$scenarios = is_array($spec['scenarios'] ?? null) ? $spec['scenarios'] : [];
if (!$scenarios) {
    failVol('No volatility scenarios found in volatility_cases.json.');
}

function sendVolatilityApiRequest(string $apiUrl, string $query, string $provider, bool $agentic, ?array $evidenceOverride): array {
    $evalBlock = [
        'provider'=>$provider,
        'temperature'=>0.0,
        'promptStyle'=>'strict_grounding',
        'agentic'=>$agentic,
        'cachePolicy'=>'none',
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
        $runnerCode = '$_SERVER["REQUEST_METHOD"]="POST"; $_SERVER["REMOTE_ADDR"]="127.0.0.1"; chdir(' . var_export(dirname($apiFile), true) . '); require ' . var_export($apiFile, true) . ';';
        $started = microtime(true);
        $pipes = [];
        $proc = proc_open([$phpBin, '-r', $runnerCode], [0=>['pipe', 'r'], 1=>['pipe', 'w'], 2=>['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            return ['latency'=>0.0, 'failure'=>'Could not start local PHP process', 'data'=>null];
        }
        fwrite($pipes[0], $body);
        fclose($pipes[0]);
        $raw = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $latency = microtime(true) - $started;
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($decoded) || $exit !== 0) {
            return ['latency'=>$latency, 'failure'=>'Local worker error: ' . trim((string)$stderr), 'data'=>$decoded];
        }
        return ['latency'=>$latency, 'failure'=>null, 'data'=>$decoded];
    }

    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST=>true,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
        CURLOPT_POSTFIELDS=>$body,
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_TIMEOUT=>90,
    ]);
    $started = microtime(true);
    $raw = curl_exec($ch);
    $latency = microtime(true) - $started;
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($decoded) || $http < 200 || $http >= 300) {
        return ['latency'=>$latency, 'failure'=>$err !== '' ? $err : "HTTP {$http}", 'data'=>$decoded];
    }
    return ['latency'=>$latency, 'failure'=>null, 'data'=>$decoded];
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

if ($extraLiveProvider !== '' && in_array($extraLiveProvider, ['deepseek', 'gpt-4o-mini', 'hybrid'], true)) {
    $conditions[] = [
        'id'=>strtoupper($extraLiveProvider) . '-LOOP-FRESH',
        'provider'=>$extraLiveProvider,
        'agentic'=>true,
        'evidence_mode'=>'fresh',
        'description'=>"Live LLM ({$extraLiveProvider}) + 2-pass Autonomous Iterative Loop with fresh web evidence",
    ];
}

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

