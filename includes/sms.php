<?php
/*
|--------------------------------------------------------------------------
| Nivlektech Company Portal
|--------------------------------------------------------------------------
| File : includes/sms.php
| SMS alerts for the IT team. Providers: log (test), Twilio, Africa's Talking.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/kb_functions.php';   // brings ticket_functions + isITStaff

function ensureSmsSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $pk   = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
    $tail = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sms_settings (
            id INT PRIMARY KEY,
            enabled TINYINT NOT NULL DEFAULT 0,
            provider VARCHAR(20) NOT NULL DEFAULT 'log',
            country_code VARCHAR(5) NOT NULL DEFAULT '254',
            twilio_sid VARCHAR(100) DEFAULT '',
            twilio_token VARCHAR(100) DEFAULT '',
            twilio_from VARCHAR(30) DEFAULT '',
            at_username VARCHAR(100) DEFAULT '',
            at_api_key VARCHAR(150) DEFAULT '',
            at_sender_id VARCHAR(20) DEFAULT '',
            new_ticket_level VARCHAR(10) NOT NULL DEFAULT 'high',
            notify_assigned TINYINT NOT NULL DEFAULT 1,
            notify_due TINYINT NOT NULL DEFAULT 1,
            updated_at DATETIME DEFAULT NULL
        )$tail
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_sms (
            user_id INT PRIMARY KEY,
            enabled TINYINT NOT NULL DEFAULT 0
        )$tail
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sms_logs (
            id $pk,
            ticket_id INT DEFAULT NULL,
            recipient VARCHAR(30),
            message VARCHAR(400),
            event VARCHAR(30),
            provider VARCHAR(20),
            status VARCHAR(15),
            error_message TEXT,
            created_at DATETIME NOT NULL
        )$tail
    ");
    if (!$pdo->query("SELECT COUNT(*) FROM sms_settings")->fetchColumn()) {
        $pdo->exec("INSERT INTO sms_settings (id) VALUES (1)");
    }
    $done = true;
}

function getSmsSettings(PDO $pdo): array
{
    ensureSmsSchema($pdo);
    return $pdo->query("SELECT * FROM sms_settings WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
}

/* "0712 345 678" -> "+254712345678". Returns null when it cannot be a valid number. */
function normalizePhone(string $raw, string $cc = '254'): ?string
{
    $n = preg_replace('/[^\d+]/', '', trim($raw));
    if ($n === '') {
        return null;
    }
    if (strpos($n, '+') === 0) {
        $n = '+' . preg_replace('/\D/', '', $n);
    } elseif (strpos($n, '00') === 0) {
        $n = '+' . substr($n, 2);
    } elseif (strpos($n, '0') === 0) {
        $n = '+' . $cc . substr($n, 1);
    } elseif (strpos($n, $cc) === 0) {
        $n = '+' . $n;
    } else {
        $n = '+' . $cc . $n;
    }
    return preg_match('/^\+\d{9,15}$/', $n) ? $n : null;
}

class SmsSender
{
    private PDO $pdo;
    private array $cfg;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->cfg = getSmsSettings($pdo);
    }

    /** Returns [bool ok, string error]. Always writes a row to sms_logs. */
    public function send(string $to, string $message, string $event = 'general', ?int $ticketId = null, bool $force = false): array
    {
        $provider = $this->cfg['provider'];

        if (!$force && (int)$this->cfg['enabled'] !== 1) {
            return $this->done($ticketId, $to, $message, $event, 'skipped', 'SMS alerts are turned off');
        }
        $to = normalizePhone($to, $this->cfg['country_code']);
        if (!$to) {
            return $this->done($ticketId, (string)$to, $message, $event, 'failed', 'Invalid phone number');
        }

        try {
            if ($provider === 'log') {
                return $this->done($ticketId, $to, $message, $event, 'logged', 'Log-only mode, nothing was sent');
            }
            if ($provider === 'twilio') {
                $this->twilio($to, $message);
            } elseif ($provider === 'africastalking') {
                $this->africasTalking($to, $message);
            } else {
                throw new RuntimeException('Unknown SMS provider');
            }
            return $this->done($ticketId, $to, $message, $event, 'sent', '');
        } catch (Throwable $e) {
            return $this->done($ticketId, $to, $message, $event, 'failed', $e->getMessage());
        }
    }

    private function twilio(string $to, string $msg): void
    {
        $c = $this->cfg;
        if ($c['twilio_sid'] === '' || $c['twilio_token'] === '' || $c['twilio_from'] === '') {
            throw new RuntimeException('Twilio SID, token and From number are required');
        }
        $base = defined('SMS_TWILIO_BASE') ? SMS_TWILIO_BASE : 'https://api.twilio.com';
        $url  = rtrim($base, '/') . '/2010-04-01/Accounts/' . rawurlencode($c['twilio_sid']) . '/Messages.json';

        [$code, $body] = $this->post($url, ['To' => $to, 'From' => $c['twilio_from'], 'Body' => $msg], [],
                                     $c['twilio_sid'] . ':' . $c['twilio_token']);
        if ($code < 200 || $code >= 300) {
            $j = json_decode($body, true);
            throw new RuntimeException('Twilio ' . $code . ': ' . ($j['message'] ?? substr($body, 0, 150)));
        }
    }

    private function africasTalking(string $to, string $msg): void
    {
        $c = $this->cfg;
        if ($c['at_username'] === '' || $c['at_api_key'] === '') {
            throw new RuntimeException("Africa's Talking username and API key are required");
        }
        $sandbox = strtolower($c['at_username']) === 'sandbox';
        $base = defined('SMS_AT_BASE') ? SMS_AT_BASE
              : ($sandbox ? 'https://api.sandbox.africastalking.com' : 'https://api.africastalking.com');

        $fields = ['username' => $c['at_username'], 'to' => $to, 'message' => $msg];
        if ($c['at_sender_id'] !== '' && !$sandbox) {
            $fields['from'] = $c['at_sender_id'];
        }
        [$code, $body] = $this->post(rtrim($base, '/') . '/version1/messaging', $fields,
                                     ['apiKey: ' . $c['at_api_key'], 'Accept: application/json']);
        $j = json_decode($body, true);
        $r = $j['SMSMessageData']['Recipients'][0] ?? null;
        if ($code < 200 || $code >= 300 || !$r || stripos((string)($r['status'] ?? ''), 'Success') === false) {
            throw new RuntimeException("Africa's Talking " . $code . ': ' . ($r['status'] ?? substr($body, 0, 150)));
        }
    }

    private function post(string $url, array $fields, array $headers = [], ?string $userpwd = null): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        if ($userpwd !== null) {
            curl_setopt($ch, CURLOPT_USERPWD, $userpwd);
        }
        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Network error: ' . $err);
        }
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, (string)$body];
    }

    private function done(?int $ticketId, string $to, string $msg, string $event, string $status, string $err): array
    {
        try {
            $this->pdo->prepare("
                INSERT INTO sms_logs (ticket_id, recipient, message, event, provider, status, error_message, created_at)
                VALUES (?,?,?,?,?,?,?,?)
            ")->execute([$ticketId, $to, $msg, $event, $this->cfg['provider'], $status, $err, date('Y-m-d H:i:s')]);
        } catch (Throwable $e) {
            // a log problem must never break a ticket action
        }
        return [in_array($status, ['sent', 'logged'], true), $err];
    }
}

/*
|--------------------------------------------------------------------------
| Who gets texted
|--------------------------------------------------------------------------
*/

/** Enrolled IT-team members that have a phone number. Optionally only one user. */
function smsRecipients(PDO $pdo, ?int $onlyUserId = null, ?int $excludeUserId = null): array
{
    ensureSmsSchema($pdo);
    $rows = $pdo->query("
        SELECT u.id, u.fullname, u.phone, u.role, d.department_name
        FROM user_sms s
        JOIN users u ON u.id = s.user_id AND u.status = 'active'
        LEFT JOIN departments d ON d.id = u.department_id
        WHERE s.enabled = 1 AND u.phone IS NOT NULL AND u.phone <> ''
    ")->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($rows as $r) {
        $isIT = $r['role'] === 'admin' || strtolower(trim((string)$r['department_name'])) === 'it';
        if (!$isIT) continue;
        if ($onlyUserId && (int)$r['id'] !== $onlyUserId) continue;
        if ($excludeUserId && (int)$r['id'] === $excludeUserId) continue;
        $out[] = $r;
    }
    return $out;
}

function smsText(array $t, string $prefix): string
{
    $no   = $t['ticket_no'] ?: ticketNumber((int)$t['id']);
    $link = rtrim(BASE_URL, '/') . '/admin/view-ticket.php?id=' . (int)$t['id'];
    $subj = mb_strimwidth(preg_replace('/\s+/', ' ', $t['subject']), 0, 60, '...');
    return "[$no] $prefix: $subj. $link";
}

/*
|--------------------------------------------------------------------------
| Event hooks (called from ticket_functions.php, best-effort)
|--------------------------------------------------------------------------
*/

function smsNotifyTicket(PDO $pdo, ?array $ticket, string $event, int $actorId): void
{
    if (!$ticket) return;
    try {
        $cfg = getSmsSettings($pdo);
        if ((int)$cfg['enabled'] !== 1) return;

        $sms = new SmsSender($pdo);
        $tid = (int)$ticket['id'];

        if ($event === 'new_ticket') {
            $level = $cfg['new_ticket_level'];          // off | urgent | high | all
            $ok = ['off' => [], 'urgent' => ['urgent'], 'high' => ['urgent', 'high'],
                   'all' => ['urgent', 'high', 'medium', 'low']][$level] ?? [];
            if (!in_array($ticket['priority'], $ok, true)) return;

            $label = strtoupper($ticket['priority']);
            $text = smsText($ticket, "$label ticket from " . $ticket['creator_name']);
            foreach (smsRecipients($pdo, null, $actorId) as $r) {
                // someone already auto-assigned gets the "assigned to you" text instead
                if ((int)$ticket['assigned_to'] === (int)$r['id'] && (int)$cfg['notify_assigned'] === 1) continue;
                $sms->send($r['phone'], $text, 'new_ticket', $tid);
            }
        }

        if ($event === 'assigned' && (int)$cfg['notify_assigned'] === 1 && $ticket['assigned_to']) {
            if ((int)$ticket['assigned_to'] === $actorId) return;   // assigned it to themselves
            $text = smsText($ticket, 'Assigned to you (' . strtoupper($ticket['priority']) . ')');
            foreach (smsRecipients($pdo, (int)$ticket['assigned_to']) as $r) {
                $sms->send($r['phone'], $text, 'assigned', $tid);
            }
        }
    } catch (Throwable $e) {
        // SMS is best-effort
    }
}

/*
| Run from cron / Task Scheduler (see cron/check-due.php): warns once when a
| ticket is within 30 minutes of its due time and once when it is overdue.
*/
function smsCheckDueTickets(PDO $pdo): array
{
    $cfg = getSmsSettings($pdo);
    $summary = ['due_soon' => 0, 'overdue' => 0, 'skipped' => ''];
    if ((int)$cfg['enabled'] !== 1 || (int)$cfg['notify_due'] !== 1) {
        $summary['skipped'] = 'SMS or due-time alerts are turned off';
        return $summary;
    }

    $now = time();
    $st = $pdo->prepare("
        SELECT t.*, c.fullname AS creator_name FROM tickets t
        LEFT JOIN users c ON c.id = t.created_by
        WHERE t.status NOT IN ('resolved','closed') AND t.due_at IS NOT NULL AND t.due_at <= ?
    ");
    $st->execute([date('Y-m-d H:i:s', $now + 1800)]);

    $already = $pdo->prepare("SELECT COUNT(*) FROM sms_logs WHERE ticket_id = ? AND event = ?");
    $sms = new SmsSender($pdo);

    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $overdue = strtotime($t['due_at']) < $now;
        $event   = $overdue ? 'overdue' : 'due_soon';

        $already->execute([$t['id'], $event]);
        if ((int)$already->fetchColumn() > 0) continue;

        $text = smsText($t, $overdue ? 'OVERDUE' : 'Due in under 30 min');
        // the assignee if there is one, otherwise the whole IT team
        $to = $t['assigned_to'] ? smsRecipients($pdo, (int)$t['assigned_to']) : [];
        if (!$to) $to = smsRecipients($pdo);
        foreach ($to as $r) {
            $sms->send($r['phone'], $text, $event, (int)$t['id']);
        }
        $summary[$event]++;
    }
    return $summary;
}
