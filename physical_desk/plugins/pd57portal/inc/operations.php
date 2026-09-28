<?php
/** Deterministic, local PD57 operational lifecycle. */
require_once __DIR__ . '/admin.php';
require_once __DIR__ . '/phase4.php';
const PD57_OPS_STATE = 'glpi_plugin_pd57portal_ticket_operations';
const PD57_OPS_EVENTS = 'glpi_plugin_pd57portal_operational_events';
const PD57_OPS_POLICY = 'glpi_plugin_pd57portal_operational_policy';
const PD57_OPS_OVERRIDE = 'glpi_plugin_pd57portal_priority_overrides';

function pd57_ops_install_schema(): void {
 global $DB;
 $sqls=[
 "CREATE TABLE IF NOT EXISTS `".PD57_OPS_STATE."` (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,`tickets_id` INT UNSIGNED NOT NULL,`department` VARCHAR(80) NOT NULL,`roles_id` INT UNSIGNED DEFAULT NULL,`people_id` INT UNSIGNED DEFAULT NULL,`assignments_id` INT UNSIGNED DEFAULT NULL,`baseline_priority` VARCHAR(12) NOT NULL,`current_priority` VARCHAR(12) NOT NULL,`level_started_at` DATETIME NOT NULL,`aging_anchor_at` DATETIME NOT NULL,`last_meaningful_update_at` DATETIME DEFAULT NULL,`last_meaningful_business_date` DATE DEFAULT NULL,`last_reminder_at` DATETIME DEFAULT NULL,`reminder_count` INT UNSIGNED NOT NULL DEFAULT 0,`escalation_count` INT UNSIGNED NOT NULL DEFAULT 0,`created_at` DATETIME NOT NULL,`updated_at` DATETIME NOT NULL,PRIMARY KEY(`id`),UNIQUE KEY `ticket_once`(`tickets_id`),KEY `active_work`(`current_priority`,`updated_at`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
 "CREATE TABLE IF NOT EXISTS `".PD57_OPS_EVENTS."` (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,`tickets_id` INT UNSIGNED NOT NULL,`event_type` VARCHAR(40) NOT NULL,`reason_code` VARCHAR(80) DEFAULT NULL,`actor_users_id` INT UNSIGNED DEFAULT NULL,`from_roles_id` INT UNSIGNED DEFAULT NULL,`to_roles_id` INT UNSIGNED DEFAULT NULL,`from_people_id` INT UNSIGNED DEFAULT NULL,`to_people_id` INT UNSIGNED DEFAULT NULL,`from_priority` VARCHAR(12) DEFAULT NULL,`to_priority` VARCHAR(12) DEFAULT NULL,`event_key` VARCHAR(190) NOT NULL,`note` TEXT DEFAULT NULL,`created_at` DATETIME NOT NULL,PRIMARY KEY(`id`),UNIQUE KEY `event_once`(`event_key`),KEY `ticket_timeline`(`tickets_id`,`created_at`),KEY `event_type`(`event_type`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
 "CREATE TABLE IF NOT EXISTS `".PD57_OPS_POLICY."` (`id` TINYINT UNSIGNED NOT NULL,`medium_to_high_minutes` INT UNSIGNED NOT NULL,`high_to_critical_minutes` INT UNSIGNED NOT NULL,`auto_escalation_minutes` INT UNSIGNED NOT NULL,`reminder_minutes` INT UNSIGNED NOT NULL,`is_active` TINYINT(1) NOT NULL DEFAULT 1,`updated_by_users_id` INT UNSIGNED NOT NULL DEFAULT 0,`updated_at` DATETIME NOT NULL,PRIMARY KEY(`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
 "CREATE TABLE IF NOT EXISTS `".PD57_OPS_OVERRIDE."` (`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,`itilcategories_id` INT UNSIGNED NOT NULL,`priority` VARCHAR(12) NOT NULL,`is_active` TINYINT(1) NOT NULL DEFAULT 1,`updated_at` DATETIME NOT NULL,PRIMARY KEY(`id`),UNIQUE KEY `category_once`(`itilcategories_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
 ]; foreach($sqls as $sql){$DB->doQuery($sql);} pd57_p4_install_schema(); pd57_p4_handoff_install_schema(); foreach([
 'teams_id'=>'INT UNSIGNED DEFAULT NULL','designation'=>'VARCHAR(120) NOT NULL DEFAULT \'Employee Services Desk\'',
 'designation_level'=>'TINYINT UNSIGNED NOT NULL DEFAULT 1','team_memberships_id'=>'BIGINT UNSIGNED DEFAULT NULL',
 'assignment_history_id'=>'BIGINT UNSIGNED DEFAULT NULL','fallback_reason'=>'VARCHAR(120) DEFAULT NULL',
 'stage_reminder_count'=>'INT UNSIGNED NOT NULL DEFAULT 0','priority_summary'=>'VARCHAR(500) NOT NULL DEFAULT \'\''
 ] as $column=>$definition) if(!$DB->fieldExists(PD57_OPS_STATE,$column)) $DB->doQuery('ALTER TABLE `'.PD57_OPS_STATE.'` ADD COLUMN `'.$column.'` '.$definition);
 foreach(['from_designation'=>'VARCHAR(120) DEFAULT NULL','to_designation'=>'VARCHAR(120) DEFAULT NULL','from_designation_level'=>'TINYINT UNSIGNED DEFAULT NULL','to_designation_level'=>'TINYINT UNSIGNED DEFAULT NULL','teams_id'=>'INT UNSIGNED DEFAULT NULL','team_memberships_id'=>'BIGINT UNSIGNED DEFAULT NULL','fallback_reason'=>'VARCHAR(120) DEFAULT NULL'] as $column=>$definition) if(!$DB->fieldExists(PD57_OPS_EVENTS,$column)) $DB->doQuery('ALTER TABLE `'.PD57_OPS_EVENTS.'` ADD COLUMN `'.$column.'` '.$definition);
 foreach($DB->request(['SELECT'=>['id','tickets_id','department','designation','designation_level'],'FROM'=>PD57_OPS_STATE]) as $state){$hierarchy=pd57_p4_hierarchy((int)$state['tickets_id']);$department=$hierarchy['department'];$level=pd57_p4_designation_level($department,(string)$state['designation']);if($level>0 && ($level!==(int)$state['designation_level'] || $department!==$state['department']))$DB->update(PD57_OPS_STATE,['department'=>$department,'designation_level'=>$level],['id'=>(int)$state['id']]);}
 if(!$DB->request(['FROM'=>PD57_OPS_POLICY,'WHERE'=>['id'=>1],'LIMIT'=>1])->current()){$DB->insert(PD57_OPS_POLICY,['id'=>1,'medium_to_high_minutes'=>240,'high_to_critical_minutes'=>720,'auto_escalation_minutes'=>960,'reminder_minutes'=>60,'is_active'=>1,'updated_by_users_id'=>0,'updated_at'=>date('Y-m-d H:i:s')]);}
 foreach(['Salary not received'=>'High','Payslip'=>'Medium','unable-to-work identity/access'=>'High','Major outage'=>'Critical'] as $name=>$priority){$cat=$DB->request(['FROM'=>'glpi_itilcategories','WHERE'=>['completename'=>$name],'LIMIT'=>1])->current();if($cat&&!$DB->request(['FROM'=>PD57_OPS_OVERRIDE,'WHERE'=>['itilcategories_id'=>(int)$cat['id']],'LIMIT'=>1])->current())$DB->insert(PD57_OPS_OVERRIDE,['itilcategories_id'=>(int)$cat['id'],'priority'=>$priority,'is_active'=>1,'updated_at'=>date('Y-m-d H:i:s')]);}
}
function pd57_ops_policy(): array {global $DB;return $DB->request(['FROM'=>PD57_OPS_POLICY,'WHERE'=>['id'=>1],'LIMIT'=>1])->current() ?: ['medium_to_high_minutes'=>240,'high_to_critical_minutes'=>720,'auto_escalation_minutes'=>960,'reminder_minutes'=>60,'is_active'=>1];}
function pd57_ops_rank(string $p):int{return ['Low'=>1,'Medium'=>2,'High'=>3,'Critical'=>4][$p]??2;}
function pd57_ops_now(?DateTimeImmutable $now=null):DateTimeImmutable{return $now?:new DateTimeImmutable('now',new DateTimeZone('UTC'));}
function pd57_ops_calendar():array {global $DB;return $DB->request(['FROM'=>PD57_ADMIN_TABLE_CALENDAR,'WHERE'=>['id'=>1],'LIMIT'=>1])->current()?:['timezone'=>'Asia/Kolkata','working_days'=>'1,2,3,4,5','start_time'=>'09:00:00','end_time'=>'17:00:00'];}
function pd57_ops_business_minutes(DateTimeImmutable $from,DateTimeImmutable $to):int {if($to<=$from)return 0;$c=pd57_ops_calendar();$tz=new DateTimeZone($c['timezone']);$days=array_map('intval',explode(',',$c['working_days']));$a=$from->setTimezone($tz);$b=$to->setTimezone($tz);$cursor=$a->setTime(0,0);$end=$b->setTime(0,0);$m=0;while($cursor<=$end){if(in_array((int)$cursor->format('N'),$days,true)){$s=new DateTimeImmutable($cursor->format('Y-m-d').' '.$c['start_time'],$tz);$e=new DateTimeImmutable($cursor->format('Y-m-d').' '.$c['end_time'],$tz);$x=max($s->getTimestamp(),$a->getTimestamp());$y=min($e->getTimestamp(),$b->getTimestamp());if($y>$x)$m+=(int)(($y-$x)/60);} $cursor=$cursor->modify('+1 day');}return $m;}
function pd57_ops_business_date(DateTimeImmutable $now):string{$c=pd57_ops_calendar();return $now->setTimezone(new DateTimeZone($c['timezone']))->format('Y-m-d');}
/** Length of one configured working day.  Priority stages are business-day
 * based; reminder frequency must never shorten an unattended stage. */
function pd57_ops_working_day_minutes(): int {
 $c=pd57_ops_calendar(); $tz=new DateTimeZone($c['timezone']);
 $start=new DateTimeImmutable('2026-01-05 '.$c['start_time'],$tz);
 $end=new DateTimeImmutable('2026-01-05 '.$c['end_time'],$tz);
 return max(1,(int)(($end->getTimestamp()-$start->getTimestamp())/60));
}
function pd57_ops_compatibility_role(string $department,int $level): ?array { global $DB; $legacyDepartment=$department==='Studio Operations'?'Operations':$department; return $DB->request(['FROM'=>PD57_ADMIN_TABLE_ROLES,'WHERE'=>['department'=>$legacyDepartment,'escalation_level'=>$level],'ORDER'=>['id ASC'],'LIMIT'=>1])->current()?:null; }
function pd57_ops_sync_compatibility_group(int $ticketId,array $owner): ?string {
 global $DB; $department=$owner['department']==='Studio Operations'?'OPS':strtoupper((string)$owner['department']);
 $groupName=$owner['designation']==='Employee Services Desk'?'PD57_TRIAGE':'PD57_'.$department.'_'.((int)$owner['designation_level']===1?'L1':((int)$owner['designation_level']===2?'L2':'LEAD'));
 $group=$DB->request(['FROM'=>'glpi_groups','WHERE'=>['name'=>$groupName],'LIMIT'=>1])->current(); if(!$group)return null;
 if(!$DB->request(['FROM'=>'glpi_groups_tickets','WHERE'=>['tickets_id'=>$ticketId,'groups_id'=>(int)$group['id'],'type'=>2],'LIMIT'=>1])->current()) (new Group_Ticket())->add(['tickets_id'=>$ticketId,'groups_id'=>(int)$group['id'],'type'=>2]);
 return $groupName;
}
/** Phase 3 compatibility only. All owner decisions delegate to the Phase 4 resolver. */
function pd57_ops_owner(string $department,int $afterLevel=0,?int $ticketId=null):array { if(!$ticketId) throw new InvalidArgumentException('A ticket is required for Phase 4 ownership resolution.'); $owner=pd57_p4_resolve_escalation($ticketId,$afterLevel); $role=pd57_ops_compatibility_role($owner['department'],(int)$owner['designation_level']); return ['role'=>$role,'assignment'=>$owner['person']?['people_id'=>(int)$owner['person']['id']]:null,'skipped'=>$owner['fallback_reason']?[$owner['fallback_reason']]:[],'phase4_owner'=>$owner]; }
function pd57_ops_baseline(Ticket $ticket):string {global $DB;$priority=pd57_p4_priority((string)($ticket->fields['name']??'').' '.strip_tags((string)($ticket->fields['content']??'')));$id=(int)($ticket->fields['itilcategories_id']??0);if($id){$o=$DB->request(['FROM'=>PD57_OPS_OVERRIDE,'WHERE'=>['itilcategories_id'=>$id,'is_active'=>1],'LIMIT'=>1])->current();if($o&&pd57_ops_rank($o['priority'])>pd57_ops_rank($priority['priority']))return $o['priority'];}return $priority['priority'];}
function pd57_ops_event(int $ticket,string $type,string $key,array $data=[]):bool {global $DB;if($DB->request(['FROM'=>PD57_OPS_EVENTS,'WHERE'=>['event_key'=>$key],'LIMIT'=>1])->current())return false;if(!$DB->insert(PD57_OPS_EVENTS,['tickets_id'=>$ticket,'event_type'=>$type,'reason_code'=>$data['reason']??null,'actor_users_id'=>$data['actor']??null,'from_roles_id'=>$data['from_role']??null,'to_roles_id'=>$data['to_role']??null,'from_people_id'=>$data['from_person']??null,'to_people_id'=>$data['to_person']??null,'from_priority'=>$data['from_priority']??null,'to_priority'=>$data['to_priority']??null,'from_designation'=>$data['from_designation']??null,'to_designation'=>$data['to_designation']??null,'from_designation_level'=>$data['from_level']??null,'to_designation_level'=>$data['to_level']??null,'teams_id'=>$data['team_id']??null,'team_memberships_id'=>$data['membership_id']??null,'fallback_reason'=>$data['fallback_reason']??null,'event_key'=>$key,'note'=>$data['note']??null,'created_at'=>$data['at']??date('Y-m-d H:i:s')]))throw new RuntimeException('Could not persist operational event.');return true;}
function pd57_ops_reason_label(string $reason):string{return ['customer_impact'=>'Customer impact','missed_commitment'=>'Missed commitment','specialist_needed'=>'Additional expertise required','additional_expertise_required'=>'Additional expertise required','manager_review'=>'Management review','resolution_rejected'=>'Resolution rejected','unattended_critical_stage'=>'Unattended Critical stage'][$reason]??str_replace('_',' ',$reason);}
function pd57_ops_current_level(array $state):int { $stored=(int)($state['designation_level']??0); if($stored>0)return $stored; $level=pd57_p4_designation_level((string)$state['department'],(string)($state['designation']??'')); if($level>0)return $level; $role=!empty($state['roles_id'])?pd57_admin_role((int)$state['roles_id']):null; return max(1,(int)($role['escalation_level']??1)); }
function pd57_ops_notify_owner(int $ticketId,string $event,string $key,array $state): void { global $DB; $person=!empty($state['people_id'])?$DB->request(['FROM'=>PD57_ADMIN_TABLE_PEOPLE,'WHERE'=>['id'=>(int)$state['people_id']],'LIMIT'=>1])->current():null; pd57_p4_deliver_notification($ticketId,$event,$key,$person,['designation'=>$state['designation']??'Employee Services Desk']); }
function pd57_ops_state(int $ticketId,?DateTimeImmutable $now=null):?array {global $DB;$s=$DB->request(['FROM'=>PD57_OPS_STATE,'WHERE'=>['tickets_id'=>$ticketId],'LIMIT'=>1])->current();if($s)return $s;$ticket=new Ticket();if(!$ticket->getFromDB($ticketId)||in_array((int)$ticket->fields['status'],[5,6],true))return null;$now=pd57_ops_now($now);$classification=pd57_p4_current_classification($ticketId)?:[];$department=$classification['final_department']??$classification['suggested_department']??'Other / unknown';$owner=pd57_p4_assign_ticket($ticketId,'initial_team_assignment',1);$assessment=pd57_p4_priority((string)$ticket->fields['name'].' '.strip_tags((string)$ticket->fields['content']),$classification['final_request_type']??$classification['suggested_request_type']??'');$base=pd57_ops_baseline($ticket);$role=pd57_ops_compatibility_role($department,(int)$owner['level']);$row=['tickets_id'=>$ticketId,'department'=>$department,'teams_id'=>$owner['team']['id']??null,'roles_id'=>$role['id']??null,'people_id'=>$owner['person']['id']??null,'designation'=>$owner['designation'],'designation_level'=>$owner['level'],'team_memberships_id'=>$owner['membership']['id']??null,'assignment_history_id'=>$owner['assignment_history_id']??null,'fallback_reason'=>$owner['fallback_reason']??null,'baseline_priority'=>$base,'current_priority'=>$base,'priority_summary'=>$assessment['summary'],'level_started_at'=>$now->format('Y-m-d H:i:s'),'aging_anchor_at'=>$now->format('Y-m-d H:i:s'),'created_at'=>$now->format('Y-m-d H:i:s'),'updated_at'=>$now->format('Y-m-d H:i:s')];$DB->insert(PD57_OPS_STATE,$row);pd57_p4_deliver_notification($ticketId,'Request received',$ticketId.'|received',null,['designation'=>$owner['designation']]);return $DB->request(['FROM'=>PD57_OPS_STATE,'WHERE'=>['tickets_id'=>$ticketId],'LIMIT'=>1])->current();}
function pd57_ops_escalate(int $ticketId,string $type,string $reason,?string $note=null,?int $actor=null,?DateTimeImmutable $now=null,?int $targetDesignationLevel=null,?int $expectedEscalationCount=null):array {
 global $DB; $now=pd57_ops_now($now); $s=pd57_ops_state($ticketId,$now); if(!$s)throw new RuntimeException('This request is not active.');
 if(!in_array($type,['manual_escalation','automatic_escalation','employee_rejection'],true))throw new InvalidArgumentException('Unknown escalation source.');
 $DB->beginTransaction();
 try {
  $DB->doQuery('SELECT `id` FROM `'.PD57_OPS_STATE.'` WHERE `tickets_id` = '.(int)$ticketId.' FOR UPDATE');
  $s=$DB->request(['FROM'=>PD57_OPS_STATE,'WHERE'=>['tickets_id'=>$ticketId],'LIMIT'=>1])->current(); $currentLevel=pd57_ops_current_level($s);
  if ($expectedEscalationCount !== null && (int)$s['escalation_count'] !== $expectedEscalationCount) {
   throw new RuntimeException('Ownership changed since this form was opened. Refresh the request.', 409);
  }
  $owner=pd57_p4_resolve_escalation($ticketId,$currentLevel,$targetDesignationLevel); $compatibilityRole=pd57_ops_compatibility_role($owner['department'],(int)$owner['designation_level']);
  $at=$now->format('Y-m-d H:i:s'); $key=$ticketId.'|'.$type.'|'.(int)$s['escalation_count'].'|'.$s['level_started_at'];
  if(!pd57_ops_event($ticketId,$type,$key,['reason'=>$reason,'actor'=>$actor,'from_role'=>$s['roles_id'],'to_role'=>$compatibilityRole['id']??null,'from_person'=>$s['people_id'],'to_person'=>$owner['person']['id']??null,'from_priority'=>$s['current_priority'],'to_priority'=>$s['current_priority'],'from_designation'=>$s['designation'],'to_designation'=>$owner['designation'],'from_level'=>$currentLevel,'to_level'=>$owner['designation_level'],'team_id'=>$owner['team']['id']??null,'membership_id'=>$owner['membership']['id']??null,'fallback_reason'=>$owner['fallback_reason'],'note'=>$note,'at'=>$at])){$DB->rollBack();return pd57_ops_state($ticketId,$now);}
  $event=$DB->request(['FROM'=>PD57_OPS_EVENTS,'WHERE'=>['event_key'=>$key],'LIMIT'=>1])->current()?:[];
  $assignmentId=pd57_p4_record_assignment($ticketId,$owner,$reason,$type,$at);
  pd57_p4_record_handoff($ticketId,$type,$s,$owner,$event,$reason,$note,$actor,$at);
  $DB->update(PD57_OPS_STATE,['department'=>$owner['department'],'teams_id'=>$owner['team']['id']??null,'roles_id'=>$compatibilityRole['id']??null,'people_id'=>$owner['person']['id']??null,'assignments_id'=>null,'designation'=>$owner['designation'],'designation_level'=>$owner['designation_level'],'team_memberships_id'=>$owner['membership']['id']??null,'assignment_history_id'=>$assignmentId,'fallback_reason'=>$owner['fallback_reason'],'level_started_at'=>$at,'aging_anchor_at'=>$at,'last_reminder_at'=>null,'stage_reminder_count'=>0,'escalation_count'=>(int)$s['escalation_count']+1,'updated_at'=>$at],['id'=>$s['id']]);
  pd57_ops_sync_compatibility_group($ticketId,$owner);
  $DB->commit(); $result=pd57_ops_state($ticketId,$now); pd57_ops_notify_owner($ticketId,$type==='automatic_escalation'?'Automatically escalated':($type==='manual_escalation'?'Manually escalated':'Employee rejected resolution'),$key,$result); return $result;
 } catch(Throwable $e){$DB->rollBack();throw $e;}
}
function pd57_ops_meaningful_update(int $ticketId, int $actor, string $note, ?DateTimeImmutable $now = null, ?int $followupId = null): void
{
 global $DB;
 $now = pd57_ops_now($now); $s = pd57_ops_state($ticketId, $now); if (!$s) return;
 $at = $now->format('Y-m-d H:i:s');
 $key = $followupId ? $ticketId . '|meaningful|followup|' . $followupId
     : $ticketId . '|meaningful|' . $at . '|' . $actor . '|' . substr(hash('sha256', $note), 0, 16);
 if (pd57_ops_event($ticketId, 'meaningful_update', $key, ['actor' => $actor,
     'from_person' => $s['people_id'], 'from_designation' => $s['designation'], 'note' => $note, 'at' => $at])) {
  $resetPriority=(int)($s['escalation_count']??0)>0 && ($s['current_priority']??'')!==($s['baseline_priority']??'');
  if($resetPriority) pd57_ops_event($ticketId,'priority_reset',$ticketId.'|priority-reset|'.$s['escalation_count'].'|'.$at,[
   'from_priority'=>$s['current_priority'],'to_priority'=>$s['baseline_priority'],'actor'=>$actor,
   'from_person'=>$s['people_id'],'from_designation'=>$s['designation'],'note'=>'Meaningful support update started a fresh ownership cycle.','at'=>$at]);
  $changes=['aging_anchor_at' => $at, 'stage_reminder_count' => 0,
   'last_meaningful_update_at' => $at, 'last_meaningful_business_date' => pd57_ops_business_date($now),
   'updated_at' => $at];
  if($resetPriority)$changes['current_priority']=$s['baseline_priority'];
  $DB->update(PD57_OPS_STATE,$changes, ['id' => $s['id']]);
 }
}

function pd57_ops_run(?DateTimeImmutable $now=null,?array $ticketIds=null):array { global $DB; $now=pd57_ops_now($now); $out=['priority'=>0,'escalation'=>0,'reminder'=>0]; $policy=pd57_ops_policy(); $calendar=pd57_ops_calendar(); $tz=new DateTimeZone($calendar['timezone']);
 $localNow=$now->setTimezone($tz);
 $workingDays=array_map('intval',explode(',',$calendar['working_days']));
 if (!(int)$policy['is_active'] || !in_array((int)$localNow->format('N'),$workingDays,true)
     || $localNow->format('H:i:s')<$calendar['start_time'] || $localNow->format('H:i:s')>=$calendar['end_time']) return $out;
 $dayMinutes=pd57_ops_working_day_minutes(); $query=['FROM'=>PD57_OPS_STATE]; if($ticketIds!==null)$query['WHERE']=['tickets_id'=>array_map('intval',$ticketIds)]; foreach($DB->request($query) as $s){ $t=new Ticket(); if(!(int)$policy['is_active']||!$t->getFromDB((int)$s['tickets_id'])||in_array((int)$t->fields['status'],[5,6],true))continue; $anchor=new DateTimeImmutable($s['aging_anchor_at'],new DateTimeZone('UTC')); $age=pd57_ops_business_minutes($anchor,$now); $interval=max(1,(int)$policy['reminder_minutes']); $last=$s['last_reminder_at']?new DateTimeImmutable($s['last_reminder_at'],new DateTimeZone('UTC')):null; if($age>=$interval && ($s['last_meaningful_business_date']??'')!==pd57_ops_business_date($now) && (!$last||pd57_ops_business_minutes($last,$now)>=$interval)){$key=(int)$s['tickets_id'].'|reminder|'.$s['aging_anchor_at'].'|'.intdiv($age,$interval);if(pd57_ops_event((int)$s['tickets_id'],'reminder',$key,['at'=>$now->format('Y-m-d H:i:s')])){$DB->update(PD57_OPS_STATE,['last_reminder_at'=>$now->format('Y-m-d H:i:s'),'reminder_count'=>(int)$s['reminder_count']+1,'updated_at'=>$now->format('Y-m-d H:i:s')],['id'=>$s['id']]);pd57_ops_notify_owner((int)$s['tickets_id'],'Reminder: update required',$key,$s);$out['reminder']++;}}
  $required=$s['current_priority']==='Critical' ? 2*$dayMinutes : $dayMinutes;
  if($age<$required)continue;
  $next=['Low'=>'Medium','Medium'=>'High','High'=>'Critical'][$s['current_priority']]??null;
  if($next){$key=(int)$s['tickets_id'].'|priority|'.$next.'|'.$s['aging_anchor_at'];if(pd57_ops_event((int)$s['tickets_id'],'priority_changed',$key,['from_priority'=>$s['current_priority'],'to_priority'=>$next,'at'=>$now->format('Y-m-d H:i:s')])){$updated=$s;$updated['current_priority']=$next;$DB->update(PD57_OPS_STATE,['current_priority'=>$next,'aging_anchor_at'=>$now->format('Y-m-d H:i:s'),'stage_reminder_count'=>0,'updated_at'=>$now->format('Y-m-d H:i:s')],['id'=>$s['id']]);pd57_ops_notify_owner((int)$s['tickets_id'],'Priority increased',$key,$updated);$out['priority']++;}continue;}
  $before=(int)$s['escalation_count'];$after=pd57_ops_escalate((int)$s['tickets_id'],'automatic_escalation','unattended_critical_stage',null,null,$now,null,$before);if((int)$after['escalation_count']===$before+1)$out['escalation']++;
 } return $out; }
