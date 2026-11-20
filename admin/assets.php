<?php
require_once("../config/auth.php");
require_once("../config/database.php");
require_once("../includes/ticket_functions.php");
ensureTicketSchema($pdo);
ensureAssetSchema($pdo);
requireITStaff($pdo);

$q      = trim($_GET['q'] ?? '');
$type   = $_GET['type'] ?? '';
$status = $_GET['status'] ?? '';
$holder = (int)($_GET['holder'] ?? 0);
$warn   = ($_GET['warranty'] ?? '') === 'soon';

$where = []; $args = [];
if ($q !== '') {
    $where[] = '(a.name LIKE ? OR a.asset_tag LIKE ? OR a.serial_number LIKE ? OR a.brand LIKE ? OR a.model LIKE ?)';
    $like = '%' . $q . '%'; array_push($args, $like, $like, $like, $like, $like);
}
if (in_array($type, ASSET_TYPES, true))     { $where[] = 'a.asset_type = ?'; $args[] = $type; }
if (isset(ASSET_STATUSES[$status]))         { $where[] = 'a.status = ?';     $args[] = $status; }
if ($holder)                                { $where[] = 'a.assigned_to = ?'; $args[] = $holder; }

$since = date('Y-m-d H:i:s', time() - 90 * 86400);
$sql = "
    SELECT a.*, u.fullname AS holder_name,
           (SELECT COUNT(*) FROM ticket_assets ta WHERE ta.asset_id = a.id) AS ticket_total,
           (SELECT COUNT(*) FROM ticket_assets ta JOIN tickets t ON t.id = ta.ticket_id
             WHERE ta.asset_id = a.id AND t.created_at >= ?) AS ticket_90
    FROM assets a LEFT JOIN users u ON u.id = a.assigned_to
    " . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . "
    ORDER BY a.id DESC LIMIT 500";
$st = $pdo->prepare($sql);
$st->execute(array_merge([$since], $args));
$assets = $st->fetchAll(PDO::FETCH_ASSOC);
if ($warn) {
    $assets = array_values(array_filter($assets, fn($a) => in_array(warrantyState($a['warranty_end'])['state'], ['soon', 'expired'], true)));
}

// summary tiles (whole inventory, not just the filtered list)
$all = $pdo->query("SELECT status, warranty_end FROM assets")->fetchAll(PDO::FETCH_ASSOC);
$tiles = ['total' => count($all), 'in_use' => 0, 'in_repair' => 0, 'warranty' => 0];
foreach ($all as $r) {
    if ($r['status'] === 'in_use')    $tiles['in_use']++;
    if ($r['status'] === 'in_repair') $tiles['in_repair']++;
    if ($r['status'] !== 'retired' && in_array(warrantyState($r['warranty_end'])['state'], ['soon', 'expired'], true)) $tiles['warranty']++;
}
$problem = $pdo->prepare("
    SELECT COUNT(*) FROM (SELECT ta.asset_id FROM ticket_assets ta JOIN tickets t ON t.id = ta.ticket_id
    WHERE t.created_at >= ? GROUP BY ta.asset_id HAVING COUNT(*) >= 3) x");
$problem->execute([$since]);
$tiles['problem'] = (int)$problem->fetchColumn();

$users = $pdo->query("SELECT id, fullname FROM users WHERE status='active' ORDER BY fullname")->fetchAll(PDO::FETCH_ASSOC);

include("../includes/header.php");
include("../includes/navbar.php");
include("../includes/sidebar.php");
?>

<div class="content">

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h2><i class="bi bi-laptop"></i> Asset Tracking</h2>
    <div>
        <a href="asset-export.php" class="btn btn-outline-success"><i class="bi bi-filetype-csv"></i> Export CSV</a>
        <a href="asset-edit.php" class="btn btn-primary"><i class="bi bi-plus-circle"></i> Add Asset</a>
    </div>
</div>

<?php if (isset($_GET['saved'])):   ?><div class="alert alert-success">Asset saved.</div><?php endif; ?>
<?php if (isset($_GET['deleted'])): ?><div class="alert alert-danger">Asset deleted.</div><?php endif; ?>

<div class="row g-3 mb-4">
<?php foreach ([
    ['Total assets', $tiles['total'],   'primary',   ''],
    ['In use',       $tiles['in_use'],  'success',   '?status=in_use'],
    ['In repair',    $tiles['in_repair'],'warning',  '?status=in_repair'],
    ['Warranty ending / expired', $tiles['warranty'], 'danger', '?warranty=soon'],
    ['Problem devices (3+ tickets in 90 days)', $tiles['problem'], 'danger', ''],
] as [$label, $val, $color, $href]): ?>
    <div class="col-6 col-md-4 col-xl">
        <div class="card shadow border-0 text-center h-100"><div class="card-body">
            <div class="display-6 text-<?= $color ?>"><?= (int)$val ?></div>
            <div class="text-muted small"><?= $href ? '<a class="text-muted" href="assets.php' . $href . '">' . e($label) . '</a>' : e($label) ?></div>
        </div></div>
    </div>
<?php endforeach; ?>
</div>

<div class="card shadow mb-3"><div class="card-body">
<form method="get" class="row g-2 align-items-end">
    <div class="col-md-3"><label class="form-label">Search</label>
        <input type="text" name="q" value="<?= e($q) ?>" class="form-control" placeholder="Name, tag, serial, brand"></div>
    <div class="col-md-2"><label class="form-label">Type</label>
        <select name="type" class="form-select"><option value="">All</option>
        <?php foreach (ASSET_TYPES as $t): ?><option <?= $type === $t ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><label class="form-label">Status</label>
        <select name="status" class="form-select"><option value="">All</option>
        <?php foreach (ASSET_STATUSES as $k => $s): ?><option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= e($s['label']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3"><label class="form-label">Held by</label>
        <select name="holder" class="form-select"><option value="">Anyone</option>
        <?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>" <?= $holder === (int)$u['id'] ? 'selected' : '' ?>><?= e($u['fullname']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><button class="btn btn-dark">Filter</button> <a href="assets.php" class="btn btn-secondary">Reset</a></div>
    <?php if ($warn): ?><input type="hidden" name="warranty" value="soon"><?php endif; ?>
</form>
</div></div>

<div class="card shadow"><div class="card-body">
<div class="table-responsive">
<table class="table table-bordered table-hover align-middle">
<thead class="table-dark"><tr>
    <th width="100">Tag</th><th>Asset</th><th>Held by</th><th>Status</th><th>Warranty ends</th><th width="110">Tickets<br><small>90 days / all</small></th><th width="80">Action</th>
</tr></thead>
<tbody>
<?php if ($assets): foreach ($assets as $a): ?>
<tr>
    <td><strong><?= e($a['asset_tag']) ?></strong></td>
    <td><?= e($a['name']) ?>
        <?php if ((int)$a['ticket_90'] >= 3): ?><span class="badge bg-danger ms-1" title="3 or more tickets in the last 90 days">Problem device</span><?php endif; ?>
        <br><small class="text-muted"><?= e($a['asset_type']) ?><?= $a['serial_number'] ? ' · S/N ' . e($a['serial_number']) : '' ?></small></td>
    <td><?= $a['holder_name'] ? e($a['holder_name']) : '<span class="text-muted">-</span>' ?></td>
    <td><?= assetStatusBadge($a['status']) ?></td>
    <td><?= warrantyBadge($a['warranty_end']) ?></td>
    <td class="text-center"><?= (int)$a['ticket_90'] ?> / <?= (int)$a['ticket_total'] ?></td>
    <td><a href="asset-view.php?id=<?= (int)$a['id'] ?>" class="btn btn-primary btn-sm">View</a></td>
</tr>
<?php endforeach; else: ?>
<tr><td colspan="7" class="text-center">No assets found</td></tr>
<?php endif; ?>
</tbody></table>
</div>
</div></div>

</div>

<?php include("../includes/footer.php"); ?>
