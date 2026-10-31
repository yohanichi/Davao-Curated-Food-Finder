<?php
declare(strict_types=1);

function meanVal(array $values): float {
    $n = count($values);
    return $n > 0 ? array_sum($values) / $n : 0.0;
}

function medianVal(array $values): float {
    return percentileVal($values, 50.0);
}

/**
 * Linear-interpolated percentile (Type 7 / R-7 standard), p in [0, 100].
 */
function percentileVal(array $values, float $p): float {
    $n = count($values);
    if ($n === 0) return 0.0;
    sort($values, SORT_NUMERIC);
    if ($n === 1) return (float)$values[0];
    $clamped = max(0.0, min(100.0, $p)) / 100.0;
    $pos = $clamped * ($n - 1);
    $lower = (int)floor($pos);
    $upper = (int)ceil($pos);
    if ($lower === $upper) return (float)$values[$lower];
    $weight = $pos - $lower;
    return (float)$values[$lower] * (1.0 - $weight) + (float)$values[$upper] * $weight;
}

function stdDevVal(array $values): float {
    $n = count($values);
    if ($n < 2) return 0.0;
    $mean = meanVal($values);
    $sumSq = 0.0;
    foreach ($values as $v) {
        $d = (float)$v - $mean;
        $sumSq += $d * $d;
    }
    return sqrt($sumSq / ($n - 1));
}

/**
 * Deterministic xorshift32 PRNG so bootstrap CIs are 100% reproducible across platforms and runs.
 */
function nextDeterministicRand(int &$state): float {
    $x = $state & 0xFFFFFFFF;
    if ($x === 0) $x = 2463534242;
    $x ^= ($x << 13) & 0xFFFFFFFF;
    $x ^= ($x >> 17) & 0xFFFFFFFF;
    $x ^= ($x << 5) & 0xFFFFFFFF;
    $state = $x & 0xFFFFFFFF;
    return ($state / 4294967296.0);
}

/**
 * Compute mean, sample SD, standard error, and seeded 95% bootstrap confidence interval.
 */
function confidenceInterval95(array $values, int $seed = 42, int $bootstrapSamples = 2000): array {
    $clean = array_values(array_map('floatval', $values));
    $n = count($clean);
    if ($n === 0) {
        return ['mean'=>0.0, 'sd'=>0.0, 'se'=>0.0, 'ci_lower'=>0.0, 'ci_upper'=>0.0, 'n'=>0];
    }
    $mean = meanVal($clean);
    if ($n === 1) {
        return ['mean'=>$mean, 'sd'=>0.0, 'se'=>0.0, 'ci_lower'=>$mean, 'ci_upper'=>$mean, 'n'=>1];
    }
    $sd = stdDevVal($clean);
    $se = $sd / sqrt($n);
    if ($sd == 0.0) {
        return ['mean'=>$mean, 'sd'=>0.0, 'se'=>0.0, 'ci_lower'=>$mean, 'ci_upper'=>$mean, 'n'=>$n];
    }

    $state = ($seed ^ ($n * 2654435761)) & 0xFFFFFFFF;
    $bootMeans = [];
    for ($b = 0; $b < $bootstrapSamples; $b++) {
        $sum = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $idx = (int)floor(nextDeterministicRand($state) * $n);
            if ($idx >= $n) $idx = $n - 1;
            $sum += $clean[$idx];
        }
        $bootMeans[] = $sum / $n;
    }
    return [
        'mean'=>$mean,
        'sd'=>$sd,
        'se'=>$se,
        'ci_lower'=>percentileVal($bootMeans, 2.5),
        'ci_upper'=>percentileVal($bootMeans, 97.5),
        'n'=>$n,
    ];
}

function logGamma(float $z): float {
    $coefficients = [
        676.5203681218851,
        -1259.1392167224028,
        771.32342877765313,
        -176.61502916214059,
        12.507343278686905,
        -0.13857109526572012,
        9.9843695780195716e-6,
        1.5056327351493116e-7,
    ];
    $z -= 1.0;
    $sum = 0.99999999999980993;
    foreach ($coefficients as $index => $coefficient) $sum += $coefficient / ($z + $index + 1.0);
    $t = $z + count($coefficients) - 0.5;
    return 0.9189385332046727 + ($z + 0.5) * log($t) - $t + log($sum);
}

function regularizedGammaQ(float $a, float $x): float {
    if ($x <= 0) return 1.0;
    if ($x < $a + 1.0) {
        $sum = 1.0 / $a;
        $term = $sum;
        $ap = $a;
        for ($i = 1; $i <= 200; $i++) {
            $ap++;
            $term *= $x / $ap;
            $sum += $term;
            if (abs($term) < abs($sum) * 1.0e-14) break;
        }
        $p = $sum * exp(-$x + $a * log($x) - logGamma($a));
        return max(0.0, min(1.0, 1.0 - $p));
    }

    $b = $x + 1.0 - $a;
    $c = 1.0 / 1.0e-300;
    $d = 1.0 / $b;
    $h = $d;
    for ($i = 1; $i <= 200; $i++) {
        $an = -$i * ($i - $a);
        $b += 2.0;
        $d = $an * $d + $b;
        if (abs($d) < 1.0e-300) $d = 1.0e-300;
        $c = $b + $an / $c;
        if (abs($c) < 1.0e-300) $c = 1.0e-300;
        $d = 1.0 / $d;
        $delta = $d * $c;
        $h *= $delta;
        if (abs($delta - 1.0) < 1.0e-14) break;
    }
    $q = exp(-$x + $a * log($x) - logGamma($a)) * $h;
    return max(0.0, min(1.0, $q));
}

function normalTwoSidedPValue(float $absZ): float {
    if ($absZ <= 0.0) return 1.0;
    // 2 * (1 - Phi(z)) = erfc(z / sqrt(2)) = Q(0.5, z^2 / 2)
    return regularizedGammaQ(0.5, ($absZ * $absZ) / 2.0);
}

/**
 * Tie-corrected Friedman omnibus test across k >= 3 configurations and n >= 2 queries.
 */
function friedmanTest(array $scoresByConfig, int $queryCount): ?array {
    $scoresByConfig = array_values($scoresByConfig);
    $configCount = count($scoresByConfig);
    if ($configCount < 3 || $queryCount < 2) return null;

    $rankSums = array_fill(0, $configCount, 0.0);
    $tieCorrectionSum = 0.0;
    for ($caseIndex = 0; $caseIndex < $queryCount; $caseIndex++) {
        $scores = [];
        foreach ($scoresByConfig as $configScores) $scores[] = (float)($configScores[$caseIndex] ?? 0.0);
        $sorted = $scores;
        sort($sorted, SORT_NUMERIC);
        $ranks = [];
        $tieCounts = [];
        $position = 0;
        while ($position < $configCount) {
            $end = $position + 1;
            while ($end < $configCount && abs($sorted[$end] - $sorted[$position]) < 1.0e-12) $end++;
            $tieCount = $end - $position;
            $averageRank = (($position + 1) + $end) / 2.0;
            $key = sprintf('%.12f', $sorted[$position]);
            for ($i = $position; $i < $end; $i++) $ranks[$key][] = $averageRank;
            $tieCounts[] = $tieCount;
            $position = $end;
        }
        foreach ($scores as $configIndex => $score) {
            $key = sprintf('%.12f', $score);
            $rankSums[$configIndex] += array_shift($ranks[$key]);
        }
        foreach ($tieCounts as $tieCount) $tieCorrectionSum += ($tieCount ** 3) - $tieCount;
    }

    $statistic = (12.0 / ($queryCount * $configCount * ($configCount + 1))) * array_sum(array_map(fn($sum) => $sum ** 2, $rankSums))
        - 3.0 * $queryCount * ($configCount + 1);
    $correction = 1.0 - $tieCorrectionSum / ($queryCount * ($configCount ** 3 - $configCount));
    $statistic = $correction > 0 ? max(0.0, $statistic / $correction) : 0.0;
    $degreesOfFreedom = $configCount - 1;
    $meanRanks = array_map(fn($sum) => $sum / $queryCount, $rankSums);
    return [
        'statistic'=>$statistic,
        'degrees_of_freedom'=>$degreesOfFreedom,
        'p_value'=>regularizedGammaQ($degreesOfFreedom / 2.0, $statistic / 2.0),
        'mean_ranks'=>$meanRanks,
    ];
}

/**
 * Paired two-sided Wilcoxon signed-rank test.
 * Uses exact sign-permutation distribution for 1 <= n_nonzero <= 14 and tie-corrected normal approximation for n_nonzero >= 15.
 */
function wilcoxonSignedRankTest(array $scoresA, array $scoresB): array {
    $nPairs = min(count($scoresA), count($scoresB));
    $diffs = [];
    $allDiffs = [];
    for ($i = 0; $i < $nPairs; $i++) {
        $d = (float)$scoresA[$i] - (float)$scoresB[$i];
        $allDiffs[] = $d;
        if (abs($d) > 1.0e-12) {
            $diffs[] = ['abs'=>abs($d), 'sign'=>$d > 0 ? 1 : -1];
        }
    }
    $nr = count($diffs);
    if ($nr === 0) {
        return [
            'n_pairs'=>$nPairs,
            'n_nonzero'=>0,
            'w_plus'=>0.0,
            'w_minus'=>0.0,
            'w_stat'=>0.0,
            'z_score'=>0.0,
            'p_value'=>1.0,
            'effect_size_r'=>0.0,
            'mean_diff'=>0.0,
            'median_diff'=>0.0,
            'method'=>'identical',
        ];
    }

    usort($diffs, fn($a, $b) => $a['abs'] <=> $b['abs']);
    $ranks = array_fill(0, $nr, 0.0);
    $tieGroups = [];
    $pos = 0;
    while ($pos < $nr) {
        $end = $pos + 1;
        while ($end < $nr && abs($diffs[$end]['abs'] - $diffs[$pos]['abs']) < 1.0e-12) $end++;
        $tieCount = $end - $pos;
        $avgRank = (($pos + 1) + $end) / 2.0;
        for ($k = $pos; $k < $end; $k++) $ranks[$k] = $avgRank;
        if ($tieCount > 1) $tieGroups[] = $tieCount;
        $pos = $end;
    }

    $wPlus = 0.0;
    $wMinus = 0.0;
    for ($i = 0; $i < $nr; $i++) {
        if ($diffs[$i]['sign'] > 0) $wPlus += $ranks[$i];
        else $wMinus += $ranks[$i];
    }
    $wStat = min($wPlus, $wMinus);
    $meanW = $nr * ($nr + 1.0) / 4.0;
    $tieTerm = 0.0;
    foreach ($tieGroups as $t) $tieTerm += $t * ($t - 1.0) * ($t + 1.0);
    $varW = ($nr * ($nr + 1.0) * (2.0 * $nr + 1.0) - 0.5 * $tieTerm) / 24.0;
    $absDiffFromMean = abs($wPlus - $meanW);
    $z = $varW > 0.0 ? max(0.0, $absDiffFromMean - 0.5) / sqrt($varW) : 0.0;

    if ($nr <= 14) {
        // Exact two-sided signed-rank permutation p-value over all 2^nr sign assignments
        $totalPerms = 1 << $nr;
        $extremeCount = 0;
        $observedDev = abs($wPlus - $meanW) - 1.0e-12;
        for ($mask = 0; $mask < $totalPerms; $mask++) {
            $permPlus = 0.0;
            for ($bit = 0; $bit < $nr; $bit++) {
                if (($mask >> $bit) & 1) $permPlus += $ranks[$bit];
            }
            if (abs($permPlus - $meanW) >= $observedDev) {
                $extremeCount++;
            }
        }
        $pValue = min(1.0, $extremeCount / $totalPerms);
        $method = 'exact';
    } else {
        $pValue = normalTwoSidedPValue($z);
        $method = 'normal_approx';
    }

    $rawZForEffect = $varW > 0.0 ? ($wPlus - $meanW) / sqrt($varW) : 0.0;
    $effectSizeR = $nPairs > 0 ? $rawZForEffect / sqrt($nPairs) : 0.0;

    return [
        'n_pairs'=>$nPairs,
        'n_nonzero'=>$nr,
        'w_plus'=>$wPlus,
        'w_minus'=>$wMinus,
        'w_stat'=>$wStat,
        'z_score'=>$rawZForEffect,
        'p_value'=>$pValue,
        'effect_size_r'=>$effectSizeR,
        'mean_diff'=>meanVal($allDiffs),
        'median_diff'=>medianVal($allDiffs),
        'method'=>$method,
    ];
}

/**
 * Pairwise Wilcoxon signed-rank post-hoc tests with Holm-Bonferroni step-down correction.
 */
function pairwiseWilcoxonPostHoc(array $scoresByConfig, array $configIds): array {
    $scoresByConfig = array_values($scoresByConfig);
    $configIds = array_values($configIds);
    $k = min(count($scoresByConfig), count($configIds));
    if ($k < 2) return [];

    $pairs = [];
    for ($i = 0; $i < $k; $i++) {
        for ($j = $i + 1; $j < $k; $j++) {
            $res = wilcoxonSignedRankTest($scoresByConfig[$i], $scoresByConfig[$j]);
            $pairs[] = [
                'config_a'=>$configIds[$i],
                'config_b'=>$configIds[$j],
            ] + $res;
        }
    }

    $m = count($pairs);
    $order = range(0, $m - 1);
    usort($order, fn($a, $b) => $pairs[$a]['p_value'] <=> $pairs[$b]['p_value']);

    $runningMax = 0.0;
    foreach ($order as $rankIdx => $origIdx) {
        $adj = ($m - $rankIdx) * $pairs[$origIdx]['p_value'];
        $runningMax = max($runningMax, $adj);
        $pairs[$origIdx]['p_holm'] = min(1.0, $runningMax);
        $pairs[$origIdx]['significant_005'] = $pairs[$origIdx]['p_holm'] < 0.05;
    }

    return $pairs;
}

function interpretKappa(float $kappa): string {
    if ($kappa < 0.0) return 'poor';
    if ($kappa < 0.20) return 'slight';
    if ($kappa < 0.40) return 'fair';
    if ($kappa < 0.60) return 'moderate';
    if ($kappa < 0.80) return 'substantial';
    return 'almost_perfect';
}

/**
 * Cohen's Kappa for two annotators (nominal unweighted or ordinal quadratic-weighted).
 */
function cohensKappa(array $labelsA, array $labelsB, bool $quadraticWeighted = false): ?array {
    $n = min(count($labelsA), count($labelsB));
    if ($n < 2) return null;

    $categories = [];
    for ($i = 0; $i < $n; $i++) {
        $categories[(string)$labelsA[$i]] = $labelsA[$i];
        $categories[(string)$labelsB[$i]] = $labelsB[$i];
    }
    $catKeys = array_keys($categories);
    sort($catKeys, SORT_NATURAL);
    $k = count($catKeys);
    if ($k === 1) {
        return [
            'kappa'=>1.0,
            'observed_agreement'=>1.0,
            'expected_agreement'=>1.0,
            'n_items'=>$n,
            'weighted'=>$quadraticWeighted,
            'interpretation'=>'almost_perfect',
        ];
    }

    $idxMap = array_flip($catKeys);
    $matrix = array_fill(0, $k, array_fill(0, $k, 0));
    $rowSums = array_fill(0, $k, 0);
    $colSums = array_fill(0, $k, 0);

    for ($m = 0; $m < $n; $m++) {
        $r = $idxMap[(string)$labelsA[$m]];
        $c = $idxMap[(string)$labelsB[$m]];
        $matrix[$r][$c]++;
        $rowSums[$r]++;
        $colSums[$c]++;
    }

    if (!$quadraticWeighted) {
        $po = 0.0;
        $pe = 0.0;
        for ($i = 0; $i < $k; $i++) {
            $po += $matrix[$i][$i] / $n;
            $pe += ($rowSums[$i] / $n) * ($colSums[$i] / $n);
        }
        $kappa = (1.0 - $pe) > 1.0e-12 ? ($po - $pe) / (1.0 - $pe) : 1.0;
    } else {
        $maxDistSq = ($k - 1) * ($k - 1);
        $obsDisagree = 0.0;
        $expDisagree = 0.0;
        for ($i = 0; $i < $k; $i++) {
            for ($j = 0; $j < $k; $j++) {
                $w = (($i - $j) * ($i - $j)) / $maxDistSq;
                $obsDisagree += $w * ($matrix[$i][$j] / $n);
                $expDisagree += $w * (($rowSums[$i] / $n) * ($colSums[$j] / $n));
            }
        }
        $po = 1.0 - $obsDisagree;
        $pe = 1.0 - $expDisagree;
        $kappa = $expDisagree > 1.0e-12 ? 1.0 - ($obsDisagree / $expDisagree) : 1.0;
    }

    return [
        'kappa'=>$kappa,
        'observed_agreement'=>$po,
        'expected_agreement'=>$pe,
        'n_items'=>$n,
        'weighted'=>$quadraticWeighted,
        'interpretation'=>interpretKappa($kappa),
    ];
}

/**
 * Fleiss' Kappa for M >= 2 raters across N items.
 * $ratingsPerItem: array of N arrays, each containing M category labels assigned to item i.
 */
function fleissKappa(array $ratingsPerItem): ?array {
    $N = count($ratingsPerItem);
    if ($N < 2) return null;
    $first = reset($ratingsPerItem);
    if (!is_array($first) || count($first) < 2) return null;
    $M = count($first);

    $catSet = [];
    foreach ($ratingsPerItem as $itemRatings) {
        if (!is_array($itemRatings) || count($itemRatings) !== $M) return null;
        foreach ($itemRatings as $label) $catSet[(string)$label] = true;
    }
    $cats = array_keys($catSet);
    sort($cats, SORT_NATURAL);
    $K = count($cats);
    if ($K === 1) {
        return [
            'kappa'=>1.0,
            'observed_agreement'=>1.0,
            'expected_agreement'=>1.0,
            'n_items'=>$N,
            'n_raters'=>$M,
            'interpretation'=>'almost_perfect',
        ];
    }

    $catIdx = array_flip($cats);
    $colTotals = array_fill(0, $K, 0);
    $pItemSum = 0.0;

    foreach ($ratingsPerItem as $itemRatings) {
        $counts = array_fill(0, $K, 0);
        foreach ($itemRatings as $label) {
            $c = $catIdx[(string)$label];
            $counts[$c]++;
            $colTotals[$c]++;
        }
        $sumSq = 0;
        foreach ($counts as $nij) $sumSq += $nij * $nij;
        $pItemSum += ($sumSq - $M) / ($M * ($M - 1.0));
    }

    $Pbar = $pItemSum / $N;
    $Pe = 0.0;
    $totalAssignments = $N * $M;
    foreach ($colTotals as $cj) {
        $pj = $cj / $totalAssignments;
        $Pe += $pj * $pj;
    }
    $kappa = (1.0 - $Pe) > 1.0e-12 ? ($Pbar - $Pe) / (1.0 - $Pe) : 1.0;

    return [
        'kappa'=>$kappa,
        'observed_agreement'=>$Pbar,
        'expected_agreement'=>$Pe,
        'n_items'=>$N,
        'n_raters'=>$M,
        'interpretation'=>interpretKappa($kappa),
    ];
}

