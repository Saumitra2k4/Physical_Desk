<?php
/** Focused PD57 suggestion integrity checks against an existing configured database. */
require_once __DIR__ . '/vendor/autoload.php';
include_once GLPI_ROOT . '/config/config_db.php';
include_once GLPI_ROOT . '/inc/includes.php';
include_once GLPI_ROOT . '/plugins/pd57classifier/setup.php';
include_once GLPI_ROOT . '/plugins/pd57classifier/hook.php';
$kernel = new \Glpi\Kernel\Kernel();
global $DB, $GLPI_CACHE;
if (!($DB instanceof DBmysql)) $DB = new DB();
if ($GLPI_CACHE === null) $GLPI_CACHE = (new \Glpi\Cache\CacheManager())->getCoreCacheInstance();
$user = new User();
if (!$user->getFromDBbyName('glpi')) throw new RuntimeException('Test admin unavailable');
$auth = new Auth();
$auth->auth_succeded = true;
$auth->user = $user;
Session::init($auth);
Session::changeProfile(4);
plugin_init_pd57classifier();

$originalUrl = getenv('PD57_CLASSIFIER_URL');
putenv('PD57_CLASSIFIER_URL=http://127.0.0.1:19999');
$passed = 0; $failed = 0;
function checkCase(string $name, bool $ok): void {
    global $passed, $failed;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $name . PHP_EOL;
    if (!$ok && isset($GLOBALS['result'])) echo '  Action: ' . json_encode($GLOBALS['result']) . PHP_EOL;
    $ok ? $passed++ : $failed++;
}
function fixture(): array {
    global $DB;
    $ticket = new Ticket();
    $id = $ticket->add([
        'name' => 'PD57 suggestion authorization fixture',
        'content' => 'Request to verify human classification controls.',
        'entities_id' => 0,
        '_users_id_requester' => Session::getLoginUserID(),
    ]);
    if (!$id) throw new RuntimeException('Fixture ticket creation failed');
    $suggestion = $DB->request([
        'FROM' => 'glpi_plugin_pd57classifier_suggestions',
        'WHERE' => ['tickets_id' => $id], 'ORDER' => ['rank ASC'], 'LIMIT' => 1,
    ])->current();
    if (!$suggestion) throw new RuntimeException('Fixture suggestion missing');
    return [(int)$id, (int)$suggestion['id'], (int)$suggestion['category_id']];
}
try {
    [$ticketA, $suggestionA, $suggestedA] = fixture();
    [$ticketB] = fixture();
    $result = plugin_pd57classifier_apply_suggestion('confirm', $suggestionA, $ticketB, $suggestedA);
    checkCase('Suggestion A on Ticket B rejected', $result['code'] !== 200);

    $originalId = $_SESSION['glpiID']; $_SESSION['glpiID'] = 0;
    $savedInterface = $_SESSION['glpiactiveprofile']['interface'];
    $_SESSION['glpiactiveprofile']['interface'] = 'helpdesk';
    $result = plugin_pd57classifier_apply_suggestion('confirm', $suggestionA, $ticketA, $suggestedA);
    $_SESSION['glpiactiveprofile']['interface'] = $savedInterface;
    $_SESSION['glpiID'] = $originalId;
    checkCase('Unauthenticated user rejected', $result['code'] === 403);

    $originalInterface = $_SESSION['glpiactiveprofile']['interface'] ?? null;
    $_SESSION['glpiID'] = 3;
    $_SESSION['glpiactiveprofile']['interface'] = 'helpdesk';
    $result = plugin_pd57classifier_apply_suggestion('confirm', $suggestionA, $ticketA, $suggestedA);
    $_SESSION['glpiID'] = $originalId;
    $_SESSION['glpiactiveprofile']['interface'] = $originalInterface;
    checkCase('Authenticated nonrequester without agent interface rejected', $result['code'] === 403);

    $result = plugin_pd57classifier_apply_suggestion('confirm', 1000000000, $ticketA, $suggestedA);
    checkCase('Nonexistent suggestion rejected', $result['code'] !== 200);

    $result = plugin_pd57classifier_apply_suggestion('override', $suggestionA, $ticketA, 1000000000);
    checkCase('Nonexistent override category rejected', $result['code'] === 400);

    $otherCategory = plugin_pd57classifier_category_id_for_path('IT > Network > Wi-Fi');
    $result = plugin_pd57classifier_apply_suggestion('confirm', $suggestionA, $ticketA, $otherCategory);
    $audit = $DB->request(['FROM' => 'glpi_plugin_pd57classifier_suggestions', 'WHERE' => ['id' => $suggestionA]])->current();
    checkCase('Manipulated confirm category leaves audit pending', $result['code'] === 400 && !(int)$audit['human_confirmed']);

    $result = plugin_pd57classifier_apply_suggestion('confirm', $suggestionA, $ticketA, $suggestedA,
        static fn (Ticket $item, array $input): bool => false);
    $audit = $DB->request(['FROM' => 'glpi_plugin_pd57classifier_suggestions', 'WHERE' => ['id' => $suggestionA]])->current();
    checkCase('Failed ticket update leaves suggestion pending', $result['code'] === 409 && !(int)$audit['human_confirmed']);

    $_SESSION['glpiactiveprofile']['interface'] = 'helpdesk';
    $result = plugin_pd57classifier_apply_suggestion('confirm', $suggestionA, $ticketA, $suggestedA);
    $_SESSION['glpiactiveprofile']['interface'] = $savedInterface;
    $ticket = new Ticket(); $ticket->getFromDB($ticketA);
    $audit = $DB->request(['FROM' => 'glpi_plugin_pd57classifier_suggestions', 'WHERE' => ['id' => $suggestionA]])->current();
    checkCase('Requester confirms stored suggestion', !empty($result['success'])
        && (int)$ticket->fields['itilcategories_id'] === $suggestedA && (int)$audit['human_confirmed'] === 1);

    [$ticketC, $suggestionC] = fixture();
    $_SESSION['glpiactiveprofile']['interface'] = 'helpdesk';
    $result = plugin_pd57classifier_apply_suggestion('override', $suggestionC, $ticketC, $otherCategory);
    $_SESSION['glpiactiveprofile']['interface'] = $savedInterface;
    $ticket = new Ticket(); $ticket->getFromDB($ticketC);
    checkCase('Requester changes suggested category', !empty($result['success'])
        && (int)$ticket->fields['itilcategories_id'] === $otherCategory);

    [$ticketD, $suggestionD] = fixture();
    $agentCategory = plugin_pd57classifier_category_id_for_path('IT > Cybersecurity > Security Incident');
    $result = plugin_pd57classifier_apply_suggestion('override', $suggestionD, $ticketD, $agentCategory);
    $audit = $DB->request(['FROM' => 'glpi_plugin_pd57classifier_suggestions', 'WHERE' => ['id' => $suggestionD]])->current();
    checkCase('Authorized agent override persists', !empty($result['success'])
        && (int)$audit['human_override'] === 1 && (int)$audit['override_category_id'] === $agentCategory);
} finally {
    putenv('PD57_CLASSIFIER_URL=' . ($originalUrl ?: 'http://pd57-classifier:5000'));
}
echo "Security cases: $passed passed, $failed failed" . PHP_EOL;
exit($failed ? 1 : 0);
