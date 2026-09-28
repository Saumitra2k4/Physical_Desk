<?php

/**
 * Physical Desk / PD57
 *
 * Closed-loop resolution helpers.
 *
 * Department proposes resolution.
 * Employee confirms or rejects.
 * Rejection reopens and escalates:
 *
 * L1/specialist -> L2 -> Lead -> Triage.
 */

function pd57_resolution_is_requester(
    int $ticketId,
    int $userId
): bool {
    global $DB;

    foreach (
        $DB->request([
            'FROM' => 'glpi_tickets_users',
            'WHERE' => [
                'tickets_id' => $ticketId,
                'users_id'   => $userId,
                'type'       => 1,
            ],
            'LIMIT' => 1,
        ]) as $unused
    ) {
        return true;
    }

    return false;
}


function pd57_resolution_followup(
    int $ticketId,
    string $content
): int {

    $followup = new ITILFollowup();

    $id = $followup->add([
        'itemtype'   => 'Ticket',
        'items_id'   => $ticketId,
        'content'    => $content,
        'is_private' => 0,
        // PD57 delivers the corresponding lifecycle message itself.  Keep the
        // framework notification system enabled for every other follow-up.
        '_disablenotif' => 1,
    ]);
    if (!$id) throw new RuntimeException('Could not save resolution history.');
    return (int)$id;
}


function pd57_resolution_assigned_groups(
    int $ticketId
): array {
    global $DB;

    $names = [];

    foreach (
        $DB->request([
            'FROM' => 'glpi_groups_tickets',
            'WHERE' => [
                'tickets_id' => $ticketId,
                'type'       => 2,
            ],
        ]) as $relation
    ) {

        $groupId = (int)($relation['groups_id'] ?? 0);

        if ($groupId <= 0) {
            continue;
        }

        $name = trim(
            (string)Dropdown::getDropdownName(
                'glpi_groups',
                $groupId
            )
        );

        if ($name !== '') {
            $names[] = $name;
        }
    }

    return array_values(
        array_unique($names)
    );
}


function pd57_resolution_escalation_target(
    array $groups
): string {

    $department = null;
    $hasL2 = false;
    $hasLead = false;

    foreach ($groups as $name) {

        if (
            preg_match(
                '/^PD57_(HR|PAYROLL|OPS|IT)_/i',
                $name,
                $match
            )
        ) {
            $department = strtoupper($match[1]);
        }

        if (
            preg_match(
                '/_L2$/i',
                $name
            )
        ) {
            $hasL2 = true;
        }

        if (
            preg_match(
                '/_LEAD$/i',
                $name
            )
        ) {
            $hasLead = true;
        }
    }

    if ($department === null) {
        return 'PD57_TRIAGE';
    }

    if ($hasLead) {
        return 'PD57_TRIAGE';
    }

    if ($hasL2) {
        return 'PD57_' .
            $department .
            '_LEAD';
    }

    return 'PD57_' .
        $department .
        '_L2';
}


function pd57_resolution_add_group(
    int $ticketId,
    string $groupName
): bool {
    global $DB;

    $groupId = 0;

    foreach (
        $DB->request([
            'FROM' => 'glpi_groups',
            'WHERE' => [
                'name' => $groupName,
            ],
            'LIMIT' => 1,
        ]) as $group
    ) {
        $groupId = (int)$group['id'];
    }

    if ($groupId <= 0) {
        return false;
    }

    foreach (
        $DB->request([
            'FROM' => 'glpi_groups_tickets',
            'WHERE' => [
                'tickets_id' => $ticketId,
                'groups_id'  => $groupId,
                'type'       => 2,
            ],
            'LIMIT' => 1,
        ]) as $unused
    ) {
        return true;
    }

    $relation = new Group_Ticket();

    return (bool)$relation->add([
        'tickets_id' => $ticketId,
        'groups_id'  => $groupId,
        'type'       => 2,
    ]);
}


function pd57_resolution_escalate(
    int $ticketId
): string {
    require_once __DIR__ . '/operations.php';
    $state = pd57_ops_escalate(
        $ticketId,
        'employee_rejection',
        'resolution_rejected'
    );
    return $state['designation'] ?? 'Employee Services Desk';
}

/**
 * One testable state transition shared by the existing web controller.
 * Locks the ticket so stale Yes/No submissions cannot repeat an escalation.
 * Notifications remain in the controller and run only after this commits.
 */
function pd57_resolution_apply_action(int $ticketId, string $action, string $note = '', ?DateTimeImmutable $now = null): array
{
    global $DB;
    require_once __DIR__ . '/operations.php';
    $userId = (int)Session::getLoginUserID();
    if ($userId < 1 || !in_array($action, ['propose', 'confirm', 'reject'], true)) {
        throw new RuntimeException('Not authorized.', 403);
    }
    $ticket = new Ticket();
    if (!$ticket->getFromDB($ticketId) || !$ticket->canViewItem()) throw new RuntimeException('Request unavailable.', 403);
    $requester = pd57_resolution_is_requester($ticketId, $userId);
    if (($action === 'propose' && ($requester || !$ticket->can($ticketId, UPDATE)))
        || ($action !== 'propose' && !$requester)) throw new RuntimeException('Not authorized.', 403);
    $note = trim(strip_tags($note));
    $note = function_exists('mb_substr') ? mb_substr($note, 0, 1500) : substr($note, 0, 1500);
    $now = pd57_ops_now($now); $at = $now->format('Y-m-d H:i:s');
    // Prepare active state before taking locks (legacy initialization may install schema).
    if ($action === 'propose' && !in_array((int)$ticket->fields['status'], [5, 6], true)) pd57_ops_state($ticketId, $now);
    $DB->beginTransaction();
    try {
        $DB->doQuery('SELECT `id` FROM `glpi_tickets` WHERE `id` = ' . $ticketId . ' FOR UPDATE');
        $ticket->getFromDB($ticketId);
        $status = (int)$ticket->fields['status'];
        if ($action === 'propose' && in_array($status, [5, 6], true)) {
            $DB->commit();
            return ['resolution' => $status === 6 ? 'already_closed' : 'already_proposed', 'notification' => null];
        }
        if ($action !== 'propose' && $status !== 5) throw new RuntimeException('This resolution has already been answered. Refresh the request.', 409);
        $nextStatus = ['propose' => 5, 'confirm' => 6, 'reject' => 2][$action];
        if (!$ticket->update(['id' => $ticketId, 'status' => $nextStatus, '_disablenotif' => 1])) throw new RuntimeException('Could not update request.');
        $state = $DB->request(['FROM' => PD57_OPS_STATE, 'WHERE' => ['tickets_id' => $ticketId], 'LIMIT' => 1])->current() ?: [];
        if ($action === 'reject') {
            $state = pd57_ops_escalate($ticketId, 'employee_rejection', 'resolution_rejected',
                $note ?: 'Employee rejected proposed resolution.', $userId, $now, null,
                isset($state['escalation_count']) ? (int)$state['escalation_count'] : null);
            $content = '[PD57_ESCALATION] Employee reported that the proposed resolution did not solve the issue. Request reopened and escalated to ' . $state['designation'] . '.';
            if ($note !== '') $content .= "\n\nWhat is still unresolved:\n" . $note;
        } elseif ($action === 'propose') {
            $content = '[PD57_RESOLUTION_PROPOSED] The handling team marked this request as resolved. Employee confirmation is required before closure.';
            if ($note !== '') $content .= "\n\nResolution note:\n" . $note;
        } else {
            $content = '[PD57_RESOLUTION_CONFIRMED] Employee confirmed that the request is resolved.';
        }
        $followupId = pd57_resolution_followup($ticketId, $content);
        if ($action === 'reject') {
            // Finish this still-uncommitted handoff with its exact public history source.
            $handoff = $DB->request(['FROM' => PD57_P4_HANDOFFS, 'WHERE' => ['tickets_id' => $ticketId],
                'ORDER' => ['handoff_sequence DESC'], 'LIMIT' => 1])->current();
            if (!$handoff) throw new RuntimeException('Rejection handoff was not captured.');
            $snapshot = json_decode((string)$handoff['snapshot_json'], true, 512, JSON_THROW_ON_ERROR);
            $snapshot['public_resolution_followup_id'] = $followupId;
            if (!$DB->update(PD57_P4_HANDOFFS, ['snapshot_json' => json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)], ['id' => (int)$handoff['id']])) {
                throw new RuntimeException('Could not link rejection history.');
            }
        }
        if ($action !== 'reject') {
            $type = $action === 'propose' ? 'resolution_proposed' : 'resolution_confirmed';
            pd57_ops_event($ticketId, $type, $ticketId . '|' . $type . '|followup|' . $followupId, [
                'actor' => $userId, 'from_person' => $state['people_id'] ?? null,
                'from_designation' => $state['designation'] ?? null,
                'from_level' => $state['designation_level'] ?? null,
                'note' => $content, 'at' => $at,
            ]);
        }
        $DB->commit();
        return ['resolution' => ['propose' => 'proposed', 'confirm' => 'confirmed', 'reject' => 'reopened'][$action],
            'designation' => $state['designation'] ?? 'Employee Services Desk',
            'person_id' => $state['people_id'] ?? null,
            'notification' => ['propose' => 'Resolution proposed', 'confirm' => 'Closed', 'reject' => 'Employee rejected resolution'][$action],
            'notification_key' => $ticketId . '|resolution|' . $action . '|followup|' . $followupId];
    } catch (Throwable $e) {
        $DB->rollBack();
        throw $e;
    }
}
