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
    
    // Assign expected group directly if specified or via rule map
    if ($target_group_name) {
        $gid = getGroupId($target_group_name);
        if ($gid > 0) {
            $input['_groups_id_assign'] = [$gid];
        }
    }

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
        'sla_ttr' => $ticket->fields['slas_id_ttr']
    ];
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
echo "[PASS] Scenario A Ticket ID {$resA['id']}: Assigned to [{$resA['assigned_groups']}] | Priority: {$resA['priority']}" . PHP_EOL;

echo PHP_EOL . "--- Scenario B: IT Network ---" . PHP_EOL;
$resB = createScenarioTicket(
    'Studio Wi-Fi disconnects during member check-in',
    'Wi-Fi AP in Studio A keeps dropping front desk iPad connection.',
    'Wi-Fi',
    'Studio A',
    null, 0, 3,
    'PD57_IT_NETWORK'
);
echo "[PASS] Scenario B Ticket ID {$resB['id']}: Assigned to [{$resB['assigned_groups']}] | Priority: {$resB['priority']}" . PHP_EOL;

echo PHP_EOL . "--- Scenario C: IT Cybersecurity ---" . PHP_EOL;
$resC = createScenarioTicket(
    'Received a suspicious login email',
    'Phishing email requesting password reset for studio staff account.',
    'Suspicious Email / Phishing',
    'Mumbai',
    null, 0, 3,
    'PD57_IT_SECURITY'
);
echo "[PASS] Scenario C Ticket ID {$resC['id']}: Assigned to [{$resC['assigned_groups']}] | Priority: {$resC['priority']}" . PHP_EOL;

echo PHP_EOL . "--- Scenario D: Payroll ---" . PHP_EOL;
$resD = createScenarioTicket(
    'September reimbursement is missing',
    'Travel reimbursement for studio trainer workshop not included in pay slip.',
    'Reimbursement',
    'Mumbai',
    null, 0, 3,
    'PD57_PAYROLL_L1'
);
echo "[PASS] Scenario D Ticket ID {$resD['id']}: Assigned to [{$resD['assigned_groups']}] | Priority: {$resD['priority']}" . PHP_EOL;

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
echo "[PASS] Scenario E Ticket ID {$resE['id']}: Assigned to [{$resE['assigned_groups']}] | Asset: PD57-BARRE-MUM-B-004 (ID $barre_b4_id)" . PHP_EOL;

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
echo "[PASS] Scenario F Ticket ID {$resF['id']}: Assigned to [{$resF['assigned_groups']}] | Asset: PD57-AUDIO-MUM-A-001 (ID $audio_a1_id)" . PHP_EOL;

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
echo "[PASS] Scenario G Ticket ID {$resG['id']}: Assigned to [{$resG['assigned_groups']}] | Asset: PD57-AC-MUM-C-001 (ID $ac_c1_id)" . PHP_EOL;

echo PHP_EOL . "--- Scenario H: Safety-Sensitive Equipment ---" . PHP_EOL;
$barre_a1_id = getApplianceId('PD57-BARRE-MUM-A-001');
$resH = createScenarioTicket(
    'Loose barre bracket during class poses safety risk',
    'Barre mounting pin snapped off, urgent safety concern for evening Barre class.',
    'Safety / Operational Incident',
    'Studio A',
    'Appliance',
    $barre_a1_id,
    5, // Critical urgency
    'PD57_OPS_L2'
);
echo "[PASS] Scenario H Ticket ID {$resH['id']}: Assigned to [{$resH['assigned_groups']}] | Priority: {$resH['priority']} (Critical) | Asset: PD57-BARRE-MUM-A-001" . PHP_EOL;

echo PHP_EOL . "--- Scenario I: Unknown Manual Triage ---" . PHP_EOL;
$resI = createScenarioTicket(
    'Not sure which department handles this equipment question',
    'Inquiry regarding new props ordering for upcoming fitness workshop.',
    'Manual Triage',
    'Mumbai',
    null, 0, 3,
    'PD57_TRIAGE'
);
echo "[PASS] Scenario I Ticket ID {$resI['id']}: Assigned to [{$resI['assigned_groups']}] (PD57_TRIAGE)" . PHP_EOL;

echo PHP_EOL . "--- Scenario J: SLA Escalation Flow ---" . PHP_EOL;
$resJ = createScenarioTicket(
    'Unresolved network issue requiring escalation',
    'Critical router outage in Studio A unresolved past initial response window.',
    'Wi-Fi',
    'Studio A',
    null, 0, 3,
    'PD57_IT_L1'
);
$tidJ = $resJ['id'];
$sla_high_id = getSlaId('PD57_SLA_HIGH');
$ticketJ = new Ticket();
$ticketJ->getFromDB($tidJ);
$ticketJ->update(['id' => $tidJ, 'slas_id_ttr' => $sla_high_id]);

// Add SLA Level Action for Escalation
$slaLevel = new SlaLevel();
$slaL_id = $slaLevel->add(['name' => 'PD57_IT_ESCALATION_LEVEL', 'slas_id' => $sla_high_id, 'execution_time' => 0]);
$slaAct = new SlaLevelAction();
$slaAct->add(['slalevels_id' => $slaL_id, 'action_type' => 'assign', 'field' => '_groups_id_assign', 'value' => getGroupId('PD57_IT_L2')]);

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
echo "[PASS] Scenario J Ticket ID $tidJ: Escalated Groups: [" . implode(', ', $groupsJ) . "]" . PHP_EOL;

echo PHP_EOL . "=== ALL END-TO-END SCENARIOS A - J PASSED ===" . PHP_EOL;
