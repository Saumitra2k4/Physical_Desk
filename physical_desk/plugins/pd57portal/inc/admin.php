<?php

const PD57_ADMIN_GROUP = 'PD57_ADMIN';
const PD57_ADMIN_TABLE_PEOPLE = 'glpi_plugin_pd57portal_people';
const PD57_ADMIN_TABLE_ROLES = 'glpi_plugin_pd57portal_org_roles';
const PD57_ADMIN_TABLE_ASSIGNMENTS = 'glpi_plugin_pd57portal_role_assignments';
const PD57_ADMIN_TABLE_CALENDAR = 'glpi_plugin_pd57portal_working_calendar';
const PD57_ADMIN_TABLE_AUDIT = 'glpi_plugin_pd57portal_audit';

function pd57_admin_install_schema(): bool
{
    global $DB;
    require_once __DIR__ . '/operations.php';

    $queries = [
        PD57_ADMIN_TABLE_PEOPLE => "CREATE TABLE `" . PD57_ADMIN_TABLE_PEOPLE . "` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `linked_users_id` INT UNSIGNED DEFAULT NULL,
            `display_name` VARCHAR(255) NOT NULL,
            `delivery_email` VARCHAR(255) NOT NULL,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `linked_users_id` (`linked_users_id`),
            KEY `is_active` (`is_active`),
            CONSTRAINT `pd57_people_user_fk` FOREIGN KEY (`linked_users_id`) REFERENCES `glpi_users` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        PD57_ADMIN_TABLE_ROLES => "CREATE TABLE `" . PD57_ADMIN_TABLE_ROLES . "` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `department` VARCHAR(80) NOT NULL,
            `role_key` VARCHAR(80) NOT NULL,
            `role_label` VARCHAR(120) NOT NULL,
            `escalation_level` TINYINT UNSIGNED NOT NULL,
            `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `department_role` (`department`, `role_key`),
            KEY `role_active_order` (`is_active`, `sort_order`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        PD57_ADMIN_TABLE_ASSIGNMENTS => "CREATE TABLE `" . PD57_ADMIN_TABLE_ASSIGNMENTS . "` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `roles_id` INT UNSIGNED NOT NULL,
            `people_id` INT UNSIGNED NOT NULL,
            `backup_people_id` INT UNSIGNED DEFAULT NULL,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `effective_from` DATE NOT NULL,
            `effective_until` DATE DEFAULT NULL,
            `created_by_users_id` INT UNSIGNED NOT NULL,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `role_interval` (`roles_id`, `is_active`, `effective_from`, `effective_until`),
            KEY `people_id` (`people_id`),
            CONSTRAINT `pd57_assignment_role_fk` FOREIGN KEY (`roles_id`) REFERENCES `" . PD57_ADMIN_TABLE_ROLES . "` (`id`),
            CONSTRAINT `pd57_assignment_person_fk` FOREIGN KEY (`people_id`) REFERENCES `" . PD57_ADMIN_TABLE_PEOPLE . "` (`id`),
            CONSTRAINT `pd57_assignment_backup_fk` FOREIGN KEY (`backup_people_id`) REFERENCES `" . PD57_ADMIN_TABLE_PEOPLE . "` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        PD57_ADMIN_TABLE_CALENDAR => "CREATE TABLE `" . PD57_ADMIN_TABLE_CALENDAR . "` (
            `id` INT UNSIGNED NOT NULL,
            `timezone` VARCHAR(80) NOT NULL,
            `working_days` VARCHAR(32) NOT NULL,
            `start_time` TIME NOT NULL,
            `end_time` TIME NOT NULL,
            `updated_by_users_id` INT UNSIGNED NOT NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        PD57_ADMIN_TABLE_AUDIT => "CREATE TABLE `" . PD57_ADMIN_TABLE_AUDIT . "` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `actor_users_id` INT UNSIGNED NOT NULL,
            `actor_identity` VARCHAR(255) NOT NULL,
            `action` VARCHAR(80) NOT NULL,
            `entity_type` VARCHAR(80) NOT NULL,
            `entity_id` VARCHAR(80) NOT NULL,
            `department` VARCHAR(80) DEFAULT NULL,
            `role_key` VARCHAR(80) DEFAULT NULL,
            `previous_json` LONGTEXT DEFAULT NULL,
            `new_json` LONGTEXT DEFAULT NULL,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `audit_created` (`created_at`),
            KEY `audit_entity` (`entity_type`, `entity_id`),
            KEY `audit_actor` (`actor_users_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];

    foreach ($queries as $table => $sql) {
        if (!$DB->tableExists($table)) {
            $DB->doQuery($sql);
        }
    }

    $group = new Group();
    if (!$group->getFromDBByCrit(['name' => PD57_ADMIN_GROUP])) {
        $group->add([
            'name' => PD57_ADMIN_GROUP,
            'comment' => 'PD57 organisation control-plane administrators',
            'is_assign' => 0,
            'is_requester' => 0,
            'is_notify' => 0,
        ]);
    }

    $roles = [
        ['HR', 'handler', 'Handler', 1],
        ['HR', 'specialist', 'Specialist', 2],
        ['HR', 'team_lead', 'Team Lead', 3],
        ['HR', 'department_manager', 'Department Manager', 4],
        ['IT', 'handler', 'Handler', 1],
        ['IT', 'specialist', 'Specialist', 2],
        ['IT', 'team_lead', 'Team Lead', 3],
        ['IT', 'department_manager', 'Department Manager', 4],
        ['Payroll', 'handler', 'Handler', 1],
        ['Payroll', 'specialist', 'Specialist', 2],
        ['Payroll', 'team_lead', 'Team Lead', 3],
        ['Payroll', 'department_manager', 'Department Manager', 4],
        ['Operations', 'handler', 'Handler', 1],
        ['Operations', 'specialist', 'Specialist', 2],
        ['Operations', 'team_lead', 'Team Lead', 3],
        ['Operations', 'department_manager', 'Department Manager', 4],
        ['Central', 'central_triage', 'Central Triage', 1],
    ];
    foreach ($roles as $i => [$department, $key, $label, $level]) {
        $existing = $DB->request([
            'FROM' => PD57_ADMIN_TABLE_ROLES,
            'WHERE' => ['department' => $department, 'role_key' => $key],
            'LIMIT' => 1,
        ])->current();
        if (!$existing) {
            $DB->insert(PD57_ADMIN_TABLE_ROLES, [
                'department' => $department,
                'role_key' => $key,
                'role_label' => $label,
                'escalation_level' => $level,
                'sort_order' => ($i + 1) * 10,
                'is_active' => 1,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }
    // Legacy keys remain stable for existing assignments; labels are professional,
    // configurable data rather than user-facing escalation jargon.
    $professional = [
        'HR' => ['HR Executive','Senior HR Executive','Junior HR Business Partner','HR Business Partner','Senior HR Business Partner','HR Manager','Head of Human Resources'],
        'IT' => ['IT Support Engineer','Senior IT Support Engineer','IT Support Team Lead','IT Support Manager','Senior IT Manager','Head of IT'],
        'Payroll' => ['Accounts Executive','Accountant','Senior Accountant','Accounts Manager','Finance Manager','Head of Finance'],
        'Operations' => ['Concierge Team Member','Assistant Manager','Studio Manager','Studio Director','Director of Studio Performance & Growth'],
        'Central' => ['Employee Services Desk'],
    ];
    foreach ($professional as $department => $labels) foreach ($labels as $index => $label) {
        $level = $index + 1;
        $key = $level === 1 && $department === 'Central' ? 'central_triage' : 'professional_' . $level;
        $existing = $DB->request(['FROM'=>PD57_ADMIN_TABLE_ROLES,'WHERE'=>['department'=>$department,'role_key'=>$key],'LIMIT'=>1])->current();
        if (!$existing && $level <= 4 && $department !== 'Central') {
            $legacy = ['handler','specialist','team_lead','department_manager'][$index];
            $existing = $DB->request(['FROM'=>PD57_ADMIN_TABLE_ROLES,'WHERE'=>['department'=>$department,'role_key'=>$legacy],'LIMIT'=>1])->current();
        }
        // The existing four operational ownership levels remain active for
        // compatibility; additional ladder records are provisioned inactive
        // until a team membership is configured for that designation.
        $active = $level <= 4 ? 1 : 0;
        if ($existing) $DB->update(PD57_ADMIN_TABLE_ROLES,['role_label'=>$label,'escalation_level'=>$level,'is_active'=>$active,'updated_at'=>date('Y-m-d H:i:s')],['id'=>(int)$existing['id']]);
        else $DB->insert(PD57_ADMIN_TABLE_ROLES,['department'=>$department,'role_key'=>$key,'role_label'=>$label,'escalation_level'=>$level,'sort_order'=>($level*10),'is_active'=>$active,'created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')]);
    }

    $calendar = $DB->request(['FROM' => PD57_ADMIN_TABLE_CALENDAR, 'WHERE' => ['id' => 1], 'LIMIT' => 1])->current();
    if (!$calendar) {
        $DB->insert(PD57_ADMIN_TABLE_CALENDAR, [
            'id' => 1,
            'timezone' => 'Asia/Kolkata',
            'working_days' => '1,2,3,4,5',
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
            'updated_by_users_id' => 0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    pd57_ops_install_schema();

    return true;
}

function pd57_admin_update_operational_policy(array $input): void
{
    global $DB;
    require_once __DIR__ . '/operations.php';
    $fields = ['medium_to_high_minutes', 'high_to_critical_minutes', 'auto_escalation_minutes', 'reminder_minutes'];
    $new = ['is_active' => isset($input['is_active']) ? 1 : 0, 'updated_by_users_id' => (int)Session::getLoginUserID(), 'updated_at' => date('Y-m-d H:i:s')];
    foreach ($fields as $field) { $value = (int)($input[$field] ?? 0); if ($value < 1) throw new InvalidArgumentException('Policy intervals must be positive minutes.'); $new[$field] = $value; }
    if (!($new['medium_to_high_minutes'] < $new['high_to_critical_minutes'] && $new['high_to_critical_minutes'] < $new['auto_escalation_minutes'])) throw new InvalidArgumentException('Priority thresholds must increase before automatic escalation.');
    $previous = pd57_ops_policy(); $DB->update(PD57_OPS_POLICY, $new, ['id' => 1]);
    pd57_admin_audit('operational_policy.updated', 'operational_policy', '1', $previous, $new);
}

function pd57_admin_is_authorized(?int $userId = null): bool
{
    global $DB;
    $userId ??= (int)Session::getLoginUserID();
    if ($userId < 1 || !$DB->tableExists('glpi_groups_users')) {
        return false;
    }
    return (bool)$DB->request([
        'SELECT' => ['glpi_groups_users.id'],
        'FROM' => 'glpi_groups_users',
        'INNER JOIN' => ['glpi_groups' => ['FKEY' => ['glpi_groups_users' => 'groups_id', 'glpi_groups' => 'id']]],
        'WHERE' => ['glpi_groups_users.users_id' => $userId, 'glpi_groups.name' => PD57_ADMIN_GROUP],
        'LIMIT' => 1,
    ])->current();
}

function pd57_admin_require_access(): void
{
    if (!pd57_admin_is_authorized()) {
        http_response_code(403);
        exit('PD57 administration access denied.');
    }
}

function pd57_admin_actor(): array
{
    $id = (int)Session::getLoginUserID();
    $user = new User();
    $identity = 'user#' . $id;
    if ($user->getFromDB($id)) {
        $display = trim((string)($user->fields['firstname'] ?? '') . ' ' . (string)($user->fields['realname'] ?? ''));
        $identity = ($display !== '' ? $display . ' · ' : '') . (string)$user->fields['name'];
    }
    return [$id, $identity];
}

function pd57_admin_audit(string $action, string $entityType, string $entityId, ?array $previous, ?array $new, ?array $role = null): void
{
    global $DB;
    [$actorId, $identity] = pd57_admin_actor();
    $DB->insert(PD57_ADMIN_TABLE_AUDIT, [
        'actor_users_id' => $actorId,
        'actor_identity' => $identity,
        'action' => $action,
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'department' => $role['department'] ?? null,
        'role_key' => $role['role_key'] ?? null,
        'previous_json' => $previous === null ? null : json_encode($previous, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'new_json' => $new === null ? null : json_encode($new, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'created_at' => date('Y-m-d H:i:s'),
    ]);
}

function pd57_admin_valid_date(string $date): bool
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

function pd57_admin_role(int $roleId): ?array
{
    global $DB;
    $row = $DB->request(['FROM' => PD57_ADMIN_TABLE_ROLES, 'WHERE' => ['id' => $roleId, 'is_active' => 1], 'LIMIT' => 1])->current();
    return $row ?: null;
}

function pd57_admin_person(int $personId): ?array
{
    global $DB;
    $row = $DB->request(['FROM' => PD57_ADMIN_TABLE_PEOPLE, 'WHERE' => ['id' => $personId], 'LIMIT' => 1])->current();
    return $row ?: null;
}

function pd57_admin_create_person(array $input): int
{
    global $DB;
    $name = trim((string)($input['display_name'] ?? ''));
    $email = trim((string)($input['delivery_email'] ?? ''));
    $linkedUserId = (int)($input['linked_users_id'] ?? 0);
    if ($name === '' || mb_strlen($name) > 255) {
        throw new InvalidArgumentException('Enter a display name of 255 characters or fewer.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255) {
        throw new InvalidArgumentException('Enter a valid delivery email address.');
    }
    if ($linkedUserId > 0 && !$DB->request(['FROM' => 'glpi_users', 'WHERE' => ['id' => $linkedUserId, 'is_active' => 1], 'LIMIT' => 1])->current()) {
        throw new InvalidArgumentException('The linked GLPI user does not exist or is inactive.');
    }
    $row = [
        'linked_users_id' => $linkedUserId ?: null,
        'display_name' => $name,
        'delivery_email' => $email,
        'is_active' => !empty($input['is_active']) ? 1 : 0,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ];
    $DB->insert(PD57_ADMIN_TABLE_PEOPLE, $row);
    $id = (int)$DB->insertId();
    pd57_admin_audit('person.created', 'person', (string)$id, null, $row);
    return $id;
}

function pd57_admin_update_person(int $personId, array $input): void
{
    global $DB;
    $previous = pd57_admin_person($personId);
    if (!$previous) throw new InvalidArgumentException('Select a valid person.');
    $name = trim((string)($input['display_name'] ?? ''));
    $email = trim((string)($input['delivery_email'] ?? ''));
    $linkedUserId = (int)($input['linked_users_id'] ?? 0);
    if ($name === '' || mb_strlen($name) > 255 || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255) {
        throw new InvalidArgumentException('Enter a valid display name and delivery email.');
    }
    if ($linkedUserId > 0 && !$DB->request(['FROM'=>'glpi_users','WHERE'=>['id'=>$linkedUserId,'is_active'=>1],'LIMIT'=>1])->current()) {
        throw new InvalidArgumentException('The linked PD57 account is unavailable.');
    }
    $new = ['display_name'=>$name,'delivery_email'=>$email,'linked_users_id'=>$linkedUserId ?: null,
        'is_active'=>!empty($input['is_active']) ? 1 : 0,'updated_at'=>date('Y-m-d H:i:s')];
    $DB->update(PD57_ADMIN_TABLE_PEOPLE, $new, ['id'=>$personId]);
    pd57_admin_audit('person.updated', 'person', (string)$personId, $previous, $new);
}

function pd57_admin_save_membership(array $input): int
{
    global $DB;
    require_once __DIR__ . '/phase4.php';
    $id = (int)($input['membership_id'] ?? 0);
    $personId = (int)($input['people_id'] ?? 0);
    $teamId = (int)($input['teams_id'] ?? 0);
    $level = (int)($input['designation_level'] ?? 0);
    $from = trim((string)($input['effective_from'] ?? ''));
    $until = trim((string)($input['effective_until'] ?? ''));
    $person = pd57_admin_person($personId);
    $team = $DB->request(['FROM'=>PD57_P4_TEAMS,'WHERE'=>['id'=>$teamId,'is_active'=>1],'LIMIT'=>1])->current();
    if (!$person || !$team || $level < 1 || !pd57_admin_valid_date($from) || ($until !== '' && !pd57_admin_valid_date($until)) || ($until !== '' && $until < $from)) {
        throw new InvalidArgumentException('Select a valid person, team, designation and effective date range.');
    }
    $designation = trim((string)($input['designation'] ?? ''));
    if ($designation === '') $designation = pd57_p4_display_designation((string)$team['department'], $level, '');
    $new = ['teams_id'=>$teamId,'people_id'=>$personId,'designation'=>$designation,'designation_level'=>$level,
        'is_active'=>!empty($input['is_active']) ? 1 : 0,'is_unavailable'=>!empty($input['is_unavailable']) ? 1 : 0,
        'effective_from'=>$from,'effective_until'=>$until ?: null];
    $previous = $id ? $DB->request(['FROM'=>PD57_P4_MEMBERS,'WHERE'=>['id'=>$id],'LIMIT'=>1])->current() : null;
    if ($id && !$previous) throw new InvalidArgumentException('The membership no longer exists.');
    if ($id) $DB->update(PD57_P4_MEMBERS, $new, ['id'=>$id]);
    else { $DB->insert(PD57_P4_MEMBERS, $new); $id = (int)$DB->insertId(); }
    pd57_admin_audit($previous ? 'membership.updated' : 'membership.created', 'team_membership', (string)$id, $previous ?: null, $new);
    return $id;
}

function pd57_admin_end_membership(int $membershipId, string $effectiveUntil): void
{
    global $DB;
    if (!pd57_admin_valid_date($effectiveUntil)) throw new InvalidArgumentException('Enter a valid membership end date.');
    $previous = $DB->request(['FROM'=>PD57_P4_MEMBERS,'WHERE'=>['id'=>$membershipId],'LIMIT'=>1])->current();
    if (!$previous) throw new InvalidArgumentException('The membership no longer exists.');
    if ($effectiveUntil < (string)$previous['effective_from']) throw new InvalidArgumentException('The end date cannot precede the membership start.');
    $new = ['effective_until'=>$effectiveUntil,'is_active'=>0];
    $DB->update(PD57_P4_MEMBERS, $new, ['id'=>$membershipId]);
    pd57_admin_audit('membership.ended', 'team_membership', (string)$membershipId, $previous, $new);
}

function pd57_admin_replace_assignment(array $input): int
{
    global $DB;
    $roleId = (int)($input['roles_id'] ?? 0);
    $personId = (int)($input['people_id'] ?? 0);
    $backupPersonId = (int)($input['backup_people_id'] ?? 0);
    $effectiveFrom = trim((string)($input['effective_from'] ?? ''));
    if (!pd57_admin_valid_date($effectiveFrom)) {
        throw new InvalidArgumentException('Enter a valid effective date.');
    }
    $role = pd57_admin_role($roleId);
    $person = pd57_admin_person($personId);
    if (!$role || !$person) {
        throw new InvalidArgumentException('Select a valid role and person.');
    }
    if ($backupPersonId > 0 && (!$backup = pd57_admin_person($backupPersonId))) {
        throw new InvalidArgumentException('Select a valid backup person.');
    }
    if ($backupPersonId === $personId) {
        throw new InvalidArgumentException('The backup person must be different from the primary person.');
    }

    $DB->beginTransaction();
    try {
        $roleLock = $DB->doQuery('SELECT `id` FROM `' . PD57_ADMIN_TABLE_ROLES . '` WHERE `id` = ' . $roleId . ' FOR UPDATE');
        if (!$DB->fetchAssoc($roleLock)) {
            throw new InvalidArgumentException('The selected role is unavailable.');
        }
        $future = $DB->request([
            'FROM' => PD57_ADMIN_TABLE_ASSIGNMENTS,
            'WHERE' => ['roles_id' => $roleId, 'is_active' => 1, ['effective_from' => ['>=', $effectiveFrom]]],
            'LIMIT' => 1,
        ])->current();
        if ($future) {
            throw new DomainException('This role already has an assignment beginning on or after that date. Choose a later date or review history.');
        }
        $current = $DB->request([
            'FROM' => PD57_ADMIN_TABLE_ASSIGNMENTS,
            'WHERE' => [
                'roles_id' => $roleId,
                'is_active' => 1,
                ['effective_from' => ['<', $effectiveFrom]],
                'OR' => ['effective_until' => null, ['effective_until' => ['>=', $effectiveFrom]]],
            ],
            'ORDER' => ['effective_from DESC'],
            'LIMIT' => 1,
        ])->current();
        if ($current) {
            $until = (new DateTimeImmutable($effectiveFrom))->modify('-1 day')->format('Y-m-d');
            $DB->update(PD57_ADMIN_TABLE_ASSIGNMENTS, ['effective_until' => $until], ['id' => (int)$current['id']]);
        }
        $overlap = $DB->request([
            'FROM' => PD57_ADMIN_TABLE_ASSIGNMENTS,
            'WHERE' => [
                'roles_id' => $roleId,
                'is_active' => 1,
                'OR' => ['effective_until' => null, ['effective_until' => ['>=', $effectiveFrom]]],
            ],
            'LIMIT' => 1,
        ])->current();
        if ($overlap) {
            throw new DomainException('The requested interval overlaps an existing active assignment.');
        }
        $row = [
            'roles_id' => $roleId,
            'people_id' => $personId,
            'backup_people_id' => $backupPersonId ?: null,
            'is_active' => 1,
            'effective_from' => $effectiveFrom,
            'effective_until' => null,
            'created_by_users_id' => (int)Session::getLoginUserID(),
            'created_at' => date('Y-m-d H:i:s'),
        ];
        $DB->insert(PD57_ADMIN_TABLE_ASSIGNMENTS, $row);
        $id = (int)$DB->insertId();
        $snapshot = $row + ['id' => $id, 'person' => $person['display_name'], 'delivery_email' => $person['delivery_email']];
        pd57_admin_audit('assignment.replaced', 'role_assignment', (string)$id, $current ?: null, $snapshot, $role);
        $DB->commit();
        return $id;
    } catch (Throwable $e) {
        $DB->rollBack();
        throw $e;
    }
}

function pd57_admin_assignment_as_of(int $roleId, string $date): ?array
{
    global $DB;
    if (!pd57_admin_valid_date($date)) {
        throw new InvalidArgumentException('Invalid lookup date.');
    }
    $row = $DB->request([
        'SELECT' => [
            'a.*', 'p.display_name', 'p.delivery_email', 'p.employee_id', 'p.linked_users_id',
            'b.display_name AS backup_name', 'r.department', 'r.role_key', 'r.role_label', 'r.escalation_level',
        ],
        'FROM' => PD57_ADMIN_TABLE_ASSIGNMENTS . ' AS a',
        'INNER JOIN' => [
            PD57_ADMIN_TABLE_PEOPLE . ' AS p' => ['FKEY' => ['a' => 'people_id', 'p' => 'id']],
            PD57_ADMIN_TABLE_ROLES . ' AS r' => ['FKEY' => ['a' => 'roles_id', 'r' => 'id']],
        ],
        'LEFT JOIN' => [PD57_ADMIN_TABLE_PEOPLE . ' AS b' => ['FKEY' => ['a' => 'backup_people_id', 'b' => 'id']]],
        'WHERE' => [
            'a.roles_id' => $roleId,
            'a.is_active' => 1,
            'p.is_active' => 1,
            ['a.effective_from' => ['<=', $date]],
            'OR' => ['a.effective_until' => null, ['a.effective_until' => ['>=', $date]]],
        ],
        'ORDER' => ['a.effective_from DESC'],
        'LIMIT' => 1,
    ])->current();
    return $row ?: null;
}

function pd57_admin_update_calendar(array $input): void
{
    global $DB;
    $timezone = trim((string)($input['timezone'] ?? ''));
    $days = array_values(array_unique(array_map('intval', (array)($input['working_days'] ?? []))));
    sort($days);
    $start = trim((string)($input['start_time'] ?? ''));
    $end = trim((string)($input['end_time'] ?? ''));
    if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
        throw new InvalidArgumentException('Select a valid IANA timezone.');
    }
    if (!$days || array_diff($days, range(1, 7))) {
        throw new InvalidArgumentException('Select at least one valid working day.');
    }
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $start) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $end) || $start >= $end) {
        throw new InvalidArgumentException('Working hours require a valid start time before the end time.');
    }
    $previous = $DB->request(['FROM' => PD57_ADMIN_TABLE_CALENDAR, 'WHERE' => ['id' => 1], 'LIMIT' => 1])->current() ?: null;
    $new = [
        'timezone' => $timezone,
        'working_days' => implode(',', $days),
        'start_time' => $start . ':00',
        'end_time' => $end . ':00',
        'updated_by_users_id' => (int)Session::getLoginUserID(),
        'updated_at' => date('Y-m-d H:i:s'),
    ];
    $DB->beginTransaction();
    try {
        $DB->update(PD57_ADMIN_TABLE_CALENDAR, $new, ['id' => 1]);
        pd57_admin_audit('calendar.updated', 'working_calendar', '1', $previous, $new);
        $DB->commit();
    } catch (Throwable $e) {
        $DB->rollBack();
        throw $e;
    }
}

function pd57_admin_analytics(): array
{
    global $DB;
    $counts = ['open' => 0, 'awaiting' => 0, 'closed' => 0, 'total' => 0];
    foreach ($DB->request(['SELECT' => ['status', new QueryExpression('COUNT(*) AS count')], 'FROM' => 'glpi_tickets', 'GROUP' => ['status']]) as $row) {
        $status = (int)$row['status'];
        $count = (int)$row['count'];
        $counts['total'] += $count;
        if ($status === 5) {
            $counts['awaiting'] += $count;
        } elseif ($status === 6) {
            $counts['closed'] += $count;
        } else {
            $counts['open'] += $count;
        }
    }
    $byDepartment = ['HR' => 0, 'IT' => 0, 'Payroll' => 0, 'Studio Operations' => 0, 'Employee Services Desk' => 0, 'Unassigned' => 0];
    $sql = "SELECT DISTINCT t.id, g.name FROM glpi_tickets t LEFT JOIN glpi_groups_tickets gt ON gt.tickets_id=t.id AND gt.type=2 LEFT JOIN glpi_groups g ON g.id=gt.groups_id WHERE t.status NOT IN (5,6)";
    $result = $DB->doQuery($sql);
    $seen = [];
    while ($row = $DB->fetchAssoc($result)) {
        $id = (int)$row['id'];
        $name = (string)($row['name'] ?? '');
        $department = str_starts_with($name, 'PD57_HR_') ? 'HR'
            : (str_starts_with($name, 'PD57_IT_') ? 'IT'
            : (str_starts_with($name, 'PD57_PAYROLL_') ? 'Payroll'
            : (str_starts_with($name, 'PD57_OPS_') ? 'Studio Operations'
            : ($name === 'PD57_TRIAGE' ? 'Employee Services Desk' : 'Unassigned'))));
        if (!isset($seen[$id])) {
            $seen[$id] = $department;
            $byDepartment[$department]++;
        } elseif ($seen[$id] === 'Unassigned' && $department !== 'Unassigned') {
            $byDepartment['Unassigned']--;
            $byDepartment[$department]++;
            $seen[$id] = $department;
        }
    }
    $recent = iterator_to_array($DB->request(['SELECT' => ['id', 'name', 'status', 'date_mod'], 'FROM' => 'glpi_tickets', 'ORDER' => ['date_mod DESC'], 'LIMIT' => 8]));
    $priority = []; foreach ($DB->request(['SELECT'=>['priority',new QueryExpression('COUNT(*) AS count')],'FROM'=>'glpi_tickets','GROUP'=>['priority']]) as $row) $priority[(string)$row['priority']] = (int)$row['count'];
    $ops = ['critical'=>0,'aging'=>0,'escalated'=>0,'rejected'=>0];
    if ($DB->tableExists(PD57_OPS_STATE)) {
        $ops['critical'] = (int)(($DB->request(['SELECT'=>[new QueryExpression('COUNT(*) AS count')],'FROM'=>PD57_OPS_STATE,'WHERE'=>['current_priority'=>'Critical']])->current())['count'] ?? 0);
        $ops['aging'] = (int)(($DB->request(['SELECT'=>[new QueryExpression('COUNT(*) AS count')],'FROM'=>PD57_OPS_STATE,'WHERE'=>['last_meaningful_update_at'=>null]])->current())['count'] ?? 0);
        $ops['escalated'] = (int)(($DB->request(['SELECT'=>[new QueryExpression('COUNT(*) AS count')],'FROM'=>PD57_OPS_STATE,'WHERE'=>[['escalation_count'=>['>',0]]]])->current())['count'] ?? 0);
        $ops['rejected'] = (int)(($DB->request(['SELECT'=>[new QueryExpression('COUNT(*) AS count')],'FROM'=>PD57_OPS_EVENTS,'WHERE'=>['event_type'=>'employee_rejection']])->current())['count'] ?? 0);
    }
    $workload = []; if ($DB->tableExists(PD57_OPS_STATE)) foreach ($DB->request(['SELECT'=>['p.display_name',new QueryExpression('COUNT(*) AS count')],'FROM'=>PD57_OPS_STATE.' AS s','LEFT JOIN'=>[PD57_ADMIN_TABLE_PEOPLE.' AS p'=>['FKEY'=>['s'=>'people_id','p'=>'id']]],'GROUP'=>['p.display_name'],'ORDER'=>['count DESC']]) as $row) $workload[(string)($row['display_name'] ?: 'Unassigned')] = (int)$row['count'];
    return ['counts' => $counts, 'by_department' => $byDepartment, 'recent' => $recent, 'priority'=>$priority, 'ops'=>$ops, 'workload'=>$workload];
}
