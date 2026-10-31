<?php
declare(strict_types=1);

$appRoot = dirname(__DIR__);
require_once $appRoot . '/vendor/autoload.php';
require_once $appRoot . '/src/search.php';
require_once __DIR__ . '/statistics.php';
require_once __DIR__ . '/metrics.php';
require_once __DIR__ . '/usability.php';

function esc(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Execute an API evaluation request safely even on single-threaded Windows `php -S` built-in server.
 */
function dashboardApiRequest(string $apiUrl, array $payloadArray): array {
    $jsonPayload = json_encode($payloadArray, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    $serverPort = (int)($_SERVER['SERVER_PORT'] ?? 0);
    $targetPort = (int)(parse_url($apiUrl, PHP_URL_PORT) ?: 80);

    // Avoid self-deadlock on Windows single-worker `php -S` when dashboard.php and api.php share the same port
    if (PHP_SAPI === 'cli-server' && $serverPort > 0 && $serverPort === $targetPort) {
        $apiFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'api.php';
        $phpBin = PHP_BINARY ?: 'php';
        $runnerCode = '$_SERVER["REQUEST_METHOD"]="POST"; $_SERVER["REMOTE_ADDR"]="127.0.0.1"; chdir(' . var_export(dirname($apiFile), true) . '); require ' . var_export($apiFile, true) . ';';
        $started = microtime(true);
        $pipes = [];
        $proc = proc_open([$phpBin, '-r', $runnerCode], [0=>['pipe', 'r'], 1=>['pipe', 'w'], 2=>['pipe', 'w']], $pipes);
        if (is_resource($proc)) {
            fwrite($pipes[0], $jsonPayload);
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($proc);
            $latency = microtime(true) - $started;
            $decoded = is_string($stdout) && $stdout !== '' ? json_decode($stdout, true) : null;
            return [
                'latency'=>$latency,
                'http'=>$exitCode === 0 && is_array($decoded) && !isset($decoded['error']) ? 200 : 500,
                'data'=>is_array($decoded) ? $decoded : null,
                'error'=>is_array($decoded) ? '' : trim((string)$stderr ?: 'Local CLI API execution failed'),
            ];
        }
    }

    $started = microtime(true);
    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST=>true,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
        CURLOPT_POSTFIELDS=>$jsonPayload,
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CONNECTTIMEOUT=>5,
        CURLOPT_TIMEOUT=>90,
    ]);
    $raw = curl_exec($ch);
    $latency = microtime(true) - $started;
    $httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    $data = $raw !== false ? json_decode($raw, true) : null;
    return [
        'latency'=>$latency,
        'http'=>$httpStatus,
        'data'=>is_array($data) ? $data : null,
        'error'=>is_array($data) ? '' : ($curlError !== '' ? $curlError : 'Invalid or unavailable API response'),
    ];
}

$groundTruthPath = __DIR__ . '/ground_truth.json';
$gtRaw = is_file($groundTruthPath) ? json_decode((string)file_get_contents($groundTruthPath), true) : null;
$validatedGt = is_array($gtRaw) ? validateAndNormalizeGroundTruth($gtRaw) : ['cases'=>[], 'scope_cases'=>[], 'annotator_agreement_samples'=>[], 'metadata'=>['version'=>'2.0']];

// Load latest CLI full run & volatility reports if available
$latestRunPath = __DIR__ . '/results/latest_run.json';
$latestCliReport = is_file($latestRunPath) ? json_decode((string)file_get_contents($latestRunPath), true) : null;
$latestVolPath = __DIR__ . '/results/latest_volatility.json';
$latestVolReport = is_file($latestVolPath) ? json_decode((string)file_get_contents($latestVolPath), true) : null;
$usabilitySummary = aggregateUsabilityStudy();

$selectedSplit = strtolower(trim((string)($_POST['split'] ?? 'test')));
if (!in_array($selectedSplit, ['all', 'train', 'dev', 'test'], true)) $selectedSplit = 'test';
$maxQueries = max(3, min(72, (int)($_POST['max_queries'] ?? 10)));
$cachePolicy = strtolower(trim((string)($_POST['cache_policy'] ?? 'default')));
if (!in_array($cachePolicy, ['default', 'cold', 'warm', 'none'], true)) $cachePolicy = 'default';

$rows = [];
$summaries = [];
$posthocPairs = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    set_time_limit(300);
    $providers = is_array($_POST['providers'] ?? null) ? $_POST['providers'] : ['baseline', 'agentic-local'];
    $providers = array_values(array_intersect(['baseline', 'ablation-no-loop', 'agentic-local', 'non-agentic', 'deepseek', 'gpt-4o-mini', 'hybrid'], array_map('strval', $providers)));
    if (!$providers) $providers = ['baseline'];

    $cases = $validatedGt['cases'];
    $scopeCases = $validatedGt['scope_cases'];
    if ($selectedSplit !== 'all') {
        $cases = array_values(array_filter($cases, fn($c) => $c['split'] === $selectedSplit));
        $scopeCases = array_values(array_filter($scopeCases, fn($sc) => $sc['split'] === $selectedSplit));
    }
    $cases = array_slice($cases, 0, $maxQueries);
    $scopeCases = array_slice($scopeCases, 0, max(1, intdiv($maxQueries, 3)));

    $dataDir = $appRoot . DIRECTORY_SEPARATOR . 'data';
    $datasetIndex = [];
    $placesCachePath = $dataDir . DIRECTORY_SEPARATOR . '.places-cache.json';
    $loadedPlaces = [];
    if (is_file($placesCachePath)) {
        $cachedObj = json_decode((string)file_get_contents($placesCachePath), true);
        if (is_array($cachedObj['places'] ?? null)) $loadedPlaces = $cachedObj['places'];
    }
    if (!$loadedPlaces) $loadedPlaces = loadDavaoPlaces($dataDir);
    foreach ($loadedPlaces as $lp) {
        $norm = normalizePlaceName((string)($lp['name'] ?? ''));
        if ($norm !== '') $datasetIndex[$norm] = $lp;
    }

    $port = (int)($_SERVER['SERVER_PORT'] ?? 8080) ?: 8080;
    $apiUrl = "http://127.0.0.1:{$port}/api.php";

    $ndcgByProvider = [];
    $providerLabels = [];

    foreach ($providers as $provider) {
        $providerRows = [];
        $pNdcgScores = [];
        $p5Scores = [];
        $r5Scores = [];
        $r10Scores = [];
        $mrrScores = [];
        $constrScores = [];
        $faithScores = [];
        $latencies = [];
        $dataMsList = [];
        $pwMsList = [];
        $aiMsList = [];
        $costs = [];

        foreach ($cases as $case) {
            $history = [];
            $shownNames = [];
            $turns = is_array($case['turns'] ?? null) ? $case['turns'] : [];
            if ($turns) {
                for ($i = 0; $i < count($turns) - 1; $i++) {
                    if (!empty($turns[$i]['query'])) $history[] = ['role'=>'user', 'content'=>(string)$turns[$i]['query']];
                    if (!empty($turns[$i]['assistant_summary'])) $history[] = ['role'=>'assistant', 'content'=>(string)$turns[$i]['assistant_summary']];
                    foreach (($turns[$i]['shown_names'] ?? []) as $sn) $shownNames[] = (string)$sn;
                }
            }

            $resp = dashboardApiRequest($apiUrl, [
                'query'=>$case['query'],
                'history'=>$history,
                'shownNames'=>$shownNames,
                'evaluation'=>[
                    'provider'=>$provider,
                    'temperature'=>0.2,
                    'promptStyle'=>'strict_grounding',
                    'agentic'=>!in_array($provider, ['baseline', 'non-agentic'], true),
                    'cachePolicy'=>$cachePolicy,
                    'seed'=>42,
                ],
            ]);

            $data = $resp['data'];
            $latency = (float)$resp['latency'];
            $latencies[] = $latency;
            $valid = is_array($data) && ($data['assistantStatus'] ?? '') === 'ready';

            $metrics = is_array($data['metrics'] ?? null) ? $data['metrics'] : [];
            $timings = is_array($metrics['timings_ms'] ?? null) ? $metrics['timings_ms'] : [];
            $playwright = is_array($metrics['playwright'] ?? null) ? $metrics['playwright'] : [];
            $usage = is_array($metrics['usage'] ?? null) ? $metrics['usage'] : [];

            $dataMsList[] = (float)($timings['dataset_total_ms'] ?? 0);
            $pwMsList[] = (float)($timings['playwright_ms'] ?? ($playwright['latency_ms'] ?? 0));
            $aiMsList[] = (float)($timings['ai_inference_ms'] ?? 0);
            $costs[] = (float)($usage['estimated_cost_usd'] ?? 0.0);

            $localResults = $valid && is_array($data['results'] ?? null) ? $data['results'] : [];
            $webResults = $valid && is_array($data['webResults'] ?? null) ? $data['webResults'] : [];
            $combined = mergeRecommendations($localResults, $webResults, true);

            if (!empty($case['expect_out_of_scope'])) {
                $scopePass = $valid && ($data['outOfScope'] ?? false) === true && !$localResults && !$webResults;
                $row = [
                    'provider'=>$provider,
                    'id'=>$case['id'],
                    'category'=>$case['category'],
                    'query'=>$case['query'],
                    'ai_status'=>$valid ? 'READY' : strtoupper((string)($data['assistantStatus'] ?? 'ERROR')),
                    'playwright_success'=>(bool)($playwright['success'] ?? false),
                    'playwright_cache'=>$playwright['cache'] ?? 'none',
                    'p5'=>null,
                    'ndcg5'=>null,
                    'mrr'=>null,
                    'constraint'=>$scopePass ? 'PASS' : 'FAIL',
                    'faithfulness'=>1.0,
                    'latency'=>$latency,
                    'http'=>$resp['http'],
                ];
                $rows[] = $row;
                $providerRows[] = $row;
                continue;
            }

            $rank = $valid
                ? computeRankingMetrics($combined, $case['relevance_grades'], $case['base_relevance_grades'], $case['constraints'], true)
                : ['precision_at_5'=>0.0, 'recall_at_5'=>0.0, 'recall_at_10'=>0.0, 'ndcg_at_5'=>0.0, 'mrr'=>0.0];
            $cEval = $valid
                ? evaluateConstraintSatisfaction($combined, $case['constraints'], $datasetIndex)
                : ['strict_pass'=>false, 'item_pass_rate'=>0.0];
            $val = $valid
                ? validateRecommendationQuality($data, $case, $datasetIndex)
                : ['evidence_support_rate'=>0.0];

            $p5Scores[] = $rank['precision_at_5'];
            $r5Scores[] = $rank['recall_at_5'];
            $r10Scores[] = $rank['recall_at_10'];
            $pNdcgScores[] = $rank['ndcg_at_5'];
            $mrrScores[] = $rank['mrr'];
            $constrScores[] = $cEval['item_pass_rate'];
            $faithScores[] = $val['evidence_support_rate'];

            $row = [
                'provider'=>$provider,
                'id'=>$case['id'],
                'category'=>$case['category'],
                'query'=>$case['query'],
                'ai_status'=>$valid ? 'READY' : strtoupper((string)($data['assistantStatus'] ?? 'ERROR')),
                'playwright_success'=>(bool)($playwright['success'] ?? false),
                'playwright_cache'=>$playwright['cache'] ?? 'none',
                'p5'=>$rank['precision_at_5'],
                'ndcg5'=>$rank['ndcg_at_5'],
                'mrr'=>$rank['mrr'],
                'constraint'=>$cEval['strict_pass'] ? 'PASS' : 'FAIL',
                'constraint_rate'=>$cEval['item_pass_rate'],
                'faithfulness'=>$val['evidence_support_rate'],
                'latency'=>$latency,
                'http'=>$resp['http'],
            ];
            $rows[] = $row;
            $providerRows[] = $row;
        }

        foreach ($scopeCases as $scopeCase) {
            $resp = dashboardApiRequest($apiUrl, [
                'query'=>$scopeCase['query'],
                'history'=>[],
                'shownNames'=>[],
                'evaluation'=>[
                    'provider'=>$provider,
                    'temperature'=>0.2,
                    'promptStyle'=>'strict_grounding',
                    'agentic'=>!in_array($provider, ['baseline', 'non-agentic'], true),
                    'cachePolicy'=>$cachePolicy,
                    'seed'=>42,
                ],
            ]);
            $data = $resp['data'];
            $latency = (float)$resp['latency'];
            $latencies[] = $latency;
            $valid = is_array($data) && ($data['assistantStatus'] ?? '') === 'ready';
            $scopePassed = $valid && ($data['outOfScope'] ?? false) === true && !($data['results'] ?? []) && !($data['webResults'] ?? []);
            $playwright = is_array($data['metrics']['playwright'] ?? null) ? $data['metrics']['playwright'] : [];

            $row = [
                'provider'=>$provider,
                'id'=>$scopeCase['id'],
                'category'=>'out_of_scope',
                'query'=>$scopeCase['query'],
                'ai_status'=>$valid ? 'READY' : strtoupper((string)($data['assistantStatus'] ?? 'ERROR')),
                'playwright_success'=>(bool)($playwright['success'] ?? false),
                'playwright_cache'=>$playwright['cache'] ?? 'none',
                'p5'=>null,
                'ndcg5'=>null,
                'mrr'=>null,
                'constraint'=>$scopePassed ? 'PASS' : 'FAIL',
                'constraint_rate'=>$scopePassed ? 1.0 : 0.0,
                'faithfulness'=>1.0,
                'latency'=>$latency,
                'http'=>$resp['http'],
            ];
            $rows[] = $row;
            $providerRows[] = $row;
        }

        $ndcgByProvider[] = $pNdcgScores;
        $providerLabels[] = $provider;

        $scopeCheckRows = array_filter($providerRows, fn($r) => $r['p5'] === null);
        $scopePasses = count(array_filter($scopeCheckRows, fn($r) => $r['constraint'] === 'PASS'));

        $summaries[] = [
            'provider'=>$provider,
            'p5'=>confidenceInterval95($p5Scores, 42),
            'r5'=>meanVal($r5Scores),
            'r10'=>meanVal($r10Scores),
            'ndcg5'=>confidenceInterval95($pNdcgScores, 43),
            'mrr'=>meanVal($mrrScores),
            'constraint_rate'=>meanVal($constrScores),
            'faithfulness'=>meanVal($faithScores),
            'latency_mean'=>meanVal($latencies),
            'latency_p50'=>percentileVal($latencies, 50.0),
            'latency_p95'=>percentileVal($latencies, 95.0),
            'data_ms'=>meanVal($dataMsList),
            'pw_ms'=>meanVal($pwMsList),
            'ai_ms'=>meanVal($aiMsList),
            'cost_mean'=>meanVal($costs),
            'ai_successes'=>count(array_filter($providerRows, fn($r) => $r['ai_status'] === 'READY')),
            'scope_passes'=>$scopePasses,
            'scope_requests'=>count($scopeCheckRows),
            'requests'=>count($providerRows),
        ];
    }

    if (count($providerLabels) >= 2) {
        $posthocPairs = pairwiseWilcoxonPostHoc($ndcgByProvider, $providerLabels);
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Davao Food Finder - Evaluation & Research Metrics Dashboard</title>
<style>
body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;margin:0;background:#f5f7f4;color:#20251f}
.wrap{max-width:1360px;margin:auto;padding:28px}
.card{background:#fff;border:1px solid #e2e7df;border-radius:14px;padding:18px;margin-bottom:18px;box-shadow:0 8px 24px #263d2810}
button,.btn-link{display:inline-block;border:0;border-radius:9px;background:#185d43;color:#fff;padding:10px 15px;font-weight:700;cursor:pointer;text-decoration:none;font-size:13px}
.btn-secondary{background:#edf4ed;color:#185d43;border:1px solid #c6dacb}
table{border-collapse:collapse;width:100%;font-size:12.5px}th,td{border-bottom:1px solid #e5e9e2;padding:9px 8px;text-align:left;vertical-align:top}
th{background:#edf3ec;color:#315f43;white-space:nowrap}.pass{color:#18743c;font-weight:800}.fail{color:#b42318;font-weight:800}.warn{color:#9a6700;font-weight:800}
.table-wrap{overflow-x:auto}label{margin-right:16px;font-size:13.5px;display:inline-flex;align-items:center;gap:5px}.note{color:#5e685f;font-size:13px;line-height:1.5}
.controls-row{display:flex;flex-wrap:wrap;gap:14px;align-items:center;margin-top:12px}
select,input[type=number]{padding:7px 10px;border:1px solid #ccd5c9;border-radius:8px;font:inherit;font-size:13px}
.badge{display:inline-block;padding:3px 8px;border-radius:99px;font-size:11px;font-weight:700;background:#edf4ed;color:#255b3e}
.topbar{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:12px}
</style>
</head>
<body>
<main class="wrap">
<div class="topbar">
  <div>
    <h1 style="margin:0 0 4px">Agentic Intent & Retrieval Evaluation Dashboard</h1>
    <p class="note" style="margin:0">Benchmark v<?=esc($validatedGt['metadata']['version'])?> · <?=count($validatedGt['cases'])?> in-scope/multi-turn cases + <?=count($validatedGt['scope_cases'])?> scope checks across train/dev/test splits.</p>
  </div>
  <div style="display:flex;gap:10px;flex-wrap:wrap">
    <a class="btn-link btn-secondary" href="../Main.html">← Back to App</a>
    <a class="btn-link btn-secondary" href="usability.php">Usability & SUS Portal ↗</a>
    <a class="btn-link" href="annotate.php">Blind Human Annotation & Kappa Tool ↗</a>
  </div>
</div>

<form method="post" class="card">
  <strong>Select Systems to Compare:</strong>
  <div class="controls-row">
    <label><input type="checkbox" name="providers[]" value="baseline" checked> Deterministic Baseline (Local)</label>
    <label><input type="checkbox" name="providers[]" value="ablation-no-loop" checked> Single-Pass Ablation (No Loop)</label>
    <label><input type="checkbox" name="providers[]" value="agentic-local" checked> Agentic Iterative Loop (Local Grounded)</label>
    <label><input type="checkbox" name="providers[]" value="non-agentic"> Non-Agentic LLM</label>
    <label><input type="checkbox" name="providers[]" value="deepseek"> DeepSeek R1</label>
    <label><input type="checkbox" name="providers[]" value="gpt-4o-mini"> GPT-4o mini</label>
    <label><input type="checkbox" name="providers[]" value="hybrid"> Hybrid Agentic Pipeline</label>
  </div>
  <div class="controls-row">
    <label>Split:
      <select name="split">
        <?php foreach (['test'=>'Test (Held-out)', 'dev'=>'Dev', 'train'=>'Train', 'all'=>'All Splits'] as $k => $lbl): ?>
          <option value="<?=esc($k)?>" <?= $selectedSplit === $k ? 'selected' : '' ?>><?=esc($lbl)?></option>
        <?php endforeach ?>
      </select>
    </label>
    <label>Max Queries: <input type="number" name="max_queries" min="3" max="72" value="<?=$maxQueries?>" style="width:70px"></label>
    <label>Playwright Cache:
      <select name="cache_policy">
        <option value="default" <?= $cachePolicy === 'default' ? 'selected' : '' ?>>Default (12h TTL)</option>
        <option value="warm" <?= $cachePolicy === 'warm' ? 'selected' : '' ?>>Warm Cache Only (Fast)</option>
        <option value="cold" <?= $cachePolicy === 'cold' ? 'selected' : '' ?>>Cold Cache (Force Crawl)</option>
        <option value="none" <?= $cachePolicy === 'none' ? 'selected' : '' ?>>Disabled (Local Only)</option>
      </select>
    </label>
    <button type="submit">Run Live Evaluation</button>
  </div>
</form>

<?php if ($summaries): ?>
<section class="card">
  <h2>Live Evaluation Summary</h2>
  <div class="table-wrap"><table>
    <thead><tr>
      <th>System</th><th>P@5 [95% CI]</th><th>NDCG@5 [95% CI]</th><th>MRR</th><th>R@5</th><th>R@10</th>
      <th>Constraint %</th><th>Faithful %</th><th>Scope Safety</th><th>P50 / P95 (s)</th><th>Data / PW / AI (ms)</th><th>Cost/Query</th><th>Ready</th>
    </tr></thead>
    <tbody>
    <?php foreach ($summaries as $s): ?>
      <tr>
        <td><strong><?=esc($s['provider'])?></strong></td>
        <td><?=number_format($s['p5']['mean']*100,1)?>% <span class="note">[<?=number_format($s['p5']['ci_lower']*100,1)?>, <?=number_format($s['p5']['ci_upper']*100,1)?>]</span></td>
        <td><?=number_format($s['ndcg5']['mean']*100,1)?>% <span class="note">[<?=number_format($s['ndcg5']['ci_lower']*100,1)?>, <?=number_format($s['ndcg5']['ci_upper']*100,1)?>]</span></td>
        <td><?=number_format($s['mrr'],3)?></td>
        <td><?=number_format($s['r5']*100,1)?>%</td>
        <td><?=number_format($s['r10']*100,1)?>%</td>
        <td><?=number_format($s['constraint_rate']*100,1)?>%</td>
        <td><?=number_format($s['faithfulness']*100,1)?>%</td>
        <td><?=$s['scope_passes'].'/'.$s['scope_requests']?></td>
        <td><?=number_format($s['latency_p50'],2)?>s / <?=number_format($s['latency_p95'],2)?>s</td>
        <td><?=number_format($s['data_ms'],0)?> / <?=number_format($s['pw_ms'],0)?> / <?=number_format($s['ai_ms'],0)?></td>
        <td>$<?=number_format($s['cost_mean'],6)?></td>
        <td><?=$s['ai_successes'].'/'.$s['requests']?></td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table></div>
</section>

<?php if ($posthocPairs): ?>
<section class="card">
  <h2>Pairwise Wilcoxon Signed-Rank Post-Hoc (NDCG@5, Holm-Bonferroni Adjusted)</h2>
  <div class="table-wrap"><table>
    <thead><tr><th>System A</th><th>System B</th><th>Mean Diff</th><th>W+ / W-</th><th>Raw p</th><th>Holm p</th><th>Effect Size r</th><th>Significant (p&lt;0.05)</th></tr></thead>
    <tbody>
    <?php foreach ($posthocPairs as $p): ?>
      <tr>
        <td><?=esc($p['config_a'])?></td>
        <td><?=esc($p['config_b'])?></td>
        <td><?=sprintf('%+.2f%%', $p['mean_diff']*100)?></td>
        <td><?=number_format($p['w_plus'],1).' / '.number_format($p['w_minus'],1)?></td>
        <td><?=number_format($p['p_value'],5)?></td>
        <td><?=number_format($p['p_holm'],5)?></td>
        <td><?=sprintf('%+.3f', $p['effect_size_r'])?></td>
        <td class="<?=$p['significant_005']?'pass':'note'?>"><?=$p['significant_005']?'YES':'no'?></td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table></div>
</section>
<?php endif ?>

<section class="card">
  <h2>Per-Query Run Details</h2>
  <div class="table-wrap"><table>
    <thead><tr><th>System</th><th>Case</th><th>Category</th><th>Query</th><th>Status</th><th>PW Cache</th><th>P@5</th><th>NDCG@5</th><th>MRR</th><th>Constraint</th><th>Faithfulness</th><th>Latency</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?=esc($r['provider'])?></td>
        <td><strong><?=esc($r['id'])?></strong></td>
        <td><span class="badge"><?=esc($r['category'])?></span></td>
        <td><?=esc($r['query'])?></td>
        <td class="<?=$r['ai_status']==='READY'?'pass':'fail'?>"><?=esc($r['ai_status'])?></td>
        <td><?=esc((string)$r['playwright_cache'])?></td>
        <td><?=$r['p5']===null?'N/A':number_format($r['p5']*100,1).'%'?></td>
        <td><?=$r['ndcg5']===null?'N/A':number_format($r['ndcg5']*100,1).'%'?></td>
        <td><?=$r['mrr']===null?'N/A':number_format($r['mrr'],2)?></td>
        <td class="<?=$r['constraint']==='PASS'?'pass':'fail'?>"><?=esc($r['constraint'])?><?=isset($r['constraint_rate']) && $r['p5']!==null ? ' ('.number_format($r['constraint_rate']*100,0).'%)' : ''?></td>
        <td><?=number_format(($r['faithfulness']??1.0)*100,0)?>%</td>
        <td><?=number_format($r['latency'],2)?>s</td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table></div>
</section>
<?php endif ?>

<?php if (is_array($latestCliReport) && is_array($latestCliReport['summary'] ?? null)): ?>
<section class="card">
  <h2>Latest Saved Reproducible CLI Benchmark (`evaluation/results/latest_run.json`)</h2>
  <?php $man = $latestCliReport['reproducibility_manifest'] ?? []; $agr = $latestCliReport['annotator_agreement'] ?? []; ?>
  <p class="note">
    Completed: <strong><?=esc((string)($man['completed_at_utc'] ?? ''))?></strong> ·
    Split: <strong><?=esc((string)($man['split'] ?? 'all'))?></strong> (<?=(int)($man['in_scope_case_count'] ?? 0)?> cases + <?=(int)($man['scope_case_count'] ?? 0)?> scope) ·
    Trials: <strong><?=(int)($man['trials'] ?? 1)?></strong> ·
    Seed: <strong><?=(int)($man['seed'] ?? 42)?></strong> ·
    GT SHA-256: <code><?=esc(substr((string)($man['ground_truth_sha256'] ?? ''), 0, 16))?>…</code>
    <?php if (isset($agr['cohens_kappa_weighted']['kappa'])): ?>
      · Weighted Cohen's &kappa;: <strong><?=number_format((float)$agr['cohens_kappa_weighted']['kappa'], 3)?></strong> (<?=esc((string)($agr['source'] ?? ''))?>)
    <?php endif ?>
  </p>
  <div class="table-wrap"><table>
    <thead><tr>
      <th>Config ID</th><th>Trials</th><th>P@5 [95% CI]</th><th>NDCG@5 [95% CI]</th><th>MRR</th>
      <th>Disambig %</th><th>Price Ceil %</th><th>Loop Init→Final</th>
      <th>Constraint %</th><th>Scope Acc %</th><th>Follow-up %</th><th>Faithful %</th><th>P50 (s)</th>
    </tr></thead>
    <tbody>
    <?php foreach ($latestCliReport['summary'] as $sr): ?>
      <?php $al = $sr['agentic_loop_stats'] ?? []; ?>
      <tr>
        <td><strong><?=esc((string)$sr['id'])?></strong></td>
        <td><?=(int)($sr['trials'] ?? 1)?></td>
        <td><?=number_format(($sr['p5']['mean'] ?? 0)*100,1)?>% [<?=number_format(($sr['p5']['ci_lower'] ?? 0)*100,1)?>, <?=number_format(($sr['p5']['ci_upper'] ?? 0)*100,1)?>]</td>
        <td><?=number_format(($sr['ndcg5']['mean'] ?? 0)*100,1)?>% [<?=number_format(($sr['ndcg5']['ci_lower'] ?? 0)*100,1)?>, <?=number_format(($sr['ndcg5']['ci_upper'] ?? 0)*100,1)?>]</td>
        <td><?=number_format((float)($sr['mrr']['mean'] ?? 0),3)?></td>
        <td><?=isset($sr['disambiguation_accuracy']) && $sr['disambiguation_accuracy'] !== null ? number_format($sr['disambiguation_accuracy']*100,1).'%' : 'N/A'?></td>
        <td><?=isset($sr['price_ceiling_compliance_rate']) && $sr['price_ceiling_compliance_rate'] !== null ? number_format($sr['price_ceiling_compliance_rate']*100,1).'%' : 'N/A'?></td>
        <td><?=!empty($al['enabled']) ? number_format(($al['initial_compliance_rate']??0)*100,1).'% → '.number_format(($al['final_compliance_rate']??0)*100,1).'%' : 'Disabled'?></td>
        <td><?=number_format(($sr['constraint_item_rate']['mean'] ?? 0)*100,1)?>%</td>
        <td><?=number_format(($sr['scope_rejection_accuracy'] ?? 0)*100,1)?>%</td>
        <td><?=isset($sr['followup_resolution_rate']) && $sr['followup_resolution_rate'] !== null ? number_format($sr['followup_resolution_rate']*100,1).'%' : 'N/A'?></td>
        <td><?=number_format(($sr['faithfulness']['mean'] ?? 0)*100,1)?>%</td>
        <td><?=number_format((float)($sr['latency']['median_p50_s'] ?? 0),2)?>s</td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table></div>
</section>
<?php endif ?>

<?php if (is_array($latestVolReport) && is_array($latestVolReport['summary'] ?? null)): ?>
<section class="card">
  <h2>Objective 1 — Controlled Data Volatility Resilience (`evaluation/results/latest_volatility.json`)</h2>
  <p class="note">
    Scenarios: <strong><?=(int)($latestVolReport['scenario_count'] ?? 0)?></strong> (Closures, Menu Price Drift, Hours Changes, Rating Shifts, &amp; Stale 12h Cache vs. Fresh Snapshot) ·
    Friedman p-value: <strong><?=isset($latestVolReport['statistical_tests']['friedman']['p_value']) ? number_format((float)$latestVolReport['statistical_tests']['friedman']['p_value'], 6) : 'N/A'?></strong>
  </p>
  <div class="table-wrap"><table>
    <thead><tr>
      <th>Condition</th><th>Evidence Mode</th><th>Resilience Pass [95% CI]</th><th>Invalid Venue Suppr %</th><th>Valid Top-5 P@5</th><th>Closure %</th><th>Price Drift %</th><th>Hours %</th><th>Rating %</th>
    </tr></thead>
    <tbody>
    <?php foreach ($latestVolReport['summary'] as $vs): $bt = $vs['accuracy_by_volatility_type'] ?? []; ?>
      <tr>
        <td><strong><?=esc((string)$vs['id'])?></strong></td>
        <td><span class="badge"><?=esc((string)$vs['evidence_mode'])?></span></td>
        <td><?=number_format(($vs['resilience_pass_rate']['mean']??0)*100,1)?>% [<?=number_format(($vs['resilience_pass_rate']['ci_lower']??0)*100,1)?>, <?=number_format(($vs['resilience_pass_rate']['ci_upper']??0)*100,1)?>]</td>
        <td><?=number_format(($vs['invalid_venue_suppression_rate']['mean']??0)*100,1)?>%</td>
        <td><?=number_format(($vs['valid_top5_precision']['mean']??0)*100,1)?>%</td>
        <td><?=number_format(($bt['closure']??0)*100,1)?>%</td>
        <td><?=number_format(($bt['price_drift']??0)*100,1)?>%</td>
        <td><?=number_format(($bt['hours_change']??0)*100,1)?>%</td>
        <td><?=number_format(($bt['rating_shift']??0)*100,1)?>%</td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table></div>
</section>
<?php endif ?>

<section class="card">
  <h2>Objective 4 — Human Usability &amp; SUS Status (<a href="usability.php">Open `usability.php`</a>)</h2>
  <?php if (($usabilitySummary['participant_count'] ?? 0) === 0): ?>
    <p class="note">Recorded human sessions: <strong>n = 0</strong> (<code>awaiting_human_participants</code>). Use <a href="usability.php">evaluation/usability.php</a> to administer the 6 standardized interactive tasks and official 10-item System Usability Scale (SUS) with real human participants.</p>
  <?php else: ?>
    <p class="note">
      Participants: <strong><?=(int)$usabilitySummary['participant_count']?></strong> ·
      Mean SUS: <strong><?=number_format((float)$usabilitySummary['sus_ci95']['mean'], 1)?> / 100</strong> [<?=number_format((float)$usabilitySummary['sus_ci95']['ci_lower'], 1)?>, <?=number_format((float)$usabilitySummary['sus_ci95']['ci_upper'], 1)?>] ·
      Task Completion: <strong><?=number_format((float)($usabilitySummary['task_completion_ci95']['mean'] ?? 0)*100, 1)?>%</strong> ·
      Median Task Time: <strong><?=number_format((float)$usabilitySummary['median_task_time_sec'], 1)?>s</strong>
    </p>
  <?php endif ?>
</section>

</main>
</body>
</html>
