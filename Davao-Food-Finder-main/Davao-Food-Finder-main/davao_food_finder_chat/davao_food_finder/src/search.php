<?php
use PhpOffice\PhpSpreadsheet\IOFactory;

function normalizeText($value): string {
    if (is_array($value) || is_object($value)) return '';
    return trim(preg_replace('/\s+/u', ' ', (string)$value));
}
function normKey(string $s): string {
    return strtolower(preg_replace('/[^a-z0-9]+/i', '', $s));
}
function findValue(array $row, array $needles): string {
    foreach ($row as $key => $value) {
        $k = normKey((string)$key);
        foreach ($needles as $needle) {
            if (strpos($k, normKey($needle)) !== false && normalizeText($value) !== '') return normalizeText($value);
        }
    }
    return '';
}
/** Stream CSV rows so the large reviews file does not have to be loaded into memory at once. */
function csvRows(string $file): Generator {
    $handle = fopen($file, 'rb');
    if (!$handle) return;
    $first = fgets($handle);
    if ($first === false) { fclose($handle); return; }
    // Detect common delimiters from the header line.
    $delimiter = ',';
    foreach ([',', ';', "\t", '|'] as $candidate) {
        if (substr_count($first, $candidate) > substr_count($first, $delimiter)) $delimiter = $candidate;
    }
    rewind($handle);
    $headers = fgetcsv($handle, 0, $delimiter, '"', '\\');
    if (!$headers) { fclose($handle); return; }
    $headers = array_map(fn($v) => normalizeText($v), $headers);
    while (($values = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
        if (count($values) === 1 && ($values[0] === null || trim((string)$values[0]) === '')) continue;
        $row = [];
        foreach ($headers as $i => $header) if ($header !== '') $row[$header] = normalizeText($values[$i] ?? '');
        yield $row;
    }
    fclose($handle);
}
function worksheetRows(string $file): Generator {
    $book = IOFactory::load($file);
    foreach ($book->getWorksheetIterator() as $sheet) {
        $rows = $sheet->toArray(null, true, true, false);
        if (count($rows) < 2) continue;
        $headers = array_map(fn($v) => normalizeText($v), array_shift($rows));
        foreach ($rows as $values) {
            $row = [];
            foreach ($headers as $i => $header) if ($header !== '') $row[$header] = normalizeText($values[$i] ?? '');
            if ($row) yield $row;
        }
    }
    $book->disconnectWorksheets();
}
function isDavaoCity(array $row): bool {
    $city = findValue($row, ['city','municipality','locality','citymunicipality']);
    // This dataset uses "davao city davao del sur". Match Davao City, not other Davao Region cities.
    return preg_match('/\bdavao\s+city\b/i', $city) === 1;
}
function loadDavaoPlaces(string $dir): array {
    $files = glob($dir . '/*.{xlsx,xls,csv}', GLOB_BRACE) ?: [];
    $reviewStats = [];
    $restaurantRows = [];

    // First pass: aggregate reviews by StoreId, keeping memory use low.
    foreach ($files as $file) {
        if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'csv') continue;
        try {
            $first = true;
            foreach (csvRows($file) as $row) {
                if ($first) {
                    $keys = array_map('normKey', array_keys($row));
                    $first = false;
                    // The review export has StoreId + overall + restaurant_food, but no restaurant name/city.
                    $isReviewFile = in_array('storeid', $keys, true)
                        && (in_array('restaurantfood', $keys, true) || in_array('overall', $keys, true))
                        && !in_array('completestorename', $keys, true);
                    if (!$isReviewFile) break;
                }
                $storeId = findValue($row, ['storeid']);
                if ($storeId === '') continue;
                $score = findValue($row, ['restaurant_food']);
                if ($score === '') $score = findValue($row, ['overall']);
                if (!isset($reviewStats[$storeId])) $reviewStats[$storeId] = ['count'=>0,'sum'=>0.0,'scored'=>0];
                $reviewStats[$storeId]['count']++;
                if (is_numeric($score) && (float)$score > 0 && (float)$score <= 5) {
                    $reviewStats[$storeId]['sum'] += (float)$score;
                    $reviewStats[$storeId]['scored']++;
                }
            }
        } catch (Throwable $e) {
            error_log('Could not aggregate reviews in ' . basename($file) . ': ' . $e->getMessage());
        }
    }

    // Second pass: load restaurant records from CSV/Excel files.
    foreach ($files as $file) {
        try {
            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $rows = $extension === 'csv' ? csvRows($file) : worksheetRows($file);
            foreach ($rows as $assoc) {
                $name = findValue($assoc, ['completestorename','restaurantname','businessname','placename','establishmentname','name']);
                if ($name === '') continue;
                // Only rows with a City column explicitly identifying Davao City are included.
                if (!isDavaoCity($assoc)) continue;
                $storeId = findValue($assoc, ['storeid']);
                $address = findValue($assoc, ['fulladdress','completeaddress','address','location','street']);
                $foodType = findValue($assoc, ['foodtype','cuisine','category','specialty','offerings']);
                $ratingText = findValue($assoc, ['averagerating','starrating','reviewscore','rating']);
                $reviewCountText = findValue($assoc, ['reviewers','reviewcount','numberofreviews','totalreviews']);
                $price = findValue($assoc, ['pricerange','pricelevel','averageprice']);
                $description = findValue($assoc, ['description','about']);
                if ($description === '') $description = $foodType;

                $rating = is_numeric($ratingText) && (float)$ratingText > 0 ? (float)$ratingText : null;
                $reviewCount = is_numeric($reviewCountText) ? (int)$reviewCountText : null;
                if ($storeId !== '' && isset($reviewStats[$storeId])) {
                    $stats = $reviewStats[$storeId];
                    if ($reviewCount === null || $reviewCount === 0) $reviewCount = $stats['count'];
                    if ($rating === null && $stats['scored'] > 0) $rating = round($stats['sum'] / $stats['scored'], 1);
                }
                $record = [
                    'name'=>$name, 'address'=>$address, 'city'=>findValue($assoc, ['city']),
                    'food_type'=>$foodType, 'price_range'=>$price, 'description'=>$description,
                    'rating'=>$rating, 'review_count'=>$reviewCount, 'maps_url'=>'',
                    'store_id'=>$storeId, 'source'=>basename($file)
                ];
                // StoreId alone is not unique for every branch; deduplicate on name + city/address.
                $record['_id'] = hash('sha256', strtolower($name.'|'.$address.'|'.$record['city']));
                $restaurantRows[] = $record;
            }
        } catch (Throwable $e) {
            error_log('Could not read dataset ' . basename($file) . ': ' . $e->getMessage());
        }
    }

    $places = []; $seen = [];
    foreach ($restaurantRows as $record) {
        if (isset($seen[$record['_id']])) {
            $idx = $seen[$record['_id']];
            foreach (['address','food_type','price_range','description','rating','review_count','maps_url'] as $field) {
                if (($places[$idx][$field] === '' || $places[$idx][$field] === null || $places[$idx][$field] === 0) && $record[$field] !== '' && $record[$field] !== null && $record[$field] !== 0) $places[$idx][$field] = $record[$field];
            }
        } else {
            $seen[$record['_id']] = count($places);
            $places[] = $record;
        }
    }
    return $places;
}
function shortlistPlaces(array $places, string $query, int $limit): array {
    $terms = array_values(array_filter(preg_split('/[^\pL\pN]+/u', mb_strtolower($query)), fn($w) => mb_strlen($w) > 1));
    $scored = [];
    foreach ($places as $p) {
        $haystack = mb_strtolower(implode(' ', [$p['name'],$p['food_type'] ?? '',$p['description'],$p['address'],$p['price_range']]));
        $score = 0;
        foreach ($terms as $term) {
            if (mb_strpos($haystack, $term) !== false) $score += (mb_strpos(mb_strtolower($p['name']), $term) !== false) ? 4 : 1;
        }
        // Mild boosts for cuisine/category records directly matching common food phrases.
        if (preg_match('/fried\s+chicken/i', $query) && preg_match('/fried chicken|chicken/i', $haystack)) $score += 2;
        if ($score > 0) $scored[] = ['score'=>$score, 'place'=>$p];
    }
    usort($scored, fn($a,$b) => $b['score'] <=> $a['score']);
    $chosen = array_map(fn($x) => $x['place'], array_slice($scored, 0, $limit));
    if (count($chosen) < 10) {
        $used = array_fill_keys(array_map(fn($p)=>$p['_id'], $chosen), true);
        foreach ($places as $p) {
            if (!isset($used[$p['_id']])) $chosen[] = $p;
            if (count($chosen) >= min($limit, 120)) break;
        }
    }
    return $chosen;
}
function answerWithGemini(string $query, array $history, array $candidates): array {
    $fallbackIds = array_map(fn($p) => $p['_id'], $candidates);
    $fallback = 'Here are some Davao City places that may fit your request. You can keep asking for alternatives, a cuisine, or a budget and I will narrow the options.';
    if (GEMINI_API_KEY === '' || str_contains(GEMINI_API_KEY, 'PASTE_YOUR_')) return [$fallback, $fallbackIds];

    $compact = [];
    foreach ($candidates as $p) $compact[] = [
        'id'=>$p['_id'], 'name'=>$p['name'], 'food_type'=>$p['food_type'] ?? '',
        'description'=>$p['description'], 'address'=>$p['address'], 'city'=>$p['city'] ?? '',
        'price_range'=>$p['price_range'], 'rating'=>$p['rating'], 'review_count'=>$p['review_count']
    ];
    $chat = [];
    foreach ($history as $turn) {
        if (!is_array($turn)) continue;
        $role = ($turn['role'] ?? '') === 'assistant' ? 'model' : 'user';
        $content = trim((string)($turn['content'] ?? ''));
        if ($content !== '') $chat[] = ['role'=>$role, 'parts'=>[['text'=>mb_substr($content, 0, 1200)]]];
    }
    $chat[] = ['role'=>'user','parts'=>[['text'=>
        "Latest user request: {$query}\n\nYou are Davao Food Finder, a helpful conversational guide to restaurants and food places in Davao City. Use only the supplied records. Interpret follow-ups using the conversation history (e.g. cheaper options, more choices, similar places). Do not invent ratings, prices, addresses, reviews, or restaurants. If information is absent, say it is not available. Return JSON only with keys answer (short friendly response) and ids (array of supplied IDs ranked best first). Aim for up to 10 relevant options if available, but do not claim unsupported facts.\nRecords:\n" . json_encode($compact, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)
    ]]];
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode(GEMINI_MODEL) . ':generateContent';
    $payload = json_encode([
        'systemInstruction'=>['parts'=>[['text'=>'You are a grounded restaurant discovery assistant for Davao City. Never invent dataset facts. Keep answers concise and use only supplied place records. Return valid JSON.']]],
        'contents'=>$chat,
        'generationConfig'=>['responseMimeType'=>'application/json','temperature'=>0.2,'maxOutputTokens'=>1800]
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST=>true,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json', 'x-goog-api-key: ' . GEMINI_API_KEY],
        CURLOPT_POSTFIELDS=>$payload, CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>50
    ]);
    $raw = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $curlError = curl_error($ch); curl_close($ch);
    if ($raw === false || $status < 200 || $status >= 300) {
        error_log('Gemini API error HTTP ' . $status . ': ' . ($curlError ?: (string)$raw));
        return ['Gemini could not be reached just now, so I am showing locally matched places. Check the server error log and your API key/model settings if this continues.', $fallbackIds];
    }
    $decoded = json_decode($raw, true);
    $text = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? '';
    $parsed = json_decode($text, true);
    if (!is_array($parsed)) return [$fallback, $fallbackIds];
    $allowed = array_fill_keys($fallbackIds, true); $ids = [];
    foreach (($parsed['ids'] ?? []) as $id) if (is_string($id) && isset($allowed[$id]) && !in_array($id, $ids, true)) $ids[] = $id;
    $answer = trim((string)($parsed['answer'] ?? $fallback));
    return [$answer !== '' ? $answer : $fallback, $ids ?: $fallbackIds];
}
function publicPlace(array $p): array { return $p; }
