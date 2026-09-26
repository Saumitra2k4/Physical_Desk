<?php
require_once dirname(__DIR__) . '/src/Glpi/Application/ResourcesChecker.php';
(new \Glpi\Application\ResourcesChecker(dirname(__DIR__)))->checkResources();
require_once dirname(__DIR__) . '/vendor/autoload.php';

$kernel = new \Glpi\Kernel\Kernel();
$kernel->boot();

// Init admin session
$user = new User();
$user->getFromDBbyName('glpi');
$auth = new Auth();
$auth->auth_succeded = true;
$auth->user = $user;
Session::init($auth);
Session::changeProfile(4); // Super-Admin profile

echo "==========================================================" . PHP_EOL;
echo "   PHYSICAL DESK / PD57 - BUSINESS ROUTING RULES SETUP    " . PHP_EOL;
echo "==========================================================" . PHP_EOL;

global $DB;

// Helper to create RuleTicket
function createTicketRule($name, $criterias, $actions, $match = 'AND') {
    $rule = new RuleTicket();
    // Check if rule exists
    if ($rule->getFromDBByCrit(['name' => $name])) {
        echo "[EXISTS] Rule '$name' (ID: " . $rule->fields['id'] . ")" . PHP_EOL;
        return $rule->fields['id'];
    }
    
    $rule_id = $rule->add([
        'name' => $name,
        'sub_type' => 'RuleTicket',
        'is_active' => 1,
        'match' => $match,
        'description' => "PD57 Automated Routing Rule: $name"
    ]);

    foreach ($criterias as $crit) {
        $ruleCrit = new RuleCriteria();
        $ruleCrit->add([
            'rules_id' => $rule_id,
            'criteria' => $crit['criteria'],
            'condition' => $crit['condition'],
            'pattern' => $crit['pattern']
        ]);
    }

    foreach ($actions as $act) {
        $ruleAct = new RuleAction();
        $ruleAct->add([
            'rules_id' => $rule_id,
            'action_type' => $act['action_type'],
            'field' => $act['field'],
            'value' => $act['value']
        ]);
    }

    echo "[CREATED] Rule '$name' (ID: $rule_id)" . PHP_EOL;
    return $rule_id;
}

// Fetch group IDs
function getGroupId($name) {
    $g = new Group();
    if ($g->getFromDBByCrit(['name' => $name])) {
        return $g->fields['id'];
    }
    return 0;
}

// Fetch SLA IDs
function getSlaId($name) {
    $sla = new SLA();
    if ($sla->getFromDBByCrit(['name' => $name])) {
        return $sla->fields['id'];
    }
    return 0;
}

// Fetch Category IDs
function getCatId($name) {
    $cat = new ITILCategory();
    if ($cat->getFromDBByCrit(['name' => $name])) {
        return $cat->fields['id'];
    }
    return 0;
}

$g_hr_l1   = getGroupId('PD57_HR_L1');
$g_pay_l1  = getGroupId('PD57_PAYROLL_L1');
$g_ops_l1  = getGroupId('PD57_OPS_L1');
$g_ops_l2  = getGroupId('PD57_OPS_L2');
$g_it_l1   = getGroupId('PD57_IT_L1');
$g_it_net  = getGroupId('PD57_IT_NETWORK');
$g_it_hw   = getGroupId('PD57_IT_HARDWARE');
$g_it_acc  = getGroupId('PD57_IT_ACCESS');
$g_it_sec  = getGroupId('PD57_IT_SECURITY');
$g_it_app  = getGroupId('PD57_IT_APPLICATIONS');
$g_it_av   = getGroupId('PD57_IT_AV');
$g_triage  = getGroupId('PD57_TRIAGE');

$sla_crit = getSlaId('PD57_SLA_CRITICAL');
$sla_high = getSlaId('PD57_SLA_HIGH');
$sla_norm = getSlaId('PD57_SLA_NORMAL');

// 1. HR Routing Rule
createTicketRule('PD57 Route HR', [
    ['criteria' => 'itilcategories_id', 'condition' => 17, 'pattern' => getCatId('HR')] // under HR category
], [
    ['action_type' => 'assign', 'field' => '_groups_id_assign', 'value' => $g_hr_l1]
]);

// 2. Payroll Routing Rule
createTicketRule('PD57 Route Payroll', [
    ['criteria' => 'itilcategories_id', 'condition' => 17, 'pattern' => getCatId('Payroll')]
], [
    ['action_type' => 'assign', 'field' => '_groups_id_assign', 'value' => $g_pay_l1]
]);

// 3. Operations General Routing Rule
createTicketRule('PD57 Route Operations', [
    ['criteria' => 'itilcategories_id', 'condition' => 17, 'pattern' => getCatId('Operations')]
], [
    ['action_type' => 'assign', 'field' => '_groups_id_assign', 'value' => $g_ops_l1]
]);

// 4. IT General Routing Rule
createTicketRule('PD57 Route IT General', [
    ['criteria' => 'itilcategories_id', 'condition' => 17, 'pattern' => getCatId('IT')]
], [
    ['action_type' => 'assign', 'field' => '_groups_id_assign', 'value' => $g_it_l1]
]);

// 5. IT Network Specialist Rule
createTicketRule('PD57 Route IT Network', [
    ['criteria' => 'itilcategories_id', 'condition' => 17, 'pattern' => getCatId('Network')]
], [
    ['action_type' => 'assign', 'field' => '_groups_id_assign', 'value' => $g_it_net]
]);

// 6. IT Cybersecurity Specialist Rule
createTicketRule('PD57 Route IT Security', [
    ['criteria' => 'itilcategories_id', 'condition' => 17, 'pattern' => getCatId('Cybersecurity')]
], [
    ['action_type' => 'assign', 'field' => '_groups_id_assign', 'value' => $g_it_sec]
]);

// 7. IT Hardware Specialist Rule
createTicketRule('PD57 Route IT Hardware', [
    ['criteria' => 'itilcategories_id', 'condition' => 17, 'pattern' => getCatId('Hardware')]
], [
    ['action_type' => 'assign', 'field' => '_groups_id_assign', 'value' => $g_it_hw]
]);

// 8. IT Identity & Access Rule
createTicketRule('PD57 Route IT Access', [
    ['criteria' => 'itilcategories_id', 'condition' => 17, 'pattern' => getCatId('Identity & Access')]
], [
    ['action_type' => 'assign', 'field' => '_groups_id_assign', 'value' => $g_it_acc]
]);

// 9. IT Studio AV Rule
createTicketRule('PD57 Route IT Studio AV', [
    ['criteria' => 'itilcategories_id', 'condition' => 0, 'pattern' => getCatId('Studio Audio / Visual')]
], [
    ['action_type' => 'assign', 'field' => '_groups_id_assign', 'value' => $g_it_av]
]);

// 10. Operations Facility Maintenance Rule
createTicketRule('PD57 Route Facilities', [
    ['criteria' => 'itilcategories_id', 'condition' => 17, 'pattern' => getCatId('Facility Maintenance')]
], [
    ['action_type' => 'assign', 'field' => '_groups_id_assign', 'value' => $g_ops_l2]
]);

// 11. Safety / Operational Incident Rule (Rule-based safety escalation)
createTicketRule('PD57 Safety Incident Priority', [
    ['criteria' => 'itilcategories_id', 'condition' => 0, 'pattern' => getCatId('Safety / Operational Incident')]
], [
    ['action_type' => 'assign', 'field' => 'priority', 'value' => 5], // Critical
    ['action_type' => 'assign', 'field' => '_groups_id_assign', 'value' => $g_ops_l2],
    ['action_type' => 'assign', 'field' => 'slas_id_ttr', 'value' => $sla_crit]
]);

// 12. Manual Triage / Other Rule
createTicketRule('PD57 Route Manual Triage', [
    ['criteria' => 'itilcategories_id', 'condition' => 17, 'pattern' => getCatId('Other')]
], [
    ['action_type' => 'assign', 'field' => '_groups_id_assign', 'value' => $g_triage]
]);

echo PHP_EOL . "=== BUSINESS ROUTING RULES INITIALIZED ===" . PHP_EOL;
