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

echo "--- SLA Escalation Trigger Test ---" . PHP_EOL;

// Set ticket 4 time_to_resolve to past so SLA is breached/triggered
$ticket = new Ticket();
if ($ticket->getFromDB(4)) {
    $ticket->update([
        'id' => 4,
        'time_to_resolve' => date('Y-m-d H:i:s', time() - 300) // 5 minutes ago
    ]);
    echo "Updated Ticket 4 time_to_resolve to past: " . date('Y-m-d H:i:s', time() - 300) . PHP_EOL;
}

// Run SLA CronTask
CronTask::launch(CronTask::MODE_EXTERNAL, 1, 'sla');
echo "CronTask 'sla' executed." . PHP_EOL;

// Check SLA levels / SLA ticket assignments
$group_ticket = new Group_Ticket();
$l2_assigned = $group_ticket->getFromDBByCrit(['tickets_id' => 4, 'groups_id' => 2, 'type' => CommonITILActor::ASSIGN]);
if ($l2_assigned) {
    echo "[ESCALATION SUCCESS] Ticket 4 successfully escalated & assigned to Group L2 (ID 2)!" . PHP_EOL;
} else {
    echo "[ESCALATION CHECK] Current groups on Ticket 4:" . PHP_EOL;
    $groups = $group_ticket->find(['tickets_id' => 4]);
    foreach ($groups as $g) {
        echo "  - Group ID: " . $g['groups_id'] . " | Type: " . $g['type'] . PHP_EOL;
    }
}
