<?php
/*
|--------------------------------------------------------------------------
| Nivlektech Company Portal
|--------------------------------------------------------------------------
| File : includes/autoassign.php
| Auto-assign new tickets to the right IT person (by specialty, workload,
| away status and a per-person open-ticket cap).
|--------------------------------------------------------------------------
*/

function ensureAutoAssignSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $tail = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS autoassign_settings (
            id INT PRIMARY KEY,
            enabled TINYINT NOT NULL DEFAULT 0,
            fallback_any TINYINT NOT NULL DEFAULT 1,
            updated_at DATETIME DEFAULT NULL
        )$tail
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS it_agents (
            user_id INT PRIMARY KEY,
            auto_assign TINYINT NOT NULL DEFAULT 0,
            away TINYINT NOT NULL DEFAULT 0,
            max_open INT NOT NULL DEFAULT 0,
            specialties VARCHAR(400) NOT NULL DEFAULT '',
            last_assigned_at DATETIME DEFAULT NULL
        )$tail
    ");
    if (!$pdo->query("SELECT COUNT(*) FROM autoassign_settings")->fetchColumn()) {
        $pdo->exec("INSERT INTO autoassign_settings (id) VALUES (1)");
    }
    $done = true;
}

function getAutoAssignSettings(PDO $pdo): array
{
    ensureAutoAssignSchema($pdo);
    return $pdo->query("SELECT * FROM autoassign_settings WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
}

/* IT team = active admins + active users whose department is "IT". Includes their agent row. */
function itTeamMembers(PDO $pdo): array
{
    ensureAutoAssignSchema($pdo);
    $rows = $pdo->query("
        SELECT u.id, u.fullname, u.role, d.department_name,
               a.auto_assign, a.away, a.max_open, a.specialties, a.last_assigned_at,
               (SELECT COUNT(*) FROM tickets t WHERE t.assigned_to = u.id AND t.status NOT IN ('resolved','closed')) AS open_count
        FROM users u
        LEFT JOIN departments d ON d.id = u.department_id
        LEFT JOIN it_agents a ON a.user_id = u.id
        WHERE u.status = 'active'
        ORDER BY u.fullname
    ")->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($rows as $r) {
        if ($r['role'] === 'admin' || strtolower(trim((string)$r['department_name'])) === 'it') {
            $r['auto_assign'] = (int)$r['auto_assign'];
            $r['away']        = (int)$r['away'];
            $r['max_open']    = (int)$r['max_open'];
            $r['open_count']  = (int)$r['open_count'];
            $r['spec_list']   = array_values(array_filter(array_map('trim', explode(',', (string)$r['specialties']))));
            $out[] = $r;
        }
    }
    return $out;
}

/*
| Pick the best person for a ticket:
|   1. only people who are opted in, not away and under their open-ticket cap
|   2. prefer those whose specialties include the ticket's category
|      (no specialist free -> any eligible person, if "fallback" is on)
|   3. fewest open tickets first; ties go to whoever was assigned longest ago
| Returns ['user' => row, 'reason' => text] or null.
*/
function pickAssignee(PDO $pdo, array $ticket): ?array
{
    $cfg = getAutoAssignSettings($pdo);
    $eligible = array_values(array_filter(itTeamMembers($pdo), function ($m) {
        return $m['auto_assign'] === 1 && $m['away'] === 0
            && ($m['max_open'] === 0 || $m['open_count'] < $m['max_open']);
    }));
    if (!$eligible) {
        return null;
    }

    $specialists = array_values(array_filter($eligible, fn($m) => in_array($ticket['category'], $m['spec_list'], true)));
    if ($specialists) {
        $pool = $specialists;
        $reason = 'specialty: ' . $ticket['category'];
    } elseif ((int)$cfg['fallback_any'] === 1) {
        $pool = $eligible;
        $reason = 'least busy';
    } else {
        return null;
    }

    usort($pool, function ($a, $b) {
        return [$a['open_count'], (string)$a['last_assigned_at'], $a['id']]
           <=> [$b['open_count'], (string)$b['last_assigned_at'], $b['id']];
    });
    return ['user' => $pool[0], 'reason' => $reason . ', ' . $pool[0]['open_count'] . ' open'];
}

/* Assign one ticket automatically. $actorId = who triggered it (ticket creator or the admin clicking). */
function autoAssignTicket(PDO $pdo, array $ticket, int $actorId, bool $force = false): ?array
{
    try {
        $cfg = getAutoAssignSettings($pdo);
        if (!$force && (int)$cfg['enabled'] !== 1) {
            return null;
        }
        if (!empty($ticket['assigned_to']) || isTicketClosed($ticket['status'])) {
            return null;
        }
        $pick = pickAssignee($pdo, $ticket);
        if (!$pick) {
            return null;
        }
        $uid = (int)$pick['user']['id'];
        if (!assignTicket($pdo, $ticket, $uid, $actorId, true, $pick['reason'])) {
            return null;
        }
        $pdo->prepare("UPDATE it_agents SET last_assigned_at = ? WHERE user_id = ?")->execute([date('Y-m-d H:i:s'), $uid]);
        return $pick;
    } catch (Throwable $e) {
        return null;      // never block ticket creation
    }
}

/* Assign every open, unassigned ticket (oldest first). Returns [assigned, left]. */
function autoAssignAllUnassigned(PDO $pdo, int $actorId): array
{
    $ids = $pdo->query("SELECT id FROM tickets WHERE assigned_to IS NULL AND status NOT IN ('resolved','closed') ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    $done = 0;
    foreach ($ids as $id) {
        $t = getTicket($pdo, (int)$id);
        if ($t && autoAssignTicket($pdo, $t, $actorId, true)) {
            $done++;
        }
    }
    return [$done, count($ids) - $done];
}
