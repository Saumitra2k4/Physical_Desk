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
        $rule_id = (int)$rule->fields['id'];
        if ((int)$rule->fields['condition'] !== (RuleTicket::ONADD | RuleTicket::ONUPDATE)) {
            if (!$rule->update(['id' => $rule_id, 'condition' => RuleTicket::ONADD | RuleTicket::ONUPDATE])) {
                throw new RuntimeException("Could not enable add/update routing for $name");
            }
        }
        foreach ($criterias as $crit) {
            $existingCriteria = new RuleCriteria();
            if ($existingCriteria->getFromDBByCrit(['rules_id' => $rule_id, 'criteria' => $crit['criteria']])) {
                if ((int)$existingCriteria->fields['condition'] !== (int)$crit['condition']
                    || (string)$existingCriteria->fields['pattern'] !== (string)$crit['pattern']) {
                    if (!$existingCriteria->update(['id' => $existingCriteria->getID(),
                        'condition' => $crit['condition'], 'pattern' => $crit['pattern']])) {
                        throw new RuntimeException("Could not correct criteria for $name");
                    }
                }
            } else {
                $existingCriteria->add(['rules_id' => $rule_id, 'criteria' => $crit['criteria'],
                    'condition' => $crit['condition'], 'pattern' => $crit['pattern']]);
            }
        }
        foreach ($actions as $act) {
            $existing = new RuleAction();
            if (!$existing->getFromDBByCrit(['rules_id' => $rule_id, 'field' => $act['field']])) {
                $existing->add(['rules_id' => $rule_id, 'action_type' => $act['action_type'],
                    'field' => $act['field'], 'value' => $act['value']]);
            }
        }
        echo "[VERIFIED] Rule '$name' (ID: $rule_id)" . PHP_EOL;
        return $rule_id;
    }
    
    $rule_id = $rule->add([
        'name' => $name,
        'sub_type' => 'RuleTicket',
        'is_active' => 1,
        'condition' => RuleTicket::ONADD | RuleTicket::ONUPDATE,
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
$sla_crit_tto = getSlaId('PD57_SLA_CRITICAL_TTO');
$sla_high = getSlaId('PD57_SLA_HIGH');
$sla_high_tto = getSlaId('PD57_SLA_HIGH_TTO');
$sla_norm = getSlaId('PD57_SLA_NORMAL');
$sla_norm_tto = getSlaId('PD57_SLA_NORMAL_TTO');
$sla_low = getSlaId('PD57_SLA_LOW');
$sla_low_tto = getSlaId('PD57_SLA_LOW_TTO');
foreach ([$sla_crit, $sla_crit_tto, $sla_high, $sla_high_tto, $sla_norm, $sla_norm_tto, $sla_low, $sla_low_tto] as $slaId) {
    if (!$slaId) throw new RuntimeException('Provision both TTO and TTR objectives before routing rules');
}

// 1. HR Routing Rule
createTicketRule('PD57 Route HR', [
    ['criteria' => 'itilcategories_id', 'condition' => Rule::PATTERN_UNDER, 'pattern' => getCatId('HR')] // under HR category
], [
    ['action_type' => 'assign', 'field' => '_groups_id_assign', 'value' => $g_hr_l1]
]);

// 2. Payroll Routing Rule
createTicketRule('PD57 Route Payroll', [
    ['criteria' => 'itilcategories_id', 'condition' => Rule::PATTERN_UNDER, 'pattern' => getCatId('Payroll')]
], [
    ['action_type' => 'assign', 'field' => '_groups_id_assign', 'value' => $g_pay_l1]
]);

// 3. Operations General Routing Rule
createTicketRule('PD57 Route Operations', [
    ['criteria' => 'itilcategories_id', 'condition' => Rule::PATTERN_UNDER, 'pattern' => getCatId('Operations')]
], [
    ['action_type' => 'assign', 'field' => '_groups_id_assign', 'value' => $g_ops_l1]
]);

// 4. IT General Routing Rule
createTicketRule('PD57 Route IT General', [
    ['criteria' => 'itilcategories_id', 'condition' => Rule::PATTERN_UNDER, 'pattern' => getCatId('IT')]
], [
    ['action_type' => 'assign', 'field' => '_groups_id_assign', 'value' => $g_it_l1]
]);

// 5. IT Network Specialist Rule
createTicketRule('PD57 Route IT Network', [
    ['criteria' => 'itilcategories_id', 'condition' => Rule::PATTERN_UNDER, 'pattern' => getCatId('Network')]
], [
    ['action_type' => 'assign', 'field' => '_groups_id_assign', 'value' => $g_it_net]
]);

// 6. IT Cybersecurity Specialist Rule
createTicketRule('PD57 Route IT Security', [
    ['criteria' => 'itilcategories_id', 'condition' => Rule::PATTERN_UNDER, 'pattern' => getCatId('Cybersecurity')]
], [
    ['action_type' => 'assign', 'field' => '_groups_id_assign', 'value' => $g_it_sec]
]);

// 7. IT Hardware Specialist Rule
createTicketRule('PD57 Route IT Hardware', [
    ['criteria' => 'itilcategories_id', 'condition' => Rule::PATTERN_UNDER, 'pattern' => getCatId('Hardware')]
], [
    ['action_type' => 'assign', 'field' => '_groups_id_assign', 'value' => $g_it_hw]
]);

// 8. IT Identity & Access Rule
createTicketRule('PD57 Route IT Access', [
    ['criteria' => 'itilcategories_id', 'condition' => Rule::PATTERN_UNDER, 'pattern' => getCatId('Identity & Access')]
], [
    ['action_type' => 'assign', 'field' => '_groups_id_assign', 'value' => $g_it_acc]
]);

// 9. IT Studio AV Rule
createTicketRule('PD57 Route IT Studio AV', [
    ['criteria' => 'itilcategories_id', 'condition' => Rule::PATTERN_IS, 'pattern' => getCatId('Studio Audio / Visual')]
], [
    ['action_type' => 'assign', 'field' => '_groups_id_assign', 'value' => $g_it_av]
]);

// 10. Operations Facility Maintenance Rule
createTicketRule('PD57 Route Facilities', [
    ['criteria' => 'itilcategories_id', 'condition' => Rule::PATTERN_UNDER, 'pattern' => getCatId('Facility Maintenance')]
], [
    ['action_type' => 'assign', 'field' => '_groups_id_assign', 'value' => $g_ops_l2]
]);

// 11. Safety / Operational Incident Rule (Rule-based safety escalation)
createTicketRule('PD57 Safety Incident Priority', [
    ['criteria' => 'itilcategories_id', 'condition' => Rule::PATTERN_IS, 'pattern' => getCatId('Safety / Operational Incident')]
], [
    ['action_type' => 'assign', 'field' => 'priority', 'value' => 5], // GLPI Very high; deterministic safety policy
    ['action_type' => 'assign', 'field' => '_groups_id_assign', 'value' => $g_ops_l2],
    ['action_type' => 'assign', 'field' => 'slas_id_ttr', 'value' => $sla_crit],
    ['action_type' => 'assign', 'field' => 'slas_id_tto', 'value' => $sla_crit_tto]
]);

// 12. Manual Triage / Other Rule
createTicketRule('PD57 Route Manual Triage', [
    ['criteria' => 'itilcategories_id', 'condition' => Rule::PATTERN_UNDER, 'pattern' => getCatId('Other')]
], [
    ['action_type' => 'assign', 'field' => '_groups_id_assign', 'value' => $g_triage]
]);

// Apply both native SLA objectives after category and safety rules have set final priority.
foreach ([
    5 => ['VERY_HIGH', $sla_crit_tto, $sla_crit],
    4 => ['HIGH', $sla_high_tto, $sla_high],
    3 => ['NORMAL', $sla_norm_tto, $sla_norm],
    2 => ['LOW', $sla_low_tto, $sla_low],
] as $priority => [$tier, $ttoId, $ttrId]) {
    createTicketRule('PD57 SLA Priority ' . $tier, [
        ['criteria' => 'priority', 'condition' => Rule::PATTERN_IS, 'pattern' => $priority],
    ], [
        ['action_type' => 'assign', 'field' => 'slas_id_tto', 'value' => $ttoId],
        ['action_type' => 'assign', 'field' => 'slas_id_ttr', 'value' => $ttrId],
    ]);
}

echo PHP_EOL . "=== BUSINESS ROUTING RULES INITIALIZED ===" . PHP_EOL;
