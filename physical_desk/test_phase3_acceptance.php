<?php
/**
 * PHYSICAL DESK / PD57 - PHASE 3 FINAL ACCEPTANCE TEST SUITE
 *
 * Independently tests all Phase 3 requirements & edge cases:
 *   T1.  Health & Readiness Probe (/health/ready)
 *   T2.  Dynamic Canonical Category Lookup (No hardcoded ID 70)
 *   E1.  AI Confirmed Unchanged by Employee
 *   E2.  Employee Changes Department
 *   E3.  Employee Changes Subcategory
 *   E4.  Explicit Manual Category Before AI
 *   E5.  Abstention + Manual Selection
 *   E6.  Abstention + I'm Not Sure -> Manual Triage
 *   E7.  Classifier Unavailable - Manual Submit Still Works
 *   T9.  Agent Override Authority (AI < Employee < Agent)
 *   T10. Malformed Response Safety (5 sub-cases)
 *   T11. Timeout Resilience
 *   T12. Execution Model Verification (Bounded Synchronous)
 *   T13. Offline Model Operation
 *   T14. Metadata Schema & Audit Completeness
 *   T15. Restart Persistence
 */

require_once(__DIR__ . "/vendor/autoload.php");
include_once(GLPI_ROOT . "/config/config_db.php");
include_once(GLPI_ROOT . "/inc/includes.php");
include_once(GLPI_ROOT . "/plugins/pd57classifier/setup.php");
include_once(GLPI_ROOT . "/plugins/pd57classifier/hook.php");

// Boot Kernel and DB
$kernel = new \Glpi\Kernel\Kernel();
global $DB, $GLPI_CACHE;
if (!($DB instanceof DBmysql)) {
    $DB = new DB();
}
if ($GLPI_CACHE === null) {
    $GLPI_CACHE = (new \Glpi\Cache\CacheManager())->getCoreCacheInstance();
}

// Session init for CLI
$user = new User();
if (!$user->getFromDBbyName('glpi')) throw new RuntimeException('Test admin unavailable');
$auth = new Auth();
$auth->auth_succeded = true;
$auth->user = $user;
Session::init($auth);
Session::changeProfile(4);

plugin_init_pd57classifier();
plugin_pd57classifier_ensure_table();

echo "======================================================================\n";
echo "   PHYSICAL DESK / PD57 - PHASE 3 FINAL ACCEPTANCE TEST SUITE        \n";
echo "======================================================================\n\n";

$passCount = 0;
$failCount = 0;

function runTest(string $testCode, string $description, callable $fn) {
    global $passCount, $failCount;
    echo "[{$testCode}] {$description}\n";
    try {
        $result = $fn();
        if ($result['passed']) {
            $passCount++;
            echo "  ✅ PASS";
            if (!empty($result['details'])) echo " — {$result['details']}";
            echo "\n";
        } else {
            $failCount++;
            echo "  ❌ FAIL — {$result['reason']}\n";
        }
    } catch (\Throwable $e) {
        $failCount++;
        echo "  ❌ EXCEPTION: " . $e->getMessage() . "\n";
    }
    echo "\n";
}

// Read only the persisted assignment. Never simulate rules or infer from a category map.
function resolveTicketGroup(int $ticketId): string {
    global $DB;
    $relation = new Group_Ticket();
    foreach ($relation->find(['tickets_id' => $ticketId, 'type' => CommonITILActor::ASSIGN]) as $row) {
        $group = new Group();
        if ($group->getFromDB((int)$row['groups_id'])) return $group->fields['name'];
    }
    return 'Unassigned';
}

function categoryId(string $path): int {
    return plugin_pd57classifier_category_id_for_path($path);
}

// Suggestion record helper
function getTopSuggestion(int $ticketId): ?array {
    global $DB;
    $rows = array_values(iterator_to_array($DB->request([
        'FROM'  => 'glpi_plugin_pd57classifier_suggestions',
        'WHERE' => ['tickets_id' => $ticketId],
        'ORDER' => ['rank ASC'],
    ])));
    return $rows[0] ?? null;
}

// ============================================================================
// TESTS
// ============================================================================

// --- T1: Health & Readiness Probe ---
runTest('T1', 'Classifier /health/ready probe returns ready status', function() {
    $url = plugin_pd57classifier_get_service_url() . '/health/ready';
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode($resp ?: '{}', true);
    return [
        'passed'  => ($code === 200 && ($data['status'] ?? '') === 'ready'),
        'details' => sprintf("Model: %s | Load: %ss | Readiness: %ss | Offline: %s",
            $data['model_name'] ?? '?', $data['model_load_time_s'] ?? '?',
            $data['readiness_time_s'] ?? '?', var_export($data['offline_mode'] ?? null, true)),
        'reason'  => "HTTP {$code}: {$resp}"
    ];
});

// --- T2: Canonical Category Lookup ---
runTest('T2', 'Dynamic canonical lookup resolves "Other > Manual Triage" without hardcoded ID', function() {
    $id = plugin_pd57classifier_get_manual_triage_category_id();
    // Verify via DB that this ID actually has the correct completename
    global $DB;
    $row = $DB->request(['FROM' => 'glpi_itilcategories', 'WHERE' => ['id' => $id]])->current();
    $name = $row['completename'] ?? '';
    return [
        'passed'  => ($id > 0 && $name === 'Other > Manual Triage'),
        'details' => "Resolved ID: {$id}, Completename: {$name}",
        'reason'  => "Failed to resolve canonical Manual Triage path"
    ];
});

// --- E1: AI Confirmed Unchanged ---
runTest('E1', 'Employee confirms AI suggestion (Wi-Fi → PD57_IT_NETWORK)', function() {
    $ticket = new Ticket();
    $tId = $ticket->add([
        'name'              => 'Studio Wi-Fi keeps disconnecting',
        'content'           => 'Wi-Fi AP in Studio A keeps dropping member check-in tablets.',
        'entities_id'       => 0,
        'itilcategories_id' => categoryId('IT > Network > Wi-Fi'), // Employee confirms Wi-Fi
    ]);
    if ($tId <= 0) return ['passed' => false, 'reason' => 'Ticket creation failed'];
    $group = resolveTicketGroup($tId);
    $rec = getTopSuggestion($tId);
    $src = $rec['classification_source'] ?? '';
    return [
        'passed'  => ($group === 'PD57_IT_NETWORK' && $src === 'employee_confirmed'),
        'details' => "Ticket #{$tId} → {$group}, Source: {$src}",
        'reason'  => "Expected PD57_IT_NETWORK and employee_confirmed, got group {$group}, source {$src}"
    ];
});

// --- E2: Employee Changes Department ---
runTest('E2', 'Employee changes department (AI suggested IT, employee chooses HR)', function() {
    $ticket = new Ticket();
    $tId = $ticket->add([
        'name'              => 'Need help with laptop login but selecting HR',
        'content'           => 'My clock-in was recorded incorrectly on Thursday.',
        'entities_id'       => 0,
        'itilcategories_id' => categoryId('HR > Leave & Attendance > Attendance Correction'), // HR Attendance Correction
    ]);
    if ($tId <= 0) return ['passed' => false, 'reason' => 'Ticket creation failed'];
    $group = resolveTicketGroup($tId);
    $rec = getTopSuggestion($tId);
    $src = $rec['classification_source'] ?? '';
    return [
        'passed'  => ($group === 'PD57_HR_L1' && $src === 'employee_override'),
        'details' => "Ticket #{$tId} → {$group}, Source: {$src}",
        'reason'  => "Expected PD57_HR_L1 and employee_override, got group {$group}, source {$src}"
    ];
});

// --- E3: Employee Changes Subcategory ---
runTest('E3', 'Employee changes subcategory within same department', function() {
    $ticket = new Ticket();
    $tId = $ticket->add([
        'name'              => 'Network is slow but I need a password reset',
        'content'           => 'Forgotten credentials for portal access.',
        'entities_id'       => 0,
        'itilcategories_id' => categoryId('IT > Identity & Access > Password / Login'), // IT > Identity & Access > Password
    ]);
    if ($tId <= 0) return ['passed' => false, 'reason' => 'Ticket creation failed'];
    $tObj = new Ticket(); $tObj->getFromDB($tId);
    $catId = (int)$tObj->fields['itilcategories_id'];
    return [
        'passed'  => ($catId === categoryId('IT > Identity & Access > Password / Login')),
        'details' => "Ticket #{$tId} final category: {$catId} (Password/Login)",
        'reason'  => "Category was changed from employee selection to {$catId}"
    ];
});

// --- E4: Explicit Manual Category Before AI ---
runTest('E4', 'Explicit manual category is authoritative — AI does not overwrite', function() {
    $ticket = new Ticket();
    $tId = $ticket->add([
        'name'              => 'Equipment issue in studio',
        'content'           => 'Barre support wobbly in Studio B.',
        'entities_id'       => 0,
        'itilcategories_id' => categoryId('Operations > Studio Equipment > Barre Equipment'), // Operations > Studio Equipment > Barre Equipment
    ]);
    if ($tId <= 0) return ['passed' => false, 'reason' => 'Ticket creation failed'];
    $tObj = new Ticket(); $tObj->getFromDB($tId);
    $finalCat = (int)$tObj->fields['itilcategories_id'];
    $rec = getTopSuggestion($tId);
    $src = $rec['classification_source'] ?? '';
    return [
        'passed'  => ($finalCat === categoryId('Operations > Studio Equipment > Barre Equipment') && ($src === 'employee_confirmed' || $src === 'employee_override' || $src === 'manual_selection')),
        'details' => "Ticket #{$tId} category: {$finalCat}, Source: {$src}",
        'reason'  => "Category {$finalCat} or source '{$src}' incorrect"
    ];
});

// --- E5: Abstention + Manual Selection ---
runTest('E5', 'Abstention fallback with manual employee selection', function() {
    $ticket = new Ticket();
    $tId = $ticket->add([
        'name'              => 'Unclear request text 12345',
        'content'           => 'XYZ random nonsense',
        'entities_id'       => 0,
        'itilcategories_id' => categoryId('Payroll > Salary'), // Payroll > Salary
    ]);
    if ($tId <= 0) return ['passed' => false, 'reason' => 'Ticket creation failed'];
    $group = resolveTicketGroup($tId);
    return [
        'passed'  => ($group === 'PD57_PAYROLL_L1'),
        'details' => "Ticket #{$tId} → {$group}",
        'reason'  => "Expected PD57_PAYROLL_L1, got {$group}"
    ];
});

// --- E6: Abstention + "I'm Not Sure" → Manual Triage ---
runTest('E6', '"I\'m Not Sure" resolves to canonical Manual Triage → PD57_TRIAGE', function() {
    $manualTriageId = plugin_pd57classifier_get_manual_triage_category_id();
    $ticket = new Ticket();
    $tId = $ticket->add([
        'name'              => 'Random request text needing triage',
        'content'           => 'Not sure which department handles this inquiry.',
        'entities_id'       => 0,
        'itilcategories_id' => 0,
        '_is_not_sure'      => 1,
    ]);
    if ($tId <= 0) return ['passed' => false, 'reason' => 'Ticket creation failed'];
    $tObj = new Ticket(); $tObj->getFromDB($tId);
    $cat = (int)$tObj->fields['itilcategories_id'];
    $group = resolveTicketGroup($tId);
    return [
        'passed'  => ($cat === $manualTriageId && $group === 'PD57_TRIAGE'),
        'details' => "Ticket #{$tId} category: {$cat}, Group: {$group}",
        'reason'  => "Category {$cat} or Group {$group} did not match"
    ];
});

// --- E7: Classifier Unavailable ---
runTest('E7', 'Classifier unavailable — employee can still classify and submit', function() {
    // Simulate classifier unavailable by calling with a bogus URL
    $origUrl = getenv('PD57_CLASSIFIER_URL');
    putenv('PD57_CLASSIFIER_URL=http://127.0.0.1:19999'); // unreachable port

    $ticket = new Ticket();
    $tId = $ticket->add([
        'name'              => 'Ticket while classifier is down',
        'content'           => 'Wi-Fi issue test with classifier offline.',
        'entities_id'       => 0,
        'itilcategories_id' => categoryId('IT > Network > Wi-Fi'), // Employee manually selects Wi-Fi
    ]);

    putenv('PD57_CLASSIFIER_URL=' . ($origUrl ?: 'http://pd57-classifier:5000'));

    if ($tId <= 0) return ['passed' => false, 'reason' => 'Ticket creation failed'];

    $tObj = new Ticket(); $tObj->getFromDB($tId);
    $finalCat = (int)$tObj->fields['itilcategories_id'];
    $rec = getTopSuggestion($tId);
    $failureReason = $rec['failure_reason'] ?? '';

    return [
        'passed'  => ($tId > 0 && $finalCat === categoryId('IT > Network > Wi-Fi') && !empty($failureReason)),
        'details' => "Ticket #{$tId} created (cat: {$finalCat}), Failure: {$failureReason}",
        'reason'  => "Ticket failed or no failure_reason recorded"
    ];
});

// --- T9: Authorized agent action through the same service used by HTTP ---
runTest('T9', 'Authorized agent override updates ticket and suggestion audit', function() {
    $ticket = new Ticket();
    $id = $ticket->add([
        'name' => 'Agent classification correction',
        'content' => 'Studio account access issue requiring agent correction.',
        'entities_id' => 0,
        'itilcategories_id' => categoryId('IT > Network > Wi-Fi'),
    ]);
    if ($id <= 0) return ['passed' => false, 'reason' => 'Ticket creation failed'];
    $suggestion = getTopSuggestion($id);
    if (!$suggestion) return ['passed' => false, 'reason' => 'No persisted suggestion'];
    $target = categoryId('IT > Cybersecurity > Security Incident');
    $result = plugin_pd57classifier_apply_suggestion('override', (int)$suggestion['id'], $id, $target);
    $updated = new Ticket(); $updated->getFromDB($id);
    $audit = getTopSuggestion($id);
    return [
        'passed' => !empty($result['success']) && (int)$updated->fields['itilcategories_id'] === $target
            && (int)$audit['human_override'] === 1 && (int)$audit['override_category_id'] === $target,
        'details' => "Ticket #$id, action HTTP " . $result['code'],
        'reason' => 'Agent action, ticket or persisted audit disagreed',
    ];
});

// --- T10: Malformed Response Safety (5 sub-cases via call_service validation) ---
runTest('T10a', 'Malformed JSON produces canonical fallback', function() {
    $result = plugin_pd57classifier_parse_response('{invalid');
    return ['passed' => !empty($result['fallback_used'])
        && $result['suggestions'][0]['category_id'] === categoryId('Other > Manual Triage')
        && $result['failure_reason'] === 'malformed_json',
        'reason' => 'Malformed response did not produce Manual Triage'];
});

runTest('T10b', 'Nonexistent suggested category is rejected', function() {
    $result = plugin_pd57classifier_parse_response(json_encode(['suggestions' => [[
        'path' => 'IT > Invented Category', 'confidence' => 0.99, 'category_id' => 999999,
    ]]]));
    return ['passed' => !empty($result['fallback_used'])
        && $result['suggestions'][0]['category_id'] === categoryId('Other > Manual Triage'),
        'reason' => 'Unconfigured category was accepted'];
});

runTest('T10c', 'Classifier-down request → safe fallback with failure_reason', function() {
    $origUrl = getenv('PD57_CLASSIFIER_URL');
    putenv('PD57_CLASSIFIER_URL=http://127.0.0.1:19999');
    $result = plugin_pd57classifier_call_service("Test request while classifier is down");
    putenv('PD57_CLASSIFIER_URL=' . ($origUrl ?: 'http://pd57-classifier:5000'));

    $hasFailure = !empty($result['failure_reason']);
    $hasFallback = !empty($result['fallback_used']);
    $manualTriageId = plugin_pd57classifier_get_manual_triage_category_id();
    $topCatId = (int)($result['suggestions'][0]['category_id'] ?? 0);

    return [
        'passed'  => ($hasFailure && $hasFallback && $topCatId === $manualTriageId),
        'details' => "Failure: {$result['failure_reason']}, Fallback Cat: {$topCatId}",
        'reason'  => "Expected failure_reason + fallback to Manual Triage ({$manualTriageId})"
    ];
});

runTest('T10d', 'Empty HTTP body produces canonical fallback', function() {
    $result = plugin_pd57classifier_parse_response('');
    return ['passed' => !empty($result['fallback_used'])
        && $result['suggestions'][0]['category_id'] === categoryId('Other > Manual Triage'),
        'reason' => 'Empty body did not produce Manual Triage'];
});

runTest('T10e', 'Ticket creation with classifier failure → no PHP fatal, ticket persists', function() {
    $origUrl = getenv('PD57_CLASSIFIER_URL');
    putenv('PD57_CLASSIFIER_URL=http://127.0.0.1:19999');
    $ticket = new Ticket();
    $tId = $ticket->add([
        'name'              => 'Ticket created during classifier failure T10e',
        'content'           => 'Verifying no PHP fatal error occurs.',
        'entities_id'       => 0,
    ]);
    putenv('PD57_CLASSIFIER_URL=' . ($origUrl ?: 'http://pd57-classifier:5000'));

    if ($tId <= 0) return ['passed' => false, 'reason' => 'Ticket creation failed'];
    $saved = new Ticket(); $saved->getFromDB($tId);
    $record = getTopSuggestion($tId);
    return [
        'passed' => (int)$saved->fields['itilcategories_id'] === categoryId('Other > Manual Triage')
            && resolveTicketGroup($tId) === 'PD57_TRIAGE' && !empty($record['failure_reason']),
        'details' => "Ticket #$tId persisted with Manual Triage and PD57_TRIAGE",
        'reason' => 'Fallback category, persisted route or failure metadata missing',
    ];
});

// --- T11: Timeout Resilience ---
runTest('T11', 'Timeout resilience — bounded 3s timeout prevents indefinite wait', function() {
    $origUrl = getenv('PD57_CLASSIFIER_URL');
    putenv('PD57_CLASSIFIER_URL=http://192.0.2.1:5000'); // RFC 5737 TEST-NET — will timeout
    $start = microtime(true);
    $result = plugin_pd57classifier_call_service("Timeout test request");
    $elapsed = microtime(true) - $start;
    putenv('PD57_CLASSIFIER_URL=' . ($origUrl ?: 'http://pd57-classifier:5000'));

    $withinBound = ($elapsed < 6.0); // Must complete within bounded timeout + margin
    return [
        'passed'  => ($withinBound && !empty($result['failure_reason'])),
        'details' => sprintf("Elapsed: %.2fs (bounded), Failure: %s", $elapsed, $result['failure_reason'] ?? 'none'),
        'reason'  => sprintf("Took %.2fs (> 6s bound) or no failure recorded", $elapsed)
    ];
});

// --- T12: Execution Model Verification ---
runTest('T12', 'Execution model is bounded synchronous (PRE_ITEM_ADD, not async)', function() {
    // Verify by timing: add a ticket and measure that it takes > 0 but < timeout
    $start = microtime(true);
    $ticket = new Ticket();
    $tId = $ticket->add([
        'name'              => 'Execution model timing test',
        'content'           => 'Wi-Fi outage in Studio B affecting check-in tablets.',
        'entities_id'       => 0,
        'itilcategories_id' => categoryId('IT > Network > Wi-Fi'),
    ]);
    $elapsed = microtime(true) - $start;

    // If synchronous, elapsed should include inference time (~1-2s)
    $rec = getTopSuggestion($tId);
    $inferenceMs = (float)($rec['inference_time_ms'] ?? 0);

    return [
        'passed'  => ($tId > 0 && $elapsed > 0.3), // Must be > 0.3s (inference visible)
        'details' => sprintf("Ticket #{$tId} add took %.2fs, Inference: %.0fms — confirms bounded synchronous", $elapsed, $inferenceMs),
        'reason'  => sprintf("Elapsed %.2fs too fast — may be async or hook not firing", $elapsed)
    ];
});

// --- T13: Offline Model Verification ---
runTest('T13', 'Offline model operation (HF_HUB_OFFLINE=1, TRANSFORMERS_OFFLINE=1)', function() {
    $url = plugin_pd57classifier_get_service_url() . '/health/ready';
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($resp ?: '{}', true);
    $offlineMode = $data['offline_mode'] ?? false;
    $ready = ($data['status'] ?? '') === 'ready';
    return [
        'passed'  => ($ready && $offlineMode === true),
        'details' => "Model: {$data['model_name']}, Load: {$data['model_load_time_s']}s, Offline: " . var_export($offlineMode, true),
        'reason'  => "Offline mode not enabled or model not ready"
    ];
});

// --- T14: Metadata Schema & Audit Completeness ---
runTest('T14', 'Metadata schema has all required audit columns', function() {
    global $DB;
    $requiredCols = [
        'classification_source', 'ai_department', 'ai_subdepartment',
        'ai_leaf_category', 'ai_score', 'ai_margin',
        'final_confirmed_category_id', 'employee_changed_suggestion',
        'failure_reason', 'human_confirmed', 'human_override',
        'override_category_id', 'override_user_id', 'override_date',
        'model_name', 'inference_time_ms',
    ];
    $missing = [];
    foreach ($requiredCols as $col) {
        if (!$DB->fieldExists('glpi_plugin_pd57classifier_suggestions', $col)) {
            $missing[] = $col;
        }
    }
    return [
        'passed'  => empty($missing),
        'details' => "All " . count($requiredCols) . " audit columns present",
        'reason'  => "Missing columns: " . implode(', ', $missing)
    ];
});

// --- T15: Restart Persistence ---
runTest('T15', 'Ticket and AI metadata persist across fresh database reads (restart tested separately)', function() {
    global $DB;
    // Pick the last ticket we created with a suggestion record
    $lastSug = $DB->request([
        'FROM'  => 'glpi_plugin_pd57classifier_suggestions',
        'ORDER' => ['id DESC'],
        'LIMIT' => 1,
    ])->current();

    if (!$lastSug) return ['passed' => false, 'reason' => 'No suggestion records in DB'];

    $ticketId = (int)$lastSug['tickets_id'];
    $ticket = new Ticket();
    $exists = $ticket->getFromDB($ticketId);
    $catId = (int)$ticket->fields['itilcategories_id'];

    return [
        'passed'  => ($exists && $catId > 0 && !empty($lastSug['classification_source'])),
        'details' => "Ticket #{$ticketId} persisted (cat: {$catId}), AI source: {$lastSug['classification_source']}, Model: {$lastSug['model_name']}",
        'reason'  => "Ticket or metadata did not persist"
    ];
});

// ============================================================================
// SUMMARY
// ============================================================================
echo "======================================================================\n";
echo "FINAL RESULT: Passed {$passCount}, Failed {$failCount}\n";
echo "======================================================================\n";

exit($failCount === 0 ? 0 : 1);
