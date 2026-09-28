<?php
include('../../../inc/includes.php');
require_once dirname(__DIR__) . '/inc/portal.php';
require_once dirname(__DIR__) . '/inc/resolution.php';
Session::checkLoginUser();
// The existing GLPI controller listener validates _glpi_csrf_token before entry.
$id = (int)($_POST['id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $id < 1) {
    http_response_code(400); exit('Invalid request.');
}
$action = (string)($_POST['pd57_resolution_action'] ?? '');
$note = (string)($_POST[$action === 'reject' ? 'unresolved_note' : 'resolution_note'] ?? '');
try {
    $result = pd57_resolution_apply_action($id, $action, $note);
} catch (Throwable $e) {
    $code = in_array($e->getCode(), [400, 403, 404, 409], true) ? $e->getCode() : 500;
    http_response_code($code);
    exit($code === 500 ? 'The request could not be updated. Please contact support.' : htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
}
// Delivery is outside the status/history transaction. Preserve the existing transport.
if ($result['notification'] !== null) {
    // Proposal and closure are employee-facing; rejection is sent to the new owner.
    $person = in_array($result['resolution'], ['proposed', 'confirmed'], true) ? null
        : (!empty($result['person_id']) ? pd57_admin_person((int)$result['person_id']) : null);
    try {
        pd57_p4_deliver_notification($id, $result['notification'], $result['notification_key'], $person,
            ['designation' => $result['designation'] ?? 'Employee Services Desk']);
    } catch (Throwable $e) {
        error_log('PD57: resolution saved; notification delivery failed.');
    }
}
Html::redirect('/plugins/pd57portal/front/ticket.php?id=' . $id . '&resolution=' . rawurlencode($result['resolution']));
