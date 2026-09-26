<?php
require_once dirname(__DIR__) . '/src/Glpi/Application/ResourcesChecker.php';
(new \Glpi\Application\ResourcesChecker(dirname(__DIR__)))->checkResources();
require_once dirname(__DIR__) . '/vendor/autoload.php';

$kernel = new \Glpi\Kernel\Kernel();
$kernel->boot();

// Init admin session
$user = new User();
$user->getFromDBbyName('glpi');
$auth = new Auth();
$auth->auth_succeded = true;
$auth->user = $user;
Session::init($auth);
Session::changeProfile(4); // Super-Admin profile

echo "==========================================================" . PHP_EOL;
echo "   PHYSICAL DESK / PD57 - END-TO-END SCENARIO RUNNER      " . PHP_EOL;
echo "==========================================================" . PHP_EOL;

global $DB;

function getCatId($name) {
    $cat = new ITILCategory();
    if ($cat->getFromDBByCrit(['name' => $name])) {
        return (int)$cat->fields['id'];
    }
    return 0;
}

function getLocId($name) {
    $loc = new Location();
    if ($loc->getFromDBByCrit(['name' => $name])) {
        return (int)$loc->fields['id'];
    }
    return 0;
}

function getApplianceId($name) {
    $app = new Appliance();
    if ($app->getFromDBByCrit(['name' => $name])) {
        return (int)$app->fields['id'];
    }
    return 0;
}

function getComputerId($name) {
    $comp = new Computer();
    if ($comp->getFromDBByCrit(['name' => $name])) {
        return (int)$comp->fields['id'];
    }
    return 0;
}

function getGroupId($name) {
    $g = new Group();
    if ($g->getFromDBByCrit(['name' => $name])) {
        return (int)$g->fields['id'];
    }
    return 0;
}

function getSlaId($name) {
    $sla = new SLA();
    if ($sla->getFromDBByCrit(['name' => $name])) {
        return (int)$sla->fields['id'];
    }
    return 0;
}

function getGroupName($group_id) {
    $g = new Group();
    if ($g->getFromDB($group_id)) {
        return $g->fields['name'];
    }
    return "Unknown ($group_id)";
}

function createScenarioTicket($title, $content, $category_name, $location_name, $asset_type = null, $asset_id = 0, $urgency = 3, $target_group_name = null) {
    $cat_id = getCatId($category_name);
    $loc_id = getLocId($location_name);
    
    // Input deliberately omits the expected assignment; native rules must provide it.
    // Prepare input for Ticket
    $input = [
        'name' => $title,
        'content' => $content,
        'itilcategories_id' => $cat_id,
        'locations_id' => $loc_id,
        'urgency' => $urgency,
        'impact' => $urgency,
        '_auto_update' => true
    ];
    
    $ticket = new Ticket();
    $tid = $ticket->add($input);
    if (!$tid) {
        echo "[FAIL] Ticket creation failed for '$title'" . PHP_EOL;
        return null;
    }

    // Link asset if provided
    if ($asset_type && $asset_id > 0) {
        $it = new Item_Ticket();
        $it->add([
            'tickets_id' => $tid,
            'itemtype' => $asset_type,
            'items_id' => $asset_id
        ]);
    }

    $ticket->getFromDB($tid);
    
    // Check assigned group
    $gt_check = new Group_Ticket();
    $assigned_groups = $gt_check->find(['tickets_id' => $tid, 'type' => CommonITILActor::ASSIGN]);
    $group_names = [];
    foreach ($assigned_groups as $ag) {
        $group_names[] = getGroupName($ag['groups_id']);
    }

    return [
        'id' => $tid,
        'name' => $ticket->fields['name'],
        'category' => $category_name,
        'location' => $location_name,
        'priority' => $ticket->fields['priority'],
        'assigned_groups' => implode(', ', $group_names),
        'sla_ttr' => $ticket->fields['slas_id_ttr'],
        'sla_tto' => $ticket->fields['slas_id_tto']
    ];
}

function assertScenario($label, $result, $expectedGroup, $expectedPriority = null) {
    if (!$result) {
        throw new RuntimeException("Scenario $label ticket was not created");
    }
    $groups = array_map('trim', explode(',', $result['assigned_groups']));
    if (!in_array($expectedGroup, $groups, true)) {
        throw new RuntimeException("Scenario $label missing persisted group $expectedGroup; found " . $result['assigned_groups']);
    }
    if ($expectedPriority !== null && (int)$result['priority'] !== $expectedPriority) {
        throw new RuntimeException("Scenario $label priority was " . $result['priority'] . ", expected $expectedPriority");
    }
    echo "[PASS] Scenario $label Ticket #" . $result['id'] . ": persisted assignment " . $result['assigned_groups'] . PHP_EOL;
}

// ---------------------------------------------------------
// EXECUTE SCENARIOS A - J
// ---------------------------------------------------------

echo PHP_EOL . "--- Scenario A: HR Request ---" . PHP_EOL;
$resA = createScenarioTicket(
    'Need to correct my attendance record',
    'Forgot to clock out on Thursday shift at Mumbai Studio A.',
    'Attendance Correction',
    'Mumbai',
    null, 0, 3,
    'PD57_HR_L1'
);
assertScenario('A', $resA, 'PD57_HR_L1');
if ((int)$resA['sla_tto'] !== getSlaId('PD57_SLA_NORMAL_TTO')
    || (int)$resA['sla_ttr'] !== getSlaId('PD57_SLA_NORMAL')) {
    throw new RuntimeException('Scenario A did not receive both Normal SLA objectives');
}
echo '[PASS] Scenario A persisted both TTO and TTR objectives' . PHP_EOL;

echo PHP_EOL . "--- Scenario B: IT Network ---" . PHP_EOL;
$resB = createScenarioTicket(
    'Studio Wi-Fi disconnects during member check-in',
    'Wi-Fi AP in Studio A keeps dropping front desk iPad connection.',
    'Wi-Fi',
    'Studio A',
    null, 0, 3,
    'PD57_IT_NETWORK'
);
assertScenario('B', $resB, 'PD57_IT_NETWORK');

echo PHP_EOL . "--- Scenario C: IT Cybersecurity ---" . PHP_EOL;
$resC = createScenarioTicket(
    'Received a suspicious login email',
    'Phishing email requesting password reset for studio staff account.',
    'Suspicious Email / Phishing',
    'Mumbai',
    null, 0, 3,
    'PD57_IT_SECURITY'
);
assertScenario('C', $resC, 'PD57_IT_SECURITY');

echo PHP_EOL . "--- Scenario D: Payroll ---" . PHP_EOL;
$resD = createScenarioTicket(
    'September reimbursement is missing',
    'Travel reimbursement for studio trainer workshop not included in pay slip.',
    'Reimbursement',
    'Mumbai',
    null, 0, 3,
    'PD57_PAYROLL_L1'
);
assertScenario('D', $resD, 'PD57_PAYROLL_L1');

echo PHP_EOL . "--- Scenario E: Barre / Studio Equipment ---" . PHP_EOL;
$barre_b4_id = getApplianceId('PD57-BARRE-MUM-B-004');
$resE = createScenarioTicket(
    'Barre support near mirror 3 in Studio B feels loose',
    'Wall mount bracket wobbling on Barre Unit #4 during class.',
    'Barre Equipment',
    'Studio B',
    'Appliance',
    $barre_b4_id,
    3,
    'PD57_OPS_L1'
);
assertScenario('E', $resE, 'PD57_OPS_L1');

echo PHP_EOL . "--- Scenario F: Studio Audio ---" . PHP_EOL;
$audio_a1_id = getApplianceId('PD57-AUDIO-MUM-A-001');
$resF = createScenarioTicket(
    'Music system in Studio A keeps disconnecting',
    'Bluetooth audio receiver dropping signal during high intensity class.',
    'Studio Audio / Visual',
    'Studio A',
    'Appliance',
    $audio_a1_id,
    3,
    'PD57_IT_AV'
);
assertScenario('F', $resF, 'PD57_IT_AV');

echo PHP_EOL . "--- Scenario G: Facility Maintenance ---" . PHP_EOL;
$ac_c1_id = getApplianceId('PD57-AC-MUM-C-001');
$resG = createScenarioTicket(
    'AC in Studio C is not cooling',
    'Temperature inside Studio C registered at 28C during afternoon session.',
    'HVAC / AC',
    'Studio C',
    'Appliance',
    $ac_c1_id,
    3,
    'PD57_OPS_L2'
);
assertScenario('G', $resG, 'PD57_OPS_L2');

echo PHP_EOL . "--- Scenario H: Safety-Sensitive Equipment ---" . PHP_EOL;
$barre_a1_id = getApplianceId('PD57-BARRE-MUM-A-001');
$resH = createScenarioTicket(
    'Loose barre bracket during class poses safety risk',
    'Barre mounting pin snapped off, urgent safety concern for evening Barre class.',
    'Safety / Operational Incident',
    'Studio A',
    'Appliance',
    $barre_a1_id,
    5, // Very high urgency in GLPI
    'PD57_OPS_L2'
);
assertScenario('H', $resH, 'PD57_OPS_L2', 5);
if ((int)$resH['sla_tto'] !== getSlaId('PD57_SLA_CRITICAL_TTO')
    || (int)$resH['sla_ttr'] !== getSlaId('PD57_SLA_CRITICAL')) {
    throw new RuntimeException('Scenario H did not receive both Very High SLA objectives');
}
echo '[PASS] Scenario H persisted both TTO and TTR objectives' . PHP_EOL;

echo PHP_EOL . "--- Scenario I: Unknown Manual Triage ---" . PHP_EOL;
$resI = createScenarioTicket(
    'Not sure which department handles this equipment question',
    'Inquiry regarding new props ordering for upcoming fitness workshop.',
    'Manual Triage',
    'Mumbai',
    null, 0, 3,
    'PD57_TRIAGE'
);
assertScenario('I', $resI, 'PD57_TRIAGE');

echo PHP_EOL . "--- Scenario J: SLA Escalation Flow ---" . PHP_EOL;
$resJ = createScenarioTicket(
    'Unresolved network issue requiring escalation',
    'Critical router outage in Studio A unresolved past initial response window.',
    'Wi-Fi',
    'Studio A',
    null, 0, 3,
    'PD57_IT_NETWORK'
);
assertScenario('J initial', $resJ, 'PD57_IT_NETWORK');
$tidJ = $resJ['id'];
$sla_high_id = getSlaId('PD57_SLA_HIGH');
$ticketJ = new Ticket();
$ticketJ->getFromDB($tidJ);
if (!$ticketJ->update(['id' => $tidJ, 'slas_id_ttr' => $sla_high_id])) {
    throw new RuntimeException('Scenario J could not attach the High TTR objective');
}
$ticketJ->getFromDB($tidJ);
if ((int)$ticketJ->fields['slas_id_ttr'] !== $sla_high_id) {
    throw new RuntimeException('Scenario J High TTR objective did not persist');
}

// Add SLA Level Action for Escalation
$levelRow = $DB->request([
    'SELECT' => ['id'], 'FROM' => 'glpi_slalevels',
    'WHERE' => ['name' => 'PD57_IT_ESCALATION_LEVEL', 'slas_id' => $sla_high_id],
    'ORDER' => 'id ASC', 'LIMIT' => 1,
])->current();
if ($levelRow) {
    $slaL_id = (int)$levelRow['id'];
} else {
    $slaLevel = new SlaLevel();
    $slaL_id = $slaLevel->add(['name' => 'PD57_IT_ESCALATION_LEVEL', 'slas_id' => $sla_high_id, 'execution_time' => 0]);
}
if (!$slaL_id) throw new RuntimeException('Scenario J escalation level unavailable');
$slaAct = new SlaLevelAction();
if (!$slaAct->getFromDBByCrit(['slalevels_id' => $slaL_id, 'field' => '_groups_id_assign'])) {
    $slaAct->add(['slalevels_id' => $slaL_id, 'action_type' => 'assign', 'field' => '_groups_id_assign', 'value' => getGroupId('PD57_IT_L2')]);
}

// Force SLA execution
$DB->insert('glpi_slalevels_tickets', ['tickets_id' => $tidJ, 'slalevels_id' => $slaL_id, 'date' => date('Y-m-d H:i:s', time() - 300)]);
$task = new CronTask();
$task->getFromDBByCrit(['name' => 'slaticket']);
SlaLevel_Ticket::cronSlaTicket($task);

// Verify escalation group on Ticket J
$gt_checkJ = new Group_Ticket();
$assignedJ = $gt_checkJ->find(['tickets_id' => $tidJ, 'type' => CommonITILActor::ASSIGN]);
$groupsJ = [];
foreach ($assignedJ as $ag) {
    $groupsJ[] = getGroupName($ag['groups_id']);
}
if (!in_array('PD57_IT_L2', $groupsJ, true)) {
    throw new RuntimeException('Scenario J escalation did not persist PD57_IT_L2; found ' . implode(', ', $groupsJ));
}
echo "[PASS] Scenario J Ticket ID $tidJ: persisted escalation PD57_IT_L2" . PHP_EOL;

echo PHP_EOL . "=== ALL END-TO-END SCENARIOS A - J PASSED ===" . PHP_EOL;
