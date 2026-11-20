<?php
require_once("../config/auth.php");
requireRole("admin");
require_once("../config/database.php");
require_once("../includes/ticket_functions.php");
ensureTicketSchema($pdo);
ensureSmsSchema($pdo);

if (isset($_POST['clear'])) {
    csrfCheck();
    $pdo->exec("DELETE FROM sms_logs");
    logActivity($pdo, 'Cleared SMS log');
    header("Location: sms-logs.php?cleared=1");
    exit;
}

$logs = $pdo->query("SELECT * FROM sms_logs ORDER BY id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
$badge = ['sent' => 'success', 'logged' => 'info', 'failed' => 'danger', 'skipped' => 'secondary'];

include("../includes/header.php");
include("../includes/navbar.php");
include("../includes/sidebar.php");
?>

<div class="content">

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>SMS Log</h2>
    <div>
        <a href="sms-settings.php" class="btn btn-secondary">← Settings</a>
        <form method="post" class="d-inline" onsubmit="return confirm('Clear the whole SMS log? Due-time alerts may be sent again for tickets still due.');">
            <?= csrfField() ?>
            <button name="clear" value="1" class="btn btn-danger">Clear Log</button>
        </form>
    </div>
</div>

<?php if (isset($_GET['cleared'])): ?><div class="alert alert-success">SMS log cleared.</div><?php endif; ?>

<div class="card shadow"><div class="card-body">
<div class="table-responsive">
<table class="table table-bordered table-hover align-middle">
<thead class="table-dark"><tr><th width="170">Time</th><th>To</th><th>Message</th><th>Event</th><th>Provider</th><th>Status</th><th>Error</th></tr></thead>
<tbody>
<?php if ($logs): foreach ($logs as $l): ?>
<tr>
    <td><?= e(date('d M Y h:i A', strtotime($l['created_at']))) ?></td>
    <td><?= e($l['recipient']) ?></td>
    <td><small><?= e($l['message']) ?></small></td>
    <td><?= e($l['event']) ?></td>
    <td><?= e($l['provider']) ?></td>
    <td><span class="badge bg-<?= $badge[$l['status']] ?? 'secondary' ?>"><?= e($l['status']) ?></span></td>
    <td><small class="text-danger"><?= e($l['error_message']) ?></small></td>
</tr>
<?php endforeach; else: ?>
<tr><td colspan="7" class="text-center">No SMS yet</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
<small class="text-muted">Showing the latest 200.</small>
</div></div>

</div>

<?php include("../includes/footer.php"); ?>
