<?php
require_once dirname(__DIR__) . '/src/Glpi/Application/ResourcesChecker.php';
(new \Glpi\Application\ResourcesChecker(dirname(__DIR__)))->checkResources();
require_once dirname(__DIR__) . '/vendor/autoload.php';

$kernel = new \Glpi\Kernel\Kernel();
$kernel->boot();

// Init admin session
$user = new User();
if (!$user->getFromDBbyName('glpi')) {
    echo "ERROR: Admin user 'glpi' not found!" . PHP_EOL;
    exit(1);
}
$auth = new Auth();
$auth->auth_succeded = true;
$auth->user = $user;
Session::init($auth);
Session::changeProfile(4); // Super-Admin profile

echo "==========================================================" . PHP_EOL;
echo "   PHYSICAL DESK / PD57 - PHASE 2 OPERATING MODEL SETUP   " . PHP_EOL;
echo "==========================================================" . PHP_EOL;

global $DB;

// Helper to get or create ITILCategory
function getOrCreateCategory($name, $parent_id = 0, $comment = '') {
    $cat = new ITILCategory();
    if ($cat->getFromDBByCrit(['name' => $name, 'itilcategories_id' => $parent_id])) {
        return $cat->fields['id'];
    }
    return $cat->add([
        'name' => $name,
        'itilcategories_id' => $parent_id,
        'comment' => $comment,
        'is_helpdeskvisible' => 1
    ]);
}

// Helper to get or create Group
function getOrCreateGroup($name, $comment = '') {
    $group = new Group();
    if ($group->getFromDBByCrit(['name' => $name])) {
        return $group->fields['id'];
    }
    return $group->add([
        'name' => $name,
        'comment' => $comment,
        'is_assign' => 1,
        'is_requester' => 1,
        'is_notify' => 1
    ]);
}

// Helper to get or create Location
function getOrCreateLocation($name, $parent_id = 0, $comment = '') {
    $loc = new Location();
    if ($loc->getFromDBByCrit(['name' => $name, 'locations_id' => $parent_id])) {
        return $loc->fields['id'];
    }
    return $loc->add([
        'name' => $name,
        'locations_id' => $parent_id,
        'comment' => $comment
    ]);
}

// Helper to get or create SLA
function getOrCreateSLA($name, $tto_min, $ttr_min, $comment = '') {
    $sla = new SLA();
    if ($sla->getFromDBByCrit(['name' => $name])) {
        return $sla->fields['id'];
    }
    return $sla->add([
        'name' => $name,
        'type' => SLM::TTR,
        'number_time' => $ttr_min,
        'definition_time' => 'minute',
        'comment' => $comment
    ]);
}

// ---------------------------------------------------------
// 1. LOCATIONS / STUDIO HIERARCHY
// ---------------------------------------------------------
echo PHP_EOL . "--- 1. Provisioning Studio Locations ---" . PHP_EOL;
$loc_demo = getOrCreateLocation('PD57 Demo', 0, 'PD57 Demo Region');
$loc_mumbai = getOrCreateLocation('Mumbai', $loc_demo, 'Mumbai City Center');
$loc_studioA = getOrCreateLocation('Studio A', $loc_mumbai, 'Mumbai Studio A');
$loc_studioB = getOrCreateLocation('Studio B', $loc_mumbai, 'Mumbai Studio B');
$loc_studioC = getOrCreateLocation('Studio C', $loc_mumbai, 'Mumbai Studio C');

echo "[SUCCESS] Studio Locations Provisioned:" . PHP_EOL;
echo "  - PD57 Demo (ID: $loc_demo) -> Mumbai (ID: $loc_mumbai)" . PHP_EOL;
echo "  - Studio A (ID: $loc_studioA), Studio B (ID: $loc_studioB), Studio C (ID: $loc_studioC)" . PHP_EOL;

// ---------------------------------------------------------
// 2. ORGANIZATIONAL SUPPORT GROUPS
// ---------------------------------------------------------
echo PHP_EOL . "--- 2. Provisioning Support Groups ---" . PHP_EOL;

// HR
$g_hr_l1   = getOrCreateGroup('PD57_HR_L1', 'HR Tier 1 General Support');
$g_hr_l2   = getOrCreateGroup('PD57_HR_L2', 'HR Tier 2 Specialist Support');
$g_hr_lead = getOrCreateGroup('PD57_HR_LEAD', 'HR Department Lead');

// Payroll
$g_pay_l1   = getOrCreateGroup('PD57_PAYROLL_L1', 'Payroll Tier 1 Support');
$g_pay_l2   = getOrCreateGroup('PD57_PAYROLL_L2', 'Payroll Tier 2 Specialist');
$g_pay_lead = getOrCreateGroup('PD57_PAYROLL_LEAD', 'Payroll Lead');

// Operations
$g_ops_l1   = getOrCreateGroup('PD57_OPS_L1', 'Operations & Studio Support L1');
$g_ops_l2   = getOrCreateGroup('PD57_OPS_L2', 'Facilities & Technical Ops L2');
$g_ops_lead = getOrCreateGroup('PD57_OPS_LEAD', 'Operations Lead');

// IT General & Specialists
$g_it_l1    = getOrCreateGroup('PD57_IT_L1', 'IT General Service Desk L1');
$g_it_l2    = getOrCreateGroup('PD57_IT_L2', 'IT Systems & Infrastructure L2');
$g_it_lead  = getOrCreateGroup('PD57_IT_LEAD', 'IT Department Lead');

$g_it_net   = getOrCreateGroup('PD57_IT_NETWORK', 'IT Network & Connectivity Specialist');
$g_it_hw    = getOrCreateGroup('PD57_IT_HARDWARE', 'IT POS & Hardware Specialist');
$g_it_acc   = getOrCreateGroup('PD57_IT_ACCESS', 'IT Identity & Access Specialist');
$g_it_sec   = getOrCreateGroup('PD57_IT_SECURITY', 'IT Cybersecurity Incident Team');
$g_it_app   = getOrCreateGroup('PD57_IT_APPLICATIONS', 'IT Business Applications Specialist');
$g_it_av    = getOrCreateGroup('PD57_IT_AV', 'Studio Audio & Visual Specialist');

// Triage
$g_triage   = getOrCreateGroup('PD57_TRIAGE', 'Unclassified / Manual Triage Desk');

echo "[SUCCESS] Support Groups Provisioned (HR, Payroll, Ops, IT, Triage)." . PHP_EOL;

// ---------------------------------------------------------
// 3. SERVICE TAXONOMY (MULTI-LEVEL DEPARTMENT HIERARCHY)
// ---------------------------------------------------------
echo PHP_EOL . "--- 3. Provisioning Multi-Level Service Taxonomy ---" . PHP_EOL;

// 3.1 HR
$cat_hr = getOrCreateCategory('HR', 0, 'HR Department');
$cat_hr_leave = getOrCreateCategory('Leave & Attendance', $cat_hr);
  $cat_hr_leave_req = getOrCreateCategory('Leave Request', $cat_hr_leave);
  $cat_hr_leave_att = getOrCreateCategory('Attendance Correction', $cat_hr_leave);
  $cat_hr_leave_sch = getOrCreateCategory('Shift / Schedule Query', $cat_hr_leave);
$cat_hr_rec   = getOrCreateCategory('Employee Records', $cat_hr);
$cat_hr_ben   = getOrCreateCategory('Benefits', $cat_hr);
$cat_hr_hire  = getOrCreateCategory('Hiring & Onboarding', $cat_hr);
$cat_hr_ppl   = getOrCreateCategory('Workplace / People Support', $cat_hr);
$cat_hr_pol   = getOrCreateCategory('Policy / HR Query', $cat_hr);
$cat_hr_oth   = getOrCreateCategory('Other HR', $cat_hr);

// 3.2 IT
$cat_it = getOrCreateCategory('IT', 0, 'IT Department');
$cat_it_desk  = getOrCreateCategory('Service Desk / General Support', $cat_it);

$cat_it_hw    = getOrCreateCategory('Hardware', $cat_it);
  $cat_it_hw_lap = getOrCreateCategory('Laptop / Desktop', $cat_it_hw);
  $cat_it_hw_prt = getOrCreateCategory('Printer', $cat_it_hw);
  $cat_it_hw_per = getOrCreateCategory('Peripheral', $cat_it_hw);
  $cat_it_hw_pos = getOrCreateCategory('POS / Check-in Device', $cat_it_hw);

$cat_it_net   = getOrCreateCategory('Network', $cat_it);
  $cat_it_net_wifi = getOrCreateCategory('Wi-Fi', $cat_it_net);
  $cat_it_net_inet = getOrCreateCategory('Internet', $cat_it_net);
  $cat_it_net_lan  = getOrCreateCategory('LAN / Connectivity', $cat_it_net);
  $cat_it_net_eq   = getOrCreateCategory('Network Equipment', $cat_it_net);

$cat_it_id    = getOrCreateCategory('Identity & Access', $cat_it);
  $cat_it_id_pwd = getOrCreateCategory('Password / Login', $cat_it_id);
  $cat_it_id_prm = getOrCreateCategory('Permission / Access', $cat_it_id);
  $cat_it_id_new = getOrCreateCategory('New Account', $cat_it_id);
  $cat_it_id_lck = getOrCreateCategory('Account Lockout', $cat_it_id);

$cat_it_sec   = getOrCreateCategory('Cybersecurity', $cat_it);
  $cat_it_sec_phish = getOrCreateCategory('Suspicious Email / Phishing', $cat_it_sec);
  $cat_it_sec_acct  = getOrCreateCategory('Account Security', $cat_it_sec);
  $cat_it_sec_dev   = getOrCreateCategory('Device Security', $cat_it_sec);
  $cat_it_sec_inc   = getOrCreateCategory('Security Incident', $cat_it_sec);

$cat_it_app   = getOrCreateCategory('Applications / Software', $cat_it);
$cat_it_pos   = getOrCreateCategory('POS / Check-in Systems', $cat_it);
$cat_it_av    = getOrCreateCategory('Studio Audio / Visual', $cat_it);
$cat_it_cctv  = getOrCreateCategory('CCTV / Security Systems', $cat_it);
$cat_it_oth   = getOrCreateCategory('Other IT', $cat_it);

// 3.3 Payroll
$cat_pay = getOrCreateCategory('Payroll', 0, 'Payroll Department');
$cat_pay_sal = getOrCreateCategory('Salary', $cat_pay);
$cat_pay_rem = getOrCreateCategory('Reimbursement', $cat_pay);
$cat_pay_slp = getOrCreateCategory('Payslip', $cat_pay);
$cat_pay_ded = getOrCreateCategory('Deduction', $cat_pay);
$cat_pay_bnk = getOrCreateCategory('Bank / Payment Details', $cat_pay);
$cat_pay_tax = getOrCreateCategory('Tax / Payroll Documentation', $cat_pay);
$cat_pay_oth = getOrCreateCategory('Other Payroll', $cat_pay);

// 3.4 Operations
$cat_ops = getOrCreateCategory('Operations', 0, 'Operations Department');

$cat_ops_eq  = getOrCreateCategory('Studio Equipment', $cat_ops);
  $cat_ops_eq_barre = getOrCreateCategory('Barre Equipment', $cat_ops_eq);
  $cat_ops_eq_wgt   = getOrCreateCategory('Weights / Dumbbells', $cat_ops_eq);
  $cat_ops_eq_rst   = getOrCreateCategory('Resistance Equipment', $cat_ops_eq);
  $cat_ops_eq_cls   = getOrCreateCategory('Exercise / Class Equipment', $cat_ops_eq);
  $cat_ops_eq_crd   = getOrCreateCategory('Cardio Equipment', $cat_ops_eq);
  $cat_ops_eq_oth   = getOrCreateCategory('Other Fitness Equipment', $cat_ops_eq);

$cat_ops_fac = getOrCreateCategory('Facility Maintenance', $cat_ops);
  $cat_ops_fac_hvac = getOrCreateCategory('HVAC / AC', $cat_ops_fac);
  $cat_ops_fac_elec = getOrCreateCategory('Electrical', $cat_ops_fac);
  $cat_ops_fac_plmb = getOrCreateCategory('Plumbing / Water', $cat_ops_fac);
  $cat_ops_fac_acc  = getOrCreateCategory('Access Control', $cat_ops_fac);
  $cat_ops_fac_gen  = getOrCreateCategory('General Facility', $cat_ops_fac);

$cat_ops_hk   = getOrCreateCategory('Housekeeping', $cat_ops);
$cat_ops_sup  = getOrCreateCategory('Supplies / Inventory', $cat_ops);
$cat_ops_vnd  = getOrCreateCategory('Vendor Issue', $cat_ops);
$cat_ops_std  = getOrCreateCategory('Studio Operations', $cat_ops);
$cat_ops_safe = getOrCreateCategory('Safety / Operational Incident', $cat_ops);
$cat_ops_oth  = getOrCreateCategory('Other Operations', $cat_ops);

// 3.5 Other / Triage
$cat_oth = getOrCreateCategory('Other', 0, 'Other Department');
$cat_oth_gen  = getOrCreateCategory('General Request', $cat_oth);
$cat_oth_trg  = getOrCreateCategory('Manual Triage', $cat_oth);

echo "[SUCCESS] Multi-Level Taxonomy Provisioned across HR, IT, Payroll, Operations, Other." . PHP_EOL;

// ---------------------------------------------------------
// 4. SLA MATRIX PROTOTYPE ASSUMPTIONS
// ---------------------------------------------------------
echo PHP_EOL . "--- 4. Provisioning SLA Policy Matrix ---" . PHP_EOL;
$sla_critical = getOrCreateSLA('PD57_SLA_CRITICAL', 15, 120, 'Critical SLA: TTO 15m, TTR 2h');
$sla_high     = getOrCreateSLA('PD57_SLA_HIGH', 30, 240, 'High SLA: TTO 30m, TTR 4h');
$sla_normal   = getOrCreateSLA('PD57_SLA_NORMAL', 240, 480, 'Normal SLA: TTO 4h, TTR 8h');
$sla_low      = getOrCreateSLA('PD57_SLA_LOW', 480, 1440, 'Low SLA: TTO 8h, TTR 24h');

echo "[SUCCESS] SLA Policies Provisioned (CRITICAL, HIGH, NORMAL, LOW)." . PHP_EOL;

// ---------------------------------------------------------
// 5. FITNESS & IT ASSETS
// ---------------------------------------------------------
echo PHP_EOL . "--- 5. Provisioning Studio & IT Assets ---" . PHP_EOL;

// Helper for Computer Asset (Native IT / POS)
function getOrCreateComputer($name, $loc_id, $serial = '', $comment = '') {
    $comp = new Computer();
    if ($comp->getFromDBByCrit(['name' => $name])) {
        return $comp->fields['id'];
    }
    return $comp->add([
        'name' => $name,
        'locations_id' => $loc_id,
        'serial' => $serial,
        'comment' => $comment,
        'is_template' => 0
    ]);
}

// Helper for NetworkEquipment Asset
function getOrCreateNetworkEquipment($name, $loc_id, $comment = '') {
    $net = new NetworkEquipment();
    if ($net->getFromDBByCrit(['name' => $name])) {
        return $net->fields['id'];
    }
    return $net->add([
        'name' => $name,
        'locations_id' => $loc_id,
        'comment' => $comment
    ]);
}

// Helper for Custom Appliance (Studio Equipment / Audio / Facility)
function getOrCreateAppliance($name, $loc_id, $comment = '') {
    $app = new Appliance();
    if ($app->getFromDBByCrit(['name' => $name])) {
        return $app->fields['id'];
    }
    return $app->add([
        'name' => $name,
        'locations_id' => $loc_id,
        'comment' => $comment
    ]);
}

// Create sample assets
$ast_barre_b4 = getOrCreateAppliance('PD57-BARRE-MUM-B-004', $loc_studioB, 'Studio B Barre Unit #4 near Mirror 3');
$ast_barre_a1 = getOrCreateAppliance('PD57-BARRE-MUM-A-001', $loc_studioA, 'Studio A Barre Unit #1 Main Wall');
$ast_audio_a1 = getOrCreateAppliance('PD57-AUDIO-MUM-A-001', $loc_studioA, 'Studio A Master Audio Receiver & Speaker System');
$ast_ac_c1    = getOrCreateAppliance('PD57-AC-MUM-C-001', $loc_studioC, 'Studio C Primary Commercial HVAC Unit');
$ast_pos_b3   = getOrCreateComputer('PD57-POS-MUM-B-003', $loc_studioB, 'POS-MUM-B3-8871', 'Studio B Front Desk Check-in Terminal');
$ast_net_a1   = getOrCreateNetworkEquipment('PD57-NET-MUM-A-001', $loc_studioA, 'Studio A High-Density Access Point Router');

echo "[SUCCESS] Studio & IT Assets Provisioned:" . PHP_EOL;
echo "  - Barre Equipment: PD57-BARRE-MUM-B-004 (Studio B), PD57-BARRE-MUM-A-001 (Studio A)" . PHP_EOL;
echo "  - Studio Audio: PD57-AUDIO-MUM-A-001 (Studio A)" . PHP_EOL;
echo "  - Facility HVAC: PD57-AC-MUM-C-001 (Studio C)" . PHP_EOL;
echo "  - POS Terminal: PD57-POS-MUM-B-003 (Studio B)" . PHP_EOL;
echo "  - Network AP: PD57-NET-MUM-A-001 (Studio A)" . PHP_EOL;

echo PHP_EOL . "=== PHASE 2 OPERATING MODEL INITIALIZED ===" . PHP_EOL;
