<?php
/** Read an existing ticket and classifier metadata after container recreation. */
require_once __DIR__ . '/vendor/autoload.php';
$kernel = new \Glpi\Kernel\Kernel(); $kernel->boot();
$id = (int)($argv[1] ?? 0);
if ($id <= 0) throw new InvalidArgumentException('Ticket ID required');
$ticket = new Ticket();
if (!$ticket->getFromDB($id)) throw new RuntimeException('Ticket missing after recreation');
global $DB;
$record = $DB->request(['FROM' => 'glpi_plugin_pd57classifier_suggestions',
    'WHERE' => ['tickets_id' => $id], 'ORDER' => ['rank ASC'], 'LIMIT' => 1])->current();
if (!$record) throw new RuntimeException('Classifier metadata missing after recreation');
$triage = $DB->request(['FROM' => 'glpi_itilcategories',
    'WHERE' => ['completename' => 'Other > Manual Triage'], 'LIMIT' => 1])->current();
$ok = (int)$ticket->fields['itilcategories_id'] === (int)$triage['id']
    && (int)$record['fallback_used'] === 1 && !empty($record['failure_reason']);
echo json_encode(['ticket_id' => $id, 'ticket_persisted' => true,
    'classifier_metadata_persisted' => true, 'fallback_preserved' => $ok]) . PHP_EOL;
exit($ok ? 0 : 1);
