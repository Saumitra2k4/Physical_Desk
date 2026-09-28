<?php
require_once dirname(__DIR__) . '/inc/portal.php';
require_once dirname(__DIR__) . '/inc/operations.php';
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$ticket = new Ticket();
if (!$ticket->getFromDB($id) || !$ticket->canViewItem()) { http_response_code(403); exit('Request unavailable'); }
$agent = pd57_is_agent();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['confirm_suggestion'])) {
        require_once dirname(__DIR__, 2) . '/pd57classifier/hook.php';
        $result = plugin_pd57classifier_apply_suggestion('confirm', (int)($_POST['suggestion_id'] ?? 0), $id, (int)($_POST['category_id'] ?? 0));
        if (($result['code'] ?? 500) === 200) { Html::redirect(pd57_url('ticket', ['id' => $id])); }
        $error = $result['error'] ?? 'Category confirmation failed.';
    } elseif ($agent && isset($_POST['override_category'])) {
        require_once dirname(__DIR__, 2) . '/pd57classifier/hook.php';
        $result = plugin_pd57classifier_apply_suggestion('override', (int)($_POST['suggestion_id'] ?? 0), $id, (int)($_POST['category_id'] ?? 0));
        if (($result['code'] ?? 500) === 200) { Html::redirect(pd57_url('ticket', ['id' => $id])); }
        $error = $result['error'] ?? 'Category correction failed.';
    } elseif ($agent && isset($_POST['followup'])) {
        $content = trim((string)($_POST['content'] ?? ''));
        if (mb_strlen($content) < 3) { $error = 'Write a short update before posting.'; }
        elseif (!$ticket->canAddFollowups()) { $error = 'You cannot add an update to this request.'; }
        else {
            $followup = new ITILFollowup();
            if ($followupId = $followup->add(['itemtype' => 'Ticket', 'items_id' => $id, 'content' => $content, 'is_private' => 0])) {
                pd57_ops_meaningful_update($id, (int)Session::getLoginUserID(), $content, null, (int)$followupId);
                Html::redirect(pd57_url('ticket', ['id' => $id]));
            }
            $error = 'The update could not be saved.';
        }
    }
    $ticket->getFromDB($id);
}
$category = $ticket->fields['itilcategories_id'] ? Dropdown::getDropdownName('glpi_itilcategories', (int)$ticket->fields['itilcategories_id']) : 'Needs confirmation';
$department = pd57_ticket_department($id);
$phase4Classification = $DB->tableExists(PD57_P4_CLASSIFICATIONS) ? $DB->request(['FROM'=>PD57_P4_CLASSIFICATIONS,'WHERE'=>['tickets_id'=>$id],'ORDER'=>['id DESC'],'LIMIT'=>1])->current() : null;
$phase4State = $DB->tableExists(PD57_OPS_STATE) ? ($DB->request(['FROM'=>PD57_OPS_STATE,'WHERE'=>['tickets_id'=>$id],'LIMIT'=>1])->current()?:null) : null;
$ownership = pd57_p4_ticket_ownership($id,$phase4State);
$priorityAssessment=pd57_p4_priority((string)$ticket->fields['name'].' '.strip_tags((string)$ticket->fields['content']),$phase4Classification['final_request_type']??$phase4Classification['suggested_request_type']??'');
$displayPriority=$phase4State['current_priority']??$priorityAssessment['priority'];
$requesterPerson = null;
foreach ($DB->request(['FROM'=>'glpi_tickets_users','WHERE'=>['tickets_id'=>$id,'type'=>1],'LIMIT'=>1]) as $requesterLink) {
    $requesterPerson = $DB->request(['FROM'=>PD57_ADMIN_TABLE_PEOPLE,'WHERE'=>['linked_users_id'=>(int)$requesterLink['users_id'],'is_active'=>1],'LIMIT'=>1])->current();
}
pd57_layout_start('Request #' . $id);
echo '<a class="back" href="' . pd57_url('index') . '">← Back to ' . ($agent ? 'queue' : 'requests') . '</a>';
if (isset($_GET['created'])) { echo '<div class="success" role="status">Request submitted. Your reference is <strong>PD57-' . str_pad((string)$id, 7, '0', STR_PAD_LEFT) . '</strong>.</div>'; }
if ($error) { echo '<div class="alert" role="alert">' . pd57_h($error) . '</div>'; }
echo '<section class="detail-head"><div><p class="eyebrow">REQUEST PD57-' . str_pad((string)$id, 7, '0', STR_PAD_LEFT) . '</p><h1>' . pd57_h($ticket->fields['name']) . '</h1><p>Submitted ' . pd57_h($ticket->fields['date_creation'] ?? '') . '</p></div><span class="badge status-badge large ' . pd57_status_class((int)$ticket->fields['status']) . '">' . pd57_h(pd57_status((int)$ticket->fields['status'])) . '</span></section>';
echo '<div class="detail-grid"><section class="panel detail-main"><p class="eyebrow">DESCRIPTION</p><div class="description">' . nl2br(pd57_h(strip_tags((string)$ticket->fields['content']))) . '</div>';
$followups = $DB->request(['FROM' => 'glpi_itilfollowups', 'WHERE' => ['itemtype' => 'Ticket', 'items_id' => $id, 'is_private' => 0], 'ORDER' => ['id DESC'], 'LIMIT' => 20]);
echo '<h2 class="updates-title">Updates</h2>';
$hadUpdates = false;
foreach ($followups as $followup) { $hadUpdates = true; $followupText=strip_tags((string)$followup['content']); if(str_starts_with(trim($followupText),'[PD57_'))$followupText=pd57_p4_visible_system_text($followupText,$ownership['department']); echo '<article class="update"><span class="timeline-dot" aria-hidden="true"></span><div><time>' . pd57_h($followup['date_creation']) . '</time><p>' . nl2br(pd57_h($followupText)) . '</p></div></article>'; }
if (!$hadUpdates) { echo '<p class="muted">No updates yet. The assigned team will respond here.</p>'; }
if ($agent) {
    $ops = $phase4State;
    echo '<section class="ops-summary"><p class="eyebrow">CURRENT OWNERSHIP</p><strong>' . pd57_h($ownership['designation']==='Employee Services Desk'?'Employee Services Desk':$ownership['owner_name'].' · '.$ownership['designation']) . '</strong><p>Team: ' . pd57_h($ownership['team']) . '</p><p>Priority: ' . pd57_h($displayPriority) . '</p><p>' . pd57_h($phase4State['priority_summary'] ?? $priorityAssessment['summary']) . '</p><small>Escalations: ' . (int)($phase4State['escalation_count']??0) . ' · Reminders: ' . (int)($phase4State['reminder_count']??0) . '</small></section>';
    if ($ops) {
        $targets = pd57_p4_manual_targets($id,pd57_ops_current_level($ops));
        echo '<form method="post" action="/plugins/pd57portal/front/escalate.php" class="update-form"><input type="hidden" name="_glpi_csrf_token" value="' . pd57_h(Session::getNewCSRFToken()) . '"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="expected_escalation_count" value="' . (int)$ops['escalation_count'] . '"><label for="target_designation_level">Manual escalation</label><select id="target_designation_level" name="target_designation_level" required><option value="">Higher professional designation</option>';
        foreach ($targets as $target) echo '<option value="' . (int)$target['level'] . '">' . pd57_h($target['designation']) . '</option>';
        echo '</select><select name="reason_code" required><option value="">Reason</option><option value="customer_impact">Customer impact</option><option value="missed_commitment">Missed commitment</option><option value="additional_expertise_required">Additional expertise required</option><option value="manager_review">Management review</option></select><textarea name="handoff_note" rows="3" required placeholder="Internal handoff note"></textarea><button class="button button-primary">Escalate</button></form>';
    }
    echo '<form method="post" class="update-form"><input type="hidden" name="_glpi_csrf_token" value="' . pd57_h(Session::getNewCSRFToken()) . '"><input type="hidden" name="id" value="' . $id . '">';
    echo '<label for="update">Add an update</label><textarea id="update" name="content" rows="4" required placeholder="Share progress or the next action"></textarea><button class="button button-primary" name="followup" value="1">Post update</button></form>';
    echo '<h2 class="updates-title">Full request timeline</h2>';
    $opsEvents = $DB->request(['FROM' => PD57_OPS_EVENTS, 'WHERE' => ['tickets_id' => $id], 'ORDER' => ['created_at DESC', 'id DESC'], 'LIMIT' => 50]);
    $hasOps = false;
    foreach ($opsEvents as $event) { $hasOps = true; $isEscalation=in_array($event['event_type'],['manual_escalation','automatic_escalation','employee_rejection'],true); $eventOwnership=$isEscalation?pd57_p4_event_ownership($id,$event):null; $eventTitle=$isEscalation?'Ownership escalated':ucfirst(str_replace('_',' ',$event['event_type'])); echo '<article class="update"><span class="timeline-dot" aria-hidden="true"></span><div><time>' . pd57_h($event['created_at']) . '</time><p><strong>' . pd57_h($eventTitle) . '</strong>' . ($event['reason_code'] ? ' · ' . pd57_h(pd57_ops_reason_label($event['reason_code'])) : '') . ($eventOwnership?'<br>From: '.pd57_h($eventOwnership['from_label']).'<br>To: '.pd57_h($eventOwnership['to_label']):'') . ($event['note'] ? '<br>' . nl2br(pd57_h($event['note'])) : '') . '</p></div></article>'; }
    if (!$hasOps) echo '<p class="muted">No operational events yet.</p>';

}
echo '</section><aside class="panel detail-aside"><p class="eyebrow">REQUEST DETAILS</p><dl><dt>Department</dt><dd>' . pd57_h($ownership['department']) . '</dd><dt>Assigned team</dt><dd>' . pd57_h($ownership['team']) . '</dd>' . ($agent?'<dt>Current owner</dt><dd>'.pd57_h($ownership['owner_name']).'</dd>':'') . '<dt>Designation</dt><dd>' . pd57_h($ownership['designation']) . '</dd><dt>Request Type</dt><dd>' . pd57_h($phase4Classification['final_request_type'] ?? $phase4Classification['suggested_request_type'] ?? $category) . '</dd><dt>Priority</dt><dd>' . pd57_h($displayPriority) . '</dd><dt>Priority assessment</dt><dd>' . pd57_h($phase4State['priority_summary'] ?? $priorityAssessment['summary']) . '</dd>' . ($requesterPerson && ((int)Session::getLoginUserID()===(int)$requesterPerson['linked_users_id'] || $agent) ? '<dt>Employee ID</dt><dd>'.pd57_h($requesterPerson['employee_id'] ?: 'Not assigned').'</dd>' : '') . '<dt>Category</dt><dd>' . pd57_h($category) . '</dd><dt>Status</dt><dd>' . pd57_h(pd57_status((int)$ticket->fields['status'])) . '</dd><dt>Location</dt><dd>' . pd57_h($ticket->fields['locations_id'] ? Dropdown::getDropdownName('glpi_locations', (int)$ticket->fields['locations_id']) : '—') . '</dd><dt>Response target · TTO</dt><dd>' . pd57_h($ticket->fields['time_to_own'] ?: 'Pending') . '</dd><dt>Resolution target · TTR</dt><dd>' . pd57_h($ticket->fields['time_to_resolve'] ?: 'Pending') . '</dd></dl>';
if (!(int)$ticket->fields['itilcategories_id']) {
    echo '<div class="confirm-box"><h2>Confirm a category</h2><p>Choose the suggestion that best fits. Your choice sends this request to a team.</p>';
    foreach ($DB->request(['FROM' => 'glpi_plugin_pd57classifier_suggestions', 'WHERE' => ['tickets_id' => $id], 'ORDER' => ['rank ASC']]) as $suggestion) {
        echo '<form method="post"><input type="hidden" name="_glpi_csrf_token" value="' . pd57_h(Session::getNewCSRFToken()) . '"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="suggestion_id" value="' . (int)$suggestion['id'] . '"><input type="hidden" name="category_id" value="' . (int)$suggestion['category_id'] . '"><button class="suggestion-choice" name="confirm_suggestion" value="1">' . pd57_h($suggestion['category_path']) . ' →</button></form>';
    }
    echo '</div>';
}
if ($agent) {
    $firstSuggestion = $DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_plugin_pd57classifier_suggestions',
        'WHERE' => ['tickets_id' => $id], 'ORDER' => ['rank ASC'], 'LIMIT' => 1])->current();
    if ($firstSuggestion) {
        echo '<form method="post" class="confirm-box"><h2>Correct the category</h2><p>Department staff can update the employee selection when the request belongs elsewhere.</p>';
        echo '<input type="hidden" name="_glpi_csrf_token" value="' . pd57_h(Session::getNewCSRFToken()) . '"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="suggestion_id" value="' . (int)$firstSuggestion['id'] . '">';
        echo '<label for="correct-category">New category</label><select id="correct-category" name="category_id" required><option value="">Choose a category</option>';
        foreach ($DB->request(['FROM' => 'glpi_itilcategories', 'WHERE' => ['is_helpdeskvisible' => 1], 'ORDER' => ['completename ASC']]) as $option) {
            echo '<option value="' . (int)$option['id'] . '">' . pd57_h($option['completename']) . '</option>';
        }
        echo '</select><button class="button button-primary" name="override_category" value="1">Save correction</button></form>';
    }
}
echo '</aside></div>';
require_once dirname(__DIR__) . '/inc/handoff_panel.php';
if (pd57_p4_can_view_internal_handoff($id)) {
    pd57_p4_render_handoff_panel(pd57_p4_handoff_dossier($id));
}

/* PD57_RESOLUTION_PANEL_V1 */
require_once dirname(__DIR__) . '/inc/resolution_panel.php';

pd57_layout_end();
