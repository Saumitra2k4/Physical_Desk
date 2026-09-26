<?php
/** Run while the local classifier container is stopped. Creates one non-destructive test ticket. */
require_once __DIR__ . '/vendor/autoload.php';
$kernel = new \Glpi\Kernel\Kernel(); $kernel->boot();
$user = new User();
if (!$user->getFromDBbyName('glpi')) throw new RuntimeException('Test admin unavailable');
$auth = new Auth(); $auth->auth_succeded = true; $auth->user = $user;
Session::init($auth); Session::changeProfile(4);
include_once GLPI_ROOT . '/plugins/pd57classifier/setup.php';
include_once GLPI_ROOT . '/plugins/pd57classifier/hook.php';
plugin_init_pd57classifier();
global $DB;
$ticket = new Ticket();
$id = $ticket->add([
    'name' => 'Offline classifier resilience check',
    'content' => 'Studio network request submitted while optional AI is unavailable.',
    'entities_id' => 0,
]);
if (!$id || !$ticket->getFromDB($id)) throw new RuntimeException('Ticket did not persist');
$triage = plugin_pd57classifier_get_manual_triage_category_id();
$relation = new Group_Ticket(); $groups = [];
foreach ($relation->find(['tickets_id' => $id, 'type' => CommonITILActor::ASSIGN]) as $row) {
    $group = new Group(); if ($group->getFromDB((int)$row['groups_id'])) $groups[] = $group->fields['name'];
}
$suggestion = $DB->request(['FROM' => 'glpi_plugin_pd57classifier_suggestions',
    'WHERE' => ['tickets_id' => $id], 'ORDER' => ['rank ASC'], 'LIMIT' => 1])->current();
$ok = (int)$ticket->fields['itilcategories_id'] === $triage
    && in_array('PD57_TRIAGE', $groups, true)
    && !empty($suggestion['failure_reason']) && (int)$suggestion['fallback_used'] === 1;
echo json_encode(['ticket_id' => $id, 'category_is_manual_triage' => (int)$ticket->fields['itilcategories_id'] === $triage,
    'routed_to_triage' => in_array('PD57_TRIAGE', $groups, true),
    'failure_recorded' => !empty($suggestion['failure_reason']), 'passed' => $ok]) . PHP_EOL;
exit($ok ? 0 : 1);
