<?php
declare(strict_types=1);

require_once __DIR__ . '/src/config.php';
require_once __DIR__ . '/evaluation/run_live_pipeline_verification.php';

echo "================================================================\n";
echo "   DAVAO FOOD FINDER - PIPELINE COMPONENT DIAGNOSTIC            \n";
echo "================================================================\n";
echo "Independent provider/retrieval probes (not one end-to-end request):\n";
echo "  User Input -> (1) GPT-4o mini Query Planner\n";
echo "             -> (2) Playwright Active Web Scraping\n";
echo "             -> (3) GPT-4o mini Raw Evidence Structurer\n";
echo "             -> (4) DeepSeek Flash Reasoner & Curator\n";
echo "             -> (5) GPT-4o mini Final User Responder\n";
echo "================================================================\n\n";

$openAiKey = OPENAI_API_KEY;
$openAiModel = OPENAI_MODEL;
$deepSeekKey = DEEPSEEK_API_KEY;
$deepSeekBase = DEEPSEEK_BASE_URL;
$deepSeekModel = DEEPSEEK_MODEL;

$openAiOk = false;
$playwrightOk = false;
$deepSeekOk = false;
$playwrightProbe = null;

echo "[1] ENVIRONMENT & CONFIGURATION\n";
echo "-------------------------------\n";
echo "  OPENAI_API_KEY    : " . ($openAiKey !== '' ? 'set' : '(not set - required for GPT-4o mini)') . "\n";
echo "  OPENAI_MODEL      : {$openAiModel}\n";
echo "  DEEPSEEK_API_KEY  : " . ($deepSeekKey !== '' ? 'set' : '(not set - required for the paid API)') . "\n";
echo "  DEEPSEEK_BASE_URL : {$deepSeekBase}\n";
echo "  DEEPSEEK_MODEL    : {$deepSeekModel}\n\n";

// ----------------------------------------------------
// Test GPT-4o mini (Stages 1, 3, 5)
// ----------------------------------------------------
echo "[2] TESTING GPT-4o mini (Stages 1, 3, & 5)\n";
echo "------------------------------------------\n";
if ($openAiKey === '') {
    echo "  [FAIL] OPENAI_API_KEY is not set in this shell.\n";
    echo "         Set it before starting PHP: \$env:OPENAI_API_KEY = \"sk-proj-...\"\n\n";
} else {
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    $payload = json_encode([
        'model' => $openAiModel,
        'messages' => [['role' => 'user', 'content' => 'Return JSON: {"ok":true}']],
        'response_format' => ['type' => 'json_object'],
        'max_tokens' => 15,
    ]);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $openAiKey],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $t0 = microtime(true);
    $res = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);
    $lat = round((microtime(true) - $t0) * 1000);
    $openAiData = is_string($res) ? json_decode($res, true) : null;
    $openAiReturnedModel = is_array($openAiData) ? trim((string)($openAiData['model'] ?? '')) : '';
    $openAiContent = $openAiData['choices'][0]['message']['content'] ?? null;
    $openAiParsedContent = is_string($openAiContent) ? json_decode($openAiContent, true) : null;
    $openAiUsable = is_array($openAiParsedContent) && ($openAiParsedContent['ok'] ?? false) === true;
    $openAiModelMatches = str_starts_with(strtolower($openAiReturnedModel), 'gpt-4o-mini');

    if ($httpCode >= 200 && $httpCode < 300 && $openAiModelMatches && $openAiUsable) {
        $openAiOk = true;
        echo "  [OK] GPT-4o mini ({$openAiReturnedModel}) returned usable JSON in {$lat} ms!\n\n";
    } else {
        echo "  [FAIL] GPT probe did not verify expected model and usable output (HTTP {$httpCode}, returned model: " . ($openAiReturnedModel ?: 'not reported') . ", {$lat} ms)\n";
        if ($curlErr !== '') echo "         cURL error: {$curlErr}\n";
        if ($res !== false && $res !== '') {
            $msg = $openAiData['error']['message'] ?? substr((string)$res, 0, 300);
            echo "         Details: {$msg}\n";
        }
        echo "\n";
    }
}

// ----------------------------------------------------
// Test Playwright Active Web Scraping (Stage 2)
// ----------------------------------------------------
echo "[3] TESTING PLAYWRIGHT ACTIVE SCRAPER (Stage 2)\n";
echo "-----------------------------------------------\n";
$script = __DIR__ . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'web-search.js';
$node = trim((string)getenv('PLAYWRIGHT_NODE')) !== '' ? trim((string)getenv('PLAYWRIGHT_NODE')) : 'node';

if (!is_file($script)) {
    echo "  [FAIL] Script not found: {$script}\n\n";
} else {
    $testQuery = 'quiet study cafes Obrero Davao City';
    $command = escapeshellarg($node) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($testQuery);
    $start = microtime(true);
    $pipes = [];
    $proc = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        echo "  [FAIL] Could not launch Node process: {$command}\n\n";
    } else {
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + 20;
        while (microtime(true) < $deadline) {
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);
            $status = proc_get_status($proc);
            if (!$status['running']) break;
            usleep(100000);
        }
        if (proc_get_status($proc)['running']) {
            proc_terminate($proc);
        }
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);
        $elapsed = round((microtime(true) - $start) * 1000);

        $playwrightProbe = json_decode(trim($stdout), true);
        if (is_array($playwrightProbe) && !empty($playwrightProbe['results'])) {
            $playwrightOk = true;
            $cnt = count($playwrightProbe['results']);
            echo "  [OK] Playwright probe found {$cnt} source(s) for '{$testQuery}' in {$elapsed} ms!\n";
            echo "       Sample source: " . ($playwrightProbe['results'][0]['title'] ?? 'Untitled') . " (" . ($playwrightProbe['results'][0]['url'] ?? '') . ")\n\n";
        } else {
            echo "  [FAIL/WARN] Exit code: {$exitCode} ({$elapsed} ms)\n";
            if ($stderr !== '') echo "       Error: " . trim($stderr) . "\n";
            if (is_array($playwrightProbe) && !empty($playwrightProbe['browser_used'])) {
                echo "       Chromium launched successfully, but retrieval returned no sources; investigate search-engine/network access.\n";
            }
            if (trim($stdout) !== '') echo "       Output: " . substr(trim($stdout), 0, 500) . "\n";
            echo "\n";
        }
    }
}

// ----------------------------------------------------
// Test hosted DeepSeek Flash (Stage 4)
// ----------------------------------------------------
echo "[4] TESTING DEEPSEEK FLASH API (Stage 4)\n";
echo "------------------------------------------\n";
if ($deepSeekKey !== '') {
    $base = rtrim($deepSeekBase, '/');
    $testModel = $deepSeekModel;
    $ch = curl_init($base . '/chat/completions');
    $payload = json_encode([
        'model'=>$testModel,
        'messages'=>[['role'=>'user', 'content'=>'Return JSON: {"reasoning":"probe","status":"ready"}']],
        'max_tokens'=>80,
        'thinking'=>['type'=>'disabled'],
        'response_format'=>['type'=>'json_object'],
    ]);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $deepSeekKey],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $deepSeekData = is_string($res) ? json_decode($res, true) : null;
    $deepSeekReturnedModel = is_array($deepSeekData) ? trim((string)($deepSeekData['model'] ?? '')) : '';
    $deepSeekContent = $deepSeekData['choices'][0]['message']['content'] ?? null;
    $deepSeekParsedContent = is_string($deepSeekContent) ? json_decode($deepSeekContent, true) : null;
    $deepSeekUsable = is_array($deepSeekParsedContent) && ($deepSeekParsedContent['status'] ?? '') === 'ready';
    $deepSeekModelMatches = livePipelineIsSupportedDeepSeekModel($deepSeekReturnedModel);
    if ($code >= 200 && $code < 300 && $deepSeekModelMatches && $deepSeekUsable) {
        $deepSeekOk = true;
        echo "  [OK] Paid DeepSeek ({$deepSeekReturnedModel}) returned usable JSON!\n\n";
    } else {
        echo "  [FAIL] Paid DeepSeek probe did not verify the supported model and usable JSON (HTTP {$code}, returned model: " . ($deepSeekReturnedModel ?: 'not reported') . ").\n";
        if ($res !== false && $res !== '') {
            $errData = json_decode((string)$res, true);
            $msg = $errData['error']['message'] ?? substr((string)$res, 0, 300);
            echo "         Details: {$msg}\n";
        }
        echo "\n";
    }
} else {
    echo "  [FAIL] DEEPSEEK_API_KEY is not set; the paid API is required and Ollama is not used as a fallback.\n\n";
}

echo "================================================================\n";
echo "   COMPONENT PROBE VERDICT\n";
echo "================================================================\n";
if ($openAiOk && $playwrightOk && $deepSeekOk) {
    echo "  [READY] GPT-4o mini, Playwright, and paid DeepSeek probes passed independently.\n";
    echo "  This does not prove that all five stages succeeded in the same request.\n";
} else {
    echo "  One or more independent component probes failed; see details above.\n";
    if (!$openAiOk) echo "    1. Set OPENAI_API_KEY in the same PowerShell window where you start PHP.\n";
    if (!$playwrightOk) {
        if (is_array($playwrightProbe) && !empty($playwrightProbe['browser_used'])) {
            echo "    2. Chromium is available; check outbound HTTPS/proxy access to search engines and Photon.\n";
        } else {
            echo "    2. Ensure internet access & Chromium (`npx playwright install chromium`).\n";
        }
    }
    if (!$deepSeekOk) echo "    3. Set DEEPSEEK_API_KEY and confirm DEEPSEEK_API_MODEL is supported (default: deepseek-flash).\n";
}
echo "  For request-level five-stage verification, run evaluation/run_volatility_experiment.php --live-provider=hybrid.\n";
echo "================================================================\n";
