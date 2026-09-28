<?php
Session::checkLoginUser();
global $DB;
require_once __DIR__ . '/admin.php';
require_once __DIR__ . '/operations.php';

function pd57_h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function pd57_url(string $page, array $params = []): string {
    return '/plugins/pd57portal/front/' . $page . '.php' . ($params ? '?' . http_build_query($params) : '');
}
function pd57_department_groups(): array {
    $map = [
        'HR' => ['PD57_HR_L1', 'PD57_HR_L2', 'PD57_HR_LEAD'],
        'IT' => ['PD57_IT_L1', 'PD57_IT_L2', 'PD57_IT_NETWORK', 'PD57_IT_HARDWARE', 'PD57_IT_ACCESS', 'PD57_IT_SECURITY', 'PD57_IT_APPLICATIONS', 'PD57_IT_AV'],
        'Payroll' => ['PD57_PAYROLL_L1', 'PD57_PAYROLL_L2', 'PD57_PAYROLL_LEAD'],
        'Operations' => ['PD57_OPS_L1', 'PD57_OPS_L2', 'PD57_OPS_LEAD', 'PD57_TRIAGE'],
    ];
    global $DB;
    $names = [];
    $groupIds = $_SESSION['glpigroups'] ?? [];
    if (!$groupIds) { return ['', []]; }
    foreach ($DB->request(['SELECT' => ['name'], 'FROM' => 'glpi_groups', 'WHERE' => ['id' => $groupIds]]) as $row) { $names[] = $row['name']; }
    foreach ($map as $department => $groups) {
        if (array_intersect($names, $groups)) { return [$department, $groups]; }
    }
    return ['', []];
}
function pd57_is_agent(): bool { return pd57_department_groups()[0] !== '' && Session::getCurrentInterface() !== 'helpdesk'; }
function pd57_status(int $status): string {
    return [1 => 'Waiting', 2 => 'In progress', 3 => 'In progress', 4 => 'Waiting', 5 => 'Resolution proposed', 6 => 'Closed'][$status] ?? 'Open';
}
function pd57_status_class(int $status): string {
    return match ($status) {
        2, 3 => 'status-progress',
        4 => 'status-waiting',
        5 => 'status-review',
        6 => 'status-closed',
        default => 'status-open',
    };
}
function pd57_ticket_rows(bool $department = false): array {
    global $DB;
    $rows = [];
    $uid = Session::getLoginUserID();
    if ($department) {
        $ids = $_SESSION['glpigroups'] ?? [];
        if (!$ids) { return []; }
        $links = $DB->request(['SELECT' => ['tickets_id'], 'FROM' => 'glpi_groups_tickets', 'WHERE' => ['groups_id' => $ids, 'type' => 2]]);
        $ticketIds = [];
        foreach ($links as $link) { $ticketIds[] = (int)$link['tickets_id']; }
        if (!$ticketIds) { return []; }
        $criteria = ['id' => array_values(array_unique($ticketIds))];
    } else {
        $links = $DB->request(['SELECT' => ['tickets_id'], 'FROM' => 'glpi_tickets_users', 'WHERE' => ['users_id' => $uid, 'type' => 1]]);
        $ticketIds = [];
        foreach ($links as $link) { $ticketIds[] = (int)$link['tickets_id']; }
        $criteria = ['OR' => ['id' => $ticketIds ?: [0], 'users_id_recipient' => $uid]];
    }
    foreach ($DB->request(['FROM' => 'glpi_tickets', 'WHERE' => $criteria, 'ORDER' => ['id DESC'], 'LIMIT' => 100]) as $row) {
        $ticket = new Ticket();
        if ($ticket->getFromDB((int)$row['id']) && $ticket->canViewItem()) { $rows[] = $row; }
    }
    return $rows;
}
function pd57_ticket_department(int $ticketId): string {
    global $DB;
    $links = $DB->request(['FROM' => 'glpi_groups_tickets', 'WHERE' => ['tickets_id' => $ticketId, 'type' => 2]]);
    foreach ($links as $link) {
        $group = new Group();
        if ($group->getFromDB((int)$link['groups_id'])) {
            $name = (string)$group->fields['name'];
            if (str_starts_with($name, 'PD57_IT_')) { return 'IT'; }
            if (str_starts_with($name, 'PD57_HR_')) { return 'HR'; }
            if (str_starts_with($name, 'PD57_PAYROLL_')) { return 'Payroll'; }
            if (str_starts_with($name, 'PD57_OPS_')) { return 'Studio Operations'; }
            if ($name === 'PD57_TRIAGE') { return 'Employee Services Desk'; }
        }
    }
    return 'Awaiting routing';
}
function pd57_layout_start(string $title, string $active = ''): void {
    $agent = pd57_is_agent();
    $department = pd57_department_groups()[0];
    $home = pd57_url('index');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . pd57_h($title) . ' · PD57</title><link rel="stylesheet" href="/css/pd57-portal.css?v=20260927.1"></head><body>';
    echo '<a class="skip-link" href="#pd57-main">Skip to main content</a>';
    echo '<header class="topbar"><a class="brand" href="' . $home . '" aria-label="PD57 overview"><span class="brand-mark" aria-hidden="true"><span>PD</span><span>57</span></span><span class="brand-copy"><strong>PD57</strong><small>Physical Desk</small></span></a>';
    echo '<nav aria-label="Primary navigation"><a href="' . $home . '"' . ($active === 'home' ? ' class="active" aria-current="page"' : '') . '>Overview</a>';
    if (!$agent) { echo '<a href="' . pd57_url('request') . '"' . ($active === 'request' ? ' class="active"' : '') . '>Create a Request</a><a href="' . pd57_url('index', ['view' => 'all']) . '">My Requests</a>'; }
    else { echo '<a href="' . $home . '#queue">' . pd57_h(pd57_p4_display_department($department)) . ' Queue</a>'; }
    if (pd57_admin_is_authorized()) { echo '<a href="' . pd57_url('admin') . '">Administration</a>'; }
    echo '</nav><a class="logout" href="/front/logout.php">Sign out</a></header><main id="pd57-main" class="shell" tabindex="-1">';
}
function pd57_layout_end(): void { echo '</main><footer class="footer"><span>PD57 · Physical Desk</span><a href="/plugins/pd57portal/front/open_source_notices.php">Open Source Notices</a></footer></body></html>'; }
function pd57_ticket_table(array $rows,bool $showOwnership=false): void {
    if (!$rows) { echo '<div class="empty">No requests here yet. New requests will appear in this queue.</div>'; return; }
    echo '<div class="table-wrap"><table><thead><tr><th>Request</th><th>Department</th><th>Category</th>' . ($showOwnership?'<th>Owner</th>':'') . '<th>Status</th><th>TTO</th><th>TTR</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        $id = (int)$row['id'];
        $ownership = $showOwnership ? pd57_p4_ticket_ownership($id) : null;
        echo '<tr><td><a class="row-title" href="' . pd57_url('ticket', ['id' => $id]) . '">' . pd57_h($row['name']) . '</a><small>#' . $id . '</small></td>';
        echo '<td>' . pd57_h($ownership['department'] ?? pd57_ticket_department($id)) . '</td><td>' . pd57_h($row['itilcategories_id'] ? Dropdown::getDropdownName('glpi_itilcategories', (int)$row['itilcategories_id']) : 'Needs confirmation') . '</td>';
        if($ownership){echo '<td><strong>'.pd57_h($ownership['owner_name']).'</strong><small>'.pd57_h($ownership['designation'].' · '.$ownership['team']).'</small></td>';}
        echo '<td><span class="badge status-badge ' . pd57_status_class((int)$row['status']) . '">' . pd57_h(pd57_status((int)$row['status'])) . '</span></td><td>' . pd57_h($row['time_to_own'] ?: '—') . '</td><td>' . pd57_h($row['time_to_resolve'] ?: '—') . '</td></tr>';
    }
    echo '</tbody></table></div>';
}
