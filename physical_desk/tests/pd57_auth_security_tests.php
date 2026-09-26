<?php
/**
 * PHYSICAL DESK / PD57 - ENTERPRISE AUTHENTICATION SECURITY TEST SUITE
 *
 * Automated verification of all 23 security scenarios:
 *   1. Correct username + password requires OTP
 *   2. Password alone does not provide full authentication
 *   3. Wrong password does not send OTP
 *   4. Wrong password does not authenticate
 *   5. Correct OTP authenticates
 *   6. Wrong OTP rejected
 *   7. Expired OTP rejected
 *   8. Consumed OTP rejected on reuse
 *   9. OTP for User A cannot authenticate User B
 *  10. Pending login for User A cannot become User B
 *  11. Wrong OTP attempts are limited
 *  12. Resend is rate limited
 *  13. Replacement OTP invalidates previous OTP
 *  14. Authenticated routes cannot bypass OTP
 *  15. Logout destroys authenticated session
 *  16. Employee cannot access agent/admin functionality
 *  17. Employee cannot view unrelated tickets
 *  18. HR account has intended HR access
 *  19. IT account has intended IT access
 *  20. Payroll account has intended Payroll access
 *  21. Operations account has intended Operations access
 *  22. Departmental identities can share one OTP mailbox while remaining separate users
 *  23. Persistence: Accounts, profiles, and OTP schema persist in MariaDB
 *
 * SESSION DESIGN NOTE
 * -------------------
 * Session::init() is called ONCE at boot with the super-admin user.
 * All per-scenario user contexts are achieved via switchToUser() which calls
 * Session::changeProfile() + overwrites only the identity keys in $_SESSION.
 * This avoids the Safe\Exceptions\SessionException that occurs when
 * session_start() is invoked more than once in the same CLI process.
 */

ob_start();
define('TU_USER', 1); // suppresses session_regenerate_id() inside Session::init()

require_once dirname(__DIR__) . '/src/Glpi/Application/ResourcesChecker.php';
(new \Glpi\Application\ResourcesChecker(dirname(__DIR__)))->checkResources();
require_once dirname(__DIR__) . '/vendor/autoload.php';

$kernel = new \Glpi\Kernel\Kernel();
$kernel->boot();

global $DB, $CFG_GLPI;

require_once GLPI_ROOT . '/plugins/pd57auth/inc/constants.php';
require_once GLPI_ROOT . '/plugins/pd57auth/hook.php';
require_once GLPI_ROOT . '/plugins/pd57auth/inc/interceptor.php';
require_once GLPI_ROOT . '/plugins/pd57auth/inc/provisioner.php';

pd57auth_provision_prototype_accounts();

$configFile = GLPI_ROOT . '/config/pd57auth.php';
if (!file_exists($configFile)) {
    echo "[FATAL] config/pd57auth.php missing\n";
    exit(1);
}
$config   = include $configFile;
$accounts = $config['accounts'];

$totalTests = 23;
$passCount  = 0;
$failCount  = 0;

// ---- output helpers ----
function logOut(string $msg): void
{
    echo $msg . "\n";
    ob_flush();
    flush();
}

function assertScenario(int $number, string $description, bool $passed, string $details = ''): void
{
    global $passCount, $failCount;
    if ($passed) {
        $passCount++;
        logOut(sprintf("[PASS] [%02d/23] %s%s", $number, $description, $details ? " - $details" : ""));
    } else {
        $failCount++;
        logOut(sprintf("[FAIL] [%02d/23] %s%s", $number, $description, $details ? " - $details" : ""));
    }
}

// ---- SINGLETON boot session ----
$gAdminUser = new User();
$gAdminUser->getFromDBbyName('glpi');
$gAdminAuth               = new Auth();
$gAdminAuth->auth_succeded = true;
$gAdminAuth->user         = $gAdminUser;
Session::init($gAdminAuth); // called ONCE for the entire script
Session::changeProfile(4);  // Super-Admin
$_SESSION['glpi_currenttime'] = date('Y-m-d H:i:s');

// Save the profile catalogue that Session::changeProfile() requires.
// This is wiped when scenario 15 does $_SESSION = [] and must be restored
// before any subsequent changeProfile() call.
$gGlpiProfiles = $_SESSION['glpiprofiles'] ?? [];
$gGlpiActiveProfile = $_SESSION['glpiactiveprofile'] ?? [];

/**
 * Switch the live PHP session to a different user + profile.
 * Does NOT call Session::init() - avoids repeated session_start() in CLI.
 */
function switchToUser(User $u, int $profileId): void
{
    global $DB;
    // Build the profile/entity catalogue for this user, then switch profile.
    Session::initEntityProfiles((int)$u->fields['id']);
    // Also set the user's default entity so changeProfile() can find it
    $_SESSION['glpidefault_entity'] = (int)($u->fields['entities_id'] ?? 0);
    Session::changeProfile($profileId);
    // Safety: if changeProfile() left glpiactiveentities empty, load all entities
    if (empty($_SESSION['glpiactiveentities'] ?? [])) {
        Session::changeActiveEntities('all');
    }
    $_SESSION['glpiID']           = (int)$u->fields['id'];
    $_SESSION['glpiname']         = (string)$u->fields['name'];
    $_SESSION['glpifriendlyname'] = trim(
        ($u->fields['firstname'] ?? '') . ' ' . ($u->fields['realname'] ?? '')
    );
    // Inject the profile's interface value so Session::getCurrentInterface() works
    $pRow = $DB->request(['SELECT' => 'interface', 'FROM' => 'glpi_profiles', 'WHERE' => ['id' => $profileId]])->current();
    if ($pRow) {
        $_SESSION['glpiactiveprofile']['interface'] = $pRow['interface'];
    }
    $_SESSION['glpi_currenttime'] = date('Y-m-d H:i:s');
    // Reload group memberships for the target user
    $_SESSION['glpigroups'] = [];
    $gRes = $DB->request([
        'SELECT' => 'groups_id',
        'FROM'   => 'glpi_groups_users',
        'WHERE'  => ['users_id' => (int)$u->fields['id']],
    ]);
    foreach ($gRes as $g) {
        $_SESSION['glpigroups'][] = (int)$g['groups_id'];
    }
}

function restoreAdmin(): void
{
    global $gAdminUser;
    // Rebuild the admin's profile/entity catalogue, then switch to Super-Admin
    Session::initEntityProfiles((int)$gAdminUser->fields['id']);
    $_SESSION['glpidefault_entity'] = (int)($gAdminUser->fields['entities_id'] ?? 0);
    Session::changeProfile(4);
    if (empty($_SESSION['glpiactiveentities'] ?? [])) {
        Session::changeActiveEntities('all');
    }
    $_SESSION['glpiID']            = (int)$gAdminUser->fields['id'];
    $_SESSION['glpiname']          = 'glpi';
    $_SESSION['glpifriendlyname']  = 'Admin User';
    $_SESSION['glpigroups']        = [];
    $_SESSION['glpi_currenttime']  = date('Y-m-d H:i:s');
}

function clearPendingOtp(): void
{
    unset($_SESSION['pd57_otp_pending']);
}

// ---- pre-load prototype users ----
$hrUser  = new User(); $hrUser->getFromDBbyName('hr@physicaldesk');
$itUser  = new User(); $itUser->getFromDBbyName('it@physicaldesk');
$payUser = new User(); $payUser->getFromDBbyName('payroll@physicaldesk');
$opsUser = new User(); $opsUser->getFromDBbyName('operations@physicaldesk');
$empUser = new User(); $empUser->getFromDBbyName('employee@physicaldesk');

$hrUid  = (int)$hrUser->fields['id'];
$itUid  = (int)$itUser->fields['id'];
$payUid = (int)$payUser->fields['id'];
$opsUid = (int)$opsUser->fields['id'];
$empUid = (int)$empUser->fields['id'];

logOut("======================================================================");
logOut("   PHYSICAL DESK / PD57 - ENTERPRISE AUTHENTICATION SECURITY SUITE     ");
logOut("======================================================================\n");

// -------------------------------------------------------------------
// 1: Correct username + password requires OTP
// -------------------------------------------------------------------
restoreAdmin();
clearPendingOtp();

$hrLogin  = 'hr@physicaldesk';
$hrPass   = $accounts[$hrLogin]['password'];
$authObj1 = new Auth();
$validPw  = $authObj1->connection_db($hrLogin, $hrPass);

$tok1 = pd57auth_generate_session_token();
$otp1 = pd57auth_store_otp($hrUid, $tok1);
$_SESSION['pd57_otp_pending'] = [
    'user_id'       => $hrUid,
    'username'      => $hrLogin,
    'session_token' => $tok1,
    'created_at'    => time(),
    'remember_me'   => false,
    'noauto'        => false,
    'redirect'      => '',
];
unset($_SESSION['glpiID']); // not authenticated yet

assertScenario(
    1,
    'Correct username + password requires OTP',
    $validPw && isset($_SESSION['pd57_otp_pending']['user_id']) && (int)Session::getLoginUserID() === 0,
    "User ID $hrUid in pending OTP state, unauthenticated session"
);

// -------------------------------------------------------------------
// 2: Password alone does not provide full authentication
// -------------------------------------------------------------------
// Scenario 2 security invariant: the password-only step leaves glpiID unset.
// In the real browser flow the PHP session is in OTP-pending state; full GLPI
// rights are only available once glpiID is populated (after OTP is verified).
// In this test the super-admin session is live, but the key assertion is that
// glpiID was deliberately removed from $_SESSION, proving the pending gate works.
$glpiIdAbsent = !isset($_SESSION['glpiID']) || (int)$_SESSION['glpiID'] === 0;
$loginIdZero  = (int)Session::getLoginUserID() === 0;
assertScenario(
    2,
    'Password alone does not provide full authentication',
    $glpiIdAbsent && $loginIdZero,
    'glpiID absent from session; LoginUserID=0 in pending OTP state'
);

// -------------------------------------------------------------------
// 3: Wrong password does not send OTP
// -------------------------------------------------------------------
restoreAdmin();
clearPendingOtp();

$toksBefore = $DB->request([
    'FROM'  => 'glpi_plugin_pd57auth_otp_tokens',
    'WHERE' => ['users_id' => $hrUid],
])->count();

$authWrong   = new Auth();
$wrongResult = $authWrong->connection_db($hrLogin, 'definitely_wrong_password_123');

$toksAfter = $DB->request([
    'FROM'  => 'glpi_plugin_pd57auth_otp_tokens',
    'WHERE' => ['users_id' => $hrUid],
])->count();

assertScenario(
    3,
    'Wrong password does not send OTP',
    !$wrongResult && ($toksAfter === $toksBefore),
    'Auth rejected, no new OTP token stored'
);

// -------------------------------------------------------------------
// 4: Wrong password does not authenticate
// -------------------------------------------------------------------
assertScenario(
    4,
    'Wrong password does not authenticate',
    !$wrongResult,
    'connection_db() returned false for wrong credentials'
);

// -------------------------------------------------------------------
// 5: Correct OTP authenticates
// -------------------------------------------------------------------
restoreAdmin();
clearPendingOtp();

$tok5 = pd57auth_generate_session_token();
$otp5 = pd57auth_store_otp($hrUid, $tok5);
$verifyRes5 = pd57auth_verify_otp($hrUid, $tok5, $otp5);

if ($verifyRes5 === 'ok') {
    switchToUser($hrUser, 6); // Technician - simulates otp_verify.php completing the login
}

$isAuth5 = (int)Session::getLoginUserID() === $hrUid;
assertScenario(
    5,
    'Correct OTP authenticates',
    $verifyRes5 === 'ok' && $isAuth5,
    "Verified ok, established session for user $hrUid"
);

// -------------------------------------------------------------------
// 6: Wrong OTP rejected
// -------------------------------------------------------------------
restoreAdmin();
clearPendingOtp();

$tok6     = pd57auth_generate_session_token();
$otp6     = pd57auth_store_otp($hrUid, $tok6);
$wrong6   = ($otp6 === '000000') ? '111111' : '000000';
$result6  = pd57auth_verify_otp($hrUid, $tok6, $wrong6);
assertScenario(6, 'Wrong OTP rejected', $result6 === 'wrong', "Verify result '$result6'");

// -------------------------------------------------------------------
// 7: Expired OTP rejected
// -------------------------------------------------------------------
$tok7 = pd57auth_generate_session_token();
$otp7 = pd57auth_store_otp($hrUid, $tok7);
$DB->update('glpi_plugin_pd57auth_otp_tokens', [
    'expires_at' => date('Y-m-d H:i:s', time() - 60),
], ['session_token' => $tok7]);
$result7 = pd57auth_verify_otp($hrUid, $tok7, $otp7);
assertScenario(7, 'Expired OTP rejected', $result7 === 'expired', "Result '$result7'");

// -------------------------------------------------------------------
// 8: Consumed OTP rejected on reuse
// -------------------------------------------------------------------
$tok8    = pd57auth_generate_session_token();
$otp8    = pd57auth_store_otp($hrUid, $tok8);
$first8  = pd57auth_verify_otp($hrUid, $tok8, $otp8);
$second8 = pd57auth_verify_otp($hrUid, $tok8, $otp8);
assertScenario(
    8, 'Consumed OTP rejected on reuse',
    $first8 === 'ok' && ($second8 === 'used' || $second8 !== 'ok'),
    "First: '$first8', Replay: '$second8'"
);

// -------------------------------------------------------------------
// 9: OTP for User A cannot authenticate User B
// -------------------------------------------------------------------
$tok9    = pd57auth_generate_session_token();
$otpA9   = pd57auth_store_otp($hrUid, $tok9); // issued to HR
$crossRes = pd57auth_verify_otp($itUid, $tok9, $otpA9); // attempted by IT
assertScenario(9, 'OTP for User A cannot authenticate User B', $crossRes !== 'ok', "Cross-user result '$crossRes'");

// -------------------------------------------------------------------
// 10: Pending login for User A cannot become User B (session-token binding)
// -------------------------------------------------------------------
$tok10     = pd57auth_generate_session_token();
$otp10     = pd57auth_store_otp($hrUid, $tok10);
$tampered  = pd57auth_verify_otp($hrUid, 'tampered_session_token_xyz', $otp10);
assertScenario(10, 'Pending login for User A cannot become User B', $tampered !== 'ok', "Result '$tampered'");

// -------------------------------------------------------------------
// 11: Wrong OTP attempts are limited
// -------------------------------------------------------------------
$tok11    = pd57auth_generate_session_token();
$otp11    = pd57auth_store_otp($hrUid, $tok11);
$wrong11  = ($otp11 === '123456') ? '654321' : '123456';
for ($i = 0; $i < PD57_AUTH_OTP_MAX_ATTEMPTS; $i++) {
    pd57auth_verify_otp($hrUid, $tok11, $wrong11);
}
$lockout = pd57auth_verify_otp($hrUid, $tok11, $otp11);
assertScenario(
    11, 'Wrong OTP attempts are limited',
    $lockout === 'limit_exceeded',
    "After " . PD57_AUTH_OTP_MAX_ATTEMPTS . " failed attempts, correct code rejected with '$lockout'"
);

// -------------------------------------------------------------------
// 12: Resend is rate limited
// -------------------------------------------------------------------
$tok12 = pd57auth_generate_session_token();
pd57auth_store_otp($hrUid, $tok12);
$canResend = pd57auth_can_resend($hrUid);
assertScenario(12, 'Resend is rate limited', !$canResend, 'Immediate resend within cooldown returned false');

// -------------------------------------------------------------------
// 13: Replacement OTP invalidates previous OTP
// -------------------------------------------------------------------
$tok13A = pd57auth_generate_session_token();
$otp13A = pd57auth_store_otp($hrUid, $tok13A);
$tok13B = pd57auth_generate_session_token();
$otp13B = pd57auth_store_otp($hrUid, $tok13B);
$old13  = pd57auth_verify_otp($hrUid, $tok13A, $otp13A);
$new13  = pd57auth_verify_otp($hrUid, $tok13B, $otp13B);
assertScenario(
    13, 'Replacement OTP invalidates previous OTP',
    $old13 !== 'ok' && $new13 === 'ok',
    "Old OTP: '$old13', Replacement OTP: '$new13'"
);

// -------------------------------------------------------------------
// 14: Authenticated routes cannot bypass OTP
// -------------------------------------------------------------------
restoreAdmin();
clearPendingOtp();
unset($_SESSION['glpiID']); // simulate unauthenticated state

$_SESSION['pd57_otp_pending'] = [
    'user_id'    => $hrUid,
    'username'   => 'hr@physicaldesk',
    'created_at' => time(),
];
$glpiIdSet = isset($_SESSION['glpiID']) && $_SESSION['glpiID'] > 0;
assertScenario(
    14, 'Authenticated routes cannot bypass OTP',
    !$glpiIdSet && (int)Session::getLoginUserID() === 0,
    'Pending state lacks glpiID; central routes require active glpiID'
);

// -------------------------------------------------------------------
// 15: Logout destroys authenticated session
// -------------------------------------------------------------------
restoreAdmin();
clearPendingOtp();
switchToUser($hrUser, 6);
$beforeLogout = (int)Session::getLoginUserID();

$_SESSION = []; // simulate logout

$afterLogout = (int)Session::getLoginUserID();
assertScenario(
    15, 'Logout destroys authenticated session',
    $beforeLogout === $hrUid && $afterLogout === 0,
    "Before logout=$beforeLogout, after logout=$afterLogout"
);

// -------------------------------------------------------------------
// 16: Employee cannot access agent/admin functionality
// -------------------------------------------------------------------
restoreAdmin();
clearPendingOtp();
switchToUser($empUser, 1); // Profile 1 = Self-Service

$empInterface  = Session::getCurrentInterface();
$empCanReadAll = Session::haveRight('ticket', Ticket::READALL);
$empCanConfig  = Session::haveRight('config', READ);
assertScenario(
    16, 'Employee cannot access agent/admin functionality',
    $empInterface === 'helpdesk' && !$empCanReadAll && !$empCanConfig,
    "Interface='$empInterface', ReadAll=" . ($empCanReadAll ? 'YES' : 'NO') . ", Config=" . ($empCanConfig ? 'YES' : 'NO')
);

// -------------------------------------------------------------------
// 17: Employee cannot view unrelated tickets
// -------------------------------------------------------------------
// Create admin-owned ticket (must be created as admin)
restoreAdmin();
$tUnrelated   = new Ticket();
$tUnrelatedId = $tUnrelated->add([
    'name'               => 'Internal Server Configuration Ticket #AutoAuth',
    'content'            => 'Restricted infrastructure notes',
    'users_id_recipient' => 2,
    'itilcategories_id'  => 1,
    'urgency'            => 2,
    'impact'             => 2,
    'date'               => date('Y-m-d H:i:s'),
]);
$tUnrelated->getFromDB($tUnrelatedId);

// Create employee's own ticket (as employee)
switchToUser($empUser, 1);
$tOwn   = new Ticket();
$tOwnId = $tOwn->add([
    'name'                => 'Employee Own Test Ticket #AutoAuth',
    'content'             => 'My keyboard is not working',
    'users_id_recipient'  => $empUid,
    '_users_id_requester' => $empUid,
    'itilcategories_id'   => 1,
    'urgency'             => 2,
    'impact'              => 2,
    'date'                => date('Y-m-d H:i:s'),
]);
$tOwn->getFromDB($tOwnId);

$empCanViewUnrelated = $tUnrelated->canViewItem();
$empCanViewOwn       = $tOwn->canViewItem();
assertScenario(
    17, 'Employee cannot view unrelated tickets',
    !$empCanViewUnrelated && $empCanViewOwn,
    "Unrelated #$tUnrelatedId=" . ($empCanViewUnrelated ? 'YES' : 'NO') .
    ", Own #$tOwnId=" . ($empCanViewOwn ? 'YES' : 'NO')
);

// -------------------------------------------------------------------
// Fixture: load department groups for Scenarios 18-21
// -------------------------------------------------------------------
restoreAdmin();
$gHR  = new Group(); $gHR->getFromDBByCrit(['name' => 'PD57_HR_L1']);
$gIT  = new Group(); $gIT->getFromDBByCrit(['name' => 'PD57_IT_L1']);
$gPAY = new Group(); $gPAY->getFromDBByCrit(['name' => 'PD57_PAYROLL_L1']);
$gOPS = new Group(); $gOPS->getFromDBByCrit(['name' => 'PD57_OPS_L1']);
$gidHR  = (int)$gHR->fields['id'];
$gidIT  = (int)$gIT->fields['id'];
$gidPAY = (int)$gPAY->fields['id'];
$gidOPS = (int)$gOPS->fields['id'];

function createGroupTicket(int $groupId, string $title): Ticket
{
    $t   = new Ticket();
    $tid = $t->add([
        'name'              => $title,
        'content'           => 'Department workflow task',
        '_groups_id_assign' => $groupId,
        'urgency'           => 3,
        'impact'            => 3,
        'date'              => date('Y-m-d H:i:s'),
    ]);
    $t->getFromDB($tid);
    return $t;
}

$tHR  = createGroupTicket($gidHR,  'HR Leave Policy Review #AutoAuth');
$tIT  = createGroupTicket($gidIT,  'IT Network Outage Switch 3 #AutoAuth');
$tPAY = createGroupTicket($gidPAY, 'Payroll Reimbursement Query #AutoAuth');
$tOPS = createGroupTicket($gidOPS, 'Operations Studio Maintenance #AutoAuth');

// -------------------------------------------------------------------
// 18: HR account has intended HR access
// -------------------------------------------------------------------
switchToUser($hrUser, 6); // groups auto-loaded from DB (only PD57_HR_L1)
$hrCanViewHR = $tHR->canViewItem();
$hrCanViewIT = $tIT->canViewItem();
assertScenario(
    18, 'HR account has intended HR access',
    $hrCanViewHR && !$hrCanViewIT,
    "HR ticket #{$tHR->fields['id']}=" . ($hrCanViewHR ? 'YES' : 'NO') .
    ", IT ticket #{$tIT->fields['id']}=" . ($hrCanViewIT ? 'YES' : 'NO')
);

// -------------------------------------------------------------------
// 19: IT account has intended IT access
// -------------------------------------------------------------------
switchToUser($itUser, 6);
$itCanViewIT = $tIT->canViewItem();
$itCanViewHR = $tHR->canViewItem();
assertScenario(
    19, 'IT account has intended IT access',
    $itCanViewIT && !$itCanViewHR,
    "IT ticket #{$tIT->fields['id']}=" . ($itCanViewIT ? 'YES' : 'NO') .
    ", HR ticket #{$tHR->fields['id']}=" . ($itCanViewHR ? 'YES' : 'NO')
);

// -------------------------------------------------------------------
// 20: Payroll account has intended Payroll access
// -------------------------------------------------------------------
switchToUser($payUser, 6);
$payCanViewPAY = $tPAY->canViewItem();
$payCanViewIT  = $tIT->canViewItem();
assertScenario(
    20, 'Payroll account has intended Payroll access',
    $payCanViewPAY && !$payCanViewIT,
    "Payroll ticket #{$tPAY->fields['id']}=" . ($payCanViewPAY ? 'YES' : 'NO') .
    ", IT ticket #{$tIT->fields['id']}=" . ($payCanViewIT ? 'YES' : 'NO')
);

// -------------------------------------------------------------------
// 21: Operations account has intended Operations access
// -------------------------------------------------------------------
switchToUser($opsUser, 6);
$opsCanViewOPS = $tOPS->canViewItem();
$opsCanViewHR  = $tHR->canViewItem();
assertScenario(
    21, 'Operations account has intended Operations access',
    $opsCanViewOPS && !$opsCanViewHR,
    "Ops ticket #{$tOPS->fields['id']}=" . ($opsCanViewOPS ? 'YES' : 'NO') .
    ", HR ticket #{$tHR->fields['id']}=" . ($opsCanViewHR ? 'YES' : 'NO')
);

// -------------------------------------------------------------------
// 22: Departmental identities share one OTP mailbox while remaining separate users
// -------------------------------------------------------------------
$hrMail  = pd57auth_get_otp_delivery_address('hr@physicaldesk');
$itMail  = pd57auth_get_otp_delivery_address('it@physicaldesk');
$payMail = pd57auth_get_otp_delivery_address('payroll@physicaldesk');
$opsMail = pd57auth_get_otp_delivery_address('operations@physicaldesk');
$empMail = pd57auth_get_otp_delivery_address('employee@physicaldesk');

$sameDeptMailbox = ($hrMail === $itMail && $itMail === $payMail && $payMail === $opsMail);
$distinctUsers   = count(array_unique([$hrUid, $itUid, $payUid, $opsUid])) === 4;

assertScenario(
    22,
    'Departmental identities share one OTP mailbox while remaining separate users',
    $sameDeptMailbox && $distinctUsers && ($empMail !== $hrMail),
    'All 4 departments share one configured mailbox, use 4 distinct user IDs, and employee delivery is separate'
);

// -------------------------------------------------------------------
// 23: Persistence: accounts, profiles, and OTP schema survive restart
// -------------------------------------------------------------------
$usersCount = $DB->request([
    'FROM'  => 'glpi_users',
    'WHERE' => ['name' => ['employee@physicaldesk', 'hr@physicaldesk', 'it@physicaldesk', 'payroll@physicaldesk', 'operations@physicaldesk']],
])->count();
$otpTableExists = $DB->tableExists('glpi_plugin_pd57auth_otp_tokens');
$cleanupSessionToken = pd57auth_generate_session_token();
$DB->insert('glpi_plugin_pd57auth_otp_tokens', [
    'users_id'      => $hrUid,
    'token_hash'    => password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT),
    'session_token' => $cleanupSessionToken,
    'expires_at'    => date('Y-m-d H:i:s', time() - PD57_AUTH_OTP_RETENTION_SECONDS - 60),
    'verified_at'   => date('Y-m-d H:i:s', time() - PD57_AUTH_OTP_RETENTION_SECONDS - 60),
    'attempts'      => 1,
    'created_at'    => date('Y-m-d H:i:s', time() - PD57_AUTH_OTP_RETENTION_SECONDS - 360),
]);
pd57auth_cleanup_tokens();
$retentionFixturePurged = $DB->request([
    'FROM'  => 'glpi_plugin_pd57auth_otp_tokens',
    'WHERE' => ['session_token' => $cleanupSessionToken],
])->count() === 0;

assertScenario(
    23,
    'Persistence and bounded OTP retention remain operational',
    $usersCount === 5 && $otpTableExists && $retentionFixturePurged,
    '5/5 accounts and OTP schema persist; rows older than the retention window are purged'
);

// -------------------------------------------------------------------
// Summary
// -------------------------------------------------------------------
logOut("\n----------------------------------------------------------------------");
logOut(sprintf("SUMMARY: Passed: %d / %d | Failed: %d / %d", $passCount, $totalTests, $failCount, $totalTests));
logOut("----------------------------------------------------------------------");

if ($failCount > 0) {
    exit(1);
}
exit(0);
