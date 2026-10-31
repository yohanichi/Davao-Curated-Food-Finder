<?php
declare(strict_types=1);

/**
 * Diagnostic & Validation Script for Local DeepSeek (llama-server / Ollama)
 * Runs 6 key benchmark queries (4 previously failing + 2 passing) 5 times each.
 * Measures: Parse success rate, avg tokens, avg latency, finish_reason distribution.
 */

$appRoot = dirname(__DIR__);
require_once $appRoot . '/src/config.php';
require_once $appRoot . '/src/search.php';
require_once $appRoot . '/src/web_reasoner.php';

$testQueries = [
    // Failing queries (reasoning runaway / token exhaustion)
    ['id' => 'Q006', 'query' => 'best ramen or japanese in davao city', 'category' => 'cuisine (failing)'],
    ['id' => 'Q009', 'query' => 'quiet cafe with good coffee for studying in obrero', 'category' => 'cuisine (failing)'],
    ['id' => 'Q010', 'query' => 'unlimited samgyupsal or buffet in davao', 'category' => 'cuisine (failing)'],
    ['id' => 'Q012', 'query' => 'halal certified restaurant in davao city', 'category' => 'dietary (failing)'],
    // Passing baseline queries
    ['id' => 'Q005', 'query' => 'fresh tuna and seafood in davao city', 'category' => 'cuisine (passing)'],
    ['id' => 'Q024', 'query' => 'highest rated restaurants in davao city with many reviews', 'category' => 'rating (passing)'],
];

$runsPerQuery = 5;
$serverUrl = rtrim(DEEPSEEK_BASE_URL ?: 'http://127.0.0.1:11434', '/');

echo "====================================================================================\n";
echo " LOCAL DEEPSEEK (LLAMA-SERVER) STRUCTURED PARSING VALIDATION\n";
echo "====================================================================================\n";
echo "Target Endpoint : {$serverUrl}\n";
echo "Model           : " . DEEPSEEK_MODEL . "\n";
echo "Test Cases      : " . count($testQueries) . " queries x {$runsPerQuery} runs = " . (count($testQueries) * $runsPerQuery) . " requests\n";
echo "====================================================================================\n\n";

// Load dataset places for candidate context
$dataDir = $appRoot . DIRECTORY_SEPARATOR . 'data';
$placesCache = $dataDir . DIRECTORY_SEPARATOR . '.places-cache.json';
$places = is_file($placesCache) ? (json_decode((string)file_get_contents($placesCache), true)['places'] ?? []) : [];
if (!$places) $places = loadDavaoPlaces($dataDir);

// Define strict JSON Schema for constrained decoding
$responseJsonSchema = [
    'type' => 'object',
    'properties' => [
        'answer' => ['type' => 'string'],
        'out_of_scope' => ['type' => 'boolean'],
        'ids' => [
            'type' => 'array',
            'items' => ['type' => 'string']
        ],
        'web_places' => [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string'],
                    'food_type' => ['type' => 'string'],
                    'address' => ['type' => 'string'],
                    'city' => ['type' => 'string'],
                    'rating' => ['type' => 'string'],
                    'review_count' => ['type' => 'string'],
                    'price_range' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'source_url' => ['type' => 'string'],
                ],
                'required' => ['name']
            ]
        ]
    ],
    'required' => ['answer', 'out_of_scope', 'ids', 'web_places']
];

// Optimized Static System Prompt (Prefix Cacheable)
$staticSystemPrompt = <<<'SYS'
You are the recommendation engine for Davao Food Finder in Davao City, Philippines.
Given the user request and 2025 candidate places, output ONLY a valid JSON object.
Never output thoughts, reasoning, or markdown text outside the JSON.

Strict Grounding Rules:
1. Recommend ONLY places from the provided candidate IDs that satisfy the user's constraints.
2. If the user asks for a place outside Davao City, set "out_of_scope": true, "ids": [], "answer": "I only cover Davao City."
3. Do not invent places, ratings, or prices.

JSON Format:
{
  "answer": "string",
  "out_of_scope": false,
  "ids": ["matching_id_1", "matching_id_2"],
  "web_places": []
}

Example:
User: "tuna kinilaw in davao"
Candidates: [{"id": "p001", "name": "Marina Tuna", "food_type": "Seafood"}]
Output:
{"answer":"Here are top Davao City seafood spots: Marina Tuna.","out_of_scope":false,"ids":["p001"],"web_places":[]}
SYS;

$resultsByQuery = [];
$totalTokensAll = 0;
$totalLatencyAll = 0.0;
$totalParses = 0;
$totalAttempts = 0;
$finishReasonCounts = ['stop' => 0, 'length' => 0, 'other' => 0];

foreach ($testQueries as $qIdx => $case) {
    $qid = $case['id'];
    $qText = $case['query'];
    echo sprintf("[%d/%d] Testing %s (%s): \"%s\"\n", $qIdx + 1, count($testQueries), $qid, $case['category'], $qText);

    // Retrieve simple candidates
    $matchedCandidates = searchDavaoPlaces($qText, $places, 6);
    $compactCandidates = [];
    foreach ($matchedCandidates as $p) {
        $compactCandidates[] = [
            'id' => $p['_id'],
            'name' => $p['name'],
            'food_type' => $p['food_type'] ?? '',
            'address' => $p['address'] ?? '',
            'rating' => $p['rating'] ?? null,
            'review_count' => $p['review_count'] ?? null,
        ];
    }

    $caseStats = [
        'parses' => 0,
        'tokens' => [],
        'latencies' => [],
        'finish_reasons' => [],
    ];

    for ($run = 1; $run <= $runsPerQuery; $run++) {
        $totalAttempts++;
        $userContent = "User query: {$qText}\nCandidates:\n" . json_encode($compactCandidates, JSON_UNESCAPED_UNICODE);

        // Assistant turn prefill trick: closes <think> and begins JSON with {
        $messages = [
            ['role' => 'system', 'content' => $staticSystemPrompt],
            ['role' => 'user', 'content' => $userContent],
            ['role' => 'assistant', 'content' => "<think>\n\n</think>\n\n{"]
        ];

        $payload = json_encode([
            'model' => DEEPSEEK_MODEL,
            'messages' => $messages,
            'stream' => false,
            'format' => $responseJsonSchema,
            'options' => [
                'temperature' => 0.2,
                'num_predict' => 1024,
                'stop' => ["\n```", "</think>", "<|im_end|>", "<|endoftext|>"],
            ],
            'think' => false, // Suppress Ollama think mode if supported
        ], JSON_UNESCAPED_UNICODE);

        $t0 = microtime(true);
        $ch = curl_init($serverUrl . '/api/chat');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
        ]);
        $raw = curl_exec($ch);
        $latency = microtime(true) - $t0;
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $tokensGen = 0;
        $finishReason = 'other';
        $parseOk = false;

        if ($raw !== false && $httpCode >= 200 && $httpCode < 300) {
            $respObj = json_decode($raw, true);
            $content = $respObj['message']['content'] ?? ($respObj['choices'][0]['message']['content'] ?? '');
            
            // Re-attach prefilled '{' if the server omitted it from content
            if (!str_starts_with(trim($content), '{') && !str_contains($content, '{')) {
                $content = "{\n" . ltrim($content);
            }

            $tokensGen = (int)($respObj['eval_count'] ?? ($respObj['usage']['completion_tokens'] ?? 0));
            $finishReason = (string)($respObj['done_reason'] ?? ($respObj['choices'][0]['finish_reason'] ?? 'stop'));
            if ($finishReason === 'length' || $tokensGen >= 1024) $finishReason = 'length';
            elseif ($finishReason === 'stop' || $finishReason === 'done') $finishReason = 'stop';
            else $finishReason = 'other';

            $parsed = extractAndDecodeJson($content);
            if (is_array($parsed) && isset($parsed['answer'], $parsed['ids'])) {
                $parseOk = true;
                $caseStats['parses']++;
                $totalParses++;
            }
        }

        $caseStats['tokens'][] = $tokensGen;
        $caseStats['latencies'][] = $latency;
        $caseStats['finish_reasons'][] = $finishReason;
        $finishReasonCounts[$finishReason] = ($finishReasonCounts[$finishReason] ?? 0) + 1;
        $totalTokensAll += $tokensGen;
        $totalLatencyAll += $latency;

        echo sprintf(
            "   Run %d/%d: %s | Tokens: %d | Latency: %.2fs | Finish: %s\n",
            $run,
            $runsPerQuery,
            $parseOk ? 'PASS (Valid JSON)' : 'FAIL',
            $tokensGen,
            $latency,
            $finishReason
        );
    }
    $resultsByQuery[$qid] = $caseStats;
    echo "\n";
}

echo "====================================================================================\n";
echo " FINAL VALIDATION SUMMARY\n";
echo "====================================================================================\n";
printf("Overall Parse Success Rate : %.1f%% (%d / %d)\n", ($totalParses / max(1, $totalAttempts)) * 100, $totalParses, $totalAttempts);
printf("Average Tokens Generated   : %.1f tokens\n", $totalAttempts > 0 ? $totalTokensAll / $totalAttempts : 0);
printf("Average Latency            : %.2f seconds\n", $totalAttempts > 0 ? $totalLatencyAll / $totalAttempts : 0);
echo "Finish Reason Distribution :\n";
foreach ($finishReasonCounts as $fr => $cnt) {
    printf("  - %-10s : %d (%.1f%%)\n", $fr, $cnt, ($cnt / max(1, $totalAttempts)) * 100);
}
echo "====================================================================================\n";

