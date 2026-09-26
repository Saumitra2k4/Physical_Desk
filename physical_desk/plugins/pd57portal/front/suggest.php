<?php
require_once dirname(__DIR__) . '/inc/portal.php';
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || pd57_is_agent()) { http_response_code(403); echo '{}'; exit; }
require_once dirname(__DIR__, 2) . '/pd57classifier/hook.php';
$text = trim((string)($_POST['text'] ?? ''));
if (mb_strlen($text) < 10 || mb_strlen($text) > 4000) { http_response_code(400); echo json_encode(['error' => 'Enter a description first.']); exit; }
$result = plugin_pd57classifier_call_service(strip_tags($text));
echo json_encode(['suggestions' => array_map(static fn($row) => ['category_id' => $row['category_id'], 'path' => $row['path']], $result['suggestions'])]);
