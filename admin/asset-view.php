<?php
require_once("../config/auth.php");
require_once("../config/database.php");
require_once("../includes/ticket_functions.php");
ensureTicketSchema($pdo);
ensureAssetSchema($pdo);
requireITStaff($pdo);

$id = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare("SELECT a.*, u.fullname AS holder_name, d.department_name FROM assets a
                     LEFT JOIN users u ON u.id = a.assigned_to LEFT JOIN departments d ON d.id = a.department_id WHERE a.id = ?");
$st->execute([$id]);
$a = $st->fetch(PDO::FETCH_ASSOC);
if (!$a) { http_response_code(404); exit('Asset not found.'); }

if (isset($_POST['delete']) && $currentUserRole === 'admin') {
    csrfCheck();
    $pdo->prepare("DELETE FROM ticket_assets WHERE asset_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM assets WHERE id = ?")->execute([$id]);
    logActivity($pdo, 'Deleted asset: ' . $a['name']);
    header("Location: assets.php?deleted=1");
    exit;
}

$th = $pdo->prepare("
    SELECT t.id, t.ticket_no, t.subject, t.status, t.priority, t.category, t.created_at
    FROM ticket_assets ta JOIN tickets t ON t.id = ta.ticket_id
    WHERE ta.asset_id = ? ORDER BY t.id DESC LIMIT 100");
$th->execute([$id]);
$tickets = $th->fetchAll(PDO::FETCH_ASSOC);
$recent = count(array_filter($tickets, fn($t) => strtotime($t['created_at']) >= time() - 90 * 86400));

include("../includes/header.php");
include("../includes/navbar.php");
include("../includes/sidebar.php");
?>

<div class="content">

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h2><?= e($a['asset_tag']) ?> <small class="text-muted"><?= e($a['name']) ?></small> <?= assetStatusBadge($a['status']) ?></h2>
    <div>
        <a href="asset-edit.php?id=<?= $id ?>" class="btn btn-warning">Edit</a>
        <a href="assets.php" class="btn btn-secondary">← Back</a>
    </div>
</div>

<?php if (isset($_GET['saved'])): ?><div class="alert alert-success">Asset saved.</div><?php endif; ?>
<?php if ($recent >= 3): ?>
<div class="alert alert-danger"><strong>Problem device:</strong> <?= $recent ?> tickets in the last 90 days. Consider repair or replacement.</div>
<?php endif; ?>

<div class="row g-4">
<div class="col-lg-4">
    <div class="card shadow">
        <div class="card-header bg-primary text-white">Details</div>
        <ul class="list-group list-group-flush">
            <?php foreach ([
                'Type' => e($a['asset_type']), 'Brand / model' => e(trim($a['brand'] . ' ' . $a['model'])) ?: '-',
                'Serial number' => e($a['serial_number']) ?: '-',
                'Held by' => $a['holder_name'] ? e($a['holder_name']) : '-', 'Department' => e($a['department_name'] ?: '-'),
                'Location' => e($a['location']) ?: '-',
                'Purchased' => $a['purchase_date'] ? e(date('d M Y', strtotime($a['purchase_date']))) : '-',
                'Warranty ends' => warrantyBadge($a['warranty_end']),
            ] as $k => $v): ?>
            <li class="list-group-item d-flex justify-content-between"><span class="text-muted"><?= $k ?></span><span class="text-end"><?= $v ?></span></li>
            <?php endforeach; ?>
        </ul>
        <?php if (trim((string)$a['notes']) !== ''): ?><div class="card-body border-top"><small class="text-muted">Notes</small><br><?= nl2br(e($a['notes'])) ?></div><?php endif; ?>
    </div>
    <?php if ($currentUserRole === 'admin'): ?>
    <form method="post" class="mt-3" onsubmit="return confirm('Delete this asset? Its tickets stay, but the device link is removed.');">
        <?= csrfField() ?><button name="delete" value="1" class="btn btn-outline-danger btn-sm">Delete asset</button>
    </form>
    <?php endif; ?>
</div>

<div class="col-lg-8">
    <div class="card shadow">
        <div class="card-header bg-dark text-white">Ticket history (<?= count($tickets) ?>)</div>
        <div class="card-body">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Ticket</th><th>Subject</th><th>Priority</th><th>Status</th><th>Opened</th></tr></thead>
            <tbody>
            <?php if ($tickets): foreach ($tickets as $t): ?>
            <tr>
                <td><a href="view-ticket.php?id=<?= (int)$t['id'] ?>"><strong><?= e($t['ticket_no']) ?></strong></a></td>
                <td><?= e($t['subject']) ?><br><small class="text-muted"><?= e($t['category']) ?></small></td>
                <td><?= priorityBadge($t['priority']) ?></td>
                <td><?= statusBadge($t['status']) ?></td>
                <td><small><?= e(date('d M Y', strtotime($t['created_at']))) ?></small></td>
            </tr>
            <?php endforeach; else: ?>
            <tr><td colspan="5" class="text-center text-muted">No tickets linked to this device yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
</div>
</div>

<?php include("../includes/footer.php"); ?>
