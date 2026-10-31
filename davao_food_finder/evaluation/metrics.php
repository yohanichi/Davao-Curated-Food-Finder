<?php
declare(strict_types=1);

require_once __DIR__ . '/statistics.php';

function normalizePlaceName(string $name): string {
    $name = mb_strtolower(trim($name), 'UTF-8');
    return preg_replace('/[^\p{L}\p{N}]+/u', '', $name) ?? '';
}

function normalizeBaseRestaurantName(string $name): string {
    $base = preg_replace('/\s+-\s+[^-]+$/u', '', trim($name)) ?? trim($name);
    return normalizePlaceName($base);
}

/**
 * Validate and normalize ground_truth.json (supports both legacy format and expanded graded/multi-turn/constraint schema).
 */
function validateAndNormalizeGroundTruth(array $data): array {
    $cases = $data['cases'] ?? null;
    if (!is_array($cases) || !$cases) {
        throw new InvalidArgumentException('Ground truth has no cases. Add labeled queries to evaluation/ground_truth.json.');
    }
    $scopeCases = $data['scope_cases'] ?? [];
    if (!is_array($scopeCases)) {
        throw new InvalidArgumentException('Ground-truth scope_cases must be an array.');
    }

    $validatedCases = [];
    $seenIds = [];
    foreach ($cases as $index => $case) {
        if (!is_array($case)) {
            throw new InvalidArgumentException('Ground-truth case ' . ($index + 1) . ' must be an object.');
        }
        $id = trim((string)($case['id'] ?? ''));
        if ($id === '' || isset($seenIds[$id])) {
            throw new InvalidArgumentException('Every ground-truth case needs a unique, non-empty id.');
        }

        $category = strtolower(trim((string)($case['category'] ?? 'cuisine')));
        $split = strtolower(trim((string)($case['split'] ?? 'test')));
        if (!in_array($split, ['train', 'dev', 'test'], true)) $split = 'test';

        // Support both single-turn `query` and multi-turn `turns`
        $turns = is_array($case['turns'] ?? null) ? $case['turns'] : [];
        $query = trim((string)($case['query'] ?? ''));
        if ($turns) {
            $lastTurn = end($turns);
            if ($query === '' && is_array($lastTurn)) {
                $query = trim((string)($lastTurn['query'] ?? ''));
            }
        }
        if ($query === '' || mb_strlen($query, 'UTF-8') > 300) {
            throw new InvalidArgumentException("Ground-truth case {$id} needs a query of 1-300 characters.");
        }

        $expectsOutOfScope = (bool)($case['expect_out_of_scope'] ?? false);
        if (!$turns && !in_array($category, ['ambiguous', 'disambiguation'], true) && !$expectsOutOfScope) {
            if (preg_match('/\bdavao(\s+city)?\b/iu', $query) !== 1) {
                throw new InvalidArgumentException("Single-turn non-ambiguous in-scope query {$id} must target Davao / Davao City (or set category='ambiguous'|'disambiguation' / turns=[...]).");
            }
        }

        $relevantNamesRaw = is_array($case['relevant_names'] ?? null) ? $case['relevant_names'] : [];
        $gradesRaw = is_array($case['relevance_grades'] ?? null) ? $case['relevance_grades'] : [];

        if (!$expectsOutOfScope && !$relevantNamesRaw && !$gradesRaw) {
            throw new InvalidArgumentException("Ground-truth case {$id} needs relevant_names or relevance_grades.");
        }

        $gradeMap = [];
        $baseGradeMap = [];
        $normalizedRelevant = [];

        foreach ($relevantNamesRaw as $name) {
            if (!is_string($name) || trim($name) === '') continue;
            $norm = normalizePlaceName($name);
            $base = normalizeBaseRestaurantName($name);
            if ($norm !== '') {
                $normalizedRelevant[$norm] = true;
                $gradeMap[$norm] = max($gradeMap[$norm] ?? 0, 2);
            }
            if ($base !== '') {
                $baseGradeMap[$base] = max($baseGradeMap[$base] ?? 0, 2);
            }
        }
        foreach ($gradesRaw as $name => $grade) {
            $g = (int)$grade;
            if ($g <= 0 || !is_string($name) || trim($name) === '') continue;
            $norm = normalizePlaceName($name);
            $base = normalizeBaseRestaurantName($name);
            if ($norm !== '') {
                $normalizedRelevant[$norm] = true;
                $gradeMap[$norm] = max($gradeMap[$norm] ?? 0, min(3, $g));
            }
            if ($base !== '') {
                $baseGradeMap[$base] = max($baseGradeMap[$base] ?? 0, min(3, $g));
            }
        }

        if (!$expectsOutOfScope && !$normalizedRelevant) {
            throw new InvalidArgumentException("Ground-truth case {$id} has no usable relevant restaurant names.");
        }

        $seenIds[$id] = true;
        $validatedCases[] = [
            'id'=>$id,
            'split'=>$split,
            'category'=>$category,
            'disambiguation_type'=>strtolower(trim((string)($case['disambiguation_type'] ?? 'none'))),
            'query'=>$query,
            'turns'=>$turns,
            'expect_out_of_scope'=>$expectsOutOfScope,
            'relevant_names'=>$normalizedRelevant,
            'relevance_grades'=>$gradeMap,
            'base_relevance_grades'=>$baseGradeMap,
            'constraints'=>is_array($case['constraints'] ?? null) ? $case['constraints'] : ['city'=>'Davao City'],
            'annotations'=>is_array($case['annotations'] ?? null) ? $case['annotations'] : [],
            'notes'=>trim((string)($case['notes'] ?? '')),
        ];
    }

    $validatedScopeCases = [];
    foreach ($scopeCases as $index => $case) {
        if (!is_array($case)) {
            throw new InvalidArgumentException('Ground-truth scope case ' . ($index + 1) . ' must be an object.');
        }
        $id = trim((string)($case['id'] ?? ''));
        $query = trim((string)($case['query'] ?? ''));
        $split = strtolower(trim((string)($case['split'] ?? 'test')));
        if (!in_array($split, ['train', 'dev', 'test'], true)) $split = 'test';
        if ($id === '' || isset($seenIds[$id])) {
            throw new InvalidArgumentException('Every scope case needs a unique, non-empty id.');
        }
        if ($query === '' || mb_strlen($query, 'UTF-8') > 300) {
            throw new InvalidArgumentException("Scope case {$id} needs a query of 1-300 characters.");
        }
        if (preg_match('/\bdavao\s+city\b/i', $query) === 1 && !preg_match('/\b(outside|beyond|not\s+in)\s+davao\s+city\b/i', $query)) {
            throw new InvalidArgumentException("Scope case {$id} must request a place outside Davao City.");
        }
        $seenIds[$id] = true;
        $validatedScopeCases[] = [
            'id'=>$id,
            'split'=>$split,
            'category'=>strtolower(trim((string)($case['category'] ?? 'out_of_scope'))),
            'query'=>$query,
            'target_location'=>trim((string)($case['target_location'] ?? '')),
            'annotations'=>is_array($case['annotations'] ?? null) ? $case['annotations'] : [],
        ];
    }

    return [
        'metadata'=>[
            'version'=>(string)($data['version'] ?? '1.0'),
            'dataset_version'=>(string)($data['dataset_version'] ?? 'ph_restos_2025+ph_reviews_2025'),
            'labeling_rule'=>(string)($data['labeling_rule'] ?? ''),
            'annotator_count'=>(int)($data['annotator_count'] ?? 0),
        ],
        'disambiguation_schema'=>is_array($data['disambiguation_schema'] ?? null) ? $data['disambiguation_schema'] : [],
        'cases'=>$validatedCases,
        'scope_cases'=>$validatedScopeCases,
        'annotator_agreement_samples'=>is_array($data['annotator_agreement_samples'] ?? null) ? $data['annotator_agreement_samples'] : [],
    ];
}

function lookupItemGrade(array $place, array $gradeMap, array $baseGradeMap = [], array $constraints = [], bool $allowBaseMatch = false): int {
    $name = trim((string)($place['name'] ?? ''));
    if ($name === '') return 0;
    $norm = normalizePlaceName($name);
    if ($norm !== '' && isset($gradeMap[$norm])) {
        return (int)$gradeMap[$norm];
    }
    if ($allowBaseMatch && $baseGradeMap) {
        $base = normalizeBaseRestaurantName($name);
        if ($base !== '' && isset($baseGradeMap[$base])) {
            $areas = is_array($constraints['location_areas'] ?? null) ? $constraints['location_areas'] : [];
            if ($areas) {
                $locText = mb_strtolower($name . ' ' . ($place['address'] ?? '') . ' ' . ($place['description'] ?? ''), 'UTF-8');
                foreach ($areas as $a) {
                    if (mb_strpos($locText, mb_strtolower((string)$a, 'UTF-8')) !== false) {
                        return (int)$baseGradeMap[$base];
                    }
                }
                return 1; // Partial credit: matching brand in Davao City but unverified neighborhood branch
            }
            return (int)$baseGradeMap[$base];
        }
    }
    return 0;
}

/**
 * Merge webResults and local results into a deduplicated unified recommendation list (preserving presentation order: web then local, or local prioritized when web is supplementary).
 * Note: local dataset records carry full CSV ground-truth metadata, while webResults carry live web recommendations.
 * We provide `mergeRecommendations` in two modes: local_first (default for combined retrieval + web expansion) and web_first.
 */
function mergeRecommendations(array $localResults, array $webResults, bool $localFirst = true): array {
    $primary = $localFirst ? $localResults : $webResults;
    $secondary = $localFirst ? $webResults : $localResults;
    $merged = [];
    $seen = [];

    foreach ($primary as $item) {
        if (!is_array($item)) continue;
        $key = normalizePlaceName((string)($item['name'] ?? ''));
        if ($key === '' || isset($seen[$key])) continue;
        $seen[$key] = true;
        $merged[] = $item + ['_origin'=>$localFirst ? 'local' : 'web'];
    }
    foreach ($secondary as $item) {
        if (!is_array($item)) continue;
        $key = normalizePlaceName((string)($item['name'] ?? ''));
        if ($key === '' || isset($seen[$key])) continue;
        $seen[$key] = true;
        $merged[] = $item + ['_origin'=>$localFirst ? 'web' : 'local'];
    }
    return $merged;
}

/**
 * Compute Precision@5, Recall@5, Recall@10, NDCG@5, and MRR.
 */
function computeRankingMetrics(array $results, array $gradeMap, array $baseGradeMap = [], array $constraints = [], bool $allowBaseMatch = false): array {
    $totalRelevant = count(array_filter($gradeMap, fn($g) => $g > 0));
    if ($totalRelevant === 0) {
        return [
            'precision_at_5'=>0.0,
            'recall_at_5'=>0.0,
            'recall_at_10'=>0.0,
            'ndcg_at_5'=>0.0,
            'mrr'=>0.0,
            'hits_at_5'=>0,
            'hits_at_10'=>0,
            'returned_count'=>count($results),
        ];
    }

    $seen = [];
    $hits5 = 0;
    $hits10 = 0;
    $firstRelevantRank = null;
    $dcg5 = 0.0;

    $maxEval = min(count($results), 20);
    for ($idx = 0; $idx < $maxEval; $idx++) {
        $place = is_array($results[$idx]) ? $results[$idx] : [];
        $norm = normalizePlaceName((string)($place['name'] ?? ''));
        $isOnline = (($place['_origin'] ?? '') === 'web') || !empty($place['source_url']) || !empty($place['online']);
        $grade = 0;
        if ($norm !== '' && !isset($seen[$norm])) {
            $grade = lookupItemGrade($place, $gradeMap, $baseGradeMap, $constraints, $allowBaseMatch || $isOnline);
            if ($grade > 0) {
                $seen[$norm] = true;
            }
        }
        $rank = $idx + 1;
        if ($grade > 0) {
            if ($firstRelevantRank === null) $firstRelevantRank = $rank;
            if ($rank <= 5) $hits5++;
            if ($rank <= 10) $hits10++;
        }
        if ($rank <= 5 && $grade > 0) {
            $dcg5 += ((2 ** $grade) - 1.0) / log($rank + 1.0, 2.0);
        }
    }

    // Ideal DCG@5 from sorted ground-truth relevance grades
    $idealGrades = array_values($gradeMap);
    rsort($idealGrades, SORT_NUMERIC);
    $idcg5 = 0.0;
    foreach (array_slice($idealGrades, 0, 5) as $idx => $g) {
        if ($g > 0) {
            $idcg5 += ((2 ** $g) - 1.0) / log($idx + 2.0, 2.0);
        }
    }

    return [
        'precision_at_5'=>$hits5 / 5.0,
        'recall_at_5'=>$hits5 / $totalRelevant,
        'recall_at_10'=>$hits10 / $totalRelevant,
        'ndcg_at_5'=>$idcg5 > 0.0 ? min(1.0, $dcg5 / $idcg5) : 0.0,
        'mrr'=>$firstRelevantRank !== null ? (1.0 / $firstRelevantRank) : 0.0,
        'hits_at_5'=>$hits5,
        'hits_at_10'=>$hits10,
        'returned_count'=>count($results),
    ];
}

/**
 * Evaluate multi-dimensional constraint adherence across top-5 recommendations.
 */
function evaluateConstraintSatisfaction(array $places, array $constraints, array $datasetIndex = []): array {
    $top5 = array_slice($places, 0, 5);
    if (!$top5) {
        return [
            'davao_only_pass'=>true,
            'strict_pass'=>false,
            'item_pass_rate'=>0.0,
            'dimension_scores'=>[],
            'failed_dimensions'=>['empty_results'],
        ];
    }

    $davaoPassAll = true;
    $dimTotals = [];
    $dimPasses = [];
    $itemFullPasses = 0;

    $recordDim = function (string $dim, bool $ok) use (&$dimTotals, &$dimPasses): bool {
        $dimTotals[$dim] = ($dimTotals[$dim] ?? 0) + 1;
        if ($ok) $dimPasses[$dim] = ($dimPasses[$dim] ?? 0) + 1;
        return $ok;
    };

    $excludeMap = [];
    foreach (($constraints['exclude_names'] ?? []) as $exName) {
        $n = normalizePlaceName((string)$exName);
        if ($n !== '') $excludeMap[$n] = true;
    }
    $forbiddenMap = [];
    foreach (($constraints['forbidden_names'] ?? []) as $fbName) {
        $n = normalizePlaceName((string)$fbName);
        if ($n !== '') $forbiddenMap[$n] = true;
    }
    $verifiedPriceHits = 0;

    foreach ($top5 as $place) {
        if (!is_array($place)) continue;
        $name = (string)($place['name'] ?? '');
        $norm = normalizePlaceName($name);
        $dsRecord = $norm !== '' && isset($datasetIndex[$norm]) ? $datasetIndex[$norm] : null;
        $signals = is_array($dsRecord['_review_signals'] ?? null) ? $dsRecord['_review_signals'] : [];
        $city = (string)($place['city'] ?? ($dsRecord['city'] ?? ''));
        $foodType = mb_strtolower((string)($place['food_type'] ?? ($dsRecord['food_type'] ?? '')), 'UTF-8');
        $combinedText = mb_strtolower(implode(' ', [
            $name,
            $foodType,
            (string)($place['description'] ?? ''),
            (string)($place['address'] ?? ''),
            (string)($place['price_range'] ?? ''),
            (string)($dsRecord['_branch_area'] ?? ''),
            implode(' ', $dsRecord['_review_snippets'] ?? []),
        ]), 'UTF-8');

        $itemAllOk = true;

        // 1. Davao City constraint
        $inDavao = preg_match('/\bdavao\s+city\b/iu', $city) === 1;
        if ($inDavao && function_exists('detectOutOfScopeLocation')) {
            $locCheck = detectOutOfScopeLocation($name . ' ' . (string)($place['address'] ?? ''));
            if ($locCheck['out_of_scope']) $inDavao = false;
        }
        if (!$inDavao) $davaoPassAll = false;
        if (!$recordDim('city_davao', $inDavao)) $itemAllOk = false;

        // 2. Cuisine / category constraint
        if (!empty($constraints['food_types']) || !empty($constraints['keywords_any'])) {
            $cuisineOk = false;
            foreach (($constraints['food_types'] ?? []) as $ft) {
                if ($foodType === mb_strtolower((string)$ft, 'UTF-8') || mb_strpos($foodType, mb_strtolower((string)$ft, 'UTF-8')) !== false) {
                    $cuisineOk = true;
                    break;
                }
            }
            if (!$cuisineOk) {
                foreach (($constraints['keywords_any'] ?? []) as $kw) {
                    if (mb_strpos($combinedText, mb_strtolower((string)$kw, 'UTF-8')) !== false) {
                        $cuisineOk = true;
                        break;
                    }
                }
            }
            if (!$recordDim('cuisine', $cuisineOk)) $itemAllOk = false;
        }

        // 3. Davao neighborhood / mall location constraint
        if (!empty($constraints['location_areas'])) {
            $locOk = false;
            foreach ($constraints['location_areas'] as $area) {
                if (mb_strpos($combinedText, mb_strtolower((string)$area, 'UTF-8')) !== false) {
                    $locOk = true;
                    break;
                }
            }
            if (!$recordDim('location_area', $locOk)) $itemAllOk = false;
        }

        // 4. Minimum rating constraint
        if (isset($constraints['min_rating']) && is_numeric($constraints['min_rating'])) {
            $minR = (float)$constraints['min_rating'];
            $rVal = is_numeric($place['rating'] ?? null)
                ? (float)$place['rating']
                : (is_numeric($dsRecord['rating'] ?? null) ? (float)$dsRecord['rating'] : null);
            $ratingOk = $rVal !== null && $rVal >= ($minR - 1.0e-6);
            if (!$recordDim('min_rating', $ratingOk)) $itemAllOk = false;
        }

        // 5. Budget / affordability & hard numeric price-ceiling constraint
        if (isset($constraints['max_price_php']) && is_numeric($constraints['max_price_php'])) {
            $maxP = (float)$constraints['max_price_php'];
            $obsMedian = null;
            if (isset($place['price_evidence']['observed_median_php']) && is_numeric($place['price_evidence']['observed_median_php'])) {
                $obsMedian = (float)$place['price_evidence']['observed_median_php'];
            } elseif (isset($dsRecord['_observed_prices_php']['median']) && is_numeric($dsRecord['_observed_prices_php']['median'])) {
                $obsMedian = (float)$dsRecord['_observed_prices_php']['median'];
            } elseif (!empty($place['price_range']) && function_exists('extractPesoAmountsFromText')) {
                $parsedP = extractPesoAmountsFromText((string)$place['price_range']);
                if ($parsedP) {
                    sort($parsedP);
                    $obsMedian = (float)$parsedP[intdiv(count($parsedP), 2)];
                }
            }
            if ($obsMedian !== null) {
                $priceOk = $obsMedian <= ($maxP + 1.0e-6);
                if ($priceOk) $verifiedPriceHits++;
            } else {
                $expCount = (int)($signals['expensive'] ?? 0);
                $affCount = (int)($signals['affordable'] ?? 0);
                $priceOk = !($maxP <= 250 && $expCount >= 2 && $affCount === 0)
                    && ($affCount > 0 || in_array($foodType, ['silog', 'pares', 'ulam', 'siomai', 'porridge', 'snacks', 'pancit', 'fast food', 'rice bowl', 'rice dishes', 'shawarma', 'fried chicken', 'pasta', 'coffee'], true));
            }
            if (!$recordDim('max_price_php', $priceOk)) $itemAllOk = false;
        } elseif (($constraints['budget_tier'] ?? null) === 'affordable') {
            $budgetOk = ((int)($signals['affordable'] ?? 0) > 0)
                || in_array($foodType, ['silog', 'pares', 'ulam', 'siomai', 'porridge', 'snacks', 'pancit', 'fast food', 'rice bowl', 'rice dishes', 'takoyaki', 'shawarma', 'halo-halo', 'fried chicken'], true)
                || preg_match('/\b(affordable|budget|cheap|sulit|mura|eatery|haus|food\s+house|kitchenette|lutong\s+bahay|silogan|paresan|₱)\b/iu', $combinedText) === 1;
            if (!$recordDim('budget', $budgetOk)) $itemAllOk = false;
        }

        // 6. Dietary restriction constraint
        if (!empty($constraints['dietary'])) {
            $dietType = (string)$constraints['dietary'];
            $dietOk = false;
            if ($dietType === 'halal') {
                $hasPork = in_array($foodType, ['lechon', 'liempo', 'sisig'], true)
                    || preg_match('/\b(pork|baboy|bacon|lechon|liempo|chicharon)\b/iu', $name) === 1;
                $dietOk = !$hasPork && (
                    ((int)($signals['halal'] ?? 0) > 0)
                    || in_array($foodType, ['shawarma', 'middle eastern', 'biryani', 'indian', 'seafood', 'fish', 'wraps'], true)
                    || preg_match('/\b(halal|muslim|arabian|turkish|kabab|kebab|biryani|shawarma)\b/iu', $combinedText) === 1
                );
            } elseif ($dietType === 'healthy_veg') {
                $dietOk = ((int)($signals['healthy_veg'] ?? 0) > 0)
                    || in_array($foodType, ['healthy', 'salads', 'seafood', 'fish', 'fruit shake'], true)
                    || preg_match('/\b(healthy|salad|vegan|vegetarian|veggie|green\s+bounty|greeneats|fresh|fit|soul\s+kitchen|echostore)\b/iu', $combinedText) === 1;
            }
            if (!$recordDim('dietary', $dietOk)) $itemAllOk = false;
        }

        // 7. Ambiance / group suitability constraint
        if (!empty($constraints['ambiance'])) {
            $ambType = (string)$constraints['ambiance'];
            $ambOk = false;
            if ($ambType === 'quiet_study') {
                $ambOk = ((int)($signals['quiet_study'] ?? 0) > 0)
                    || $foodType === 'coffee'
                    || preg_match('/\b(cafe|café|coffee|libro|study|lounge|garden|hygge|woods|roastery|tea|cozy|quiet)\b/iu', $combinedText) === 1;
            } elseif ($ambType === 'family_group') {
                $ambOk = ((int)($signals['family_group'] ?? 0) > 0)
                    || preg_match('/\b(buffet|family|kamayan|bilao|house|haus|platter|kitchen|grill|savory|shakey|gerry|inasal|pizza)\b/iu', $combinedText) === 1;
            }
            if (!$recordDim('ambiance', $ambOk)) $itemAllOk = false;
        }

        // 8. Hours / mealtime constraint
        if (!empty($constraints['hours'])) {
            $hrOk = ((int)($signals['late_24'] ?? 0) > 0)
                || in_array($foodType, ['silog', 'pares', 'porridge', 'fast food', 'rice bowl', 'rice dishes'], true)
                || preg_match('/\b(24\/7|24\s*hours|late|midnight|silog|silogan|pares|paresan|painitan|lugaw|gotokai|hab-hub|breakfast)\b/iu', $combinedText) === 1;
            if (!$recordDim('hours', $hrOk)) $itemAllOk = false;
        }

        // 9. Follow-up exclusion constraint
        if ($excludeMap) {
            $notExcluded = !isset($excludeMap[$norm]);
            if (!$recordDim('followup_exclusion', $notExcluded)) $itemAllOk = false;
        }

        // 10. Disambiguation / polysemy trap avoidance constraint
        if ($forbiddenMap) {
            $notForbidden = !isset($forbiddenMap[$norm]);
            if (!$recordDim('disambiguation_trap_avoidance', $notForbidden)) $itemAllOk = false;
        }

        if ($itemAllOk) $itemFullPasses++;
    }

    $dimScores = [];
    $failedDims = [];
    foreach ($dimTotals as $dim => $tot) {
        $rate = $tot > 0 ? ($dimPasses[$dim] ?? 0) / $tot : 1.0;
        $dimScores[$dim] = $rate;
        if ($rate < 0.6) $failedDims[] = $dim;
    }

    $itemPassRate = count($top5) > 0 ? $itemFullPasses / count($top5) : 0.0;
    $noExclusionViolation = !isset($dimScores['followup_exclusion']) || $dimScores['followup_exclusion'] >= 1.0;
    $noForbiddenTrap = !isset($dimScores['disambiguation_trap_avoidance']) || $dimScores['disambiguation_trap_avoidance'] >= 1.0;
    $hasPriceCeiling = isset($constraints['max_price_php']) && is_numeric($constraints['max_price_php']);

    return [
        'davao_only_pass'=>$davaoPassAll,
        'strict_pass'=>$davaoPassAll && $noExclusionViolation && $noForbiddenTrap && $itemPassRate >= 0.6,
        'item_pass_rate'=>$itemPassRate,
        'numeric_price_compliant'=>$hasPriceCeiling ? (($dimScores['max_price_php'] ?? 0.0) >= 0.8 && $verifiedPriceHits >= 1) : null,
        'verified_price_hit_rate'=>$hasPriceCeiling && count($top5) > 0 ? ($verifiedPriceHits / count($top5)) : null,
        'disambiguation_trap_free'=>$noForbiddenTrap,
        'dimension_scores'=>$dimScores,
        'failed_dimensions'=>$failedDims,
    ];
}

/**
 * Validate recommendations beyond name matching:
 * Detects duplicate results, wrong locations, missing sources, fabricated details, and unsupported claims.
 */
function validateRecommendationQuality(array $responseData, array $case, array $datasetIndex = []): array {
    $localResults = is_array($responseData['results'] ?? null) ? $responseData['results'] : [];
    $webResults = is_array($responseData['webResults'] ?? null) ? $responseData['webResults'] : [];
    $sources = is_array($responseData['sources'] ?? null) ? $responseData['sources'] : [];
    $answer = trim((string)($responseData['answer'] ?? ''));
    $metrics = is_array($responseData['metrics'] ?? null) ? $responseData['metrics'] : [];

    $sourceUrls = [];
    foreach ($sources as $s) {
        if (is_array($s) && !empty($s['url'])) {
            $sourceUrls[(string)$s['url']] = true;
        }
    }

    $duplicateCount = 0;
    $wrongLocationCount = 0;
    $missingSourceCount = 0;
    $fabricatedDetailCount = 0;
    $unsupportedClaimCount = 0;
    $issues = [];

    $seenLocal = [];
    $localValidated = [];
    $factChecksTotal = 0;
    $factChecksPassed = 0;
    $evidenceChecksTotal = 0;
    $evidenceChecksPassed = 0;

    // 1. Validate local recommendations against ground-truth CSV dataset
    foreach ($localResults as $item) {
        if (!is_array($item)) continue;
        $name = trim((string)($item['name'] ?? ''));
        $norm = normalizePlaceName($name);
        if ($norm === '') continue;

        if (isset($seenLocal[$norm])) {
            $duplicateCount++;
            $issues[] = ['type'=>'duplicate_local', 'name'=>$name];
        }
        $seenLocal[$norm] = true;

        $city = (string)($item['city'] ?? '');
        $inDavao = preg_match('/\bdavao\s+city\b/iu', $city) === 1;
        if (!$inDavao) {
            $wrongLocationCount++;
            $issues[] = ['type'=>'wrong_location_local', 'name'=>$name, 'city'=>$city];
        }

        $factChecksTotal++;
        $ds = $datasetIndex[$norm] ?? null;
        $factOk = true;
        if ($ds === null) {
            $factOk = false;
            $fabricatedDetailCount++;
            $issues[] = ['type'=>'fabricated_local_record', 'name'=>$name];
        } else {
            // Verify rating and review_count were not altered/hallucinated
            $retRating = is_numeric($item['rating'] ?? null) ? round((float)$item['rating'], 1) : null;
            $dsRating = is_numeric($ds['rating'] ?? null) ? round((float)$ds['rating'], 1) : null;
            if ($retRating !== $dsRating) {
                $factOk = false;
                $fabricatedDetailCount++;
                $issues[] = ['type'=>'altered_local_rating', 'name'=>$name, 'returned'=>$retRating, 'expected'=>$dsRating];
            }
            if ((string)($item['price_range'] ?? '') !== '' && (string)($ds['price_range'] ?? '') === '') {
                $factOk = false;
                $fabricatedDetailCount++;
                $issues[] = ['type'=>'fabricated_local_price', 'name'=>$name];
            }
        }
        if ($factOk) $factChecksPassed++;

        $evidenceChecksTotal++;
        if ($ds !== null) $evidenceChecksPassed++;

        $grade = lookupItemGrade($item, $case['relevance_grades'] ?? [], $case['base_relevance_grades'] ?? [], $case['constraints'] ?? [], false);
        $localValidated[] = [
            'name'=>$name,
            'origin'=>'local',
            'relevance_grade'=>$grade,
            'factually_correct'=>$factOk,
            'evidence_supported'=>$ds !== null,
            'davao_verified'=>$inDavao,
        ];
    }

    // 2. Validate web recommendations against Playwright sources & grounding rules
    $seenWeb = [];
    $webValidated = [];
    foreach ($webResults as $item) {
        if (!is_array($item)) continue;
        $name = trim((string)($item['name'] ?? ''));
        $norm = normalizePlaceName($name);
        if ($norm === '') continue;

        if (isset($seenWeb[$norm])) {
            $duplicateCount++;
            $issues[] = ['type'=>'duplicate_web', 'name'=>$name];
        }
        if (isset($seenLocal[$norm])) {
            $duplicateCount++;
            $issues[] = ['type'=>'duplicate_cross_web_local', 'name'=>$name];
        }
        $seenWeb[$norm] = true;

        $city = (string)($item['city'] ?? '');
        $addr = (string)($item['address'] ?? '');
        $inDavao = preg_match('/\bdavao\s+city\b/iu', $city) === 1;
        if ($inDavao && function_exists('detectOutOfScopeLocation')) {
            $outCheck = detectOutOfScopeLocation($name . ' ' . $addr);
            if ($outCheck['out_of_scope']) $inDavao = false;
        }
        if (!$inDavao) {
            $wrongLocationCount++;
            $issues[] = ['type'=>'wrong_location_web', 'name'=>$name, 'city'=>$city];
        }

        $srcUrl = trim((string)($item['source_url'] ?? ''));
        $validUrl = $srcUrl !== '' && filter_var($srcUrl, FILTER_VALIDATE_URL) !== false;
        $backedByCollectedSource = $validUrl && (!$sourceUrls || isset($sourceUrls[$srcUrl]));

        $evidenceChecksTotal++;
        if (!$validUrl) {
            $missingSourceCount++;
            $unsupportedClaimCount++;
            $issues[] = ['type'=>'missing_web_source', 'name'=>$name];
        } elseif (!$backedByCollectedSource) {
            $fabricatedDetailCount++;
            $unsupportedClaimCount++;
            $issues[] = ['type'=>'unverified_web_source_url', 'name'=>$name, 'url'=>$srcUrl];
        } else {
            $evidenceChecksPassed++;
        }

        $factChecksTotal++;
        $ratingStr = trim((string)($item['rating'] ?? ''));
        $webFactOk = $inDavao && $backedByCollectedSource;
        if ($ratingStr !== '' && preg_match('/^\d+(\.\d+)?$/', $ratingStr)) {
            $rv = (float)$ratingStr;
            if ($rv <= 0.0 || $rv > 5.0) {
                $webFactOk = false;
                $fabricatedDetailCount++;
                $issues[] = ['type'=>'impossible_web_rating', 'name'=>$name, 'rating'=>$ratingStr];
            }
        }
        if ($webFactOk) $factChecksPassed++;

        $grade = lookupItemGrade($item, $case['relevance_grades'] ?? [], $case['base_relevance_grades'] ?? [], $case['constraints'] ?? [], true);
        $webValidated[] = [
            'name'=>$name,
            'origin'=>'web',
            'relevance_grade'=>$grade,
            'factually_correct'=>$webFactOk,
            'evidence_supported'=>$backedByCollectedSource,
            'davao_verified'=>$inDavao,
            'source_url'=>$srcUrl,
        ];
    }

    // 3. Check answer text for unsupported outside-city recommendations on in-scope responses
    if (!($responseData['outOfScope'] ?? false) && $answer !== '' && function_exists('detectOutOfScopeLocation')) {
        if (preg_match('/\b(recommend|try|visit)\b[^.!?]{0,60}\b(in\s+(cebu|manila|tagum|panabo|digos|makati|quezon\s+city|baguio))\b/iu', $answer, $m)) {
            $unsupportedClaimCount++;
            $wrongLocationCount++;
            $issues[] = ['type'=>'answer_mentions_outside_city', 'excerpt'=>$m[0]];
        }
    }

    $cacheAge = $metrics['cache']['playwright_age_seconds'] ?? null;
    $pwCacheState = (string)($metrics['cache']['playwright'] ?? 'unknown');
    $sourceFresh = ($pwCacheState === 'miss' || ($cacheAge !== null && (int)$cacheAge <= 43200));

    $totalWeb = count($webValidated);
    $supportedWeb = count(array_filter($webValidated, fn($w) => $w['evidence_supported']));

    return [
        'factual_correctness_rate'=>$factChecksTotal > 0 ? ($factChecksPassed / $factChecksTotal) : 1.0,
        'evidence_support_rate'=>$evidenceChecksTotal > 0 ? ($evidenceChecksPassed / $evidenceChecksTotal) : 1.0,
        'web_source_support_rate'=>$totalWeb > 0 ? ($supportedWeb / $totalWeb) : null,
        'source_fresh'=>$sourceFresh,
        'duplicate_count'=>$duplicateCount,
        'wrong_location_count'=>$wrongLocationCount,
        'missing_source_count'=>$missingSourceCount,
        'fabricated_detail_count'=>$fabricatedDetailCount,
        'unsupported_claim_count'=>$unsupportedClaimCount,
        'local_items'=>$localValidated,
        'web_items'=>$webValidated,
        'issues'=>$issues,
    ];
}

/**
 * Average pairwise Jaccard similarity across repeated runs of top-k normalized restaurant names.
 */
function computeRepeatRunConsistency(array $trialTopNameSets): ?float {
    $t = count($trialTopNameSets);
    if ($t < 2) return null;
    $sum = 0.0;
    $pairs = 0;
    for ($i = 0; $i < $t; $i++) {
        for ($j = $i + 1; $j < $t; $j++) {
            $setA = array_fill_keys($trialTopNameSets[$i], true);
            $setB = array_fill_keys($trialTopNameSets[$j], true);
            if (!$setA && !$setB) {
                $sum += 1.0;
            } else {
                $inter = count(array_intersect_key($setA, $setB));
                $union = count($setA + $setB);
                $sum += $union > 0 ? ($inter / $union) : 1.0;
            }
            $pairs++;
        }
    }
    return $pairs > 0 ? ($sum / $pairs) : 1.0;
}

