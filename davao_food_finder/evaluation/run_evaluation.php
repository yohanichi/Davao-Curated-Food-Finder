<?php
declare(strict_types=1);

$appRoot = dirname(__DIR__);
require_once $appRoot . '/vendor/autoload.php';
require_once $appRoot . '/src/config.php';
require_once $appRoot . '/src/search.php';
require_once $appRoot . '/src/web_reasoner.php';
require_once __DIR__ . '/statistics.php';
require_once __DIR__ . '/metrics.php';
require_once __DIR__ . '/provenance.php';
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
        'api_url_explicit'=>false,
        'ground_truth'=>__DIR__ . '/ground_truth.json',
        'providers'=>['deepseek'],
        'providers_explicit'=>false,
        'split'=>'all',
        'limit'=>0,
        'trials'=>1,
        'trials_explicit'=>false,
        'cache_policy'=>'default',
        'cache_policy_explicit'=>false,
        'detailed_telemetry'=>false,
        'dev_grid'=>false,
        'final_run'=>false,
        'selection_metric'=>'ndcg5',
        'allow_test_selection'=>false,
        'selection_report'=>'',
        'randomize_config_order'=>false,
        'reject_listicles'=>false,
        'compare_listicle_filter'=>false,
        'critique_web_rescrape'=>false,
        'loop_telemetry'=>false,
        'gpt_retries'=>0,
        'retry_backoff_ms'=>250,
        'gpt_max_tokens'=>null,
        'trace_case'=>'',
        'deepseek_repeat_identical'=>false,
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
            $options['api_url_explicit'] = true;
        } elseif ($argument === '--local-ablation') {
            $options['local_cli'] = true;
            $options['api_url'] = 'local';
            $options['api_url_explicit'] = true;
            $options['research'] = true;
            if (!$options['providers_explicit']) {
                $options['providers'] = ['baseline', 'ablation-no-loop', 'agentic-local'];
                $options['providers_explicit'] = true;
            }
        } elseif (str_starts_with($argument, '--api-url=')) {
            $options['api_url'] = substr($argument, strlen('--api-url='));
            $options['api_url_explicit'] = true;
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
            $options['cache_policy_explicit'] = true;
        } elseif ($argument === '--detailed-telemetry') {
            $options['detailed_telemetry'] = true;
        } elseif ($argument === '--dev-grid') {
            $options['dev_grid'] = true;
        } elseif ($argument === '--final-run') {
            $options['final_run'] = true;
        } elseif ($argument === '--allow-test-selection') {
            $options['allow_test_selection'] = true;
        } elseif ($argument === '--randomize-config-order') {
            $options['randomize_config_order'] = true;
        } elseif ($argument === '--reject-listicles') {
            $options['reject_listicles'] = true;
        } elseif ($argument === '--compare-listicle-filter') {
            $options['compare_listicle_filter'] = true;
        } elseif ($argument === '--critique-web-rescrape') {
            $options['critique_web_rescrape'] = true;
        } elseif ($argument === '--loop-telemetry') {
            $options['loop_telemetry'] = true;
        } elseif ($argument === '--deepseek-repeat-identical') {
            $options['deepseek_repeat_identical'] = true;
        } elseif (str_starts_with($argument, '--selection-metric=')) {
            $options['selection_metric'] = strtolower(trim(substr($argument, strlen('--selection-metric='))));
        } elseif (str_starts_with($argument, '--selection-report=')) {
            $options['selection_report'] = trim(substr($argument, strlen('--selection-report=')));
        } elseif (str_starts_with($argument, '--gpt-retries=')) {
            $options['gpt_retries'] = max(0, min(5, (int)substr($argument, strlen('--gpt-retries='))));
        } elseif (str_starts_with($argument, '--retry-backoff-ms=')) {
            $options['retry_backoff_ms'] = max(0, min(30000, (int)substr($argument, strlen('--retry-backoff-ms='))));
        } elseif (str_starts_with($argument, '--gpt-max-tokens=')) {
            $options['gpt_max_tokens'] = max(1, (int)substr($argument, strlen('--gpt-max-tokens=')));
        } elseif (str_starts_with($argument, '--trace=')) {
            $options['trace_case'] = trim(substr($argument, strlen('--trace=')));
        } elseif (str_starts_with($argument, '--seed=')) {
            $options['seed'] = (int)substr($argument, strlen('--seed='));
        } elseif (str_starts_with($argument, '--output=')) {
            $options['output'] = trim(substr($argument, strlen('--output=')));
        } else {
                fail("Unknown option {$argument}. Supported: --quick, --research, --dev-grid, --final-run, --selection-report=PATH, --selection-metric=ndcg5|p5, --allow-test-selection, --randomize-config-order, --reject-listicles, --compare-listicle-filter, --critique-web-rescrape, --loop-telemetry, --gpt-retries=N, --retry-backoff-ms=MS, --gpt-max-tokens=N, --trace=CASE_ID, --deepseek-repeat-identical, --local-cli, --local-ablation, --require-ai, --live-turns, --no-save, --detailed-telemetry, --api-url=URL, --ground-truth=PATH, --provider=..., --providers=baseline,ablation-no-loop,agentic-local,non-agentic,deepseek,deepseek-api,gpt-4o-mini,hybrid, --split=all|train|dev|test, --trials=N, --cache-policy=default|cold|warm|none, --seed=N, --limit=N, --output=PATH.");
        }
    }

    if ($options['dev_grid']) {
            if ($options['providers_explicit']) fail('--dev-grid selects the fixed DeepSeek + GPT-4o-mini grid; do not combine it with --provider/--providers.');
            if (!in_array($options['split'], ['all', 'dev'], true)) fail('--dev-grid can only evaluate the dev split.');
            $options['providers'] = ['deepseek', 'gpt-4o-mini'];
            $options['providers_explicit'] = true;
            $options['research'] = false;
            $options['split'] = 'dev';
            if (!$options['trials_explicit']) $options['trials'] = 1;
        }

    if ($options['final_run']) {
            if ($options['selection_report'] === '') fail('--final-run requires --selection-report=PATH to a completed dev selection report.');
            if ($options['split'] !== 'all' && $options['split'] !== 'test') fail('--final-run runs only on --split=test.');
            $options['split'] = 'test';
            $options['providers'] = ['baseline', 'ablation-no-loop', 'agentic-local', 'non-agentic', 'deepseek', 'gpt-4o-mini', 'hybrid'];
            $options['providers_explicit'] = true;
            $options['research'] = true;
            if (!$options['trials_explicit']) $options['trials'] = 1;
        }

    if ($options['deepseek_repeat_identical']) {
            if ($options['providers_explicit'] && $options['providers'] !== ['deepseek']) {
                fail('--deepseek-repeat-identical only supports the DeepSeek provider.');
            }
            $options['providers'] = ['deepseek'];
            $options['providers_explicit'] = true;
            $options['research'] = false;
            if (!$options['trials_explicit']) $options['trials'] = 3;
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
        if (!$options['api_url_explicit']) {
            $fp = @fsockopen('127.0.0.1', 8080, $errno, $errstr, 0.2);
            if ($fp) {
                fclose($fp);
            } else {
                $options['local_cli'] = true;
                $options['api_url'] = 'local';
            }
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

    $allowedProviders = ['baseline', 'ablation-no-loop', 'agentic-local', 'non-agentic', 'deepseek', 'deepseek-api', 'gpt-4o-mini', 'hybrid'];
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
    if (!in_array($options['selection_metric'], ['ndcg5', 'p5'], true)) {
        fail('Selection metric must be ndcg5 or p5.');
    }
    if ($options['split'] === 'test' && !$options['final_run'] && !$options['allow_test_selection']) {
        fail('Refusing to select a configuration on the held-out test split. Use a dev selection report with --final-run, or explicitly pass --allow-test-selection.');
    }
    if ($options['trace_case'] !== '' && !preg_match('/^[A-Za-z0-9_-]+$/', $options['trace_case'])) {
        fail('--trace requires a case ID containing only letters, numbers, underscore, or hyphen.');
    }
    if ($options['trace_case'] !== '' && $options['no_save']) {
        fail('--trace cannot be combined with --no-save because trace artifacts must be persisted.');
    }
    $options['providers'] = array_values(array_unique($options['providers']));
    return $options;
}

function sanitizedEndpoint(string $endpoint): string {
    $parts = parse_url($endpoint);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) return '';
    $path = (string)($parts['path'] ?? '');
    $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
    return strtolower((string)$parts['scheme']) . '://' . strtolower((string)$parts['host']) . $port . $path;
}

function clearEvidenceCache(string $cacheDir): int {
    if (!is_dir($cacheDir)) return 0;
    $resolved = realpath($cacheDir);
    if ($resolved === false) throw new RuntimeException('Could not resolve the evidence cache directory.');
    $removed = 0;
    foreach (new DirectoryIterator($resolved) as $entry) {
        if ($entry->isDot() || !$entry->isFile() || strtolower($entry->getExtension()) !== 'json') continue;
        if (!unlink($entry->getPathname())) {
            throw new RuntimeException('Could not clear evidence cache file: ' . $entry->getFilename());
        }
        $removed++;
    }
    return $removed;
}

function shuffleConfigurationsDeterministic(array $configurations, int $seed): array {
    $state = $seed & 0x7fffffff;
    for ($i = count($configurations) - 1; $i > 0; $i--) {
        $state = (1103515245 * $state + 12345) & 0x7fffffff;
        $j = $state % ($i + 1);
        [$configurations[$i], $configurations[$j]] = [$configurations[$j], $configurations[$i]];
    }
    return $configurations;
}

function extractRequestTelemetry(string $caseId, string $configId, int $trial, array $response, bool $detailed): array {
    $data = is_array($response['data'] ?? null) ? $response['data'] : [];
    $metrics = is_array($data['metrics'] ?? null) ? $data['metrics'] : [];
    $usage = is_array($metrics['usage'] ?? null) ? $metrics['usage'] : [];
    $timings = is_array($metrics['timings_ms'] ?? null) ? $metrics['timings_ms'] : [];
    $stageUsage = is_array($usage['stages'] ?? null) ? $usage['stages'] : [];
    $stageLatencies = [];
    foreach ($stageUsage as $stage) {
        $name = (string)($stage['stage'] ?? 'unknown');
        $stageLatencies[$name] = ($stageLatencies[$name] ?? 0) + (int)($stage['latency_ms'] ?? 0);
    }

    return [
        'case_id'=>$caseId,
        'config_id'=>$configId,
        'trial'=>$trial,
        'model_id'=>(string)($data['modelUsed'] ?? $metrics['reproducibility']['model_used'] ?? ''),
        'input_tokens'=>(int)($usage['prompt_tokens'] ?? 0),
        'output_tokens'=>(int)($usage['completion_tokens'] ?? 0),
        'reasoning_tokens'=>(int)($usage['reasoning_tokens'] ?? 0),
        'total_tokens'=>(int)($usage['total_tokens'] ?? 0),
        'saved_cost_usd'=>(float)($usage['estimated_cost_usd'] ?? 0.0),
        'stage_usage'=>$stageUsage,
        'stage_latency_ms'=>$stageLatencies ?: ($timings['ai_stages_ms'] ?? []),
        'total_latency_ms'=>(int)($metrics['total_latency_ms'] ?? round((float)($response['latency'] ?? 0.0) * 1000)),
        'cache'=>$metrics['cache'] ?? [],
        'collector'=>$metrics['playwright'] ?? [],
        'pipeline'=>$metrics['pipeline'] ?? [],
        'retries'=>(int)($metrics['deepseek']['retries'] ?? 0) + max((int)($metrics['gpt_retry_count'] ?? 0), (int)($response['retry_count'] ?? 0)),
        'failure'=>$response['failure'] ?? null,
        'error_type'=>$response['error_reason'] ?? null,
        'stage'=>failureStageFromMetrics($metrics, (string)($response['failure'] ?? '')),
        'http_code'=>(int)($response['http_status'] ?? 0),
        'timeout_threshold_s'=>$response['timeout_threshold_s'] ?? null,
        'trace'=>$metrics['trace'] ?? null,
    ];
}

function failureStageFromMetrics(array $metrics, string $failure): string {
    $stages = is_array($metrics['pipeline']['stages'] ?? null) ? $metrics['pipeline']['stages'] : [];
    foreach ($stages as $name => $status) {
        if (in_array(strtolower((string)$status), ['error', 'invalid_json', 'unavailable'], true)) {
            return (string)$name;
        }
    }
    return $failure !== '' ? 'api_request' : 'unknown';
}

function providerHttpCode(array $metrics, int $transportCode): int {
    foreach (['deepseek', 'deepseek_api', 'gpt4o'] as $providerKey) {
        $provider = is_array($metrics[$providerKey] ?? null) ? $metrics[$providerKey] : [];
        $error = is_array($provider['error'] ?? null) ? $provider['error'] : [];
        $status = (int)($error['http'] ?? $error['code'] ?? 0);
        if ($status > 0) return $status;
    }
    return $transportCode;
}

function timeoutThresholdForError(array $response, string $errorType, string $stage): ?int {
    if ($errorType === 'timeout' && $stage !== 'api_request') {
        return (int)getTimeoutConfig()['llm'];
    }
    return isset($response['timeout_threshold_s']) ? (int)$response['timeout_threshold_s'] : null;
}

function buildCaseDiagnostics(array $groundTruth, array $detailedRuns, array $datasetIndex, array $listicleComparisonResults = []): array {
    $diagnostics = [];
    foreach (($groundTruth['cases'] ?? []) as $case) {
        if (!is_array($case) || !in_array((string)($case['id'] ?? ''), ['D014', 'Q036'], true)) continue;
        $caseId = (string)$case['id'];
        $isInRun = false;
        foreach ($detailedRuns as $configRuns) {
            if (isset($configRuns['cases'][$caseId])) {
                $isInRun = true;
                break;
            }
        }
        if (!$isInRun) continue;

        $expectedItems = [];
        foreach (($case['relevant_names'] ?? []) as $name) {
            $normalizedName = normalizePlaceName((string)$name);
            $record = $datasetIndex[$normalizedName] ?? null;
            $matchedAddresses = [];
            if (!is_array($record)) {
                $baseName = normalizeBaseRestaurantName((string)$name);
                foreach ($datasetIndex as $datasetRecord) {
                    if ($baseName !== '' && normalizeBaseRestaurantName((string)($datasetRecord['name'] ?? '')) === $baseName) {
                        $matchedAddresses[] = (string)($datasetRecord['address'] ?? $datasetRecord['full_address'] ?? '');
                    }
                }
            }
            $address = is_array($record)
                ? (string)($record['address'] ?? $record['full_address'] ?? '')
                : implode(' / ', array_values(array_unique(array_filter($matchedAddresses))));
            $expectedItems[] = [
                'name'=>(string)$name,
                'address'=>$address,
            ];
        }
        $perConfig = [];
        foreach ($detailedRuns as $configId => $configRuns) {
            $records = $configRuns['cases'][$caseId] ?? [];
            $perConfig[$configId] = array_map(static function(array $record): array {
                return [
                    'trial'=>(int)($record['trial'] ?? 0),
                    'returned_top5'=>$record['top5_returned'] ?? [],
                    'constraint_dimension_scores'=>$record['constraint']['dimension_scores'] ?? [],
                    'failed_dimensions'=>$record['constraint']['failed_dimensions'] ?? [],
                ];
            }, $records);
        }
        $comparisonForCase = [];
        foreach ($listicleComparisonResults as $configId => $caseResults) {
            if (isset($caseResults[$caseId])) $comparisonForCase[$configId] = $caseResults[$caseId];
        }
        $diagnostics[$caseId] = [
            'query'=>(string)($case['query'] ?? ''),
            'expected_relevant_items'=>$expectedItems,
            'constraints'=>$case['constraints'] ?? [],
            'location_area_checker_rule'=>'Each returned place passes location_area if its combined candidate text contains at least one requested location_areas token; case-level location_area passes when the dimension reaches the evaluator threshold.',
            'per_config_trials'=>$perConfig,
            'listicle_filter_comparison'=>in_array($caseId, ['Q036', 'D014'], true)
                ? [
                    'enabled'=>count($comparisonForCase) > 0,
                    'by_config'=>$comparisonForCase,
                    'status'=>count($comparisonForCase) > 0 ? 'paired live comparison recorded' : 'pass --compare-listicle-filter to run paired before/after requests',
                ]
                : null,
        ];
    }

    return $diagnostics;
}

function buildScopeGuardAudit(): array {
    $recognizedLocations = [
        'Tagum City', 'Panabo City', 'Digos City', 'Island Garden City of Samal', 'Mati City',
        'Davao del Norte', 'Davao de Oro', 'Davao Occidental', 'Bansalan / Sta. Cruz',
        'Cebu City', 'Metro Manila', 'General Santos City', 'Cagayan de Oro', 'Baguio City',
        'Iloilo City', 'Bacolod City', 'Zamboanga City', 'Other Philippine/International Destination',
    ];
    $testCities = ['Digos', 'Samal', 'General Santos', 'Quezon City', 'Mati', 'Baguio'];
    $checks = [];
    foreach ($testCities as $city) {
        $detected = detectOutOfScopeLocation('Restaurants in ' . $city);
        $checks[] = [
            'city'=>$city,
            'rejected'=>(bool)($detected['out_of_scope'] ?? false),
            'matched_rule'=>$detected['matched_location'] ?? null,
        ];
    }
    return ['recognized_locations'=>$recognizedLocations, 'test_scope_city_checks'=>$checks];
}

function auditRequestCosts(array $requests, string $provider, array $modelPricing, bool $detailed): array {
    $rows = [];
    $mismatches = [];
    $computedCosts = [];
    foreach ($requests as $request) {
        $stages = is_array($request['stage_usage'] ?? null) ? $request['stage_usage'] : [];
        $computed = 0.0;
        if ($detailed && $stages) {
            foreach ($stages as $stage) {
                $stageCost = estimateModelTokenCost(
                    (string)($stage['provider_type'] ?? ''),
                    (string)($stage['model_id'] ?? ''),
                    (int)($stage['input_tokens'] ?? 0),
                    (int)($stage['output_tokens'] ?? 0)
                );
                $computed += $stageCost;
            }
        } else {
            $model = (string)($request['model_id'] ?? '');
            if ($provider === 'gpt-4o-mini') {
                $type = 'openai';
                if ($model === '') $model = (string)$modelPricing['gpt4o']['model'];
            } elseif ($provider === 'deepseek-api') {
                $type = 'deepseek_hosted';
                if ($model === '') $model = (string)$modelPricing['deepseek_api']['model'];
            } else {
                $type = $modelPricing['deepseek_local']['backend'] === 'hosted' ? 'deepseek_hosted' : 'ollama';
                if ($model === '') $model = (string)$modelPricing['deepseek_local']['model'];
            }
            $computed = estimateModelTokenCost(
                $type,
                $model,
                (int)($request['input_tokens'] ?? 0),
                (int)($request['output_tokens'] ?? 0)
            );
        }
        $saved = (float)($request['saved_cost_usd'] ?? 0.0);
        $diff = abs($computed - $saved);
        $row = [
            'case_id'=>$request['case_id'] ?? '',
            'trial'=>$request['trial'] ?? 0,
            'model_id'=>$request['model_id'] ?? '',
            'input_tokens'=>$request['input_tokens'] ?? 0,
            'output_tokens'=>$request['output_tokens'] ?? 0,
            'reasoning_tokens'=>$request['reasoning_tokens'] ?? 0,
            'computed_cost_usd'=>$computed,
            'saved_cost_usd'=>$saved,
            'absolute_difference_usd'=>$diff,
            'matches_saved_cost'=>$diff <= 0.00000002,
        ];
        $rows[] = $row;
        $computedCosts[] = $computed;
        if (!$row['matches_saved_cost']) $mismatches[] = $row;
    }

    return [
        'request_count'=>count($rows),
        'mean_computed_cost_usd'=>$computedCosts ? meanVal($computedCosts) : null,
        'total_computed_cost_usd'=>array_sum($computedCosts),
        'mismatch_count'=>count($mismatches),
        'mismatches'=>$mismatches,
        'requests'=>$rows,
    ];
}

/**
 * Classify a failed request into exactly one of the 7 specified error reasons:
 * timeout, ollama_unreachable, empty_response, invalid_json, schema_validation, php_exception, http_error.
 * Truncates raw message to 300 chars.
 */
function classifyErrorReason(?string $failure, int $httpStatus = 0, mixed $providerError = null, ?string $rawResponse = null): array {
    $rawMsg = '';
    if (is_array($providerError)) {
        $rawMsg = (string)($providerError['message'] ?? $providerError['error'] ?? '');
    } elseif (is_string($providerError) && $providerError !== '') {
        $rawMsg = $providerError;
    }
    if ($rawMsg === '' && $failure !== null) {
        $rawMsg = $failure;
    }
    if ($rawMsg === '' && $rawResponse !== null) {
        $rawMsg = trim($rawResponse);
    }
    $rawMsgTruncated = mb_substr(trim($rawMsg), 0, 300);
    $lower = strtolower($rawMsgTruncated);

    if (str_contains($lower, 'timed out') || str_contains($lower, 'timeout') || str_contains($lower, 'operation timed out') || str_contains($lower, 'maximum execution time')) {
        $category = 'timeout';
    } elseif (str_contains($lower, 'ollama') || str_contains($lower, '11434') || str_contains($lower, 'connection refused') || str_contains($lower, 'could not connect to host') || str_contains($lower, 'ollama_unreachable')) {
        $category = 'ollama_unreachable';
    } elseif (($rawResponse !== null && trim($rawResponse) === '') || str_contains($lower, 'empty output') || str_contains($lower, 'empty response')) {
        $category = 'empty_response';
    } elseif (str_contains($lower, 'invalid json') || str_contains($lower, 'json_error') || str_contains($lower, 'syntax error')) {
        $category = 'invalid_json';
    } elseif (str_contains($lower, 'schema') || str_contains($lower, 'validation') || str_contains($lower, 'missing required')) {
        $category = 'schema_validation';
    } elseif (str_contains($lower, 'fatal error') || str_contains($lower, 'uncaught') || str_contains($lower, 'exception') || str_contains($lower, 'php_exception') || str_contains($lower, 'stack trace') || str_contains($lower, 'could not launch')) {
        $category = 'php_exception';
    } else {
        $category = 'http_error';
    }

    return [$category, $rawMsgTruncated];
}

function sendSingleApiRequestOnce(string $apiUrl, string $query, array $history, array $shownNames, array $config, string $cachePolicy, int $seed): array {
    $evalPayload = [
        'provider'=>$config['provider'],
        'temperature'=>$config['temperature'],
        'promptStyle'=>$config['prompt_style'],
        'agentic'=>$config['agentic'] ?? ($config['provider'] !== 'baseline' && $config['provider'] !== 'non-agentic'),
        'cachePolicy'=>$cachePolicy,
        'seed'=>$seed,
    ];
    if (!empty($config['detailed_telemetry'])) {
        $evalPayload['detailedTelemetry'] = true;
    }
    $evalPayload['captureStageManifest'] = true;
    if (!empty($config['loop_telemetry'])) $evalPayload['loopTelemetry'] = true;
    if (!empty($config['reject_listicles'])) $evalPayload['rejectListicles'] = true;
    if (!empty($config['critique_web_rescrape'])) $evalPayload['critiqueWebRescrape'] = true;
    if (!empty($config['enforce_cache_policy'])) $evalPayload['enforceCachePolicy'] = true;
    if (isset($config['gpt_max_tokens'])) $evalPayload['gptMaxTokens'] = (int)$config['gpt_max_tokens'];
    if (!empty($config['trace'])) $evalPayload['trace'] = true;
    $headers = ['Content-Type: application/json'];

    $dsKey = trim((string)(getenv('DEEPSEEK_API_KEY') ?: (defined('DEEPSEEK_API_KEY') ? DEEPSEEK_API_KEY : '')));
    if ($dsKey !== '') {
        $evalPayload['deepseek_api_key'] = $dsKey;
        $headers[] = 'X-DeepSeek-Api-Key: ' . $dsKey;
    }
    $oaKey = trim((string)(getenv('OPENAI_API_KEY') ?: (defined('OPENAI_API_KEY') ? OPENAI_API_KEY : '')));
    if ($oaKey !== '') {
        $evalPayload['openai_api_key'] = $oaKey;
        $headers[] = 'X-OpenAI-Api-Key: ' . $oaKey;
    }

    $body = json_encode([
        'query'=>$query,
        'history'=>$history,
        'shownNames'=>$shownNames,
        'evaluation'=>$evalPayload,
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);

    $timeoutConfig = getTimeoutConfig();
    $timeoutThreshold = $apiUrl === 'local' ? $timeoutConfig['php_max_execution'] : $timeoutConfig['curl'];

    if ($apiUrl === 'local') {
        $apiFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'api.php';
        $phpBin = PHP_BINARY ?: 'php';
        $runnerCode = '$_SERVER["REQUEST_METHOD"]="POST"; $_SERVER["REMOTE_ADDR"]="127.0.0.1"; chdir(' . var_export(dirname($apiFile), true) . '); require ' . var_export($apiFile, true) . ';';
        $startedAt = microtime(true);
        $pipes = [];
        $proc = proc_open([$phpBin, '-r', $runnerCode], [0=>['pipe', 'r'], 1=>['pipe', 'w'], 2=>['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            [$reason, $rawMsg] = classifyErrorReason('Could not launch local PHP CLI worker.', 500, null, '');
            return ['latency'=>0.0, 'failure'=>'Could not launch local PHP CLI worker.', 'error_reason'=>$reason, 'raw_message'=>$rawMsg, 'http_status'=>0, 'timeout_threshold_s'=>$timeoutThreshold, 'data'=>null];
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
            $failureMsg = 'Local CLI worker returned empty output: ' . trim((string)$stderr);
            [$reason, $rawMsg] = classifyErrorReason($failureMsg, 500, null, (string)$stderr);
            return ['latency'=>$latency, 'failure'=>$failureMsg, 'error_reason'=>$reason, 'http_status'=>500, 'timeout_threshold_s'=>$timeoutThreshold, 'data'=>null];
        }
        $status = $exitCode === 0 ? 200 : 500;
    } else {
        $handle = curl_init($apiUrl);
        if ($handle === false) throw new RuntimeException('Could not initialize cURL.');
        curl_setopt_array($handle, [
            CURLOPT_POST=>true,
            CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_POSTFIELDS=>$body,
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_CONNECTTIMEOUT=>$timeoutConfig['curl_connect'],
            CURLOPT_TIMEOUT=>$timeoutConfig['curl'],
        ]);

        $startedAt = microtime(true);
        $raw = curl_exec($handle);
        $latency = microtime(true) - $startedAt;
        $status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $curlError = curl_error($handle);
        curl_close($handle);

        if ($raw === false) {
            $failureMsg = 'Connection failed: ' . $curlError;
            [$reason, $rawMsg] = classifyErrorReason($failureMsg, $status, null, $raw);
            return ['latency'=>$latency, 'failure'=>$failureMsg, 'error_reason'=>$reason, 'http_status'=>$status, 'timeout_threshold_s'=>$timeoutThreshold, 'data'=>null];
        }
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        $failureMsg = "Invalid JSON response (HTTP {$status}).";
        [$reason, $rawMsg] = classifyErrorReason($failureMsg, $status, null, $raw);
        return ['latency'=>$latency, 'failure'=>$failureMsg, 'error_reason'=>$reason, 'raw_message'=>$rawMsg, 'http_status'=>$status, 'timeout_threshold_s'=>$timeoutThreshold, 'data'=>null];
    }
    if ($status < 200 || $status >= 300) {
        $failureMsg = (string)($data['error'] ?? "HTTP {$status}.");
        [$reason, $rawMsg] = classifyErrorReason($failureMsg, $status, $data['error'] ?? null, $raw);
        return ['latency'=>$latency, 'failure'=>$failureMsg, 'error_reason'=>$reason, 'raw_message'=>$rawMsg, 'http_status'=>$status, 'timeout_threshold_s'=>$timeoutThreshold, 'data'=>$data];
    }
    if (($data['assistantStatus'] ?? '') !== 'ready') {
        $provider = (string)($config['provider'] ?? 'deepseek');
        $providerError = $data['metrics']['deepseek_api']['error']
            ?? $data['metrics']['deepseek']['error']
            ?? $data['metrics']['gpt4o']['error']
            ?? null;
        if (is_array($providerError)) {
            $http = (int)($providerError['http'] ?? $providerError['code'] ?? 0);
            $message = trim((string)($providerError['message'] ?? $providerError['error'] ?? ''));
            $statusMessage = 'Provider failed'
                . ($http > 0 ? ' with HTTP ' . $http : '')
                . ($message !== '' ? ': ' . mb_substr($message, 0, 240) : '')
                . '.';
        } else {
            $statusMessage = match ($data['assistantStatus'] ?? '') {
                'not_configured' => match ($provider) {
                    'gpt-4o-mini', 'hybrid' => 'OpenAI is not configured: set OPENAI_API_KEY on the PHP server.',
                    'deepseek-api' => 'DeepSeek API is not configured: set DEEPSEEK_API_KEY on the PHP server.',
                    default => 'DeepSeek R1 is not configured: run Ollama or set DEEPSEEK_API_KEY.',
                },
                'error' => match ($provider) {
                    'gpt-4o-mini' => 'GPT-4o mini pipeline failed; check the PHP server error log.',
                    'hybrid' => 'Hybrid pipeline failed; check the PHP server error log.',
                    'deepseek-api' => 'DeepSeek API failed; check the PHP server error log.',
                    default => 'DeepSeek R1 failed; check Ollama or PHP server error log.',
                },
                default => 'The API did not report a ready response.',
            };
        }
        [$reason, $rawMsg] = classifyErrorReason($statusMessage, $status, $providerError, $raw);
        return ['latency'=>$latency, 'failure'=>$statusMessage, 'error_reason'=>$reason, 'raw_message'=>$rawMsg, 'http_status'=>$status, 'timeout_threshold_s'=>$timeoutThreshold, 'data'=>$data];
    }
    return ['latency'=>$latency, 'failure'=>null, 'error_reason'=>null, 'raw_message'=>null, 'http_status'=>$status, 'timeout_threshold_s'=>$timeoutThreshold, 'data'=>$data];
}

function sendSingleApiRequest(string $apiUrl, string $query, array $history, array $shownNames, array $config, string $cachePolicy, int $seed): array {
    $isGptPipeline = in_array($config['provider'], ['gpt-4o-mini', 'hybrid'], true);
    $maxRetries = (int)($isGptPipeline ? ($config['gpt_retries'] ?? 0) : 0);
    $backoffMs = max(0, (int)($config['retry_backoff_ms'] ?? 250));
    $response = null;
    $attempt = 0;
    do {
        $response = sendSingleApiRequestOnce($apiUrl, $query, $history, $shownNames, $config, $cachePolicy, $seed);
        $gptError = $response['data']['metrics']['gpt4o']['error'] ?? null;
        $retryable = $config['provider'] === 'gpt-4o-mini'
            ? $response['failure'] !== null
            : ($config['provider'] === 'hybrid' && is_array($gptError));
        if (!$retryable || $attempt >= $maxRetries) break;
        if ($backoffMs > 0) usleep($backoffMs * (1 << min($attempt, 10)) * 1000);
        $attempt++;
    } while (true);

    $response['retry_count'] = $attempt;
    if (is_array($response['data'] ?? null)) {
        $response['data']['metrics']['gpt_retry_count'] = $attempt;
    }
    return $response;
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

function computeAnnotatorAgreementReport(string $evalDir): array {
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

    return [
        'source'=>'awaiting_real_human_annotators',
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
    $sourceManifest = buildSourceTreeManifest($appRoot);
    $promptVersionHashes = buildPromptVersionHashes($sourceManifest['files']);
    $deepSeekBackend = DEEPSEEK_API_KEY !== '' ? 'hosted' : 'ollama';
    $modelPricing = [
        'deepseek_local'=>[
            'model'=>DEEPSEEK_MODEL,
            'backend'=>$deepSeekBackend,
            'endpoint'=>sanitizedEndpoint(DEEPSEEK_BASE_URL),
            'rates'=>getModelTokenRates($deepSeekBackend === 'hosted' ? 'deepseek_hosted' : 'ollama', DEEPSEEK_MODEL),
        ],
        'deepseek_api'=>[
            'model'=>DEEPSEEK_API_MODEL,
            'backend'=>'hosted',
            'endpoint'=>sanitizedEndpoint(DEEPSEEK_API_BASE_URL),
            'rates'=>getModelTokenRates('deepseek_hosted', DEEPSEEK_API_MODEL),
        ],
        'gpt4o'=>[
            'model'=>OPENAI_MODEL,
            'backend'=>'hosted',
            'endpoint'=>'https://api.openai.com',
            'rates'=>getModelTokenRates('openai', OPENAI_MODEL),
        ],
    ];

    if ($options['split'] !== 'all') {
        $allCases = array_values(array_filter($allCases, fn($c) => $c['split'] === $options['split']));
        $allScopeCases = array_values(array_filter($allScopeCases, fn($sc) => $sc['split'] === $options['split']));
    }
    if ($options['trace_case'] !== '') {
        $allCases = array_values(array_filter($allCases, fn($c) => $c['id'] === $options['trace_case']));
        $allScopeCases = array_values(array_filter($allScopeCases, fn($sc) => $sc['id'] === $options['trace_case']));
        if (!$allCases && !$allScopeCases) fail("Trace case '{$options['trace_case']}' is not in the selected split.");
    }
    if ($options['limit'] > 0) {
        $allCases = array_slice($allCases, 0, $options['limit']);
        $allScopeCases = array_slice($allScopeCases, 0, max(1, intdiv($options['limit'], 3)));
    }
    if (!$allCases && !$allScopeCases) fail("No ground-truth cases matched split='{$options['split']}'.");

    $selectedDevConfig = null;
    $selectionReportHash = null;
    if ($options['final_run']) {
        $selectionPath = realpath($options['selection_report']);
        if ($selectionPath === false || !is_file($selectionPath)) fail('Dev selection report not found: ' . $options['selection_report']);
        $selectionData = json_decode((string)file_get_contents($selectionPath), true);
        if (!is_array($selectionData)
            || ($selectionData['reproducibility_manifest']['split'] ?? '') !== 'dev'
            || !is_array($selectionData['best_config_selection']['selected_config'] ?? null)) {
            fail('The final run requires a valid dev report with a selected_config entry.');
        }
        $selectedDevConfig = $selectionData['best_config_selection']['selected_config'];
        $selectionReportHash = hash_file('sha256', $selectionPath);
    }

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

    if ($options['deepseek_repeat_identical']) {
        $configurations = [
            ['provider'=>'deepseek', 'id'=>'DEEPSEEK-BASELINE-T0', 'temperature'=>0.0, 'top_p'=>1.0, 'prompt_style'=>'baseline', 'agentic'=>true],
            ['provider'=>'deepseek', 'id'=>'DEEPSEEK-STRICT-T0', 'temperature'=>0.0, 'top_p'=>1.0, 'prompt_style'=>'strict_grounding', 'agentic'=>true],
        ];
    }
    if ($options['final_run']) {
        $systemIds = [
            'baseline'=>'BASELINE-LOCAL',
            'ablation-no-loop'=>'ABLATION-NO-LOOP',
            'agentic-local'=>'AGENTIC-LOOP-LOCAL',
            'non-agentic'=>'NON-AGENTIC',
            'deepseek'=>'DEEPSEEK',
            'gpt-4o-mini'=>'GPT-4O-MINI',
            'hybrid'=>'HYBRID',
        ];
        foreach ($configurations as &$finalConfig) {
            $finalConfig['id'] = $systemIds[$finalConfig['provider']] ?? $finalConfig['id'];
        }
        unset($finalConfig);
        $selectedDevConfig['id'] = 'SELECTED-DEV-' . (string)($selectedDevConfig['id'] ?? 'CONFIG');
        $selectedDevConfig['provider'] = strtolower((string)($selectedDevConfig['provider'] ?? ''));
        if (!in_array($selectedDevConfig['provider'], ['deepseek', 'deepseek-api', 'gpt-4o-mini', 'hybrid'], true)) {
            fail('The selected dev configuration has an unsupported provider.');
        }
        $selectedDevConfig['agentic'] = (bool)($selectedDevConfig['agentic'] ?? true);
        $selectedDevConfig['top_p'] = (float)($selectedDevConfig['top_p'] ?? 1.0);
        $configurations[] = $selectedDevConfig;
    }
    if ($options['dev_grid'] && count($configurations) !== 22) {
        fail('The dev grid must contain exactly 22 configurations.');
    }
    foreach ($configurations as &$configForRun) {
        $configForRun['detailed_telemetry'] = $options['detailed_telemetry'];
        $configForRun['loop_telemetry'] = $options['loop_telemetry'];
        $configForRun['reject_listicles'] = $options['reject_listicles'];
        $configForRun['critique_web_rescrape'] = $options['critique_web_rescrape'];
        $configForRun['gpt_retries'] = $options['gpt_retries'];
        $configForRun['retry_backoff_ms'] = $options['retry_backoff_ms'];
        $configForRun['gpt_max_tokens'] = $options['gpt_max_tokens'];
        $configForRun['trace'] = $options['trace_case'] !== '';
        $configForRun['enforce_cache_policy'] = $options['cache_policy_explicit'] || $options['final_run'];
    }
    unset($configForRun);
    if ($options['randomize_config_order']) {
        $configurations = shuffleConfigurationsDeterministic($configurations, $options['seed']);
    }
    if ($options['trace_case'] !== '' && count($configurations) !== 1) {
        fail('--trace requires exactly one configuration; use --provider=... or a one-system --research run.');
    }
    $configurationOrder = array_values(array_map(fn($c) => (string)$c['id'], $configurations));
    $coldCacheClearedFiles = 0;
    if ($options['cache_policy'] === 'cold') {
        $coldCacheClearedFiles = clearEvidenceCache($appRoot . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . '.web-cache');
    }

    $agreementReport = computeAnnotatorAgreementReport(__DIR__);

    $inScopeRetrievalCases = array_values(array_filter($allCases, fn($c) => empty($c['expect_out_of_scope'])));
    $totalCasesPerTrial = count($allCases) + count($allScopeCases);
    $listicleComparisonCaseCount = $options['compare_listicle_filter']
        ? count(array_filter($allCases, fn($case) => in_array((string)$case['id'], ['Q036', 'D014'], true)))
        : 0;
    $totalExpectedRequests = ($totalCasesPerTrial + $listicleComparisonCaseCount) * count($configurations) * $options['trials'];
    $trials = $options['trials'];

    echo "====================================================================================================\n";
    echo " Davao Food Finder Comprehensive Evaluation & Agentic Intent Benchmark (v3.0)\n";
    echo "====================================================================================================\n";
    echo 'API Endpoint       : ' . $options['api_url'] . "\n";
    echo 'Ground Truth       : ' . basename($groundTruthPath) . ' (v' . $validatedGt['metadata']['version'] . ', sha256=' . substr($groundTruthHash, 0, 12) . "...)\n";
    echo 'Benchmark Split    : ' . $options['split'] . ' (' . count($allCases) . ' in-scope/multi-turn cases + ' . count($allScopeCases) . " scope cases)\n";
    echo 'Systems / Providers: ' . implode(', ', $options['providers']) . "\n";
    echo 'Configurations     : ' . count($configurations) . " | Trials/config: {$trials} | Seed: {$options['seed']} | Cache policy: {$options['cache_policy']}\n";
    echo 'Config order       : ' . implode(' -> ', $configurationOrder) . "\n";
    echo 'Total HTTP Requests: ' . $totalExpectedRequests . "\n";
    if ($options['cache_policy'] === 'cold') echo "Cold cache cleared : {$coldCacheClearedFiles} evidence cache files\n";
    if ($options['final_run']) echo 'Selected dev config: ' . $selectedDevConfig['id'] . " (loaded; not selected on test)\n";
    $timeoutCfg = getTimeoutConfig();
    echo sprintf(
        "Timeouts           : PHP max=%ds | cURL connect=%ds, total=%ds | LLM=%ds | Ollama connect=%ds | Playwright proc=%ds, step=%dms\n",
        $timeoutCfg['php_max_execution'],
        $timeoutCfg['curl_connect'],
        $timeoutCfg['curl'],
        $timeoutCfg['llm'],
        $timeoutCfg['ollama_connect'],
        $timeoutCfg['playwright_proc'],
        $timeoutCfg['playwright_step_ms']
    );
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
    if ($options['compare_listicle_filter']) {
        echo "Listicle comparison: enabled; paired requests for Q036/D014 are separate from scored trials\n";
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
    $errorCaseIndex = [];
    $requestAuditByConfig = [];
    $listicleComparisonResults = [];
    $listicleComparisonRequestAudit = [];
    $anyAiFailure = false;

    $providerErrorBreakdown = [];
    foreach ($options['providers'] as $pName) {
        $providerErrorBreakdown[$pName] = [
            'timeout' => 0,
            'ollama_unreachable' => 0,
            'empty_response' => 0,
            'invalid_json' => 0,
            'schema_validation' => 0,
            'php_exception' => 0,
            'http_error' => 0,
            'total_errors' => 0,
            'total_requests' => 0,
        ];
    }
    $hybridCurationStats = [
        'total_requests' => 0,
        'invoked_count' => 0,
        'invoked_percent' => 0.0,
        'triggers' => [],
    ];

    foreach ($configurations as $config) {
        $config['detailed_telemetry'] = $options['detailed_telemetry'];
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
        $totalRetries = 0;
        $playwrightSuccesses = 0;
        $playwrightRequests = 0;
        $playwrightCacheHits = 0;
        $playwrightCacheMisses = 0;
        $playwrightLiveFetched = 0;
        $playwrightCachedEvidence = 0;
        $playwrightEvidenceCount = 0;
        $listicleRejectedTotal = 0;
        $providerSuccesses = 0;
        $providerRequests = 0;

        // Circuit breaker: abort run after N consecutive provider errors with identical signature
        $circuitBreakerLimit = 5;
        $consecutiveErrors = 0;
        $lastErrorMessage = '';
        $circuitBroken = false;

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
        $requestAudit = [];

        for ($trial = 1; $trial <= $effectiveTrials; $trial++) {
            $trialSeed = $options['seed'] + ($trial - 1) * 1009;
            if ($effectiveTrials > 1) {
                echo "  [Trial {$trial}/{$effectiveTrials} | seed={$trialSeed}]\n";
            }

            foreach ($allCases as $index => $case) {
                $totalExecutedRequests++;
                $pName = (string)$config['provider'];
                if (!isset($providerErrorBreakdown[$pName])) {
                    $providerErrorBreakdown[$pName] = [
                        'timeout' => 0, 'ollama_unreachable' => 0, 'empty_response' => 0,
                        'invalid_json' => 0, 'schema_validation' => 0, 'php_exception' => 0,
                        'http_error' => 0, 'total_errors' => 0, 'total_requests' => 0,
                    ];
                }
                $providerErrorBreakdown[$pName]['total_requests']++;

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
                if ($options['compare_listicle_filter'] && in_array((string)$case['id'], ['Q036', 'D014'], true)) {
                    $currentResults = mergeRecommendations(
                        is_array($data['results'] ?? null) ? $data['results'] : [],
                        is_array($data['webResults'] ?? null) ? $data['webResults'] : [],
                        true
                    );
                    $currentTop5 = array_values(array_map(
                        static fn($item) => (string)($item['name'] ?? ''),
                        array_slice($currentResults, 0, 5)
                    ));
                    $comparisonConfig = $config;
                    $comparisonConfig['reject_listicles'] = !$options['reject_listicles'];
                    $comparisonResponse = executeCaseEvaluation(
                        $options['api_url'],
                        $case,
                        $comparisonConfig,
                        $options['cache_policy'],
                        $trialSeed,
                        $options['live_turns']
                    );
                    $comparisonData = is_array($comparisonResponse['data'] ?? null) ? $comparisonResponse['data'] : [];
                    $comparisonResults = mergeRecommendations(
                        is_array($comparisonData['results'] ?? null) ? $comparisonData['results'] : [],
                        is_array($comparisonData['webResults'] ?? null) ? $comparisonData['webResults'] : [],
                        true
                    );
                    $comparisonTop5 = array_values(array_map(
                        static fn($item) => (string)($item['name'] ?? ''),
                        array_slice($comparisonResults, 0, 5)
                    ));
                    $comparisonMetrics = is_array($comparisonData['metrics'] ?? null) ? $comparisonData['metrics'] : [];
                    $comparisonPlaywright = is_array($comparisonMetrics['playwright'] ?? null) ? $comparisonMetrics['playwright'] : [];
                    $beforeTop5 = $options['reject_listicles'] ? $comparisonTop5 : $currentTop5;
                    $afterTop5 = $options['reject_listicles'] ? $currentTop5 : $comparisonTop5;
                    $beforeRejected = $options['reject_listicles'] ? 0 : (int)($playwright['listicle_rejected_count'] ?? 0);
                    $afterRejected = $options['reject_listicles']
                        ? (int)($playwright['listicle_rejected_count'] ?? 0)
                        : (int)($comparisonPlaywright['listicle_rejected_count'] ?? 0);
                    $listicleComparisonResults[$config['id']][(string)$case['id']][$trial] = [
                        'before_filter_top5'=>$beforeTop5,
                        'after_filter_top5'=>$afterTop5,
                        'before_filter_rejected_count'=>$beforeRejected,
                        'after_filter_rejected_count'=>$afterRejected,
                        'comparison_request_failure'=>$comparisonResponse['failure'] ?? null,
                    ];
                    $listicleComparisonRequestAudit[] = extractRequestTelemetry(
                        (string)$case['id'],
                        $config['id'] . ':listicle-filter-comparison',
                        $trial,
                        $comparisonResponse,
                        $options['detailed_telemetry']
                    );
                }
                $listicleRejectedTotal += (int)($playwright['listicle_rejected_count'] ?? 0);
                $requestAudit[] = extractRequestTelemetry($case['id'], $config['id'], $trial, $response, $options['detailed_telemetry']);
                $requestRetries = (int)($metrics['deepseek']['retries'] ?? 0) + max((int)($metrics['gpt_retry_count'] ?? 0), (int)($response['retry_count'] ?? 0));
                $totalRetries += $requestRetries;

                $datasetMsList[] = (float)($timings['dataset_total_ms'] ?? 0);
                $playwrightMsList[] = (float)($timings['playwright_ms'] ?? ($playwright['latency_ms'] ?? 0));
                $aiMsList[] = (float)($timings['ai_inference_ms'] ?? 0);
                $costList[] = (float)($usageInfo['estimated_cost_usd'] ?? 0.0);
                $totalTokensSum += (int)($usageInfo['total_tokens'] ?? 0);

                $pwCache = (string)($cacheInfo['playwright'] ?? ($playwright['cache'] ?? 'unknown'));
                if ($pwCache === 'hit') {
                    $warmLatencies[] = $latency;
                    $playwrightCacheHits++;
                    $playwrightCachedEvidence++;
                } elseif ($pwCache === 'miss') {
                    $coldLatencies[] = $latency;
                    $playwrightCacheMisses++;
                    if (($playwright['success'] ?? false) === true) $playwrightLiveFetched++;
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
                    $errorReason = $response['error_reason'] ?? 'http_error';
                    $rawMsg = $response['raw_message'] ?? mb_substr($failure, 0, 300);
                    $errorStage = failureStageFromMetrics($metrics, (string)$failure);
                    $errorCaseIndex[] = [
                        'case_id'=>$case['id'],
                        'config_id'=>$config['id'],
                        'trial'=>$trial,
                        'error_type'=>$errorReason,
                        'stage'=>$errorStage,
                        'http_code'=>providerHttpCode($metrics, (int)($response['http_status'] ?? 0)),
                        'timeout_threshold_s'=>timeoutThresholdForError($response, $errorReason, $errorStage),
                    ];
                    $providerErrorBreakdown[$pName][$errorReason]++;
                    $providerErrorBreakdown[$pName]['total_errors']++;

                    // Circuit Breaker tracking
                    $consecutiveErrors = ($rawMsg === $lastErrorMessage && $lastErrorMessage !== '') ? ($consecutiveErrors + 1) : 1;
                    $lastErrorMessage = $rawMsg;

                    $record = [
                        'trial'=>$trial,
                        'failed'=>true,
                        'failure'=>$failure,
                        'error_reason'=>$errorReason,
                        'raw_message'=>$rawMsg,
                        'latency'=>$latency,
                        'combined'=>['precision_at_5'=>0.0, 'recall_at_5'=>0.0, 'recall_at_10'=>0.0, 'ndcg_at_5'=>0.0, 'mrr'=>0.0],
                        'local'=>['precision_at_5'=>0.0, 'recall_at_5'=>0.0, 'recall_at_10'=>0.0, 'ndcg_at_5'=>0.0, 'mrr'=>0.0],
                        'web'=>['precision_at_5'=>0.0, 'recall_at_5'=>0.0, 'recall_at_10'=>0.0, 'ndcg_at_5'=>0.0, 'mrr'=>0.0, 'returned_count'=>0],
                        'constraint'=>['davao_only_pass'=>false, 'strict_pass'=>false, 'item_pass_rate'=>0.0],
                        'validation'=>['factual_correctness_rate'=>0.0, 'evidence_support_rate'=>0.0, 'web_source_support_rate'=>null, 'source_fresh'=>false],
                        'scope_passed'=>false,
                        'followup_passed'=>false,
                        'top5_names'=>[],
                        'top5_returned'=>[],
                    ];
                    $perCaseTrialRecords[$case['id']][] = $record;
                    printf("  [%d/%d] %-6s (%-16s) | ERROR [%s]: %s (%.2fs)\n", $index + 1, count($allCases), $case['id'], $case['category'], $errorReason, $failure, $latency);

                    if ($consecutiveErrors >= $circuitBreakerLimit) {
                        fwrite(STDERR, "\n" . str_repeat('!', 80) . "\n");
                        fwrite(STDERR, sprintf("  [CIRCUIT BREAKER TRIGGERED] Provider '%s' aborted after %d consecutive identical errors:\n    [%s] %s\n", $config['provider'], $consecutiveErrors, $errorReason, $rawMsg));
                        fwrite(STDERR, str_repeat('!', 80) . "\n\n");
                        $circuitBroken = true;
                        break 2;
                    }
                    continue;
                }

                // Reset circuit breaker on successful request
                $consecutiveErrors = 0;
                $lastErrorMessage = '';
                $caseRetries = $requestRetries;

                if ($config['provider'] === 'hybrid' && is_array($data['metrics']['deepseek_curation'] ?? null)) {
                    $cur = $data['metrics']['deepseek_curation'];
                    $hybridCurationStats['total_requests']++;
                    if (!empty($cur['invoked'])) {
                        $hybridCurationStats['invoked_count']++;
                        $trig = (string)($cur['trigger'] ?? 'unspecified');
                        $hybridCurationStats['triggers'][$trig] = ($hybridCurationStats['triggers'][$trig] ?? 0) + 1;
                    }
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
                $loopAttribution = null;
                if ($options['loop_telemetry'] && is_array($agenticLoopInfo['initial_top5_names'] ?? null) && is_array($agenticLoopInfo['final_top5_names'] ?? null)) {
                    $initialLoopRank = computeRankingMetrics(
                        array_map(static fn($name) => ['name'=>$name], $agenticLoopInfo['initial_top5_names']),
                        $case['relevance_grades'],
                        $case['base_relevance_grades'],
                        $case['constraints'],
                        true
                    );
                    $finalLoopRank = computeRankingMetrics(
                        array_map(static fn($name) => ['name'=>$name], $agenticLoopInfo['final_top5_names']),
                        $case['relevance_grades'],
                        $case['base_relevance_grades'],
                        $case['constraints'],
                        true
                    );
                    $loopAttribution = [
                        'initial_p5'=>$initialLoopRank['precision_at_5'],
                        'final_p5'=>$finalLoopRank['precision_at_5'],
                        'p5_change'=>$finalLoopRank['precision_at_5'] - $initialLoopRank['precision_at_5'],
                        'initial_ndcg5'=>$initialLoopRank['ndcg_at_5'],
                        'final_ndcg5'=>$finalLoopRank['ndcg_at_5'],
                        'ndcg5_change'=>$finalLoopRank['ndcg_at_5'] - $initialLoopRank['ndcg_at_5'],
                    ];
                }

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
                    'loop_attribution'=>$loopAttribution,
                    'scope_passed'=>!$falseOutOfScope && $constraintEval['davao_only_pass'],
                    'followup_passed'=>$followupPassed,
                    'top5_names'=>$top5Names,
                    'top5_returned'=>$top5Display,
                    'sample_answer'=>$trial === 1 ? mb_substr((string)($data['answer'] ?? ''), 0, 600) : null,
                    'sample_top5_display'=>$trial === 1 ? $top5Display : [],
                ];
                $perCaseTrialRecords[$case['id']][] = $record;

                $retryTag = $caseRetries > 0 ? " [RETRY: {$caseRetries}]" : "";
                printf(
                    "  [%d/%d] %-6s (%-16s) | P@5 %5.1f%% | NDCG@5 %5.1f%% | MRR %.2f | Constr %5.1f%% | Faith %5.1f%% | %.2fs (%s)%s\n",
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
                    $pwCache,
                    $retryTag
                );
            }

            if ($circuitBroken) {
                break;
            }

            foreach ($allScopeCases as $scopeCase) {
                $totalExecutedRequests++;
                $pName = (string)$config['provider'];
                if (!isset($providerErrorBreakdown[$pName])) {
                    $providerErrorBreakdown[$pName] = [
                        'timeout' => 0, 'ollama_unreachable' => 0, 'empty_response' => 0,
                        'invalid_json' => 0, 'schema_validation' => 0, 'php_exception' => 0,
                        'http_error' => 0, 'total_errors' => 0, 'total_requests' => 0,
                    ];
                }
                $providerErrorBreakdown[$pName]['total_requests']++;

                $response = sendSingleApiRequest($options['api_url'], $scopeCase['query'], [], [], $config, $options['cache_policy'], $trialSeed);
                $latency = (float)$response['latency'];
                $allLatencies[] = $latency;
                $failure = $response['failure'];
                $data = $response['data'];

                $metrics = is_array($data['metrics'] ?? null) ? $data['metrics'] : [];
                $timings = is_array($metrics['timings_ms'] ?? null) ? $metrics['timings_ms'] : [];
                $usageInfo = is_array($metrics['usage'] ?? null) ? $metrics['usage'] : [];
                $cacheInfo = is_array($metrics['cache'] ?? null) ? $metrics['cache'] : [];
                $playwright = is_array($metrics['playwright'] ?? null) ? $metrics['playwright'] : [];
                $listicleRejectedTotal += (int)($playwright['listicle_rejected_count'] ?? 0);
                $datasetMsList[] = (float)($timings['dataset_total_ms'] ?? 0);
                $playwrightMsList[] = (float)($timings['playwright_ms'] ?? 0);
                $aiMsList[] = (float)($timings['ai_inference_ms'] ?? 0);
                $costList[] = (float)($usageInfo['estimated_cost_usd'] ?? 0.0);
                $totalTokensSum += (int)($usageInfo['total_tokens'] ?? 0);
                $requestAudit[] = extractRequestTelemetry($scopeCase['id'], $config['id'], $trial, $response, $options['detailed_telemetry']);
                $requestRetries = (int)($metrics['deepseek']['retries'] ?? 0) + max((int)($metrics['gpt_retry_count'] ?? 0), (int)($response['retry_count'] ?? 0));
                $totalRetries += $requestRetries;
                $scopeCache = (string)($cacheInfo['playwright'] ?? ($playwright['cache'] ?? 'unknown'));
                if ($scopeCache === 'hit') {
                    $playwrightCacheHits++;
                    $playwrightCachedEvidence++;
                } elseif ($scopeCache === 'miss') {
                    $playwrightCacheMisses++;
                    if (($playwright['success'] ?? false) === true) $playwrightLiveFetched++;
                }

                if ($config['provider'] !== 'baseline') {
                    $providerRequests++;
                    if ($failure === null) $providerSuccesses++;
                }

                if ($failure !== null) {
                    $failures++;
                    if ($config['provider'] !== 'baseline') $anyAiFailure = true;
                    $errorReason = $response['error_reason'] ?? 'http_error';
                    $rawMsg = $response['raw_message'] ?? mb_substr($failure, 0, 300);
                    $errorStage = failureStageFromMetrics($metrics, (string)$failure);
                    $errorCaseIndex[] = [
                        'case_id'=>$scopeCase['id'],
                        'config_id'=>$config['id'],
                        'trial'=>$trial,
                        'error_type'=>$errorReason,
                        'stage'=>$errorStage,
                        'http_code'=>providerHttpCode($metrics, (int)($response['http_status'] ?? 0)),
                        'timeout_threshold_s'=>timeoutThresholdForError($response, $errorReason, $errorStage),
                    ];
                    $providerErrorBreakdown[$pName][$errorReason]++;
                    $providerErrorBreakdown[$pName]['total_errors']++;

                    $consecutiveErrors = ($rawMsg === $lastErrorMessage && $lastErrorMessage !== '') ? ($consecutiveErrors + 1) : 1;
                    $lastErrorMessage = $rawMsg;

                    $perScopeTrialRecords[$scopeCase['id']][] = [
                        'trial'=>$trial,
                        'failed'=>true,
                        'failure'=>$failure,
                        'error_reason'=>$errorReason,
                        'raw_message'=>$rawMsg,
                        'scope_passed'=>false,
                        'latency'=>$latency
                    ];
                    printf("  [Scope %-4s] ERROR [%s]: %s (%.2fs)\n", $scopeCase['id'], $errorReason, $failure, $latency);

                    if ($consecutiveErrors >= $circuitBreakerLimit) {
                        fwrite(STDERR, "\n" . str_repeat('!', 80) . "\n");
                        fwrite(STDERR, sprintf("  [CIRCUIT BREAKER TRIGGERED] Provider '%s' aborted after %d consecutive identical errors:\n    [%s] %s\n", $config['provider'], $consecutiveErrors, $errorReason, $rawMsg));
                        fwrite(STDERR, str_repeat('!', 80) . "\n\n");
                        $circuitBroken = true;
                        break 2;
                    }
                    continue;
                }

                // Reset circuit breaker on success
                $consecutiveErrors = 0;
                $lastErrorMessage = '';
                $scopeRetries = $requestRetries;

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
        $caseP5FailuresExcluded = [];
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
        $loopEnabledRequestCount = 0;
        $loopCauseCounts = ['violation_repair'=>0, 'shortfall_top_up'=>0];
        $loopCauseP5Changes = ['violation_repair'=>[], 'shortfall_top_up'=>[]];
        $loopCauseNdcgChanges = ['violation_repair'=>[], 'shortfall_top_up'=>[]];

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
            $successfulP5 = array_values(array_map(
                fn($r) => (float)($r['combined']['precision_at_5'] ?? 0.0),
                array_filter($tRecs, fn($r) => empty($r['failed']))
            ));
            if ($successfulP5) $caseP5FailuresExcluded[] = meanVal($successfulP5);
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
                    $loopEnabledRequestCount++;
                    $loopInitialCompliance[] = (float)($al['initial_compliance_rate'] ?? 1.0);
                    $loopFinalCompliance[] = (float)($al['final_compliance_rate'] ?? 1.0);
                    $loopIterations[] = (float)($al['iterations'] ?? 1);
                    $loopReRetrieved[] = !empty($al['re_retrieved']) ? 1.0 : 0.0;
                    $loopViolationsDetectedTotal += count($al['violations_detected'] ?? []);
                    $loopViolationsRepairedTotal += (int)($al['violations_repaired'] ?? 0);
                    $causes = is_array($al['retrieval_causes'] ?? null) ? $al['retrieval_causes'] : [];
                    foreach (array_keys($loopCauseCounts) as $cause) {
                        if (!in_array($cause, $causes, true)) continue;
                        $loopCauseCounts[$cause]++;
                        if (is_array($tr['loop_attribution'] ?? null)) {
                            $loopCauseP5Changes[$cause][] = (float)$tr['loop_attribution']['p5_change'];
                            $loopCauseNdcgChanges[$cause][] = (float)$tr['loop_attribution']['ndcg5_change'];
                        }
                    }
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
        $stageManifest = [];
        foreach ($requestAudit as $requestTelemetry) {
            foreach (($requestTelemetry['stage_usage'] ?? []) as $stageUsage) {
                $stageName = (string)($stageUsage['stage'] ?? 'unknown');
                if (!isset($stageManifest[$stageName])) {
                    $stageManifest[$stageName] = [
                        'model_ids'=>[],
                        'input_tokens'=>0,
                        'output_tokens'=>0,
                        'reasoning_tokens'=>0,
                        'cost_usd'=>0.0,
                        'latency_ms'=>0,
                        'temperature_values'=>[],
                        'top_p_sent'=>[],
                        'max_tokens_sent'=>[],
                        'num_predict_values'=>[],
                    ];
                }
                $stageManifest[$stageName]['model_ids'][] = (string)($stageUsage['model_id'] ?? '');
                $stageManifest[$stageName]['input_tokens'] += (int)($stageUsage['input_tokens'] ?? 0);
                $stageManifest[$stageName]['output_tokens'] += (int)($stageUsage['output_tokens'] ?? 0);
                $stageManifest[$stageName]['reasoning_tokens'] += (int)($stageUsage['reasoning_tokens'] ?? 0);
                $stageManifest[$stageName]['cost_usd'] += (float)($stageUsage['cost_usd'] ?? 0.0);
                $stageManifest[$stageName]['latency_ms'] += (int)($stageUsage['latency_ms'] ?? 0);
                if (!empty($stageUsage['temperature_sent'])) $stageManifest[$stageName]['temperature_values'][] = $stageUsage['temperature'];
                $stageManifest[$stageName]['top_p_sent'][] = (bool)($stageUsage['top_p_sent'] ?? false);
                $stageManifest[$stageName]['max_tokens_sent'][] = (bool)($stageUsage['max_tokens_sent'] ?? false);
                if (!empty($stageUsage['num_predict_sent'])) $stageManifest[$stageName]['num_predict_values'][] = (int)$stageUsage['num_predict'];
            }
        }
        foreach ($stageManifest as &$stage) {
            $stage['model_ids'] = array_values(array_unique(array_filter($stage['model_ids'])));
            $stage['temperature_values'] = array_values(array_unique($stage['temperature_values'], SORT_REGULAR));
            $stage['top_p_sent'] = array_values(array_unique($stage['top_p_sent']));
            $stage['max_tokens_sent'] = array_values(array_unique($stage['max_tokens_sent']));
            $stage['num_predict_values'] = array_values(array_unique($stage['num_predict_values']));
            $stage['cost_usd'] = round($stage['cost_usd'], 8);
        }
        unset($stage);
        $repeatTrialP5Means = [];
        if ($options['deepseek_repeat_identical']) {
            for ($repeatTrial = 1; $repeatTrial <= $effectiveTrials; $repeatTrial++) {
                $trialScores = [];
                foreach ($allCases as $case) {
                    if (!empty($case['expect_out_of_scope'])) continue;
                    foreach (($perCaseTrialRecords[$case['id']] ?? []) as $trialRecord) {
                        if ((int)($trialRecord['trial'] ?? 0) !== $repeatTrial) continue;
                        $trialScores[] = (float)($trialRecord['combined']['precision_at_5'] ?? 0.0);
                        break;
                    }
                }
                if ($trialScores) $repeatTrialP5Means[] = meanVal($trialScores);
            }
        }
        $repeatP5NoiseSd = count($repeatTrialP5Means) >= 2 ? stdDevVal($repeatTrialP5Means) : null;

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
            'total_retries'=>$totalRetries,
            'retry_rate'=>$totalExecutedRequests > 0 ? ($totalRetries / $totalExecutedRequests) : 0.0,
            'p5'=>$ciP5,
            'p5_failures_excluded_mean'=>$caseP5FailuresExcluded ? meanVal($caseP5FailuresExcluded) : null,
            'p5_failure_scoring'=>[
                'failed_requests_as_zero'=>$ciP5['mean'],
                'failed_requests_excluded'=>$caseP5FailuresExcluded ? meanVal($caseP5FailuresExcluded) : null,
                'excluded_case_count'=>count($allCases) - count($caseP5FailuresExcluded),
                'failed_request_count'=>$failures,
            ],
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
                'cause_rates'=>[
                    'loop_enabled_requests'=>$loopEnabledRequestCount,
                    'causes'=>[
                        'violation_repair'=>[
                            'requests'=>$loopCauseCounts['violation_repair'],
                            'rate'=>$loopEnabledRequestCount > 0 ? $loopCauseCounts['violation_repair'] / $loopEnabledRequestCount : 0.0,
                            'mean_p5_change'=>$loopCauseP5Changes['violation_repair'] ? meanVal($loopCauseP5Changes['violation_repair']) : null,
                            'mean_ndcg5_change'=>$loopCauseNdcgChanges['violation_repair'] ? meanVal($loopCauseNdcgChanges['violation_repair']) : null,
                        ],
                        'shortfall_top_up'=>[
                            'requests'=>$loopCauseCounts['shortfall_top_up'],
                            'rate'=>$loopEnabledRequestCount > 0 ? $loopCauseCounts['shortfall_top_up'] / $loopEnabledRequestCount : 0.0,
                            'mean_p5_change'=>$loopCauseP5Changes['shortfall_top_up'] ? meanVal($loopCauseP5Changes['shortfall_top_up']) : null,
                            'mean_ndcg5_change'=>$loopCauseNdcgChanges['shortfall_top_up'] ? meanVal($loopCauseNdcgChanges['shortfall_top_up']) : null,
                        ],
                    ],
                    'overlapping_causes'=>true,
                ],
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
            'playwright_live_fetched'=>$playwrightLiveFetched,
            'playwright_cached_evidence'=>$playwrightCachedEvidence,
            'playwright_evidence_count'=>$playwrightEvidenceCount,
            'listicle_rejected_count'=>$listicleRejectedTotal,
            'stages_executed'=>array_keys($stageManifest),
            'stage_manifest'=>$stageManifest,
            'deepseek_repeat_trial_p5_means'=>$repeatTrialP5Means,
            'deepseek_repeat_p5_sample_sd'=>$repeatP5NoiseSd,
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
        $requestAuditByConfig[$config['id']] = $requestAudit;
        $detailedRuns[$config['id']] = [
            'cases'=>$perCaseTrialRecords,
            'scope_cases'=>$perScopeTrialRecords,
            'requests'=>$requestAudit,
        ];
    }

    $datasetHashList = [];
    foreach ($datasetHashes as $fileName => $fileHash) {
        $datasetHashList[$fileName] = $fileHash['sha256'];
    }
    $tableSourceHeader = 'SOURCE: script=evaluation/run_evaluation.php; function=metrics/exportTables1To5; inputs=ground_truth.json,data/*; ground_truth_sha256='
        . $groundTruthHash . '; dataset_sha256=' . json_encode($datasetHashList, JSON_UNESCAPED_SLASHES)
        . '; source_tree_sha256=' . $sourceManifest['combined_sha256'];

    // ==================== PRINT SUMMARY TABLES ====================
    echo "\n======================================================================================================================================\n";
    echo " TABLE 1: RANKING & INTENT MATCHING METRICS (Combined Web+Local, with 95% Bootstrap CIs, n=" . count($inScopeRetrievalCases) . " retrieval cases)\n";
    echo $tableSourceHeader . "\n";
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
    echo "\nLive-fetched vs cached evidence by config:\n";
    foreach ($summary as $row) {
        printf("  %-30s live-fetched=%d cached=%d listicle/aggregator-rejected=%d\n",
            $row['id'], $row['playwright_live_fetched'], $row['playwright_cached_evidence'], $row['listicle_rejected_count']);
    }
    if ($options['deepseek_repeat_identical']) {
        echo "DeepSeek identical-repeat noise (sample SD of per-trial mean P@5):\n";
        foreach ($summary as $row) {
            printf("  %-30s trial means=[%s] SD=%s\n",
                $row['id'],
                implode(', ', array_map(static fn($v) => sprintf('%.4f', $v), $row['deepseek_repeat_trial_p5_means'])),
                $row['deepseek_repeat_p5_sample_sd'] !== null ? sprintf('%.4f', $row['deepseek_repeat_p5_sample_sd']) : 'N/A (need >=2 trials)');
        }
    }
    if ($options['loop_telemetry']) {
        echo "Re-retrieval causes and within-request P@5/NDCG@5 change (cause groups can overlap):\n";
        foreach ($summary as $row) {
            $causes = $row['agentic_loop_stats']['cause_rates']['causes'];
            foreach ($causes as $cause => $stats) {
                printf("  %-30s %-18s rate=%.1f%% n=%d delta-P@5=%s delta-NDCG@5=%s\n",
                    $row['id'],
                    $cause,
                    $stats['rate'] * 100,
                    $stats['requests'],
                    $stats['mean_p5_change'] !== null ? sprintf('%+.3f', $stats['mean_p5_change']) : 'N/A',
                    $stats['mean_ndcg5_change'] !== null ? sprintf('%+.3f', $stats['mean_ndcg5_change']) : 'N/A');
            }
        }
    }
    echo "P@5 failure treatment (primary=failed requests scored as zero; alternate excludes failed requests and all-failed cases):\n";
    foreach ($summary as $row) {
        printf("  %-30s zero=%.1f%% excluded=%s failed=%d\n",
            $row['id'],
            $row['p5']['mean'] * 100,
            $row['p5_failures_excluded_mean'] !== null ? sprintf('%.1f%%', $row['p5_failures_excluded_mean'] * 100) : 'N/A',
            $row['failures']);
    }

    echo "\n======================================================================================================================================\n";
    echo " TABLE 2: CONSTRAINT ADHERENCE, SCOPE SAFETY, MULTI-TURN, FAITHFULNESS, RELIABILITY & COST\n";
    echo $tableSourceHeader . "\n";
    echo "======================================================================================================================================\n";
    printf(
        "%-22s | %-12s | %-10s | %-11s | %-12s | %-11s | %-11s | %-10s | %-8s | %-12s\n",
        'Config ID', 'Constr Item%', 'Scope Acc%', 'Follow-up%', 'Faithful%', 'Fact Corr%', 'Consistency', 'Error Rate', 'Retries', 'Cost/Query'
    );
    echo str_repeat('-', 145) . "\n";
    foreach ($summary as $row) {
        printf(
            "%-22s | %11.1f%% | %9.1f%% | %11s | %11.1f%% | %10.1f%% | %11s | %9.1f%% | %8d | %12s\n",
            $row['id'],
            $row['constraint_item_rate']['mean'] * 100,
            $row['scope_rejection_accuracy'] * 100,
            $row['followup_resolution_rate'] !== null ? sprintf('%.1f%%', $row['followup_resolution_rate'] * 100) : 'N/A',
            $row['faithfulness']['mean'] * 100,
            $row['factual_correctness']['mean'] * 100,
            $row['repeat_consistency'] !== null ? sprintf('%.1f%%', $row['repeat_consistency'] * 100) : 'N/A (1x)',
            $row['error_rate'] * 100,
            $row['total_retries'] ?? 0,
            sprintf('$%.6f', $row['mean_cost_usd'])
        );
    }

    echo "\n======================================================================================================================================\n";
    echo " TABLE 3: LATENCY DECOMPOSITION & CACHE BEHAVIOR (End-to-End S & Server Component MS)\n";
    echo $tableSourceHeader . "\n";
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
    echo $tableSourceHeader . "\n";
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
    echo $tableSourceHeader . "\n";
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

    // ==================== ERROR REASON BREAKDOWN ====================
    echo "\n======================================================================================================================================\n";
    echo " ERROR REASON BREAKDOWN PER PROVIDER\n";
    echo "======================================================================================================================================\n";
    printf(
        "%-16s | %-10s | %-10s | %-7s | %-16s | %-10s | %-12s | %-12s | %-10s | %-10s\n",
        'Provider', 'Total Req', 'Total Err', 'Timeout', 'Ollama Unreach', 'Empty Resp', 'Invalid JSON', 'Schema Valid', 'PHP Except', 'HTTP Error'
    );
    echo str_repeat('-', 134) . "\n";
    foreach ($providerErrorBreakdown as $pName => $eb) {
        printf(
            "%-16s | %10d | %10d | %7d | %16d | %10d | %12d | %12d | %10d | %10d\n",
            $pName,
            $eb['total_requests'],
            $eb['total_errors'],
            $eb['timeout'],
            $eb['ollama_unreachable'],
            $eb['empty_response'],
            $eb['invalid_json'],
            $eb['schema_validation'],
            $eb['php_exception'],
            $eb['http_error']
        );
    }

    $caseDiagnostics = buildCaseDiagnostics($groundTruthDecoded, $detailedRuns, $datasetIndex, $listicleComparisonResults);
    $scopeGuardAudit = buildScopeGuardAudit();

    echo "\n--- SCOPE-GUARD RULE AUDIT ---\n";
    echo 'Recognized out-of-city labels: ' . implode(', ', $scopeGuardAudit['recognized_locations']) . "\n";
    foreach ($scopeGuardAudit['test_scope_city_checks'] as $check) {
        printf("  %-18s | rejected=%s | matched rule=%s\n", $check['city'], $check['rejected'] ? 'yes' : 'no', $check['matched_rule'] ?? 'none');
    }

    if (isset($caseDiagnostics['D014'])) {
        $d014 = $caseDiagnostics['D014'];
        echo "\n--- D014 LOCATION-AREA DIAGNOSTIC ---\n";
        echo 'Checker expects one of: ' . implode(', ', $d014['constraints']['location_areas'] ?? []) . "\n";
        echo "Ground-truth relevant items (dataset address):\n";
        foreach ($d014['expected_relevant_items'] as $item) {
            printf("  - %s | %s\n", $item['name'], $item['address'] !== '' ? $item['address'] : '[address unavailable in dataset index]');
        }
        foreach ($d014['per_config_trials'] as $configId => $trialsForConfig) {
            foreach ($trialsForConfig as $trialInfo) {
                printf(
                    "  %s trial %d | location_area score=%s | failed_dimensions=%s | returned=%s\n",
                    $configId,
                    $trialInfo['trial'],
                    isset($trialInfo['constraint_dimension_scores']['location_area']) ? (string)$trialInfo['constraint_dimension_scores']['location_area'] : 'N/A',
                    implode(',', $trialInfo['failed_dimensions']),
                    implode(' | ', $trialInfo['returned_top5'])
                );
            }
        }
        echo "Checker rule: each result's name/address/food text is searched for any requested area token; no token match fails that result's location_area dimension.\n";
    }
    foreach (['Q036', 'D014'] as $comparisonCaseId) {
        if (!isset($caseDiagnostics[$comparisonCaseId])) continue;
        echo "\n--- {$comparisonCaseId} TOP-5 LISTICLE-FILTER COMPARISON ---\n";
        $comparison = $caseDiagnostics[$comparisonCaseId]['listicle_filter_comparison'];
        if (!empty($comparison['enabled'])) {
            foreach ($comparison['by_config'] as $configId => $trials) {
                foreach ($trials as $trial => $trialResult) {
                    printf(
                        "  %s trial %d | before: %s | after: %s | rejected=%d | comparison_error=%s\n",
                        $configId,
                        $trial,
                        implode(' | ', $trialResult['before_filter_top5']),
                        implode(' | ', $trialResult['after_filter_top5']),
                        $trialResult['after_filter_rejected_count'],
                        $trialResult['comparison_request_failure'] ?? 'none'
                    );
                }
            }
        } else {
            foreach ($caseDiagnostics[$comparisonCaseId]['per_config_trials'] as $configId => $trialsForConfig) {
                foreach ($trialsForConfig as $trialInfo) {
                    printf("  %s trial %d current top-5: %s\n", $configId, $trialInfo['trial'], implode(' | ', $trialInfo['returned_top5']));
                }
            }
            echo '  Paired before/after output requires --compare-listicle-filter (extra diagnostic API requests are recorded separately).' . "\n";
        }
    }

    if ($hybridCurationStats['total_requests'] > 0) {
        echo "\n======================================================================================================================================\n";
        echo " HYBRID PIPELINE DEEPSEEK CURATION TELEMETRY\n";
        echo "======================================================================================================================================\n";
        $hybridCurationStats['invoked_percent'] = round(($hybridCurationStats['invoked_count'] / $hybridCurationStats['total_requests']) * 100.0, 1);
        printf(
            "Total Hybrid Requests: %d | Curation Invoked: %d (%.1f%%)\n",
            $hybridCurationStats['total_requests'],
            $hybridCurationStats['invoked_count'],
            $hybridCurationStats['invoked_percent']
        );
        if ($hybridCurationStats['triggers']) {
            echo "Triggers Breakdown:\n";
            foreach ($hybridCurationStats['triggers'] as $trig => $cnt) {
                printf("  - %-32s: %d (%.1f%%)\n", $trig, $cnt, ($cnt / max(1, $hybridCurationStats['invoked_count'])) * 100.0);
            }
        }
    }

    echo "\n--- CONFIGURED TOKEN RATES ---\n";
    foreach ($modelPricing as $modelKey => $pricing) {
        printf(
            "  %-14s model=%s | backend=%s | endpoint=%s | input=$%.2f/M | output=$%.2f/M\n",
            $modelKey,
            $pricing['model'],
            $pricing['backend'],
            $pricing['endpoint'],
            $pricing['rates']['input_usd_per_million'],
            $pricing['rates']['output_usd_per_million']
        );
    }
    echo "Token accounting: output_tokens includes reasoning_tokens; reasoning_tokens is a subset, not an additional token total.\n";
    echo 'Detailed per-stage usage: ' . ($options['detailed_telemetry'] ? "enabled\n" : "not collected (pass --detailed-telemetry)\n");

    $providerByConfig = [];
    foreach ($configurations as $configuredSystem) {
        $providerByConfig[$configuredSystem['id']] = $configuredSystem['provider'];
    }
    $costAudit = [];
    foreach (['deepseek'=>['deepseek', 'deepseek-api'], 'gpt'=>['gpt-4o-mini']] as $auditName => $providersForAudit) {
        $selectedConfig = null;
        foreach ($providerByConfig as $configId => $providerName) {
            if (in_array($providerName, $providersForAudit, true)) {
                $selectedConfig = $configId;
                break;
            }
        }
        if ($selectedConfig === null) {
            $costAudit[$auditName] = ['status'=>'not_included'];
            printf("Cost recomputation (%s): skipped; no matching config in this run.\n", $auditName);
            continue;
        }
        $selectedProvider = $providerByConfig[$selectedConfig];
        $audited = auditRequestCosts(
            $requestAuditByConfig[$selectedConfig] ?? [],
            $selectedProvider,
            $modelPricing,
            $options['detailed_telemetry']
        );
        $costAudit[$auditName] = ['config_id'=>$selectedConfig] + $audited;
        printf(
            "Cost recomputation (%s, %s): requests=%d | hand-recomputed mean=$%.8f/query | mismatches=%d\n",
            $auditName,
            $selectedConfig,
            $audited['request_count'],
            $audited['mean_computed_cost_usd'] ?? 0.0,
            $audited['mismatch_count']
        );
        foreach ($audited['requests'] as $requestCost) {
            $reasoningDisplay = $options['detailed_telemetry'] ? (string)$requestCost['reasoning_tokens'] : 'n/a';
            printf(
                "  %s trial %d | model=%s | input=%d output=%d reasoning=%s | computed=$%.8f saved=$%.8f %s\n",
                $requestCost['case_id'],
                $requestCost['trial'],
                $requestCost['model_id'] !== '' ? $requestCost['model_id'] : '[not returned]',
                $requestCost['input_tokens'],
                $requestCost['output_tokens'],
                $reasoningDisplay,
                $requestCost['computed_cost_usd'],
                $requestCost['saved_cost_usd'],
                $requestCost['matches_saved_cost'] ? 'OK' : 'MISMATCH'
            );
        }
    }

    echo "\n--- ERROR-TO-CASE INDEX ---\n";
    if (!$errorCaseIndex) {
        echo "No failed requests.\n";
    } else {
        foreach ($errorCaseIndex as $errorRow) {
            printf(
                "  %s | %s | trial=%d | type=%s | HTTP=%d | stage=%s | timeout=%ss\n",
                $errorRow['case_id'],
                $errorRow['config_id'],
                $errorRow['trial'],
                $errorRow['error_type'],
                $errorRow['http_code'],
                $errorRow['stage'],
                $errorRow['timeout_threshold_s'] ?? 'unknown'
            );
        }
    }

    // ==================== STATISTICAL TESTS ====================
    $statisticalTests = ['friedman'=>[], 'wilcoxon_posthoc'=>[], 'pre_specified'=>[]];
    echo "\n======================================================================================================================================\n";
    echo " STATISTICAL SIGNIFICANCE ANALYSIS (Friedman Omnibus + Pairwise Wilcoxon Signed-Rank with Holm-Bonferroni)\n";
    echo "======================================================================================================================================\n";

    if (count($configIds) >= 3) {
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
    } else {
        echo "Friedman omnibus test skipped: requires at least 3 configurations (observed " . count($configIds) . ").\n";
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

    $averageVectors = static function(array $vectors): array {
        if (!$vectors) return [];
        $length = min(array_map('count', $vectors));
        $averages = [];
        for ($i = 0; $i < $length; $i++) {
            $values = array_map(static fn($vector) => (float)$vector[$i], $vectors);
            $averages[] = meanVal($values);
        }
        return $averages;
    };
    $poolProviderScores = static function(array $providers, string $metric) use ($summary, $pairedScores, $averageVectors): array {
        $vectors = [];
        foreach ($summary as $index => $row) {
            if (in_array($row['provider'], $providers, true)) $vectors[] = $pairedScores[$metric][$index] ?? [];
        }
        return $averageVectors(array_values(array_filter($vectors, 'is_array')));
    };
    $preSpecifiedReports = [];
    foreach (['p5', 'ndcg5'] as $metric) {
        $comparisons = [];
        $deepseekScores = $poolProviderScores(['deepseek', 'deepseek-api'], $metric);
        $gptScores = $poolProviderScores(['gpt-4o-mini'], $metric);
        if ($deepseekScores && $gptScores) {
            $comparisons[] = ['id'=>'DeepSeek pooled vs GPT pooled', 'config_a'=>'deepseek|deepseek-api', 'config_b'=>'gpt-4o-mini', 'scores_a'=>$deepseekScores, 'scores_b'=>$gptScores];
        }

        $providersInRun = array_values(array_unique(array_map(static fn($row) => $row['provider'], $summary)));
        foreach ($providersInRun as $provider) {
            $baselineVectors = [];
            $strictVectors = [];
            foreach ($summary as $index => $row) {
                if ($row['provider'] !== $provider) continue;
                if ($row['prompt_style'] === 'baseline') $baselineVectors[] = $pairedScores[$metric][$index] ?? [];
                if ($row['prompt_style'] === 'strict_grounding') $strictVectors[] = $pairedScores[$metric][$index] ?? [];
            }
            $baselinePromptScores = $averageVectors($baselineVectors);
            $strictPromptScores = $averageVectors($strictVectors);
            if ($baselinePromptScores && $strictPromptScores) {
                $comparisons[] = [
                    'id'=>"{$provider}: baseline vs strict prompt",
                    'config_a'=>"{$provider}:baseline",
                    'config_b'=>"{$provider}:strict_grounding",
                    'scores_a'=>$baselinePromptScores,
                    'scores_b'=>$strictPromptScores,
                ];
            }
        }

        foreach (['deepseek', 'gpt-4o-mini', 'hybrid'] as $llmProvider) {
            $llmScores = $poolProviderScores([$llmProvider], $metric);
            if (!$llmScores) continue;
            foreach ([
                'baseline'=>'BASELINE-LOCAL',
                'agentic-local'=>'AGENTIC-LOOP-LOCAL',
                'non-agentic'=>'NON-AGENTIC',
            ] as $baselineProvider => $label) {
                $baselineScores = $poolProviderScores([$baselineProvider], $metric);
                if (!$baselineScores) continue;
                $comparisons[] = [
                    'id'=>"{$llmProvider} vs {$label}",
                    'config_a'=>$llmProvider,
                    'config_b'=>$label,
                    'scores_a'=>$llmScores,
                    'scores_b'=>$baselineScores,
                ];
            }
        }

        $family = pairedWilcoxonFamily($comparisons, $options['seed'] + ($metric === 'p5' ? 100 : 200));
        $preSpecifiedReports[$metric] = $family;
        echo "\nPre-specified comparisons ({$metric}): family size={$family['family_size']} (Holm correction restricted to this family)\n";
        foreach ($family['comparisons'] as $comparison) {
            printf("  %-42s n=%d mean_diff=%+.4f 95%% bootstrap CI=[%+.4f,%+.4f] method=%s p=%.5f Holm=%.5f r=%+.3f %s\n",
                $comparison['comparison'],
                $comparison['n_pairs'],
                $comparison['mean_difference'],
                $comparison['bootstrap_ci_lower'],
                $comparison['bootstrap_ci_upper'],
                $comparison['method'],
                $comparison['p_value'],
                $comparison['p_holm'],
                $comparison['effect_size_r'],
                $comparison['significant_005'] ? 'YES*' : 'ns');
        }
    }
    $statisticalTests['pre_specified'] = $preSpecifiedReports;

    $validSummary = array_values(array_filter($summary, fn($row) => $row['failures'] < $row['total_requests']));
    $rankByP5 = $validSummary;
    usort($rankByP5, fn($a, $b) => ($b['p5']['mean'] <=> $a['p5']['mean'])
        ?: ($b['ndcg5']['mean'] <=> $a['ndcg5']['mean'])
        ?: ($b['constraint_item_rate']['mean'] <=> $a['constraint_item_rate']['mean'])
        ?: ($b['scope_rejection_accuracy'] <=> $a['scope_rejection_accuracy'])
        ?: ($a['error_rate'] <=> $b['error_rate'])
        ?: ($a['latency']['median_p50_s'] <=> $b['latency']['median_p50_s'])
        ?: strcmp($a['id'], $b['id']));
    $rankByFaithfulness = $validSummary;
    usort($rankByFaithfulness, fn($a, $b) => ($b['faithfulness']['mean'] <=> $a['faithfulness']['mean'])
        ?: (($a['error_taxonomy']['unsupported_claims'] ?? 0) <=> ($b['error_taxonomy']['unsupported_claims'] ?? 0))
        ?: ($b['p5']['mean'] <=> $a['p5']['mean'])
        ?: ($b['constraint_item_rate']['mean'] <=> $a['constraint_item_rate']['mean'])
        ?: ($b['scope_rejection_accuracy'] <=> $a['scope_rejection_accuracy'])
        ?: ($a['error_rate'] <=> $b['error_rate'])
        ?: ($a['latency']['median_p50_s'] <=> $b['latency']['median_p50_s'])
        ?: strcmp($a['id'], $b['id']));

    $selectionRule = $options['selection_metric'] === 'p5'
        ? 'P@5 mean descending, then NDCG@5 mean descending, constraint item-pass mean descending, scope accuracy descending, error rate ascending, median P50 latency ascending, config ID ascending.'
        : 'NDCG@5 mean descending, then P@5 mean descending, constraint item-pass mean descending, scope accuracy descending, error rate ascending, median P50 latency ascending, config ID ascending.';
    $bestConfigSelection = null;
    echo "\n";
    if ($options['final_run']) {
        echo 'Best-config selection: skipped on test; the selected config was loaded from the dev report: ' . $selectedDevConfig['id'] . ".\n";
    } elseif ($validSummary) {
        echo "Ranking — P@5 first:\n";
        foreach ($rankByP5 as $rank => $row) {
            printf("  %2d. %-30s P@5=%.1f%% (95%% CI %.1f–%.1f%%) NDCG@5=%.1f%%\n",
                $rank + 1, $row['id'], $row['p5']['mean'] * 100, $row['p5']['ci_lower'] * 100, $row['p5']['ci_upper'] * 100, $row['ndcg5']['mean'] * 100);
        }
        echo "Ranking — faithfulness/unsupported-claims first:\n";
        foreach ($rankByFaithfulness as $rank => $row) {
            printf("  %2d. %-30s Faithfulness=%.1f%% Unsupported claims=%d P@5=%.1f%%\n",
                $rank + 1, $row['id'], $row['faithfulness']['mean'] * 100, (int)($row['error_taxonomy']['unsupported_claims'] ?? 0), $row['p5']['mean'] * 100);
        }
        $topP5 = $rankByP5[0];
        $ciOverlapsTopP5 = array_values(array_map(
            fn($row) => $row['id'],
            array_filter($rankByP5, fn($row) => $row['p5']['ci_lower'] <= $topP5['p5']['ci_upper'] && $row['p5']['ci_upper'] >= $topP5['p5']['ci_lower'])
        ));
        echo 'Overlapping 95% P@5 CIs with top P@5 config (' . $topP5['id'] . '): ' . implode(', ', $ciOverlapsTopP5) . "\n";
        echo 'Selection metric    : ' . $options['selection_metric'] . "\n";
        echo 'Exact rule/tie-break: ' . $selectionRule . "\n";
        $selectedRows = $options['selection_metric'] === 'p5'
            ? $rankByP5
            : $rankByFaithfulness;
        if ($options['selection_metric'] === 'ndcg5') {
            $selectedRows = $validSummary;
            usort($selectedRows, fn($a, $b) => ($b['ndcg5']['mean'] <=> $a['ndcg5']['mean'])
                ?: ($b['p5']['mean'] <=> $a['p5']['mean'])
                ?: ($b['constraint_item_rate']['mean'] <=> $a['constraint_item_rate']['mean'])
                ?: ($b['scope_rejection_accuracy'] <=> $a['scope_rejection_accuracy'])
                ?: ($a['error_rate'] <=> $b['error_rate'])
                ?: ($a['latency']['median_p50_s'] <=> $b['latency']['median_p50_s'])
                ?: strcmp($a['id'], $b['id']));
        }
        $chosen = $selectedRows[0];
        $bestConfigSelection = [
            'selection_metric'=>$options['selection_metric'],
            'selection_rule'=>$selectionRule,
            'top_p5_config'=>$topP5['id'],
            'p5_ci_overlap_configs'=>$ciOverlapsTopP5,
            'selected_config'=>array_intersect_key($chosen, array_flip(['id', 'provider', 'temperature', 'top_p', 'prompt_style', 'agentic'])),
            'rankings'=>[
                'p5_first'=>array_map(fn($row) => $row['id'], $rankByP5),
                'faithfulness_unsupported_first'=>array_map(fn($row) => $row['id'], $rankByFaithfulness),
            ],
        ];
        echo 'Selected configuration: ' . $chosen['id'] . sprintf(
            " (P@5=%.1f%%, NDCG@5=%.1f%%, Faithfulness=%.1f%%).\n",
            $chosen['p5']['mean'] * 100,
            $chosen['ndcg5']['mean'] * 100,
            $chosen['faithfulness']['mean'] * 100
        );
    } else {
        echo "Best configuration not selected: every configuration failed all requests.\n";
    }

    // Helper to export clean CSV representation of Tables 1 through 5
    $exportTables1To5 = static function(string $filePath, array $summaryRows, array $retrievalCases) use ($groundTruthHash, $datasetHashes, $sourceManifest): void {
        $fp = @fopen($filePath, 'wb');
        if (!$fp) return;

        $sourceHeader = 'SOURCE: script=evaluation/run_evaluation.php; function=exportTables1To5; inputs=ground_truth.json,' . implode(',', array_keys($datasetHashes))
            . '; ground_truth_sha256=' . $groundTruthHash . '; source_tree_sha256=' . $sourceManifest['combined_sha256'];
        fputcsv($fp, [$sourceHeader]);
        fputcsv($fp, ['TABLE 1: RANKING & INTENT MATCHING METRICS (Combined Web+Local, n=' . count($retrievalCases) . ')']);
        fputcsv($fp, ['Config ID', 'P@5 Mean', 'P@5 CI Lower', 'P@5 CI Upper', 'NDCG@5 Mean', 'NDCG@5 CI Lower', 'NDCG@5 CI Upper', 'MRR', 'R@5 Mean', 'R@5 CI Lower', 'R@5 CI Upper', 'R@10 Mean', 'Local P@5', 'Web P@5', 'Web Active Queries']);
        foreach ($summaryRows as $r) {
            fputcsv($fp, [
                $r['id'],
                round($r['p5']['mean'], 4), round($r['p5']['ci_lower'], 4), round($r['p5']['ci_upper'], 4),
                round($r['ndcg5']['mean'], 4), round($r['ndcg5']['ci_lower'], 4), round($r['ndcg5']['ci_upper'], 4),
                round($r['mrr']['mean'], 4),
                round($r['r5']['mean'], 4), round($r['r5']['ci_lower'], 4), round($r['r5']['ci_upper'], 4),
                round($r['r10']['mean'], 4),
                round($r['local_p5_mean'], 4),
                $r['web_p5_mean'] !== null ? round($r['web_p5_mean'], 4) : 'N/A',
                $r['web_active_queries'] ?? 0
            ]);
        }
        fputcsv($fp, []);

        fputcsv($fp, [$sourceHeader]);
        fputcsv($fp, ['TABLE 2: CONSTRAINT ADHERENCE, SCOPE SAFETY, MULTI-TURN, FAITHFULNESS, RELIABILITY & COST']);
        fputcsv($fp, ['Config ID', 'Constraint Item Pass Rate', 'Scope Rejection Accuracy', 'Follow-up Resolution Rate', 'Faithfulness Mean', 'Factual Correctness Mean', 'Repeat Consistency', 'Error Rate', 'Retries', 'Mean Cost USD']);
        foreach ($summaryRows as $r) {
            fputcsv($fp, [
                $r['id'],
                round($r['constraint_item_rate']['mean'], 4),
                round($r['scope_rejection_accuracy'], 4),
                $r['followup_resolution_rate'] !== null ? round($r['followup_resolution_rate'], 4) : 'N/A',
                round($r['faithfulness']['mean'], 4),
                round($r['factual_correctness']['mean'], 4),
                $r['repeat_consistency'] !== null ? round($r['repeat_consistency'], 4) : 'N/A',
                round($r['error_rate'], 4),
                $r['total_retries'] ?? 0,
                round($r['mean_cost_usd'], 8)
            ]);
        }
        fputcsv($fp, []);

        fputcsv($fp, [$sourceHeader]);
        fputcsv($fp, ['TABLE 3: LATENCY DECOMPOSITION & CACHE BEHAVIOR']);
        fputcsv($fp, ['Config ID', 'Mean Latency (s)', 'P50 Latency (s)', 'P95 Latency (s)', 'P99 Latency (s)', 'Cold P50 (s)', 'Warm P50 (s)', 'Dataset Mean (ms)', 'Playwright Mean (ms)', 'AI Mean (ms)', 'Playwright Cache Hits', 'Playwright Cache Misses']);
        foreach ($summaryRows as $r) {
            $lat = $r['latency'];
            fputcsv($fp, [
                $r['id'],
                round($lat['mean_s'], 4),
                round($lat['median_p50_s'], 4),
                round($lat['p95_s'], 4),
                round($lat['p99_s'], 4),
                $lat['cold_p50_s'] !== null ? round($lat['cold_p50_s'], 4) : 'N/A',
                $lat['warm_p50_s'] !== null ? round($lat['warm_p50_s'], 4) : 'N/A',
                round($lat['dataset_mean_ms'], 2),
                round($lat['playwright_mean_ms'], 2),
                round($lat['ai_mean_ms'], 2),
                $r['playwright_cache_hits'] ?? 0,
                $r['playwright_cache_misses'] ?? 0
            ]);
        }
        fputcsv($fp, []);

        fputcsv($fp, [$sourceHeader]);
        fputcsv($fp, ['TABLE 4: QUALITATIVE ERROR & GROUNDING AUDIT COUNTS']);
        fputcsv($fp, ['Config ID', 'Duplicates', 'Wrong Locations', 'Missing Sources', 'Fabricated Details', 'Unsupported Claims', 'Constraint Failures', 'Scope Violations']);
        foreach ($summaryRows as $r) {
            $et = $r['error_taxonomy'];
            fputcsv($fp, [
                $r['id'],
                $et['duplicates'] ?? 0,
                $et['wrong_locations'] ?? 0,
                $et['missing_sources'] ?? 0,
                $et['fabricated_details'] ?? 0,
                $et['unsupported_claims'] ?? 0,
                $et['constraint_failures'] ?? 0,
                $et['scope_violations'] ?? 0
            ]);
        }
        fputcsv($fp, []);

        fputcsv($fp, [$sourceHeader]);
        fputcsv($fp, ['TABLE 5: STUDY OBJECTIVES BREAKDOWN — DISAMBIGUATION, NUMERIC PRICE CEILINGS & AUTONOMOUS ITERATIVE LOOP']);
        fputcsv($fp, ['Config ID', 'Disambiguation Accuracy', 'Trap-Free Rate', 'Price Ceiling Accuracy', 'Price Ceiling NDCG@5', 'Loop Initial Compliance', 'Loop Final Compliance', 'Violations Repaired', 'Violations Detected', 'Re-Retrieval Rate']);
        foreach ($summaryRows as $r) {
            $al = $r['agentic_loop_stats'] ?? [];
            fputcsv($fp, [
                $r['id'],
                $r['disambiguation_accuracy'] !== null ? round($r['disambiguation_accuracy'], 4) : 'N/A',
                $r['disambiguation_trap_free_rate'] !== null ? round($r['disambiguation_trap_free_rate'], 4) : 'N/A',
                $r['price_ceiling_compliance_rate'] !== null ? round($r['price_ceiling_compliance_rate'], 4) : 'N/A',
                $r['price_ceiling_ndcg5'] !== null ? round($r['price_ceiling_ndcg5'], 4) : 'N/A',
                !empty($al['enabled']) ? round($al['initial_compliance_rate'] ?? 0, 4) : 'disabled',
                !empty($al['enabled']) ? round($al['final_compliance_rate'] ?? 0, 4) : 'disabled',
                !empty($al['enabled']) ? ($al['violations_repaired'] ?? 0) : '-',
                !empty($al['enabled']) ? ($al['violations_detected'] ?? 0) : '-',
                !empty($al['enabled']) ? round($al['re_retrieval_rate'] ?? 0, 4) : '-'
            ]);
        }
        fclose($fp);
    };

    // ==================== SAVE REPRODUCIBILITY ARTIFACTS ====================
    if (!$options['no_save']) {
        $resultsDir = __DIR__ . DIRECTORY_SEPARATOR . 'results';
        if (!is_dir($resultsDir)) @mkdir($resultsDir, 0775, true);
        $stamp = gmdate('Ymd_His');
        $jsonOutPath = $options['output'] !== ''
            ? $options['output']
            : ($resultsDir . DIRECTORY_SEPARATOR . "eval_{$stamp}.json");
        $traceArtifact = null;
        $traceJsonPath = null;
        $traceMarkdownPath = null;
        if ($options['trace_case'] !== '') {
            $traceConfigId = (string)($configurationOrder[0] ?? '');
            $traceRequest = null;
            foreach (($detailedRuns[$traceConfigId]['requests'] ?? []) as $request) {
                if (($request['case_id'] ?? '') === $options['trace_case']) {
                    $traceRequest = $request;
                    break;
                }
            }
            $rawTraceCase = null;
            foreach (array_merge($groundTruthDecoded['cases'] ?? [], $groundTruthDecoded['scope_cases'] ?? []) as $candidateCase) {
                if (($candidateCase['id'] ?? '') === $options['trace_case']) {
                    $rawTraceCase = $candidateCase;
                    break;
                }
            }
            $normalizedTraceCase = null;
            foreach (array_merge($allCases, $allScopeCases) as $candidateCase) {
                if (($candidateCase['id'] ?? '') === $options['trace_case']) {
                    $normalizedTraceCase = $candidateCase;
                    break;
                }
            }
            $traceRecord = $detailedRuns[$traceConfigId]['cases'][$options['trace_case']][0]
                ?? $detailedRuns[$traceConfigId]['scope_cases'][$options['trace_case']][0]
                ?? [];
            $apiTrace = is_array($traceRequest['trace'] ?? null) ? $traceRequest['trace'] : [];
            $traceTop5 = mergeRecommendations(
                is_array($apiTrace['final_local_results'] ?? null) ? $apiTrace['final_local_results'] : [],
                is_array($apiTrace['final_web_results'] ?? null) ? $apiTrace['final_web_results'] : [],
                true
            );
            $relevanceGrades = is_array($normalizedTraceCase['relevance_grades'] ?? null) ? $normalizedTraceCase['relevance_grades'] : [];
            $baseGrades = is_array($normalizedTraceCase['base_relevance_grades'] ?? null) ? $normalizedTraceCase['base_relevance_grades'] : [];
            $top5WithRelevance = [];
            $constraintAudit = [];
            foreach (array_slice($traceTop5, 0, 5) as $rank => $place) {
                $name = trim((string)($place['name'] ?? ''));
                $normName = normalizePlaceName($name);
                $baseName = normalizeBaseRestaurantName($name);
                $top5WithRelevance[] = [
                    'rank'=>$rank + 1,
                    'name'=>$name,
                    'relevance_grade'=>$relevanceGrades[$normName] ?? $baseGrades[$baseName] ?? 0,
                    'source_url'=>$place['source_url'] ?? null,
                ];
                $constraintAudit[] = [
                    'rank'=>$rank + 1,
                    'name'=>$name,
                    'audit'=>evaluateConstraintSatisfaction([$place], $normalizedTraceCase['constraints'] ?? [], $datasetIndex),
                ];
            }
            $traceArtifact = [
                'trace_version'=>1,
                'case_id'=>$options['trace_case'],
                'config_id'=>$traceConfigId,
                'ground_truth_sha256'=>$groundTruthHash,
                'dataset_files'=>$datasetHashes,
                'raw_query'=>$rawTraceCase['query'] ?? ($apiTrace['raw_query'] ?? ''),
                'history'=>$apiTrace['history'] ?? [],
                'parsed_constraints'=>$apiTrace['stage1']['parsed_constraints'] ?? [],
                'stage1_search_query'=>$apiTrace['stage1']['search_query'] ?? null,
                'collected_evidence'=>$apiTrace['collected_evidence'] ?? [],
                'evidence_cache'=>$apiTrace['evidence_cache'] ?? [],
                'stage3_structured_candidates'=>$apiTrace['stage3_structured_candidates'] ?? [],
                'stage4_model_output'=>$apiTrace['stage4_model_output'] ?? [
                    'model'=>$traceRequest['model_id'] ?? '',
                    'status'=>$traceRequest['failure'] ?? 'unavailable',
                ],
                'stage4_reasoning_summary'=>$traceRequest['pipeline']['stages'] ?? [],
                'stage5_final_answer'=>$apiTrace['stage5_final_answer'] ?? null,
                'critique_audit'=>$apiTrace['critique_audit'] ?? [],
                'per_result_constraint_audit'=>$constraintAudit,
                'ground_truth'=>[
                    'relevant_names'=>$rawTraceCase['relevant_names'] ?? [],
                    'relevance_grades'=>$rawTraceCase['relevance_grades'] ?? [],
                    'constraints'=>$rawTraceCase['constraints'] ?? [],
                ],
                'top5_with_relevance'=>$top5WithRelevance,
                'metrics'=>[
                    'P_at_5'=>$traceRecord['combined']['precision_at_5'] ?? 0.0,
                    'NDCG_at_5'=>$traceRecord['combined']['ndcg_at_5'] ?? 0.0,
                    'MRR'=>$traceRecord['combined']['mrr'] ?? 0.0,
                    'R_at_5'=>$traceRecord['combined']['recall_at_5'] ?? 0.0,
                    'R_at_10'=>$traceRecord['combined']['recall_at_10'] ?? 0.0,
                    'constraint_pass_rate'=>$traceRecord['constraint']['item_pass_rate'] ?? null,
                    'faithfulness'=>$traceRecord['validation']['evidence_support_rate'] ?? null,
                ],
                'request_error'=>[
                    'type'=>$traceRequest['error_type'] ?? null,
                    'http_code'=>$traceRequest['http_code'] ?? null,
                    'stage'=>$traceRequest['stage'] ?? null,
                    'timeout_threshold_s'=>$traceRequest['timeout_threshold_s'] ?? null,
                ],
            ];
            $traceDir = $resultsDir . DIRECTORY_SEPARATOR . 'traces';
            if (!is_dir($traceDir) && !mkdir($traceDir, 0775, true) && !is_dir($traceDir)) {
                throw new RuntimeException('Could not create trace output directory.');
            }
            $traceBase = 'trace_' . $options['trace_case'] . '_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $traceConfigId);
            $traceJsonPath = $traceDir . DIRECTORY_SEPARATOR . $traceBase . '.json';
            $traceMarkdownPath = $traceDir . DIRECTORY_SEPARATOR . $traceBase . '.md';
            $traceArtifact['output_files'] = [basename($traceJsonPath), basename($traceMarkdownPath)];
        }

        $failureMatrixRows = [];
        $caseObjectiveRows = [];
        foreach (array_merge($allCases, $allScopeCases) as $case) {
            $caseId = (string)$case['id'];
            $isScopeCase = isset($case['expect_out_of_scope']) && !isset($case['relevance_grades']);
            foreach ($configurationOrder as $configId) {
                $records = $isScopeCase
                    ? ($detailedRuns[$configId]['scope_cases'][$caseId] ?? [])
                    : ($detailedRuns[$configId]['cases'][$caseId] ?? []);
                $errorTypes = [];
                $top5ByTrial = [];
                $failedDimensions = [];
                $hasTimeout = false;
                $hasMalformed = false;
                $priceFailure = false;
                $disambiguationFailure = false;
                $scopeFailure = false;
                foreach ($records as $record) {
                    if (!empty($record['failed'])) {
                        $errorType = (string)($record['error_reason'] ?? 'unknown');
                        $errorTypes[] = $errorType;
                        if ($errorType === 'timeout') $hasTimeout = true;
                        if (in_array($errorType, ['invalid_json', 'empty_response', 'schema_validation'], true)) $hasMalformed = true;
                    }
                    if (isset($record['top5_returned'])) {
                        $top5ByTrial[(string)($record['trial'] ?? 0)] = $record['top5_returned'];
                    }
                    $dims = is_array($record['constraint']['failed_dimensions'] ?? null) ? $record['constraint']['failed_dimensions'] : [];
                    foreach ($dims as $dim) $failedDimensions[(string)$dim] = true;
                    if (isset($case['constraints']['max_price_php']) && (($record['constraint']['numeric_price_compliant'] ?? null) === false
                        || in_array('max_price_php', $dims, true))) $priceFailure = true;
                    if ((!empty($case['disambiguation_type']) && $case['disambiguation_type'] !== 'none')
                        || in_array($case['category'] ?? '', ['disambiguation', 'ambiguous'], true)) {
                        if (($record['constraint']['disambiguation_trap_free'] ?? true) === false) $disambiguationFailure = true;
                    }
                    if ($isScopeCase) {
                        if (empty($record['scope_passed'])) $scopeFailure = true;
                    } elseif (!empty($record['false_out_of_scope'])) {
                        $scopeFailure = true;
                    }
                }
                $notRun = count($records) === 0;
                if ($notRun) $errorTypes[] = 'not_run_after_circuit_breaker';
                $failureMatrixRows[] = [
                    'case_id'=>$caseId,
                    'config_id'=>$configId,
                    'failed'=>count(array_diff($errorTypes, ['not_run_after_circuit_breaker'])) > 0,
                    'not_run'=>$notRun,
                    'failure_types'=>array_values(array_unique($errorTypes)),
                    'timeout'=>$hasTimeout,
                    'malformed'=>$hasMalformed,
                    'price_failure'=>$priceFailure,
                    'disambiguation_failure'=>$disambiguationFailure,
                    'scope_failure'=>$scopeFailure,
                ];
                $isDisambiguationCase = ((!empty($case['disambiguation_type']) && $case['disambiguation_type'] !== 'none')
                    || in_array($case['category'] ?? '', ['disambiguation', 'ambiguous'], true));
                $isPriceCase = isset($case['constraints']['max_price_php']) && is_numeric($case['constraints']['max_price_php']);
                if ($isDisambiguationCase || $isPriceCase) {
                    $objectiveRecords = $records ?: [[
                        'trial'=>0,
                        'top5_returned'=>[],
                        'constraint'=>[],
                        'error_reason'=>'not_run_after_circuit_breaker',
                    ]];
                    foreach ($objectiveRecords as $record) {
                        $caseObjectiveRows[] = [
                            'case_id'=>$caseId,
                            'case_category'=>$case['category'] ?? '',
                            'query'=>$case['query'] ?? '',
                            'config_id'=>$configId,
                            'trial'=>$record['trial'] ?? 0,
                            'disambiguation_case'=>$isDisambiguationCase,
                            'price_ceiling_case'=>$isPriceCase,
                            'max_price_php'=>$case['constraints']['max_price_php'] ?? null,
                            'top5_returned'=>$record['top5_returned'] ?? [],
                            'failed_constraints'=>$record['constraint']['failed_dimensions'] ?? [],
                            'numeric_price_compliant'=>$record['constraint']['numeric_price_compliant'] ?? null,
                            'disambiguation_trap_free'=>$record['constraint']['disambiguation_trap_free'] ?? null,
                            'error_type'=>$record['error_reason'] ?? null,
                        ];
                    }
                }
            }
        }

        $listicleComparisonCostUsd = array_sum(array_map(
            static fn($request) => (float)($request['saved_cost_usd'] ?? 0.0),
            $listicleComparisonRequestAudit
        ));
        if ($options['compare_listicle_filter']) {
            printf(
                "\nListicle comparison diagnostics: requests=%d | saved API cost=$%.8f\n",
                count($listicleComparisonRequestAudit),
                $listicleComparisonCostUsd
            );
        }

        $artifact = [
            'reproducibility_manifest'=>[
                'evaluator_version'=>'3.0.0',
                'started_at_utc'=>$runStartedIso,
                'completed_at_utc'=>gmdate('c'),
                'php_version'=>PHP_VERSION,
                'os'=>PHP_OS_FAMILY,
                'git'=>'unavailable',
                'git_commit_hash'=>null,
                'source_file_count'=>$sourceManifest['file_count'],
                'source_files_sha256'=>$sourceManifest['files'],
                'source_tree_sha256'=>$sourceManifest['combined_sha256'],
                'api_url'=>$options['api_url'],
                'ground_truth_path'=>basename($groundTruthPath),
                'ground_truth_version'=>$validatedGt['metadata']['version'],
                'ground_truth_sha256'=>$groundTruthHash,
                'dataset_files'=>$datasetHashes,
                'model_pricing'=>$modelPricing,
                'prompt_version_hashes'=>$promptVersionHashes,
                'split'=>$options['split'],
                'selection_metric'=>$options['selection_metric'],
                'allow_test_selection'=>$options['allow_test_selection'],
                'dev_grid'=>$options['dev_grid'],
                'final_run'=>$options['final_run'],
                'selection_report_sha256'=>$selectionReportHash,
                'selected_dev_config'=>$options['final_run'] ? $selectedDevConfig : null,
                'in_scope_case_count'=>count($allCases),
                'scope_case_count'=>count($allScopeCases),
                'trials'=>$options['trials'],
                'seed'=>$options['seed'],
                'configuration_order'=>$configurationOrder,
                'randomize_config_order'=>$options['randomize_config_order'],
                'cache_policy'=>$options['cache_policy'],
                'cold_cache_cleared_files'=>$coldCacheClearedFiles,
                'detailed_telemetry'=>$options['detailed_telemetry'],
                'stage_manifest_telemetry'=>true,
                'gpt_retries'=>$options['gpt_retries'],
                'retry_backoff_ms'=>$options['retry_backoff_ms'],
                'gpt_max_tokens'=>$options['gpt_max_tokens'],
                'sampling_parameter_policy'=>[
                    'top_p_sent_by_gpt_stages'=>false,
                    'max_tokens_sent_by_gpt_stages'=>$options['gpt_max_tokens'] !== null,
                    'gpt_max_tokens_default_unchanged'=>$options['gpt_max_tokens'] === null,
                ],
                'reject_listicles'=>$options['reject_listicles'],
                'compare_listicle_filter'=>$options['compare_listicle_filter'],
                'listicle_comparison_requests_expected'=>$listicleComparisonCaseCount * count($configurations) * $options['trials'],
                'listicle_comparison_request_cost_usd'=>$listicleComparisonCostUsd,
                'critique_web_rescrape'=>$options['critique_web_rescrape'],
                'loop_telemetry'=>$options['loop_telemetry'],
                'trace_case'=>$options['trace_case'] !== '' ? $options['trace_case'] : null,
                'deepseek_repeat_identical'=>$options['deepseek_repeat_identical'],
                'failed_request_p5_treatment'=>'primary=score as 0; alternate=exclude failed trials and cases with no successful trial',
                'metric_relationships'=>[
                    'missing_sources_vs_unsupported_claims'=>'missing_source_count is a subset of unsupported_claim_count for web recommendations; unsupported_claim_count may also include unverified URLs and unsupported out-of-city answer text. Do not sum the two.',
                    'faithfulness_vs_factual_correctness'=>'faithfulness is evidence_support_rate (dataset/source backing); factual_correctness_rate checks dataset detail, location, and verifiable source-backed facts. They use different checks and are not aliases.',
                    'reasoning_tokens'=>'reasoning_tokens is included within output_tokens and must not be added a second time.',
                ],
                'effective_timeouts'=>getTimeoutConfig(),
                'effective_timeouts_per_stage'=>[
                    'api_request_max_seconds'=>$options['api_url'] === 'local' ? getTimeoutConfig()['php_max_execution'] : getTimeoutConfig()['curl'],
                    'deepseek_single_seconds'=>max(TIMEOUT_LLM, TIMEOUT_LLM),
                    'hybrid_query_planner_seconds'=>max(TIMEOUT_LLM, 25),
                    'hybrid_cleaner_seconds'=>max(TIMEOUT_LLM, 45),
                    'hybrid_curator_seconds'=>max(TIMEOUT_LLM, TIMEOUT_LLM),
                    'hybrid_final_seconds'=>max(TIMEOUT_LLM, 45),
                    'gpt4o_single_seconds'=>max(TIMEOUT_LLM, 45),
                    'deepseek_api_seconds'=>max(TIMEOUT_LLM, TIMEOUT_LLM),
                    'playwright_process_seconds'=>TIMEOUT_PLAYWRIGHT_PROC,
                    'playwright_step_milliseconds'=>TIMEOUT_PLAYWRIGHT_STEP,
                    'playwright_page_goto_cap_milliseconds'=>6000,
                    'playwright_price_page_goto_cap_milliseconds'=>3500,
                ],
                'live_turns'=>$options['live_turns'],
            ],
            'annotator_agreement'=>$agreementReport,
            'usability_study'=>aggregateUsabilityStudy(),
            'summary'=>$summary,
            'error_reason_breakdown'=>$providerErrorBreakdown,
            'hybrid_curation'=>$hybridCurationStats,
            'request_telemetry'=>$requestAuditByConfig,
            'listicle_comparison_request_telemetry'=>$listicleComparisonRequestAudit,
            'error_case_index'=>$errorCaseIndex,
            'cost_audit'=>$costAudit,
            'case_diagnostics'=>$caseDiagnostics,
            'scope_guard_audit'=>$scopeGuardAudit,
            'statistical_tests'=>$statisticalTests,
            'best_config_selection'=>$bestConfigSelection,
            'trace'=>$traceArtifact,
            'failure_matrix'=>$failureMatrixRows,
            'case_objective_exports'=>$caseObjectiveRows,
            'detailed_runs'=>$detailedRuns,
        ];

        $encodedArtifact = json_encode($artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        @file_put_contents($jsonOutPath, $encodedArtifact, LOCK_EX);
        @file_put_contents($resultsDir . DIRECTORY_SEPARATOR . 'latest_run.json', $encodedArtifact, LOCK_EX);
        if ($traceArtifact !== null && $traceJsonPath !== null && $traceMarkdownPath !== null) {
            $traceJson = json_encode($traceArtifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($traceJson === false || file_put_contents($traceJsonPath, $traceJson, LOCK_EX) === false) {
                throw new RuntimeException('Could not persist JSON trace artifact.');
            }
            $markdown = "# Worked request trace: {$traceArtifact['case_id']}\n\n";
            $markdown .= "- Configuration: `{$traceArtifact['config_id']}`\n";
            $markdown .= "- Ground-truth SHA-256: `{$traceArtifact['ground_truth_sha256']}`\n";
            $markdown .= "- Query: " . (string)$traceArtifact['raw_query'] . "\n\n";
            foreach ([
                'History'=>'history',
                'Parsed constraints'=>'parsed_constraints',
                'Collected evidence'=>'collected_evidence',
                'Stage 3 structured candidates'=>'stage3_structured_candidates',
                'Stage 4 structured model output'=>'stage4_model_output',
                'Stage 5 final answer'=>'stage5_final_answer',
                'Critique audit'=>'critique_audit',
                'Per-result constraint audit'=>'per_result_constraint_audit',
                'Ground truth'=>'ground_truth',
                'Top-5 with relevance'=>'top5_with_relevance',
                'Computed metrics'=>'metrics',
                'Request error'=>'request_error',
            ] as $title => $key) {
                $value = json_encode($traceArtifact[$key] ?? null, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $markdown .= "## {$title}\n\n```json\n" . ($value ?: 'null') . "\n```\n\n";
            }
            if (file_put_contents($traceMarkdownPath, $markdown, LOCK_EX) === false) {
                throw new RuntimeException('Could not persist Markdown trace artifact.');
            }
            echo "Saved worked trace JSON              : {$traceJsonPath}\n";
            echo "Saved worked trace Markdown          : {$traceMarkdownPath}\n";
        }

        // Also write CSV summary for thesis tables / spreadsheets
        $csvPath = preg_replace('/\.json$/i', '', $jsonOutPath) . '_summary.csv';
        $fp = @fopen($csvPath, 'wb');
        if ($fp) {
            fputcsv($fp, [$tableSourceHeader]);
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
            @copy($csvPath, $resultsDir . DIRECTORY_SEPARATOR . 'latest_run_summary.csv');
        }

        $tablesCsvPath = preg_replace('/\.json$/i', '', $jsonOutPath) . '_tables_1_5.csv';
        $exportTables1To5($tablesCsvPath, $summary, $inScopeRetrievalCases);
        $exportTables1To5($resultsDir . DIRECTORY_SEPARATOR . 'latest_tables_1_5.csv', $summary, $inScopeRetrievalCases);

        $extraSourceHeader = 'SOURCE: script=evaluation/run_evaluation.php; function=exportCaseFailureAndObjectiveTables; inputs=ground_truth.json,data/*; ground_truth_sha256='
            . $groundTruthHash . '; dataset_sha256=' . json_encode($datasetHashList, JSON_UNESCAPED_SLASHES)
            . '; source_tree_sha256=' . $sourceManifest['combined_sha256'];
        $failureMatrixPath = preg_replace('/\.json$/i', '', $jsonOutPath) . '_failure_matrix.csv';
        $failureFp = fopen($failureMatrixPath, 'wb');
        if ($failureFp === false) throw new RuntimeException('Could not write failure matrix CSV.');
        fputcsv($failureFp, [$extraSourceHeader]);
        fputcsv($failureFp, ['case_id', 'config_id', 'failed', 'not_run', 'failure_types', 'timeout', 'malformed', 'price_failure', 'disambiguation_failure', 'scope_failure']);
        foreach ($failureMatrixRows as $matrixRow) {
            fputcsv($failureFp, [
                $matrixRow['case_id'], $matrixRow['config_id'], $matrixRow['failed'] ? '1' : '0',
                $matrixRow['not_run'] ? '1' : '0', implode('|', $matrixRow['failure_types']), $matrixRow['timeout'] ? '1' : '0',
                $matrixRow['malformed'] ? '1' : '0', $matrixRow['price_failure'] ? '1' : '0',
                $matrixRow['disambiguation_failure'] ? '1' : '0', $matrixRow['scope_failure'] ? '1' : '0',
            ]);
        }
        fclose($failureFp);

        $objectiveExportPath = preg_replace('/\.json$/i', '', $jsonOutPath) . '_disambiguation_price_cases.csv';
        $objectiveFp = fopen($objectiveExportPath, 'wb');
        if ($objectiveFp === false) throw new RuntimeException('Could not write disambiguation/price case CSV.');
        fputcsv($objectiveFp, [$extraSourceHeader]);
        fputcsv($objectiveFp, ['case_id', 'case_category', 'query', 'config_id', 'trial', 'disambiguation_case', 'price_ceiling_case', 'max_price_php', 'top5_returned', 'failed_constraints', 'numeric_price_compliant', 'disambiguation_trap_free', 'error_type']);
        foreach ($caseObjectiveRows as $objectiveRow) {
            fputcsv($objectiveFp, [
                $objectiveRow['case_id'], $objectiveRow['case_category'], $objectiveRow['query'],
                $objectiveRow['config_id'], $objectiveRow['trial'], $objectiveRow['disambiguation_case'] ? '1' : '0',
                $objectiveRow['price_ceiling_case'] ? '1' : '0', $objectiveRow['max_price_php'],
                json_encode($objectiveRow['top5_returned'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                implode('|', $objectiveRow['failed_constraints']),
                $objectiveRow['numeric_price_compliant'] === null ? '' : ($objectiveRow['numeric_price_compliant'] ? '1' : '0'),
                $objectiveRow['disambiguation_trap_free'] === null ? '' : ($objectiveRow['disambiguation_trap_free'] ? '1' : '0'),
                $objectiveRow['error_type'],
            ]);
        }
        fclose($objectiveFp);

        echo "Saved reproducible evaluation report: {$jsonOutPath}\n";
        echo "Saved CSV thesis summary table      : {$csvPath}\n";
        echo "Saved CSV Tables 1-5 report         : {$tablesCsvPath}\n";
        echo "Saved per-case failure matrix       : {$failureMatrixPath}\n";
        echo "Saved disambiguation/price cases    : {$objectiveExportPath}\n";
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