<?php
/** PD57 human classification decision endpoint. */
include('../../../inc/includes.php');
require_once dirname(__DIR__) . '/hook.php';
header('Content-Type: application/json');
Session::checkLoginUser();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}
Session::checkCSRF($_POST);
$result = plugin_pd57classifier_apply_suggestion(
    (string)($_POST['action'] ?? ''),
    (int)($_POST['suggestion_id'] ?? 0),
    (int)($_POST['ticket_id'] ?? 0),
    (int)($_POST['category_id'] ?? 0)
);
http_response_code($result['code']);
unset($result['code']);
echo json_encode($result);
