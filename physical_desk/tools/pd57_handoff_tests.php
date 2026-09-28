<?php
/** No real database, authentication server, network or SMTP is used by these tests. */
if (PHP_SAPI !== 'cli' || !in_array('--portable', $argv, true)) {
    fwrite(STDERR, "Usage: php tools/pd57_handoff_tests.php --portable\n"); exit(2);
}
define('PD57_PORTABLE_HANDOFF_TEST', true);
require __DIR__ . '/pd57_handoff_test_support.php';
require dirname(__DIR__) . '/plugins/pd57portal/inc/operations.php';
require dirname(__DIR__) . '/plugins/pd57portal/inc/resolution.php';
require dirname(__DIR__) . '/plugins/pd57portal/inc/handoff_panel.php';
set_error_handler(static function($level, $message, $file, $line) { throw new ErrorException($message, 0, $level, $file, $line); });
$DB = new MemoryPD57Database(); $TEST_NOW = '2026-09-28 04:00:00'; $passed = 0;
foreach ([PD57_P4_TEAMS, PD57_P4_MEMBERS, PD57_P4_ASSIGNMENTS, PD57_P4_CLASSIFICATIONS, PD57_P4_NOTIFICATIONS,
    PD57_P4_HANDOFFS, PD57_OPS_STATE, PD57_OPS_EVENTS, PD57_OPS_POLICY, PD57_OPS_OVERRIDE,
    PD57_ADMIN_TABLE_PEOPLE, PD57_ADMIN_TABLE_ROLES, PD57_ADMIN_TABLE_CALENDAR,
    'glpi_tickets', 'glpi_tickets_users', 'glpi_itilfollowups', 'glpi_groups', 'glpi_groups_tickets', 'glpi_users'] as $t) $DB->tables[$t] = [];
function check(bool $ok, string $name): void { global $passed; if (!$ok) throw new RuntimeException('FAIL: ' . $name); ++$passed; echo "PASS: $name\n"; }
function rejects(callable $f): bool { try { $f(); return false; } catch (RuntimeException $e) { return true; } }
function at(string $s): DateTimeImmutable { return new DateTimeImmutable($s, new DateTimeZone('UTC')); }
function fixture(int $id, string $department = 'IT', string $team = 'Desktop & Device Support', int $level = 1): void {
    global $DB;
    $now = '2026-09-28 04:00:00';
    $DB->insert('glpi_tickets', ['id'=>$id,'name'=>'HANDOFF TEST '.$id,'content'=>'Original request <b>details</b>.','status'=>2,'date'=>$now,'date_creation'=>$now,'locations_id'=>0]);
    $DB->insert('glpi_tickets_users', ['tickets_id'=>$id,'users_id'=>501,'type'=>1]);
    $DB->insert(PD57_P4_CLASSIFICATIONS,['tickets_id'=>$id,'suggested_department'=>$department,'suggested_team'=>$team,'suggested_request_type'=>'Device Support','final_department'=>$department,'final_team'=>$team,'final_request_type'=>'Device Support','confidence'=>0.7,'created_at'=>$now]);
    $DB->insert(PD57_OPS_STATE,['tickets_id'=>$id,'department'=>$department,'teams_id'=>1,'roles_id'=>null,'people_id'=>1001,
        'designation'=>pd57_p4_designation($department,$level),'designation_level'=>$level,'baseline_priority'=>'Medium','current_priority'=>'Medium',
        'level_started_at'=>$now,'aging_anchor_at'=>$now,'last_reminder_at'=>null,'last_meaningful_update_at'=>null,
        'last_meaningful_business_date'=>null,'stage_reminder_count'=>0,'reminder_count'=>0,'escalation_count'=>0,
        'team_memberships_id'=>null,'assignment_history_id'=>null,'created_at'=>$now,'updated_at'=>$now]);
    $DB->insert(PD57_P4_ASSIGNMENTS,['tickets_id'=>$id,'department'=>$department,'teams_id'=>1,'team_name'=>$team,'designation_level'=>$level,
        'designation'=>pd57_p4_designation($department,$level),'people_id'=>1001,'reason'=>'fixture','assignment_source'=>'initial_assignment','assigned_at'=>$now,'created_at'=>$now]);
    $DB->update(PD57_OPS_STATE,['assignment_history_id'=>$DB->insertId()],['tickets_id'=>$id]);
}
function countRows(string $t, int $id): int { return count(pd57_p4_history_rows($t,['tickets_id'=>$id])); }
function addUpdate(int $id, string $content, string $time, int $uid=101): int {
    global $DB,$TEST_NOW; Session::$id=$uid; Session::$role='support'; $TEST_NOW=$time;
    $f = (new ITILFollowup())->add(['itemtype'=>'Ticket','items_id'=>$id,'content'=>$content,'is_private'=>0]);
    pd57_ops_meaningful_update($id,$uid,$content,at($time),$f); return $f;
}
try {
    $DB->insert(PD57_ADMIN_TABLE_CALENDAR,['id'=>1,'timezone'=>'Asia/Kolkata','working_days'=>'1,2,3,4,5','start_time'=>'09:00:00','end_time'=>'17:00:00']);
    $DB->insert(PD57_OPS_POLICY,['id'=>1,'is_active'=>1,'reminder_minutes'=>60]);
    $DB->insert(PD57_P4_TEAMS,['id'=>1,'department'=>'IT','name'=>'Desktop & Device Support','is_active'=>1]);
    foreach ([[1001,101,'Owner A'],[2002,202,'Owner B'],[3003,303,'Owner C'],[4004,404,'Owner D']] as $i => [$pid,$uid,$name]) {
        $DB->insert(PD57_ADMIN_TABLE_PEOPLE,['id'=>$pid,'linked_users_id'=>$uid,'display_name'=>$name,'employee_id'=>'TEST-'.$pid,'is_active'=>1]);
        $DB->insert('glpi_users',['id'=>$uid,'name'=>'test-'.$uid,'firstname'=>$name,'realname'=>'']);
        $DB->insert(PD57_P4_MEMBERS,['teams_id'=>1,'people_id'=>$pid,'designation'=>pd57_p4_designation('IT',$i+1),'designation_level'=>$i+1,'is_active'=>1,'is_unavailable'=>0,'effective_from'=>'2026-01-01','effective_until'=>null]);
    }
    // A deliberate colliding person ID proves users_id is not used as people.id.
    $DB->insert(PD57_ADMIN_TABLE_PEOPLE,['id'=>101,'linked_users_id'=>999,'display_name'=>'WRONG IDENTITY','employee_id'=>null,'is_active'=>1]);
    $DB->insert('glpi_users',['id'=>501,'name'=>'employee-fixture','firstname'=>'Employee','realname'=>'Fixture']);
    $DB->insert(PD57_ADMIN_TABLE_PEOPLE,['id'=>5005,'linked_users_id'=>501,'display_name'=>'Employee Fixture','employee_id'=>'TEST-EMP-1','is_active'=>1]);
    pd57_p4_handoff_install_schema(); $ddl=count($DB->queries); pd57_p4_handoff_install_schema();
    check($DB->fieldExists(PD57_P4_HANDOFFS,'snapshot_json'),'idempotent additive snapshot migration');
    check(count(array_filter($DB->queries,fn($q)=>str_starts_with($q,'ALTER TABLE')))===1,'repeat migration does not repeat ALTER');
    $DB->beginTransaction(); check(rejects(fn()=>pd57_p4_handoff_install_schema()),'migration refused inside transaction'); $DB->rollBack();
    fixture(9001);
    addUpdate(9001,'Update one','2026-09-28 04:10:00');
    addUpdate(9001,'Update two','2026-09-28 04:20:00');
    addUpdate(9001,'Update three','2026-09-28 04:30:00');
    $initial=pd57_p4_handoff_dossier(9001);
    check(count($initial['meaningful_updates'])===3,'public followup and operational mirror count once');
    check($initial['meaningful_updates'][0]['author']==='Owner A','author uses linked user identity, not matching numeric person ID');
    check($initial['requester']['employee_id']==='TEST-EMP-1','authorized requester employee ID resolved');
    pd57_ops_event(9001,'priority_changed','9001|fixture-priority', ['from_priority'=>'Medium','to_priority'=>'Critical','reason'=>'baseline_policy_correction','at'=>'2026-09-28 05:00:00']);
    $DB->update(PD57_OPS_STATE,['current_priority'=>'Critical','stage_reminder_count'=>7,'last_reminder_at'=>'2026-09-28 05:00:00'],['tickets_id'=>9001]);
    // Critical receives one complete working-day grace before escalation.
    $run=pd57_ops_run(at('2026-09-30 05:00:00'),[9001]);
    check($run['escalation']===1,'existing scheduler path produces one escalation');
    check(countRows(PD57_P4_HANDOFFS,9001)===1,'automatic escalation persists one handoff');
    $d=pd57_p4_handoff_dossier(9001);
    check($d['handoffs'][0]['to_designation_label']==='Senior IT Support Engineer','professional designation preserved');
    check(count($d['meaningful_updates'])===3,'new owner inherits all three updates');
    check($d['priority_history'][0]['reason']==='Baseline priority corrected by policy','priority reason not mislabeled as inactivity');
    check($d['current_state']['current_priority']==='Critical','escalation preserves Critical priority');
    $event=$DB->request(['FROM'=>PD57_OPS_EVENTS,'WHERE'=>['event_type'=>'automatic_escalation'],'LIMIT'=>1])->current();
    $DB->beginTransaction();
    $same=pd57_p4_record_handoff(9001,'automatic_escalation',[],[],$event,'ignored',null,null,'2026-09-28 06:00:00');
    $DB->commit();
    check((int)$same['handoff_sequence']===1&&countRows(PD57_P4_HANDOFFS,9001)===1,'source-event replay returns original handoff');
    $before=countRows(PD57_P4_HANDOFFS,9001); pd57_ops_run(at('2026-09-30 05:00:00'),[9001]);
    check(countRows(PD57_P4_HANDOFFS,9001)===$before,'scheduler rerun does not duplicate ownership handoff');
    addUpdate(9001,'Update four','2026-09-30 05:20:00',202);
    $d=pd57_p4_handoff_dossier(9001);
    check(count($d['meaningful_updates'])===4,'second owner update appends without erasing history');
    check($d['current_state']['aging_anchor_at']==='2026-09-30 05:20:00','meaningful update resets current inactivity anchor');
    check($d['current_state']['current_priority']==='Medium','new owner meaningful update resets priority to the preserved baseline');
    // A new owner then receives a complete fresh Medium → High → Critical →
    // grace cycle before another automatic handoff.
    pd57_ops_run(at('2026-10-01 05:20:00'),[9001]);
    pd57_ops_run(at('2026-10-02 05:20:00'),[9001]);
    pd57_ops_run(at('2026-10-06 05:20:00'),[9001]);
    $d=pd57_p4_handoff_dossier(9001);
    check(count($d['handoffs'])===2&&count($d['ownership_history'])===3,'three ownership generations reconstructed');
    check(count($d['meaningful_updates'])===4,'third owner receives four prior updates');
    check(array_column($d['handoffs'],'handoff_sequence')===[1,2],'per-ticket sequence is stable');
    check($d['handoffs'][0]['working_minutes']===1020,'working duration uses captured calendar');
    // Low baseline: each completed business-day stage advances once; the
    // Critical stage has one full working-day grace before handoff.
    fixture(9010);
    $DB->update(PD57_OPS_STATE,['baseline_priority'=>'Low','current_priority'=>'Low','aging_anchor_at'=>'2026-09-28 04:00:00'],['tickets_id'=>9010]);
    pd57_ops_run(at('2026-09-29 04:00:00'),[9010]);
    check($DB->request(['FROM'=>PD57_OPS_STATE,'WHERE'=>['tickets_id'=>9010],'LIMIT'=>1])->current()['current_priority']==='Medium','Low baseline advances to Medium after one working-day stage');
    pd57_ops_run(at('2026-09-30 04:00:00'),[9010]);
    check($DB->request(['FROM'=>PD57_OPS_STATE,'WHERE'=>['tickets_id'=>9010],'LIMIT'=>1])->current()['current_priority']==='High','Medium advances to High after one working-day stage');
    pd57_ops_run(at('2026-10-01 04:00:00'),[9010]);
    check($DB->request(['FROM'=>PD57_OPS_STATE,'WHERE'=>['tickets_id'=>9010],'LIMIT'=>1])->current()['current_priority']==='Critical','High advances to Critical after one working-day stage');
    pd57_ops_run(at('2026-10-02 04:00:00'),[9010]);
    check((int)$DB->request(['FROM'=>PD57_OPS_STATE,'WHERE'=>['tickets_id'=>9010],'LIMIT'=>1])->current()['escalation_count']===0,'Critical grace prevents early escalation');
    pd57_ops_run(at('2026-10-05 05:00:00'),[9010]);
    $lowCycle=$DB->request(['FROM'=>PD57_OPS_STATE,'WHERE'=>['tickets_id'=>9010],'LIMIT'=>1])->current();
    check((int)$lowCycle['escalation_count']===1&&$lowCycle['current_priority']==='Critical','Critical grace ends in one automatic escalation that retains Critical');
    $DB->update(PD57_ADMIN_TABLE_PEOPLE,['display_name'=>'RENAMED'],['id'=>1001]);
    check(pd57_p4_handoff_dossier(9001)['handoffs'][0]['from_name']==='Owner A','historical captured name survives person rename');
    $current=(int)$d['current_state']['escalation_count'];
    pd57_ops_escalate(9001,'manual_escalation','manager_review','PRIVATE HANDOFF SECRET',303,at('2026-09-30 08:20:00'),4,$current);
    check(countRows(PD57_P4_HANDOFFS,9001)===3,'manual escalation captures handoff');
    check(rejects(fn()=>pd57_ops_escalate(9001,'manual_escalation','manager_review','PRIVATE HANDOFF SECRET',303,at('2026-09-30 08:20:00'),4,$current)),'stale manual version cannot escalate twice');
    check(countRows(PD57_P4_HANDOFFS,9001)===3,'stale replay leaves handoff count unchanged');
    $internal=pd57_p4_handoff_dossier(9001);
    check(str_contains(json_encode($internal),'PRIVATE HANDOFF SECRET'),'authorized support sees internal handoff');
    Session::$id=501;Session::$role='employee';
    $safe=pd57_p4_employee_safe_dossier(9001);
    check(!str_contains(json_encode($safe),'PRIVATE HANDOFF SECRET'),'employee projection excludes private note');
    check(!str_contains(json_encode($safe),'employee_id')&&!str_contains(json_encode($safe),'people_id'),'employee projection excludes internal identifiers');
    check(!str_contains(json_encode($safe),'selection_reason'),'employee projection excludes assignment diagnostics');
    check(rejects(fn()=>pd57_p4_handoff_dossier(9001)),'employee forbidden from full dossier');
    Session::$id=888;
    check(rejects(fn()=>pd57_p4_employee_safe_dossier(9001)),'unrelated employee forbidden from ticket');
    Session::$id=0;
    check(rejects(fn()=>pd57_p4_handoff_dossier(9001)),'anonymous forbidden from dossier');
    Session::$id=101;Session::$role='support';
    fixture(9002);$stateBefore=$DB->tables[PD57_OPS_STATE];$eventBefore=countRows(PD57_OPS_EVENTS,9002);$assignmentBefore=countRows(PD57_P4_ASSIGNMENTS,9002);
    $DB->failInsert=PD57_P4_HANDOFFS;
    check(rejects(fn()=>pd57_ops_escalate(9002,'manual_escalation','manager_review','failure fixture',101,at('2026-09-28 05:00:00'),2,0)),'handoff write failure is not reported as success');
    check($stateBefore===$DB->tables[PD57_OPS_STATE]&&countRows(PD57_OPS_EVENTS,9002)===$eventBefore&&countRows(PD57_P4_ASSIGNMENTS,9002)===$assignmentBefore,'handoff failure rolls back event assignment and ownership');
    fixture(9003);$TEST_NOW='2026-09-28 05:00:00';
    $p=pd57_resolution_apply_action(9003,'propose','First proposed fix',at($TEST_NOW));
    check($DB->tables['glpi_tickets'][9003]['status']===5,'resolution proposal retains status 5');
    $n=countRows(PD57_OPS_EVENTS,9003);pd57_resolution_apply_action(9003,'propose','duplicate',at($TEST_NOW));
    check(countRows(PD57_OPS_EVENTS,9003)===$n,'duplicate proposal does not create second cycle');
    Session::$id=501;Session::$role='employee';$TEST_NOW='2026-09-28 05:10:00';
    pd57_resolution_apply_action(9003,'reject','Still broken',at($TEST_NOW));
    check($DB->tables['glpi_tickets'][9003]['status']===2&&countRows(PD57_P4_HANDOFFS,9003)===1,'employee rejection reopens and captures professional handoff');
    check(rejects(fn()=>pd57_resolution_apply_action(9003,'reject','replay',at($TEST_NOW))),'rejection replay blocked by current status');
    Session::$id=202;Session::$role='support';$TEST_NOW='2026-09-28 06:00:00';
    pd57_resolution_apply_action(9003,'propose','Second proposed fix',at($TEST_NOW));
    $d=pd57_p4_handoff_dossier(9003);
    check(count(array_filter($d['resolution_history'],fn($e)=>$e['type']==='resolution_proposed'))===2,'two resolution cycles retained, not collapsed');
    Session::$id=501;Session::$role='employee';$TEST_NOW='2026-09-28 06:10:00';
    pd57_resolution_apply_action(9003,'confirm','',at($TEST_NOW));
    check($DB->tables['glpi_tickets'][9003]['status']===6,'employee acceptance closes request');
    check(countRows(PD57_P4_HANDOFFS,9003)===1,'acceptance does not create another escalation');
    Session::$id=101;Session::$role='support';
    $d=pd57_p4_handoff_dossier(9003);
    check(str_contains(json_encode($d['resolution_history']),'Still broken'),'employee rejection explanation remains in history');
    check(str_contains(json_encode($d['resolution_history']),'First proposed fix')&&str_contains(json_encode($d['resolution_history']),'Second proposed fix'),'both proposed fixes survive closure');
    fixture(9004);$DB->update(PD57_OPS_STATE,['people_id'=>null],['tickets_id'=>9004]);
    pd57_ops_escalate(9004,'manual_escalation','manager_review','unknown person',101,at('2026-09-28 06:00:00'),2,0);
    check(pd57_p4_handoff_dossier(9004)['handoffs'][0]['from_name']==='Unassigned / unknown','unknown previous owner is not invented');
    ob_start();pd57_p4_render_handoff_panel(pd57_p4_handoff_dossier(9001));$html=ob_get_clean();
    check(str_contains($html,'Update one')&&str_contains($html,'Update four'),'actual panel renders complete accumulated work');
    addUpdate(9001,'<script>alert(1)</script><b>Safe text</b>','2026-09-30 09:00:00',404);
    ob_start();pd57_p4_render_handoff_panel(pd57_p4_handoff_dossier(9001));$html=ob_get_clean();
    check(!str_contains($html,'<script>')&&str_contains($html,'Safe text'),'panel never renders request content as executable HTML');
    check(!str_contains($html,'PD57_IT_L2')&&!str_contains($html,'Handler'),'panel uses professional labels');
    $copy=unserialize(serialize($DB->tables));$d1=pd57_p4_handoff_dossier(9001);$DB->tables=$copy;$d2=pd57_p4_handoff_dossier(9001);
    check($d1===$d2,'dossier reconstructs consistently from persisted-style records');
    check(!array_filter($DB->queries,fn($q)=>str_starts_with($q,'DROP')||str_starts_with($q,'DELETE')),'test workflow used no destructive SQL');
    // Synthetic legacy evidence modelled on the supplied #365 transcript, not the user's database.
    fixture(365, 'Payroll', 'Salary Processing', 7);
    $DB->update(PD57_OPS_STATE, ['people_id'=>null,'designation'=>'Employee Services Desk','current_priority'=>'High','baseline_priority'=>'High'], ['tickets_id'=>365]);
    foreach ($DB->tables[PD57_P4_ASSIGNMENTS] as $key=>$a) if ($a['tickets_id']===365) unset($DB->tables[PD57_P4_ASSIGNMENTS][$key]);
    foreach ([['You will receive it in 3 working days','2026-09-26 22:49:12'],
        ['[PD57_RESOLUTION_PROPOSED] Employee confirmation is required before closure.','2026-09-26 22:49:16'],
        ['[PD57_ESCALATION] Employee reported unresolved issue. Escalated to PD57_PAYROLL_L2.','2026-09-26 22:50:10']] as [$content,$time]) {
        $DB->insert('glpi_itilfollowups',['itemtype'=>'Ticket','items_id'=>365,'content'=>$content,'users_id'=>777,'is_private'=>0,'date'=>$time,'date_creation'=>$time]);
    }
    $legacyBefore=serialize($DB->tables);
    $legacy=pd57_p4_handoff_dossier(365);
    check($legacy['current_state']['current_priority']==='High'&&$legacy['classification']['confirmed']['team']==='Salary Processing','legacy-shaped fixture keeps High and Salary Processing');
    check(count($legacy['meaningful_updates'])===1&&count($legacy['resolution_history'])===2,'legacy public work proposal and rejection remain visible');
    check(str_contains(json_encode($legacy['legacy_escalation_evidence']),'Accountant')&&!str_contains(json_encode($legacy['legacy_escalation_evidence']),'PD57_PAYROLL_L2'),'legacy escalation wording translates without rewriting source history');
    check($legacy['meaningful_updates'][0]['author']==='Unknown author','unmapped legacy author is not invented');
    check(serialize($DB->tables)===$legacyBefore,'dossier reads do not backfill or mutate real-style records');
    $DB->insert('glpi_itilfollowups',['itemtype'=>'Ticket','items_id'=>9001,'content'=>'NATIVE PRIVATE COMMENT','users_id'=>101,'is_private'=>1,'date'=>'2026-09-28 10:00:00','date_creation'=>'2026-09-28 10:00:00']);
    Session::$id=501;Session::$role='employee';
    check(!str_contains(json_encode(pd57_p4_employee_safe_dossier(9001)),'NATIVE PRIVATE COMMENT'),'private native followup is not exposed to employee');
    Session::$id=101;Session::$role='support';
    $DB->update('glpi_tickets',['content'=>'EDITED AFTER HANDOFF'],['id'=>9001]);
    check(pd57_p4_handoff_dossier(9001)['request']['description']==='Original request details.','first handoff request snapshot survives later request edits');
    echo "HANDOFF_PORTABLE_TESTS=$passed/$passed\n";
    echo "TEST_MODE=PRODUCTION_FUNCTIONS_WITH_IN_MEMORY_DB_AND_GLPI_DOUBLES\n";
    echo "REAL_GLPI_MARIADB_AUTH_SMTP_TESTS=NOT_RUN\n";
} catch (Throwable $e) { fwrite(STDERR,$e."\n"); exit(1); }
