<?php
declare(strict_types=1);

require_once __DIR__ . '/src/config.php';

echo "================================================================\n";
echo "   DAVAO FOOD FINDER - 5-STAGE HYBRID PIPELINE DIAGNOSTIC       \n";
echo "================================================================\n";
echo "Target Flow:\n";
echo "  User Input -> (1) GPT-4o mini Query Planner\n";
echo "             -> (2) Playwright Active Web Scraping\n";
echo "             -> (3) GPT-4o mini Raw Evidence Structurer\n";
echo "             -> (4) DeepSeek R1 Reasoner & Curator\n";
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

echo "[1] ENVIRONMENT & CONFIGURATION\n";
echo "-------------------------------\n";
echo "  OPENAI_API_KEY    : " . ($openAiKey !== '' ? substr($openAiKey, 0, 7) . '...' . substr($openAiKey, -4) : '(NOT SET - required for Stages 1, 3, and 5)') . "\n";
echo "  OPENAI_MODEL      : {$openAiModel}\n";
echo "  DEEPSEEK_API_KEY  : " . ($deepSeekKey !== '' ? substr($deepSeekKey, 0, 7) . '...' . substr($deepSeekKey, -4) : '(not set - using local Ollama)') . "\n";
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

    if ($httpCode === 200) {
        $openAiOk = true;
        echo "  [OK] GPT-4o mini ({$openAiModel}) responded in {$lat} ms!\n\n";
    } else {
        echo "  [FAIL] OpenAI returned HTTP {$httpCode} ({$lat} ms)\n";
        if ($curlErr !== '') echo "         cURL error: {$curlErr}\n";
        if ($res !== false && $res !== '') {
            $errData = json_decode((string)$res, true);
            $msg = $errData['error']['message'] ?? substr((string)$res, 0, 300);
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

        $json = json_decode(trim($stdout), true);
        if (is_array($json) && !empty($json['results'])) {
            $playwrightOk = true;
            $cnt = count($json['results']);
            echo "  [OK] Playwright scraped {$cnt} live Davao City page(s) in {$elapsed} ms!\n";
            echo "       Sample source: " . ($json['results'][0]['title'] ?? 'Untitled') . " (" . ($json['results'][0]['url'] ?? '') . ")\n\n";
        } else {
            echo "  [FAIL/WARN] Exit code: {$exitCode} ({$elapsed} ms)\n";
            if ($stderr !== '') echo "       Error: " . trim($stderr) . "\n";
            if (trim($stdout) !== '') echo "       Output: " . substr(trim($stdout), 0, 500) . "\n";
            echo "\n";
        }
    }
}

// ----------------------------------------------------
// Test DeepSeek R1 / Ollama (Stage 4)
// ----------------------------------------------------
echo "[4] TESTING DEEPSEEK R1 REASONER (Stage 4)\n";
echo "------------------------------------------\n";
if ($deepSeekKey !== '') {
    $base = rtrim($deepSeekBase !== '' && $deepSeekBase !== 'http://127.0.0.1:11434' ? $deepSeekBase : 'https://api.deepseek.com', '/');
    $testModel = ($deepSeekModel !== '' && $deepSeekModel !== 'deepseek-r1:8b') ? $deepSeekModel : 'deepseek-reasoner';
    $ch = curl_init($base . '/chat/completions');
    $payload = json_encode(['model' => $testModel, 'messages' => [['role' => 'user', 'content' => 'Say OK']]]);
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
    if ($code === 200) {
        $deepSeekOk = true;
        echo "  [OK] Hosted DeepSeek R1 ({$testModel}) responded!\n\n";
    } else {
        echo "  [FAIL] Hosted DeepSeek API returned HTTP {$code}.\n";
        if ($res !== false && $res !== '') {
            $errData = json_decode((string)$res, true);
            $msg = $errData['error']['message'] ?? substr((string)$res, 0, 300);
            echo "         Details: {$msg}\n";
        }
        echo "\n";
    }
} else {
    $ch = curl_init(rtrim($deepSeekBase, '/') . '/api/tags');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
    ]);
    $tagsRaw = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($tagsRaw === false || $httpCode < 200 || $httpCode >= 300) {
        echo "  [FAIL] Local Ollama is unreachable at {$deepSeekBase}\n";
        if ($curlErr !== '') echo "         cURL error: {$curlErr}\n";
        echo "         Start Ollama (`ollama serve` or launch Ollama from Start Menu).\n\n";
    } else {
        $tagsData = json_decode((string)$tagsRaw, true);
        $models = [];
        foreach (($tagsData['models'] ?? []) as $m) {
            if (isset($m['name'])) $models[] = $m['name'];
        }
        echo "  [OK] Ollama service is running. Installed models: " . ($models ? implode(', ', $models) : '(none)') . "\n";
        $hasModel = false;
        foreach ($models as $m) {
            if ($m === $deepSeekModel || str_starts_with($m, $deepSeekModel . ':')) {
                $hasModel = true;
                break;
            }
        }
        if (!$hasModel) {
            echo "  [FAIL] Model '{$deepSeekModel}' is NOT installed. Run: ollama pull {$deepSeekModel}\n\n";
        } else {
            $ch = curl_init(rtrim($deepSeekBase, '/') . '/api/chat');
            $testBody = json_encode([
                'model' => $deepSeekModel,
                'messages' => [['role' => 'user', 'content' => 'Return JSON: {"reasoning":"ok","status":"ready"}']],
                'stream' => false,
                'format' => 'json',
                'options' => ['num_predict' => 40],
            ]);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS => $testBody,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 60,
            ]);
            $t2 = microtime(true);
            $chatRaw = curl_exec($ch);
            $chatCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $chatErr = curl_error($ch);
            curl_close($ch);
            $chatLat = round((microtime(true) - $t2) * 1000);
            if ($chatCode === 200) {
                $deepSeekOk = true;
                echo "  [OK] DeepSeek R1 ({$deepSeekModel}) reasoned & replied in {$chatLat} ms!\n\n";
            } else {
                echo "  [FAIL] Ollama /api/chat returned HTTP {$chatCode}: {$chatErr}\n\n";
            }
        }
    }
}

echo "================================================================\n";
echo "   PIPELINE READINESS VERDICT\n";
echo "================================================================\n";
if ($openAiOk && $playwrightOk && $deepSeekOk) {
    echo "  [READY] ALL 5 STAGES ARE ACTIVE AND VERIFIED!\n";
} else {
    echo "  To run the full 5-stage flow, ensure all 3 checks above are [OK]:\n";
    if (!$openAiOk) echo "    1. Set OPENAI_API_KEY in the same PowerShell window where you start PHP.\n";
    if (!$playwrightOk) echo "    2. Ensure internet access & Chromium (`npx playwright install chromium`).\n";
    if (!$deepSeekOk) echo "    3. Keep Ollama running with `{$deepSeekModel}` pulled (`ollama pull {$deepSeekModel}`).\n";
}
echo "================================================================\n";
