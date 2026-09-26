<?php
require_once dirname(__DIR__) . '/src/Glpi/Application/ResourcesChecker.php';
(new \Glpi\Application\ResourcesChecker(dirname(__DIR__)))->checkResources();
require_once dirname(__DIR__) . '/vendor/autoload.php';
(new \Glpi\Kernel\Kernel())->boot();
global $DB;
$mode = $argv[1] ?? '';
if ($mode === 'users') {
    $ids = [];
    foreach (array_slice($argv, 2) as $name) {
        $user = new User();
        $ids[$name] = $user->getFromDBbyName($name) ? (int)$user->getID() : 0;
    }
    echo json_encode($ids), PHP_EOL;
    exit;
}
if ($mode === 'followup') {
    $count = $DB->request(['FROM' => 'glpi_itilfollowups', 'WHERE' => [
        'itemtype' => 'Ticket', 'items_id' => (int)($argv[2] ?? 0), 'content' => (string)($argv[3] ?? '')
    ]])->count();
    echo $count, PHP_EOL;
    exit;
}
if ($mode === 'memberships') {
    $result = [];
    foreach (array_slice($argv, 2) as $name) {
        $user = new User();
        if (!$user->getFromDBbyName($name)) { continue; }
        $groups = [];
        foreach ($DB->request(['FROM' => 'glpi_groups_users', 'WHERE' => ['users_id' => (int)$user->getID()]]) as $membership) {
            $group = new Group();
            if ($group->getFromDB((int)$membership['groups_id'])) { $groups[] = $group->fields['name']; }
        }
        $result[$name] = $groups;
    }
    echo json_encode($result), PHP_EOL;
    exit;
}
if ($mode !== 'ticket') { throw new RuntimeException('Unknown mode'); }
$row = $DB->request(['FROM' => 'glpi_tickets', 'WHERE' => ['name' => (string)($argv[2] ?? '')], 'ORDER' => ['id DESC'], 'LIMIT' => 1])->current();
if (!$row) { throw new RuntimeException('Ticket not found'); }
$id = (int)$row['id'];
$groups = [];
foreach ($DB->request(['FROM' => 'glpi_groups_tickets', 'WHERE' => ['tickets_id' => $id, 'type' => 2]]) as $link) {
    $group = new Group();
    if ($group->getFromDB((int)$link['groups_id'])) { $groups[] = $group->fields['name']; }
}
$suggestions = [];
foreach ($DB->request(['FROM' => 'glpi_plugin_pd57classifier_suggestions', 'WHERE' => ['tickets_id' => $id], 'ORDER' => ['rank ASC']]) as $item) {
    $suggestions[] = ['id' => (int)$item['id'], 'category_id' => (int)$item['category_id'],
        'path' => $item['category_path'], 'rank' => (int)$item['rank'],
        'human_confirmed' => (int)$item['human_confirmed'], 'human_override' => (int)$item['human_override'],
        'final_confirmed_category_id' => (int)$item['final_confirmed_category_id']];
}
echo json_encode(['id' => $id, 'category_id' => (int)$row['itilcategories_id'],
    'category' => $row['itilcategories_id'] ? Dropdown::getDropdownName('glpi_itilcategories', (int)$row['itilcategories_id']) : '',
    'groups' => $groups, 'suggestions' => $suggestions,
    'slas_id_tto' => (int)$row['slas_id_tto'], 'slas_id_ttr' => (int)$row['slas_id_ttr'],
    'time_to_own' => $row['time_to_own'], 'time_to_resolve' => $row['time_to_resolve']]), PHP_EOL;
