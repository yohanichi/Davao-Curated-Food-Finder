<?php
declare(strict_types=1);

$appRoot = dirname(__DIR__);
require_once $appRoot . '/vendor/autoload.php';
require_once $appRoot . '/src/search.php';
require_once __DIR__ . '/statistics.php';
require_once __DIR__ . '/metrics.php';

function esc(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$annotationsDir = __DIR__ . DIRECTORY_SEPARATOR . 'annotations';
if (!is_dir($annotationsDir)) @mkdir($annotationsDir, 0775, true);

$groundTruthPath = __DIR__ . '/ground_truth.json';
$gtRaw = is_file($groundTruthPath) ? json_decode((string)file_get_contents($groundTruthPath), true) : null;
$validatedGt = is_array($gtRaw) ? validateAndNormalizeGroundTruth($gtRaw) : ['cases'=>[], 'scope_cases'=>[]];
$cases = array_values(array_filter($validatedGt['cases'], fn($c) => empty($c['expect_out_of_scope'])));

$dataDir = $appRoot . DIRECTORY_SEPARATOR . 'data';
$placesCachePath = $dataDir . DIRECTORY_SEPARATOR . '.places-cache.json';
$places = [];
if (is_file($placesCachePath)) {
    $cachedObj = json_decode((string)file_get_contents($placesCachePath), true);
    if (is_array($cachedObj['places'] ?? null)) $places = $cachedObj['places'];
}
if (!$places) $places = loadDavaoPlaces($dataDir);

$annotatorId = preg_replace('/[^a-zA-Z0-9_-]/', '', trim((string)($_REQUEST['annotator_id'] ?? 'annotator_1'))) ?: 'annotator_1';
$annotatorId = substr($annotatorId, 0, 32);
$selectedCaseId = trim((string)($_REQUEST['case_id'] ?? ($cases[0]['id'] ?? 'Q001')));

$selectedCase = null;
foreach ($cases as $c) {
    if ($c['id'] === $selectedCaseId) {
        $selectedCase = $c;
        break;
    }
}
if (!$selectedCase && $cases) {
    $selectedCase = $cases[0];
    $selectedCaseId = $selectedCase['id'];
}

$flashMessage = '';

// Save submitted candidate relevance grades + optional blind answer quality ratings
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['save_annotations']) && $selectedCase) {
    $annotatorFile = $annotationsDir . DIRECTORY_SEPARATOR . $annotatorId . '.json';
    $existing = is_file($annotatorFile) ? json_decode((string)file_get_contents($annotatorFile), true) : null;
    if (!is_array($existing)) {
        $existing = [
            'annotator_id'=>$annotatorId,
            'created_at_utc'=>gmdate('c'),
            'ratings'=>[],
            'blind_system_scores'=>[],
        ];
    }
    $byKey = [];
    foreach (($existing['ratings'] ?? []) as $r) {
        if (is_array($r) && !empty($r['item_key'])) $byKey[$r['item_key']] = $r;
    }

    $gradesInput = is_array($_POST['grades'] ?? null) ? $_POST['grades'] : [];
    $namesInput = is_array($_POST['place_names'] ?? null) ? $_POST['place_names'] : [];
    $savedCount = 0;
    foreach ($gradesInput as $normName => $gradeVal) {
        if ($gradeVal === '' || !is_numeric($gradeVal)) continue;
        $g = max(0, min(3, (int)$gradeVal));
        $itemKey = $selectedCase['id'] . '::' . preg_replace('/[^a-z0-9]/', '', strtolower((string)$normName));
        $byKey[$itemKey] = [
            'item_key'=>$itemKey,
            'case_id'=>$selectedCase['id'],
            'restaurant_name'=>(string)($namesInput[$normName] ?? $normName),
            'relevance_grade'=>$g,
            'updated_at_utc'=>gmdate('c'),
        ];
        $savedCount++;
    }
    $existing['ratings'] = array_values($byKey);
    $existing['updated_at_utc'] = gmdate('c');

    // Blind system quality scores (1-5 Likert + faithfulness + constraint adherence)
    $blindQuality = is_array($_POST['blind_quality'] ?? null) ? $_POST['blind_quality'] : [];
    $blindFaithful = is_array($_POST['blind_faithful'] ?? null) ? $_POST['blind_faithful'] : [];
    $blindTokens = is_array($_POST['blind_token'] ?? null) ? $_POST['blind_token'] : [];
    $blindMap = [];
    foreach (($existing['blind_system_scores'] ?? []) as $bs) {
        if (is_array($bs) && !empty($bs['score_key'])) $blindMap[$bs['score_key']] = $bs;
    }
    foreach ($blindQuality as $slotIdx => $qScore) {
        if ($qScore === '' || !is_numeric($qScore)) continue;
        $token = (string)($blindTokens[$slotIdx] ?? '');
        $decodedSys = base64_decode($token, true);
        if (!is_string($decodedSys) || !in_array($decodedSys, ['baseline', 'non-agentic', 'deepseek', 'gpt-4o-mini', 'hybrid'], true)) continue;
        $scoreKey = $selectedCase['id'] . '::' . $decodedSys;
        $blindMap[$scoreKey] = [
            'score_key'=>$scoreKey,
            'case_id'=>$selectedCase['id'],
            'system'=>$decodedSys, // Stored in backend JSON; hidden in UI during blind rating
            'answer_quality_1_to_5'=>max(1, min(5, (int)$qScore)),
            'faithful_to_evidence'=>(int)($blindFaithful[$slotIdx] ?? 1) === 1,
            'updated_at_utc'=>gmdate('c'),
        ];
    }
    $existing['blind_system_scores'] = array_values($blindMap);

    file_put_contents($annotatorFile, json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
    $flashMessage = "Saved {$savedCount} relevance judgment(s) for {$selectedCase['id']} under annotator '{$annotatorId}'.";
}

// Build candidate pool for selected case (union of deterministic baseline top-6 + intent shortlist top-6, shuffled deterministically by case_id so rank position doesn't bias annotators)
$candidatePool = [];
if ($selectedCase) {
    $poolMap = [];
    foreach (shortlistPlacesBaseline($places, $selectedCase['query'], 6) as $p) {
        $poolMap[normalizePlaceName($p['name'])] = $p;
    }
    foreach (shortlistPlaces($places, $selectedCase['query'], 6, parseQueryIntent($selectedCase['query'])) as $p) {
        $poolMap[normalizePlaceName($p['name'])] = $p;
    }
    $candidatePool = array_values($poolMap);
    usort($candidatePool, fn($a, $b) => strcmp(hash('sha256', $selectedCase['id'] . '|' . $a['name']), hash('sha256', $selectedCase['id'] . '|' . $b['name'])));
}

// Load current annotator's saved grades & blind scores
$currentAnnotatorGrades = [];
$currentBlindScores = [];
$currentAnnotatorFile = $annotationsDir . DIRECTORY_SEPARATOR . $annotatorId . '.json';
if (is_file($currentAnnotatorFile)) {
    $curData = json_decode((string)file_get_contents($currentAnnotatorFile), true);
    foreach (($curData['ratings'] ?? []) as $r) {
        if (($r['case_id'] ?? '') === $selectedCaseId) {
            $parts = explode('::', (string)$r['item_key'], 2);
            if (isset($parts[1])) $currentAnnotatorGrades[$parts[1]] = (int)$r['relevance_grade'];
        }
    }
    foreach (($curData['blind_system_scores'] ?? []) as $bs) {
        if (($bs['case_id'] ?? '') === $selectedCaseId && !empty($bs['system'])) {
            $currentBlindScores[(string)$bs['system']] = $bs;
        }
    }
}

// Build anonymized, deterministically shuffled blind system outputs from latest_run.json (if present)
$blindSystemOutputs = [];
$latestRunFile = __DIR__ . '/results/latest_run.json';
if ($selectedCase && is_file($latestRunFile)) {
    $latestData = json_decode((string)file_get_contents($latestRunFile), true);
    $summaryByConfig = [];
    foreach (($latestData['summary'] ?? []) as $sr) {
        if (!empty($sr['id']) && !empty($sr['provider'])) $summaryByConfig[$sr['id']] = (string)$sr['provider'];
    }
    $seenProviders = [];
    foreach (($latestData['detailed_runs'] ?? []) as $cfgId => $cfgRuns) {
        $prov = $summaryByConfig[$cfgId] ?? '';
        if ($prov === '' || isset($seenProviders[$prov])) continue;
        $firstTrial = $cfgRuns['cases'][$selectedCase['id']][0] ?? null;
        if (!is_array($firstTrial) || !empty($firstTrial['failed'])) continue;
        $seenProviders[$prov] = true;
        $ansText = (string)($firstTrial['sample_answer'] ?? '');
        // Redact any accidental model self-references to preserve blind evaluation
        $ansText = preg_replace('/\b(DeepSeek[- ]?R1|GPT-4o(\s+mini)?|OpenAI|Ollama|deterministic[- ]local[- ]baseline)\b/iu', '[Assistant]', $ansText) ?? $ansText;
        $blindSystemOutputs[] = [
            'provider'=>$prov,
            'token'=>base64_encode($prov),
            'answer'=>$ansText,
            'top5'=>$firstTrial['sample_top5_display'] ?? [],
            'sort_hash'=>hash('sha256', 'blind:' . $selectedCase['id'] . ':' . $prov),
        ];
    }
    usort($blindSystemOutputs, fn($a, $b) => strcmp($a['sort_hash'], $b['sort_hash']));
}

// Compute live Inter-Annotator Agreement across all saved human files in evaluation/annotations/*.json
$humanFiles = glob($annotationsDir . '/*.json') ?: [];
$byRater = [];
$blindAggregates = [];
foreach ($humanFiles as $hf) {
    $d = json_decode((string)file_get_contents($hf), true);
    if (!is_array($d) || empty($d['annotator_id'])) continue;
    $rid = (string)$d['annotator_id'];
    foreach (($d['ratings'] ?? []) as $r) {
        if (!empty($r['item_key']) && isset($r['relevance_grade'])) {
            $byRater[$rid][(string)$r['item_key']] = (int)$r['relevance_grade'];
        }
    }
    foreach (($d['blind_system_scores'] ?? []) as $bs) {
        $sys = (string)($bs['system'] ?? '');
        if ($sys !== '' && isset($bs['answer_quality_1_to_5'])) {
            $blindAggregates[$sys]['scores'][] = (int)$bs['answer_quality_1_to_5'];
            $blindAggregates[$sys]['faithful'][] = !empty($bs['faithful_to_evidence']) ? 1.0 : 0.0;
        }
    }
}

$liveKappa = null;
$liveFleiss = null;
$commonCount = 0;
if (count($byRater) >= 2) {
    $rIds = array_keys($byRater);
    $common = array_keys($byRater[$rIds[0]]);
    for ($i = 1; $i < count($rIds); $i++) {
        $common = array_values(array_intersect($common, array_keys($byRater[$rIds[$i]])));
    }
    $commonCount = count($common);
    if ($commonCount >= 2) {
        $a = []; $b = []; $mat = [];
        foreach ($common as $k) {
            $a[] = $byRater[$rIds[0]][$k];
            $b[] = $byRater[$rIds[1]][$k];
            $row = [];
            foreach ($rIds as $rid) $row[] = $byRater[$rid][$k];
            $mat[] = $row;
        }
        $liveKappa = [
            'pair'=>$rIds[0] . ' vs ' . $rIds[1],
            'unweighted'=>cohensKappa($a, $b, false),
            'weighted'=>cohensKappa($a, $b, true),
        ];
        $liveFleiss = fleissKappa($mat);
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Blind Human Annotation & Relevance Rubric - Davao Food Finder</title>
<style>
body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;margin:0;background:#f5f7f4;color:#20251f}
.wrap{max-width:1180px;margin:auto;padding:28px}
.card{background:#fff;border:1px solid #e2e7df;border-radius:14px;padding:18px;margin-bottom:18px;box-shadow:0 8px 24px #263d2810}
button,.btn-link{display:inline-block;border:0;border-radius:9px;background:#185d43;color:#fff;padding:10px 16px;font-weight:700;cursor:pointer;text-decoration:none;font-size:13px}
.btn-secondary{background:#edf4ed;color:#185d43;border:1px solid #c6dacb}
table{border-collapse:collapse;width:100%;font-size:13px}th,td{border-bottom:1px solid #e5e9e2;padding:10px;text-align:left;vertical-align:middle}
th{background:#edf3ec;color:#315f43}.note{color:#5e685f;font-size:13px;line-height:1.5}
input[type=text],select{padding:8px 10px;border:1px solid #ccd5c9;border-radius:8px;font:inherit;font-size:13px}
.flash{background:#e7f6ec;border:1px solid #9ad0ae;color:#13532c;padding:12px 15px;border-radius:10px;margin-bottom:16px;font-weight:600}
.rubric-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:10px;margin-top:10px}
.rubric-box{background:#f8faf7;border:1px solid #dfe6dc;border-radius:10px;padding:10px 12px;font-size:12.5px}
</style>
</head>
<body>
<main class="wrap">
<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:14px">
  <div>
    <h1 style="margin:0 0 4px">Blind Human Annotation &amp; Kappa Verification</h1>
    <p class="note" style="margin:0">Collect independent multi-annotator relevance grades (0–3) and blind answer quality scores (1–5) without revealing model/system identities.</p>
  </div>
  <a class="btn-link btn-secondary" href="dashboard.php">← Back to Metrics Dashboard</a>
</div>

<?php if ($flashMessage !== ''): ?>
  <div class="flash"><?=esc($flashMessage)?></div>
<?php endif ?>

<section class="card">
  <h2 style="margin:0 0 6px;font-size:16px">Standardized Relevance Grading Rubric (0–3)</h2>
  <div class="rubric-grid">
    <div class="rubric-box"><strong>Grade 3 — Highly Relevant:</strong> Verified in Davao City AND satisfies <em>all</em> primary and secondary constraints (cuisine, neighborhood/mall, budget, dietary, ambiance, rating, hours).</div>
    <div class="rubric-box"><strong>Grade 2 — Relevant:</strong> Verified in Davao City AND satisfies the primary intent, with minor secondary attribute unverified or partial.</div>
    <div class="rubric-box"><strong>Grade 1 — Marginally Relevant:</strong> Verified in Davao City with broader category overlap, but misses a requested neighborhood, budget, or specialty constraint.</div>
    <div class="rubric-box"><strong>Grade 0 — Non-Relevant:</strong> Unrelated food type, violates dietary/exclusion constraint, or located outside Davao City.</div>
  </div>
</section>

<form method="get" class="card" style="display:flex;flex-wrap:wrap;gap:14px;align-items:center">
  <label><strong>Annotator ID:</strong>
    <input type="text" name="annotator_id" value="<?=esc($annotatorId)?>" placeholder="e.g. rater_1" required>
  </label>
  <label><strong>Benchmark Query:</strong>
    <select name="case_id" onchange="this.form.submit()">
      <?php foreach ($cases as $c): ?>
        <option value="<?=esc($c['id'])?>" <?=$c['id']===$selectedCaseId?'selected':''?>>
          <?=esc("{$c['id']} [{$c['split']}/{$c['category']}] — " . mb_substr($c['query'], 0, 72))?>
        </option>
      <?php endforeach ?>
    </select>
  </label>
  <button type="submit" class="btn-secondary">Load Case</button>
</form>

<?php if ($selectedCase): ?>
<form method="post" class="card">
  <input type="hidden" name="annotator_id" value="<?=esc($annotatorId)?>">
  <input type="hidden" name="case_id" value="<?=esc($selectedCase['id'])?>">
  <input type="hidden" name="save_annotations" value="1">

  <h2 style="margin-top:0">Query <?=esc($selectedCase['id'])?>: “<?=esc($selectedCase['query'])?>”</h2>
  <?php if (!empty($selectedCase['turns'])): ?>
    <p class="note"><strong>Prior Dialogue Context:</strong>
      <?php foreach (array_slice($selectedCase['turns'], 0, -1) as $idx => $t): ?>
        Turn <?=($idx+1)?> User: <em>“<?=esc((string)($t['query'] ?? ''))?>”</em> · Shown: <?=esc(implode(', ', $t['shown_names'] ?? []))?> |
      <?php endforeach ?>
    </p>
  <?php endif ?>
  <p class="note"><strong>Required Constraints:</strong> <code><?=esc(json_encode($selectedCase['constraints'], JSON_UNESCAPED_UNICODE))?></code> · Candidates below are shuffled in blind deterministic order (no system rank shown).</p>

  <table>
    <thead><tr><th>Candidate Restaurant (Davao City Dataset)</th><th>FoodType</th><th>Rating (Reviews)</th><th>Grounded Review Mentions</th><th>Your Relevance Grade (0–3)</th></tr></thead>
    <tbody>
    <?php foreach ($candidatePool as $cand):
      $norm = normalizePlaceName($cand['name']);
      $savedG = $currentAnnotatorGrades[$norm] ?? null;
      $sigs = array_filter($cand['_review_signals'] ?? [], fn($v) => (int)$v > 0);
    ?>
      <tr>
        <td>
          <strong><?=esc($cand['name'])?></strong>
          <input type="hidden" name="place_names[<?=esc($norm)?>]" value="<?=esc($cand['name'])?>">
        </td>
        <td><?=esc((string)($cand['food_type'] ?: 'N/A'))?></td>
        <td><?=$cand['rating'] !== null ? esc(number_format((float)$cand['rating'], 1) . ' ★ (' . (int)$cand['review_count'] . ')') : 'Unrated'?></td>
        <td class="note"><?=$sigs ? esc(json_encode($sigs)) : '—'?></td>
        <td>
          <select name="grades[<?=esc($norm)?>]">
            <option value="" <?=$savedG===null?'selected':''?>>— Select grade —</option>
            <option value="3" <?=$savedG===3?'selected':''?>>3 - Highly Relevant (All constraints)</option>
            <option value="2" <?=$savedG===2?'selected':''?>>2 - Relevant (Primary intent)</option>
            <option value="1" <?=$savedG===1?'selected':''?>>1 - Partial / Broad match</option>
            <option value="0" <?=$savedG===0?'selected':''?>>0 - Non-Relevant</option>
          </select>
        </td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
  <?php if ($blindSystemOutputs): ?>
  <h3 style="margin:22px 0 6px">Blind System Response Evaluation (1–5 Answer Quality &amp; Faithfulness)</h3>
  <p class="note" style="margin:0 0 10px">System names are hidden and randomized per query. Score overall Answer Quality from <strong>1 (Poor/Irrelevant)</strong> to <strong>5 (Excellent/Complete &amp; Concise)</strong>.</p>
  <table>
    <thead><tr><th>Blind Label</th><th>System Answer &amp; Top-5 Recommendations</th><th>Answer Quality (1–5)</th><th>Evidence Faithfulness</th></tr></thead>
    <tbody>
    <?php foreach ($blindSystemOutputs as $sIdx => $bOut):
      $letter = chr(65 + ($sIdx % 26));
      $savedBs = $currentBlindScores[$bOut['provider']] ?? null;
      $savedQual = isset($savedBs['answer_quality_1_to_5']) ? (int)$savedBs['answer_quality_1_to_5'] : null;
      $savedFaith = isset($savedBs['faithful_to_evidence']) ? ($savedBs['faithful_to_evidence'] ? 1 : 0) : 1;
    ?>
      <tr>
        <td>
          <strong>System <?=esc($letter)?></strong>
          <input type="hidden" name="blind_token[<?=$sIdx?>]" value="<?=esc($bOut['token'])?>">
        </td>
        <td>
          <div><?=esc($bOut['answer'] ?: '(No conversational text)')?></div>
          <?php if ($bOut['top5']): ?>
            <div class="note" style="margin-top:4px"><strong>Top-5 Cards:</strong> <?=esc(implode(' · ', $bOut['top5']))?></div>
          <?php endif ?>
        </td>
        <td>
          <select name="blind_quality[<?=$sIdx?>]">
            <option value="" <?=$savedQual===null?'selected':''?>>— Score 1–5 —</option>
            <?php for ($qv = 5; $qv >= 1; $qv--): ?>
              <option value="<?=$qv?>" <?=$savedQual===$qv?'selected':''?>><?=$qv?> / 5</option>
            <?php endfor ?>
          </select>
        </td>
        <td>
          <select name="blind_faithful[<?=$sIdx?>]">
            <option value="1" <?=$savedFaith===1?'selected':''?>>Supported / Grounded</option>
            <option value="0" <?=$savedFaith===0?'selected':''?>>Has Unsupported Claim</option>
          </select>
        </td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
  <?php endif ?>

  <div style="margin-top:16px">
    <button type="submit">Save Independent Annotations for <?=esc($selectedCase['id'])?></button>
  </div>
</form>
<?php endif ?>

<section class="card">
  <h2 style="margin-top:0">Inter-Annotator Agreement Summary (Live)</h2>
  <p class="note">Registered human annotator files in <code>evaluation/annotations/</code>: <strong><?=count($byRater)?></strong> (<?=esc(implode(', ', array_keys($byRater)) ?: 'none yet')?>) · Overlapping labeled items: <strong><?=$commonCount?></strong>.</p>
  <?php if ($liveKappa !== null): ?>
    <table>
      <thead><tr><th>Metric</th><th>Value</th><th>Observed Agreement (Po)</th><th>Chance Agreement (Pe)</th><th>Interpretation (Landis &amp; Koch)</th></tr></thead>
      <tbody>
        <tr>
          <td>Cohen's &kappa; (Unweighted, <?=esc($liveKappa['pair'])?>)</td>
          <td><strong><?=number_format($liveKappa['unweighted']['kappa'], 3)?></strong></td>
          <td><?=number_format($liveKappa['unweighted']['observed_agreement']*100, 1)?>%</td>
          <td><?=number_format($liveKappa['unweighted']['expected_agreement']*100, 1)?>%</td>
          <td><?=esc($liveKappa['unweighted']['interpretation'])?></td>
        </tr>
        <tr>
          <td>Cohen's &kappa; (Quadratic-Weighted Ordinal 0–3)</td>
          <td><strong><?=number_format($liveKappa['weighted']['kappa'], 3)?></strong></td>
          <td><?=number_format($liveKappa['weighted']['observed_agreement']*100, 1)?>%</td>
          <td><?=number_format($liveKappa['weighted']['expected_agreement']*100, 1)?>%</td>
          <td><?=esc($liveKappa['weighted']['interpretation'])?></td>
        </tr>
        <?php if ($liveFleiss !== null): ?>
        <tr>
          <td>Fleiss' &kappa; (<?=$liveFleiss['n_raters']?> raters across <?=$liveFleiss['n_items']?> items)</td>
          <td><strong><?=number_format($liveFleiss['kappa'], 3)?></strong></td>
          <td><?=number_format($liveFleiss['observed_agreement']*100, 1)?>%</td>
          <td><?=number_format($liveFleiss['expected_agreement']*100, 1)?>%</td>
          <td><?=esc($liveFleiss['interpretation'])?></td>
        </tr>
        <?php endif ?>
      </tbody>
    </table>
  <?php else: ?>
    <p class="note">Have at least <strong>two</strong> independent raters (e.g., <code>rater_1</code> and <code>rater_2</code>) grade at least two overlapping queries above to compute live human Cohen's &kappa; and Fleiss' &kappa;.</p>
  <?php endif ?>
  <?php if ($blindAggregates): ?>
    <details style="margin-top:14px">
      <summary style="cursor:pointer;font-weight:700;color:#185d43">Coordinator Only: Unblinded Mean Human Answer Quality (1–5) Across Systems</summary>
      <table style="margin-top:10px">
        <thead><tr><th>System</th><th>Rated Responses</th><th>Mean Answer Quality (1–5)</th><th>Human Faithfulness Pass Rate</th></tr></thead>
        <tbody>
        <?php foreach ($blindAggregates as $sysName => $agg): ?>
          <tr>
            <td><strong><?=esc($sysName)?></strong></td>
            <td><?=count($agg['scores'])?></td>
            <td><?=number_format(meanVal($agg['scores']), 2)?> / 5.00</td>
            <td><?=number_format(meanVal($agg['faithful']) * 100, 1)?>%</td>
          </tr>
        <?php endforeach ?>
        </tbody>
      </table>
    </details>
  <?php endif ?>
</section>

</main>
</body>
</html>
