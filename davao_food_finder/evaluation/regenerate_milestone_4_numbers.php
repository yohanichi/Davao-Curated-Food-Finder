<?php
declare(strict_types=1);

/**
 * Regenerate Milestone 4 Numbers & Tables from latest_run.json
 * Ensures milestone_4_addition_to_final_paper.md remains perfectly synchronized with experimental run outputs.
 */

$appRoot = dirname(__DIR__);
$resultsDir = __DIR__ . DIRECTORY_SEPARATOR . 'results';

$inputPath = $resultsDir . DIRECTORY_SEPARATOR . 'latest_run.json';
$outputPath = $resultsDir . DIRECTORY_SEPARATOR . 'milestone_4_addition_to_final_paper.md';

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--input=')) {
        $inputPath = substr($arg, strlen('--input='));
    } elseif (str_starts_with($arg, '--output=')) {
        $outputPath = substr($arg, strlen('--output='));
    } elseif ($arg === '--help' || $arg === '-h') {
        echo "Usage: php evaluation/regenerate_milestone_4_numbers.php [--input=PATH] [--output=PATH]\n";
        exit(0);
    }
}

if (!is_file($inputPath)) {
    fwrite(STDERR, "Error: Input run file not found at {$inputPath}.\n");
    exit(1);
}

$rawJson = (string)file_get_contents($inputPath);
$data = json_decode($rawJson, true);
if (!is_array($data)) {
    fwrite(STDERR, "Error: Could not parse JSON from {$inputPath}.\n");
    exit(1);
}

$manifest = is_array($data['reproducibility_manifest'] ?? null) ? $data['reproducibility_manifest'] : [];
$summary = is_array($data['summary'] ?? null) ? $data['summary'] : [];
$agreement = is_array($data['annotator_agreement'] ?? null) ? $data['annotator_agreement'] : [];
$statTests = is_array($data['statistical_tests'] ?? null) ? $data['statistical_tests'] : [];
$usability = is_array($data['usability_study'] ?? null) ? $data['usability_study'] : [];
$errorBreakdown = is_array($data['error_reason_breakdown'] ?? null) ? $data['error_reason_breakdown'] : [];
$hybridCuration = is_array($data['hybrid_curation'] ?? null) ? $data['hybrid_curation'] : [];

$lines = [];
$lines[] = "# Milestone 4: Experimental Evaluation & Empirical Results";
$lines[] = "";
$lines[] = "> **Generated Automatically**: " . gmdate('Y-m-d H:i:s') . " UTC from `" . basename($inputPath) . "`";
$lines[] = "> **Evaluator Version**: " . ($manifest['evaluator_version'] ?? '3.0.0') . " | **PHP**: " . ($manifest['php_version'] ?? PHP_VERSION) . " | **OS**: " . ($manifest['os'] ?? PHP_OS_FAMILY);
$lines[] = "";

// 1. Experimental Setup & Manifest
$lines[] = "## 1. Experimental Setup & Reproducibility Manifest";
$lines[] = "";
$lines[] = "| Parameter | Value |";
$lines[] = "| :--- | :--- |";
$lines[] = "| **Benchmark Split** | `" . ($manifest['split'] ?? 'test') . "` |";
$lines[] = "| **In-Scope Retrieval Cases** | " . ($manifest['in_scope_case_count'] ?? count($summary)) . " |";
$lines[] = "| **Out-of-Scope Safety Cases** | " . ($manifest['scope_case_count'] ?? 0) . " |";
$lines[] = "| **Trials per Configuration** | " . ($manifest['trials'] ?? 1) . " |";
$lines[] = "| **PRNG Seed** | `" . ($manifest['seed'] ?? 42) . "` |";
$lines[] = "| **Cache Policy** | `" . ($manifest['cache_policy'] ?? 'default') . "` |";
$lines[] = "| **API Endpoint** | `" . ($manifest['api_url'] ?? 'local') . "` |";
$lines[] = "| **Ground Truth Version** | `" . ($manifest['ground_truth_version'] ?? '3.0.0') . "` (`" . substr((string)($manifest['ground_truth_sha256'] ?? ''), 0, 12) . "...`) |";
$lines[] = "";

// 2. Table 1: Ranking & Intent Matching Metrics
$lines[] = "## 2. Table 1: Ranking & Intent Matching Metrics";
$lines[] = "";
$lines[] = "Combined Web + Local retrieval metrics with 95% deterministic bootstrap confidence intervals (n=" . ($manifest['in_scope_case_count'] ?? count($summary)) . ").";
$lines[] = "";
$lines[] = "| Config ID | P@5 [95% CI] | NDCG@5 [95% CI] | MRR | R@5 [95% CI] | R@10 | Local P@5 | Web P@5 |";
$lines[] = "| :--- | :---: | :---: | :---: | :---: | :---: | :---: | :---: |";
foreach ($summary as $r) {
    $webP5Str = $r['web_p5_mean'] !== null ? sprintf('%.1f%% (%d)', $r['web_p5_mean'] * 100, $r['web_active_queries'] ?? 0) : 'N/A';
    $lines[] = sprintf(
        "| **%s** | %.1f%% [%.1f, %.1f] | %.1f%% [%.1f, %.1f] | %.3f | %.1f%% [%.1f, %.1f] | %.1f%% | %.1f%% | %s |",
        $r['id'],
        $r['p5']['mean'] * 100, $r['p5']['ci_lower'] * 100, $r['p5']['ci_upper'] * 100,
        $r['ndcg5']['mean'] * 100, $r['ndcg5']['ci_lower'] * 100, $r['ndcg5']['ci_upper'] * 100,
        $r['mrr']['mean'],
        $r['r5']['mean'] * 100, $r['r5']['ci_lower'] * 100, $r['r5']['ci_upper'] * 100,
        $r['r10']['mean'] * 100,
        $r['local_p5_mean'] * 100,
        $webP5Str
    );
}
$lines[] = "";

// 3. Table 2: Constraint Adherence, Scope Safety, Multi-Turn, Faithfulness, Reliability & Cost
$lines[] = "## 3. Table 2: Constraint Adherence, Safety, Faithfulness & Reliability";
$lines[] = "";
$lines[] = "| Config ID | Constr Item% | Scope Acc% | Follow-up% | Faithful% | Fact Corr% | Consistency | Error Rate | Cost/Query |";
$lines[] = "| :--- | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: |";
foreach ($summary as $r) {
    $followStr = $r['followup_resolution_rate'] !== null ? sprintf('%.1f%%', $r['followup_resolution_rate'] * 100) : 'N/A';
    $repStr = $r['repeat_consistency'] !== null ? sprintf('%.1f%%', $r['repeat_consistency'] * 100) : 'N/A (1x)';
    $lines[] = sprintf(
        "| **%s** | %.1f%% | %.1f%% | %s | %.1f%% | %.1f%% | %s | %.1f%% | $%.6f |",
        $r['id'],
        $r['constraint_item_rate']['mean'] * 100,
        $r['scope_rejection_accuracy'] * 100,
        $followStr,
        $r['faithfulness']['mean'] * 100,
        $r['factual_correctness']['mean'] * 100,
        $repStr,
        $r['error_rate'] * 100,
        $r['mean_cost_usd']
    );
}
$lines[] = "";

// 4. Table 3: Latency Decomposition & Cache Behavior
$lines[] = "## 4. Table 3: Latency Decomposition & Cache Behavior";
$lines[] = "";
$lines[] = "| Config ID | Mean (s) | P50 (s) | P95 (s) | P99 (s) | Cold P50 (s) | Warm P50 (s) | Dataset (ms) | PW (ms) | AI (ms) | Cache Hit/Miss |";
$lines[] = "| :--- | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: |";
foreach ($summary as $r) {
    $lat = $r['latency'];
    $coldStr = $lat['cold_p50_s'] !== null ? sprintf('%.2fs', $lat['cold_p50_s']) : '-';
    $warmStr = $lat['warm_p50_s'] !== null ? sprintf('%.2fs', $lat['warm_p50_s']) : '-';
    $lines[] = sprintf(
        "| **%s** | %.2fs | %.2fs | %.2fs | %.2fs | %s | %s | %.1f | %.1f | %.1f | %d / %d |",
        $r['id'],
        $lat['mean_s'],
        $lat['median_p50_s'],
        $lat['p95_s'],
        $lat['p99_s'],
        $coldStr,
        $warmStr,
        $lat['dataset_mean_ms'],
        $lat['playwright_mean_ms'],
        $lat['ai_mean_ms'],
        $r['playwright_cache_hits'] ?? 0,
        $r['playwright_cache_misses'] ?? 0
    );
}
$lines[] = "";

// 5. Table 4: Qualitative Error & Grounding Audit Counts
$lines[] = "## 5. Table 4: Qualitative Error & Grounding Audit Counts";
$lines[] = "";
$lines[] = "| Config ID | Duplicates | Wrong Locs | Missing Src | Fabricated | Unsupported | Constr Fails | Scope Fails |";
$lines[] = "| :--- | :---: | :---: | :---: | :---: | :---: | :---: | :---: |";
foreach ($summary as $r) {
    $et = $r['error_taxonomy'];
    $lines[] = sprintf(
        "| **%s** | %d | %d | %d | %d | %d | %d | %d |",
        $r['id'],
        $et['duplicates'],
        $et['wrong_locations'],
        $et['missing_sources'],
        $et['fabricated_details'],
        $et['unsupported_claims'],
        $et['constraint_failures'],
        $et['scope_violations']
    );
}
$lines[] = "";

// 6. Table 5: Study Objectives Breakdown
$lines[] = "## 6. Table 5: Study Objectives Breakdown (Disambiguation, Price Ceilings, Iterative Loop)";
$lines[] = "";
$lines[] = "| Config ID | Disambig Acc | Trap-Free% | PriceCeil Acc | Price NDCG@5 | Loop Init->Fin | Viol Repaired | Re-Retr% |";
$lines[] = "| :--- | :---: | :---: | :---: | :---: | :---: | :---: | :---: |";
foreach ($summary as $r) {
    $al = $r['agentic_loop_stats'] ?? [];
    $loopStr = !empty($al['enabled'])
        ? sprintf('%.1f%% -> %.1f%%', ($al['initial_compliance_rate'] ?? 0) * 100, ($al['final_compliance_rate'] ?? 0) * 100)
        : 'disabled';
    $repairedStr = !empty($al['enabled']) ? sprintf('%d / %d', $al['violations_repaired'] ?? 0, $al['violations_detected'] ?? 0) : '-';
    $reRetrStr = !empty($al['enabled']) ? sprintf('%.1f%%', ($al['re_retrieval_rate'] ?? 0) * 100) : '-';

    $lines[] = sprintf(
        "| **%s** | %s | %s | %s | %s | %s | %s | %s |",
        $r['id'],
        $r['disambiguation_accuracy'] !== null ? sprintf('%.1f%%', $r['disambiguation_accuracy'] * 100) : 'N/A',
        $r['disambiguation_trap_free_rate'] !== null ? sprintf('%.1f%%', $r['disambiguation_trap_free_rate'] * 100) : 'N/A',
        $r['price_ceiling_compliance_rate'] !== null ? sprintf('%.1f%%', $r['price_ceiling_compliance_rate'] * 100) : 'N/A',
        $r['price_ceiling_ndcg5'] !== null ? sprintf('%.1f%%', $r['price_ceiling_ndcg5'] * 100) : 'N/A',
        $loopStr,
        $repairedStr,
        $reRetrStr
    );
}
$lines[] = "";

// 7. Error Reason Breakdown Per Provider
if (!empty($errorBreakdown)) {
    $lines[] = "## 7. Error Reason Breakdown Per Provider";
    $lines[] = "";
    $lines[] = "| Provider | Total Req | Total Err | Timeout | Ollama Unreach | Empty Resp | Invalid JSON | Schema Valid | PHP Except | HTTP Error |";
    $lines[] = "| :--- | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: |";
    foreach ($errorBreakdown as $pName => $eb) {
        $lines[] = sprintf(
            "| **%s** | %d | %d | %d | %d | %d | %d | %d | %d | %d |",
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
    $lines[] = "";
}

// 8. Hybrid DeepSeek Curation Telemetry
if (!empty($hybridCuration['total_requests'])) {
    $lines[] = "## 8. Hybrid Pipeline DeepSeek Curation Telemetry";
    $lines[] = "";
    $lines[] = sprintf("- **Total Hybrid Requests**: %d", $hybridCuration['total_requests']);
    $lines[] = sprintf("- **Curation Invoked**: %d (%.1f%%)", $hybridCuration['invoked_count'], $hybridCuration['invoked_percent']);
    if (!empty($hybridCuration['triggers'])) {
        $lines[] = "- **Trigger Distribution**:";
        foreach ($hybridCuration['triggers'] as $trig => $cnt) {
            $lines[] = sprintf("  - `%s`: %d (%.1f%%)", $trig, $cnt, ($cnt / max(1, $hybridCuration['invoked_count'])) * 100.0);
        }
    }
    $lines[] = "";
}

// 9. Statistical Significance Analysis
$lines[] = "## 9. Statistical Significance Analysis";
$lines[] = "";
if (!empty($statTests['friedman'])) {
    $lines[] = "### Friedman Omnibus Test";
    $lines[] = "";
    $lines[] = "| Metric | Q Statistic | df | p-value | Significant (alpha=0.05)? |";
    $lines[] = "| :--- | :---: | :---: | :---: | :---: |";
    foreach ($statTests['friedman'] as $mKey => $fTest) {
        if ($fTest === null) continue;
        $lines[] = sprintf(
            "| `%s` | %.4f | %d | %.6f | %s |",
            $mKey,
            $fTest['statistic'],
            $fTest['degrees_of_freedom'],
            $fTest['p_value'],
            $fTest['p_value'] < 0.05 ? '**YES**' : 'no'
        );
    }
    $lines[] = "";
}

if (!empty($statTests['wilcoxon_posthoc'])) {
    $lines[] = "### Pairwise Wilcoxon Signed-Rank Post-Hoc (Holm-Bonferroni Corrected)";
    $lines[] = "";
    foreach ($statTests['wilcoxon_posthoc'] as $mKey => $pairs) {
        if (!$pairs) continue;
        $lines[] = "#### Metric: `{$mKey}`";
        $lines[] = "";
        $lines[] = "| Config A | Config B | Mean Diff | W+ / W- | Raw p | Holm p | Effect Size r | Sig? |";
        $lines[] = "| :--- | :--- | :---: | :---: | :---: | :---: | :---: | :---: |";
        foreach ($pairs as $p) {
            $lines[] = sprintf(
                "| %s | %s | %+.2f%% | %.0f / %.0f | %.5f | %.5f | %+.3f | %s |",
                $p['config_a'],
                $p['config_b'],
                $p['mean_diff'] * 100,
                $p['w_plus'],
                $p['w_minus'],
                $p['p_value'],
                $p['p_holm'],
                $p['effect_size_r'],
                $p['significant_005'] ? '**YES\***' : 'no'
            );
        }
        $lines[] = "";
    }
}

// 10. Annotator Agreement
if (!empty($agreement['source']) && $agreement['source'] !== 'none') {
    $lines[] = "## 10. Inter-Annotator Agreement";
    $lines[] = "";
    $lines[] = sprintf("- **Source**: `%s` (n=%d overlapping items)", $agreement['source'], $agreement['overlapping_items'] ?? 0);
    if (!empty($agreement['cohens_kappa_weighted'])) {
        $lines[] = sprintf("- **Cohen's Weighted Kappa**: `%.3f` (%s)", $agreement['cohens_kappa_weighted']['kappa'] ?? 0.0, $agreement['cohens_kappa_weighted']['interpretation'] ?? '');
    }
    if (!empty($agreement['fleiss_kappa'])) {
        $lines[] = sprintf("- **Fleiss' Kappa**: `%.3f`", $agreement['fleiss_kappa']['kappa'] ?? 0.0);
    }
    $lines[] = "";
}

// 11. Usability Study
if (!empty($usability['total_participants'])) {
    $lines[] = "## 11. Human Usability Study Summary";
    $lines[] = "";
    $lines[] = sprintf("- **Total Participants**: %d across %d sessions", $usability['total_participants'], $usability['total_sessions'] ?? 0);
    if (isset($usability['sus_score']['mean'])) {
        $lines[] = sprintf("- **System Usability Scale (SUS)**: %.2f / 100 (%s)", $usability['sus_score']['mean'], $usability['sus_score']['adjective_rating'] ?? '');
    }
    if (isset($usability['task_completion_rate'])) {
        $lines[] = sprintf("- **Task Completion Rate**: %.1f%%", $usability['task_completion_rate'] * 100);
    }
    if (isset($usability['single_ease_question_seq']['mean'])) {
        $lines[] = sprintf("- **Single Ease Question (SEQ)**: %.2f / 7", $usability['single_ease_question_seq']['mean']);
    }
    $lines[] = "";
}

$content = implode("\n", $lines) . "\n";
file_put_contents($outputPath, $content, LOCK_EX);
echo "Regenerated paper results from {$inputPath} -> {$outputPath}\n";

