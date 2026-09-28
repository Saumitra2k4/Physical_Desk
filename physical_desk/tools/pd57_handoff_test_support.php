<?php
/**
 * Portable TEST DOUBLE only. Not GLPI/MariaDB integration and never for a web route.
 * Stores fixtures in memory and deliberately rejects DDL in a transaction.
 */
if (PHP_SAPI !== 'cli' || !defined('PD57_PORTABLE_HANDOFF_TEST')) {
    throw new RuntimeException('Portable test support must not run in the application.');
}
if (!function_exists('mb_strtolower')) { function mb_strtolower($s) { return strtolower($s); } }
if (!function_exists('mb_strlen')) { function mb_strlen($s) { return strlen($s); } }
if (!function_exists('mb_substr')) { function mb_substr($s, $a, $b = null) { return substr($s, $a, $b); } }
const UPDATE = 2;
class QueryExpression { public function __construct(public string $expression) {} public function __toString() { return $this->expression; } }
class MemoryPD57Database
{
    public array $tables = []; public array $columns = []; public array $queries = [];
    public array $frames = []; public ?string $failInsert = null;
    private int $lastId = 0;
    public function tableExists($t): bool { return isset($this->tables[$t]); }
    public function fieldExists($t, $c): bool { return isset($this->columns[$t][$c]); }
    public function fetchAssoc($r) { return $r->current(); }
    public function insertId(): int { return $this->lastId; }
    public function beginTransaction(): void { $this->frames[] = [$this->tables, $this->lastId]; }
    public function commit(): void { if (!$this->frames) throw new RuntimeException('No transaction.'); array_pop($this->frames); }
    public function rollBack(): void { if (!$this->frames) throw new RuntimeException('No transaction.'); [$this->tables, $this->lastId] = array_pop($this->frames); }
    public function doQuery(string $sql) {
        $this->queries[] = $sql;
        if (str_contains($sql, '@@in_transaction')) return new ArrayIterator([['active' => count($this->frames) > 0 ? 1 : 0]]);
        if (preg_match('/^(CREATE|ALTER)/i', $sql) && $this->frames) throw new RuntimeException('DDL inside transaction detected.');
        if (preg_match('/CREATE TABLE(?: IF NOT EXISTS)? `([^`]+)`/', $sql, $m)) { $this->tables[$m[1]] ??= []; return true; }
        if (preg_match('/ALTER TABLE `([^`]+)` ADD COLUMN `([^`]+)`/', $sql, $m)) { $this->columns[$m[1]][$m[2]] = true; return true; }
        if (str_ends_with($sql, ' FOR UPDATE')) return new ArrayIterator([]); // Lock behaviour needs a real MariaDB test.
        throw new RuntimeException('Unsupported test SQL: ' . $sql);
    }
    private function matches(array $row, array $criteria): bool {
        foreach ($criteria as $key => $v) {
            if (is_int($key)) { if (!$this->matches($row, $v)) return false; continue; }
            if ($key === 'OR') {
                $ok = false;
                foreach ($v as $k => $x) if ($this->matches($row, is_int($k) ? $x : [$k => $x])) $ok = true;
                if (!$ok) return false; continue;
            }
            $key = str_contains($key, '.') ? substr($key, strrpos($key, '.') + 1) : $key;
            $a = $row[$key] ?? null;
            if (is_array($v)) {
                if (isset($v[0]) && in_array($v[0], ['>=', '<=', '>', '<', '!='], true)) {
                    $ok = match ($v[0]) { '>=' => $a >= $v[1], '<=' => $a <= $v[1], '>' => $a > $v[1], '<' => $a < $v[1], '!=' => $a != $v[1] };
                    if (!$ok) return false;
                } elseif (!in_array($a, $v)) return false;
            } elseif ($v === null ? $a !== null : $a != $v) return false;
        }
        return true;
    }
    public function request(array $q): ArrayIterator {
        $table = explode(' AS ', $q['FROM'])[0];
        if (!isset($this->tables[$table])) throw new RuntimeException('Missing fixture table ' . $table);
        $rows = array_values(array_filter($this->tables[$table], fn($r) => $this->matches($r, $q['WHERE'] ?? [])));
        if (isset($q['INNER JOIN'])) throw new RuntimeException('Join requires real database fixture; not silently simulated.');
        foreach (array_reverse($q['ORDER'] ?? []) as $order) {
            $parts = explode(' ', $order); $key = $parts[0]; $dir = strtoupper($parts[1] ?? 'ASC');
            usort($rows, fn($a, $b) => (($a[$key] ?? '') <=> ($b[$key] ?? '')) * ($dir === 'DESC' ? -1 : 1));
        }
        if (isset($q['SELECT'][0]) && $q['SELECT'][0] instanceof QueryExpression) {
            $exp = (string)$q['SELECT'][0];
            if (preg_match('/COUNT\(\*\) AS (\w+)/i', $exp, $m)) return new ArrayIterator([[$m[1] => count($rows)]]);
            throw new RuntimeException('Unsupported test expression.');
        }
        if (isset($q['LIMIT'])) $rows = array_slice($rows, 0, $q['LIMIT']);
        return new ArrayIterator($rows);
    }
    public function insert(string $t, array $row): bool {
        if ($this->failInsert === $t) { $this->failInsert = null; return false; }
        $this->tables[$t] ??= [];
        $unique = [];
        if ($t === PD57_P4_HANDOFFS) $unique = [['dedupe_key'], ['tickets_id', 'handoff_sequence']];
        if ($t === PD57_OPS_EVENTS) $unique = [['event_key']];
        if ($t === PD57_OPS_STATE) $unique = [['tickets_id']];
        foreach ($this->tables[$t] as $old) foreach ($unique as $fields) {
            $same = true; foreach ($fields as $f) if (($old[$f] ?? null) !== ($row[$f] ?? null)) $same = false;
            if ($same) throw new RuntimeException('Duplicate fixture key.');
        }
        $row['id'] ??= $this->tables[$t] ? max(array_keys($this->tables[$t])) + 1 : 1;
        $this->tables[$t][$row['id']] = $row; $this->lastId = (int)$row['id']; return true;
    }
    public function update(string $t, array $values, array $where): bool {
        foreach ($this->tables[$t] as &$row) if ($this->matches($row, $where)) $row = array_replace($row, $values);
        return true;
    }
}
class Session {
    public static int $id = 101; public static string $role = 'support';
    public static function getLoginUserID() { return self::$id; }
}
class Ticket {
    public array $fields = [];
    public function getFromDB(int $id): bool { $this->fields = $GLOBALS['DB']->tables['glpi_tickets'][$id] ?? []; return (bool)$this->fields; }
    public function canViewItem(): bool {
        if (!Session::$id) return false;
        if (in_array(Session::$role, ['support', 'admin'], true)) return true;
        return (bool)$GLOBALS['DB']->request(['FROM' => 'glpi_tickets_users', 'WHERE' => ['tickets_id' => $this->fields['id'], 'users_id' => Session::$id, 'type' => 1]])->current();
    }
    public function can($id, $right): bool { return Session::$role === 'support' || Session::$role === 'admin'; }
    public function update(array $v): bool { $GLOBALS['DB']->update('glpi_tickets', $v, ['id' => $v['id']]); return $this->getFromDB($v['id']); }
}
class Group_Ticket { public function add($data) { $GLOBALS['DB']->insert('glpi_groups_tickets', $data); return $GLOBALS['DB']->insertId(); } }
class ITILFollowup {
    public function add($data) { $GLOBALS['DB']->insert('glpi_itilfollowups', $data + ['users_id' => Session::$id, 'date' => $GLOBALS['TEST_NOW'], 'date_creation' => $GLOBALS['TEST_NOW']]); return $GLOBALS['DB']->insertId(); }
}
class Dropdown { public static function getDropdownName($t, $id) { return 'Fixture location'; } }
function pd57_is_agent(): bool { return Session::$role === 'support'; }
