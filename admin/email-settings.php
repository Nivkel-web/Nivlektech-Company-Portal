<?php
require_once("../config/auth.php");
requireRole("admin");
require_once("../config/database.php");
require_once("../includes/ticket_functions.php");
ensureTicketSchema($pdo);

$message = "";
$cfg = getMailSettings($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    csrfCheck();

    if (isset($_POST['save'])) {

        $driver = in_array($_POST['driver'] ?? '', ['log', 'mail', 'smtp'], true) ? $_POST['driver'] : 'log';
        $enc    = in_array($_POST['smtp_encryption'] ?? '', ['tls', 'ssl', 'none'], true) ? $_POST['smtp_encryption'] : 'tls';
        $from   = trim($_POST['from_email'] ?? '');

        if ($from !== '' && !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            $message = "<div class='alert alert-danger'>The From address is not a valid email.</div>";
        } else {
            // keep the old password when the field is left empty
            $pass = $_POST['smtp_password'] ?? '';
            if ($pass === '') {
                $pass = $cfg['smtp_password'];
            }

            $pdo->prepare("
                UPDATE mail_settings SET
                    enabled = ?, driver = ?, from_email = ?, from_name = ?,
                    smtp_host = ?, smtp_port = ?, smtp_encryption = ?, smtp_username = ?, smtp_password = ?,
                    notify_new_ticket = ?, notify_assigned = ?, notify_reply = ?, notify_status = ?,
                    updated_at = ?
                WHERE id = 1
            ")->execute([
                isset($_POST['enabled']) ? 1 : 0, $driver, $from, trim($_POST['from_name'] ?? ''),
                trim($_POST['smtp_host'] ?? ''), (int)($_POST['smtp_port'] ?? 587), $enc,
                trim($_POST['smtp_username'] ?? ''), $pass,
                isset($_POST['notify_new_ticket']) ? 1 : 0, isset($_POST['notify_assigned']) ? 1 : 0,
                isset($_POST['notify_reply']) ? 1 : 0,      isset($_POST['notify_status']) ? 1 : 0,
                date('Y-m-d H:i:s'),
            ]);
            logActivity($pdo, 'Updated email notification settings');
            $cfg = getMailSettings($pdo);
            $message = "<div class='alert alert-success'>Email settings saved.</div>";
        }
    }

    if (isset($_POST['test'])) {
        $to = trim($_POST['test_to'] ?? '');
        $mailer = new Mailer($pdo);
        $html = '<div style="font-family:Segoe UI,Arial,sans-serif"><h3>Test email</h3><p>If you can read this, email notifications from <strong>'
              . e(SITE_NAME) . '</strong> are working.</p></div>';
        [$ok, $err] = $mailer->send($to, SITE_NAME . ' - test email', $html, 'test');
        $message = $ok
            ? "<div class='alert alert-success'>Test email processed for " . e($to) . ". Check the Email Log for the result.</div>"
            : "<div class='alert alert-danger'>Test failed: " . e($err) . "</div>";
    }
}

include("../includes/header.php");
include("../includes/navbar.php");
include("../includes/sidebar.php");
?>

<div class="content">

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-envelope-at"></i> Email Notification</h2>
    <a href="email-logs.php" class="btn btn-outline-dark"><i class="bi bi-list-ul"></i> Email Log</a>
</div>

<?= $message ?>

<form method="post">
<?= csrfField() ?>

<div class="row g-4">
<div class="col-lg-7">
    <div class="card shadow mb-4">
        <div class="card-header bg-dark text-white">Sending</div>
        <div class="card-body">

            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" name="enabled" id="enabled" <?= $cfg['enabled'] ? 'checked' : '' ?>>
                <label class="form-check-label" for="enabled"><strong>Turn on email notifications</strong></label>
            </div>

            <div class="mb-3">
                <label class="form-label">Method</label>
                <select name="driver" id="driver" class="form-select">
                    <option value="log"  <?= $cfg['driver'] === 'log'  ? 'selected' : '' ?>>Log only (testing — nothing is sent)</option>
                    <option value="mail" <?= $cfg['driver'] === 'mail' ? 'selected' : '' ?>>PHP mail() (server mail)</option>
                    <option value="smtp" <?= $cfg['driver'] === 'smtp' ? 'selected' : '' ?>>SMTP (Gmail, Outlook, hosting mail…)</option>
                </select>
            </div>

            <div class="row">
                <div class="col-md-6 mb-3"><label class="form-label">From name</label>
                    <input type="text" name="from_name" class="form-control" value="<?= e($cfg['from_name']) ?>"></div>
                <div class="col-md-6 mb-3"><label class="form-label">From email</label>
                    <input type="email" name="from_email" class="form-control" value="<?= e($cfg['from_email']) ?>" placeholder="support@yourcompany.com"></div>
            </div>

            <div id="smtpBox">
                <hr>
                <div class="row">
                    <div class="col-md-8 mb-3"><label class="form-label">SMTP host</label>
                        <input type="text" name="smtp_host" class="form-control" value="<?= e($cfg['smtp_host']) ?>" placeholder="smtp.gmail.com"></div>
                    <div class="col-md-4 mb-3"><label class="form-label">Port</label>
                        <input type="number" name="smtp_port" class="form-control" value="<?= (int)$cfg['smtp_port'] ?>"></div>
                </div>
                <div class="mb-3"><label class="form-label">Encryption</label>
                    <select name="smtp_encryption" class="form-select">
                        <option value="tls"  <?= $cfg['smtp_encryption'] === 'tls'  ? 'selected' : '' ?>>STARTTLS (port 587)</option>
                        <option value="ssl"  <?= $cfg['smtp_encryption'] === 'ssl'  ? 'selected' : '' ?>>SSL/TLS (port 465)</option>
                        <option value="none" <?= $cfg['smtp_encryption'] === 'none' ? 'selected' : '' ?>>None (port 25)</option>
                    </select></div>
                <div class="row">
                    <div class="col-md-6 mb-3"><label class="form-label">Username</label>
                        <input type="text" name="smtp_username" class="form-control" value="<?= e($cfg['smtp_username']) ?>" autocomplete="off"></div>
                    <div class="col-md-6 mb-3"><label class="form-label">Password</label>
                        <input type="password" name="smtp_password" class="form-control" autocomplete="new-password"
                               placeholder="<?= $cfg['smtp_password'] !== '' ? 'Saved — leave empty to keep' : '' ?>"></div>
                </div>
                <small class="text-muted">Gmail needs an <em>App Password</em>, not your normal password.</small>
            </div>
        </div>
    </div>
</div>

<div class="col-lg-5">
    <div class="card shadow mb-4">
        <div class="card-header bg-primary text-white">Send an email when…</div>
        <div class="card-body">
            <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="notify_new_ticket" id="n1" <?= $cfg['notify_new_ticket'] ? 'checked' : '' ?>><label class="form-check-label" for="n1">a new ticket is created (requester + admins)</label></div>
            <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="notify_assigned" id="n2" <?= $cfg['notify_assigned'] ? 'checked' : '' ?>><label class="form-check-label" for="n2">a ticket is assigned (agent + requester)</label></div>
            <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="notify_reply" id="n3" <?= $cfg['notify_reply'] ? 'checked' : '' ?>><label class="form-check-label" for="n3">someone replies (the other side)</label></div>
            <div class="form-check"><input class="form-check-input" type="checkbox" name="notify_status" id="n4" <?= $cfg['notify_status'] ? 'checked' : '' ?>><label class="form-check-label" for="n4">the status changes (requester + agent)</label></div>
            <hr>
            <small class="text-muted">Internal notes are never emailed. The person who performs an action is not emailed about it.</small>
        </div>
    </div>

    <div class="card shadow mb-4">
        <div class="card-header bg-success text-white">Send a test email</div>
        <div class="card-body">
            <div class="input-group">
                <input type="email" name="test_to" class="form-control" placeholder="you@example.com" value="<?= e($_SESSION['email'] ?? '') ?>">
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
    var d = document.getElementById('driver'), box = document.getElementById('smtpBox');
    function sync() { box.style.display = d.value === 'smtp' ? '' : 'none'; }
    d.addEventListener('change', sync); sync();
})();
</script>

<?php include("../includes/footer.php"); ?>
