<?php
/**
 * 2talk Voicemail Customer Matcher Handler
 *
 * For new voicemail tickets where the customer is unassigned or assigned to
 * a generic account (e.g. "2Talk Limited"), attempts to match the real customer
 * using:
 *   1. Caller ID from the ticket subject
 *   2. Phone numbers mentioned in the transcription
 *   3. Names mentioned in the transcription
 *   4. Addresses mentioned in the transcription
 *
 * Uses the splynx-service shared memory data store for fast lookups.
 *
 * Events handled: new_ticket only (runs after transcribe handler)
 */

// Path to the splynx-service shared memory data store
define('MATCH_DATA_STORE', '/dev/shm/splynx_active_services.json');

return function (array &$event, SplynxApiClient $api) {
    if ($event['type'] !== 'new_ticket') return;

    global $matchGenericCustomerIds, $splynxAdminUrl;

    $ticket = $event['ticket'];
    $ticketId = $ticket['id'];
    $customerId = (int)($ticket['customer_id'] ?? 0);
    $subject = $ticket['subject'] ?? '';

    // Only process if ticket is unassigned or assigned to a generic/2talk account
    if ($customerId !== 0 && !in_array($customerId, $matchGenericCustomerIds ?? [])) {
        return; // Already assigned to a real customer
    }

    // Only process voicemail-style tickets (configurable subject pattern)
    global $matchSubjectPattern;
    $pattern = $matchSubjectPattern ?? '/voicemail|voice message/i';
    if (!preg_match($pattern, $subject)) {
        return;
    }

    logMsg("Match: Ticket #{$ticketId} — attempting customer match");

    // Load the data store
    if (!file_exists(MATCH_DATA_STORE)) {
        logMsg("Match: Data store not available at " . MATCH_DATA_STORE);
        return;
    }
    $dataStore = json_decode(file_get_contents(MATCH_DATA_STORE), true);
    if (empty($dataStore)) {
        logMsg("Match: Data store is empty");
        return;
    }

    // Extract caller ID from subject
    // Pattern: "from caller <name> (<number>)" or "from caller <number>"
    $callerPhone = extractCallerIdFromSubject($subject);
    $transcription = '';
    if (!empty($event['transcriptions'])) {
        $transcription = implode(' ', array_column($event['transcriptions'], 'text'));
    }

    // Attempt matching in priority order
    $match = null;
    $matchMethod = '';

    // 1. Try caller ID phone number from subject
    if ($callerPhone) {
        logMsg("Match: Ticket #{$ticketId} — trying caller ID: {$callerPhone}");
        $match = searchByPhone($dataStore, $callerPhone);
        if ($match) $matchMethod = "caller ID ({$callerPhone})";
    }

    // 2. Try phone numbers from transcription
    if (!$match && $transcription) {
        $phones = extractPhoneNumbers($transcription);
        foreach ($phones as $phone) {
            logMsg("Match: Ticket #{$ticketId} — trying transcription phone: {$phone}");
            $match = searchByPhone($dataStore, $phone);
            if ($match) {
                $matchMethod = "transcription phone ({$phone})";
                break;
            }
        }
    }

    // 3. Try name from transcription
    if (!$match && $transcription) {
        $names = extractNames($transcription, $subject);
        foreach ($names as $name) {
            logMsg("Match: Ticket #{$ticketId} — trying name: {$name}");
            $match = searchByName($dataStore, $name);
            if ($match) {
                $matchMethod = "name ({$name})";
                break;
            }
        }
    }

    // 4. Try address from transcription
    if (!$match && $transcription) {
        $addresses = extractAddresses($transcription);
        foreach ($addresses as $addr) {
            logMsg("Match: Ticket #{$ticketId} — trying address: {$addr}");
            $match = searchByAddress($dataStore, $addr);
            if ($match) {
                $matchMethod = "address ({$addr})";
                break;
            }
        }
    }

    if (!$match) {
        logMsg("Match: Ticket #{$ticketId} — no customer match found");
        return;
    }

    $matchedCustomerId = (int)($match['customer_id'] ?? 0);
    $matchedName = $match['customer_name'] ?? 'Unknown';

    if ($matchedCustomerId <= 0) {
        logMsg("Match: Ticket #{$ticketId} — matched entry has no customer_id");
        return;
    }

    // Assign the customer to the ticket
    logMsg("Match: Ticket #{$ticketId} — matched to '{$matchedName}' (ID: {$matchedCustomerId}) via {$matchMethod}");

    $result = $api->put("admin/support/tickets/{$ticketId}", [
        'customer_id' => $matchedCustomerId,
    ]);

    if ($result === null || $result === false) {
        logMsg("Match: Ticket #{$ticketId} — failed to assign customer via API");
    } else {
        logMsg("Match: Ticket #{$ticketId} — assigned to customer #{$matchedCustomerId} ({$matchedName})");

        // Enrich event so Slack can mention the match
        $event['customer_matched'] = [
            'customer_id'   => $matchedCustomerId,
            'customer_name' => $matchedName,
            'method'        => $matchMethod,
        ];
    }
};

// ============================================================
// Extraction Functions
// ============================================================

/**
 * Extract caller phone number from 2talk voicemail subject.
 * Patterns:
 *   "from caller Name (063726897)"
 *   "from caller 021958400"
 */
function extractCallerIdFromSubject(string $subject): ?string
{
    // Try: "from caller ... (number)"
    if (preg_match('/from caller.*?\((\d[\d\s\-]{5,})\)/i', $subject, $m)) {
        return preg_replace('/[\s\-]/', '', $m[1]);
    }
    // Try: "from caller <number>" (just digits at the end)
    if (preg_match('/from caller\s+(\d[\d\s\-]{5,})/i', $subject, $m)) {
        return preg_replace('/[\s\-]/', '', $m[1]);
    }
    return null;
}

/**
 * Extract phone numbers from transcription text.
 * Looks for NZ-style numbers (landline and mobile).
 */
function extractPhoneNumbers(string $text): array
{
    $phones = [];

    // Match various phone formats: 027 123 4567, 03-726-8975, 0279735164, etc.
    // Also match numbers spoken as "372 689 7" or "3726 8975"
    if (preg_match_all('/\b0\d[\d\s\-]{6,12}\b/', $text, $matches)) {
        foreach ($matches[0] as $m) {
            $clean = preg_replace('/[\s\-]/', '', $m);
            if (strlen($clean) >= 7 && strlen($clean) <= 11) {
                $phones[] = $clean;
            }
        }
    }

    // Also try to find numbers without leading 0 that might be landline (e.g. "3726897")
    if (preg_match_all('/\b(\d{7,10})\b/', preg_replace('/[\s\-]/', '', $text), $matches)) {
        foreach ($matches[1] as $m) {
            // Skip if already found or if it's too short/long
            if (strlen($m) >= 7 && strlen($m) <= 10 && !in_array($m, $phones)) {
                // Prepend area code 0 if it looks like a landline without it
                if (strlen($m) === 7 && !in_array('0' . $m, $phones)) {
                    // Could be missing area code — skip, too ambiguous
                } else {
                    $phones[] = $m;
                }
            }
        }
    }

    return array_unique($phones);
}

/**
 * Extract potential customer names from transcription and subject.
 * Looks for "it's <Name>" or "this is <Name>" patterns, and the caller name from subject.
 */
function extractNames(string $transcription, string $subject): array
{
    $names = [];

    // From subject: "from caller Name (number)" or "from caller Name"
    if (preg_match('/from caller\s+([A-Z][a-zA-Z\s]+?)(?:\s*\(|\s*$)/i', $subject, $m)) {
        $name = trim($m[1]);
        // Only if it's not just a phone number
        if (!preg_match('/^\d+$/', $name) && strlen($name) > 2) {
            $names[] = $name;
        }
    }

    // From transcription: "it's <Name>", "this is <Name>", "my name is <Name>"
    $patterns = [
        "/(?:it'?s|this is|my name is|i'?m)\s+([A-Z][a-z]+(?:\s+[A-Z][a-z]+){0,2})/",
    ];
    foreach ($patterns as $pat) {
        if (preg_match($pat, $transcription, $m)) {
            $name = trim($m[1]);
            if (strlen($name) > 2 && !in_array($name, $names)) {
                $names[] = $name;
            }
        }
    }

    return $names;
}

/**
 * Extract potential addresses from transcription.
 * Looks for street number + street name patterns.
 */
function extractAddresses(string $transcription): array
{
    $addresses = [];

    // Match: "123 Street Name" or "123 Street Name Road/Street/Avenue/Drive/Place/Way"
    if (preg_match_all('/\b(\d{1,5}\s+[A-Z][a-zA-Z]+(?:\s+[A-Z][a-zA-Z]+){0,3}(?:\s+(?:Road|Street|Avenue|Drive|Place|Way|Lane|Crescent|Terrace|Close|Court))?)\b/i', $transcription, $matches)) {
        foreach ($matches[1] as $m) {
            $addr = trim($m);
            if (strlen($addr) > 5) {
                $addresses[] = $addr;
            }
        }
    }

    return $addresses;
}

// ============================================================
// Search Functions (using splynx-service data store)
// ============================================================

/**
 * Search data store by phone number with NZ format normalization.
 */
function searchByPhone(array $dataStore, string $phone): ?array
{
    $variants = phoneSearchVariants($phone);

    foreach ($dataStore as $ip => $entry) {
        $custPhone = $entry['customer_phone'] ?? '';
        $custPhone2 = $entry['contact_2_phone'] ?? '';

        if (phoneMatchesAny($custPhone, $variants) || phoneMatchesAny($custPhone2, $variants)) {
            return $entry;
        }
    }

    return null;
}

/**
 * Search data store by customer name (fuzzy).
 */
function searchByName(array $dataStore, string $name): ?array
{
    $nameLower = strtolower($name);
    $bestMatch = null;

    foreach ($dataStore as $ip => $entry) {
        $custName = strtolower($entry['customer_name'] ?? '');
        $contact2 = strtolower($entry['contact_2_name'] ?? '');

        // Exact or contains match on customer name
        if ($custName === $nameLower || strpos($custName, $nameLower) !== false) {
            return $entry;
        }
        // Check 2nd contact
        if ($contact2 && strpos($contact2, $nameLower) !== false) {
            return $entry;
        }
        // Check if search name contains the customer's surname (last word)
        $parts = explode(' ', $custName);
        $surname = end($parts);
        if (strlen($surname) > 2 && strpos($nameLower, $surname) !== false) {
            $bestMatch = $entry; // Keep as fallback, don't return immediately
        }
    }

    return $bestMatch;
}

/**
 * Search data store by address (fuzzy).
 */
function searchByAddress(array $dataStore, string $address): ?array
{
    $addrLower = strtolower($address);

    foreach ($dataStore as $ip => $entry) {
        $serviceAddr = strtolower($entry['service_address'] ?? '');
        $custAddr = strtolower($entry['customer_address_fallback'] ?? '');

        if (strpos($serviceAddr, $addrLower) !== false || strpos($custAddr, $addrLower) !== false) {
            return $entry;
        }
        // Also try if the data store address contains our search
        if (strpos($addrLower, $serviceAddr) !== false && strlen($serviceAddr) > 5) {
            return $entry;
        }
    }

    return null;
}

// ============================================================
// Phone normalization (mirrors is-radio-up/search.php logic)
// ============================================================

function phoneSearchVariants(string $phone): array
{
    $stripped = preg_replace('/[\s\-\(\)]+/', '', $phone);
    $variants = [$stripped];

    // NZ local (0xx) → international variants
    if (preg_match('/^0(\d+)$/', $stripped, $m)) {
        $withoutZero = $m[1];
        $variants[] = '+64' . $withoutZero;
        $variants[] = '64' . $withoutZero;
    }
    // International (64xx or +64xx) → local variant
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
    $h = preg_replace('/[\s\-\(\)]+/', '', strtolower($haystack));
    foreach ($variants as $v) {
        if ($h === strtolower($v)) return true;
        if (strpos($h, strtolower($v)) !== false) return true;
    }
    return false;
}
