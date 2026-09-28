<?php
/** Staff-only rendering. Call with the permission-checked handoff projection. */
function pd57_p4_render_handoff_panel(array $dossier): void
{
    $h = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $value = static fn($v): string => $v === null || $v === '' ? 'Not available' : (string)$v;
    $text = static fn($v): string => nl2br(htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    $owner = $dossier['current_ownership'];
    $state = $dossier['current_state'];
    echo '<style>
.pd57-handoff{margin:16px 0;min-width:0;overflow-wrap:anywhere}
.pd57-handoff h2{margin:0 0 12px}.pd57-handoff h3{margin:16px 0 8px}
.pd57-handoff .handoff-facts{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px 24px;margin:16px 0}
.pd57-handoff dt{font-size:.78rem;font-weight:700;color:#53616a}.pd57-handoff dd{margin:4px 0 0}
.pd57-handoff details{border-top:1px solid #dce3e8;padding:16px 0}.pd57-handoff summary{cursor:pointer;font-weight:700;line-height:1.5}
.pd57-handoff summary:focus-visible{outline:2px solid #007a97;outline-offset:3px}
.pd57-handoff article{border-left:2px solid #cad7df;padding:0 0 4px 16px;margin:18px 0;min-width:0}
.pd57-handoff time{font-size:.8rem;color:#53616a}.pd57-handoff article p{margin:6px 0}
.pd57-handoff .handoff-note{padding:12px;border:1px solid #dce3e8;border-radius:6px;background:#f6f8fa}
@media(max-width:760px){.pd57-handoff .handoff-facts{grid-template-columns:minmax(0,1fr)}}
</style>';
    echo '<section class="panel pd57-handoff" aria-labelledby="pd57-handoff-title">';
    echo '<p class="eyebrow">STAFF-ONLY REQUEST HISTORY</p><h2 id="pd57-handoff-title">Ownership &amp; handoff history</h2>';
    echo '<p class="muted">The complete recorded history follows the request. Private handoff notes are not shown to employees. Times are displayed as recorded.</p>';
    $facts = [
        'Reference' => $dossier['request_reference'], 'Current owner' => $owner['owner_name'],
        'Designation' => $owner['designation'], 'Department' => $dossier['classification']['confirmed']['department'],
        'Team' => $dossier['classification']['confirmed']['team'], 'Request type' => $dossier['classification']['confirmed']['request_type'],
        'Current priority' => $state['current_priority'] ?? null, 'Earliest recorded priority' => $dossier['initial_priority'],
        'Last meaningful update' => $state['last_meaningful_update_at'] ?? null,
        'Recorded reminders' => count($dossier['reminders']),
    ];
    echo '<dl class="handoff-facts">';
    foreach ($facts as $label => $v) echo '<div><dt>' . $h($label) . '</dt><dd>' . $h($value($v)) . '</dd></div>';
    echo '</dl><p>' . $h($dossier['next_operational_threshold']) . '</p>';
    echo '<details><summary>Original request &amp; requester</summary><h3>' . $h($dossier['request']['title']) . '</h3><p>' . $text($dossier['request']['description']) . '</p>';
    echo '<p class="muted">' . $h($dossier['request_content_evidence']) . '</p>';
    echo '<p>Submitted: ' . $h($value($dossier['request']['created_at'])) . ' | Location: ' . $h($dossier['request']['location']) . '</p>';
    foreach ($dossier['requesters'] as $requester) echo '<p>' . $h($requester['name']) . ' | Employee ID: ' . $h($requester['employee_id']) . '</p>';
    echo '<p>System suggestion: ' . $h(implode(' / ', $dossier['classification']['suggested'])) . '</p><p>Confirmed classification: ' . $h(implode(' / ', $dossier['classification']['confirmed'])) . '</p></details>';
    echo '<details open><summary>Handoffs (' . count($dossier['handoffs']) . ')</summary>';
    if (!$dossier['handoffs']) echo '<p class="muted">No captured handoffs yet. Earlier evidence, where available, appears in resolution and legacy history below.</p>';
    foreach ($dossier['handoffs'] as $entry) {
        $type = ['manual_escalation' => 'Manual escalation', 'automatic_escalation' => 'Automatic escalation', 'employee_rejection' => 'Employee reported unresolved issue'][$entry['escalation_type']] ?? 'Ownership changed';
        echo '<article><time>' . $h($entry['handed_off_at']) . '</time><h3>Handoff ' . (int)$entry['handoff_sequence'] . ' | ' . $h($type) . '</h3>';
        echo '<p><strong>From:</strong> ' . $h($entry['from_name'] . ' | ' . $entry['from_designation_label'] . ' | ' . $entry['from_team']) . '</p>';
        echo '<p><strong>To:</strong> ' . $h($entry['to_name'] . ' | ' . $entry['to_designation_label'] . ' | ' . $entry['to_team']) . '</p>';
        echo '<p>Reason: ' . $h($value($entry['reason_text'])) . '</p><p>Priority at handoff: ' . $h($value($entry['priority_at_handoff'])) . '</p>';
        if ($entry['working_minutes'] !== null) echo '<p>Time at previous ownership level: ' . (int)$entry['working_minutes'] . ' working minutes (calendar captured at handoff).</p>';
        if (isset($entry['snapshot']['stage_reminder_count'])) echo '<p>Unanswered reminders in the previous current stage: ' . (int)$entry['snapshot']['stage_reminder_count'] . '</p>';
        echo '<p class="muted">' . $h($entry['name_evidence']) . '</p>';
        if (!empty($entry['internal_handoff_note'])) echo '<div class="handoff-note"><strong>Internal handoff note</strong><p>' . $text($entry['internal_handoff_note']) . '</p></div>';
        echo '</article>';
    }
    echo '</details><details open><summary>Work history (' . count($dossier['meaningful_updates']) . ' recorded updates)</summary>';
    if (!$dossier['meaningful_updates']) echo '<p class="muted">No recorded work updates yet.</p>';
    foreach ($dossier['meaningful_updates'] as $u) echo '<article><time>' . $h($u['at']) . '</time><p><strong>' . $h($u['author']) . '</strong>' . ($u['designation'] ? ' | ' . $h($u['designation']) : '') . '</p><p>' . $text($u['content']) . '</p></article>';
    echo '</details><details><summary>Priority history (' . count($dossier['priority_history']) . ' changes)</summary>';
    echo '<p class="muted">' . $h($dossier['initial_priority_evidence']) . '</p>';
    foreach ($dossier['priority_history'] as $p) echo '<article><time>' . $h($p['at']) . '</time><p><strong>' . $h($value($p['from']) . ' to ' . $value($p['to'])) . '</strong></p><p>' . $h($p['reason']) . '</p></article>';
    echo '</details><details><summary>Ownership generations (' . count($dossier['ownership_history']) . ' recorded assignments)</summary>';
    foreach ($dossier['ownership_history'] as $a) {
        echo '<article><p><strong>' . $h($a['person_name']) . '</strong> | ' . $h($a['designation']) . '</p><p>' . $h($a['team']) . '</p><p>From ' . $h($value($a['started_at'])) . ' to ' . $h($a['ended_at'] ?: 'Current') . '</p><p>Recorded reminders: ' . (int)$a['recorded_reminders'] . '</p></article>';
    }
    echo '</details><details><summary>Resolution &amp; legacy history (' . count($dossier['resolution_history']) . ' records)</summary>';
    if (!$dossier['resolution_history']) echo '<p class="muted">No resolution records available yet.</p>';
    foreach ($dossier['resolution_history'] as $e) echo '<article><time>' . $h($e['at']) . '</time><p>' . $text($e['content']) . '</p></article>';
    echo '</details></section>';
}
