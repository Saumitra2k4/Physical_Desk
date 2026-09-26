<?php
require_once dirname(__DIR__) . '/inc/portal.php';
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
            if ($followup->add(['itemtype' => 'Ticket', 'items_id' => $id, 'content' => $content, 'is_private' => 0])) {
                Html::redirect(pd57_url('ticket', ['id' => $id]));
            }
            $error = 'The update could not be saved.';
        }
    }
    $ticket->getFromDB($id);
}
$category = $ticket->fields['itilcategories_id'] ? Dropdown::getDropdownName('glpi_itilcategories', (int)$ticket->fields['itilcategories_id']) : 'Needs confirmation';
$department = pd57_ticket_department($id);
pd57_layout_start('Request #' . $id);
echo '<a class="back" href="' . pd57_url('index') . '">← Back to ' . ($agent ? 'queue' : 'requests') . '</a>';
if (isset($_GET['created'])) { echo '<div class="success" role="status">Request submitted. Your reference is <strong>#' . $id . '</strong>.</div>'; }
if ($error) { echo '<div class="alert" role="alert">' . pd57_h($error) . '</div>'; }
echo '<section class="detail-head"><div><p class="eyebrow">REQUEST #' . $id . '</p><h1>' . pd57_h($ticket->fields['name']) . '</h1><p>Submitted ' . pd57_h($ticket->fields['date_creation'] ?? '') . '</p></div><span class="pill large">' . pd57_h(pd57_status((int)$ticket->fields['status'])) . '</span></section>';
echo '<div class="detail-grid"><section class="panel detail-main"><p class="eyebrow">DESCRIPTION</p><div class="description">' . nl2br(pd57_h(strip_tags((string)$ticket->fields['content']))) . '</div>';
$followups = $DB->request(['FROM' => 'glpi_itilfollowups', 'WHERE' => ['itemtype' => 'Ticket', 'items_id' => $id, 'is_private' => 0], 'ORDER' => ['id DESC'], 'LIMIT' => 20]);
echo '<h2 class="updates-title">Updates</h2>';
$hadUpdates = false;
foreach ($followups as $followup) { $hadUpdates = true; echo '<article class="update"><span>' . pd57_h($followup['date_creation']) . '</span><p>' . nl2br(pd57_h(strip_tags((string)$followup['content']))) . '</p></article>'; }
if (!$hadUpdates) { echo '<p class="muted">No updates yet. The assigned team will respond here.</p>'; }
if ($agent) {
    echo '<form method="post" class="update-form"><input type="hidden" name="_glpi_csrf_token" value="' . pd57_h(Session::getNewCSRFToken()) . '"><input type="hidden" name="id" value="' . $id . '">';
    echo '<label for="update">Add an update</label><textarea id="update" name="content" rows="4" required placeholder="Share progress or the next action"></textarea><button class="button button-primary" name="followup" value="1">Post update</button></form>';
}
echo '</section><aside class="panel detail-aside"><p class="eyebrow">REQUEST DETAILS</p><dl><dt>Department</dt><dd>' . pd57_h($department) . '</dd><dt>Category</dt><dd>' . pd57_h($category) . '</dd><dt>Status</dt><dd>' . pd57_h(pd57_status((int)$ticket->fields['status'])) . '</dd><dt>Location</dt><dd>' . pd57_h($ticket->fields['locations_id'] ? Dropdown::getDropdownName('glpi_locations', (int)$ticket->fields['locations_id']) : '—') . '</dd><dt>Response target · TTO</dt><dd>' . pd57_h($ticket->fields['time_to_own'] ?: 'Pending') . '</dd><dt>Resolution target · TTR</dt><dd>' . pd57_h($ticket->fields['time_to_resolve'] ?: 'Pending') . '</dd></dl>';
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
pd57_layout_end();
