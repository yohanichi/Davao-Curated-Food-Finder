<?php
// Auto-load environment variables from .env or .env.local if present
(function() {
    $envDirs = [dirname(__DIR__), dirname(dirname(__DIR__))];
    foreach ($envDirs as $dir) {
        $files = [$dir . DIRECTORY_SEPARATOR . '.env', $dir . DIRECTORY_SEPARATOR . '.env.local'];
        foreach ($files as $file) {
            if (is_file($file) && is_readable($file)) {
                $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                if (is_array($lines)) {
                    foreach ($lines as $line) {
                        $line = trim($line);
                        if ($line === '' || str_starts_with($line, '#')) continue;
                        if (!str_contains($line, '=')) continue;
                        [$k, $v] = explode('=', $line, 2);
                        $k = trim($k);
                        $v = trim($v);
                        if ((str_starts_with($v, '"') && str_ends_with($v, '"')) || (str_starts_with($v, "'") && str_ends_with($v, "'"))) {
                            $v = substr($v, 1, -1);
                        }
                        if (getenv($k) === false || getenv($k) === '') {
                            putenv("{$k}={$v}");
                            $_ENV[$k] = $v;
                            $_SERVER[$k] = $v;
                        }
                    }
                }
            }
        }
    }
})();

// Hosted DeepSeek API defaults (OpenAI-compatible)
$deepSeekApiBase = getenv('DEEPSEEK_API_BASE_URL');
$deepSeekApiModel = getenv('DEEPSEEK_API_MODEL');
define('DEEPSEEK_API_BASE_URL', $deepSeekApiBase !== false && trim($deepSeekApiBase) !== '' ? rtrim(trim($deepSeekApiBase), '/') : 'https://api.deepseek.com');
define('DEEPSEEK_API_MODEL', $deepSeekApiModel !== false && trim($deepSeekApiModel) !== '' ? trim($deepSeekApiModel) : 'deepseek-reasoner');

// DeepSeek configuration stays on the server. Ollama is the free local default;
// set DEEPSEEK_API_KEY to use the hosted DeepSeek-compatible API instead.
$apiKey = getenv('DEEPSEEK_API_KEY');
$baseUrl = getenv('DEEPSEEK_BASE_URL');
$model = getenv('DEEPSEEK_MODEL');

$hasApiKey = $apiKey !== false && trim($apiKey) !== '';
$defaultBaseUrl = $hasApiKey ? DEEPSEEK_API_BASE_URL : 'http://127.0.0.1:11434';
$defaultModel = $hasApiKey ? DEEPSEEK_API_MODEL : 'deepseek-r1:8b';

define('DEEPSEEK_API_KEY', $apiKey !== false ? trim($apiKey) : '');
define('DEEPSEEK_BASE_URL', $baseUrl !== false && trim($baseUrl) !== '' ? trim($baseUrl) : $defaultBaseUrl);
define('DEEPSEEK_MODEL', $model !== false && trim($model) !== '' ? trim($model) : $defaultModel);

$openAIKey = getenv('OPENAI_API_KEY');
$openAIModel = getenv('OPENAI_MODEL');
define('OPENAI_API_KEY', $openAIKey !== false ? trim($openAIKey) : '');
define('OPENAI_MODEL', $openAIModel !== false && trim($openAIModel) !== '' ? trim($openAIModel) : 'gpt-4o-mini');

// Centralized timeout configuration (configurable via environment variables)
$phpMaxExec = getenv('PHP_MAX_EXECUTION_TIME');
$curlConnect = getenv('CURL_CONNECT_TIMEOUT');
$curlTimeout = getenv('CURL_TIMEOUT');
$llmTimeout = getenv('LLM_TIMEOUT');
$ollamaConnect = getenv('OLLAMA_CONNECT_TIMEOUT');
$pwProcTimeout = getenv('PLAYWRIGHT_PROC_TIMEOUT');
$pwStepTimeout = getenv('PLAYWRIGHT_STEP_TIMEOUT');

define('TIMEOUT_PHP_MAX_EXECUTION', $phpMaxExec !== false && is_numeric($phpMaxExec) ? (int)$phpMaxExec : 300);
define('TIMEOUT_CURL_CONNECT', $curlConnect !== false && is_numeric($curlConnect) ? (int)$curlConnect : 10);
define('TIMEOUT_CURL', $curlTimeout !== false && is_numeric($curlTimeout) ? (int)$curlTimeout : 180);
define('TIMEOUT_LLM', max(120, $llmTimeout !== false && is_numeric($llmTimeout) ? (int)$llmTimeout : 120));
define('TIMEOUT_OLLAMA_CONNECT', $ollamaConnect !== false && is_numeric($ollamaConnect) ? (int)$ollamaConnect : 5);
define('TIMEOUT_PLAYWRIGHT_PROC', $pwProcTimeout !== false && is_numeric($pwProcTimeout) ? (int)$pwProcTimeout : 30);
define('TIMEOUT_PLAYWRIGHT_STEP', $pwStepTimeout !== false && is_numeric($pwStepTimeout) ? (int)$pwStepTimeout : 10000);

function getTimeoutConfig(): array {
    return [
        'php_max_execution_time' => TIMEOUT_PHP_MAX_EXECUTION,
        'curl_connect_timeout' => TIMEOUT_CURL_CONNECT,
        'curl_timeout' => TIMEOUT_CURL,
        'llm_timeout' => TIMEOUT_LLM,
        'ollama_connect_timeout' => TIMEOUT_OLLAMA_CONNECT,
        'playwright_proc_timeout' => TIMEOUT_PLAYWRIGHT_PROC,
        'playwright_step_timeout' => TIMEOUT_PLAYWRIGHT_STEP,
        'php_max_execution' => TIMEOUT_PHP_MAX_EXECUTION,
        'curl_connect' => TIMEOUT_CURL_CONNECT,
        'curl' => TIMEOUT_CURL,
        'llm' => TIMEOUT_LLM,
        'ollama_connect' => TIMEOUT_OLLAMA_CONNECT,
        'playwright_proc' => TIMEOUT_PLAYWRIGHT_PROC,
        'playwright_step_ms' => TIMEOUT_PLAYWRIGHT_STEP,
    ];
}
