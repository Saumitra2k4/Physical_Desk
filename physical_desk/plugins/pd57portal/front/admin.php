<?php
require_once dirname(__DIR__) . '/inc/portal.php';
pd57_admin_require_access();
pd57_admin_install_schema();

global $DB;
$section = (string)($_GET['section'] ?? 'overview');
$allowedSections = ['overview', 'people', 'calendar', 'policies', 'audit'];
if (!in_array($section, $allowedSections, true)) {
    $section = 'overview';
}
$flash = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'replace_assignment') {
            $personId = (int)($_POST['people_id'] ?? 0);
            if ($personId < 1) {
                $personId = pd57_admin_create_person([
                    'display_name' => $_POST['display_name'] ?? '',
                    'delivery_email' => $_POST['delivery_email'] ?? '',
                    'linked_users_id' => $_POST['linked_users_id'] ?? 0,
                    'is_active' => isset($_POST['person_active']),
                ]);
            }
            pd57_admin_replace_assignment([
                'roles_id' => $_POST['roles_id'] ?? 0,
                'people_id' => $personId,
                'backup_people_id' => $_POST['backup_people_id'] ?? 0,
                'effective_from' => $_POST['effective_from'] ?? '',
            ]);
            $flash = 'Ownership updated. The prior assignment and audit history were preserved.';
            $section = 'people';
        } elseif ($action === 'update_person') {
            pd57_admin_update_person((int)($_POST['person_id'] ?? 0), $_POST);
            $flash = 'Person details were updated and audited.';
            $section = 'people';
        } elseif ($action === 'save_membership') {
            pd57_admin_save_membership($_POST);
            $flash = 'Team membership was saved and audited.';
            $section = 'people';
        } elseif ($action === 'end_membership') {
            pd57_admin_end_membership((int)($_POST['membership_id'] ?? 0), (string)($_POST['effective_until'] ?? ''));
            $flash = 'Team membership was end-dated and preserved in history.';
            $section = 'people';
        } elseif ($action === 'update_calendar') {
            pd57_admin_update_calendar($_POST);
            $flash = 'Working calendar updated and audited.';
            $section = 'calendar';
        } elseif ($action === 'update_operational_policy') {
            pd57_admin_update_operational_policy($_POST);
            $flash = 'Operational policy updated and audited.';
            $section = 'policies';
        } else {
            throw new InvalidArgumentException('Unsupported admin action.');
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

function pd57_admin_layout_start(string $active): void
{
    $url = '/plugins/pd57portal/front/admin.php';
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<meta name="robots" content="noindex,nofollow"><title>Administration | PD57</title><link rel="stylesheet" href="/css/pd57-portal.css?v=20260927.2"></head><body class="admin-body">';
    echo '<a class="skip-link" href="#pd57-main">Skip to main content</a><header class="topbar admin-topbar">';
    echo '<a class="brand" href="' . $url . '"><span class="brand-mark" aria-hidden="true"><span>PD</span><span>57</span></span><span class="brand-copy"><strong>PD57</strong><small>Administration</small></span></a>';
    echo '<nav aria-label="Admin navigation">';
    foreach (['overview' => 'Overview', 'people' => 'People & Ownership', 'calendar' => 'Working Calendar', 'policies' => 'Policies', 'audit' => 'Audit History'] as $key => $label) {
        echo '<a href="' . $url . '?section=' . $key . '"' . ($active === $key ? ' class="active" aria-current="page"' : '') . '>' . pd57_h($label) . '</a>';
    }
    echo '</nav><a class="logout" href="/front/logout.php">Sign out</a></header><main id="pd57-main" class="shell admin-shell" tabindex="-1">';
}

pd57_admin_layout_start($section);
echo '<section class="admin-heading"><div><p class="eyebrow">Organisation control plane</p><h1>' . pd57_h(['overview' => 'Operational overview', 'people' => 'People & ownership', 'calendar' => 'Working calendar', 'policies' => 'Operational policies', 'audit' => 'Audit history'][$section]) . '</h1><p>Authoritative PD57 configuration, separated from physical delivery addresses.</p></div><span class="badge admin-badge">Admin access</span></section>';
if ($flash !== '') {
    echo '<div class="success" role="status">' . pd57_h($flash) . '</div>';
}
if ($error !== '') {
    echo '<div class="alert" role="alert">' . pd57_h($error) . '</div>';
}

if ($section === 'overview') {
    $analytics = pd57_admin_analytics();
    echo '<div class="stats admin-stats">';
    foreach (['open' => 'Open', 'awaiting' => 'Awaiting employee confirmation', 'closed' => 'Closed / resolved', 'total' => 'Total'] as $key => $label) {
        echo '<article class="stat"><span>' . pd57_h($label) . '</span><strong>' . (int)$analytics['counts'][$key] . '</strong></article>';
    }
    echo '</div><div class="stats admin-stats">';
    foreach (['critical'=>'Critical','aging'=>'Aging / unattended','escalated'=>'Escalated','rejected'=>'Rejected resolutions'] as $key=>$label) echo '<article class="stat"><span>' . pd57_h($label) . '</span><strong>' . (int)$analytics['ops'][$key] . '</strong></article>';
    echo '</div><div class="admin-grid"><section class="panel"><div class="section-head"><h2>Open by department</h2><span class="muted">Live ticket data</span></div><div class="metric-list">';
    foreach ($analytics['by_department'] as $label => $count) {
        echo '<div><span>' . pd57_h($label) . '</span><strong>' . (int)$count . '</strong></div>';
    }
    echo '</div></section><section class="panel"><div class="section-head"><h2>Workload by professional</h2><span class="muted">Current operational state</span></div><div class="metric-list">';
    if ($analytics['workload']) foreach ($analytics['workload'] as $label=>$count) echo '<div><span>' . pd57_h($label) . '</span><strong>' . (int)$count . '</strong></div>'; else echo '<div><span>No data</span></div>';
    echo '</div></section><section class="panel"><div class="section-head"><h2>Priority distribution</h2><span class="muted">Live ticket data</span></div><div class="metric-list">';
    if ($analytics['priority']) foreach ($analytics['priority'] as $label=>$count) echo '<div><span>' . pd57_h($label) . '</span><strong>' . (int)$count . '</strong></div>'; else echo '<div><span>No data</span></div>';
    echo '</div></section><section class="panel"><div class="section-head"><h2>Recent ticket activity</h2><span class="muted">Latest changes</span></div><div class="activity-list">';
    foreach ($analytics['recent'] as $row) {
        echo '<div><span><strong>#' . (int)$row['id'] . ' | ' . pd57_h($row['name']) . '</strong><small>' . pd57_h(pd57_status((int)$row['status'])) . '</small></span><time>' . pd57_h($row['date_mod']) . '</time></div>';
    }
    echo '</div></section></div>';
} elseif ($section === 'people') {
    $roles = iterator_to_array($DB->request(['FROM' => PD57_ADMIN_TABLE_ROLES, 'WHERE' => ['is_active' => 1], 'ORDER' => ['sort_order ASC']]));
    $people = iterator_to_array($DB->request(['FROM' => PD57_ADMIN_TABLE_PEOPLE, 'ORDER' => ['display_name ASC']]));
    $users = iterator_to_array($DB->request(['SELECT' => ['id', 'name', 'firstname', 'realname'], 'FROM' => 'glpi_users', 'WHERE' => ['is_active' => 1], 'ORDER' => ['name ASC']]));
/* PD57_LINKED_ACCOUNT_WHITELIST_V1
 * This selector intentionally exposes only operational PD57 identities.
 * Framework/system/template users and employee requester accounts remain
 * available internally but are not valid organisation-owner logins.
 */
$pd57LinkedAccountNames = [
    'admin@physicaldesk',
    'hr@physicaldesk',
    'it@physicaldesk',
    'payroll@physicaldesk',
    'operations@physicaldesk',
];

$users = array_values(array_filter(
    $users,
    static fn(array $user): bool => in_array(
        (string)($user['name'] ?? ''),
        $pd57LinkedAccountNames,
        true
    )
));

    $today = date('Y-m-d');
    echo '<div class="admin-grid ownership-grid"><section class="panel"><div class="section-head"><div><p class="eyebrow">Current structure</p><h2>Professional designation occupancy</h2></div><form class="asof-form" method="get"><input type="hidden" name="section" value="people"><label for="as_of">As of date</label><input id="as_of" type="date" name="as_of" value="' . pd57_h((string)($_GET['as_of'] ?? $today)) . '"><button type="submit" class="button-secondary">View</button></form></div><div class="role-cards">';
    $asOf = pd57_valid_date_for_view((string)($_GET['as_of'] ?? $today), $today);
    foreach ($roles as $role) {
        $assignment = pd57_admin_assignment_as_of((int)$role['id'], $asOf);
        $displayDepartment=pd57_p4_display_department((string)$role['department']);$designation=pd57_p4_display_designation($displayDepartment,(int)$role['escalation_level'],(string)$role['role_label']);$teamNames=$assignment?pd57_p4_membership_team_names($displayDepartment,(int)$assignment['people_id'],(int)$role['escalation_level'],$asOf):[];
        echo '<article class="role-card"><div><span class="role-department">' . pd57_h($displayDepartment) . '</span><h3>' . pd57_h($designation) . '</h3><small>Professional designation</small></div>';
        if ($assignment) {
            echo '<div class="occupant"><strong>' . pd57_h($assignment['display_name']) . '</strong><span>Team: ' . pd57_h($teamNames?implode(', ',$teamNames):'No active team membership') . '</span><span>Employee ID: ' . pd57_h($assignment['employee_id']?:'Not assigned') . '</span><small>' . pd57_h($assignment['effective_from']) . ' -> ' . pd57_h($assignment['effective_until'] ?: 'Current') . ($assignment['backup_name'] ? ' | Backup: ' . pd57_h($assignment['backup_name']) : '') . '</small></div>';
        } else {
            echo '<div class="occupant empty-occupant">Unassigned on ' . pd57_h($asOf) . '</div>';
        }
        echo '</article>';
    }
    echo '</div></section><aside class="panel ownership-form"><p class="eyebrow">Effective-dated change</p><h2>Assign or replace</h2><p class="muted">A replacement end-dates the prior row. History is never deleted.</p><form method="post">' . pd57_csrf_field() . '<input type="hidden" name="action" value="replace_assignment"><label for="roles_id">Department and designation</label><select id="roles_id" name="roles_id" required><option value="">Select designation</option>';
    foreach ($roles as $role) {
        $displayDepartment=pd57_p4_display_department((string)$role['department']);echo '<option value="' . (int)$role['id'] . '">' . pd57_h($displayDepartment . ' | ' . pd57_p4_display_designation($displayDepartment,(int)$role['escalation_level'],(string)$role['role_label'])) . '</option>';
    }
    echo '</select><label for="people_id">Existing person <span class="optional">or create below</span></label><select id="people_id" name="people_id"><option value="0">Create a new person</option>';
    foreach ($people as $person) {
        echo '<option value="' . (int)$person['id'] . '">' . pd57_h($person['display_name'] . ($person['is_active'] ? '' : ' | inactive')) . '</option>';
    }
    echo '</select><div class="new-person-fields"><label for="display_name">Display name</label><input id="display_name" name="display_name" maxlength="255" placeholder="Logical role occupant"><label for="delivery_email">Delivery email</label><input id="delivery_email" name="delivery_email" type="email" maxlength="255" placeholder="Shared addresses are allowed"><label for="linked_users_id">Linked PD57 account <span class="optional">optional</span></label><select id="linked_users_id" name="linked_users_id"><option value="0">No linked account</option>';
    foreach ($users as $user) {
        $display = trim((string)$user['firstname'] . ' ' . (string)$user['realname']);
        echo '<option value="' . (int)$user['id'] . '">' . pd57_h(($display !== '' ? $display . ' | ' : '') . $user['name']) . '</option>';
    }
    echo '</select><label class="check-row"><input type="checkbox" name="person_active" value="1" checked> Active person</label></div><label for="backup_people_id">Backup person <span class="optional">optional</span></label><select id="backup_people_id" name="backup_people_id"><option value="0">No backup</option>';
    foreach ($people as $person) {
        if ($person['is_active']) {
            echo '<option value="' . (int)$person['id'] . '">' . pd57_h($person['display_name']) . '</option>';
        }
    }
    echo '</select><label for="effective_from">Effective from</label><input id="effective_from" name="effective_from" type="date" value="' . pd57_h($today) . '" required><button type="submit">Save ownership change</button></form></aside></div>';
    $teams = iterator_to_array($DB->request(['FROM' => PD57_P4_TEAMS, 'WHERE' => ['is_active' => 1], 'ORDER' => ['department ASC', 'name ASC']]));
    $memberships = iterator_to_array($DB->request([
        'SELECT' => ['m.*', 'p.display_name', 'p.delivery_email', 'p.linked_users_id', 't.department', 't.name AS team_name'],
        'FROM' => PD57_P4_MEMBERS . ' AS m',
        'INNER JOIN' => [PD57_ADMIN_TABLE_PEOPLE . ' AS p' => ['FKEY' => ['m' => 'people_id', 'p' => 'id']], PD57_P4_TEAMS . ' AS t' => ['FKEY' => ['m' => 'teams_id', 't' => 'id']]],
        'ORDER' => ['p.display_name ASC', 'm.effective_from DESC'], 'LIMIT' => 300,
    ]));
    echo '<section class="panel history-panel"><div class="section-head"><div><p class="eyebrow">Current organisation directory</p><h2>People, delivery addresses and memberships</h2></div><span class="muted">Shared delivery addresses are supported</span></div><div class="table-wrap"><table><thead><tr><th>Person</th><th>Department</th><th>Team</th><th>Professional designation</th><th>Delivery / account</th><th>Availability</th><th>Effective dates</th><th>Manage</th></tr></thead><tbody>';
    foreach ($memberships as $m) {
        $account = '';
        foreach ($users as $user) if ((int)$user['id'] === (int)$m['linked_users_id']) { $account = (string)$user['name']; break; }
        echo '<tr><td><strong>' . pd57_h($m['display_name']) . '</strong><small>' . ((int)$m['is_active'] ? 'Active' : 'Inactive') . '</small></td><td>' . pd57_h(pd57_p4_display_department((string)$m['department'])) . '</td><td>' . pd57_h($m['team_name']) . '</td><td>' . pd57_h(pd57_p4_display_designation((string)$m['department'], (int)$m['designation_level'], (string)$m['designation'])) . '</td><td>' . pd57_h($m['delivery_email']) . '<small>' . pd57_h($account ?: 'No linked PD57 account') . '</small></td><td>' . ((int)$m['is_unavailable'] ? 'Unavailable' : 'Available') . '</td><td>' . pd57_h($m['effective_from']) . ' → ' . pd57_h($m['effective_until'] ?: 'Current') . '</td><td><form method="post">' . pd57_csrf_field() . '<input type="hidden" name="action" value="end_membership"><input type="hidden" name="membership_id" value="' . (int)$m['id'] . '"><input type="date" name="effective_until" value="' . pd57_h($m['effective_until'] ?: $today) . '" required><button class="button-secondary" type="submit">End</button></form></td></tr>';
    }
    if (!$memberships) echo '<tr><td colspan="8">No current memberships have been configured.</td></tr>';
    echo '</tbody></table></div></section>';
    echo '<div class="admin-grid"><section class="panel form-panel"><p class="eyebrow">People</p><h2>Edit person safely</h2><form method="post">' . pd57_csrf_field() . '<input type="hidden" name="action" value="update_person"><label>Person</label><select id="pd57-edit-person" name="person_id" required>';
    foreach ($people as $person) echo '<option value="' . (int)$person['id'] . '" data-name="' . pd57_h($person['display_name']) . '" data-email="' . pd57_h($person['delivery_email']) . '" data-user="' . (int)$person['linked_users_id'] . '" data-active="' . (int)$person['is_active'] . '">' . pd57_h($person['display_name']) . '</option>';
    echo '</select><label>Display name</label><input id="pd57-edit-name" name="display_name" required maxlength="255"><label>Delivery email</label><input id="pd57-edit-email" name="delivery_email" type="email" required maxlength="255"><label>Linked PD57 account</label><select id="pd57-edit-user" name="linked_users_id"><option value="0">No linked account</option>';
    foreach ($users as $user) echo '<option value="' . (int)$user['id'] . '">' . pd57_h($user['name']) . '</option>';
    echo '</select><label class="check-row"><input id="pd57-edit-active" type="checkbox" name="is_active" value="1" checked> Active person</label><button type="submit">Save person</button></form></section>';
    echo '<section class="panel form-panel"><p class="eyebrow">Membership</p><h2>Add or replace team membership</h2><form method="post">' . pd57_csrf_field() . '<input type="hidden" name="action" value="save_membership"><label>Person</label><select name="people_id" required>';
    foreach ($people as $person) echo '<option value="' . (int)$person['id'] . '">' . pd57_h($person['display_name']) . '</option>';
    echo '</select><label>Department / team</label><select name="teams_id" required>';
    foreach ($teams as $team) echo '<option value="' . (int)$team['id'] . '">' . pd57_h(pd57_p4_display_department((string)$team['department']) . ' | ' . $team['name']) . '</option>';
    echo '</select><label>Professional designation</label><input name="designation" maxlength="120" placeholder="IT Support Engineer"><label>Designation level</label><input type="number" name="designation_level" min="1" max="9" value="1" required><label>Effective from</label><input type="date" name="effective_from" value="' . pd57_h($today) . '" required><label>Effective until</label><input type="date" name="effective_until"><label class="check-row"><input type="checkbox" name="is_active" value="1" checked> Active membership</label><label class="check-row"><input type="checkbox" name="is_unavailable" value="1"> Unavailable for assignment</label><button type="submit">Save membership</button></form></section></div>';
    echo '<script>(function(){const s=document.getElementById("pd57-edit-person"),n=document.getElementById("pd57-edit-name"),e=document.getElementById("pd57-edit-email"),u=document.getElementById("pd57-edit-user"),a=document.getElementById("pd57-edit-active");function load(){const o=s.options[s.selectedIndex];n.value=o.dataset.name||"";e.value=o.dataset.email||"";u.value=o.dataset.user||"0";a.checked=o.dataset.active==="1";}if(s){s.addEventListener("change",load);load();}})();</script>';
    $history = iterator_to_array($DB->request([
        'SELECT' => ['a.*', 'p.display_name', 'p.delivery_email', 'p.employee_id', 'r.department', 'r.role_label', 'r.escalation_level'],
        'FROM' => PD57_ADMIN_TABLE_ASSIGNMENTS . ' AS a',
        'INNER JOIN' => [PD57_ADMIN_TABLE_PEOPLE . ' AS p' => ['FKEY' => ['a' => 'people_id', 'p' => 'id']], PD57_ADMIN_TABLE_ROLES . ' AS r' => ['FKEY' => ['a' => 'roles_id', 'r' => 'id']]],
        'ORDER' => ['a.effective_from DESC', 'a.id DESC'], 'LIMIT' => 100,
    ]));
    echo '<section class="panel history-panel"><div class="section-head"><h2>Assignment history</h2><span class="muted">Immutable timeline</span></div><div class="table-wrap"><table><thead><tr><th>Department</th><th>Team</th><th>Designation</th><th>Person</th><th>Employee ID</th><th>Effective</th><th>State</th></tr></thead><tbody>';
    foreach ($history as $row) {
        $displayDepartment=pd57_p4_display_department((string)$row['department']);$teamNames=pd57_p4_membership_team_names($displayDepartment,(int)$row['people_id'],(int)$row['escalation_level'],(string)$row['effective_from']);echo '<tr><td>' . pd57_h($displayDepartment) . '</td><td>' . pd57_h($teamNames?implode(', ',$teamNames):'No active team membership') . '</td><td>' . pd57_h(pd57_p4_display_designation($displayDepartment,(int)$row['escalation_level'],(string)$row['role_label'])) . '</td><td><strong>' . pd57_h($row['display_name']) . '</strong><small>' . pd57_h($row['delivery_email']) . '</small></td><td>' . pd57_h($row['employee_id']?:'Not assigned') . '</td><td>' . pd57_h($row['effective_from']) . ' -> ' . pd57_h($row['effective_until'] ?: 'Current') . '</td><td><span class="badge">' . ($row['is_active'] ? 'Active record' : 'Inactive') . '</span></td></tr>';
    }
    echo '</tbody></table></div></section>';
} elseif ($section === 'calendar') {
    $calendar = $DB->request(['FROM' => PD57_ADMIN_TABLE_CALENDAR, 'WHERE' => ['id' => 1], 'LIMIT' => 1])->current();
    $days = array_map('intval', explode(',', (string)$calendar['working_days']));
    echo '<div class="calendar-layout"><section class="panel calendar-summary"><p class="eyebrow">Current policy</p><h2>' . pd57_h($calendar['timezone']) . '</h2><strong>' . pd57_h(substr($calendar['start_time'], 0, 5) . '-' . substr($calendar['end_time'], 0, 5)) . '</strong><p>' . pd57_h(pd57_day_names($days)) . '</p><small>Last updated ' . pd57_h($calendar['updated_at']) . '</small></section><section class="panel form-panel"><div class="section-head"><div><p class="eyebrow">Persisted schedule</p><h2>Edit working calendar</h2></div></div><form method="post">' . pd57_csrf_field() . '<input type="hidden" name="action" value="update_calendar"><label for="timezone">Timezone</label><select id="timezone" name="timezone" required>';
    foreach (DateTimeZone::listIdentifiers() as $timezone) {
        echo '<option value="' . pd57_h($timezone) . '"' . ($timezone === $calendar['timezone'] ? ' selected' : '') . '>' . pd57_h($timezone) . '</option>';
    }
    echo '</select><fieldset><legend>Working days</legend><div class="day-options">';
    foreach ([1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'] as $number => $label) {
        echo '<label><input type="checkbox" name="working_days[]" value="' . $number . '"' . (in_array($number, $days, true) ? ' checked' : '') . '><span>' . $label . '</span></label>';
    }
    echo '</div></fieldset><div class="time-grid"><div><label for="start_time">Start time</label><input id="start_time" type="time" name="start_time" value="' . pd57_h(substr($calendar['start_time'], 0, 5)) . '" required></div><div><label for="end_time">End time</label><input id="end_time" type="time" name="end_time" value="' . pd57_h(substr($calendar['end_time'], 0, 5)) . '" required></div></div><button type="submit">Save calendar</button></form></section></div>';
} elseif ($section === 'policies') {
    require_once dirname(__DIR__) . '/inc/operations.php';
    $policy = pd57_ops_policy();
    echo '<div class="calendar-layout"><section class="panel calendar-summary"><p class="eyebrow">Deterministic engine</p><h2>Business-time lifecycle</h2><p>Calendar settings remain the sole authority for working time. Policy events are append-only.</p><small>Exact category overrides: Salary not received → High; Payslip → Medium; unable-to-work identity/access → High; Major outage → Critical.</small></section><section class="panel form-panel"><p class="eyebrow">Active policy</p><h2>Aging and reminders</h2><form method="post">' . pd57_csrf_field() . '<input type="hidden" name="action" value="update_operational_policy"><label for="medium_to_high_minutes">Medium → High (working minutes)</label><input id="medium_to_high_minutes" type="number" min="1" name="medium_to_high_minutes" value="' . (int)$policy['medium_to_high_minutes'] . '" required><label for="high_to_critical_minutes">High → Critical (working minutes)</label><input id="high_to_critical_minutes" type="number" min="1" name="high_to_critical_minutes" value="' . (int)$policy['high_to_critical_minutes'] . '" required><label for="auto_escalation_minutes">Automatic escalation (working minutes)</label><input id="auto_escalation_minutes" type="number" min="1" name="auto_escalation_minutes" value="' . (int)$policy['auto_escalation_minutes'] . '" required><label for="reminder_minutes">Reminder interval (working minutes)</label><input id="reminder_minutes" type="number" min="1" name="reminder_minutes" value="' . (int)$policy['reminder_minutes'] . '" required><label class="check-row"><input type="checkbox" name="is_active" value="1"' . ($policy['is_active'] ? ' checked' : '') . '> Operational policy active</label><button type="submit">Save policy</button></form></section></div>';
} else {
    $audit = iterator_to_array($DB->request(['FROM' => PD57_ADMIN_TABLE_AUDIT, 'ORDER' => ['id DESC'], 'LIMIT' => 200]));
    echo '<section class="panel"><div class="section-head"><div><p class="eyebrow">Append-only evidence</p><h2>Configuration changes</h2></div><span class="muted">Newest first</span></div><div class="audit-list">';
    foreach ($audit as $row) {
        echo '<article><div><span class="badge">' . pd57_h($row['action']) . '</span><h3>' . pd57_h($row['entity_type'] . ' #' . $row['entity_id']) . '</h3><p>' . pd57_h($row['actor_identity']) . ($row['department'] ? ' | ' . pd57_h($row['department']) : '') . ($row['role_key'] ? ' / ' . pd57_h($row['role_key']) : '') . '</p></div><time>' . pd57_h($row['created_at']) . '</time><details><summary>View snapshots</summary><div class="snapshot-grid"><pre>' . pd57_h($row['previous_json'] ?: 'No previous state') . '</pre><pre>' . pd57_h($row['new_json'] ?: 'No new state') . '</pre></div></details></article>';
    }
    if (!$audit) {
        echo '<div class="empty">No administrative changes have been recorded yet.</div>';
    }
    echo '</div></section>';
}

echo '</main><footer class="footer"><span>PD57 | Admin control plane</span><a href="/plugins/pd57portal/front/index.php">Return to portal</a></footer></body></html>';

function pd57_csrf_field(): string
{
    return '<input type="hidden" name="_glpi_csrf_token" value="' . pd57_h(Session::getNewCSRFToken()) . '">';
}

function pd57_valid_date_for_view(string $candidate, string $fallback): string
{
    return pd57_admin_valid_date($candidate) ? $candidate : $fallback;
}

function pd57_day_names(array $days): string
{
    $names = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
    return implode(', ', array_map(static fn(int $day): string => $names[$day] ?? '', $days));
}
