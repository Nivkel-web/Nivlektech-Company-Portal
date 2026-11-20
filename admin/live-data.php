<?php
/*
|--------------------------------------------------------------------------
| JSON endpoint used by the live ticket page and the Live Management board
|--------------------------------------------------------------------------
*/
require_once("../config/session.php");
require_once("../config/database.php");
require_once("../includes/ticket_functions.php");

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function jsonOut(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

if (!isset($_SESSION['id'])) {
    jsonOut(['error' => 'Not logged in'], 401);
}

ensureTicketSchema($pdo);

$uid     = (int)$_SESSION['id'];
$isAdmin = strtolower($_SESSION['role'] ?? '') === 'admin';
$action  = $_GET['action'] ?? $_POST['action'] ?? '';

// release the session lock so polling never blocks the user's other pages
session_write_close();

/*
|--------------------------------------------------------------------------
| Live conversation for one ticket
|--------------------------------------------------------------------------
*/
if ($action === 'replies') {

    $id = (int)($_GET['id'] ?? 0);
    $ticket = $id ? getTicket($pdo, $id) : null;
    if (!$ticket || !canViewTicketFor($ticket, $uid, $isAdmin)) {
        jsonOut(['error' => 'Not found'], 404);
    }

    $staff = $isAdmin || (int)$ticket['assigned_to'] === $uid;
    touchPresence($pdo, $uid, $id);

    $rows = fetchReplies($pdo, $id, $staff, (int)($_GET['after'] ?? 0));
    $html = '';
    $last = (int)($_GET['after'] ?? 0);
    foreach ($rows as $r) {
        $html .= renderReplyHtml($r, (int)$ticket['created_by']);
        $last = max($last, (int)$r['id']);
    }

    $v = $pdo->prepare("
        SELECT u.fullname FROM user_presence p
        JOIN users u ON u.id = p.user_id
        WHERE p.watching_ticket = ? AND p.user_id <> ? AND p.last_seen >= ?
    ");
    $v->execute([$id, $uid, date('Y-m-d H:i:s', time() - 20)]);

    jsonOut([
        'html'          => $html,
        'last_id'       => $last,
        'status_html'   => statusBadge($ticket['status']),
        'assignee'      => $ticket['assignee_name'] ?: 'Unassigned',
        'also_viewing'  => $v->fetchAll(PDO::FETCH_COLUMN),
    ]);
}

/*
|--------------------------------------------------------------------------
| Live management board (admin only)
|--------------------------------------------------------------------------
*/
if ($action === 'board') {

    if (!$isAdmin) {
        jsonOut(['error' => 'Forbidden'], 403);
    }
    touchPresence($pdo, $uid, null);

    $since = date('Y-m-d H:i:s', time() - 86400);
    $st = $pdo->prepare("
        SELECT t.id, t.ticket_no, t.subject, t.priority, t.status, t.category, t.assigned_to,
               t.due_at, t.created_at, t.updated_at,
               c.fullname AS creator_name, a.fullname AS assignee_name
        FROM tickets t
        LEFT JOIN users c ON c.id = t.created_by
        LEFT JOIN users a ON a.id = t.assigned_to
        WHERE t.status NOT IN ('resolved','closed')
           OR (t.status = 'resolved' AND t.resolved_at >= ?)
        ORDER BY t.id DESC
        LIMIT 300
    ");
    $st->execute([$since]);
    $tickets = $st->fetchAll(PDO::FETCH_ASSOC);

    $now = time();
    $counts = ['open' => 0, 'in_progress' => 0, 'on_hold' => 0, 'resolved' => 0, 'unassigned' => 0, 'overdue' => 0, 'urgent' => 0];
    foreach ($tickets as &$t) {
        $t['overdue'] = !isTicketClosed($t['status']) && $t['due_at'] && strtotime($t['due_at']) < $now;
        $t['age']     = timeAgo($t['created_at']);
        $t['updated'] = timeAgo($t['updated_at']);
        $counts[$t['status']] = ($counts[$t['status']] ?? 0) + 1;
        if (!isTicketClosed($t['status'])) {
            if (!$t['assigned_to'])           $counts['unassigned']++;
            if ($t['overdue'])                $counts['overdue']++;
            if ($t['priority'] === 'urgent')  $counts['urgent']++;
        }
    }
    unset($t);

    $online = $pdo->prepare("
        SELECT u.id, u.fullname, u.role, p.watching_ticket
        FROM user_presence p JOIN users u ON u.id = p.user_id
        WHERE p.last_seen >= ?
        ORDER BY u.fullname
    ");
    $online->execute([date('Y-m-d H:i:s', time() - 45)]);

    // workload: active tickets per assignee
    $load = $pdo->query("
        SELECT a.id, a.fullname, COUNT(*) AS cnt
        FROM tickets t JOIN users a ON a.id = t.assigned_to
        WHERE t.status NOT IN ('resolved','closed')
        GROUP BY a.id, a.fullname
        ORDER BY cnt DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    jsonOut([
        'time'    => date('h:i:s A'),
        'counts'  => $counts,
        'tickets' => $tickets,
        'online'  => $online->fetchAll(PDO::FETCH_ASSOC),
        'load'    => $load,
    ]);
}

/*
|--------------------------------------------------------------------------
| Quick update from the board (admin only, POST)
|--------------------------------------------------------------------------
*/
if ($action === 'update') {

    if (!$isAdmin) {
        jsonOut(['error' => 'Forbidden'], 403);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonOut(['error' => 'POST required'], 405);
    }
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $sent)) {
        jsonOut(['error' => 'Security token expired, refresh the page'], 419);
    }

    $ticket = getTicket($pdo, (int)($_POST['id'] ?? 0));
    if (!$ticket) {
        jsonOut(['error' => 'Ticket not found'], 404);
    }

    // $_SESSION is read-only now (session closed) but the helpers only read it
    if (isset($_POST['status'])) {
        changeTicketStatus($pdo, $ticket, $_POST['status'], $uid);
        $ticket = getTicket($pdo, (int)$ticket['id']);
    }
    if (array_key_exists('assigned_to', $_POST)) {
        assignTicket($pdo, $ticket, (int)$_POST['assigned_to'] ?: null, $uid);
    }
    jsonOut(['ok' => true]);
}

jsonOut(['error' => 'Unknown action'], 400);

function canViewTicketFor(array $t, int $uid, bool $isAdmin): bool
{
    return $isAdmin || (int)$t['assigned_to'] === $uid || (int)$t['created_by'] === $uid;
}
