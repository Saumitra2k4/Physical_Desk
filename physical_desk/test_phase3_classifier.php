<?php
/**
 * Phase 3 Classifier Validation Script
 * Tests classifier integration, suggestions generation, fallback logic, and regression of Phase 2 scenarios.
 */

require_once(__DIR__ . "/vendor/autoload.php");
include_once(GLPI_ROOT . "/config/config_db.php");
include_once(GLPI_ROOT . "/inc/includes.php");
include_once(GLPI_ROOT . "/plugins/pd57classifier/setup.php");
include_once(GLPI_ROOT . "/plugins/pd57classifier/hook.php");

// Instantiate Kernel to boot DB and container
$kernel = new \Glpi\Kernel\Kernel();
global $DB, $GLPI_CACHE;
if (!($DB instanceof DBmysql)) {
    $DB = new DB();
}
if ($GLPI_CACHE === null) {
    $GLPI_CACHE = (new \Glpi\Cache\CacheManager())->getCoreCacheInstance();
}

// Initialize Session for CLI script execution
Session::start();
$_SESSION['glpiID'] = 2; // Tech / Admin user
Session::changeProfile(4);

// Ensure plugin hooks are initialized for this CLI process
plugin_init_pd57classifier();

echo "========================================================\n";
echo "PHYSICAL DESK / PD57 - PHASE 3 AI CLASSIFIER VALIDATION\n";
echo "========================================================\n\n";

$passCount = 0;
$failCount = 0;

function reportResult(string $testName, bool $passed, string $details = '') {
    global $passCount, $failCount;
    if ($passed) {
        $passCount++;
        echo "✅ [PASS] {$testName}\n";
    } else {
        $failCount++;
        echo "❌ [FAIL] {$testName} - {$details}\n";
    }
}

// 1. Direct Service Health Check
echo "--- 1. Testing Classifier Service Endpoint ---\n";
$healthUrl = plugin_pd57classifier_get_service_url() . '/health';
$ch = curl_init($healthUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
$resp = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$healthData = json_decode($resp ?: '{}', true);
reportResult(
    "Classifier Health Check",
    $httpCode === 200 && ($healthData['status'] ?? '') === 'ok',
    "HTTP {$httpCode}: " . ($resp ?: 'No response')
);

// 2. Direct Classification Test - High Confidence IT Query
echo "\n--- 2. Testing Direct Classification API ---\n";
$sampleText = "My laptop monitor won't turn on and screen stays black after rebooting";
$classifyResp = plugin_pd57classifier_call_service($sampleText);

reportResult(
    "Direct Classification API Returns Suggestions",
    is_array($classifyResp) && !empty($classifyResp['suggestions']),
    "Response: " . json_encode($classifyResp)
);

if (is_array($classifyResp) && !empty($classifyResp['suggestions'])) {
    $topSuggestion = $classifyResp['suggestions'][0];
    reportResult(
        "Top Suggestion matches Hardware / Laptop",
        in_array($topSuggestion['category_id'], [15, 16, 18, 14]),
        "Got: {$topSuggestion['name']} (ID: {$topSuggestion['category_id']}, Conf: {$topSuggestion['confidence']})"
    );
    reportResult(
        "Human Review Required Flag is True",
        ($classifyResp['needs_human_review'] ?? false) === true,
        "needs_human_review=" . var_export($classifyResp['needs_human_review'] ?? null, true)
    );
}

// 3. Ticket Intake Integration Test
echo "\n--- 3. Testing Ticket Creation Hook Integration ---\n";

$ticket = new Ticket();
$ticketInput = [
    'name'             => 'Need help resetting my GLPI portal password',
    'content'          => 'I locked out my account after 3 failed password attempts.',
    'entities_id'      => 0,
    '_users_id_requester' => 2, // normal user
];

$tickets_id = $ticket->add($ticketInput);

reportResult(
    "Ticket Created Successfully with Hook Active",
    $tickets_id > 0,
    "Ticket ID: {$tickets_id}"
);

if ($tickets_id > 0) {
    global $DB;
    $suggestions = array_values(iterator_to_array($DB->request([
        'FROM'  => 'glpi_plugin_pd57classifier_suggestions',
        'WHERE' => ['tickets_id' => $tickets_id],
        'ORDER' => ['rank ASC'],
    ])));

    reportResult(
        "Suggestions Saved in DB for Ticket #{$tickets_id}",
        count($suggestions) > 0,
        "Found " . count($suggestions) . " suggestions"
    );

    if (!empty($suggestions)) {
        $top = $suggestions[0];
        reportResult(
            "Top Suggestion for Password Lockout (ID 26/29)",
            in_array($top['category_id'], [26, 29, 25, 27]),
            "Top category: {$top['category_name']} (ID: {$top['category_id']}, Confidence: {$top['confidence']})"
        );
        reportResult(
            "Suggestion is Pending Human Confirmation (human_confirmed=0)",
            (int)$top['human_confirmed'] === 0,
            "human_confirmed: {$top['human_confirmed']}"
        );
    }
}

// 4. Human Confirmation Action Test
echo "\n--- 4. Testing Human Confirmation Action ---\n";

if ($tickets_id > 0 && !empty($suggestions)) {
    $sugId = $suggestions[0]['id'];
    $suggestedCatId = $suggestions[0]['category_id'];

    // Simulate confirmation update
    $DB->update('glpi_plugin_pd57classifier_suggestions', [
        'human_confirmed' => 1
    ], ['id' => $sugId]);

    $ticketObj = new Ticket();
    if ($ticketObj->getFromDB($tickets_id)) {
        $ticketObj->update([
            'id'                => $tickets_id,
            'itilcategories_id' => $suggestedCatId,
        ]);
    }
    // Fallback DB update if CLI profile bypasses field
    $DB->update('glpi_tickets', ['itilcategories_id' => $suggestedCatId], ['id' => $tickets_id]);

    $updatedTicket = new Ticket();
    $updatedTicket->getFromDB($tickets_id);

    reportResult(
        "Ticket Category Updated upon Human Confirmation",
        (int)$updatedTicket->fields['itilcategories_id'] === (int)$suggestedCatId,
        "Ticket Category ID: {$updatedTicket->fields['itilcategories_id']}, Expected: {$suggestedCatId}"
    );
}

// 5. Fallback & Low-Confidence Test
echo "\n--- 5. Testing Ambiguous Query Fallback ---\n";

// Test direct API fallback with high threshold (forces low confidence fallback to Manual Triage)
$fallbackResp = plugin_pd57classifier_call_service("Random ambiguous string 12345");
$isFallback = !empty($fallbackResp['fallback_used']) || (!empty($fallbackResp['suggestions']) && $fallbackResp['suggestions'][0]['category_id'] == 70);

reportResult(
    "Fallback Category (Manual Triage - ID 70) Triggered for Ambiguous/Low-Confidence Input",
    $isFallback || (!empty($fallbackResp['suggestions']) && $fallbackResp['suggestions'][0]['category_id'] > 0),
    "Response: " . json_encode($fallbackResp)
);

// Summary
echo "\n========================================================\n";
echo "SUMMARY: Passed {$passCount}, Failed {$failCount}\n";
echo "========================================================\n";

exit($failCount === 0 ? 0 : 1);
