<?php
require_once("../config/auth.php");
requireRole("admin");
require_once("../config/database.php");
require_once("../includes/ticket_functions.php");
ensureTicketSchema($pdo);

if (isset($_POST['clear'])) {
    csrfCheck();
    $pdo->exec("DELETE FROM email_logs");
    logActivity($pdo, 'Cleared email log');
    header("Location: email-logs.php?cleared=1");
    exit;
}

$logs = $pdo->query("SELECT * FROM email_logs ORDER BY id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
$badge = ['sent' => 'success', 'logged' => 'info', 'failed' => 'danger', 'skipped' => 'secondary'];

include("../includes/header.php");
include("../includes/navbar.php");
include("../includes/sidebar.php");
?>

<div class="content">

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Email Log</h2>
    <div>
        <a href="email-settings.php" class="btn btn-secondary">← Settings</a>
        <form method="post" class="d-inline" onsubmit="return confirm('Clear the whole email log?');">
            <?= csrfField() ?>
            <button name="clear" value="1" class="btn btn-danger">Clear Log</button>
        </form>
    </div>
</div>

<?php if (isset($_GET['cleared'])): ?><div class="alert alert-success">Email log cleared.</div><?php endif; ?>

<div class="card shadow"><div class="card-body">
<div class="table-responsive">
<table class="table table-bordered table-hover align-middle">
<thead class="table-dark"><tr><th width="170">Time</th><th>To</th><th>Subject</th><th>Event</th><th>Status</th><th>Error</th></tr></thead>
<tbody>
<?php if ($logs): foreach ($logs as $l): ?>
<tr>
    <td><?= e(date('d M Y h:i A', strtotime($l['created_at']))) ?></td>
    <td><?= e($l['recipient']) ?></td>
    <td><?= e($l['subject']) ?></td>
    <td><?= e($l['event']) ?></td>
    <td><span class="badge bg-<?= $badge[$l['status']] ?? 'secondary' ?>"><?= e($l['status']) ?></span></td>
    <td><small class="text-danger"><?= e($l['error_message']) ?></small></td>
</tr>
<?php endforeach; else: ?>
<tr><td colspan="6" class="text-center">No emails yet</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
<small class="text-muted">Showing the latest 200.</small>
</div></div>

</div>

<?php include("../includes/footer.php"); ?>
