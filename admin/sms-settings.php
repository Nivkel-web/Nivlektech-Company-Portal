<?php
require_once("../config/auth.php");
requireRole("admin");
require_once("../config/database.php");
require_once("../includes/ticket_functions.php");
ensureTicketSchema($pdo);
ensureSmsSchema($pdo);

$message = "";
$cfg = getSmsSettings($pdo);

// IT team = admins + active users in the IT department
$team = $pdo->query("
    SELECT u.id, u.fullname, u.role, u.phone, d.department_name, COALESCE(s.enabled, 0) AS sms_on
    FROM users u
    LEFT JOIN departments d ON d.id = u.department_id
    LEFT JOIN user_sms s ON s.user_id = u.id
    WHERE u.status = 'active'
    ORDER BY u.fullname
")->fetchAll(PDO::FETCH_ASSOC);
$team = array_values(array_filter($team, fn($u) => $u['role'] === 'admin' || strtolower(trim((string)$u['department_name'])) === 'it'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    csrfCheck();

    if (isset($_POST['save'])) {
        $provider = in_array($_POST['provider'] ?? '', ['log', 'twilio', 'africastalking'], true) ? $_POST['provider'] : 'log';
        $level    = in_array($_POST['new_ticket_level'] ?? '', ['off', 'urgent', 'high', 'all'], true) ? $_POST['new_ticket_level'] : 'high';
        $cc       = preg_replace('/\D/', '', $_POST['country_code'] ?? '254') ?: '254';

        // empty secret fields keep the stored value
        $keep = fn($field, $col) => (trim($_POST[$field] ?? '') !== '') ? trim($_POST[$field]) : $cfg[$col];

        $pdo->prepare("
            UPDATE sms_settings SET enabled=?, provider=?, country_code=?,
                twilio_sid=?, twilio_token=?, twilio_from=?,
                at_username=?, at_api_key=?, at_sender_id=?,
                new_ticket_level=?, notify_assigned=?, notify_due=?, updated_at=?
            WHERE id = 1
        ")->execute([
            isset($_POST['enabled']) ? 1 : 0, $provider, $cc,
            trim($_POST['twilio_sid'] ?? ''), $keep('twilio_token', 'twilio_token'), trim($_POST['twilio_from'] ?? ''),
            trim($_POST['at_username'] ?? ''), $keep('at_api_key', 'at_api_key'), trim($_POST['at_sender_id'] ?? ''),
            $level, isset($_POST['notify_assigned']) ? 1 : 0, isset($_POST['notify_due']) ? 1 : 0, date('Y-m-d H:i:s'),
        ]);
        $cfg = getSmsSettings($pdo);

        // team phones + opt-in
        $bad = [];
        foreach ($team as $u) {
            $uid = (int)$u['id'];
            $raw = trim($_POST['phone'][$uid] ?? '');
            $on  = isset($_POST['sms_on'][$uid]);
            $norm = $raw === '' ? '' : normalizePhone($raw, $cfg['country_code']);
            if ($raw !== '' && !$norm) {
                $bad[] = $u['fullname'];
                continue;
            }
            $pdo->prepare("UPDATE users SET phone = ? WHERE id = ?")->execute([$norm ?: null, $uid]);
            $has = $pdo->prepare("SELECT COUNT(*) FROM user_sms WHERE user_id = ?");
            $has->execute([$uid]);
            if ($has->fetchColumn()) {
                $pdo->prepare("UPDATE user_sms SET enabled = ? WHERE user_id = ?")->execute([($on && $norm) ? 1 : 0, $uid]);
            } else {
                $pdo->prepare("INSERT INTO user_sms (user_id, enabled) VALUES (?,?)")->execute([$uid, ($on && $norm) ? 1 : 0]);
            }
        }
        logActivity($pdo, 'Updated SMS alert settings');
        $message = $bad
            ? "<div class='alert alert-warning'>Saved, but these phone numbers look invalid and were not stored: " . e(implode(', ', $bad)) . ".</div>"
            : "<div class='alert alert-success'>SMS settings saved.</div>";

        // reload team view with the new values
        $team = $pdo->query("
            SELECT u.id, u.fullname, u.role, u.phone, d.department_name, COALESCE(s.enabled, 0) AS sms_on
            FROM users u LEFT JOIN departments d ON d.id = u.department_id LEFT JOIN user_sms s ON s.user_id = u.id
            WHERE u.status = 'active' ORDER BY u.fullname
        ")->fetchAll(PDO::FETCH_ASSOC);
        $team = array_values(array_filter($team, fn($u) => $u['role'] === 'admin' || strtolower(trim((string)$u['department_name'])) === 'it'));
    }

    if (isset($_POST['test'])) {
        $to = trim($_POST['test_to'] ?? '');
        $sms = new SmsSender($pdo);
        [$ok, $err] = $sms->send($to, SITE_NAME . ': test SMS. If you can read this, SMS alerts work.', 'test', null, true);
        $message = $ok
            ? "<div class='alert alert-success'>Test SMS processed for " . e($to) . ". Check the SMS Log for the result.</div>"
            : "<div class='alert alert-danger'>Test failed: " . e($err) . "</div>";
    }
}

include("../includes/header.php");
include("../includes/navbar.php");
include("../includes/sidebar.php");
?>

<div class="content">

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-chat-dots"></i> SMS Alerts</h2>
    <a href="sms-logs.php" class="btn btn-outline-dark"><i class="bi bi-list-ul"></i> SMS Log</a>
</div>

<?= $message ?>

<form method="post">
<?= csrfField() ?>

<div class="row g-4">
<div class="col-lg-7">

    <div class="card shadow mb-4">
        <div class="card-header bg-dark text-white">Provider</div>
        <div class="card-body">
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" name="enabled" id="enabled" <?= $cfg['enabled'] ? 'checked' : '' ?>>
                <label class="form-check-label" for="enabled"><strong>Turn on SMS alerts</strong></label>
            </div>

            <div class="row">
                <div class="col-md-8 mb-3"><label class="form-label">Provider</label>
                    <select name="provider" id="provider" class="form-select">
                        <option value="log" <?= $cfg['provider'] === 'log' ? 'selected' : '' ?>>Log only (testing — nothing is sent)</option>
                        <option value="africastalking" <?= $cfg['provider'] === 'africastalking' ? 'selected' : '' ?>>Africa's Talking</option>
                        <option value="twilio" <?= $cfg['provider'] === 'twilio' ? 'selected' : '' ?>>Twilio</option>
                    </select></div>
                <div class="col-md-4 mb-3"><label class="form-label">Default country code</label>
                    <input type="text" name="country_code" class="form-control" value="<?= e($cfg['country_code']) ?>" placeholder="254">
                    <small class="text-muted">0712… becomes +254712…</small></div>
            </div>

            <div id="boxAT">
                <hr><h6>Africa's Talking</h6>
                <div class="row">
                    <div class="col-md-6 mb-3"><label class="form-label">Username</label>
                        <input type="text" name="at_username" class="form-control" value="<?= e($cfg['at_username']) ?>" placeholder="your app username (or sandbox)" autocomplete="off"></div>
                    <div class="col-md-6 mb-3"><label class="form-label">Sender ID (optional)</label>
                        <input type="text" name="at_sender_id" class="form-control" value="<?= e($cfg['at_sender_id']) ?>" placeholder="must be approved by them"></div>
                </div>
                <div class="mb-3"><label class="form-label">API key</label>
                    <input type="password" name="at_api_key" class="form-control" autocomplete="new-password"
                           placeholder="<?= $cfg['at_api_key'] !== '' ? 'Saved — leave empty to keep' : '' ?>"></div>
                <small class="text-muted">Username <code>sandbox</code> uses their free test environment.</small>
            </div>

            <div id="boxTW">
                <hr><h6>Twilio</h6>
                <div class="mb-3"><label class="form-label">Account SID</label>
                    <input type="text" name="twilio_sid" class="form-control" value="<?= e($cfg['twilio_sid']) ?>" autocomplete="off"></div>
                <div class="row">
                    <div class="col-md-6 mb-3"><label class="form-label">Auth token</label>
                        <input type="password" name="twilio_token" class="form-control" autocomplete="new-password"
                               placeholder="<?= $cfg['twilio_token'] !== '' ? 'Saved — leave empty to keep' : '' ?>"></div>
                    <div class="col-md-6 mb-3"><label class="form-label">From number</label>
                        <input type="text" name="twilio_from" class="form-control" value="<?= e($cfg['twilio_from']) ?>" placeholder="+1555…"></div>
                </div>
                <small class="text-muted">Trial accounts can only text numbers verified in the Twilio console.</small>
            </div>
        </div>
    </div>

    <div class="card shadow mb-4">
        <div class="card-header bg-primary text-white">IT team — who gets texted</div>
        <div class="card-body">
            <table class="table align-middle mb-2">
                <thead><tr><th>Person</th><th>Phone</th><th width="90">SMS on</th></tr></thead>
                <tbody>
                <?php foreach ($team as $u): ?>
                <tr>
                    <td><?= e($u['fullname']) ?><br><small class="text-muted"><?= e($u['role'] === 'admin' ? 'Admin' : 'IT') ?></small></td>
                    <td><input type="text" name="phone[<?= (int)$u['id'] ?>]" class="form-control form-control-sm" value="<?= e($u['phone']) ?>" placeholder="0712 345 678"></td>
                    <td class="text-center"><input type="checkbox" class="form-check-input" name="sms_on[<?= (int)$u['id'] ?>]" <?= $u['sms_on'] ? 'checked' : '' ?>></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <small class="text-muted">Nobody is texted until they are ticked here (opt-in) and have a valid number.</small>
        </div>
    </div>

</div>

<div class="col-lg-5">

    <div class="card shadow mb-4">
        <div class="card-header bg-warning">Send an SMS when…</div>
        <div class="card-body">
            <label class="form-label">a new ticket is created with priority</label>
            <select name="new_ticket_level" class="form-select mb-3">
                <option value="urgent" <?= $cfg['new_ticket_level'] === 'urgent' ? 'selected' : '' ?>>Urgent only</option>
                <option value="high"   <?= $cfg['new_ticket_level'] === 'high'   ? 'selected' : '' ?>>High and Urgent</option>
                <option value="all"    <?= $cfg['new_ticket_level'] === 'all'    ? 'selected' : '' ?>>Any priority (noisy)</option>
                <option value="off"    <?= $cfg['new_ticket_level'] === 'off'    ? 'selected' : '' ?>>Never</option>
            </select>
            <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="notify_assigned" id="s1" <?= $cfg['notify_assigned'] ? 'checked' : '' ?>><label class="form-check-label" for="s1">a ticket is assigned to someone (that person)</label></div>
            <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="notify_due" id="s2" <?= $cfg['notify_due'] ? 'checked' : '' ?>><label class="form-check-label" for="s2">a ticket is 30 min from its due time, and when it becomes overdue</label></div>
            <hr>
            <small class="text-muted">Due-time alerts need a scheduled task that runs <code>cron/check-due.php</code> every 10 minutes (see README).</small>
        </div>
    </div>

    <div class="card shadow mb-4">
        <div class="card-header bg-success text-white">Send a test SMS</div>
        <div class="card-body">
            <div class="input-group">
                <input type="text" name="test_to" class="form-control" placeholder="0712 345 678">
                <button name="test" value="1" class="btn btn-success" formnovalidate>Send test</button>
            </div>
            <small class="text-muted">Save your settings first, then test.</small>
        </div>
    </div>

</div>
</div>

<button name="save" value="1" class="btn btn-primary"><i class="bi bi-save"></i> Save Settings</button>
</form>
</div>

<script>
(function () {
    var p = document.getElementById('provider');
    function sync() {
        document.getElementById('boxAT').style.display = p.value === 'africastalking' ? '' : 'none';
        document.getElementById('boxTW').style.display = p.value === 'twilio' ? '' : 'none';
    }
    p.addEventListener('change', sync); sync();
})();
</script>

<?php include("../includes/footer.php"); ?>
