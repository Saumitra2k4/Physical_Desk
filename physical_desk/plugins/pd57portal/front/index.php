<?php
require_once dirname(__DIR__) . '/inc/portal.php';
$agent = pd57_is_agent();
$rows = pd57_ticket_rows($agent);
$counts = ['open' => 0, 'waiting' => 0, 'progress' => 0, 'resolved' => 0];
foreach ($rows as $row) {
    $status = (int)$row['status'];
    if ($status < 5) { $counts['open']++; }
    if ($status === 1 || $status === 4) { $counts['waiting']++; }
    if ($status === 2 || $status === 3) { $counts['progress']++; }
    if ($status >= 5) { $counts['resolved']++; }
}
$department = pd57_department_groups()[0];
$all = isset($_GET['view']) && $_GET['view'] === 'all';
pd57_layout_start($agent ? $department . ' queue' : ($all ? 'My Requests' : 'Home'), 'home');
echo '<section class="hero"><div><p class="eyebrow">' . ($agent ? pd57_h($department) . ' / Department workspace' : 'Employee workspace') . '</p>';
echo '<h1>' . ($agent ? pd57_h($department) . ' queue' : ($all ? 'My Requests' : 'Good to see you.')) . '</h1>';
echo '<p>' . ($agent ? 'A focused view of requests assigned to your department.' : 'Get help quickly and stay informed at every step.') . '</p></div>';
if (!$agent) { echo '<a class="button button-primary" href="' . pd57_url('request') . '">Create a Request <span>↗</span></a>'; }
echo '</section><section class="stats" aria-label="Request summary">';
foreach (['open' => 'Open requests', 'waiting' => 'Waiting', 'progress' => 'In progress', 'resolved' => 'Resolved'] as $key => $label) {
    echo '<article class="stat"><span>' . pd57_h($label) . '</span><strong>' . $counts[$key] . '</strong></article>';
}
echo '</section><section id="queue" class="panel"><div class="section-head"><div><p class="eyebrow">LIVE WORK</p><h2>' . ($agent ? 'Department requests' : ($all ? 'All my requests' : 'Recent requests')) . '</h2></div>';
if (!$agent && !$all) { echo '<a class="text-link" href="' . pd57_url('index', ['view' => 'all']) . '">View all requests →</a>'; }
echo '</div>';
pd57_ticket_table($all || $agent ? $rows : array_slice($rows, 0, 6));
echo '</section>';
pd57_layout_end();
