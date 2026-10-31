<?php
declare(strict_types=1);

/**
 * Validation Script for DeepSeek Ollama Integration.
 *
 * Runs test cases (Q005, Q006, Q009, Q010, Q012) 5 times each (total 25 trials).
 * Evaluates:
 *  - Parse Success Rate
 *  - Average Completion Tokens
 *  - Average Latency
 *  - done_reason Distribution (stop, length, etc.)
 *  - Retry Counts
 *
 * Usage:
 *   php evaluation/validate_deepseek.php [--api-url=http://127.0.0.1:8080/api.php] [--local-cli]
 */

$appRoot = dirname(__DIR__);
require_once $appRoot . '/vendor/autoload.php';
require_once $appRoot . '/src/config.php';

$apiUrl = 'http://127.0.0.1:8080/api.php';
$localCli = false;
$trialsPerCase = 5;
$targetIds = ['Q005', 'Q006', 'Q009', 'Q010', 'Q012'];

foreach ($argv as $arg) {
    if ($arg === '--local-cli') {
        $localCli = true;
    } elseif (str_starts_with($arg, '--api-url=')) {
        $apiUrl = substr($arg, strlen('--api-url='));
    } elseif (str_starts_with($arg, '--trials=')) {
        $trialsPerCase = max(1, (int)substr($arg, strlen('--trials=')));
    }
}

if (!$localCli) {
    $fp = @fsockopen('127.0.0.1', 8080, $errno, $errstr, 0.2);
    if ($fp) {
        fclose($fp);
    } else {
        $localCli = true;
    }
}

$gtFile = __DIR__ . '/ground_truth.json';
if (!is_file($gtFile)) {
    fwrite(STDERR, "Error: Ground truth file not found at {$gtFile}\n");
    exit(1);
}

$gt = json_decode((string)file_get_contents($gtFile), true);
$allCases = is_array($gt['cases'] ?? null) ? $gt['cases'] : [];
$caseMap = [];
foreach ($allCases as $c) {
    if (isset($c['id']) && in_array($c['id'], $targetIds, true)) {
        $caseMap[$c['id']] = $c;
    }
}

$selectedCases = [];
foreach ($targetIds as $tid) {
    if (isset($caseMap[$tid])) {
        $selectedCases[] = $caseMap[$tid];
    } else {
        fwrite(STDERR, "Warning: Target query {$tid} not found in ground truth.\n");
    }
}

if (empty($selectedCases)) {
    fwrite(STDERR, "Error: No matching target queries found.\n");
    exit(1);
}

echo "========================================================================================\n";
echo " DeepSeek Validation Benchmark: 5 Queries x {$trialsPerCase} Trials (Prompt: strict_grounding, Temp: 0.2)\n";
echo " Target Cases: " . implode(', ', array_map(fn($c) => $c['id'], $selectedCases)) . "\n";
echo " Mode: " . ($localCli ? 'Local PHP CLI worker' : "HTTP POST ({$apiUrl})") . "\n";
echo " Model: " . DEEPSEEK_MODEL . " | Endpoint: " . DEEPSEEK_BASE_URL . "\n";
echo "========================================================================================\n\n";

function executeValidationRequest(string $apiUrl, bool $localCli, array $case, int $trial, int $seed): array {
    $payload = json_encode([
        'query' => $case['query'],
        'history' => [],
        'shownNames' => [],
        'evaluation' => [
            'provider' => 'deepseek',
            'temperature' => 0.2,
            'promptStyle' => 'strict_grounding',
            'agentic' => true,
            'cachePolicy' => 'default',
            'seed' => $seed,
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    $start = microtime(true);
    if ($localCli) {
        $apiFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'api.php';
        $phpBin = PHP_BINARY ?: 'php';
        $runnerCode = '$_SERVER["REQUEST_METHOD"]="POST"; $_SERVER["REMOTE_ADDR"]="127.0.0.1"; chdir(' . var_export(dirname($apiFile), true) . '); require ' . var_export($apiFile, true) . ';';
        $pipes = [];
        $proc = proc_open([$phpBin, '-r', $runnerCode], [0=>['pipe', 'r'], 1=>['pipe', 'w'], 2=>['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            return ['success' => false, 'latency' => 0.0, 'tokens' => 0, 'done_reason' => 'error', 'retries' => 0, 'error' => 'Process open failed'];
        }
        fwrite($pipes[0], $payload);
        fclose($pipes[0]);
        $raw = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        $latency = microtime(true) - $start;
    } else {
        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 180,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $raw = curl_exec($ch);
        $latency = microtime(true) - $start;
        curl_close($ch);
    }

    $decoded = json_decode((string)$raw, true);
    if (!is_array($decoded)) {
        return ['success' => false, 'latency' => $latency, 'tokens' => 0, 'done_reason' => 'invalid_json', 'retries' => 0, 'error' => 'Invalid JSON response from api.php'];
    }

    $assistantStatus = $decoded['assistantStatus'] ?? 'error';
    $success = ($assistantStatus === 'ready');
    $usage = $decoded['metrics']['usage'] ?? [];
    $completionTokens = (int)($usage['completion_tokens'] ?? 0);
    $deepseekMetrics = $decoded['metrics']['deepseek'] ?? [];
    $doneReason = (string)($deepseekMetrics['done_reason'] ?? ($success ? 'stop' : 'error'));
    $retries = (int)($deepseekMetrics['retries'] ?? 0);
    $errorMsg = $decoded['error'] ?? ($deepseekMetrics['error']['message'] ?? null);

    return [
        'success' => $success,
        'latency' => $latency,
        'tokens' => $completionTokens,
        'done_reason' => $doneReason,
        'retries' => $retries,
        'error' => $errorMsg,
    ];
}

$resultsByCase = [];
$allReasons = [];
$totalSuccess = 0;
$totalQueries = 0;
$totalTokens = 0;
$totalRetries = 0;
$allLatencies = [];

foreach ($selectedCases as $cIdx => $case) {
    $cid = $case['id'];
    $query = $case['query'];
    $cat = $case['category'] ?? 'general';
    $resultsByCase[$cid] = [
        'id' => $cid,
        'category' => $cat,
        'query' => $query,
        'trials' => [],
    ];

    echo sprintf("[%d/%d] Testing %s: \"%s\"\n", $cIdx + 1, count($selectedCases), $cid, mb_substr($query, 0, 50));

    for ($t = 1; $t <= $trialsPerCase; $t++) {
        $seed = 1000 + ($t * 101);
        $res = executeValidationRequest($apiUrl, $localCli, $case, $t, $seed);
        $resultsByCase[$cid]['trials'][] = $res;

        $totalQueries++;
        if ($res['success']) $totalSuccess++;
        $totalTokens += $res['tokens'];
        $totalRetries += $res['retries'];
        $allLatencies[] = $res['latency'];
        $allReasons[$res['done_reason']] = ($allReasons[$res['done_reason']] ?? 0) + 1;

        $statusStr = $res['success'] ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m";
        printf(
            "   Trial %d/%d: %s | %4.2fs | %4d tokens | reason: %-6s | retries: %d%s\n",
            $t,
            $trialsPerCase,
            $statusStr,
            $res['latency'],
            $res['tokens'],
            $res['done_reason'],
            $res['retries'],
            $res['error'] ? " (Err: {$res['error']})" : ""
        );
    }
    echo "\n";
}

echo "========================================================================================\n";
echo " VALIDATION RESULTS SUMMARY TABLE\n";
echo "========================================================================================\n";
printf(
    "%-6s | %-16s | %-12s | %-11s | %-11s | %-16s | %-8s\n",
    'Case', 'Category', 'Parse Pass', 'Avg Tokens', 'Avg Latency', 'Done Reasons', 'Retries'
);
echo str_repeat('-', 96) . "\n";

foreach ($resultsByCase as $cid => $data) {
    $trials = $data['trials'];
    $n = count($trials);
    $passCount = count(array_filter($trials, fn($r) => $r['success']));
    $passRate = $n > 0 ? ($passCount / $n) * 100 : 0.0;
    $avgTokens = $n > 0 ? array_sum(array_column($trials, 'tokens')) / $n : 0.0;
    $avgLat = $n > 0 ? array_sum(array_column($trials, 'latency')) / $n : 0.0;
    $caseRetries = array_sum(array_column($trials, 'retries'));

    $reasons = [];
    foreach ($trials as $tr) {
        $reasons[$tr['done_reason']] = ($reasons[$tr['done_reason']] ?? 0) + 1;
    }
    $reasonStr = implode(', ', array_map(fn($k, $v) => "{$k}:{$v}", array_keys($reasons), $reasons));

    printf(
        "%-6s | %-16s | %5.1f%% (%d/%d) | %9.1f | %9.2fs | %-16s | %8d\n",
        $cid,
        mb_substr($data['category'], 0, 16),
        $passRate,
        $passCount,
        $n,
        $avgTokens,
        $avgLat,
        mb_substr($reasonStr, 0, 16),
        $caseRetries
    );
}

echo "\n========================================================================================\n";
echo " OVERALL BENCHMARK METRICS\n";
echo "========================================================================================\n";
printf("Total Requests:           %d\n", $totalQueries);
printf("Parse-Success Rate:       %.1f%% (%d/%d)\n", $totalQueries > 0 ? ($totalSuccess / $totalQueries) * 100 : 0.0, $totalSuccess, $totalQueries);
printf("Average Tokens Generated: %.1f\n", $totalQueries > 0 ? ($totalTokens / $totalQueries) : 0.0);
printf("Average Latency:          %.2fs\n", $totalQueries > 0 ? (array_sum($allLatencies) / $totalQueries) : 0.0);
printf("Total Retries Triggered:  %d\n", $totalRetries);
echo "Done Reason Breakdown:   ";
foreach ($allReasons as $dr => $cnt) {
    printf(" [%s: %d (%.1f%%)]", $dr, $cnt, ($cnt / max(1, $totalQueries)) * 100);
}
echo "\n========================================================================================\n";

