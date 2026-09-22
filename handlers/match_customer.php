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
 *
 * Events handled: new_ticket only (runs after transcribe handler)
 */

define('MATCH_DATA_STORE', '/dev/shm/splynx_active_services.json');

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
            $assignedName = $top['customer_name'];
            $reason = "{$topScore}% confidence via {$top['reason']}";

            $result = $api->put("admin/support/tickets/{$ticketId}", [
                'customer_id' => $assignedId,
            ]);

            if ($result !== null && $result !== false) {
                logMsg("Match: Ticket #{$ticketId} — assigned to {$assignedName} (#{$assignedId}) [{$reason}]");

                postMatchSuccessNote($api, $ticketId, $assignedId, $assignedName, $reason);

                $event['customer_matched'] = [
                    'customer_id'   => $assignedId,
                    'customer_name' => $assignedName,
                    'method'        => $reason,
                ];
                return;
            }
        }
    }

    // Suggested matches (1 to 3 candidates, including ties or close margins)
    if ($matchCount >= 1 && $matchCount <= 3) {
        logMsg("Match: Ticket #{$ticketId} — {$matchCount} suggested candidate(s) found (left unassigned).");

        postSuggestedMatchesNote($api, $ticketId, array_slice($viable, 0, 3));

        $candidateSummaries = array_map(function($c) {
            return "{$c['customer_name']} (#{$c['customer_id']}, {$c['confidence']}%) [{$c['reason']}]";
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

// ============================================================
// Multi-Signal Scoring Engine
// ============================================================

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
            $scored[] = [
                'customer_id'   => $cid,
                'customer_name' => $cust['customer_name'],
                'confidence'    => $bestScore,
                'reason'        => implode('; ', $reasons),
            ];
        }
    }

    return $scored;
}

// ============================================================
// Database Frequency & Phonetic Helpers
// ============================================================

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
        'way', 'road', 'street', 'drive', 'avenue', 'place', 'lane', 'close', 'court', 'terrace'
    ];
}

/**
 * Counts how many customers have this name token (first name or surname).
 */
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

function isNameWordMatch(string $word, string $target): bool
{
    $w = strtolower(trim($word));
    $t = strtolower(trim($target));
    if ($w === $t) return true;
    if (strlen($w) < 3 || strlen($target) < 3) return false;

    $blacklist = getCommonWordBlacklist();
    if (in_array($w, $blacklist) || in_array($t, $blacklist)) return false;

    // Words with length <= 4 require exact match (prevents that vs thad, rob vs bob)
    if (strlen($w) <= 4 && strlen($t) <= 4) {
        return false;
    }

    // Typo check for length >= 5 (must share first letter and have >= 85% similarity)
    if (levenshtein($w, $t) <= 1 && $w[0] === $t[0]) {
        similar_text($w, $t, $pct);
        if ($pct >= 85) {
            return true;
        }
    }

    // Phonetic equality check: MUST have both matching metaphone AND >= 85% similarity
    // (prevents Peter [80%] from colliding with Petra)
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

// ============================================================
// Internal Note & Ticket Helpers
// ============================================================

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

function postSuggestedMatchesNote(SplynxApiClient $api, int $ticketId, array $candidates): void
{
    global $noteAuthorId, $splynxAdminUrl;
    $adminUrl = rtrim($splynxAdminUrl ?? '', '/');

    $count = count($candidates);
    $title = ($count === 1) ? "Suggested Customer Match" : "Suggested Customer Matches ({$count} candidates)";

    $noteBody = "<p>💡 <strong>{$title}:</strong> Left unassigned (confidence below 80%). Potential match(es) detected:</p><ul>";
    foreach ($candidates as $c) {
        $custUrl = $adminUrl ? "{$adminUrl}/admin/customers/view?id={$c['customer_id']}" : "#";
        $noteBody .= "<li><a href=\"{$custUrl}\"><strong>" . htmlspecialchars($c['customer_name']) . " (#{$c['customer_id']})</strong></a> "
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
            ];
        }

        if (empty($customers[$cid]['customer_name']) && !empty($entry['customer_name'])) {
            $customers[$cid]['customer_name'] = $entry['customer_name'];
        }
        if (empty($customers[$cid]['contact_2_name']) && !empty($entry['contact_2_name'])) {
            $customers[$cid]['contact_2_name'] = $entry['contact_2_name'];
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

function getFirstTicketMessageBody(SplynxApiClient $api, int $ticketId): string
{
    $messages = $api->get("admin/support/ticket-messages", [
        'main_attributes' => ['ticket_id' => $ticketId, 'message_type' => 'message'],
    ]);
    if (!is_array($messages) || empty($messages)) return '';
    $first = reset($messages);
    return strip_tags($first['message'] ?? '');
}

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

function getHonorificTitlePattern(): string
{
    return '(?:mr|mrs|ms|miss|dr|doctor|prof|professor|rev|reverend|reverand|pastor|fr|father|det|detective|sgt|sergeant|sargent|sargeant|constable|cst|inspector|insp|capt|captain|officer|sir|dame)';
}

function extractNames(string $transcription, string $subject): array
{
    $names = [];
    $blacklist = getCommonWordBlacklist();
    $titles = getHonorificTitlePattern();
    $stopWords = ['from', 'at', 'in', 'on', 'near', 'with', 'about', 'and', 'making', 'calling', 'leaving', 'testing', 'checking', 'trying', 'here', 'speaking'];

    // 1. From subject (reject candidates containing digits or blacklist words)
    if (preg_match("/from\\s+(?:caller\\s+)?([A-Za-z0-9\\s.,'-]+?)(?:\\s*\\(|\\s*$)/i", $subject, $m)) {
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

    // 2. Direct title match anywhere in speech: 'Mr. Greg', 'Detective Miller'
    if (preg_match_all('/\b(?:' . $titles . ')\.?\s+([A-Z][a-z]+)/', $transcription, $tm)) {
        foreach ($tm[1] as $tn) {
            $clean = trim($tn, " .,!?:;");
            $cleanLower = strtolower($clean);
            if (strlen($clean) > 2 && !in_array($cleanLower, $blacklist) && !in_array($cleanLower, $stopWords)) {
                $names[] = ['name' => $clean, 'is_full' => false];
            }
        }
    }

    // 3. From speech introductions: 'this is Petra', 'it is John Smith'
    $pattern = "/(?:it'?s|this is|my name is|i'?m)\\s+(?:{$titles}\\.?\\s+)?([A-Z][a-z]+(?:\\s+[A-Z][a-z]+)*)/i";
    if (preg_match_all($pattern, $transcription, $matches)) {
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

    $unique = [];
    foreach ($names as $item) {
        $k = strtolower($item['name']);
        if (!isset($unique[$k])) {
            $unique[$k] = $item;
        }
    }
    return array_values($unique);
}

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
