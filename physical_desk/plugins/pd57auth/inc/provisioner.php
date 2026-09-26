<?php
/**
 * PD57 Auth Plugin — Prototype Account Provisioner
 *
 * Provisions the standard enterprise prototype identities:
 *   - employee@physicaldesk (Profile 1: Self-Service)
 *   - hr@physicaldesk       (Profile 6: Technician, Group: PD57_HR_L1)
 *   - it@physicaldesk       (Profile 6: Technician, Group: PD57_IT_L1)
 *   - payroll@physicaldesk  (Profile 6: Technician, Group: PD57_PAYROLL_L1)
 *   - operations@physicaldesk (Profile 6: Technician, Group: PD57_OPS_L1)
 *
 * Configures least-privilege rights on Profile 6 (Technician) so agents
 * only access tickets assigned to their departmental group.
 */

function pd57auth_provision_prototype_accounts(): array
{
    global $DB;

    // Load config from untracked config/pd57auth.php
    $configFile = GLPI_ROOT . '/config/pd57auth.php';
    if (!file_exists($configFile)) {
        throw new RuntimeException("Missing config/pd57auth.php");
    }
    $config = include $configFile;
    $accounts = $config['accounts'] ?? [];
    $results = [];

    // Ensure least privilege on Profile 6 (Technician):
    // Remove READALL (1024) and READNEWTICKET (262144), add READASSIGN (4096) and READGROUP (2048)
    $currTicketRight = 429063;
    $iter = $DB->request([
        'FROM' => 'glpi_profilerights',
        'WHERE' => ['profiles_id' => 6, 'name' => 'ticket'],
    ]);
    if (count($iter)) {
        $currTicketRight = (int)$iter->current()['rights'];
    }
    $leastPrivilegeRight = ($currTicketRight & ~1024 & ~262144) | 4096 | 2048;
    $DB->update('glpi_profilerights', [
        'rights' => $leastPrivilegeRight,
    ], [
        'profiles_id' => 6,
        'name'        => 'ticket',
    ]);

    foreach ($accounts as $login => $info) {
        $user = new User();
        $uid = 0;
        if ($user->getFromDBbyName($login)) {
            $uid = (int)$user->fields['id'];
            // Only update password if changed to avoid password history check
            if (!Auth::checkPassword($info['password'], $user->fields['password'] ?? '')) {
                $user->update([
                    'id'        => $uid,
                    'password'  => $info['password'],
                    'password2' => $info['password'],
                    'is_active' => 1,
                ]);
            }
        } else {
            $uid = $user->add([
                'name'      => $login,
                'password'  => $info['password'],
                'password2' => $info['password'],
                'realname'  => $info['realname'] ?? '',
                'firstname' => $info['firstname'] ?? '',
                'is_active' => 1,
                'entities_id' => 0,
            ]);
        }

        if (!$uid) {
            $results[$login] = ['status' => 'FAILED'];
            continue;
        }

        // Profile mapping
        $targetProfileId = (int)$info['profile_id'];
        $profUser = new Profile_User();
        if ($profUser->getFromDBByCrit(['users_id' => $uid])) {
            // Existing prototype rows may be dynamic Self-Service defaults.
            // Profile_User::update does not change these rows reliably, so
            // normalize the persisted authorization and verify it below.
            $DB->update('glpi_profiles_users', [
                'profiles_id' => $targetProfileId, 'entities_id' => 0,
                'is_recursive' => 1, 'is_dynamic' => 0, 'is_default_profile' => 1,
            ], ['id' => (int)$profUser->fields['id']]);
        } else {
            $profUser->add([
                'users_id'    => $uid,
                'profiles_id' => $targetProfileId,
                'entities_id' => 0,
                'is_recursive'=> 1,
                'is_default_profile' => 1,
            ]);
        }
        $persistedProfile = $DB->request(['FROM' => 'glpi_profiles_users',
            'WHERE' => ['users_id' => $uid, 'profiles_id' => $targetProfileId], 'LIMIT' => 1])->current();
        if (!$persistedProfile) {
            throw new RuntimeException("Profile assignment failed for $login");
        }

        // Group mapping
        $targetGroups = $info['groups'] ?? [];
        // The prototype has one login per department. Specialist routing must
        // remain visible to that department's operator as well as its L1 queue.
        $departmentQueues = [
            'it@physicaldesk' => ['PD57_IT_NETWORK', 'PD57_IT_HARDWARE',
                'PD57_IT_ACCESS', 'PD57_IT_SECURITY', 'PD57_IT_APPLICATIONS', 'PD57_IT_AV'],
            'operations@physicaldesk' => ['PD57_OPS_L2', 'PD57_TRIAGE'],
        ];
        $targetGroups = array_values(array_unique(array_merge($targetGroups, $departmentQueues[$login] ?? [])));
        foreach ($targetGroups as $gname) {
            $group = new Group();
            if ($group->getFromDBByCrit(['name' => $gname])) {
                $gid = (int)$group->fields['id'];
                $gu = new Group_User();
                if (!$gu->getFromDBByCrit(['users_id' => $uid, 'groups_id' => $gid])) {
                    $gu->add([
                        'users_id'  => $uid,
                        'groups_id' => $gid,
                    ]);
                }
            }
        }

        $results[$login] = [
            'id'       => $uid,
            'profile'  => $targetProfileId,
            'groups'   => $targetGroups,
            'status'   => 'OK',
        ];
    }

    return $results;
}
