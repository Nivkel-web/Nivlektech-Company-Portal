<?php
/*
|--------------------------------------------------------------------------
| Nivlektech Company Portal
|--------------------------------------------------------------------------
| File : includes/ticket_functions.php
| Shared helpers for: Ticketing, Live Management, Report Analytics and
| Email Notification.
|--------------------------------------------------------------------------
*/

if (!defined('BASE_URL')) {
    require_once __DIR__ . '/../config/config.php';
}

require_once __DIR__ . '/mailer.php';

/*
|--------------------------------------------------------------------------
| Constants / lookups
|--------------------------------------------------------------------------
*/

const TICKET_STATUSES = [
    'open'        => ['label' => 'Open',        'badge' => 'primary'],
    'in_progress' => ['label' => 'In Progress', 'badge' => 'info'],
    'on_hold'     => ['label' => 'On Hold',     'badge' => 'warning'],
    'resolved'    => ['label' => 'Resolved',    'badge' => 'success'],
    'closed'      => ['label' => 'Closed',      'badge' => 'secondary'],
];

const TICKET_PRIORITIES = [
    'low'    => ['label' => 'Low',    'badge' => 'secondary', 'sla_hours' => 120],
    'medium' => ['label' => 'Medium', 'badge' => 'primary',   'sla_hours' => 72],
    'high'   => ['label' => 'High',   'badge' => 'warning',   'sla_hours' => 24],
    'urgent' => ['label' => 'Urgent', 'badge' => 'danger',    'sla_hours' => 4],
];

const TICKET_CATEGORIES = [
    'General', 'IT Support', 'Hardware', 'Software', 'Network',
    'Email / Accounts', 'HR Request', 'Finance / Accounts', 'Other',
];

/*
|--------------------------------------------------------------------------
| Small helpers
|--------------------------------------------------------------------------
*/

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function statusBadge(string $status): string
{
    $s = TICKET_STATUSES[$status] ?? ['label' => ucfirst($status), 'badge' => 'secondary'];
    return '<span class="badge bg-' . $s['badge'] . '">' . e($s['label']) . '</span>';
}

function priorityBadge(string $priority): string
{
    $p = TICKET_PRIORITIES[$priority] ?? ['label' => ucfirst($priority), 'badge' => 'secondary'];
    return '<span class="badge bg-' . $p['badge'] . '">' . e($p['label']) . '</span>';
}

function ticketNumber(int $id): string
{
    return 'TKT-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT);
}

function isTicketClosed(string $status): bool
{
    return in_array($status, ['resolved', 'closed'], true);
}

function timeAgo(?string $datetime): string
{
    if (!$datetime) {
        return '-';
    }
    $diff = time() - strtotime($datetime);
    if ($diff < 60)     return 'just now';
    if ($diff < 3600)   return floor($diff / 60) . ' min ago';
    if ($diff < 86400)  return floor($diff / 3600) . ' hr ago';
    if ($diff < 604800) return floor($diff / 86400) . ' day(s) ago';
    return date('d M Y', strtotime($datetime));
}

function humanDuration(float $seconds): string
{
    if ($seconds <= 0)      return '-';
    if ($seconds < 3600)    return round($seconds / 60) . ' min';
    if ($seconds < 172800)  return round($seconds / 3600, 1) . ' hrs';
    return round($seconds / 86400, 1) . ' days';
}

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrfToken()) . '">';
}

function csrfCheck(): void
{
    $sent = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($sent) || !hash_equals(csrfToken(), $sent)) {
        http_response_code(419);
        exit('Security token expired. Please go back, refresh the page and try again.');
    }
}

/*
|--------------------------------------------------------------------------
| Database schema (created automatically, safe to run many times)
|--------------------------------------------------------------------------
*/

function ensureTicketSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $pk     = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
    $tail   = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tickets (
            id $pk,
            ticket_no VARCHAR(20) DEFAULT NULL,
            subject VARCHAR(255) NOT NULL,
            description TEXT,
            category VARCHAR(60) DEFAULT 'General',
            priority VARCHAR(20) NOT NULL DEFAULT 'medium',
            status VARCHAR(20) NOT NULL DEFAULT 'open',
            created_by INT NOT NULL,
            assigned_to INT DEFAULT NULL,
            department_id INT DEFAULT NULL,
            due_at DATETIME DEFAULT NULL,
            first_response_at DATETIME DEFAULT NULL,
            resolved_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        )$tail
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ticket_replies (
            id $pk,
            ticket_id INT NOT NULL,
            user_id INT DEFAULT NULL,
            message TEXT NOT NULL,
            is_internal TINYINT NOT NULL DEFAULT 0,
            is_system TINYINT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL
        )$tail
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_presence (
            user_id INT PRIMARY KEY,
            last_seen DATETIME NOT NULL,
            watching_ticket INT DEFAULT NULL
        )$tail
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS mail_settings (
            id INT PRIMARY KEY,
            enabled TINYINT NOT NULL DEFAULT 0,
            driver VARCHAR(10) NOT NULL DEFAULT 'log',
            from_email VARCHAR(150) DEFAULT '',
            from_name VARCHAR(150) DEFAULT 'Company Portal',
            smtp_host VARCHAR(150) DEFAULT '',
            smtp_port INT DEFAULT 587,
            smtp_encryption VARCHAR(10) DEFAULT 'tls',
            smtp_username VARCHAR(150) DEFAULT '',
            smtp_password VARCHAR(255) DEFAULT '',
            notify_new_ticket TINYINT NOT NULL DEFAULT 1,
            notify_assigned TINYINT NOT NULL DEFAULT 1,
            notify_reply TINYINT NOT NULL DEFAULT 1,
            notify_status TINYINT NOT NULL DEFAULT 1,
            updated_at DATETIME DEFAULT NULL
        )$tail
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS email_logs (
            id $pk,
            recipient VARCHAR(150),
            subject VARCHAR(255),
            event VARCHAR(40),
            status VARCHAR(15),
            error_message TEXT,
            created_at DATETIME NOT NULL
        )$tail
    ");

    // Indexes (ignore "already exists" on repeat runs)
    $indexes = [
        'idx_tickets_status'   => 'tickets (status)',
        'idx_tickets_creator'  => 'tickets (created_by)',
        'idx_tickets_assignee' => 'tickets (assigned_to)',
        'idx_replies_ticket'   => 'ticket_replies (ticket_id)',
    ];
    foreach ($indexes as $name => $def) {
        try {
            $pdo->exec(($sqlite ? "CREATE INDEX IF NOT EXISTS $name ON $def" : "CREATE INDEX $name ON $def"));
        } catch (Throwable $e) {
            // index already exists
        }
    }

    $has = $pdo->query("SELECT COUNT(*) FROM mail_settings")->fetchColumn();
    if (!$has) {
        $pdo->exec("INSERT INTO mail_settings (id) VALUES (1)");
    }

    $done = true;
}

function getMailSettings(PDO $pdo): array
{
    ensureTicketSchema($pdo);
    $row = $pdo->query("SELECT * FROM mail_settings WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
    return $row ?: [
        'enabled' => 0, 'driver' => 'log', 'from_email' => '', 'from_name' => 'Company Portal',
        'smtp_host' => '', 'smtp_port' => 587, 'smtp_encryption' => 'tls',
        'smtp_username' => '', 'smtp_password' => '',
        'notify_new_ticket' => 1, 'notify_assigned' => 1, 'notify_reply' => 1, 'notify_status' => 1,
    ];
}

/*
|--------------------------------------------------------------------------
| Activity log + presence
|--------------------------------------------------------------------------
*/

function logActivity(PDO $pdo, string $activity): void
{
    try {
        $st = $pdo->prepare("INSERT INTO activity_logs (user_id, activity, ip_address) VALUES (?,?,?)");
        $st->execute([$_SESSION['id'] ?? null, $activity, $_SERVER['REMOTE_ADDR'] ?? '']);
    } catch (Throwable $e) {
        // never block the page because of a log
    }
}

function touchPresence(PDO $pdo, int $userId, ?int $watching = null): void
{
    $now = date('Y-m-d H:i:s');
    $upd = $pdo->prepare("UPDATE user_presence SET last_seen = ?, watching_ticket = ? WHERE user_id = ?");
    $upd->execute([$now, $watching, $userId]);
    if ($upd->rowCount() === 0) {
        try {
            $ins = $pdo->prepare("INSERT INTO user_presence (user_id, last_seen, watching_ticket) VALUES (?,?,?)");
            $ins->execute([$userId, $now, $watching]);
        } catch (Throwable $e) {
            // another request inserted it first
        }
    }
}

/*
|--------------------------------------------------------------------------
| Ticket access
|--------------------------------------------------------------------------
*/

function getTicket(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare("
        SELECT t.*,
               c.fullname AS creator_name, c.email AS creator_email,
               a.fullname AS assignee_name, a.email AS assignee_email,
               d.department_name
        FROM tickets t
        LEFT JOIN users c ON c.id = t.created_by
        LEFT JOIN users a ON a.id = t.assigned_to
        LEFT JOIN departments d ON d.id = t.department_id
        WHERE t.id = ?
    ");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function isStaffFor(array $ticket): bool
{
    $uid = (int)($_SESSION['id'] ?? 0);
    return strtolower($_SESSION['role'] ?? '') === 'admin' || (int)$ticket['assigned_to'] === $uid;
}

function canViewTicket(array $ticket): bool
{
    return isStaffFor($ticket) || (int)$ticket['created_by'] === (int)($_SESSION['id'] ?? 0);
}

function activeUsers(PDO $pdo): array
{
    return $pdo->query("SELECT id, fullname, email, role FROM users WHERE status = 'active' ORDER BY fullname")
               ->fetchAll(PDO::FETCH_ASSOC);
}

function addTicketReply(PDO $pdo, int $ticketId, ?int $userId, string $message, bool $internal = false, bool $system = false): void
{
    $st = $pdo->prepare("
        INSERT INTO ticket_replies (ticket_id, user_id, message, is_internal, is_system, created_at)
        VALUES (?,?,?,?,?,?)
    ");
    $st->execute([$ticketId, $userId, $message, $internal ? 1 : 0, $system ? 1 : 0, date('Y-m-d H:i:s')]);
}

/*
|--------------------------------------------------------------------------
| Ticket actions (each one also triggers the e-mail notification)
|--------------------------------------------------------------------------
*/

function createTicket(PDO $pdo, array $data, int $userId): int
{
    $priority = isset(TICKET_PRIORITIES[$data['priority']]) ? $data['priority'] : 'medium';
    $now = date('Y-m-d H:i:s');
    $due = date('Y-m-d H:i:s', time() + TICKET_PRIORITIES[$priority]['sla_hours'] * 3600);

    $st = $pdo->prepare("
        INSERT INTO tickets
            (subject, description, category, priority, status, created_by, department_id, due_at, created_at, updated_at)
        VALUES (?,?,?,?, 'open', ?,?,?,?,?)
    ");
    $st->execute([
        $data['subject'], $data['description'], $data['category'], $priority,
        $userId, $data['department_id'] ?: null, $due, $now, $now,
    ]);

    $id = (int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE tickets SET ticket_no = ? WHERE id = ?")->execute([ticketNumber($id), $id]);

    logActivity($pdo, 'Created ticket ' . ticketNumber($id));

    if (!empty($data['asset_id']) && function_exists('linkTicketAsset')) {
        linkTicketAsset($pdo, $id, (int)$data['asset_id'], $userId);
    }
    if (function_exists('autoAssignTicket')) {
        autoAssignTicket($pdo, getTicket($pdo, $id), $userId);      // no-op unless switched on
    }

    notifyTicket($pdo, getTicket($pdo, $id), 'new_ticket', $userId);
    if (function_exists('smsNotifyTicket')) {
        smsNotifyTicket($pdo, getTicket($pdo, $id), 'new_ticket', $userId);
    }

    return $id;
}

function replyToTicket(PDO $pdo, array $ticket, int $userId, string $message, bool $internal): void
{
    $staff = isStaffFor($ticket);
    $internal = $internal && $staff;     // only staff can write internal notes

    addTicketReply($pdo, (int)$ticket['id'], $userId, $message, $internal);

    $now = date('Y-m-d H:i:s');
    $sets = ['updated_at = ?'];
    $args = [$now];

    if ($staff && empty($ticket['first_response_at']) && !$internal) {
        $sets[] = 'first_response_at = ?';
        $args[] = $now;
    }
    // customer answers a ticket that was waiting -> reopen it
    if (!$staff && in_array($ticket['status'], ['resolved', 'on_hold'], true)) {
        $sets[] = "status = 'open'";
        $sets[] = 'resolved_at = NULL';
        addTicketReply($pdo, (int)$ticket['id'], null, 'Ticket re-opened after a new reply.', false, true);
    }
    $args[] = $ticket['id'];
    $pdo->prepare('UPDATE tickets SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($args);

    logActivity($pdo, ($internal ? 'Internal note on ' : 'Replied to ') . ticketNumber((int)$ticket['id']));

    if (!$internal) {
        notifyTicket($pdo, getTicket($pdo, (int)$ticket['id']), 'reply', $userId, ['message' => $message]);
    }
}

function changeTicketStatus(PDO $pdo, array $ticket, string $status, int $userId): bool
{
    if (!isset(TICKET_STATUSES[$status]) || $status === $ticket['status']) {
        return false;
    }
    $now = date('Y-m-d H:i:s');
    $resolvedAt = isTicketClosed($status) ? ($ticket['resolved_at'] ?: $now) : null;

    $pdo->prepare("UPDATE tickets SET status = ?, resolved_at = ?, updated_at = ? WHERE id = ?")
        ->execute([$status, $resolvedAt, $now, $ticket['id']]);

    $who = $_SESSION['fullname'] ?? 'System';
    addTicketReply(
        $pdo, (int)$ticket['id'], $userId,
        "$who changed status from " . TICKET_STATUSES[$ticket['status']]['label'] . ' to ' . TICKET_STATUSES[$status]['label'] . '.',
        false, true
    );
    logActivity($pdo, ticketNumber((int)$ticket['id']) . ' status -> ' . $status);
    notifyTicket($pdo, getTicket($pdo, (int)$ticket['id']), 'status', $userId, ['old' => $ticket['status']]);
    return true;
}

function assignTicket(PDO $pdo, array $ticket, ?int $assigneeId, int $userId, bool $auto = false, string $why = ''): bool
{
    if ((int)$ticket['assigned_to'] === (int)$assigneeId) {
        return false;
    }
    if ($assigneeId) {
        $chk = $pdo->prepare("SELECT fullname FROM users WHERE id = ? AND status = 'active'");
        $chk->execute([$assigneeId]);
        $name = $chk->fetchColumn();
        if (!$name) {
            return false;
        }
    } else {
        $name = null;
    }

    $now = date('Y-m-d H:i:s');
    $sets = 'assigned_to = ?, updated_at = ?';
    $args = [$assigneeId ?: null, $now];
    // picking a ticket up moves it from Open to In Progress
    if ($assigneeId && $ticket['status'] === 'open' && !$auto) {
        $sets .= ", status = 'in_progress'";
    }
    $args[] = $ticket['id'];
    $pdo->prepare("UPDATE tickets SET $sets WHERE id = ?")->execute($args);

    if ($auto) {
        addTicketReply($pdo, (int)$ticket['id'], null, "Auto-assigned to $name ($why).", false, true);
    } else {
        addTicketReply(
            $pdo, (int)$ticket['id'], $userId,
            $name ? ($_SESSION['fullname'] ?? 'System') . " assigned this ticket to $name." : 'Ticket was unassigned.',
            false, true
        );
    }
    logActivity($pdo, ticketNumber((int)$ticket['id']) . ($auto ? ' auto-assigned to ' : ' assigned to ') . ($name ?: 'nobody'));

    if ($assigneeId) {
        notifyTicket($pdo, getTicket($pdo, (int)$ticket['id']), 'assigned', $userId);
        if (function_exists('smsNotifyTicket')) {
            smsNotifyTicket($pdo, getTicket($pdo, (int)$ticket['id']), 'assigned', $userId);
        }
    }
    return true;
}

/*
|--------------------------------------------------------------------------
| E-mail notifications
|--------------------------------------------------------------------------
*/

function notifyTicket(PDO $pdo, ?array $ticket, string $event, int $actorId, array $extra = []): void
{
    if (!$ticket) {
        return;
    }
    try {
        $cfg = getMailSettings($pdo);
        $flag = [
            'new_ticket' => 'notify_new_ticket',
            'assigned'   => 'notify_assigned',
            'reply'      => 'notify_reply',
            'status'     => 'notify_status',
        ][$event] ?? null;

        if ($flag === null || (int)$cfg[$flag] !== 1 || (int)$cfg['enabled'] !== 1) {
            return;
        }

        $no = $ticket['ticket_no'] ?: ticketNumber((int)$ticket['id']);
        $recipients = [];   // email => name

        $add = function (?string $email, ?string $name) use (&$recipients) {
            if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $recipients[strtolower($email)] = $name ?: $email;
            }
        };

        $actorIsCreator = (int)$ticket['created_by'] === $actorId;

        switch ($event) {
            case 'new_ticket':
                $add($ticket['creator_email'], $ticket['creator_name']);          // confirmation
                foreach (activeUsers($pdo) as $u) {                               // alert admins
                    if ($u['role'] === 'admin') {
                        $add($u['email'], $u['fullname']);
                    }
                }
                if (!empty($ticket['assigned_to']) && (int)$cfg['notify_assigned'] === 1) {
                    $assigneeEmail = strtolower((string)$ticket['assignee_email']);   // gets the "assigned" email instead
                    unset($recipients[$assigneeEmail]);
                }
                $subject = "[$no] New ticket: " . $ticket['subject'];
                $headline = 'A new support ticket has been created';
                break;

            case 'assigned':
                $add($ticket['assignee_email'], $ticket['assignee_name']);
                $add($ticket['creator_email'], $ticket['creator_name']);
                $subject = "[$no] Ticket assigned to " . ($ticket['assignee_name'] ?: 'an agent');
                $headline = 'Ticket assigned';
                break;

            case 'reply':
                if ($actorIsCreator) {
                    if ($ticket['assignee_email']) {
                        $add($ticket['assignee_email'], $ticket['assignee_name']);
                    } else {
                        foreach (activeUsers($pdo) as $u) {
                            if ($u['role'] === 'admin') {
                                $add($u['email'], $u['fullname']);
                            }
                        }
                    }
                } else {
                    $add($ticket['creator_email'], $ticket['creator_name']);
                }
                $subject = "[$no] New reply: " . $ticket['subject'];
                $headline = 'There is a new reply on your ticket';
                break;

            case 'status':
                $add($ticket['creator_email'], $ticket['creator_name']);
                $add($ticket['assignee_email'], $ticket['assignee_name']);
                $subject = "[$no] Status changed to " . (TICKET_STATUSES[$ticket['status']]['label'] ?? $ticket['status']);
                $headline = 'Ticket status updated';
                break;

            default:
                return;
        }

        // never e-mail the person who did the action
        $actor = $pdo->prepare("SELECT email FROM users WHERE id = ?");
        $actor->execute([$actorId]);
        $actorEmail = strtolower((string)$actor->fetchColumn());
        unset($recipients[$actorEmail]);

        if (!$recipients) {
            return;
        }

        $html = buildTicketEmail($ticket, $headline, $extra['message'] ?? '');
        $mailer = new Mailer($pdo);
        foreach ($recipients as $email => $name) {
            $mailer->send($email, $subject, $html, $event);
        }
    } catch (Throwable $e) {
        // notifications are best-effort; a mail problem must not stop a ticket action
    }
}

function buildTicketEmail(array $t, string $headline, string $message = ''): string
{
    $no   = $t['ticket_no'] ?: ticketNumber((int)$t['id']);
    $link = rtrim(BASE_URL, '/') . '/admin/view-ticket.php?id=' . (int)$t['id'];
    $st   = TICKET_STATUSES[$t['status']]['label'] ?? $t['status'];
    $pr   = TICKET_PRIORITIES[$t['priority']]['label'] ?? $t['priority'];

    $msgBlock = $message !== ''
        ? '<div style="margin:16px 0;padding:12px 16px;background:#f4f6f9;border-left:4px solid #0d6efd;white-space:pre-wrap">' . e($message) . '</div>'
        : '';

    return '<div style="font-family:Segoe UI,Arial,sans-serif;max-width:600px;margin:auto;border:1px solid #e3e6ea;border-radius:8px;overflow:hidden">'
        . '<div style="background:#1f2937;color:#fff;padding:16px 24px;font-size:18px">' . e(SITE_NAME) . '</div>'
        . '<div style="padding:24px;color:#212529">'
        . '<h2 style="margin:0 0 12px;font-size:20px">' . e($headline) . '</h2>'
        . '<table style="border-collapse:collapse;width:100%;font-size:14px">'
        . '<tr><td style="padding:6px 0;color:#6c757d;width:120px">Ticket</td><td><strong>' . e($no) . '</strong></td></tr>'
        . '<tr><td style="padding:6px 0;color:#6c757d">Subject</td><td>' . e($t['subject']) . '</td></tr>'
        . '<tr><td style="padding:6px 0;color:#6c757d">Status</td><td>' . e($st) . '</td></tr>'
        . '<tr><td style="padding:6px 0;color:#6c757d">Priority</td><td>' . e($pr) . '</td></tr>'
        . '<tr><td style="padding:6px 0;color:#6c757d">Requester</td><td>' . e($t['creator_name']) . '</td></tr>'
        . '<tr><td style="padding:6px 0;color:#6c757d">Assigned to</td><td>' . e($t['assignee_name'] ?: 'Unassigned') . '</td></tr>'
        . '</table>'
        . $msgBlock
        . '<p style="margin:24px 0 0"><a href="' . e($link) . '" style="background:#0d6efd;color:#fff;padding:10px 20px;border-radius:5px;text-decoration:none">Open ticket</a></p>'
        . '</div>'
        . '<div style="background:#f4f6f9;color:#6c757d;padding:12px 24px;font-size:12px">This is an automatic message from ' . e(SITE_NAME) . '.</div>'
        . '</div>';
}

/*
|--------------------------------------------------------------------------
| Conversation rendering (used by the page and by the live endpoint)
|--------------------------------------------------------------------------
*/

function renderReplyHtml(array $r, int $ticketCreatorId): string
{
    if (!empty($r['is_system'])) {
        return '<div class="ticket-reply system" data-id="' . (int)$r['id'] . '">'
             . '<i class="bi bi-info-circle"></i> ' . e($r['message'])
             . ' <small class="text-muted ms-2">' . e(date('d M h:i A', strtotime($r['created_at']))) . '</small></div>';
    }

    $isRequester = (int)$r['user_id'] === $ticketCreatorId;
    $cls = $r['is_internal'] ? 'internal' : ($isRequester ? 'requester' : 'staff');

    return '<div class="ticket-reply ' . $cls . '" data-id="' . (int)$r['id'] . '">'
         . '<div class="d-flex justify-content-between">'
         . '<strong>' . e($r['fullname'] ?? 'Unknown') . '</strong>'
         . '<small class="text-muted">' . e(date('d M Y h:i A', strtotime($r['created_at']))) . '</small></div>'
         . ($r['is_internal'] ? '<span class="badge bg-warning text-dark mb-1">Internal note</span>' : '')
         . '<div class="reply-body">' . nl2br(e($r['message'])) . '</div></div>';
}

function fetchReplies(PDO $pdo, int $ticketId, bool $staff, int $afterId = 0): array
{
    $sql = "SELECT r.*, u.fullname FROM ticket_replies r
            LEFT JOIN users u ON u.id = r.user_id
            WHERE r.ticket_id = ? AND r.id > ?";
    if (!$staff) {
        $sql .= " AND r.is_internal = 0";
    }
    $sql .= " ORDER BY r.id ASC";
    $st = $pdo->prepare($sql);
    $st->execute([$ticketId, $afterId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/* SMS alerts (loaded last because it builds on the ticket + KB helpers) */
require_once __DIR__ . '/sms.php';

/* Auto-assign and assets (loaded last; they build on the helpers above) */
require_once __DIR__ . '/autoassign.php';
require_once __DIR__ . '/assets.php';
