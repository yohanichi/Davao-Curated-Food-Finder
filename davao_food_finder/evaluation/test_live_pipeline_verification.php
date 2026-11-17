<?php
declare(strict_types=1);

require_once __DIR__ . '/run_live_pipeline_verification.php';

$passed = 0;
$failed = 0;
$check = static function (bool $condition, string $label) use (&$passed, &$failed): void {
    if ($condition) {
        $passed++;
    } else {
        $failed++;
        fwrite(STDERR, "FAIL: {$label}\n");
    }
};

$case = ['id'=>'fixture', 'query'=>'Exact sample request', 'controlled_violation'=>false];
$liveData = [
    'assistantStatus'=>'ready',
    'answer'=>'A grounded response.',
    'modelUsed'=>'gpt-4o-mini+deepseek-r1:8b',
    'sources'=>[['url'=>'https://example.test/restaurant?id=2']],
    'metrics'=>[
        'total_latency_ms'=>1234,
        'timings_ms'=>['ai_stages_ms'=>[
            'hybrid_query_planner'=>10,
            'hybrid_cleaner'=>20,
            'hybrid_curator'=>30,
            'hybrid_final'=>40,
        ], 'playwright_ms'=>100],
        'playwright'=>['success'=>true, 'browser_used'=>true, 'browser_result_count'=>3, 'cache'=>'miss', 'result_count'=>3, 'latency_ms'=>100],
        'pipeline'=>[
            'stages'=>[
                '1_gpt4o_query_planner'=>'ok',
                '2_playwright_scraper'=>'ok',
                '3_gpt4o_structurer'=>'ok',
                '4_deepseek_r1_reasoner'=>'ok',
                '5_gpt4o_final_reply'=>'ok',
            ],
            'stage_models'=>[
                'hybrid_query_planner'=>'gpt-4o-mini-2024-07-18',
                'hybrid_cleaner'=>'gpt-4o-mini-2024-07-18',
                'hybrid_final'=>'gpt-4o-mini-2024-07-18',
            ],
            'planner_used'=>true,
            'planner_used'=>true,
            'planned_query'=>'Davao City restaurants',
            'deepseek_reasoner'=>['backend'=>'local_ollama', 'model'=>'deepseek-r1:8b'],
        ],
        'agentic_loop'=>[
            'enabled'=>true,
            'iterations'=>1,
            'initial_compliance_rate'=>1,
            'final_compliance_rate'=>1,
            'violations_detected'=>[],
            'violations_repaired'=>0,
            're_retrieved'=>false,
        ],
    ],
];
$response = ['http_status'=>200, 'data'=>$liveData, 'api_url'=>'http://127.0.0.1:8080/api.php'];
$full = livePipelineBuildResult($case, $response, 1300);
$check($full['all_five_stages_succeeded_on_live_request'], 'accepts five successful stages on a real cache-miss response');
$check($full['execution_class'] === 'full_live_pipeline', 'classifies complete pipeline distinctly');
$check($full['stages']['4_deepseek_r1_reasoner']['backend'] === 'local_ollama', 'records the observed reasoner backend');
$check($full['stages']['4_deepseek_r1_reasoner']['model_matches'], 'accepts the observed DeepSeek R1 model');
$check($full['stages']['2_playwright_retrieval']['browser_used'], 'records actual Playwright browser execution');
$check($full['source_urls'] === ['https://example.test/restaurant?id=2'], 'records source URLs');
$check($full['stages']['1_gpt4o_mini_planner']['planned_query'] === 'Davao City restaurants', 'records the query returned by the planner');
$check($full['configuration']['evidence_override_used'] === false, 'records that no evidence override was used');
$impossibleBudgetEvidence = liveImpossibleBudgetResponseEvidence(
    'No Japanese restaurant can serve a full meal under ₱1. The cheapest verified meal prices start around ₱175. For instance, Sukidesu offers meals from ₱200.',
    1
);
$check(
    $impossibleBudgetEvidence['no_match_claim']
        && $impossibleBudgetEvidence['above_budget_alternative_context']
        && $impossibleBudgetEvidence['alternative_price_above_budget'],
    'recognizes an impossible-budget rejection with a priced, higher-budget alternative'
);
$misleadingBudgetEvidence = liveImpossibleBudgetResponseEvidence(
    'This Japanese restaurant costs ₱200 and fits your budget under ₱1.',
    1
);
$check(
    !$misleadingBudgetEvidence['no_match_claim'] || !$misleadingBudgetEvidence['alternative_price_above_budget'],
    'rejects a claim that an above-budget recommendation fits an impossible budget'
);
$impossibleBudgetCase = [
    'id'=>'impossible_budget',
    'query'=>'Japanese restaurant under ₱1',
    'controlled_violation'=>false,
    'behavior_check'=>'impossible_budget_refusal',
    'max_price_php'=>1,
];
$impossibleBudgetData = $liveData;
$impossibleBudgetData['answer'] = 'No Japanese restaurants have a full meal under ₱1. The most affordable option is around ₱200.';
$impossibleBudget = livePipelineBuildResult(
    $impossibleBudgetCase,
    ['http_status'=>200, 'data'=>$impossibleBudgetData],
    1300
);
$check($impossibleBudget['behavior_check']['status'] === 'passed', 'passes a correctly separated impossible-budget response');
$check($impossibleBudget['controlled_violation_status'] === 'not_applicable', 'does not classify a correct refusal as a controlled violation');
$requestedHosted = livePipelineBuildResult($case, $response, 1300, ['deepseek_backend'=>'hosted-api']);
$check(!$requestedHosted['all_five_stages_succeeded_on_live_request'], 'does not count a backend mismatch as full-pipeline success');
$check(!$requestedHosted['configuration']['deepseek_backend_request_matches_observed'], 'records actual backend mismatch against the requested backend');

$cachedData = $liveData;
$cachedData['metrics']['playwright']['cache'] = 'hit';
$cached = livePipelineBuildResult($case, ['http_status'=>200, 'data'=>$cachedData], 1300);
$check(!$cached['all_five_stages_succeeded_on_live_request'], 'cached evidence cannot qualify as a full live pipeline run');
$check($cached['execution_class'] === 'partial_or_fallback', 'cached request is reported as partial');

$unprovenPlannerData = $liveData;
unset($unprovenPlannerData['metrics']['pipeline']['planner_used']);
$unprovenPlanner = livePipelineBuildResult($case, ['http_status'=>200, 'data'=>$unprovenPlannerData], 1300);
$check(!$unprovenPlanner['stages']['1_gpt4o_mini_planner']['succeeded'], 'does not count the planner without explicit usable planner telemetry');

$noSourceData = $liveData;
$noSourceData['sources'] = [];
$noSourceData['metrics']['playwright']['result_count'] = 0;
$noSource = livePipelineBuildResult($case, ['http_status'=>200, 'data'=>$noSourceData], 1300);
$check(!$noSource['stages']['2_playwright_retrieval']['succeeded'], 'does not count retrieval without evidence and source URLs');

$noBrowserData = $liveData;
$noBrowserData['metrics']['playwright']['browser_used'] = false;
$noBrowser = livePipelineBuildResult($case, ['http_status'=>200, 'data'=>$noBrowserData], 1300);
$check(!$noBrowser['stages']['2_playwright_retrieval']['succeeded'], 'does not count HTTP-only evidence as a Playwright retrieval');

$fallbackData = $liveData;
$fallbackData['metrics']['pipeline']['stages']['4_deepseek_r1_reasoner'] = 'unavailable';
$fallbackData['metrics']['pipeline']['deepseek_reasoner'] = ['backend'=>'not_run', 'model'=>null];
$fallback = livePipelineBuildResult($case, ['http_status'=>200, 'data'=>$fallbackData], 1300);
$check($fallback['request_success'] && !$fallback['all_five_stages_succeeded_on_live_request'], 'ready fallback output is not counted as five-stage success');

$hostedFlashData = $liveData;
$hostedFlashData['modelUsed'] = 'gpt-4o-mini+deepseek-flash';
$hostedFlashData['metrics']['pipeline']['deepseek_reasoner'] = ['backend'=>'hosted_api', 'model'=>'deepseek-flash'];
$hostedFlash = livePipelineBuildResult($case, ['http_status'=>200, 'data'=>$hostedFlashData], 1300, ['deepseek_backend'=>'hosted-api']);
$check($hostedFlash['stages']['4_deepseek_r1_reasoner']['succeeded'], 'accepts the supported paid DeepSeek Flash model');
$check($hostedFlash['all_five_stages_succeeded_on_live_request'], 'paid DeepSeek Flash can qualify for full-pipeline success');
$unsupportedHostedData = $hostedFlashData;
$unsupportedHostedData['metrics']['pipeline']['deepseek_reasoner'] = ['backend'=>'hosted_api', 'model'=>'deepseek-reasoner'];
$unsupportedHosted = livePipelineBuildResult($case, ['http_status'=>200, 'data'=>$unsupportedHostedData], 1300, ['deepseek_backend'=>'hosted-api']);
$check(!$unsupportedHosted['stages']['4_deepseek_r1_reasoner']['succeeded'], 'rejects hosted models outside the supported paid model list');
$wrongLocalR1Data = $liveData;
$wrongLocalR1Data['metrics']['pipeline']['deepseek_reasoner'] = ['backend'=>'local_ollama', 'model'=>'deepseek-r1:1.5b'];
$wrongLocalR1 = livePipelineBuildResult($case, ['http_status'=>200, 'data'=>$wrongLocalR1Data], 1300);
$check(!$wrongLocalR1['stages']['4_deepseek_r1_reasoner']['succeeded'], 'local mode requires the configured deepseek-r1:8b model');

$deepseekProviderMetrics = [
    'deepseek'=>['success'=>true, 'error'=>null],
    'deepseek_api'=>['configured'=>true],
    'timings_ms'=>['ai_stages_ms'=>['deepseek_api'=>25]],
    'pipeline'=>['stage_models'=>['deepseek_api'=>'deepseek-flash']],
];
$deepseekProviderCall = liveProviderCallEvidence('deepseek', $deepseekProviderMetrics, true);
$check($deepseekProviderCall['succeeded'], 'verifies a usable live DeepSeek R1 provider call');
$check($deepseekProviderCall['backend'] === 'hosted_api', 'records the selected DeepSeek hosted backend');
$wrongBackendCall = liveProviderCallEvidence('deepseek', $deepseekProviderMetrics, true, 'local-ollama');
$check(!$wrongBackendCall['succeeded'], 'rejects a provider backend that differs from the requested backend');
$deepseekProviderMetrics['pipeline']['stage_models']['deepseek_api'] = 'deepseek-reasoner';
$wrongDeepseekProviderCall = liveProviderCallEvidence('deepseek', $deepseekProviderMetrics, true);
$check(!$wrongDeepseekProviderCall['succeeded'], 'rejects an unsupported paid model for the live DeepSeek provider');

$gptProviderMetrics = [
    'gpt4o'=>['success'=>true, 'model'=>'gpt-4o-mini'],
    'timings_ms'=>['ai_stages_ms'=>['gpt4o_single'=>18]],
    'pipeline'=>['stage_models'=>['gpt4o_single'=>'gpt-4o-mini-2024-07-18']],
];
$gptProviderCall = liveProviderCallEvidence('gpt-4o-mini', $gptProviderMetrics, true);
$check($gptProviderCall['succeeded'], 'verifies a usable live GPT-4o mini provider call');

$violationCase = [
    'id'=>'controlled',
    'query'=>'Japanese meal under ₱250',
    'controlled_violation'=>true,
    'behavior_check'=>'price_violation_repair',
    'max_price_php'=>250,
];
$violationData = $liveData;
$violationData['metrics']['agentic_loop']['violations_detected'] = ['price_ceiling_exceeded:Venue A'];
$violationData['metrics']['agentic_loop']['violations_repaired'] = 1;
$violation = livePipelineBuildResult($violationCase, ['http_status'=>200, 'data'=>$violationData], 1300);
$check($violation['controlled_violation_detected'] && $violation['controlled_violation_repaired'], 'reports controlled violation detection and repair separately');
$check($violation['behavior_check']['status'] === 'detected_and_repaired', 'classifies a realistic price-ceiling repair separately');
$unavailableControlled = livePipelineBuildResult(
    $violationCase,
    ['http_status'=>0, 'data'=>[], 'error_category'=>'api_unreachable_or_timeout'],
    10
);
$check($unavailableControlled['controlled_violation_status'] === 'not_observed', 'does not treat unavailable requests as a negative violation result');
$check($unavailableControlled['loop']['status'] === 'not_observed', 'distinguishes an unobserved loop from a disabled loop');

$check(
    livePipelineSafeUrl('https://user:pass@example.test/search?token=hidden&q=food') === 'https://example.test/search?q=food',
    'redacts URL credentials and secret query parameters'
);

$cliOptions = parseLivePipelineArguments([
    '--start-local-server',
    '--port=8091',
    '--deepseek-backend=local-ollama',
    '--output-dir=C:\\temp\\live-results',
]);
$check($cliOptions['api_url'] === 'http://127.0.0.1:8091/api.php', 'builds local API URL from CLI port');
$check($cliOptions['deepseek_backend'] === 'local-ollama', 'accepts explicit local backend selection');
$invalidOptionsRejected = false;
try {
    parseLivePipelineArguments(['--deepseek-backend=hosted-api']);
} catch (InvalidArgumentException $error) {
    $invalidOptionsRejected = true;
}
$check($invalidOptionsRejected, 'requires local server startup for backend selection');

echo "Live pipeline verification tests: {$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
