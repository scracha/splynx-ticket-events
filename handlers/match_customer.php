<?php
/**
 * Splynx Voicemail Customer Matcher Handler
 *
 * Multi-signal scoring engine with fuzzy/phonetic matching:
 *   - Caller ID / Transcribed phone matching (100% / 95%)
 *   - Compound matching (Address + Name disambiguation)
 *   - Tokenized phonetic address matching (handles Belvadere/Belveder/Belvedier vs Belvedere)
 *   - Clean street-only address extraction (captures 'Belvedere Road', 'Elevation Cottage')
 *   - Strict first name matching (threshold >= 85% prevents 'Peter' matching 'Petra')
 *   - Comprehensive honorific/title stripping (detective, reverend, sergeant, professor, etc.)
 *   - Multi-service customer phone/address aggregation
 *   - Tie/ambiguity protection (no arbitrary auto-assign on tied scores)
 *   - Alphanumeric house numbers (e.g. 368A vs 368)
 *   - Middle name and punctuation-tolerant name matching
 *   - Blacklist for 'anonymous', 'unknown', etc.
 *   - Dynamic subject renaming:
 *       Certain:   #<id> VOICEMAIL: caller NAME - street address, town [FROM number]
 *       Unknown:   #<id> VOICEMAIL: caller unknown [FROM number]
 *
 * Events handled: new_ticket only (runs after transcribe handler)
 */

define('MATCH_DATA_STORE', '/dev/shm/splynx_active_services.json');

// Fallback logMsg definition if required in isolation outside poll.php
if (!function_exists('logMsg')) {
    function logMsg(string $msg): void {
        echo "[" . date('Y-m-d H:i:s') . "] {$msg}\n";
    }
}

// ============================================================
// Multi-Signal Scoring Engine & Helper Functions
// ============================================================

if (!function_exists('scoreCustomerCandidates')) {
function scoreCustomerCandidates(array $customers, ?string $callerPhone, array $spokenPhones, array $addresses, array $names): array
{
    $scored = [];

    $callerVariants = $callerPhone ? phoneSearchVariants($callerPhone) : [];
    $spokenVariantsList = array_map('phoneSearchVariants', $spokenPhones);

    // Pre-calculate database frequency for single first names (<= 3 in database)
    $unusualFirstNames = [];
    foreach ($names as $nameObj) {
        if (!$nameObj['is_full']) {
            $fName = $nameObj['name'];
            $occurrences = countFirstNameOccurrences($customers, $fName);
            $total = count($occurrences);
            if ($total >= 1 && $total <= 3) {
                $unusualFirstNames[strtolower($fName)] = [
                    'count'   => $total,
                    'matches' => $occurrences,
                ];
            }
        }
    }

    foreach ($customers as $cid => $cust) {
        $bestScore = 0;
        $reasons = [];
        $bestMatchedAddr = null;

        $allPhones = $cust['phones'] ?? [];

        // 1. Caller ID match (100%)
        if (!empty($callerVariants) && !empty($allPhones)) {
            foreach ($allPhones as $p) {
                if (phoneMatchesAny($p, $callerVariants)) {
                    $bestScore = 100;
                    $reasons[] = "caller ID ({$callerPhone}) matched customer phone {$p}";
                    break;
                }
            }
        }

        // 2. Transcribed phone match (95%)
        if ($bestScore < 95 && !empty($spokenVariantsList) && !empty($allPhones)) {
            foreach ($spokenVariantsList as $idx => $vList) {
                foreach ($allPhones as $p) {
                    if (phoneMatchesAny($p, $vList)) {
                        $bestScore = max($bestScore, 95);
                        $reasons[] = "transcribed phone ({$spokenPhones[$idx]}) matched customer phone {$p}";
                        break 2;
                    }
                }
            }
        }

        // 3. Address match evaluation
        $addrScore = 0;
        $addrReason = '';
        if (!empty($addresses) && !empty($cust['addresses'])) {
            foreach ($addresses as $inputAddr) {
                foreach ($cust['addresses'] as $custAddr) {
                    $sim = evaluateAddressSimilarity($inputAddr, $custAddr);
                    if ($sim > $addrScore) {
                        $addrScore = $sim;
                        $addrReason = "address '{$inputAddr}' matched '{$custAddr}' (" . round($sim) . "%)";
                        $bestMatchedAddr = $custAddr;
                    }
                }
            }
        }

        // 4. Name match evaluation
        $nameScore = 0;
        $nameReason = '';
        $matchedFirstNameOnly = false;

        if (!empty($names)) {
            foreach ($names as $nameObj) {
                $nStr = $nameObj['name'];
                $isFull = $nameObj['is_full'];

                $custName = $cust['customer_name'];
                $c2Name = $cust['contact_2_name'];

                $nSim1 = evaluateNameSimilarity($nStr, $custName, $isFull);
                $nSim2 = $c2Name ? evaluateNameSimilarity($nStr, $c2Name, $isFull) : 0;
                $maxNSim = max($nSim1, $nSim2);

                if ($maxNSim > $nameScore) {
                    $nameScore = $maxNSim;
                    $matchedField = ($nSim1 >= $nSim2) ? "customer name '{$custName}'" : "contact 2 '{$c2Name}'";
                    $nameReason = "name '{$nStr}' matched {$matchedField}";
                    $matchedFirstNameOnly = !$isFull;
                }
            }
        }

        // 5. Unusual First Name match (<= 3 in database)
        $unusualScore = 0;
        $unusualReason = '';
        foreach ($unusualFirstNames as $fName => $data) {
            if (isset($data['matches'][$cid])) {
                $matchedField = $data['matches'][$cid];
                $cnt = $data['count'];
                $unusualScore = ($cnt === 1) ? 70 : (($cnt === 2) ? 67 : 65);
                $unusualReason = "unusual first name '{$fName}' (only {$cnt} in system) matched {$matchedField}";
                break;
            }
        }

        // 6. Compound Disambiguation (Address + Name)
        if ($addrScore >= 70 && ($nameScore >= 70 || $unusualScore >= 65)) {
            $nVal = max($nameScore, $unusualScore);
            $compoundScore = min(100, (int)($addrScore * 0.55 + $nVal * 0.45 + 18));
            if ($compoundScore > $bestScore) {
                $bestScore = $compoundScore;
                $reasons[] = "compound match: {$addrReason} + " . ($nameReason ?: $unusualReason);
            }
        } elseif ($addrScore >= 70) {
            if ($addrScore > $bestScore) {
                $bestScore = (int)$addrScore;
                $reasons[] = $addrReason;
            }
        } elseif ($nameScore >= 75 && !$matchedFirstNameOnly) {
            if ($nameScore > $bestScore) {
                $bestScore = (int)$nameScore;
                $reasons[] = $nameReason;
            }
        } elseif ($unusualScore >= 65) {
            if ($unusualScore > $bestScore) {
                $bestScore = $unusualScore;
                $reasons[] = $unusualReason;
            }
        }

        if ($bestScore >= 60) {
            $primaryAddr = $bestMatchedAddr ?: (!empty($cust['addresses']) ? $cust['addresses'][0] : null);
            $scored[] = [
                'customer_id'     => $cid,
                'customer_name'   => $cust['customer_name'],
                'confidence'      => $bestScore,
                'reason'          => implode('; ', $reasons),
                'matched_address' => $primaryAddr,
                'city'            => $cust['city'] ?? '',
            ];
        }
    }

    return $scored;
}
}

if (!function_exists('formatAddressWithTown')) {
function formatAddressWithTown(?string $address, ?string $town): ?string
{
    $cleanAddr = '';
    if (!empty($address)) {
        $cleanAddr = trim(preg_replace('/\s+/', ' ', $address));
        $cleanAddr = preg_replace('/\s+\d{4}$/', '', $cleanAddr);
        $cleanAddr = rtrim($cleanAddr, " ,.");
    }

    $cleanTown = '';
    if (!empty($town)) {
        $cleanTown = trim(preg_replace('/\s+/', ' ', $town));
        $cleanTown = preg_replace('/\s+\d{4}$/', '', $cleanTown);
        $cleanTown = rtrim($cleanTown, " ,.");
    }

    if (!empty($cleanAddr) && !empty($cleanTown)) {
        if (preg_match('/\b' . preg_quote($cleanTown, '/') . '\b/i', $cleanAddr)) {
            return $cleanAddr;
        }
        return "{$cleanAddr}, {$cleanTown}";
    }

    if (!empty($cleanAddr)) {
        return $cleanAddr;
    }

    if (!empty($cleanTown)) {
        return $cleanTown;
    }

    return null;
}
}

if (!function_exists('buildVoicemailSubject')) {
function buildVoicemailSubject(int $ticketId, ?string $customerName = null, ?string $address = null, ?string $town = null, ?string $fromPhone = null): string
{
    $subject = "#{$ticketId} VOICEMAIL: caller";

    if (!empty($customerName)) {
        $subject .= " " . trim($customerName);
        $location = formatAddressWithTown($address, $town);
        if (!empty($location)) {
            $subject .= " - {$location}";
        }
    } else {
        $subject .= " unknown";
    }

    if (!empty($fromPhone)) {
        $cleanPhone = preg_replace('/[\s\-()]/', '', $fromPhone);
        if (preg_match('/\d{6,}/', $cleanPhone)) {
            if (preg_match('/^\+?64(\d+)$/', $cleanPhone, $pm)) {
                $cleanPhone = '0' . $pm[1];
            }
            $subject .= " FROM {$cleanPhone}";
        }
    }

    return $subject;
}
}

if (!function_exists('getCommonWordBlacklist')) {
function getCommonWordBlacklist(): array
{
    return [
        'that', 'this', 'there', 'here', 'the', 'a', 'an', 'and', 'or', 'but',
        'it', 'its', 'he', 'she', 'they', 'we', 'you', 'i', 'me', 'him', 'her', 'us', 'them',
        'what', 'which', 'who', 'whom', 'where', 'when', 'why', 'how',
        'is', 'am', 'are', 'was', 'were', 'be', 'been', 'being', 'have', 'has', 'had', 'do', 'does', 'did',
        'can', 'could', 'shall', 'should', 'will', 'would', 'may', 'might', 'must',
        'just', 'back', 'about', 'some', 'any', 'other', 'another', 'one', 'all', 'only',
        'calling', 'making', 'leaving', 'testing', 'checking', 'trying', 'speaking', 'ringing',
        'message', 'call', 'voicemail', 'ticket', 'phone', 'wifi', 'internet', 'modem',
        'anonymous', 'unknown', 'caller', 'wireless', 'splynx', 'support', 'restricted', 'private', 'none', 'user',
        'way', 'road', 'street', 'drive', 'avenue', 'place', 'lane', 'close', 'court', 'terrace',
        'good', 'morning', 'afternoon', 'evening', 'night', 'today', 'tomorrow', 'yesterday',
        'hello', 'hi', 'hey', 'um', 'uh', 'ah', 'er', 'ok', 'okay', 'yeah', 'yep', 'nope', 'well', 'so',
        'thank', 'thanks', 'please', 'sorry', 'test', 'pertaining', 'regarding', 'regards', 'cheers',
        'new', 'zealand', 'service', 'services', 'account', 'number', 'address', 'monday', 'tuesday', 'wednesday',
        'thursday', 'friday', 'saturday', 'sunday', 'january', 'february', 'march', 'april', 'may', 'june',
        'july', 'august', 'september', 'october', 'november', 'december'
    ];
}
}

if (!function_exists('countFirstNameOccurrences')) {
function countFirstNameOccurrences(array $customers, string $nameToken): array
{
    $tokenLower = strtolower(trim($nameToken));
    $blacklist = getCommonWordBlacklist();
    if (in_array($tokenLower, $blacklist) || strlen($tokenLower) < 3) {
        return [];
    }

    $matches = [];

    foreach ($customers as $cid => $cust) {
        $cName = strtolower($cust['customer_name']);
        $c2Name = strtolower($cust['contact_2_name']);

        $found = false;
        $matchedField = '';

        $cWords = preg_split('/[^a-zA-Z0-9]+/', $cName);
        foreach ($cWords as $w) {
            if (isNameWordMatch($w, $tokenLower)) {
                $found = true;
                $matchedField = "customer name '{$cust['customer_name']}'";
                break;
            }
        }

        if (!$found && $c2Name) {
            $c2Words = preg_split('/[^a-zA-Z0-9]+/', $c2Name);
            foreach ($c2Words as $w) {
                if (isNameWordMatch($w, $tokenLower)) {
                    $found = true;
                    $matchedField = "contact 2 '{$cust['contact_2_name']}'";
                    break;
                }
            }
        }

        if ($found) {
            $matches[$cid] = $matchedField;
        }
    }

    return $matches;
}
}

if (!function_exists('isNameWordMatch')) {
function isNameWordMatch(string $word, string $target): bool
{
    $w = strtolower(trim($word));
    $t = strtolower(trim($target));
    if ($w === $t) return true;
    if (strlen($w) < 3 || strlen($target) < 3) return false;

    $blacklist = getCommonWordBlacklist();
    if (in_array($w, $blacklist) || in_array($t, $blacklist)) return false;

    if (strlen($w) <= 4 && strlen($t) <= 4) {
        return false;
    }

    if (levenshtein($w, $t) <= 1 && $w[0] === $t[0]) {
        similar_text($w, $t, $pct);
        if ($pct >= 85) {
            return true;
        }
    }

    $wMeta = metaphone($w);
    $tMeta = metaphone($t);
    if ($wMeta && $wMeta === $tMeta) {
        similar_text($w, $t, $pct);
        if ($pct >= 85) {
            return true;
        }
    }

    return false;
}
}

if (!function_exists('evaluateAddressSimilarity')) {
function evaluateAddressSimilarity(string $input, string $target): float
{
    $inNorm = normalizeAddress($input);
    $tarNorm = normalizeAddress($target);

    if ($inNorm === $tarNorm) return 95.0;

    preg_match('/^(\d+[a-z]?)\s+(.*)$/i', $inNorm, $mIn);
    preg_match('/^(\d+[a-z]?)\s+(.*)$/i', $tarNorm, $mTar);

    $hasInNum = !empty($mIn);
    $hasTarNum = !empty($mTar);

    $inNumber = $hasInNum ? strtolower($mIn[1]) : null;
    $tarNumber = $hasTarNum ? strtolower($mTar[1]) : null;

    $inStreet = $hasInNum ? trim($mIn[2]) : $inNorm;
    $tarStreet = $hasTarNum ? trim($mTar[2]) : $tarNorm;

    if ($hasInNum && $hasTarNum) {
        $inDigits = preg_replace('/\D/', '', $inNumber);
        $tarDigits = preg_replace('/\D/', '', $tarNumber);
        if ($inDigits !== $tarDigits && $inNumber !== $tarNumber) {
            return 0.0;
        }
    }

    $inWords = array_values(array_filter(preg_split('/\s+/', $inStreet)));
    $tarWords = array_values(array_filter(preg_split('/\s+/', $tarStreet)));

    if (empty($inWords) || empty($tarWords)) {
        return 0.0;
    }

    $matchedTokens = 0;
    $totalInTokens = count($inWords);

    foreach ($inWords as $iw) {
        $tokenMatched = false;
        $iwMeta = metaphone($iw);
        foreach ($tarWords as $tw) {
            if ($iw === $tw) {
                $tokenMatched = true;
                break;
            }
            if (strlen($iw) >= 4 && $iwMeta && $iwMeta === metaphone($tw)) {
                $tokenMatched = true;
                break;
            }
            if (strlen($iw) >= 5 && levenshtein($iw, $tw) <= 1) {
                $tokenMatched = true;
                break;
            }
        }
        if ($tokenMatched) {
            $matchedTokens++;
        }
    }

    $tokenRatio = $matchedTokens / $totalInTokens;
    if ($tokenRatio < 0.75) {
        return 0.0;
    }

    if ($hasInNum && $hasTarNum && $inNumber === $tarNumber) {
        return 88.0;
    }

    return 72.0;
}
}

if (!function_exists('evaluateNameSimilarity')) {
function evaluateNameSimilarity(string $searchName, string $targetName, bool $isFullName): float
{
    $searchLower = strtolower(trim($searchName));
    $targetLower = strtolower(trim($targetName));

    $blacklist = getCommonWordBlacklist();
    if (in_array($searchLower, $blacklist)) return 0.0;

    $tParts = preg_split('/[^a-zA-Z0-9]+/', $targetLower);
    $tParts = array_values(array_filter($tParts));

    if (!$isFullName) {
        foreach ($tParts as $tw) {
            if (isNameWordMatch($tw, $searchLower)) return 75.0;
        }
        return 0.0;
    }

    $sParts = preg_split('/[^a-zA-Z0-9]+/', $searchLower);
    $sParts = array_values(array_filter($sParts));
    if (count($sParts) < 2) return 0.0;

    $first = $sParts[0];
    $last = end($sParts);

    if (in_array($first, $blacklist) || in_array($last, $blacklist)) return 0.0;

    $firstPos = null;
    $lastPos = null;
    foreach ($tParts as $idx => $tw) {
        if ($firstPos === null && isNameWordMatch($tw, $first)) {
            $firstPos = $idx;
        } elseif ($firstPos !== null && isNameWordMatch($tw, $last)) {
            $lastPos = $idx;
            break;
        }
    }

    if ($firstPos !== null && $lastPos !== null && $lastPos > $firstPos) {
        return 85.0;
    }

    return 0.0;
}
}

if (!function_exists('normalizeAddress')) {
function normalizeAddress(string $addr): string
{
    $a = strtolower(trim($addr));
    $a = preg_replace('/[,.#]/', ' ', $a);
    $replacements = [
        '/\brd\b/' => 'road', '/\bst\b/' => 'street', '/\bave\b/' => 'avenue',
        '/\bdr\b/' => 'drive', '/\bcres\b/' => 'crescent', '/\bpl\b/' => 'place',
        '/\bln\b/' => 'lane', '/\bcl\b/' => 'close', '/\bter\b/' => 'terrace',
        '/\bhwy\b/' => 'highway',
    ];
    $a = preg_replace(array_keys($replacements), array_values($replacements), $a);
    return preg_replace('/\s+/', ' ', $a);
}
}

if (!function_exists('postMatchSuccessNote')) {
function postMatchSuccessNote(SplynxApiClient $api, int $ticketId, int $customerId, string $customerName, string $reason): void
{
    global $noteAuthorId, $splynxAdminUrl;
    $adminUrl = rtrim($splynxAdminUrl ?? '', '/');
    $custUrl = $adminUrl ? "{$adminUrl}/admin/customers/view?id={$customerId}" : "#";

    $noteBody = "<p>🎯 <strong>Customer Auto-Assigned:</strong> <a href=\"{$custUrl}\">" . htmlspecialchars($customerName) . " (#{$customerId})</a><br>"
        . "<strong>Match Detail:</strong> " . htmlspecialchars($reason) . "</p>";

    $api->post("admin/support/ticket-messages", [
        'ticket_id'    => $ticketId,
        'message'      => $noteBody,
        'message_type' => 'note',
        'admin_id'     => $noteAuthorId ?? 1,
    ]);
}
}

if (!function_exists('postSuggestedMatchesNote')) {
function postSuggestedMatchesNote(SplynxApiClient $api, int $ticketId, array $candidates): void
{
    global $noteAuthorId, $splynxAdminUrl;
    $adminUrl = rtrim($splynxAdminUrl ?? '', '/');

    $count = count($candidates);
    $title = ($count === 1) ? "Suggested Customer Match" : "Suggested Customer Matches ({$count} candidates)";

    $noteBody = "<p>💡 <strong>{$title}:</strong> Left unassigned (did not meet auto-assign criteria). Potential match(es) detected:</p><ul>";
    foreach ($candidates as $c) {
        $cName = !empty($c['customer_name']) ? $c['customer_name'] : "Customer #{$c['customer_id']}";
        $custUrl = $adminUrl ? "{$adminUrl}/admin/customers/view?id={$c['customer_id']}" : "#";
        $noteBody .= "<li><a href=\"{$custUrl}\"><strong>" . htmlspecialchars($cName) . " (#{$c['customer_id']})</strong></a> "
            . "— <em>Confidence: {$c['confidence']}%</em> (" . htmlspecialchars($c['reason']) . ")</li>";
    }
    $noteBody .= "</ul><p><em>Click a customer above to review and manually assign if correct.</em></p>";

    $api->post("admin/support/ticket-messages", [
        'ticket_id'    => $ticketId,
        'message'      => $noteBody,
        'message_type' => 'note',
        'admin_id'     => $noteAuthorId ?? 1,
    ]);
}
}

if (!function_exists('aggregateCustomerStore')) {
function aggregateCustomerStore(array $dataStore): array
{
    $customers = [];
    foreach ($dataStore as $entry) {
        $cid = (int)($entry['customer_id'] ?? 0);
        if ($cid <= 0) continue;

        if (!isset($customers[$cid])) {
            $customers[$cid] = [
                'customer_id'     => $cid,
                'customer_name'   => $entry['customer_name'] ?? '',
                'contact_2_name'  => $entry['contact_2_name'] ?? '',
                'customer_phone'  => $entry['customer_phone'] ?? '',
                'contact_2_phone' => $entry['contact_2_phone'] ?? '',
                'addresses'       => [],
                'phones'          => [],
                'city'            => '',
            ];
        }

        if (empty($customers[$cid]['customer_name']) && !empty($entry['customer_name'])) {
            $customers[$cid]['customer_name'] = $entry['customer_name'];
        }
        if (empty($customers[$cid]['contact_2_name']) && !empty($entry['contact_2_name'])) {
            $customers[$cid]['contact_2_name'] = $entry['contact_2_name'];
        }

        if (empty($customers[$cid]['city'])) {
            foreach (['service_city', 'city', 'customer_city', 'town', 'service_town', 'billing_city'] as $ck) {
                if (!empty($entry[$ck])) {
                    $customers[$cid]['city'] = trim($entry[$ck]);
                    break;
                }
            }
        }

        foreach (['customer_phone', 'contact_2_phone'] as $pk) {
            if (!empty($entry[$pk])) {
                $customers[$cid]['phones'][] = $entry[$pk];
            }
        }

        if (!empty($entry['service_address'])) {
            $customers[$cid]['addresses'][] = $entry['service_address'];
        }
        if (!empty($entry['customer_address_fallback'])) {
            $customers[$cid]['addresses'][] = $entry['customer_address_fallback'];
        }
    }

    foreach ($customers as &$c) {
        $c['addresses'] = array_values(array_unique(array_filter($c['addresses'])));
        $c['phones'] = array_values(array_unique(array_filter($c['phones'])));
    }
    unset($c);

    return $customers;
}
}

if (!function_exists('getFirstTicketMessageBody')) {
function getFirstTicketMessageBody(SplynxApiClient $api, int $ticketId): string
{
    $messages = $api->get("admin/support/ticket-messages", [
        'main_attributes' => ['ticket_id' => $ticketId, 'message_type' => 'message'],
    ]);
    if (!is_array($messages) || empty($messages)) return '';
    $first = reset($messages);
    return strip_tags($first['message'] ?? '');
}
}

if (!function_exists('extractCallerId')) {
function extractCallerId(string $subject, string $body = ''): ?string
{
    if ($body && preg_match('/From:\s*([+\d(][\d\s\-().]{5,})/i', $body, $m)) {
        $clean = preg_replace('/[\s\-().]/', '', $m[1]);
        if (strlen($clean) >= 7) return $clean;
    }
    if (preg_match('/from\s+(?:caller\s+)?.*\(([+\d(][\d\s\-().]{5,})\)/i', $subject, $m)) {
        $clean = preg_replace('/[\s\-().]/', '', $m[1]);
        if (strlen($clean) >= 7) return $clean;
    }
    if (preg_match('/from\s+(?:caller\s+)?([+\d(][\d\s\-().]{6,})/i', $subject, $m)) {
        $clean = preg_replace('/[\s\-().]/', '', $m[1]);
        if (strlen($clean) >= 7) return $clean;
    }
    return null;
}
}

if (!function_exists('extractPhoneNumbers')) {
function extractPhoneNumbers(string $text): array
{
    $phones = [];
    if (preg_match_all('/\b(?:\+?64|0)[\d\s\-()]{6,14}\b/', $text, $matches)) {
        foreach ($matches[0] as $m) {
            $clean = preg_replace('/[\s\-()]/', '', $m);
            if (strlen($clean) >= 7 && strlen($clean) <= 12) {
                $phones[] = $clean;
            }
        }
    }
    return array_unique($phones);
}
}

if (!function_exists('getHonorificTitlePattern')) {
function getHonorificTitlePattern(): string
{
    return '(?:mr|mrs|ms|miss|dr|doctor|prof|professor|rev|reverend|reverand|pastor|fr|father|det|detective|sgt|sergeant|sargent|sargeant|constable|cst|inspector|insp|capt|captain|officer|sir|dame)';
}
}

if (!function_exists('extractNames')) {
function extractNames(string $transcription, string $subject): array
{
    $names = [];
    $blacklist = getCommonWordBlacklist();
    $titles = getHonorificTitlePattern();
    $stopWords = ['from', 'at', 'in', 'on', 'near', 'with', 'about', 'and', 'making', 'calling', 'leaving', 'testing', 'checking', 'trying', 'here', 'speaking', 'pertaining', 'regarding', 'to', 'the', 'a', 'an'];

    // 1. From subject
    if (preg_match("/from\s+(?:caller\s+)?([A-Za-z0-9\s.,'-]+?)(?:\s*\(|\s*$)/i", $subject, $m)) {
        $raw = trim($m[1]);
        $clean = preg_replace('/^(?:' . $titles . ')\.?\s+/i', '', $raw);
        if (!preg_match('/\d/', $clean)) {
            $cleanWords = preg_split('/[^a-zA-Z]+/', strtolower($clean));
            $hasBlacklist = false;
            foreach ($cleanWords as $cw) {
                if (in_array($cw, $blacklist)) { $hasBlacklist = true; break; }
            }
            if (!$hasBlacklist && strlen($clean) > 2) {
                $isFull = count(array_filter($cleanWords)) >= 2;
                $names[] = ['name' => $clean, 'is_full' => $isFull];
            }
        }
    }

    // 2. Direct title match anywhere in speech
    if (preg_match_all('/\b(?:' . $titles . ')\.?\s+([A-Z][a-z]+(?:\s+[A-Z][a-z]+)*)/', $transcription, $tm)) {
        foreach ($tm[1] as $tn) {
            $clean = trim($tn, " .,!?:;");
            $cleanWords = preg_split('/\s+/', $clean);
            $valid = true;
            foreach ($cleanWords as $cw) {
                $cwL = strtolower($cw);
                if (in_array($cwL, $blacklist) || in_array($cwL, $stopWords)) { $valid = false; break; }
            }
            if ($valid && strlen($clean) > 2) {
                $isFull = count($cleanWords) >= 2;
                $names[] = ['name' => $clean, 'is_full' => $isFull];
            }
        }
    }

    // 3. Spoken introductions: 'from Gemma Seddon', 'this is John Smith', 'my name is Petra', 'I am Gemma'
    $introPattern = "/(?:\b(?:from|call\s+from|calling\s+from|message\s+from|it'?s|it\s+is|this is|this was|my name is|my name's|i'?m|i\s+am|name is|name's)\s+)(?:{$titles}\.?\s+)?([A-Z][a-z]+(?:\s+[A-Z][a-z]+)*)/i";
    if (preg_match_all($introPattern, $transcription, $matches)) {
        foreach ($matches[1] as $n) {
            $n = trim($n, " .,!?:;");
            $words = preg_split('/\s+/', $n);
            $cleanWords = [];
            foreach ($words as $w) {
                $wLower = strtolower($w);
                if (in_array($wLower, $stopWords) || in_array($wLower, $blacklist)) {
                    break;
                }
                $cleanWords[] = $w;
            }

            if (!empty($cleanWords)) {
                $cleanName = implode(' ', $cleanWords);
                if (strlen($cleanName) > 2 && !preg_match('/\d/', $cleanName) && !in_array(strtolower($cleanName), $blacklist)) {
                    $isFull = count($cleanWords) >= 2;
                    $names[] = ['name' => $cleanName, 'is_full' => $isFull];
                }
            }
        }
    }

    // 4. Spoken suffixes: 'Gemma Seddon here', 'John Smith calling', 'Petra speaking'
    $suffixPattern = '/\b([A-Z][a-z]+(?:\s+[A-Z][a-z]+)*)\s+(?:here|speaking|calling)\b/i';
    if (preg_match_all($suffixPattern, $transcription, $sm)) {
        foreach ($sm[1] as $n) {
            $n = trim($n, " .,!?:;");
            $words = preg_split('/\s+/', $n);
            $cleanWords = [];
            foreach ($words as $w) {
                $wLower = strtolower($w);
                if (in_array($wLower, $stopWords) || in_array($wLower, $blacklist)) {
                    break;
                }
                $cleanWords[] = $w;
            }

            if (!empty($cleanWords)) {
                $cleanName = implode(' ', $cleanWords);
                if (strlen($cleanName) > 2 && !preg_match('/\d/', $cleanName) && !in_array(strtolower($cleanName), $blacklist)) {
                    $isFull = count($cleanWords) >= 2;
                    $names[] = ['name' => $cleanName, 'is_full' => $isFull];
                }
            }
        }
    }

    // 5. Standalone Title Case 2-to-3 word names (with greeting lookahead guard)
    $leadBlock = '(?:Hi|Hey|Hello|Dear|Good|Morning|Afternoon|Evening|Night|Today|Tomorrow|Yesterday|Test|Voice|Message|New|Zealand|Please|Thank|Thanks|Sorry)';
    if (preg_match_all('/\b(?!\b' . $leadBlock . '\b)([A-Z][a-z]{1,}\s+[A-Z][a-z]{1,}(?:\s+[A-Z][a-z]{1,})?)\b/', $transcription, $standMatches)) {
        foreach ($standMatches[1] as $sn) {
            $sn = trim($sn, " .,!?:;");
            $words = preg_split('/\s+/', $sn);
            $valid = true;
            foreach ($words as $w) {
                $wLower = strtolower($w);
                if (in_array($wLower, $stopWords) || in_array($wLower, $blacklist)) {
                    $valid = false;
                    break;
                }
            }
            if ($valid && count($words) >= 2) {
                $names[] = ['name' => $sn, 'is_full' => true];
            }
        }
    }

    $unique = [];
    foreach ($names as $item) {
        $k = strtolower($item['name']);
        if (!isset($unique[$k])) {
            $unique[$k] = $item;
        }
    }
    return array_values($unique);
}
}

if (!function_exists('extractAddresses')) {
function extractAddresses(string $transcription): array
{
    $addresses = [];
    $streetTypes = 'Road|Street|Avenue|Drive|Place|Way|Lane|Crescent|Terrace|Close|Court|Highway|Track|Line|Grove|Rise|Parade|Boulevard|Cottage|House|Station|Estate|Rd|St|Ave|Dr|Cres|Pl|Ln|Cl|Ter|Hwy';

    $pattern = '/(?:\b(?:in|at|from|on|near|off|along)\s+)?((?:\d+[A-Za-z]?\s+)?[A-Z][a-zA-Z]*(?:\s+[A-Z][a-zA-Z]*){0,2}\s+(?:' . $streetTypes . '))/i';
    if (preg_match_all($pattern, $transcription, $matches)) {
        foreach ($matches[1] as $m) {
            $clean = cleanAddressString($m);
            if ($clean) {
                $addresses[] = $clean;
            }
        }
    }

    return array_values(array_unique($addresses));
}
}

if (!function_exists('cleanAddressString')) {
function cleanAddressString(string $addr): ?string
{
    $a = trim($addr, " .,!?:;");
    $a = preg_replace('/^(?:in|at|from|on|near|off|along|to|and|is)\s+/i', '', $a);
    if (preg_match('/\b(?:from|at|in|on)\s+(.*)/i', $a, $sub)) {
        $a = trim($sub[1]);
    }

    $aLower = strtolower($a);
    $rejectPhrases = ['any other way', 'no other way', 'the other way', 'either way', 'this way', 'that way', 'my way', 'by the way', 'which way'];
    foreach ($rejectPhrases as $rp) {
        if (strpos($aLower, $rp) !== false) return null;
    }

    if (preg_match('/^(?:this|that|the|a|my|one|any|our|some|either|which)\s+/i', $a)) {
        return null;
    }

    return (strlen($a) > 5) ? $a : null;
}
}

if (!function_exists('phoneSearchVariants')) {
function phoneSearchVariants(string $phone): array
{
    $stripped = preg_replace('/[\s\-()]+/', '', $phone);
    if (empty($stripped)) return [];
    $variants = [$stripped];
    if (preg_match('/^0(\d+)$/', $stripped, $m)) {
        $variants[] = '+64' . $m[1];
        $variants[] = '64' . $m[1];
    }
    if (preg_match('/^\+?64(\d+)$/', $stripped, $m)) {
        $variants[] = '0' . $m[1];
        $variants[] = '+64' . $m[1];
        $variants[] = '64' . $m[1];
    }
    return array_unique($variants);
}
}

if (!function_exists('phoneMatchesAny')) {
function phoneMatchesAny(string $haystack, array $variants): bool
{
    if (empty($haystack)) return false;
    $h = preg_replace('/[\s\-()]+/', '', strtolower($haystack));
    if (empty($h)) return false;
    foreach ($variants as $v) {
        if ($v === '') continue;
        if ($h === strtolower($v) || strpos($h, strtolower($v)) !== false) return true;
    }
    return false;
}
}

// ============================================================
// Main Handler Closure (returned to poll.php / dispatcher)
// ============================================================

return function (array &$event, SplynxApiClient $api) {
    if ($event['type'] !== 'new_ticket') return;

    global $matchGenericCustomerIds, $matchSubjectPattern;

    $ticket = $event['ticket'];
    $ticketId = (int)$ticket['id'];
    $customerId = (int)($ticket['customer_id'] ?? 0);
    $subject = $ticket['subject'] ?? '';

    // Only process if unassigned or generic
    if ($customerId !== 0 && !in_array($customerId, $matchGenericCustomerIds ?? [])) {
        return;
    }

    $pattern = $matchSubjectPattern ?? '/voicemail|voice message/i';
    if (!preg_match($pattern, $subject)) {
        return;
    }

    logMsg("Match: Ticket #{$ticketId} — evaluating customer match candidates");

    if (!file_exists(MATCH_DATA_STORE)) {
        logMsg("Match: Data store not available at " . MATCH_DATA_STORE);
        return;
    }
    $rawStore = json_decode(file_get_contents(MATCH_DATA_STORE), true);
    if (empty($rawStore)) {
        logMsg("Match: Data store is empty");
        return;
    }

    // Aggregate store by unique customer_id (merging all services, phones, addresses)
    $customers = aggregateCustomerStore($rawStore);

    // Extract signals
    $firstMessageBody = getFirstTicketMessageBody($api, $ticketId);
    $callerPhone = extractCallerId($subject, $firstMessageBody);

    $transcription = '';
    if (!empty($event['transcriptions'])) {
        $transcription = implode(' ', array_column($event['transcriptions'], 'text'));
    }

    $transcribedPhones = $transcription ? extractPhoneNumbers($transcription) : [];
    $extractedAddresses = $transcription ? extractAddresses($transcription) : [];
    $extractedNames = extractNames($transcription, $subject);

    // Identify from phone (prefer caller ID, fallback to spoken phone)
    $fromPhone = $callerPhone ?: (!empty($transcribedPhones) ? $transcribedPhones[0] : null);

    // Score all customers
    $candidates = scoreCustomerCandidates(
        $customers,
        $callerPhone,
        $transcribedPhones,
        $extractedAddresses,
        $extractedNames
    );

    // Filter to viable candidates (confidence >= 65%)
    $viable = array_filter($candidates, fn($c) => $c['confidence'] >= 65);
    usort($viable, fn($a, $b) => $b['confidence'] <=> $a['confidence']);

    $matchCount = count($viable);

    if ($matchCount >= 1) {
        $top = $viable[0];
        $topScore = $top['confidence'];
        $secondScore = $viable[1]['confidence'] ?? 0;
        $isTied = ($topScore === $secondScore);

        // Clear winner criteria:
        // 1. Top score >= 80%
        // 2. Not tied with runner-up
        // 3. Either a 10%+ lead over runner-up OR an exceptionally high score (>= 95%) with at least 5% lead
        $isClearWinner = (!$isTied && $topScore >= 80 && ($topScore - $secondScore >= 10 || ($topScore >= 95 && $topScore - $secondScore >= 5)));

        if ($isClearWinner) {
            $assignedId = $top['customer_id'];
            $assignedName = !empty($top['customer_name']) ? $top['customer_name'] : "Customer #{$assignedId}";
            $assignedAddress = $top['matched_address'] ?? null;
            $assignedTown = $top['city'] ?? ($customers[$assignedId]['city'] ?? null);

            // If town not found in cache or name is generic, lookup customer directly via Splynx API
            if (empty($assignedTown) || $assignedName === "Customer #{$assignedId}") {
                $custDetails = $api->get("admin/customers/customer/{$assignedId}");
                if (is_array($custDetails)) {
                    if (empty($assignedTown)) {
                        $assignedTown = $custDetails['city'] ?? ($custDetails['billing_city'] ?? null);
                    }
                    if ($assignedName === "Customer #{$assignedId}" && !empty($custDetails['name'])) {
                        $assignedName = trim($custDetails['name'] . ' ' . ($custDetails['surname'] ?? ''));
                    }
                }
            }

            $reason = "{$topScore}% confidence via {$top['reason']}";

            $newSubject = buildVoicemailSubject($ticketId, $assignedName, $assignedAddress, $assignedTown, $fromPhone);

            $updateData = [
                'customer_id' => $assignedId,
            ];
            if ($subject !== $newSubject) {
                $updateData['subject'] = $newSubject;
            }

            $result = $api->put("admin/support/tickets/{$ticketId}", $updateData);

            if ($result !== null && $result !== false) {
                $ticket['subject'] = $newSubject;
                $event['ticket']['subject'] = $newSubject;

                logMsg("Match: Ticket #{$ticketId} — assigned to {$assignedName} (#{$assignedId}) [{$reason}]");
                if (isset($updateData['subject'])) {
                    logMsg("Match: Ticket #{$ticketId} — updated subject to \"{$newSubject}\"");
                }

                postMatchSuccessNote($api, $ticketId, $assignedId, $assignedName, $reason);

                $event['customer_matched'] = [
                    'customer_id'   => $assignedId,
                    'customer_name' => $assignedName,
                    'method'        => $reason,
                ];
                return;
            }

            logMsg("Match: Ticket #{$ticketId} — assignment PUT failed for {$assignedName} (#{$assignedId}); skipping unknown subject rewrite to avoid inconsistent state.");
            return;
        }
    }

    // If not a clear winner, update subject to caller unknown format
    $unknownSubject = buildVoicemailSubject($ticketId, null, null, null, $fromPhone);
    if ($subject !== $unknownSubject) {
        $updateRes = $api->put("admin/support/tickets/{$ticketId}", [
            'subject' => $unknownSubject,
        ]);
        if ($updateRes !== null && $updateRes !== false) {
            $ticket['subject'] = $unknownSubject;
            $event['ticket']['subject'] = $unknownSubject;
            logMsg("Match: Ticket #{$ticketId} — updated subject to \"{$unknownSubject}\"");
        }
    }

    // Suggested matches (1 to 3 candidates, including ties or close margins)
    if ($matchCount >= 1 && $matchCount <= 3) {
        logMsg("Match: Ticket #{$ticketId} — {$matchCount} suggested candidate(s) found (left unassigned).");

        postSuggestedMatchesNote($api, $ticketId, array_slice($viable, 0, 3));

        $candidateSummaries = array_map(function($c) {
            $cName = !empty($c['customer_name']) ? $c['customer_name'] : "Customer #{$c['customer_id']}";
            return "{$cName} (#{$c['customer_id']}, {$c['confidence']}%) [{$c['reason']}]";
        }, array_slice($viable, 0, 3));

        $event['customer_match_warning'] = ($matchCount === 1 ? "Suggested match: " : "Suggested matches: ")
            . implode(' | ', $candidateSummaries);
        return;
    }

    // More than 3 matches or no match (Unknown)
    if ($matchCount > 3) {
        logMsg("Match: Ticket #{$ticketId} — too many matches ({$matchCount} candidates). Left unassigned as unknown.");
    } else {
        logMsg("Match: Ticket #{$ticketId} — no viable customer matches found.");
    }
};
