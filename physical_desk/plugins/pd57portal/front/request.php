<?php
require_once dirname(__DIR__) . '/inc/portal.php';
if (pd57_is_agent()) { http_response_code(403); exit('Employee request form only'); }
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim((string)($_POST['name'] ?? ''));
    $content = trim((string)($_POST['content'] ?? ''));
    $categoryId = (int)($_POST['itilcategories_id'] ?? 0);
    $locationId = (int)($_POST['locations_id'] ?? 0);
    $category = new ITILCategory();
    $location = new Location();
    if (mb_strlen($title) < 5 || mb_strlen($title) > 180 || mb_strlen($content) < 10) {
        $error = 'Add a clear title and at least 10 characters of detail.';
    } elseif (!$category->getFromDB($categoryId) || !(int)$category->fields['is_helpdeskvisible']
        || !Session::haveAccessToEntity((int)$category->fields['entities_id'], (bool)$category->fields['is_recursive'])) {
        $error = 'Choose a category to confirm where this request should go.';
    } elseif ($locationId && (!$location->getFromDB($locationId)
        || !Session::haveAccessToEntity((int)$location->fields['entities_id'], (bool)$location->fields['is_recursive']))) {
        $error = 'Choose a valid location.';
    } else {
        $ticket = new Ticket();
        $id = $ticket->add(['name' => $title, 'content' => $content, 'type' => Ticket::INCIDENT_TYPE,
            'urgency' => 3, 'impact' => 3, 'entities_id' => $_SESSION['glpiactive_entity'] ?? 0,
            'locations_id' => $locationId, 'itilcategories_id' => $categoryId,
            'users_id_recipient' => Session::getLoginUserID(), '_users_id_requester' => Session::getLoginUserID()]);
        if ($id) { Html::redirect(pd57_url('ticket', ['id' => $id, 'created' => 1])); }
        $error = 'Your request could not be saved. Please try again.';
    }
}
pd57_layout_start('Create a Request', 'request');
echo '<section class="hero compact"><div><p class="eyebrow">NEW REQUEST</p><h1>Tell us what you need.</h1><p>A few details help the right team pick this up quickly.</p></div></section>';
echo '<div class="form-grid"><section class="panel form-panel"><form method="post" id="request-form">';
echo '<input type="hidden" name="_glpi_csrf_token" value="' . pd57_h(Session::getNewCSRFToken()) . '">';
if ($error) { echo '<div class="alert" role="alert">' . pd57_h($error) . '</div>'; }
echo '<label for="name">Request title</label><input id="name" name="name" maxlength="180" required minlength="5" value="' . pd57_h($_POST['name'] ?? '') . '" placeholder="e.g. Studio Wi-Fi is not connecting">';
echo '<label for="content">Description</label><textarea id="content" name="content" rows="6" required minlength="10" placeholder="What happened, when, and how is it affecting your work?">' . pd57_h($_POST['content'] ?? '') . '</textarea>';
echo '<label for="location">Location</label><select id="location" name="locations_id"><option value="0">Choose a location (optional)</option>';
foreach ($DB->request(['FROM' => 'glpi_locations', 'ORDER' => ['completename ASC']]) as $location) {
    echo '<option value="' . (int)$location['id'] . '"' . ((int)($_POST['locations_id'] ?? 0) === (int)$location['id'] ? ' selected' : '') . '>' . pd57_h($location['completename']) . '</option>';
}
echo '</select><div class="field-heading"><label for="category">Category and department</label><button type="button" id="suggest-button" class="text-link">Suggest a category</button></div>';
echo '<div id="suggestions" class="suggestions" aria-live="polite">Describe your request, then choose a suggestion or select a category yourself.</div>';
echo '<select id="category" name="itilcategories_id" required><option value="">Choose or confirm a category</option>';
foreach ($DB->request(['FROM' => 'glpi_itilcategories', 'WHERE' => ['is_helpdeskvisible' => 1], 'ORDER' => ['completename ASC']]) as $category) {
    echo '<option value="' . (int)$category['id'] . '"' . ((int)($_POST['itilcategories_id'] ?? 0) === (int)$category['id'] ? ' selected' : '') . '>' . pd57_h($category['completename']) . '</option>';
}
echo '</select><p class="hint">Your selection controls routing. The suggested category is never applied without your choice.</p>';
echo '<button class="button button-primary submit" type="submit">Submit Request <span>↗</span></button></form></section>';
echo '<aside class="panel aside"><p class="eyebrow">WHAT HAPPENS NEXT</p><h2>A clear path to help.</h2><ol><li>You choose the best category.</li><li>We route the request to the right team.</li><li>Track status and response targets in My Requests.</li></ol><div class="aside-note">Unsure which team? Choose Other › Manual Triage.</div></aside></div>';
echo '<script src="/plugins/pd57portal/js/request.js" defer></script>';
pd57_layout_end();
