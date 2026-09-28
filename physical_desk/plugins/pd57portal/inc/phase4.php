<?php
/**
 * PD57 Phase 4 operational intelligence.  This layer deliberately keeps
 * business decisions deterministic; MiniLM supplies a suggestion only.
 */
const PD57_P4_TEAMS = 'glpi_plugin_pd57portal_teams';
const PD57_P4_MEMBERS = 'glpi_plugin_pd57portal_team_memberships';
const PD57_P4_ASSIGNMENTS = 'glpi_plugin_pd57portal_assignment_history';
const PD57_P4_CLASSIFICATIONS = 'glpi_plugin_pd57portal_classifications';
const PD57_P4_NOTIFICATIONS = 'glpi_plugin_pd57portal_notifications';
const PD57_P4_HANDOFFS = 'glpi_plugin_pd57portal_handoffs';

function pd57_p4_taxonomy(): array {
    return [
        'IT' => ['Desktop & Device Support'=>['Device Support'], 'Identity & Access'=>['Account Lockout','Access Request'], 'Network & Connectivity'=>['Network Outage','Wi-Fi'], 'Business Applications'=>['Application Support'], 'IT Infrastructure'=>['Infrastructure']],
        'Payroll' => ['Salary Processing'=>['Salary Not Received','Salary Information'], 'Reimbursements'=>['Reimbursement'], 'Payslips & Payroll Documents'=>['Payslip'], 'Tax & Deductions'=>['Tax / Deduction'], 'Final Settlement'=>['Final Settlement']],
        'HR' => ['Employee Relations'=>['Employee Relations'], 'Leave & Attendance'=>['Leave Request','Attendance'], 'HR Documentation'=>['Experience Letter','HR Document'], 'Benefits'=>['Benefits'], 'Talent & Recruitment'=>['Recruitment']],
        'Studio Operations' => ['Facilities & Equipment'=>['Equipment Issue','Safety Incident'], 'Concierge / Front Desk'=>['Front Desk'], 'Studio Scheduling'=>['Studio Scheduling'], 'Inventory & Supplies'=>['Supplies'], 'Vendor & Maintenance'=>['Vendor / Maintenance']],
        'Other / unknown' => ['Employee Services Desk'=>['Manual Triage']],
    ];
}
function pd57_p4_designation_ladder(string $department): array {
    return [
        'IT'=>['IT Support Engineer','Senior IT Support Engineer','IT Support Team Lead','IT Support Manager','Senior IT Manager','Head of IT'],
        'Payroll'=>['Accounts Executive','Accountant','Senior Accountant','Accounts Manager','Finance Manager','Head of Finance'],
        'HR'=>['HR Executive','Senior HR Executive','Junior HR Business Partner','HR Business Partner','Senior HR Business Partner','HR Manager','Head of Human Resources'],
        'Studio Operations'=>['Concierge Team Member','Assistant Manager','Studio Manager','Studio Director','Director of Studio Performance & Growth'],
    ][$department] ?? [];
}
function pd57_p4_designation(string $department,int $level): string { return pd57_p4_designation_ladder($department)[$level-1]??'Employee Services Desk'; }
function pd57_p4_designation_level(string $department,string $designation): int { $level=array_search($designation,pd57_p4_designation_ladder($department),true); return $level===false?0:$level+1; }
function pd57_p4_valid_hierarchy(string $department,string $team,string $requestType): bool { $taxonomy=pd57_p4_taxonomy(); return isset($taxonomy[$department][$team]) && in_array($requestType,$taxonomy[$department][$team],true); }
/** Resolve a confirmed PD57 hierarchy to the configured helpdesk category.
 * This is deliberately a local allowlist: neither the classifier nor form data
 * can choose an arbitrary category ID. */
function pd57_p4_category_id_for_hierarchy(string $department,string $team,string $requestType): int {
    global $DB;
    $paths = [
        'IT|Network & Connectivity|Wi-Fi' => 'IT > Network > Wi-Fi',
        'IT|Network & Connectivity|Network Outage' => 'IT > Network > Internet',
        'IT|Identity & Access|Account Lockout' => 'IT > Identity & Access > Account Lockout',
        'IT|Identity & Access|Access Request' => 'IT > Identity & Access > Permission / Access',
        'IT|Desktop & Device Support|Device Support' => 'IT > Hardware > Laptop / Desktop',
        'IT|Business Applications|Application Support' => 'IT > Applications / Software',
        'IT|IT Infrastructure|Infrastructure' => 'IT > Service Desk / General Support',

        'Payroll|Salary Processing|Salary Not Received' => 'Payroll > Salary',
        'Payroll|Salary Processing|Salary Information' => 'Payroll > Salary',
        'Payroll|Reimbursements|Reimbursement' => 'Payroll > Reimbursement',
        'Payroll|Payslips & Payroll Documents|Payslip' => 'Payroll > Payslip',
        'Payroll|Tax & Deductions|Tax / Deduction' => 'Payroll > Deduction',
        'Payroll|Final Settlement|Final Settlement' => 'Payroll > Other Payroll',

        'HR|Employee Relations|Employee Relations' => 'HR > Workplace / People Support',
        'HR|Leave & Attendance|Leave Request' => 'HR > Leave & Attendance > Leave Request',
        'HR|Leave & Attendance|Attendance' => 'HR > Leave & Attendance > Attendance Correction',
        'HR|HR Documentation|Experience Letter' => 'HR > Employee Records',
        'HR|HR Documentation|HR Document' => 'HR > Employee Records',
        'HR|Benefits|Benefits' => 'HR > Benefits',
        'HR|Talent & Recruitment|Recruitment' => 'HR > Hiring & Onboarding',

        'Studio Operations|Facilities & Equipment|Equipment Issue' => 'Operations > Studio Equipment',
        'Studio Operations|Facilities & Equipment|Safety Incident' => 'Operations > Safety / Operational Incident',
        'Studio Operations|Concierge / Front Desk|Front Desk' => 'Operations > Studio Operations',
        'Studio Operations|Studio Scheduling|Studio Scheduling' => 'Operations > Studio Operations',
        'Studio Operations|Inventory & Supplies|Supplies' => 'Operations > Supplies / Inventory',
        'Studio Operations|Vendor & Maintenance|Vendor / Maintenance' => 'Operations > Vendor Issue',

        'Other / unknown|Employee Services Desk|Manual Triage' => 'Other > Manual Triage',
    ];
    $path = $paths[$department . '|' . $team . '|' . $requestType] ?? null;
    if ($path === null) return 0;
    $row = $DB->request(['SELECT'=>['id'],'FROM'=>'glpi_itilcategories','WHERE'=>['completename'=>$path,'is_helpdeskvisible'=>1],'LIMIT'=>1])->current();
    return (int)($row['id'] ?? 0);
}
function pd57_p4_confirm_hierarchy(int $ticketId,array $hierarchy,string $note='employee_confirmed'): bool { global $DB; if(!pd57_p4_valid_hierarchy($hierarchy['department']??'', $hierarchy['team']??'', $hierarchy['request_type']??'')) return false; $row=pd57_p4_current_classification($ticketId); if(!$row)return false; $DB->update(PD57_P4_CLASSIFICATIONS,['final_department'=>$hierarchy['department'],'final_team'=>$hierarchy['team'],'final_request_type'=>$hierarchy['request_type'],'correction_note'=>$note],['id'=>(int)$row['id']]); return true; }

/** High precision only: avoids routing from the employee's own department. */
function pd57_p4_semantic_profiles(): array
{
    return [
        'IT' => [
            '_keywords' => [
                'computer','laptop','desktop','device','software','application',
                'network','internet','wifi','wi-fi','login','account','system'
            ],

            'Desktop & Device Support' => [
                '_keywords' => [
                    'laptop','desktop','computer','device','hardware',
                    'printer','keyboard','mouse','peripheral'
                ],
                'Device Support' => [
                    'laptop','desktop','printer','keyboard','mouse','peripheral',
                    'hardware','device not working','device issue',
                    'will not boot',"won't boot",'broken laptop'
                ],
            ],

            'Identity & Access' => [
                '_keywords' => [
                    'login','password','account','access','permission',
                    'locked','sign in','sign-in'
                ],
                'Account Lockout' => [
                    'account locked','locked out','account is locked',
                    'too many attempts','cannot log in because locked'
                ],
                'Access Request' => [
                    'access request','need access','permission','permissions',
                    'new account','password reset','cannot login','cannot log in',
                    'sign in','login issue'
                ],
            ],

            'Network & Connectivity' => [
                '_keywords' => [
                    'network','internet','connectivity','router','lan',
                    'wireless','wifi','wi-fi','connection'
                ],
                'Wi-Fi' => [
                    'wifi','wi-fi','wireless','wireless network','ssid',
                    'access point','connects to wifi','connect to wifi'
                ],
                'Network Outage' => [
                    'no internet','internet down','network down','network outage',
                    'offline','lan','router','connectivity issue',
                    'connection dropped','cannot reach network'
                ],
            ],

            'Business Applications' => [
                '_keywords' => [
                    'application','software','portal','business application',
                    'internal system','web app'
                ],
                'Application Support' => [
                    'application error','software error','portal not working',
                    'portal will not open',"portal won't open",
                    'application not working','app not working',
                    'system error','cannot open portal'
                ],
            ],

            'IT Infrastructure' => [
                '_keywords' => [
                    'infrastructure','server','security','cybersecurity',
                    'phishing','cctv','audio visual','audio','visual'
                ],
                'Infrastructure' => [
                    'server','infrastructure','phishing','security incident',
                    'cybersecurity','cctv','audio visual','av system',
                    'service desk'
                ],
            ],
        ],

        'Payroll' => [
            '_keywords' => [
                'payroll','salary','pay','wages','payslip','pay slip',
                'reimbursement','deduction','tax'
            ],

            'Salary Processing' => [
                '_keywords' => [
                    'salary','wages','credited','credit','payment','pay'
                ],
                'Salary Not Received' => [
                    'salary not received','salary has not arrived',
                    'not received my salary','salary missing','salary delayed',
                    'not credited',"hasn't been credited",'has not been credited',
                    'wages not received','not been paid'
                ],
                'Salary Information' => [
                    'salary information','salary amount','salary details',
                    'pay information','salary query','pay query'
                ],
            ],

            'Reimbursements' => [
                '_keywords' => [
                    'reimbursement','expense','claim','medical reimbursement'
                ],
                'Reimbursement' => [
                    'reimbursement','expense claim','expense reimbursement',
                    'medical reimbursement','claim pending','claim not paid'
                ],
            ],

            'Payslips & Payroll Documents' => [
                '_keywords' => [
                    'payslip','pay slip','salary slip','payroll document'
                ],
                'Payslip' => [
                    'payslip','pay slip','salary slip','download payslip'
                ],
            ],

            'Tax & Deductions' => [
                '_keywords' => [
                    'tax','deduction','deducted','tds','withholding'
                ],
                'Tax / Deduction' => [
                    'tax deduction','salary deduction','unexpected deduction',
                    'deducted from salary','tds','tax document'
                ],
            ],

            'Final Settlement' => [
                '_keywords' => [
                    'final settlement','full and final','fnf','f&f',
                    'last working day settlement'
                ],
                'Final Settlement' => [
                    'final settlement','full and final','fnf','f&f',
                    'exit settlement'
                ],
            ],
        ],

        'HR' => [
            '_keywords' => [
                'hr','human resources','employee','employment','people',
                'leave','attendance','benefit','document','letter'
            ],

            'Employee Relations' => [
                '_keywords' => [
                    'employee relations','workplace','people issue',
                    'hr policy','policy query','manager issue'
                ],
                'Employee Relations' => [
                    'employee relations','workplace concern','people issue',
                    'hr policy','policy question','manager concern'
                ],
            ],

            'Leave & Attendance' => [
                '_keywords' => [
                    'leave','attendance','absent','shift','schedule',
                    'clock in','clock out'
                ],
                'Leave Request' => [
                    'leave request','apply for leave','request leave',
                    'time off','annual leave','sick leave'
                ],
                'Attendance' => [
                    'attendance incorrect','attendance correction',
                    'attendance wrong','clock in','clock out',
                    'missing attendance','shift attendance'
                ],
            ],

            'HR Documentation' => [
                '_keywords' => [
                    'document','documentation','letter','certificate',
                    'employee record','employment record'
                ],
                'Experience Letter' => [
                    'experience letter','employment letter',
                    'proof of employment','service certificate'
                ],
                'HR Document' => [
                    'hr document','employment document','employee record',
                    'employment certificate','document request'
                ],
            ],

            'Benefits' => [
                '_keywords' => [
                    'benefit','benefits','insurance','medical insurance',
                    'employee benefit'
                ],
                'Benefits' => [
                    'benefits','employee benefits','insurance',
                    'medical insurance','benefit enrollment'
                ],
            ],

            'Talent & Recruitment' => [
                '_keywords' => [
                    'recruitment','hiring','onboarding','candidate',
                    'new joiner','new hire'
                ],
                'Recruitment' => [
                    'recruitment','hiring','candidate','interview',
                    'onboarding','new hire'
                ],
            ],
        ],

        'Studio Operations' => [
            '_keywords' => [
                'studio','facility','equipment','class','front desk',
                'concierge','inventory','supplies','vendor',
                'treadmill','barre','dumbbell','weights'
            ],

            'Facilities & Equipment' => [
                '_keywords' => [
                    'equipment','machine','fitness equipment','treadmill',
                    'barre','dumbbell','weight','weights','resistance band',
                    'mat','bike','cardio','hvac','ac','air conditioning',
                    'electrical','plumbing','facility','housekeeping'
                ],
                'Equipment Issue' => [
                    'treadmill','barre','dumbbell','weights','resistance band',
                    'exercise equipment','cardio equipment','machine broken',
                    'equipment broken','equipment not working','damaged equipment',
                    'not working','malfunction','hvac','air conditioning',
                    'plumbing','electrical'
                ],
                'Safety Incident' => [
                    'unsafe','safety','sparking','smoke','burning smell',
                    'injury','injured','hazard','dangerous','loose barre',
                    'electric shock','fire risk'
                ],
            ],

            'Concierge / Front Desk' => [
                '_keywords' => [
                    'front desk','concierge','reception','check in','check-in',
                    'guest','member check in'
                ],
                'Front Desk' => [
                    'front desk','concierge','reception','check in','check-in',
                    'member check in','guest issue'
                ],
            ],

            'Studio Scheduling' => [
                '_keywords' => [
                    'studio schedule','class schedule','timetable',
                    'class timing','studio timing','schedule'
                ],
                'Studio Scheduling' => [
                    'class schedule','studio schedule','timetable',
                    'class timing','schedule change'
                ],
            ],

            'Inventory & Supplies' => [
                '_keywords' => [
                    'inventory','supplies','stock','towels','cleaning supplies',
                    'consumables'
                ],
                'Supplies' => [
                    'inventory','supplies','out of stock','stock',
                    'towels','cleaning supplies','consumables'
                ],
            ],

            'Vendor & Maintenance' => [
                '_keywords' => [
                    'vendor','technician','contractor','maintenance visit',
                    'service provider','repair vendor'
                ],
                'Vendor / Maintenance' => [
                    'vendor','technician','contractor','maintenance visit',
                    'service provider','repair vendor','vendor issue'
                ],
            ],
        ],
    ];
}

function pd57_p4_semantic_keyword_hits(string $text, array $keywords): array
{
    $hits = [];

    foreach (array_unique($keywords) as $keyword) {
        $needle = mb_strtolower(trim((string)$keyword));

        if ($needle === '') {
            continue;
        }

        // Match complete words/phrases instead of arbitrary substrings.
        // Examples:
        // "ac" must not match "access"
        // "mat" must not match "information".
        $pattern = '~(?<![\p{L}\p{N}])'
            . preg_quote($needle, '~')
            . '(?![\p{L}\p{N}])~u';

        if (preg_match($pattern, $text) === 1) {
            $hits[] = $needle;
        }
    }

    return $hits;
}

/**
 * Hierarchical semantic dictionary.
 *
 * Department evidence is inherited by Team and Request Type.
 * Team evidence is inherited by Request Type.
 * More-specific matches receive higher weight.
 */
function pd57_p4_semantic_route(string $text): ?array
{
    $text = mb_strtolower(strip_tags($text));
    $profiles = pd57_p4_semantic_profiles();
    $candidates = [];

    foreach ($profiles as $department => $departmentProfile) {
        $departmentHits = pd57_p4_semantic_keyword_hits(
            $text,
            $departmentProfile['_keywords'] ?? []
        );

        foreach ($departmentProfile as $team => $teamProfile) {
            if ($team === '_keywords' || !is_array($teamProfile)) {
                continue;
            }

            $teamHits = pd57_p4_semantic_keyword_hits(
                $text,
                $teamProfile['_keywords'] ?? []
            );

            foreach ($teamProfile as $requestType => $requestKeywords) {
                if ($requestType === '_keywords' || !is_array($requestKeywords)) {
                    continue;
                }

                $leafHits = pd57_p4_semantic_keyword_hits(
                    $text,
                    $requestKeywords
                );

                // A parent word alone must never force a route.
                if (!$teamHits && !$leafHits) {
                    continue;
                }

                $score =
                    count($departmentHits) * 1
                    + count($teamHits) * 2
                    + count($leafHits) * 4;

                $candidates[] = [
                    'route' => [$department, $team, $requestType],
                    'score' => $score,
                    'department_hits' => count($departmentHits),
                    'team_hits' => count($teamHits),
                    'leaf_hits' => count($leafHits),
                    'matches' => array_values(array_unique(array_merge(
                        $departmentHits,
                        $teamHits,
                        $leafHits
                    ))),
                ];
            }
        }
    }

    if (!$candidates) {
        return null;
    }

    usort($candidates, static function ($a, $b) {
        return [
            (int)$b['score'],
            (int)$b['leaf_hits'],
            (int)$b['team_hits'],
        ] <=> [
            (int)$a['score'],
            (int)$a['leaf_hits'],
            (int)$a['team_hits'],
        ];
    });

    $top = $candidates[0];
    $secondScore = isset($candidates[1]) ? (int)$candidates[1]['score'] : 0;
    $top['margin'] = (int)$top['score'] - $secondScore;

    $top['strong'] =
        (int)$top['score'] >= 4
        && (int)$top['leaf_hits'] > 0
        && (int)$top['margin'] >= 2;

    return $top;
}

/**
 * Backward-compatible high-precision router.
 */
function pd57_p4_fast_route(string $text): ?array
{
    $t = mb_strtolower($text);

    // Preserve the already accepted deterministic high-precision routes.
    // This keeps the existing 29/29 acceptance contract intact.
    $legacyRoutes = [
        [
            ['treadmill', 'equipment', 'unsafe to use', 'studio equipment'],
            ['Studio Operations', 'Facilities & Equipment', 'Equipment Issue']
        ],
        [
            ['salary', 'wages', 'salary has not arrived', 'not received my salary'],
            ['Payroll', 'Salary Processing', 'Salary Not Received']
        ],
        [
            ['medical reimbursement', 'reimbursement'],
            ['Payroll', 'Reimbursements', 'Reimbursement']
        ],
        [
            ['payslip', 'pay slip'],
            ['Payroll', 'Payslips & Payroll Documents', 'Payslip']
        ],
        [
            ['account is locked', 'account locked', 'locked out'],
            ['IT', 'Identity & Access', 'Account Lockout']
        ],
        [
            ['wi-fi', 'wifi', 'wireless network'],
            ['IT', 'Network & Connectivity', 'Wi-Fi']
        ],
        [
            ['experience letter'],
            ['HR', 'HR Documentation', 'Experience Letter']
        ],
    ];

    foreach ($legacyRoutes as [$signals, $route]) {
        foreach ($signals as $signal) {
            if (str_contains($t, $signal)) {
                return $route;
            }
        }
    }

    // The broader inherited semantic dictionary remains available for
    // additional high-certainty routes not covered by the legacy set.
    $semantic = pd57_p4_semantic_route($text);

    return $semantic && !empty($semantic['strong'])
        ? $semantic['route']
        : null;
}

/**
 * Fuse hierarchical lexical evidence with MiniLM/NLI evidence.
 *
 * The neural confidence remains the neural confidence; lexical evidence is
 * tracked separately instead of fabricating a larger model probability.
 */
function pd57_p4_preview_classification(string $text): array
{
    $semantic = pd57_p4_semantic_route($text);
    $ai = plugin_pd57classifier_call_service($text);

    $hierarchy = $ai['hierarchy'] ?? [
        'department' => 'Other / unknown',
        'team' => 'Employee Services Desk',
        'request_type' => 'Manual Triage',
        'confidence' => 0.0,
    ];

    $aiValid = pd57_p4_valid_hierarchy(
        (string)($hierarchy['department'] ?? ''),
        (string)($hierarchy['team'] ?? ''),
        (string)($hierarchy['request_type'] ?? '')
    );

    if ($semantic && !empty($semantic['strong'])) {
        [$department, $team, $requestType] = $semantic['route'];

        $sameParent =
            $aiValid
            && (string)$hierarchy['department'] === $department
            && (string)$hierarchy['team'] === $team;

        $semanticMargin = (int)($semantic['margin'] ?? 0);
        $semanticScore = (int)($semantic['score'] ?? 0);
        $semanticLeafHits = (int)($semantic['leaf_hits'] ?? 0);
        $aiConfidence = (float)($hierarchy['confidence'] ?? 0.0);

        $semanticCanOverride =
            // If the AI result is unusable, a genuinely strong semantic
            // hierarchy may rescue the request.
            !$aiValid

            // Within the same department/team, allow the dictionary to
            // distinguish the leaf only when it clearly beats sibling leaves.
            || (
                $sameParent
                && $semanticLeafHits > 0
                && $semanticMargin >= 2
            )

            // Cross-department override is intentionally conservative.
            || (
                !$sameParent
                && $semanticScore >= 12
                && $semanticMargin >= 4
                && $aiConfidence < 0.70
            );

        if ($semanticCanOverride) {
            $hierarchy = [
                'department' => $department,
                'team' => $team,
                'request_type' => $requestType,
                'confidence' => $aiValid
                    ? (float)($hierarchy['confidence'] ?? 0.0)
                    : 0.0,
            ];
        }
    }

    if (!pd57_p4_valid_hierarchy(
        (string)($hierarchy['department'] ?? ''),
        (string)($hierarchy['team'] ?? ''),
        (string)($hierarchy['request_type'] ?? '')
    )) {
        $hierarchy = [
            'department' => 'Other / unknown',
            'team' => 'Employee Services Desk',
            'request_type' => 'Manual Triage',
            'confidence' => 0.0,
        ];
    }

    $ai['hierarchy'] = $hierarchy;
    $ai['semantic_evidence'] = $semantic;

    return $ai;
}


function pd57_p4_priority(string $text, string $requestType = ''): array {
    $t = mb_strtolower($text); $score = 2; $reasons = [];
    if ($requestType === 'Salary Not Received') { $score = max($score, 3); $reasons[] = 'payroll-impact'; }
    if (preg_match('/three months|[2-9] months/', $t)) { $score = max($score, 4); $reasons[] = 'extended-duration'; }
    if (preg_match('/cannot work|can.t work|cannot operate|everyone|all staff|outage/', $t)) { $score = max($score, 3); $reasons[] = 'work-blocked-or-wide-impact'; }
    if (preg_match('/unsafe|injury|safety/', $t)) { $score = max($score, 4); $reasons[] = 'safety-impact'; }
    if (preg_match('/treatment|medical.*today|today.*treatment/', $t)) { $score = max($score, 3); $reasons[] = 'time-sensitive-financial-impact'; }
    if (preg_match('/visa deadline|deadline.*today/', $t)) { $score = max($score, 3); $reasons[] = 'explicit-deadline'; }
    $labels = [1=>'Low',2=>'Medium',3=>'High',4=>'Critical'];
    return ['priority'=>$labels[$score], 'reason_codes'=>$reasons ?: ['limited-impact'], 'summary'=>$labels[$score] . ' priority. ' . ($reasons ? 'Current information indicates ' . implode(', ', $reasons) . '.' : 'Current information indicates limited business impact and no immediate work stoppage.')];
}

/** Stable, testable fair selection: count, oldest assignment, then person id. */
function pd57_p4_choose_assignee(array $eligible): ?array {
    $eligible = array_values(array_filter($eligible, static fn($p) => !empty($p['is_active']) && empty($p['is_unavailable'])));
    if (!$eligible) return null;
    usort($eligible, static function($a, $b) {
        return [(int)($a['prior_assignment_count'] ?? 0), (string)($a['last_assigned_at'] ?? ''), (int)$a['people_id']]
            <=> [(int)($b['prior_assignment_count'] ?? 0), (string)($b['last_assigned_at'] ?? ''), (int)$b['people_id']];
    });
    return $eligible[0];
}

function pd57_p4_install_schema(): void {
    global $DB;
    // Schema DDL must never implicitly commit a caller's ownership transaction.
    // Migrations run before serving requests. Transactional callers only use it.
    if (pd57_p4_handoff_in_transaction()) {
        foreach ([PD57_P4_TEAMS, PD57_P4_MEMBERS, PD57_P4_ASSIGNMENTS,
                  PD57_P4_CLASSIFICATIONS, PD57_P4_NOTIFICATIONS, PD57_P4_HANDOFFS] as $table) {
            if (!$DB->tableExists($table)) throw new RuntimeException('Phase 4 schema migration is required.');
        }
        return;
    }
    $sql = [
        "CREATE TABLE IF NOT EXISTS `".PD57_P4_TEAMS."` (`id` INT UNSIGNED AUTO_INCREMENT,`department` VARCHAR(80) NOT NULL,`name` VARCHAR(120) NOT NULL,`is_active` TINYINT(1) NOT NULL DEFAULT 1,`created_at` DATETIME NOT NULL,PRIMARY KEY(`id`),UNIQUE KEY `department_team`(`department`,`name`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `".PD57_P4_MEMBERS."` (`id` BIGINT UNSIGNED AUTO_INCREMENT,`teams_id` INT UNSIGNED NOT NULL,`people_id` INT UNSIGNED NOT NULL,`designation` VARCHAR(120) NOT NULL,`designation_level` TINYINT UNSIGNED NOT NULL DEFAULT 1,`is_active` TINYINT(1) NOT NULL DEFAULT 1,`is_unavailable` TINYINT(1) NOT NULL DEFAULT 0,`effective_from` DATE NOT NULL,`effective_until` DATE DEFAULT NULL,PRIMARY KEY(`id`),KEY `eligible`(`teams_id`,`designation_level`,`is_active`,`is_unavailable`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `".PD57_P4_ASSIGNMENTS."` (`id` BIGINT UNSIGNED AUTO_INCREMENT,`tickets_id` INT UNSIGNED NOT NULL,`department` VARCHAR(80) DEFAULT NULL,`teams_id` INT UNSIGNED DEFAULT NULL,`team_name` VARCHAR(120) DEFAULT NULL,`designation_level` TINYINT UNSIGNED DEFAULT NULL,`designation` VARCHAR(120) NOT NULL DEFAULT '',`people_id` INT UNSIGNED DEFAULT NULL,`team_memberships_id` BIGINT UNSIGNED DEFAULT NULL,`reason` VARCHAR(120) NOT NULL,`assignment_source` VARCHAR(40) DEFAULT NULL,`fallback_reason` VARCHAR(120) DEFAULT NULL,`assigned_at` DATETIME DEFAULT NULL,`created_at` DATETIME NOT NULL,PRIMARY KEY(`id`),KEY `load`(`teams_id`,`people_id`,`created_at`),KEY `ticket_history`(`tickets_id`,`created_at`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `".PD57_P4_CLASSIFICATIONS."` (`id` BIGINT UNSIGNED AUTO_INCREMENT,`tickets_id` INT UNSIGNED NOT NULL,`suggested_department` VARCHAR(80) NOT NULL,`suggested_team` VARCHAR(120) NOT NULL,`suggested_request_type` VARCHAR(120) NOT NULL,`confidence` DECIMAL(5,4) NOT NULL DEFAULT 0,`final_department` VARCHAR(80) DEFAULT NULL,`final_team` VARCHAR(120) DEFAULT NULL,`final_request_type` VARCHAR(120) DEFAULT NULL,`correction_note` VARCHAR(500) DEFAULT NULL,`created_at` DATETIME NOT NULL,PRIMARY KEY(`id`),KEY `ticket`(`tickets_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `".PD57_P4_NOTIFICATIONS."` (`id` BIGINT UNSIGNED AUTO_INCREMENT,`tickets_id` INT UNSIGNED NOT NULL,`event_type` VARCHAR(60) NOT NULL,`event_key` VARCHAR(190) NOT NULL,`recipient_people_id` INT UNSIGNED DEFAULT NULL,`payload_json` LONGTEXT NOT NULL,`created_at` DATETIME NOT NULL,PRIMARY KEY(`id`),UNIQUE KEY `dedupe`(`event_key`),KEY `ticket`(`tickets_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `".PD57_P4_HANDOFFS."` (`id` BIGINT UNSIGNED AUTO_INCREMENT,`tickets_id` INT UNSIGNED NOT NULL,`handoff_sequence` INT UNSIGNED NOT NULL,`escalation_type` VARCHAR(40) NOT NULL,`from_people_id` INT UNSIGNED DEFAULT NULL,`from_designation_level` TINYINT UNSIGNED DEFAULT NULL,`from_designation_label` VARCHAR(120) NOT NULL DEFAULT 'Employee Services Desk',`from_team` VARCHAR(120) DEFAULT NULL,`to_people_id` INT UNSIGNED DEFAULT NULL,`to_designation_level` TINYINT UNSIGNED DEFAULT NULL,`to_designation_label` VARCHAR(120) NOT NULL DEFAULT 'Employee Services Desk',`to_team` VARCHAR(120) DEFAULT NULL,`department` VARCHAR(80) NOT NULL,`reason_code` VARCHAR(80) DEFAULT NULL,`reason_text` VARCHAR(255) DEFAULT NULL,`internal_handoff_note` TEXT DEFAULT NULL,`priority_at_handoff` VARCHAR(12) DEFAULT NULL,`previous_level_started_at` DATETIME DEFAULT NULL,`handed_off_at` DATETIME NOT NULL,`source_operational_event_id` BIGINT UNSIGNED DEFAULT NULL,`created_by_users_id` INT UNSIGNED DEFAULT NULL,`dedupe_key` VARCHAR(190) NOT NULL,`created_at` DATETIME NOT NULL,PRIMARY KEY(`id`),UNIQUE KEY `handoff_dedupe`(`dedupe_key`),UNIQUE KEY `handoff_sequence_once`(`tickets_id`,`handoff_sequence`),KEY `ticket_handoff`(`tickets_id`,`handed_off_at`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ]; foreach ($sql as $q) $DB->doQuery($q);
    foreach ([
        'department'=>'VARCHAR(80) DEFAULT NULL','team_name'=>'VARCHAR(120) DEFAULT NULL',
        'designation_level'=>'TINYINT UNSIGNED DEFAULT NULL','team_memberships_id'=>'BIGINT UNSIGNED DEFAULT NULL',
        'assignment_source'=>'VARCHAR(40) DEFAULT NULL','fallback_reason'=>'VARCHAR(120) DEFAULT NULL',
        'assigned_at'=>'DATETIME DEFAULT NULL',
    ] as $column=>$definition) if(!$DB->fieldExists(PD57_P4_ASSIGNMENTS,$column)) $DB->doQuery('ALTER TABLE `'.PD57_P4_ASSIGNMENTS.'` ADD COLUMN `'.$column.'` '.$definition);
    foreach (pd57_p4_taxonomy() as $department=>$teams) foreach (array_keys($teams) as $name) if (!$DB->request(['FROM'=>PD57_P4_TEAMS,'WHERE'=>['department'=>$department,'name'=>$name],'LIMIT'=>1])->current()) $DB->insert(PD57_P4_TEAMS,['department'=>$department,'name'=>$name,'is_active'=>1,'created_at'=>date('Y-m-d H:i:s')]);
    if ($DB->tableExists(PD57_ADMIN_TABLE_PEOPLE) && !$DB->fieldExists(PD57_ADMIN_TABLE_PEOPLE,'employee_id')) $DB->doQuery('ALTER TABLE `'.PD57_ADMIN_TABLE_PEOPLE.'` ADD COLUMN `employee_id` VARCHAR(80) DEFAULT NULL, ADD UNIQUE KEY `employee_id_unique` (`employee_id`)');
    pd57_p4_seed_demo_memberships();
}

function pd57_p4_seed_demo_memberships(): void { global $DB; if(!$DB->tableExists(PD57_ADMIN_TABLE_PEOPLE)) return; $now=date('Y-m-d H:i:s'); foreach([2=>'PD57-EMP-IT-A',3=>'PD57-EMP-IT-B',4=>'PD57-EMP-IT-C',5=>'PD57-EMP-PAY-A',6=>'PD57-EMP-PAY-B'] as $id=>$employeeId) $DB->update(PD57_ADMIN_TABLE_PEOPLE,['employee_id'=>$employeeId,'updated_at'=>$now],['id'=>$id,'employee_id'=>null]); $seed=[['IT','Desktop & Device Support',[2,3,4],'IT Support Engineer'],['IT','Network & Connectivity',[2,3,4],'IT Support Engineer'],['Payroll','Salary Processing',[3,4,5],'Accounts Executive'],['Studio Operations','Facilities & Equipment',[5],'Concierge Team Member'],['HR','HR Documentation',[2],'HR Executive']]; foreach($seed as [$department,$name,$people,$designation]){$team=pd57_p4_team($department,$name);if(!$team)continue;foreach($people as $person){if(!$DB->request(['FROM'=>PD57_P4_MEMBERS,'WHERE'=>['teams_id'=>(int)$team['id'],'people_id'=>$person,'designation'=>$designation,'effective_from'=>'2026-01-01'],'LIMIT'=>1])->current())$DB->insert(PD57_P4_MEMBERS,['teams_id'=>(int)$team['id'],'people_id'=>$person,'designation'=>$designation,'designation_level'=>1,'is_active'=>1,'is_unavailable'=>0,'effective_from'=>'2026-01-01']);}}
}

function pd57_p4_record_classification(int $ticketId, array $route, float $confidence, ?array $final = null): void { global $DB; pd57_p4_install_schema(); $DB->insert(PD57_P4_CLASSIFICATIONS,['tickets_id'=>$ticketId,'suggested_department'=>$route[0]??'Other / unknown','suggested_team'=>$route[1]??'Employee Services Desk','suggested_request_type'=>$route[2]??'Manual Triage','confidence'=>$confidence,'final_department'=>$final[0]??null,'final_team'=>$final[1]??null,'final_request_type'=>$final[2]??null,'created_at'=>date('Y-m-d H:i:s')]); }
function pd57_p4_notify(int $ticketId, string $type, string $key, array $payload = []): bool { global $DB; pd57_p4_install_schema(); if ($DB->request(['FROM'=>PD57_P4_NOTIFICATIONS,'WHERE'=>['event_key'=>$key],'LIMIT'=>1])->current()) return false; $DB->insert(PD57_P4_NOTIFICATIONS,['tickets_id'=>$ticketId,'event_type'=>$type,'event_key'=>$key,'recipient_people_id'=>$payload['people_id']??null,'payload_json'=>json_encode($payload,JSON_UNESCAPED_UNICODE),'created_at'=>date('Y-m-d H:i:s')]); return true; }
// Handoff capture/reconstruction is kept separate from routing decisions.
require_once __DIR__ . '/handoff.php';

function pd57_p4_team(string $department, string $team): ?array { global $DB; return $DB->request(['FROM'=>PD57_P4_TEAMS,'WHERE'=>['department'=>$department,'name'=>$team,'is_active'=>1],'LIMIT'=>1])->current() ?: null; }
function pd57_p4_current_classification(int $ticketId): ?array { global $DB; return $DB->request(['FROM'=>PD57_P4_CLASSIFICATIONS,'WHERE'=>['tickets_id'=>$ticketId],'ORDER'=>['id DESC'],'LIMIT'=>1])->current() ?: null; }
function pd57_p4_hierarchy(int $ticketId): array {
    $c=pd57_p4_current_classification($ticketId)?:[];
    return [
        'department'=>$c['final_department']??$c['suggested_department']??'Other / unknown',
        'team'=>$c['final_team']??$c['suggested_team']??'Employee Services Desk',
    ];
}
function pd57_p4_display_department(string $department): string {
    return match ($department) {
        'Operations' => 'Studio Operations',
        'Central', 'Central Triage', 'Manual Triage' => 'Employee Services Desk',
        default => $department,
    };
}
function pd57_p4_visible_designation_label(string $designation): string {
    if($designation==='Employee Services Desk')return $designation;
    foreach(['IT','Payroll','HR','Studio Operations'] as $department) if(in_array($designation,pd57_p4_designation_ladder($department),true))return $designation;
    return 'Employee Services Desk';
}
function pd57_p4_display_designation(string $department,int $level=0,string $stored=''): string {
    $department=pd57_p4_display_department($department);
    if($stored==='Employee Services Desk' || $department==='Employee Services Desk')return 'Employee Services Desk';
    $ladder=pd57_p4_designation_ladder($department);
    if($level>0 && isset($ladder[$level-1]))return $ladder[$level-1];
    return in_array($stored,$ladder,true)?$stored:'Employee Services Desk';
}
function pd57_p4_legacy_level(int $ticketId,string $department): int {
    global $DB; $prefix=['IT'=>'PD57_IT_','Payroll'=>'PD57_PAYROLL_','HR'=>'PD57_HR_','Studio Operations'=>'PD57_OPS_'][$department]??''; if($prefix==='')return 0;
    foreach($DB->request(['SELECT'=>['g.name'],'FROM'=>'glpi_groups_tickets AS gt','INNER JOIN'=>['glpi_groups AS g'=>['FKEY'=>['gt'=>'groups_id','g'=>'id']]],'WHERE'=>['gt.tickets_id'=>$ticketId,'gt.type'=>2]]) as $group){$name=(string)$group['name'];if(!str_starts_with($name,$prefix))continue;if(str_ends_with($name,'_L1'))return 1;if(str_ends_with($name,'_L2'))return 2;if(str_ends_with($name,'_LEAD'))return 3;}
    return 0;
}
function pd57_p4_membership_team_names(string $department,int $personId,int $level,string $asOf=''): array {
    global $DB; if($personId<1||$level<1)return []; $department=pd57_p4_display_department($department); $asOf=$asOf?:date('Y-m-d'); $names=[];
    foreach($DB->request(['SELECT'=>['t.name'],'FROM'=>PD57_P4_MEMBERS.' AS m','INNER JOIN'=>[PD57_P4_TEAMS.' AS t'=>['FKEY'=>['m'=>'teams_id','t'=>'id']]],'WHERE'=>['m.people_id'=>$personId,'m.designation_level'=>$level,'m.is_active'=>1,'t.department'=>$department,'t.is_active'=>1,['m.effective_from'=>['<=',$asOf]],'OR'=>['m.effective_until'=>null,['m.effective_until'=>['>=',$asOf]]]],'ORDER'=>['t.name ASC']]) as $team)$names[]=(string)$team['name'];
    return array_values(array_unique($names));
}
function pd57_p4_ticket_ownership(int $ticketId,?array $state=null): array {
    global $DB; $hierarchy=pd57_p4_hierarchy($ticketId); $department=pd57_p4_display_department($hierarchy['department']); $teamName=$hierarchy['team'];
    if($state===null && defined('PD57_OPS_STATE') && $DB->tableExists(PD57_OPS_STATE))$state=$DB->request(['FROM'=>PD57_OPS_STATE,'WHERE'=>['tickets_id'=>$ticketId],'LIMIT'=>1])->current()?:null;
    $source=$state;
    if(!$source && $DB->tableExists(PD57_P4_ASSIGNMENTS))$source=$DB->request(['FROM'=>PD57_P4_ASSIGNMENTS,'WHERE'=>['tickets_id'=>$ticketId],'ORDER'=>['id DESC'],'LIMIT'=>1])->current()?:null;
    $level=(int)($source['designation_level']??0); if($level<1)$level=pd57_p4_legacy_level($ticketId,$department);
    $designation=pd57_p4_display_designation($department,$level,(string)($source['designation']??''));
    $explicitFallback=$source && ($designation==='Employee Services Desk') && (!empty($source['fallback_reason']) || $level>count(pd57_p4_designation_ladder($department)));
    $person=null; if(!empty($source['people_id']))$person=$DB->request(['FROM'=>PD57_ADMIN_TABLE_PEOPLE,'WHERE'=>['id'=>(int)$source['people_id']],'LIMIT'=>1])->current()?:null;
    return ['ticket_id'=>$ticketId,'department'=>$department,'team'=>$explicitFallback?'Employee Services Desk':$teamName,'designation'=>$designation,'designation_level'=>$level,'person'=>$person,'owner_name'=>$person['display_name']??'Unassigned','source'=>$source?'phase4_state_or_history':($level>0?'compatible_group':'safe_fallback')];
}
function pd57_p4_event_ownership(int $ticketId,array $event): array {
    global $DB; $hierarchy=pd57_p4_hierarchy($ticketId); $defaultDepartment=pd57_p4_display_department($hierarchy['department']); $result=[];
    foreach(['from','to'] as $side){$department=$defaultDepartment;$role=null;$roleId=(int)($event[$side.'_roles_id']??0);if($roleId){$role=$DB->request(['FROM'=>PD57_ADMIN_TABLE_ROLES,'WHERE'=>['id'=>$roleId],'LIMIT'=>1])->current();if($role)$department=pd57_p4_display_department((string)$role['department']);}$level=(int)($event[$side.'_designation_level']??($role['escalation_level']??0));$designation=pd57_p4_display_designation($department,$level,(string)($event[$side.'_designation']??''));$person=null;$personId=(int)($event[$side.'_people_id']??0);if($personId)$person=$DB->request(['FROM'=>PD57_ADMIN_TABLE_PEOPLE,'WHERE'=>['id'=>$personId],'LIMIT'=>1])->current()?:null;$result[$side.'_designation']=$designation;$result[$side.'_person']=$person['display_name']??null;$result[$side.'_label']=($person?($person['display_name'].' · '):'').$designation;}
    return $result;
}
function pd57_p4_visible_system_text(string $text,string $department): string {
    $maps=['PD57_HR_L1'=>'HR Executive','PD57_HR_L2'=>'Senior HR Executive','PD57_IT_L1'=>'IT Support Engineer','PD57_IT_L2'=>'Senior IT Support Engineer','PD57_PAYROLL_L1'=>'Accounts Executive','PD57_PAYROLL_L2'=>'Accountant','PD57_OPS_L1'=>'Concierge Team Member','PD57_OPS_L2'=>'Assistant Manager','PD57_TRIAGE'=>'Employee Services Desk','Central Triage'=>'Employee Services Desk'];
    $text=str_ireplace(array_keys($maps),array_values($maps),$text); $department=pd57_p4_display_department($department);
    foreach([1=>'Handler',2=>'Specialist',3=>'Team Lead',4=>'Department Manager'] as $level=>$legacy)$text=preg_replace('/\b'.preg_quote($legacy,'/').'\b/i',pd57_p4_designation($department,$level),$text);
    foreach([1,2,3,4] as $level)$text=preg_replace('/\bL'.$level.'\b/i',pd57_p4_designation($department,$level),$text);
    return $text;
}
function pd57_p4_manual_targets(int $ticketId,int $currentLevel): array {
    $hierarchy=pd57_p4_hierarchy($ticketId); $targets=[];
    foreach(pd57_p4_designation_ladder($hierarchy['department']) as $index=>$label) if($index+1>$currentLevel) $targets[]=['level'=>$index+1,'designation'=>$label];
    $targets[]=['level'=>0,'designation'=>'Employee Services Desk'];
    return $targets;
}
function pd57_p4_resolve_escalation(int $ticketId,int $currentLevel,?int $requestedLevel=null): array {
    global $DB;
    $hierarchy=pd57_p4_hierarchy($ticketId); $department=$hierarchy['department']; $teamName=$hierarchy['team'];
    $team=pd57_p4_team($department,$teamName); $ladder=pd57_p4_designation_ladder($department); $maximum=count($ladder);
    if($maximum===0) return ['department'=>$department,'team'=>$team,'team_name'=>$teamName,'designation_level'=>1,'designation'=>'Employee Services Desk','person'=>null,'membership'=>null,'selection_reason'=>'employee_services_desk','fallback_reason'=>'no_configured_professional_ladder'];
    if($requestedLevel===0) return ['department'=>$department,'team'=>$team,'team_name'=>$teamName,'designation_level'=>$maximum+1,'designation'=>'Employee Services Desk','person'=>null,'membership'=>null,'selection_reason'=>'employee_services_desk','fallback_reason'=>'manual_employee_services_desk'];
    if($requestedLevel===null && $currentLevel>=$maximum) return ['department'=>$department,'team'=>$team,'team_name'=>$teamName,'designation_level'=>$maximum+1,'designation'=>'Employee Services Desk','person'=>null,'membership'=>null,'selection_reason'=>'employee_services_desk','fallback_reason'=>'no_eligible_higher_designation'];
    $start=$requestedLevel??($currentLevel+1);
    if($start<=$currentLevel || $start<1 || $start>$maximum) throw new InvalidArgumentException('Select a valid higher professional designation.');
    if($team) for($level=$start;$level<=$maximum;$level++) {
        $designation=pd57_p4_designation($department,$level); $eligible=[]; $today=date('Y-m-d');
        foreach($DB->request(['FROM'=>PD57_P4_MEMBERS,'WHERE'=>[
            'teams_id'=>(int)$team['id'],'designation_level'=>$level,'is_active'=>1,'is_unavailable'=>0,
            ['effective_from'=>['<=',$today]],'OR'=>['effective_until'=>null,['effective_until'=>['>=',$today]]],
        ]]) as $membership) {
            $person=$DB->request(['FROM'=>PD57_ADMIN_TABLE_PEOPLE,'WHERE'=>['id'=>(int)$membership['people_id'],'is_active'=>1],'LIMIT'=>1])->current();
            if(!$person) continue;
            $count=$DB->request(['SELECT'=>[new QueryExpression('COUNT(*) AS c')],'FROM'=>PD57_P4_ASSIGNMENTS,'WHERE'=>['teams_id'=>(int)$team['id'],'people_id'=>(int)$membership['people_id'],'designation_level'=>$level]])->current();
            $last=$DB->request(['SELECT'=>['assigned_at','created_at'],'FROM'=>PD57_P4_ASSIGNMENTS,'WHERE'=>['teams_id'=>(int)$team['id'],'people_id'=>(int)$membership['people_id'],'designation_level'=>$level],'ORDER'=>['id DESC'],'LIMIT'=>1])->current();
            $eligible[]=$membership+['designation'=>$designation,'prior_assignment_count'=>(int)($count['c']??0),'last_assigned_at'=>$last['assigned_at']??$last['created_at']??'','person'=>$person];
        }
        $winner=pd57_p4_choose_assignee($eligible);
        if($winner) return ['department'=>$department,'team'=>$team,'team_name'=>$teamName,'designation_level'=>$level,'designation'=>$designation,'person'=>$winner['person'],'membership'=>$winner,'selection_reason'=>'fair_lowest_count_oldest_assignment_person_id','fallback_reason'=>null];
    }
    return ['department'=>$department,'team'=>$team,'team_name'=>$teamName,'designation_level'=>$maximum+1,'designation'=>'Employee Services Desk','person'=>null,'membership'=>null,'selection_reason'=>'employee_services_desk','fallback_reason'=>'no_eligible_higher_designation'];
}
function pd57_p4_live_owner(int $ticketId, int $minimumLevel = 1): array {
    $owner=pd57_p4_resolve_escalation($ticketId,max(0,$minimumLevel-1),$minimumLevel);
    return ['team'=>$owner['team'],'person'=>$owner['person'],'designation'=>$owner['designation'],'level'=>$owner['designation_level'],'membership'=>$owner['membership'],'reason'=>$owner['selection_reason'],'fallback_reason'=>$owner['fallback_reason']];
}
function pd57_p4_record_assignment(int $ticketId,array $owner,string $reason,string $source,?string $assignedAt=null): int {
    global $DB; $assignedAt??=date('Y-m-d H:i:s');
    $DB->insert(PD57_P4_ASSIGNMENTS,[
        'tickets_id'=>$ticketId,'department'=>$owner['department']??null,'teams_id'=>$owner['team']['id']??null,
        'team_name'=>$owner['team_name']??$owner['team']['name']??'Employee Services Desk','designation_level'=>$owner['designation_level']??$owner['level']??null,
        'designation'=>$owner['designation'],'people_id'=>$owner['person']['id']??null,'team_memberships_id'=>$owner['membership']['id']??null,
        'reason'=>$reason,'assignment_source'=>$source,'fallback_reason'=>$owner['fallback_reason']??null,'assigned_at'=>$assignedAt,'created_at'=>$assignedAt,
    ]);
    return (int)$DB->insertId();
}
function pd57_p4_assign_ticket(int $ticketId, string $reason, int $minimumLevel = 1): array {
    global $DB; pd57_p4_install_schema(); $owner=pd57_p4_live_owner($ticketId,$minimumLevel); $hierarchy=pd57_p4_hierarchy($ticketId);
    $assignmentId=pd57_p4_record_assignment($ticketId,$owner+['department'=>$hierarchy['department'],'team_name'=>$hierarchy['team']],$reason,'initial_assignment');
    pd57_p4_deliver_notification($ticketId,'Assigned to you',$ticketId.'|assigned|'.$assignmentId,$owner['person']??null,['designation'=>$owner['designation'],'team'=>$owner['team']['name']??'Employee Services Desk']); return $owner+['assignment_history_id'=>$assignmentId];
}
function pd57_p4_notification_content(int $ticketId, string $event, array $context=[]): array {
    global $DB; $c=pd57_p4_current_classification($ticketId)?:[]; $state=$DB->request(['FROM'=>PD57_OPS_STATE,'WHERE'=>['tickets_id'=>$ticketId],'LIMIT'=>1])->current()?:[];
    $ticket=$DB->request(['SELECT'=>['name','status'],'FROM'=>'glpi_tickets','WHERE'=>['id'=>$ticketId],'LIMIT'=>1])->current()?:[];
    $reference='PD57-'.str_pad((string)$ticketId,7,'0',STR_PAD_LEFT); $title=(string)($ticket['name']??'Employee request');
    $department=(string)($c['final_department']??$c['suggested_department']??'Employee Services Desk'); $team=(string)($c['final_team']??$c['suggested_team']??'Employee Services Desk'); $designation=(string)($context['designation']??$state['designation']??'Employee Services Desk'); $priority=(string)($state['current_priority']??'Medium');
    $url=(getenv('PD57_BASE_URL')?:'http://127.0.0.1:8080').'/plugins/pd57portal/front/ticket.php?id='.$ticketId;
    $text="PHYSICAL DESK\nPD57 · EMPLOYEE REQUEST MANAGEMENT\n\n{$event}\n{$title}\n{$reference}\nDepartment: {$department}\nTeam: {$team}\nDesignation: {$designation}\nPriority: {$priority}\n\nView request: {$url}\n\nPhysical Desk · Employee Request Management";
    $h=static fn(string $v):string=>htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $html='<!doctype html><html><body style="margin:0;background:#f4f6f7;color:#25313b;font-family:Arial,sans-serif"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:32px 16px"><table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#fff;border:1px solid #dbe2e6"><tr><td style="padding:28px 32px;border-top:4px solid #17b8c8"><p style="margin:0 0 8px;font-size:12px;letter-spacing:1px;color:#60717c">PHYSICAL DESK</p><p style="margin:0 0 22px;font-size:12px;letter-spacing:1px;color:#60717c">PD57 · EMPLOYEE REQUEST MANAGEMENT</p><p style="margin:0 0 8px;font-size:13px;color:#17a5b4;font-weight:bold">'.$h($event).'</p><h1 style="margin:0 0 8px;font-size:24px">'.$h($title).'</h1><p style="margin:0 0 20px;color:#60717c">'.$h($reference).'</p><table role="presentation" width="100%" style="border-collapse:collapse;background:#f7fafb"><tr><td style="padding:12px">Department<br><strong>'.$h($department).'</strong></td><td style="padding:12px">Team<br><strong>'.$h($team).'</strong></td></tr><tr><td style="padding:12px">Designation<br><strong>'.$h($designation).'</strong></td><td style="padding:12px">Priority<br><strong>'.$h($priority).'</strong></td></tr></table><p style="margin:24px 0"><a href="'.$h($url).'" style="display:inline-block;background:#17a5b4;color:#fff;text-decoration:none;padding:12px 18px;font-weight:bold">View request</a></p><p style="margin:0;color:#60717c;font-size:12px">Physical Desk · Employee Request Management</p></td></tr></table></td></tr></table></body></html>';
    return ['subject'=>'PD57 '.$reference.' · '.$event,'text'=>$text,'html'=>$html];
}
function pd57_p4_deliver_notification(int $ticketId,string $event,string $key,?array $person=null,array $context=[]): bool {
    global $DB; if (!pd57_p4_notify($ticketId,$event,$key,['people_id'=>$person['id']??null]+$context)) return false;
    $to=(string)($person['delivery_email']??'');
    if(!filter_var($to,FILTER_VALIDATE_EMAIL)) { $link=$DB->request(['FROM'=>'glpi_tickets_users','WHERE'=>['tickets_id'=>$ticketId,'type'=>1],'LIMIT'=>1])->current(); if($link){$user=$DB->request(['SELECT'=>['name'],'FROM'=>'glpi_users','WHERE'=>['id'=>(int)$link['users_id']],'LIMIT'=>1])->current()?:[]; if(function_exists('pd57auth_get_otp_delivery_address'))$to=pd57auth_get_otp_delivery_address((string)($user['name']??''));} }
    if(!filter_var($to,FILTER_VALIDATE_EMAIL) || !class_exists('GLPIMailer')) return true;
    $mail=new GLPIMailer(); $email=$mail->getEmail(); $content=pd57_p4_notification_content($ticketId,$event,$context); $email->from(getenv('PD57_NOTIFICATION_FROM')?:'saumitrafreelance@gmail.com'); $email->to($to); $email->subject($content['subject']); $email->text($content['text']); $email->html($content['html']); return (bool)$mail->send();
}
function pd57_p4_backfill(): int {
    global $DB; $count=0; foreach($DB->request(['SELECT'=>['id','content'],'FROM'=>'glpi_tickets','WHERE'=>['id'=>365]]) as $ticket) { $id=(int)$ticket['id']; if(!pd57_p4_current_classification($id)){pd57_p4_record_classification($id,['Payroll','Salary Processing','Salary Not Received'],0.0,['Payroll','Salary Processing','Salary Not Received']);$count++;} foreach($DB->request(['FROM'=>'glpi_itilfollowups','WHERE'=>['itemtype'=>'Ticket','items_id'=>$id]]) as $f){$text=(string)$f['content'];$type=str_contains($text,'RESOLUTION_PROPOSED')?'resolution_proposed':(str_contains($text,'ESCALATION')?'employee_rejection':null);if($type&&pd57_ops_event($id,$type,$id.'|backfill|'.$type.'|'.$f['id'],['reason'=>'reliable_existing_pd57_marker','note'=>strip_tags($text),'at'=>$f['date_creation']]))$count++;}} return $count;
}
