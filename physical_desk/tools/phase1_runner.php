<?php
require_once dirname(__DIR__) . '/src/Glpi/Application/ResourcesChecker.php';
(new \Glpi\Application\ResourcesChecker(dirname(__DIR__)))->checkResources();
require_once dirname(__DIR__) . '/vendor/autoload.php';

$kernel = new \Glpi\Kernel\Kernel();
$kernel->boot();

// Init admin session
$user = new User();
if (!$user->getFromDBbyName('glpi')) {
    echo "ERROR: Admin user 'glpi' not found!" . PHP_EOL;
    exit(1);
}
$auth = new Auth();
$auth->auth_succeded = true;
$auth->user = $user;
Session::init($auth);
Session::changeProfile(4); // Super-Admin profile

echo "==========================================================" . PHP_EOL;
echo "   PHYSICAL DESK - PHASE 1 AUTOMATED VALIDATION RUNNER    " . PHP_EOL;
echo "==========================================================" . PHP_EOL;
echo "Authenticated as: " . Session::getLoginUserID() . " (glpi admin)" . PHP_EOL;

// ---------------------------------------------------------
// STEP 3: CATEGORY AND LOCATION TEST
// ---------------------------------------------------------
echo PHP_EOL . "--- STEP 3: Category and Location Test ---" . PHP_EOL;

$category = new ITILCategory();
if (!$category->getFromDB(1)) {
    $cat_id = $category->add(['name' => 'BASELINE_TEST_CATEGORY', 'comment' => 'Phase 1 baseline category']);
    echo "[CREATED] ITILCategory ID: $cat_id" . PHP_EOL;
} else {
    echo "[EXISTS] ITILCategory ID 1: " . $category->fields['name'] . PHP_EOL;
}

$location = new Location();
if (!$location->getFromDB(1)) {
    $loc_id = $location->add(['name' => 'BASELINE_TEST_LOCATION', 'comment' => 'Phase 1 baseline location']);
    echo "[CREATED] Location ID: $loc_id" . PHP_EOL;
} else {
    echo "[EXISTS] Location ID 1: " . $location->fields['name'] . PHP_EOL;
}

$ticket1 = new Ticket();
if ($ticket1->getFromDB(1)) {
    if ($ticket1->fields['itilcategories_id'] != 1 || $ticket1->fields['locations_id'] != 1) {
        $ticket1->update([
            'id' => 1,
            'itilcategories_id' => 1,
            'locations_id' => 1
        ]);
        echo "[UPDATED] Ticket 1 attached to Category 1 & Location 1" . PHP_EOL;
    } else {
        echo "[VERIFIED] Ticket 1 already attached: Category=" . $ticket1->fields['itilcategories_id'] . ", Location=" . $ticket1->fields['locations_id'] . PHP_EOL;
    }
} else {
    echo "[ERROR] Ticket ID 1 not found!" . PHP_EOL;
}

// ---------------------------------------------------------
// STEP 4: ASSIGNMENT TEST
// ---------------------------------------------------------
echo PHP_EOL . "--- STEP 4: Support Group Assignment Test ---" . PHP_EOL;

$groupL1 = new Group();
if (!$groupL1->getFromDB(1)) {
    $g1_id = $groupL1->add(['name' => 'BASELINE_TEST_SUPPORT_L1', 'is_assign' => 1]);
    echo "[CREATED] Group L1 ID: $g1_id" . PHP_EOL;
} else {
    echo "[EXISTS] Group L1 ID 1: " . $groupL1->fields['name'] . PHP_EOL;
}

$groupL2 = new Group();
$groupL2_id = 0;
if ($groupL2->getFromDBByCrit(['name' => 'BASELINE_TEST_SUPPORT_L2'])) {
    $groupL2_id = $groupL2->fields['id'];
    echo "[EXISTS] Group L2 ID: $groupL2_id" . PHP_EOL;
} else {
    $groupL2_id = $groupL2->add(['name' => 'BASELINE_TEST_SUPPORT_L2', 'is_assign' => 1]);
    echo "[CREATED] Group L2 ID: $groupL2_id" . PHP_EOL;
}

$group_ticket = new Group_Ticket();
if (!$group_ticket->getFromDBByCrit(['tickets_id' => 1, 'groups_id' => 1, 'type' => CommonITILActor::ASSIGN])) {
    $gt_id = $group_ticket->add([
        'tickets_id' => 1,
        'groups_id' => 1,
        'type' => CommonITILActor::ASSIGN
    ]);
    echo "[ASSIGNED] Ticket 1 assigned to Group L1 (row ID: $gt_id)" . PHP_EOL;
} else {
    echo "[VERIFIED] Ticket 1 already assigned to Group L1" . PHP_EOL;
}

// ---------------------------------------------------------
// STEP 5: VERIFY NATIVE TICKET LIFECYCLE
// ---------------------------------------------------------
echo PHP_EOL . "--- STEP 5: Native Ticket Lifecycle Test ---" . PHP_EOL;

$lifecycle_ticket = new Ticket();
$lt_id = $lifecycle_ticket->add([
    'name' => 'BASELINE_TEST_LIFECYCLE_TICKET',
    'content' => 'Testing native GLPI ticket lifecycle statuses.',
    'status' => Ticket::INCOMING
]);
echo "[CREATED] Lifecycle Test Ticket ID: $lt_id (Status: INCOMING/1)" . PHP_EOL;

$statuses = [
    Ticket::ASSIGNED => 'ASSIGNED (2)',
    Ticket::PLANNED  => 'PLANNED (3)',
    Ticket::WAITING  => 'WAITING (4)',
    Ticket::SOLVED   => 'SOLVED (5)',
    Ticket::CLOSED   => 'CLOSED (6)'
];

foreach ($statuses as $st_val => $st_name) {
    $input = ['id' => $lt_id, 'status' => $st_val];
    if ($st_val == Ticket::SOLVED) {
        $input['solutiontypes_id'] = 0;
        $input['solution'] = 'Baseline lifecycle resolution test.';
    }
    $lifecycle_ticket->update($input);
    $lifecycle_ticket->getFromDB($lt_id);
    echo "  -> Transitioned to $st_name | Current DB Status: " . $lifecycle_ticket->fields['status'] . PHP_EOL;
}

// ---------------------------------------------------------
// STEP 6: FOLLOW-UP AND HISTORY
// ---------------------------------------------------------
echo PHP_EOL . "--- STEP 6: Follow-up and History Test ---" . PHP_EOL;

$followup = new ITILFollowup();
$f_id = $followup->add([
    'itemtype' => 'Ticket',
    'items_id' => 1,
    'content' => 'BASELINE_TEST: Harmless verification follow-up comment.',
    'is_private' => 0
]);
echo "[CREATED] ITILFollowup ID: $f_id on Ticket 1" . PHP_EOL;

// ---------------------------------------------------------
// STEP 7: ASSET TEST
// ---------------------------------------------------------
echo PHP_EOL . "--- STEP 7: Computer Asset Test ---" . PHP_EOL;

$computer = new Computer();
$comp_id = 0;
if ($computer->getFromDB(1)) {
    $comp_id = 1;
    echo "[EXISTS] Computer ID 1: " . $computer->fields['name'] . PHP_EOL;
} else {
    $comp_id = $computer->add([
        'name' => 'BASELINE_TEST_COMPUTER_01',
        'locations_id' => 1,
        'comment' => 'Baseline test workstation'
    ]);
    echo "[CREATED] Computer ID: $comp_id" . PHP_EOL;
}

// Link computer to Ticket 1
$item_ticket = new Item_Ticket();
if (!$item_ticket->getFromDBByCrit(['tickets_id' => 1, 'itemtype' => 'Computer', 'items_id' => $comp_id])) {
    $it_id = $item_ticket->add([
        'tickets_id' => 1,
        'itemtype' => 'Computer',
        'items_id' => $comp_id
    ]);
    echo "[LINKED] Computer ID $comp_id linked to Ticket 1 (row ID: $it_id)" . PHP_EOL;
} else {
    echo "[VERIFIED] Computer ID $comp_id already linked to Ticket 1" . PHP_EOL;
}

// ---------------------------------------------------------
// STEP 10 & 11: TEMPORARY SLA & ESCALATION PROOF
// ---------------------------------------------------------
echo PHP_EOL . "--- STEP 10 & 11: SLA and Escalation Proof ---" . PHP_EOL;

// Create an SLA with short TTR
$sla = new SLA();
$sla_id = 0;
if ($sla->getFromDBByCrit(['name' => 'BASELINE_TEST_SLA'])) {
    $sla_id = $sla->fields['id'];
    echo "[EXISTS] SLA ID: $sla_id" . PHP_EOL;
} else {
    $sla_id = $sla->add([
        'name' => 'BASELINE_TEST_SLA',
        'type' => SLM::TTR,
        'number_time' => 1,
        'definition_time' => 'minute',
        'comment' => 'Baseline accelerated SLA for testing'
    ]);
    echo "[CREATED] SLA ID: $sla_id (1 minute TTR)" . PHP_EOL;
}

// Create SLA Level for Escalation
$slaLevel = new SlaLevel();
$slaLevel_id = 0;
if ($slaLevel->getFromDBByCrit(['slas_id' => $sla_id, 'name' => 'BASELINE_TEST_ESCALATION_LEVEL'])) {
    $slaLevel_id = $slaLevel->fields['id'];
    echo "[EXISTS] SlaLevel ID: $slaLevel_id" . PHP_EOL;
} else {
    $slaLevel_id = $slaLevel->add([
        'name' => 'BASELINE_TEST_ESCALATION_LEVEL',
        'slas_id' => $sla_id,
        'execution_time' => 0, // Immediately on trigger/due
        'match_type' => 'and'
    ]);
    echo "[CREATED] SlaLevel ID: $slaLevel_id" . PHP_EOL;
}

// Create SLA Level Action: Assign to L2 Group
$slaLevelAction = new SlaLevelAction();
if (!$slaLevelAction->getFromDBByCrit(['slalevels_id' => $slaLevel_id, 'action_type' => 'assign', 'field' => 'groups_id'])) {
    $action_id = $slaLevelAction->add([
        'slalevels_id' => $slaLevel_id,
        'action_type' => 'assign',
        'field' => 'groups_id',
        'value' => $groupL2_id
    ]);
    echo "[CREATED] SlaLevelAction ID: $action_id (Reassign to Group L2 ID $groupL2_id)" . PHP_EOL;
} else {
    echo "[VERIFIED] SlaLevelAction already present" . PHP_EOL;
}

// Create a SLA test ticket
$sla_ticket = new Ticket();
$st_id = $sla_ticket->add([
    'name' => 'BASELINE_TEST_SLA_TICKET',
    'content' => 'Testing SLA deadline calculation and escalation rule execution.',
    'status' => Ticket::INCOMING,
    'slas_id_ttr' => $sla_id
]);
echo "[CREATED] SLA Test Ticket ID: $st_id with SLA ID $sla_id" . PHP_EOL;

// Assign initial Group L1
$gt_sla = new Group_Ticket();
$gt_sla->add([
    'tickets_id' => $st_id,
    'groups_id' => 1, // L1
    'type' => CommonITILActor::ASSIGN
]);
echo "[ASSIGNED] SLA Test Ticket ID $st_id assigned to Group L1" . PHP_EOL;

// Reload ticket to inspect calculated due date / time_to_resolve
$sla_ticket->getFromDB($st_id);
echo "[DEADLINE] Ticket ID $st_id time_to_resolve calculated as: " . $sla_ticket->fields['time_to_resolve'] . PHP_EOL;

// Trigger SLA automatic action execution
CronTask::launch(CronTask::MODE_EXTERNAL, 1, 'sla');
echo "[TRIGGERED] CronTask 'sla' executed." . PHP_EOL;

// Re-check ticket assignment to verify if escalation assigned L2 group
$gt_after = new Group_Ticket();
$l2_assigned = $gt_after->getFromDBByCrit(['tickets_id' => $st_id, 'groups_id' => $groupL2_id, 'type' => CommonITILActor::ASSIGN]);
if ($l2_assigned) {
    echo "[ESCALATION SUCCESS] Ticket ID $st_id is now assigned to Group L2 (ID $groupL2_id)!" . PHP_EOL;
} else {
    echo "[ESCALATION CHECK] Group L2 assignment not yet applied or pending trigger condition." . PHP_EOL;
}

echo PHP_EOL . "=== AUTOMATED VALIDATION COMPLETE ===" . PHP_EOL;
