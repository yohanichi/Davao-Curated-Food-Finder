<?php
declare(strict_types=1);

$appRoot = 'C:/Users/cyber/OneDrive/Documents/Thesis20261028-main/Davao-Food-Finder-main/Davao-Food-Finder-main/davao_food_finder';
require_once $appRoot . '/src/config.php';
require_once $appRoot . '/src/search.php';
require_once $appRoot . '/src/web_reasoner.php';

$places = loadDavaoPlaces($appRoot . '/data');

$testCases = [
    [
        'id' => 'Q001 (cuisine)',
        'query' => 'Best coffee shops and cafes in Davao City',
        'history' => [],
        'expect_scope' => false,
    ],
    [
        'id' => 'Q014 (budget)',
        'query' => 'Affordable budget food under 150 pesos in Davao City',
        'history' => [],
        'expect_scope' => false,
    ],
    [
        'id' => 'Q017 (location)',
        'query' => 'Restaurants near Torres Street Davao City',
        'history' => [],
        'expect_scope' => false,
    ],
    [
        'id' => 'Q026 (hours)',
        'query' => 'Late night 24/7 food spots open past midnight in Davao City',
        'history' => [],
        'expect_scope' => false,
    ],
    [
        'id' => 'Q030 (dietary)',
        'query' => 'Halal certified or Muslim-friendly food in Davao City',
        'history' => [],
        'expect_scope' => false,
    ],
    [
        'id' => 'S001 (scope)',
        'query' => 'Best seafood restaurants in Tagum City',
        'history' => [],
        'expect_scope' => true,
    ],
    [
        'id' => 'MT001 (multi-turn)',
        'query' => 'any cheaper ones under 150?',
        'history' => [
            ['role' => 'user', 'content' => 'sisig near jacinto street'],
            ['role' => 'assistant', 'content' => 'Here are some spots near Jacinto...']
        ],
        'expect_scope' => false,
    ]
];

echo "Testing diverse query categories with DeepSeek (Ollama)...\n";
echo str_repeat("=", 80) . "\n";

$passCount = 0;
$failCount = 0;

foreach ($testCases as $idx => $tc) {
    $qId = $tc['id'];
    $query = $tc['query'];
    $history = $tc['history'];
    
    $intent = parseQueryIntent($query, $history);
    $candidates = shortlistPlaces($places, $query, 16, $intent);
    
    $t0 = microtime(true);
    [$answer, $rankedIds, $status, $sources, $webPlaces, $outOfScope, $model] = answerWithDeepSeek(
        $query,
        $history,
        $candidates,
        ['results'=>[], 'sources'=>[]],
        ['agentic'=>true, 'intent'=>$intent]
    );
    $elapsed = round(microtime(true) - $t0, 2);
    
    $isScopeOk = ($outOfScope === $tc['expect_scope']);
    $isReady = ($status === 'ready');
    $hasContent = (trim($answer) !== '');
    
    $pass = $isReady && $isScopeOk && $hasContent;
    
    if ($pass) {
        $passCount++;
        echo sprintf("  [%d/%d] %-20s | PASS | %5.2fs | Status: %-5s | OutOfScope: %s | Matches: %d\n", 
            $idx + 1, count($testCases), $qId, $elapsed, $status, $outOfScope ? 'YES' : 'NO', count($rankedIds));
        echo "        Answer: " . mb_substr($answer, 0, 110) . "...\n";
    } else {
        $failCount++;
        echo sprintf("  [%d/%d] %-20s | FAIL | %5.2fs | Status: %-5s | OutOfScope: %s (expected %s) | Error: %s\n", 
            $idx + 1, count($testCases), $qId, $elapsed, $status, $outOfScope ? 'YES' : 'NO', $tc['expect_scope'] ? 'YES' : 'NO', $GLOBALS['last_deepseek_error'] ?? 'None');
    }
}

echo str_repeat("=", 80) . "\n";
echo "Results: {$passCount} passed, {$failCount} failed out of " . count($testCases) . " test cases.\n";

