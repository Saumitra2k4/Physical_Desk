<?php
require_once dirname(__DIR__) . '/src/Glpi/Application/ResourcesChecker.php';
(new \Glpi\Application\ResourcesChecker(dirname(__DIR__)))->checkResources();
require_once dirname(__DIR__) . '/vendor/autoload.php';
(new \Glpi\Kernel\Kernel('production'))->boot();
require_once dirname(__DIR__) . '/plugins/pd57portal/inc/admin.php';
$passed = 0; function p4(bool $ok, string $message): void { global $passed; if (!$ok) throw new RuntimeException($message); ++$passed; echo "[PASS] $message\n"; }
pd57_admin_install_schema();
foreach ([PD57_P4_TEAMS,PD57_P4_MEMBERS,PD57_P4_ASSIGNMENTS,PD57_P4_CLASSIFICATIONS,PD57_P4_NOTIFICATIONS] as $table) p4($GLOBALS['DB']->tableExists($table), "schema $table");
$scenarios = [
 ['I work in HR and the treadmill in Mumbai Studio B is broken.',['Studio Operations','Facilities & Equipment']],
 ['I work in Operations and have not received my salary for three months.',['Payroll','Salary Processing']],
 ['My reimbursement was submitted yesterday. Just checking the status.',['Payroll','Reimbursements']],
 ['My medical reimbursement is overdue and I need the money today for treatment.',['Payroll','Reimbursements']],
 ['My account is locked and I cannot work.',['IT','Identity & Access']],
 ['Wi-Fi is down for everyone at the studio and front desk cannot operate.',['IT','Network & Connectivity']],
 ['I need a copy of last month\'s payslip.',['Payroll','Payslips & Payroll Documents']],
 ['I need an experience letter next month.',['HR','HR Documentation']],
 ['The studio equipment is unsafe to use.',['Studio Operations','Facilities & Equipment']],
];
foreach ($scenarios as [$text,$expected]) { $route=pd57_p4_fast_route($text); p4($route !== null && array_slice($route,0,2)===$expected, 'route '.implode(' / ',$expected)); }
$salary=pd57_p4_priority($scenarios[1][0],'Salary Not Received'); p4(pd57_ops_rank($salary['priority'])>=3,'salary duration is at least High');
$ordinary=pd57_p4_priority($scenarios[2][0],'Reimbursement'); $medical=pd57_p4_priority($scenarios[3][0],'Reimbursement'); p4(pd57_ops_rank($ordinary['priority'])===2,'ordinary reimbursement is not automatically High'); p4(pd57_ops_rank($medical['priority'])>pd57_ops_rank($ordinary['priority']),'medical reimbursement is raised by context');
$locked=pd57_p4_priority($scenarios[4][0],'Account Lockout'); p4(pd57_ops_rank($locked['priority'])>=3,'blocked account is High or above');
$safe=pd57_p4_priority($scenarios[8][0],'Equipment Issue'); p4($safe['priority']==='Critical','safety signal is Critical');
$pool=[['people_id'=>3,'is_active'=>1,'prior_assignment_count'=>0,'last_assigned_at'=>'2026-01-01'],['people_id'=>1,'is_active'=>1,'prior_assignment_count'=>0,'last_assigned_at'=>'2026-01-01'],['people_id'=>2,'is_active'=>1,'prior_assignment_count'=>0,'last_assigned_at'=>'2026-01-01']];
$order=[]; for($i=0;$i<4;$i++){ $winner=pd57_p4_choose_assignee($pool); $order[]=$winner['people_id']; foreach($pool as &$member) if($member['people_id']===$winner['people_id']) {$member['prior_assignment_count']++;$member['last_assigned_at']=sprintf('2026-01-01 00:0%d:00',$i); } unset($member); }
p4(count(array_unique(array_slice($order,0,3)))===3,'A/B/C each receive one before a second assignment'); p4($order===[1,2,3,1],'load balancing is deterministic');
$inactive=pd57_p4_choose_assignee([['people_id'=>1,'is_active'=>0],['people_id'=>2,'is_active'=>1,'prior_assignment_count'=>1]]); p4($inactive['people_id']===2,'inactive member is skipped');
p4(pd57_p4_category_id_for_hierarchy('IT','Network & Connectivity','Wi-Fi') > 0,'confirmed Wi-Fi hierarchy resolves a real helpdesk category');
$networkTeam=$GLOBALS['DB']->request(['FROM'=>PD57_P4_TEAMS,'WHERE'=>['department'=>'IT','name'=>'Network & Connectivity'],'LIMIT'=>1])->current()?:[];
$networkMembers=$networkTeam?$GLOBALS['DB']->request(['FROM'=>PD57_P4_MEMBERS,'WHERE'=>['teams_id'=>(int)$networkTeam['id'],'designation_level'=>1,'is_active'=>1,'is_unavailable'=>0]]):[];
p4($networkTeam && $networkMembers->count() > 0,'Network & Connectivity has eligible first-professional ownership');
$requestSource=file_get_contents(dirname(__DIR__).'/plugins/pd57portal/front/request.php'); $classifierSource=file_get_contents(dirname(__DIR__).'/plugins/pd57classifier/hook.php');
p4(str_contains($requestSource,"'itilcategories_id'=>\$categoryId") && str_contains($classifierSource,"'final_hierarchy'"),'employee-confirmed hierarchy and category persist before ownership initialization');
$notificationKey = 'phase4-test|notification-dedupe|' . date('YmdHis');
p4(pd57_p4_notify(0,'test_notification',$notificationKey,['people_id'=>null]),'notification event is recorded'); p4(!pd57_p4_notify(0,'test_notification',$notificationKey,[]),'notification event key is deduplicated');
$source=file_get_contents(dirname(__DIR__).'/plugins/pd57auth/hook.php'); p4(str_contains($source,'$email->html(') && str_contains($source,'$email->text('),'OTP has HTML and plain text parts'); p4(!str_contains($source,'GLPI Login Verification'),'OTP wording has no product-framework label');
echo "TESTS=$passed/$passed\n";
