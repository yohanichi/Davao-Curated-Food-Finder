<?php
declare(strict_types=1);

/**
 * Official Human Usability & System Usability Scale (SUS) Evaluation Instrument (Objective 4).
 *
 * Implements:
 *  - 6 standardized Davao Food Finder interactive user tasks (Task Completion Rate, Time-on-Task, Perceived Relevance 1-5, Trust 1-5)
 *  - Standard 10-item System Usability Scale (SUS, Brooke 1996, 0-100 score + Bangor/Sauro-Lewis adjective & letter grade)
 *  - Real human participant session persistence in evaluation/usability_responses/ (zero synthetic/fabricated responses)
 *  - 95% Bootstrap Confidence Intervals and CSV/JSON export for thesis reporting
 */

require_once __DIR__ . '/statistics.php';

const SUS_ITEMS = [
    1  => 'I think that I would like to use this Davao Food Finder system frequently.',
    2  => 'I found the system unnecessarily complex.',
    3  => 'I thought the system was easy to use.',
    4  => 'I think that I would need the support of a technical person to be able to use this system.',
    5  => 'I found the various functions in this system (chat, filters, price/source badges, multi-turn refinement) were well integrated.',
    6  => 'I thought there was too much inconsistency in this system.',
    7  => 'I would imagine that most people would learn to use this system very quickly.',
    8  => 'I found the system very cumbersome to use.',
    9  => 'I felt very confident using the system to find Davao City food places within my constraints.',
    10 => 'I needed to learn a lot of things before I could get going with this system.',
];

const USABILITY_TASKS = [
    'UT1' => [
        'title' => 'Complex Multi-Constraint Search',
        'objective' => 'Objective 3 (Complex Intent)',
        'prompt_hint' => 'Quiet and affordable coffee shops in Obrero or Bajada Davao City',
        'instructions' => 'Find a quiet, student-friendly coffee shop in Obrero or Bajada, Davao City and inspect at least one venue card.',
    ],
    'UT2' => [
        'title' => 'Hard Numeric Peso Price Ceiling',
        'objective' => 'Objective 4 (Price Ceilings)',
        'prompt_hint' => 'Fried chicken or pasta meals under ₱180 in Davao City',
        'instructions' => 'Search for meals with an explicit peso budget (e.g., under ₱180) and check the price provenance badge on the results.',
    ],
    'UT3' => [
        'title' => 'Thematic Keywords & Ambiance',
        'objective' => 'Objective 4 (Thematic Keywords)',
        'prompt_hint' => 'Cozy study cafe with wifi and sockets in Davao City',
        'instructions' => 'Find a venue matching thematic lifestyle keywords (e.g., Wi-Fi/outlets for studying, romantic date night, or durian pasalubong).',
    ],
    'UT4' => [
        'title' => 'Lexical & Bisaya Disambiguation',
        'objective' => 'Objective 2 (Disambiguation)',
        'prompt_hint' => 'Fresh green salad in Davao City (or: Affordable kan-anan sinugba in Davao City)',
        'instructions' => 'Test an ambiguous or local Davao Bisaya query and verify results match culinary meaning rather than brand-name keyword traps.',
    ],
    'UT5' => [
        'title' => 'Multi-Turn Conversational Refinement',
        'objective' => 'Objectives 2 & 3 (Coreference & Refinement)',
        'prompt_hint' => 'Which of those are under ₱200? / Show me other options not listed yet',
        'instructions' => 'Ask a follow-up question referring to your previous results without repeating the cuisine or city.',
    ],
    'UT6' => [
        'title' => 'Geographic Scope Boundary Verification',
        'objective' => 'Scope Safety',
        'prompt_hint' => 'Best seafood restaurants in Tagum City',
        'instructions' => 'Ask for a restaurant outside Davao City (e.g., Tagum, Digos, or Cebu) and verify the assistant politely declines.',
    ],
];

/**
 * Compute standard Brooke (1996) System Usability Scale (SUS) score [0..100] from 10 1-5 Likert responses.
 *
 * @param array<int|string,int> $responses 1-indexed (keys 1..10) or 0-indexed (keys 0..9) integer ratings in [1, 5]
 */
function computeSusScore(array $responses): array {
    $normalized = [];
    for ($i = 1; $i <= 10; $i++) {
        $val = $responses[$i] ?? ($responses[(string)$i] ?? ($responses[$i - 1] ?? null));
        if ($val === null || !is_numeric($val)) {
            throw new InvalidArgumentException("Missing SUS item {$i} response.");
        }
        $intVal = (int)$val;
        if ($intVal < 1 || $intVal > 5) {
            throw new InvalidArgumentException("SUS item {$i} must be an integer between 1 and 5; got {$intVal}.");
        }
        $normalized[$i] = $intVal;
    }

    $sumContribution = 0;
    foreach ($normalized as $idx => $rating) {
        if ($idx % 2 === 1) {
            // Odd items (positive tone): score - 1
            $sumContribution += ($rating - 1);
        } else {
            // Even items (negative tone): 5 - score
            $sumContribution += (5 - $rating);
        }
    }

    $susScore = round($sumContribution * 2.5, 2);

    // Bangor et al. (2009) & Sauro-Lewis (2016) curved grading scale
    $adjective = match (true) {
        $susScore >= 85.0 => 'Best Imaginable / Excellent',
        $susScore >= 80.3 => 'Excellent',
        $susScore >= 68.0 => 'Good (Above Average)',
        $susScore >= 51.0 => 'OK / Marginal',
        $susScore >= 39.0 => 'Poor',
        default           => 'Worst Imaginable',
    };

    $grade = match (true) {
        $susScore >= 84.1 => 'A+',
        $susScore >= 80.8 => 'A',
        $susScore >= 78.9 => 'A-',
        $susScore >= 77.2 => 'B+',
        $susScore >= 74.1 => 'B',
        $susScore >= 72.6 => 'B-',
        $susScore >= 71.1 => 'C+',
        $susScore >= 65.0 => 'C',
        $susScore >= 62.7 => 'C-',
        $susScore >= 51.7 => 'D',
        default           => 'F',
    };

    return [
        'items' => $normalized,
        'raw_sum_0_40' => $sumContribution,
        'sus_score' => $susScore,
        'grade' => $grade,
        'adjective' => $adjective,
        'acceptable' => $susScore >= 68.0,
    ];
}

/**
 * Load and aggregate all real human usability responses saved in evaluation/usability_responses/*.json.
 */
function aggregateUsabilityStudy(?string $responsesDir = null): array {
    $dir = $responsesDir ?? (__DIR__ . DIRECTORY_SEPARATOR . 'usability_responses');
    $files = is_dir($dir) ? (glob($dir . DIRECTORY_SEPARATOR . 'participant_*.json') ?: []) : [];
    sort($files);

    $participants = [];
    $susScores = [];
    $completionRates = [];
    $taskTimes = [];
    $relevanceRatings = [];
    $trustRatings = [];
    $perTaskStats = [];
    foreach (USABILITY_TASKS as $tid => $meta) {
        $perTaskStats[$tid] = [
            'title' => $meta['title'],
            'completed' => 0,
            'assisted' => 0,
            'failed' => 0,
            'total' => 0,
            'times_sec' => [],
            'relevance' => [],
            'trust' => [],
        ];
    }

    foreach ($files as $file) {
        $decoded = json_decode((string)file_get_contents($file), true);
        if (!is_array($decoded) || empty($decoded['participant_id']) || !isset($decoded['sus']['sus_score'])) {
            continue;
        }
        $participants[] = $decoded;
        $susScores[] = (float)$decoded['sus']['sus_score'];

        $tasks = is_array($decoded['tasks'] ?? null) ? $decoded['tasks'] : [];
        $pCompleted = 0;
        $pTotal = 0;
        foreach ($tasks as $tid => $tData) {
            if (!isset($perTaskStats[$tid]) || !is_array($tData)) continue;
            $status = (string)($tData['status'] ?? 'completed');
            $sec = max(0.0, (float)($tData['time_sec'] ?? 0.0));
            $rel = max(1, min(5, (int)($tData['relevance_1_5'] ?? 5)));
            $tru = max(1, min(5, (int)($tData['trust_1_5'] ?? 5)));

            $perTaskStats[$tid]['total']++;
            $pTotal++;
            if ($status === 'completed') {
                $perTaskStats[$tid]['completed']++;
                $pCompleted++;
            } elseif ($status === 'assisted') {
                $perTaskStats[$tid]['assisted']++;
                $pCompleted += 0.5;
            } else {
                $perTaskStats[$tid]['failed']++;
            }
            if ($sec > 0) {
                $perTaskStats[$tid]['times_sec'][] = $sec;
                $taskTimes[] = $sec;
            }
            $perTaskStats[$tid]['relevance'][] = $rel;
            $perTaskStats[$tid]['trust'][] = $tru;
            $relevanceRatings[] = (float)$rel;
            $trustRatings[] = (float)$tru;
        }
        if ($pTotal > 0) {
            $completionRates[] = $pCompleted / $pTotal;
        }
    }

    $taskSummary = [];
    foreach ($perTaskStats as $tid => $st) {
        $taskSummary[$tid] = [
            'title' => $st['title'],
            'n' => $st['total'],
            'strict_completion_rate' => $st['total'] > 0 ? round($st['completed'] / $st['total'], 4) : null,
            'mean_time_sec' => $st['times_sec'] ? round(meanVal($st['times_sec']), 2) : null,
            'median_time_sec' => $st['times_sec'] ? round(percentileVal($st['times_sec'], 50.0), 2) : null,
            'mean_relevance_1_5' => $st['relevance'] ? round(meanVal($st['relevance']), 2) : null,
            'mean_trust_1_5' => $st['trust'] ? round(meanVal($st['trust']), 2) : null,
        ];
    }

    return [
        'source' => count($participants) > 0 ? 'human_usability_study' : 'awaiting_human_participants',
        'participant_count' => count($participants),
        'sus_ci95' => $susScores ? confidenceInterval95($susScores, 42) : null,
        'task_completion_ci95' => $completionRates ? confidenceInterval95($completionRates, 43) : null,
        'mean_task_time_sec' => $taskTimes ? round(meanVal($taskTimes), 2) : null,
        'median_task_time_sec' => $taskTimes ? round(percentileVal($taskTimes, 50.0), 2) : null,
        'mean_perceived_relevance_1_5' => $relevanceRatings ? round(meanVal($relevanceRatings), 2) : null,
        'mean_price_source_trust_1_5' => $trustRatings ? round(meanVal($trustRatings), 2) : null,
        'per_task' => $taskSummary,
        'participants' => $participants,
    ];
}

// Guard web UI execution so unit tests and CLI aggregators can require_once this file cleanly.
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) !== __FILE__) {
    return;
}

function escUsa(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$responsesDir = __DIR__ . DIRECTORY_SEPARATOR . 'usability_responses';
$flashMessage = '';
$flashError = '';

if (isset($_GET['export'])) {
    $agg = aggregateUsabilityStudy($responsesDir);
    if ($_GET['export'] === 'json') {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($agg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    if ($_GET['export'] === 'csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="davao_food_finder_usability_sus.csv"');
        $out = fopen('php://output', 'wb');
        fputcsv($out, [
            'participant_id', 'submitted_at_utc', 'role', 'familiarity_davao',
            'sus_score', 'sus_grade', 'sus_adjective',
            'strict_tasks_completed', 'mean_task_time_sec', 'mean_relevance_1_5', 'mean_trust_1_5', 'comments',
        ]);
        foreach ($agg['participants'] as $p) {
            $tasks = is_array($p['tasks'] ?? null) ? $p['tasks'] : [];
            $comp = count(array_filter($tasks, fn($t) => ($t['status'] ?? '') === 'completed'));
            $times = array_values(array_filter(array_map(fn($t) => (float)($t['time_sec'] ?? 0), $tasks), fn($v) => $v > 0));
            $rels = array_map(fn($t) => (float)($t['relevance_1_5'] ?? 5), $tasks);
            $trus = array_map(fn($t) => (float)($t['trust_1_5'] ?? 5), $tasks);
            fputcsv($out, [
                $p['participant_id'],
                $p['submitted_at_utc'] ?? '',
                $p['participant_role'] ?? '',
                $p['davao_familiarity'] ?? '',
                $p['sus']['sus_score'] ?? '',
                $p['sus']['grade'] ?? '',
                $p['sus']['adjective'] ?? '',
                $comp . '/' . count($tasks),
                $times ? round(meanVal($times), 2) : '',
                $rels ? round(meanVal($rels), 2) : '',
                $trus ? round(meanVal($trus), 2) : '',
                $p['comments'] ?? '',
            ]);
        }
        fclose($out);
        exit;
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (empty($_POST['consent_confirmed'])) {
            throw new RuntimeException('Please confirm voluntary research participation consent before submitting.');
        }
        $participantCode = preg_replace('/[^A-Za-z0-9_-]/', '', trim((string)($_POST['participant_id'] ?? '')));
        if ($participantCode === '') {
            $participantCode = 'P-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        }

        $susInputs = [];
        for ($i = 1; $i <= 10; $i++) {
            if (!isset($_POST["sus_{$i}"])) {
                throw new RuntimeException("Please answer SUS statement #{$i}.");
            }
            $susInputs[$i] = (int)$_POST["sus_{$i}"];
        }
        $susResult = computeSusScore($susInputs);

        $taskRecords = [];
        foreach (USABILITY_TASKS as $tid => $meta) {
            $status = (string)($_POST["task_status_{$tid}"] ?? 'completed');
            if (!in_array($status, ['completed', 'assisted', 'failed'], true)) {
                $status = 'completed';
            }
            $timeSec = round(max(1.0, min(3600.0, (float)($_POST["task_time_{$tid}"] ?? 30.0))), 2);
            $rel = max(1, min(5, (int)($_POST["task_rel_{$tid}"] ?? 5)));
            $tru = max(1, min(5, (int)($_POST["task_trust_{$tid}"] ?? 5)));
            $taskRecords[$tid] = [
                'status' => $status,
                'time_sec' => $timeSec,
                'relevance_1_5' => $rel,
                'trust_1_5' => $tru,
            ];
        }

        if (!is_dir($responsesDir)) {
            @mkdir($responsesDir, 0775, true);
        }

        $record = [
            'participant_id' => $participantCode,
            'submitted_at_utc' => gmdate('c'),
            'participant_role' => mb_substr(trim((string)($_POST['participant_role'] ?? 'Student / Local Diner')), 0, 80),
            'davao_familiarity' => mb_substr(trim((string)($_POST['davao_familiarity'] ?? 'Resident')), 0, 60),
            'sus' => $susResult,
            'tasks' => $taskRecords,
            'comments' => mb_substr(trim((string)($_POST['comments'] ?? '')), 0, 1200),
        ];

        $fileName = sprintf('participant_%s_%s.json', gmdate('Ymd_His'), preg_replace('/[^A-Za-z0-9_-]/', '', $participantCode));
        file_put_contents(
            $responsesDir . DIRECTORY_SEPARATOR . $fileName,
            json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
            LOCK_EX
        );

        $flashMessage = sprintf(
            'Saved usability response for %s — SUS Score: %.1f / 100 (Grade %s, %s).',
            $participantCode,
            $susResult['sus_score'],
            $susResult['grade'],
            $susResult['adjective']
        );
    } catch (Throwable $e) {
        $flashError = $e->getMessage();
    }
}

$study = aggregateUsabilityStudy($responsesDir);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Davao Food Finder — Usability & SUS Evaluation (Objective 4)</title>
<style>
  body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;margin:0;background:#f5f7f4;color:#1f251f}
  .wrap{max-width:1180px;margin:auto;padding:26px}
  .card{background:#fff;border:1px solid #dfe6dc;border-radius:14px;padding:20px;margin-bottom:18px;box-shadow:0 8px 24px #1d35240d}
  .topbar{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:16px}
  .btn{display:inline-block;border:0;border-radius:9px;background:#185d43;color:#fff;padding:10px 16px;font-weight:700;cursor:pointer;text-decoration:none;font-size:13.5px}
  .btn-sec{background:#edf4ed;color:#185d43;border:1px solid #c6dacb}
  .btn-timer{padding:6px 11px;font-size:12px;border-radius:7px}
  .note{color:#5a655b;font-size:13px;line-height:1.5}
  .grid-4{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px;margin-top:12px}
  .stat-box{background:#f7faf7;border:1px solid #dce6dd;border-radius:11px;padding:13px}
  .stat-val{font-size:24px;font-weight:800;color:#185d43;margin-top:4px}
  table{border-collapse:collapse;width:100%;font-size:13px}
  th,td{border-bottom:1px solid #e5eae3;padding:9px 8px;text-align:left;vertical-align:middle}
  th{background:#edf3ec;color:#2c5a3f}
  input[type=text],input[type=number],select,textarea{padding:8px 10px;border:1px solid #cbd4c8;border-radius:8px;font:inherit;font-size:13px}
  .likert-row{display:flex;gap:12px;flex-wrap:wrap}
  .likert-row label{display:inline-flex;align-items:center;gap:4px;font-size:12.5px;cursor:pointer}
  .alert-ok{background:#e9f7ef;border:1px solid #a6dbb8;color:#145a32;padding:12px 15px;border-radius:10px;margin-bottom:16px;font-weight:600}
  .alert-err{background:#fdecea;border:1px solid #f1aeb5;color:#922b21;padding:12px 15px;border-radius:10px;margin-bottom:16px;font-weight:600}
  .badge{display:inline-block;padding:2px 8px;border-radius:99px;font-size:11px;font-weight:700;background:#edf4ed;color:#185d43}
</style>
</head>
<body>
<main class="wrap">
  <div class="topbar">
    <div>
      <h1 style="margin:0 0 4px">Human Usability & System Usability Scale (SUS) Portal</h1>
      <p class="note" style="margin:0">Objective 4 Empirical Instrument · Brooke (1996) 10-Item SUS + Standardized Task Completion & Time-on-Task</p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <a class="btn btn-sec" href="../Main.html" target="_blank" rel="noopener">Open Davao Food Finder App ↗</a>
      <a class="btn btn-sec" href="dashboard.php">Evaluation Dashboard</a>
      <a class="btn btn-sec" href="annotate.php">Blind Annotation Tool</a>
      <?php if ($study['participant_count'] > 0): ?>
        <a class="btn" href="?export=csv">Export CSV (n=<?=$study['participant_count']?>)</a>
      <?php endif ?>
    </div>
  </div>

  <?php if ($flashMessage !== ''): ?>
    <div class="alert-ok"><?=escUsa($flashMessage)?></div>
  <?php endif ?>
  <?php if ($flashError !== ''): ?>
    <div class="alert-err"><?=escUsa($flashError)?></div>
  <?php endif ?>

  <section class="card">
    <h2 style="margin-top:0">Live Study Aggregate Summary (Real Human Sessions Only)</h2>
    <?php if ($study['participant_count'] === 0): ?>
      <p class="note"><strong>Current status: n = 0 human participants recorded.</strong> No synthetic or placeholder responses are pre-populated, preserving strict academic integrity. Have study participants complete the 6 standardized tasks in <a href="../Main.html" target="_blank" rel="noopener">Main.html</a> and submit this form to populate SUS [95% CI], Task Completion Rate, and Time-on-Task statistics.</p>
    <?php else: ?>
      <div class="grid-4">
        <div class="stat-box">
          <div class="note">Participants (n)</div>
          <div class="stat-val"><?=$study['participant_count']?></div>
        </div>
        <div class="stat-box">
          <div class="note">Mean SUS Score [95% CI]</div>
          <div class="stat-val"><?=number_format($study['sus_ci95']['mean'], 1)?> <span class="note" style="font-weight:500">[<?=number_format($study['sus_ci95']['ci_lower'], 1)?>, <?=number_format($study['sus_ci95']['ci_upper'], 1)?>]</span></div>
        </div>
        <div class="stat-box">
          <div class="note">Task Completion Rate</div>
          <div class="stat-val"><?=number_format(($study['task_completion_ci95']['mean'] ?? 0) * 100, 1)?>%</div>
        </div>
        <div class="stat-box">
          <div class="note">Median Time / Relevance / Trust</div>
          <div class="stat-val" style="font-size:19px"><?=number_format((float)$study['median_task_time_sec'], 1)?>s · <?=number_format((float)$study['mean_perceived_relevance_1_5'], 2)?>/5 · <?=number_format((float)$study['mean_price_source_trust_1_5'], 2)?>/5</div>
        </div>
      </div>
    <?php endif ?>
  </section>

  <form method="post">
    <section class="card">
      <h2 style="margin-top:0">Part 1 — Participant Metadata</h2>
      <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:center">
        <label>Participant Code (anonymized):
          <input type="text" name="participant_id" placeholder="e.g., P01" maxlength="24">
        </label>
        <label>Background:
          <select name="participant_role">
            <option value="University Student">University Student</option>
            <option value="Davao Local Diner / Professional">Davao Local Diner / Professional</option>
            <option value="Tourist / Visitor">Tourist / Visitor</option>
            <option value="Faculty / Researcher">Faculty / Researcher</option>
          </select>
        </label>
        <label>Davao City Familiarity:
          <select name="davao_familiarity">
            <option value="Resident (3+ years)">Resident (3+ years)</option>
            <option value="Resident (<3 years)">Resident (&lt;3 years)</option>
            <option value="Occasional Visitor">Occasional Visitor</option>
            <option value="First-time / Non-resident">First-time / Non-resident</option>
          </select>
        </label>
      </div>
    </section>

    <section class="card">
      <h2 style="margin-top:0">Part 2 — Standardized Interactive Tasks (Open App in Split Tab & Use Built-in Timer)</h2>
      <p class="note">Click <strong>Start Timer</strong> before attempting each task in <a href="../Main.html" target="_blank" rel="noopener">Main.html</a>, then click <strong>Stop</strong> once finished.</p>
      <div style="overflow-x:auto">
        <table>
          <thead>
            <tr>
              <th>Task</th>
              <th>Instructions & Example Prompt</th>
              <th>Stopwatch & Time (s)</th>
              <th>Completion</th>
              <th>Perceived Relevance (1–5)</th>
              <th>Price/Source Trust (1–5)</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach (USABILITY_TASKS as $tid => $t): ?>
            <tr>
              <td><strong><?=escUsa($tid)?></strong><br><span class="badge"><?=escUsa($t['objective'])?></span></td>
              <td>
                <strong><?=escUsa($t['title'])?></strong><br>
                <span class="note"><?=escUsa($t['instructions'])?></span><br>
                <code style="font-size:11.5px;color:#185d43">Example: <?=escUsa($t['prompt_hint'])?></code>
              </td>
              <td style="white-space:nowrap">
                <button type="button" class="btn btn-timer" onclick="toggleTimer('<?=escUsa($tid)?>')" id="btn_<?=escUsa($tid)?>">Start Timer</button>
                <input type="number" step="0.1" min="1" max="3600" name="task_time_<?=escUsa($tid)?>" id="time_<?=escUsa($tid)?>" value="25.0" style="width:72px">s
              </td>
              <td>
                <select name="task_status_<?=escUsa($tid)?>">
                  <option value="completed">Completed</option>
                  <option value="assisted">With hint</option>
                  <option value="failed">Failed</option>
                </select>
              </td>
              <td>
                <select name="task_rel_<?=escUsa($tid)?>">
                  <?php for ($r = 5; $r >= 1; $r--): ?>
                    <option value="<?=$r?>"><?=$r?>/5</option>
                  <?php endfor ?>
                </select>
              </td>
              <td>
                <select name="task_trust_<?=escUsa($tid)?>">
                  <?php for ($r = 5; $r >= 1; $r--): ?>
                    <option value="<?=$r?>"><?=$r?>/5</option>
                  <?php endfor ?>
                </select>
              </td>
            </tr>
          <?php endforeach ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="card">
      <h2 style="margin-top:0">Part 3 — Official 10-Item System Usability Scale (SUS)</h2>
      <p class="note">Rate each statement from <strong>1 (Strongly Disagree)</strong> to <strong>5 (Strongly Agree)</strong>.</p>
      <table>
        <thead>
          <tr><th style="width:46px">#</th><th>Statement</th><th>Rating (1 = Strongly Disagree … 5 = Strongly Agree)</th></tr>
        </thead>
        <tbody>
        <?php foreach (SUS_ITEMS as $num => $statement): ?>
          <tr>
            <td><strong><?=$num?></strong></td>
            <td><?=escUsa($statement)?></td>
            <td>
              <div class="likert-row">
                <?php for ($val = 1; $val <= 5; $val++): ?>
                  <label>
                    <input type="radio" name="sus_<?=$num?>" value="<?=$val?>" required>
                    <?=$val?>
                  </label>
                <?php endfor ?>
              </div>
            </td>
          </tr>
        <?php endforeach ?>
        </tbody>
      </table>

      <div style="margin-top:16px">
        <label style="display:block;font-weight:600;margin-bottom:6px">Optional Qualitative Feedback (price ceiling clarity, disambiguation accuracy, UI responsiveness):</label>
        <textarea name="comments" rows="3" style="width:100%;box-sizing:border-box" placeholder="Share any observations from testing..."></textarea>
      </div>

      <div style="margin-top:14px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
        <label style="display:inline-flex;align-items:center;gap:8px;font-size:13.5px">
          <input type="checkbox" name="consent_confirmed" value="1" required>
          I confirm that I tested the Davao Food Finder application and consent to anonymized academic logging of these usability ratings.
        </label>
        <button type="submit" class="btn">Submit SUS & Task Evaluation</button>
      </div>
    </section>
  </form>
</main>
<script>
const activeTimers = {};
function toggleTimer(tid) {
  const btn = document.getElementById('btn_' + tid);
  const input = document.getElementById('time_' + tid);
  if (!activeTimers[tid]) {
    activeTimers[tid] = performance.now();
    btn.textContent = 'Stop';
    btn.style.background = '#b42318';
  } else {
    const elapsedSec = Math.max(1.0, (performance.now() - activeTimers[tid]) / 1000);
    input.value = elapsedSec.toFixed(1);
    delete activeTimers[tid];
    btn.textContent = 'Start Timer';
    btn.style.background = '#185d43';
  }
}
</script>
</body>
</html>

