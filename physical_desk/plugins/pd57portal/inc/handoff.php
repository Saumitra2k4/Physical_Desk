<?php
/**
 * PD57 handoff history. No routing decisions, mail delivery or runtime DDL.
 * Public content and staff-only operational records are projected separately.
 */

function pd57_p4_handoff_in_transaction(): bool
{
    global $DB;
    $result = $DB->doQuery('SELECT @@in_transaction AS active');
    $row = $DB->fetchAssoc($result);
    return (int)($row['active'] ?? 0) === 1;
}

/** Run explicitly during installation, never from a live escalation transaction. */
function pd57_p4_handoff_install_schema(): void
{
    global $DB;
    if (pd57_p4_handoff_in_transaction()) {
        throw new RuntimeException('Run the handoff migration outside a transaction.');
    }
    if (!$DB->tableExists(PD57_P4_HANDOFFS)) {
        // The ZIP defines this table already, but a local DB may predate that initializer.
        // Create only the handoff table; never run the broader demo-person seeder here.
        $DB->doQuery('CREATE TABLE IF NOT EXISTS `' . PD57_P4_HANDOFFS . '` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `tickets_id` INT UNSIGNED NOT NULL, `handoff_sequence` INT UNSIGNED NOT NULL,
            `escalation_type` VARCHAR(40) NOT NULL,
            `from_people_id` INT UNSIGNED NULL, `from_designation_level` TINYINT UNSIGNED NULL,
            `from_designation_label` VARCHAR(120) NOT NULL, `from_team` VARCHAR(120) NULL,
            `to_people_id` INT UNSIGNED NULL, `to_designation_level` TINYINT UNSIGNED NULL,
            `to_designation_label` VARCHAR(120) NOT NULL, `to_team` VARCHAR(120) NULL,
            `department` VARCHAR(80) NOT NULL, `reason_code` VARCHAR(80) NULL,
            `reason_text` VARCHAR(255) NULL, `internal_handoff_note` TEXT NULL,
            `priority_at_handoff` VARCHAR(12) NULL, `previous_level_started_at` DATETIME NULL,
            `handed_off_at` DATETIME NOT NULL, `source_operational_event_id` BIGINT UNSIGNED NULL,
            `created_by_users_id` INT UNSIGNED NULL, `dedupe_key` VARCHAR(190) NOT NULL,
            `snapshot_json` LONGTEXT NULL, `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `handoff_dedupe` (`dedupe_key`),
            UNIQUE KEY `handoff_sequence_once` (`tickets_id`, `handoff_sequence`),
            KEY `ticket_handoff` (`tickets_id`, `handed_off_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }
    if (!$DB->fieldExists(PD57_P4_HANDOFFS, 'snapshot_json')) {
        $DB->doQuery('ALTER TABLE `' . PD57_P4_HANDOFFS . '` ADD COLUMN `snapshot_json` LONGTEXT NULL');
    }
}

function pd57_p4_history_text($value): string
{
    // Decode first so encoded tags cannot become markup after sanitization.
    $text = html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('~<(?:br\s*/?|/p|/div|/li)>~i', "\n", $text);
    return trim(strip_tags($text));
}

function pd57_p4_history_rows(string $table, array $where, array $order = ['id ASC']): array
{
    global $DB;
    if (!$DB->tableExists($table)) {
        return [];
    }
    return array_values(iterator_to_array($DB->request(['FROM' => $table, 'WHERE' => $where, 'ORDER' => $order])));
}

function pd57_p4_history_at(array $row): string
{
    return (string)($row['date'] ?? $row['assigned_at'] ?? $row['created_at'] ?? $row['date_creation'] ?? '');
}

function pd57_p4_history_reason(string $code): string
{
    return [
        'baseline_policy_correction' => 'Baseline priority corrected by policy',
        'resolution_rejected' => 'Employee reported that the resolution did not work',
        'reliable_existing_pd57_marker' => 'Imported from existing request history',
        'unattended_critical_stage' => 'No meaningful update during the Critical working-time stage',
        'no_progress' => 'No meaningful progress within the configured working-time limit',
        'no_eligible_higher_designation' => 'No eligible higher designation was available',
        'manual_employee_services_desk' => 'Referred to the Employee Services Desk',
        'additional_expertise_required' => 'Additional expertise required',
        'specialist_needed' => 'Additional expertise required',
    ][$code] ?? (function_exists('pd57_ops_reason_label') ? pd57_ops_reason_label($code) : str_replace('_', ' ', $code));
}

/** Check the actual logged-in user's ticket permission before fetching private history. */
function pd57_p4_handoff_authorize(int $ticketId, bool $internal): Ticket
{
    global $DB;
    if (!class_exists('Session') || (int)Session::getLoginUserID() < 1) {
        throw new RuntimeException('Request unavailable.', 403);
    }
    $ticket = new Ticket();
    if (!$ticket->getFromDB($ticketId) || !$ticket->canViewItem()) {
        throw new RuntimeException('Request unavailable.', 403);
    }
    $support = (function_exists('pd57_is_agent') && pd57_is_agent())
        || (function_exists('pd57_admin_is_authorized') && pd57_admin_is_authorized());
    if ($internal && !$support) {
        throw new RuntimeException('Staff-only request history.', 403);
    }
    if (!$internal && !$support) {
        $linked = $DB->request(['FROM' => 'glpi_tickets_users', 'WHERE' => [
            'tickets_id' => $ticketId, 'users_id' => (int)Session::getLoginUserID(), 'type' => 1,
        ], 'LIMIT' => 1])->current();
        if (!$linked) {
            throw new RuntimeException('Request unavailable.', 403);
        }
    }
    return $ticket;
}

function pd57_p4_can_view_internal_handoff(int $ticketId): bool
{
    try {
        pd57_p4_handoff_authorize($ticketId, true);
        return true;
    } catch (RuntimeException $e) {
        if ($e->getCode() !== 403) {
            throw $e;
        }
        return false;
    }
}

function pd57_p4_handoff_team(array $state): string
{
    global $DB;
    if (!empty($state['team_name'])) {
        return (string)$state['team_name'];
    }
    if (!empty($state['teams_id'])) {
        $team = $DB->request(['FROM' => PD57_P4_TEAMS, 'WHERE' => ['id' => (int)$state['teams_id']], 'LIMIT' => 1])->current();
        if ($team) {
            return (string)$team['name'];
        }
    }
    return pd57_p4_hierarchy((int)($state['tickets_id'] ?? 0))['team'];
}

function pd57_p4_dossier_people(array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    $out = [];
    if ($ids) {
        foreach (pd57_p4_history_rows(PD57_ADMIN_TABLE_PEOPLE, ['id' => $ids]) as $person) {
            $out[(int)$person['id']] = $person;
        }
    }
    return $out;
}

/**
 * Requires the caller's row-locked escalation transaction. A real operational
 * event ID is the identity; replaying it returns the original immutable row.
 */
function pd57_p4_record_handoff(int $ticketId, string $type, array $from, array $owner, array $event, string $reason, ?string $note, ?int $actor, string $at): ?array
{
    global $DB;
    if (!pd57_p4_handoff_in_transaction()) {
        throw new RuntimeException('Handoff capture requires the escalation transaction.');
    }
    if (!$DB->fieldExists(PD57_P4_HANDOFFS, 'snapshot_json')) {
        throw new RuntimeException('Apply the handoff migration before escalating requests.');
    }
    $eventId = (int)($event['id'] ?? 0);
    if ($eventId < 1 || (int)($event['tickets_id'] ?? 0) !== $ticketId || ($event['event_type'] ?? '') !== $type) {
        throw new RuntimeException('A matching persisted escalation event is required.');
    }
    $DB->doQuery('SELECT `id` FROM `' . PD57_OPS_STATE . '` WHERE `tickets_id` = ' . $ticketId . ' FOR UPDATE');
    $existing = $DB->request(['FROM' => PD57_P4_HANDOFFS, 'WHERE' => [
        'tickets_id' => $ticketId, 'source_operational_event_id' => $eventId,
    ], 'LIMIT' => 1])->current();
    if ($existing) {
        return $existing;
    }
    $previous = $DB->request(['FROM' => PD57_P4_HANDOFFS, 'WHERE' => ['tickets_id' => $ticketId],
        'ORDER' => ['handoff_sequence DESC'], 'LIMIT' => 1])->current();
    $department = pd57_p4_display_department((string)($owner['department'] ?? $from['department'] ?? 'Employee Services Desk'));
    $fromLevel = (int)($from['designation_level'] ?? 0);
    $toLevel = (int)($owner['designation_level'] ?? 0);
    $ids = [(int)($from['people_id'] ?? 0), (int)($owner['person']['id'] ?? 0)];
    $people = pd57_p4_dossier_people($ids);
    $calendar = function_exists('pd57_ops_calendar') ? pd57_ops_calendar() : null;
    $requestRow = $DB->request(['FROM' => 'glpi_tickets', 'WHERE' => ['id' => $ticketId], 'LIMIT' => 1])->current() ?: [];
    $requesterSnapshot = [];
    foreach (pd57_p4_history_rows('glpi_tickets_users', ['tickets_id' => $ticketId, 'type' => 1]) as $link) {
        $uid = (int)$link['users_id'];
        $user = $DB->request(['FROM' => 'glpi_users', 'WHERE' => ['id' => $uid], 'LIMIT' => 1])->current() ?: [];
        $mapped = pd57_p4_history_rows(PD57_ADMIN_TABLE_PEOPLE, ['linked_users_id' => $uid]);
        $requesterSnapshot[] = ['name' => trim(($user['firstname'] ?? '') . ' ' . ($user['realname'] ?? '')) ?: ($user['name'] ?? 'Unknown'),
            'employee_id' => count($mapped) === 1 ? (($mapped[0]['employee_id'] ?? '') ?: 'Not available') : 'Not available'];
    }
    $snapshot = [
        'version' => 1,
        'request' => ['title' => pd57_p4_history_text($requestRow['name'] ?? ''),
            'description' => pd57_p4_history_text($requestRow['content'] ?? ''),
            'created_at' => $requestRow['date'] ?? $requestRow['date_creation'] ?? null,
            'location' => !empty($requestRow['locations_id']) ? Dropdown::getDropdownName('glpi_locations', (int)$requestRow['locations_id']) : 'Not available'],
        'requesters' => $requesterSnapshot,
        'captured_at' => $at,
        'from_person_name' => $people[$ids[0]]['display_name'] ?? null,
        'to_person_name' => $people[$ids[1]]['display_name'] ?? null,
        'from_assignment_id' => $from['assignment_history_id'] ?? null,
        'classification' => pd57_p4_current_classification($ticketId),
        'baseline_priority' => $from['baseline_priority'] ?? null,
        'last_meaningful_update_at' => $from['last_meaningful_update_at'] ?? null,
        'reminder_count_total' => (int)($from['reminder_count'] ?? 0),
        'stage_reminder_count' => (int)($from['stage_reminder_count'] ?? 0),
        'calendar' => $calendar,
    ];
    $row = [
        'tickets_id' => $ticketId,
        'handoff_sequence' => (int)($previous['handoff_sequence'] ?? 0) + 1,
        'escalation_type' => $type,
        'from_people_id' => $ids[0] ?: null,
        'from_designation_level' => $fromLevel ?: null,
        'from_designation_label' => pd57_p4_display_designation((string)($from['department'] ?? $department), $fromLevel, (string)($from['designation'] ?? '')),
        'from_team' => pd57_p4_handoff_team($from),
        'to_people_id' => $ids[1] ?: null,
        'to_designation_level' => $toLevel ?: null,
        'to_designation_label' => pd57_p4_visible_designation_label((string)($owner['designation'] ?? 'Employee Services Desk')),
        'to_team' => $owner['team_name'] ?? $owner['team']['name'] ?? 'Employee Services Desk',
        'department' => $department,
        'reason_code' => $reason,
        'reason_text' => pd57_p4_history_reason($reason),
        'internal_handoff_note' => $note ?: null,
        'priority_at_handoff' => $from['current_priority'] ?? null,
        'previous_level_started_at' => $from['level_started_at'] ?? null,
        'handed_off_at' => $at,
        'source_operational_event_id' => $eventId,
        'created_by_users_id' => $actor ?: null,
        'dedupe_key' => $ticketId . '|handoff|event|' . $eventId,
        'snapshot_json' => json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        'created_at' => $at,
    ];
    if (!$DB->insert(PD57_P4_HANDOFFS, $row)) {
        throw new RuntimeException('Could not persist the escalation handoff.');
    }
    return $DB->request(['FROM' => PD57_P4_HANDOFFS, 'WHERE' => ['id' => (int)$DB->insertId()], 'LIMIT' => 1])->current() ?: null;
}

/** Pure presentation classification of legacy public followups, never a backfill write. */
function pd57_p4_history_followup_kind(string $text): string
{
    if (str_starts_with($text, '[PD57_RESOLUTION_PROPOSED]')) return 'resolution_proposed';
    if (str_starts_with($text, '[PD57_RESOLUTION_CONFIRMED]')) return 'resolution_confirmed';
    if (str_starts_with($text, '[PD57_ESCALATION]')) return 'escalation';
    if (str_starts_with($text, '[PD57_')) return 'system';
    return 'update';
}

/** Metadata-only correspondence used to de-duplicate mirrored followup/event rows. */
function pd57_p4_history_matches(array $event, array $followup): bool
{
    $key = (string)($event['event_key'] ?? '');
    if (str_ends_with($key, '|followup|' . (int)$followup['id'])) return true;
    if (str_contains($key, '|backfill|') && str_ends_with($key, '|' . (int)$followup['id'])) return true;
    $a = pd57_p4_history_text($event['note'] ?? '');
    $b = pd57_p4_history_text($followup['content'] ?? '');
    $ea = strtotime((string)($event['created_at'] ?? ''));
    $fa = strtotime(pd57_p4_history_at($followup));
    return $a !== '' && $ea !== false && $fa !== false && abs($ea - $fa) <= 2
        && ($a === $b || (strlen($a) > 10 && str_contains($b, $a)));
}

/** Uses user IDs ONLY to look up users/linked people. It never treats them as person IDs. */
function pd57_p4_history_author(int $userId, string $at, array $users, array $people, array $assignments): array
{
    $user = $users[$userId] ?? [];
    $name = trim(($user['firstname'] ?? '') . ' ' . ($user['realname'] ?? ''));
    $name = $name ?: ($user['name'] ?? 'Unknown author');
    $candidates = [];
    foreach ($assignments as $a) {
        $person = $people[(int)($a['people_id'] ?? 0)] ?? null;
        if ($person && (int)($person['linked_users_id'] ?? 0) === $userId
            && $at !== '' && $a['started_at'] !== '' && $at >= $a['started_at']
            && (!$a['ended_at'] || $at < $a['ended_at'])) {
            $candidates[(int)$person['id']] = ['name' => $a['person_name'], 'designation' => $a['designation']];
        }
    }
    if (count($candidates) === 1) return reset($candidates);
    return ['name' => $name, 'designation' => null];
}

function pd57_p4_handoff_dossier(int $ticketId): array
{
    $ticket = pd57_p4_handoff_authorize($ticketId, true);
    return pd57_p4_build_handoff_projection($ticket, true);
}

function pd57_p4_employee_safe_dossier(int $ticketId): array
{
    $ticket = pd57_p4_handoff_authorize($ticketId, false);
    return pd57_p4_build_handoff_projection($ticket, false);
}

/**
 * Both callers are permission-gated; this boundary also checks permission to
 * prevent accidental use as an unguarded data API by future callers.
 */
function pd57_p4_build_handoff_projection(Ticket $ticket, bool $internal): array
{
    global $DB;
    $id = (int)$ticket->fields['id'];
    pd57_p4_handoff_authorize($id, $internal);
    $c = pd57_p4_current_classification($id) ?: [];
    $state = pd57_p4_history_rows(PD57_OPS_STATE, ['tickets_id' => $id])[0] ?? [];
    $followups = pd57_p4_history_rows('glpi_itilfollowups', ['itemtype' => 'Ticket', 'items_id' => $id, 'is_private' => 0], ['date ASC', 'id ASC']);
    $links = pd57_p4_history_rows('glpi_tickets_users', ['tickets_id' => $id, 'type' => 1]);
    $userIds = array_column($followups, 'users_id');
    $userIds = array_values(array_unique(array_filter(array_merge($userIds, array_column($links, 'users_id')))));
    $users = [];
    foreach ($userIds ? pd57_p4_history_rows('glpi_users', ['id' => $userIds]) : [] as $u) $users[(int)$u['id']] = $u;
    $request = [
        'title' => pd57_p4_history_text($ticket->fields['name'] ?? ''),
        'description' => pd57_p4_history_text($ticket->fields['content'] ?? ''),
        'created_at' => $ticket->fields['date'] ?? $ticket->fields['date_creation'] ?? null,
        'location' => !empty($ticket->fields['locations_id']) ? Dropdown::getDropdownName('glpi_locations', (int)$ticket->fields['locations_id']) : 'Not available',
    ];
    $confirmed = [
        'department' => pd57_p4_display_department((string)($c['final_department'] ?? $c['suggested_department'] ?? $state['department'] ?? 'Employee Services Desk')),
        'team' => $c['final_team'] ?? $c['suggested_team'] ?? 'Not available',
        'request_type' => $c['final_request_type'] ?? $c['suggested_request_type'] ?? 'Not available',
    ];
    $reference = 'PD57-' . str_pad((string)$id, 7, '0', STR_PAD_LEFT);
    if (!$internal) {
        // Allowlist only. No private event/note, employee/person ID, routing stats
        // or handoff snapshot is queried for this employee projection.
        $timeline = [];
        foreach ($followups as $f) {
            $text = pd57_p4_history_text($f['content']);
            $kind = pd57_p4_history_followup_kind($text);
            if ($text === '' || $kind === 'system') continue;
            if ($kind !== 'update') $text = pd57_p4_visible_system_text($text, $confirmed['department']);
            $timeline[] = ['at' => pd57_p4_history_at($f), 'type' => $kind, 'content' => $text];
        }
        return ['request_reference' => $reference, 'request' => $request, 'classification' => $confirmed,
            'current_priority' => $state['current_priority'] ?? null, 'timeline' => $timeline];
    }

    $events = pd57_p4_history_rows(PD57_OPS_EVENTS, ['tickets_id' => $id], ['created_at ASC', 'id ASC']);
    $actorIds = array_values(array_unique(array_filter(array_column($events, 'actor_users_id'))));
    $missingActors = array_values(array_diff($actorIds, array_keys($users)));
    foreach ($missingActors ? pd57_p4_history_rows('glpi_users', ['id' => $missingActors]) : [] as $u) $users[(int)$u['id']] = $u;
    $assignments = pd57_p4_history_rows(PD57_P4_ASSIGNMENTS, ['tickets_id' => $id], ['assigned_at ASC', 'id ASC']);
    $handoffs = pd57_p4_history_rows(PD57_P4_HANDOFFS, ['tickets_id' => $id], ['handoff_sequence ASC']);
    $peopleIds = [];
    foreach (array_merge($events, $assignments, $handoffs, [$state]) as $row) {
        foreach (['people_id', 'from_people_id', 'to_people_id'] as $field) if (!empty($row[$field])) $peopleIds[] = (int)$row[$field];
    }
    $people = pd57_p4_dossier_people($peopleIds);
    $requesters = [];
    foreach ($links as $link) {
        $uid = (int)$link['users_id'];
        $u = $users[$uid] ?? [];
        $mapped = pd57_p4_history_rows(PD57_ADMIN_TABLE_PEOPLE, ['linked_users_id' => $uid]);
        $employeeId = count($mapped) === 1 ? (($mapped[0]['employee_id'] ?? '') ?: 'Not available') : 'Not available';
        $requesters[] = ['name' => trim(($u['firstname'] ?? '') . ' ' . ($u['realname'] ?? '')) ?: ($u['name'] ?? 'Unknown'), 'employee_id' => $employeeId];
    }
    foreach ($handoffs as &$h) {
        $snapshot = json_decode((string)($h['snapshot_json'] ?? ''), true) ?: [];
        // Snapshot names take precedence even when the person is renamed later.
        $h['from_name'] = array_key_exists('from_person_name', $snapshot) ? ($snapshot['from_person_name'] ?: 'Unassigned / unknown') : ($people[(int)($h['from_people_id'] ?? 0)]['display_name'] ?? 'Unassigned / unknown');
        $h['to_name'] = array_key_exists('to_person_name', $snapshot) ? ($snapshot['to_person_name'] ?: 'Unassigned / unknown') : ($people[(int)($h['to_people_id'] ?? 0)]['display_name'] ?? 'Unassigned / unknown');
        $h['name_evidence'] = $snapshot ? 'Captured at handoff' : 'Current record where available; historical name not captured';
        $h['snapshot'] = $snapshot;
        $h['from_designation_label'] = pd57_p4_visible_designation_label((string)$h['from_designation_label']);
        $h['to_designation_label'] = pd57_p4_visible_designation_label((string)$h['to_designation_label']);
        $h['working_minutes'] = pd57_p4_history_working_minutes($h['previous_level_started_at'] ?? null, $h['handed_off_at'], $snapshot['calendar'] ?? null);
    }
    unset($h);

    // Ordered assignments, with immutable names where captured by a handoff.
    foreach ($assignments as $i => &$a) {
        $a['started_at'] = (string)($a['assigned_at'] ?? $a['created_at'] ?? '');
        $a['ended_at'] = $assignments[$i + 1]['assigned_at'] ?? $assignments[$i + 1]['created_at'] ?? null;
        $a['person_name'] = $people[(int)($a['people_id'] ?? 0)]['display_name'] ?? 'Unassigned / unknown';
        $a['designation'] = pd57_p4_visible_designation_label((string)($a['designation'] ?? ''));
        $a['team'] = $a['team_name'] ?? 'Not available';
        foreach ($handoffs as $h) {
            if ((int)($h['snapshot']['from_assignment_id'] ?? 0) === (int)$a['id']) {
                $a['person_name'] = $h['from_name'];
                $a['ended_at'] = $h['handed_off_at'];
            } elseif ($a['started_at'] === $h['handed_off_at'] && (int)($a['people_id'] ?? 0) === (int)($h['to_people_id'] ?? 0)) {
                $a['person_name'] = $h['to_name'];
            }
        }
        $a['recorded_reminders'] = 0;
        foreach ($events as $e) {
            if ($e['event_type'] === 'reminder' && $e['created_at'] >= $a['started_at'] && (!$a['ended_at'] || $e['created_at'] < $a['ended_at'])) ++$a['recorded_reminders'];
        }
    }
    unset($a);

    $resolutionFollowupByEvent = [];
    foreach ($handoffs as $h) {
        if (!empty($h['source_operational_event_id']) && !empty($h['snapshot']['public_resolution_followup_id'])) {
            $resolutionFollowupByEvent[(int)$h['source_operational_event_id']] = (int)$h['snapshot']['public_resolution_followup_id'];
        }
    }
    $updates = []; $resolutions = []; $legacy = []; $used = [];
    foreach ($followups as $f) {
        $text = pd57_p4_history_text($f['content']);
        if ($text === '') continue;
        $kind = pd57_p4_history_followup_kind($text);
        $author = pd57_p4_history_author((int)$f['users_id'], pd57_p4_history_at($f), $users, $people, $assignments);
        $entry = ['source' => 'public_followup', 'source_id' => (int)$f['id'], 'at' => pd57_p4_history_at($f), 'type' => $kind, 'content' => $text, 'author' => $author['name'], 'designation' => $author['designation']];
        if ($kind === 'update') $updates[] = $entry;
        elseif ($kind !== 'system') {
            $entry['content'] = pd57_p4_visible_system_text($text, $confirmed['department']);
            $resolutions[] = $entry;
            if ($kind === 'escalation') $legacy[] = $entry;
        }
    }
    $priority = [];
    foreach ($events as $e) {
        if ($e['event_type'] === 'priority_changed') {
            $priority[] = ['at' => $e['created_at'], 'from' => $e['from_priority'], 'to' => $e['to_priority'], 'reason' => pd57_p4_history_reason((string)($e['reason_code'] ?? '')), 'source' => 'operational_event'];
        }
        if (!in_array($e['event_type'], ['meaningful_update', 'resolution_proposed', 'resolution_confirmed', 'employee_rejection'], true)) continue;
        $matched = false;
        foreach ($followups as $f) {
            $kind = pd57_p4_history_followup_kind(pd57_p4_history_text($f['content']));
            $compatible = ($e['event_type'] === 'meaningful_update' && $kind === 'update')
                || $e['event_type'] === $kind || ($e['event_type'] === 'employee_rejection' && $kind === 'escalation');
            if ($compatible && !isset($used[$e['event_type'] . ':' . $f['id']]) && (($resolutionFollowupByEvent[(int)$e['id']] ?? 0) === (int)$f['id'] || pd57_p4_history_matches($e, $f))) {
                $used[$e['event_type'] . ':' . $f['id']] = true; $matched = true; break;
            }
        }
        if ($matched) continue;
        // Exclude an operational mirror of a private followup, even for the public work list.
        if (preg_match('/\|followup\|(\d+)$/', (string)$e['event_key'], $m)) {
            $linked = $DB->request(['FROM' => 'glpi_itilfollowups', 'WHERE' => ['id' => (int)$m[1]], 'LIMIT' => 1])->current();
            if ($linked && !empty($linked['is_private'])) continue;
        }
        $author = pd57_p4_history_author((int)($e['actor_users_id'] ?? 0), $e['created_at'], $users, $people, $assignments);
        $entry = ['source' => 'operational_event', 'source_id' => (int)$e['id'], 'type' => $e['event_type'], 'at' => $e['created_at'], 'content' => pd57_p4_history_text($e['note'] ?? ''), 'author' => $author['name'], 'designation' => $author['designation']];
        if ($e['event_type'] === 'meaningful_update') {
            if ($entry['content'] !== '') $updates[] = $entry;
        } else $resolutions[] = $entry;
    }
    $sort = static fn(array $a, array $b): int => [$a['at'], $a['source'], $a['source_id']] <=> [$b['at'], $b['source'], $b['source_id']];
    usort($updates, $sort); usort($resolutions, $sort);
    $reminders = array_values(array_filter($events, static fn($e) => $e['event_type'] === 'reminder'));
    $ownership = pd57_p4_ticket_ownership($id, $state ?: null);
    $requestEvidence = 'Current stored request; no earlier content snapshot is available.';
    foreach ($handoffs as $handoff) {
        if (isset($handoff['snapshot']['request'])) {
            $request = $handoff['snapshot']['request'];
            $requesters = $handoff['snapshot']['requesters'] ?? $requesters;
            $requestEvidence = 'Request content and requester details captured at the first recorded handoff.';
            break;
        }
    }
    return [
        'request_content_evidence' => $requestEvidence,
        'request_reference' => $reference, 'request' => $request,
        'requester' => $requesters[0] ?? ['name' => 'Not available', 'employee_id' => 'Not available'], 'requesters' => $requesters,
        'classification' => ['suggested' => ['department' => $c['suggested_department'] ?? 'Not available', 'team' => $c['suggested_team'] ?? 'Not available', 'request_type' => $c['suggested_request_type'] ?? 'Not available'], 'confirmed' => $confirmed],
        'initial_priority' => $priority[0]['from'] ?? $state['baseline_priority'] ?? null,
        'initial_priority_evidence' => $priority ? 'Earliest recorded priority change' : 'Current baseline; initial history may be unavailable',
        'current_state' => $state, 'current_ownership' => $ownership,
        'meaningful_updates' => $updates, 'priority_history' => $priority, 'ownership_history' => $assignments,
        'handoffs' => $handoffs, 'legacy_escalation_evidence' => $legacy, 'events' => $events,
        'reminders' => $reminders, 'resolution_history' => $resolutions,
        'next_operational_threshold' => ($state['current_priority'] ?? 'Medium') === 'Critical'
            ? 'Automatic ownership escalation after the configured unattended Critical stage.'
            : 'Priority increases after the configured unattended working-time stage.',
    ];
}

/** Snapshot calendar calculation; no changed policy is presented as historical fact. */
function pd57_p4_history_working_minutes(?string $from, ?string $to, ?array $calendar): ?int
{
    if (!$from || !$to || !$calendar) return null;
    try {
        $tz = new DateTimeZone($calendar['timezone']);
        $a = new DateTimeImmutable($from, new DateTimeZone('UTC'));
        $b = new DateTimeImmutable($to, new DateTimeZone('UTC'));
        if ($b < $a) return null;
        $cursor = $a->setTimezone($tz)->setTime(0, 0);
        $end = $b->setTimezone($tz)->setTime(0, 0);
        $days = array_map('intval', explode(',', $calendar['working_days']));
        $minutes = 0;
        for ($i = 0; $cursor <= $end; $cursor = $cursor->modify('+1 day'), ++$i) {
            if ($i > 36600) return null;
            if (!in_array((int)$cursor->format('N'), $days, true)) continue;
            $s = new DateTimeImmutable($cursor->format('Y-m-d') . ' ' . $calendar['start_time'], $tz);
            $e = new DateTimeImmutable($cursor->format('Y-m-d') . ' ' . $calendar['end_time'], $tz);
            $minutes += max(0, (int)((min($e->getTimestamp(), $b->getTimestamp()) - max($s->getTimestamp(), $a->getTimestamp())) / 60));
        }
        return $minutes;
    } catch (Exception $e) {
        return null;
    }
}
