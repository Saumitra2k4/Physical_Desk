<?php
require_once dirname(__DIR__) . '/src/Glpi/Application/ResourcesChecker.php';
(new \Glpi\Application\ResourcesChecker(dirname(__DIR__)))->checkResources();
require_once dirname(__DIR__) . '/vendor/autoload.php';

$kernel = new \Glpi\Kernel\Kernel();
$kernel->boot();

$user = new User();
$user->getFromDBbyName('glpi');
$auth = new Auth();
$auth->auth_succeded = true;
$auth->user = $user;
Session::init($auth);
Session::changeProfile(4);

echo "--- SLA Escalation Verification ---" . PHP_EOL;

// Ensure SLA action is configured with '_groups_id_assign'
$slaLevelAction = new SlaLevelAction();
if ($slaLevelAction->getFromDB(1)) {
    $slaLevelAction->update([
        'id' => 1,
        'field' => '_groups_id_assign',
        'value' => 2 // Group L2
    ]);
    echo "Updated SlaLevelAction ID 1 field to '_groups_id_assign', value 2 (Group L2)." . PHP_EOL;
}

// Re-add to glpi_slalevels_tickets if needed
global $DB;
$existing = $DB->request(['FROM' => 'glpi_slalevels_tickets', 'WHERE' => ['tickets_id' => 4]]);
if (count($existing) == 0) {
    $DB->insert('glpi_slalevels_tickets', [
        'tickets_id' => 4,
        'slalevels_id' => 1,
        'date' => date('Y-m-d H:i:s', time() - 300)
    ]);
    echo "Re-inserted glpi_slalevels_tickets row for Ticket 4 with past date." . PHP_EOL;
} else {
    $DB->update('glpi_slalevels_tickets', ['date' => date('Y-m-d H:i:s', time() - 300)], ['tickets_id' => 4]);
    echo "Updated glpi_slalevels_tickets date to past." . PHP_EOL;
}

// Execute cron task for slaticket
$task = new CronTask();
$task->getFromDBByCrit(['name' => 'slaticket']);
SlaLevel_Ticket::cronSlaTicket($task);
echo "Ran SlaLevel_Ticket::cronSlaTicket()." . PHP_EOL;

// Verify ticket 4 groups
$group_ticket = new Group_Ticket();
$l2_assigned = $group_ticket->getFromDBByCrit(['tickets_id' => 4, 'groups_id' => 2, 'type' => CommonITILActor::ASSIGN]);
if ($l2_assigned) {
    echo "[ESCALATION SUCCESS] Ticket 4 successfully escalated & assigned to Group L2 (ID 2)!" . PHP_EOL;
} else {
    echo "[ESCALATION CHECK] Groups on Ticket 4:" . PHP_EOL;
    $groups = $group_ticket->find(['tickets_id' => 4]);
    foreach ($groups as $g) {
        echo "  - Group ID: " . $g['groups_id'] . " | Type: " . $g['type'] . PHP_EOL;
    }
}
