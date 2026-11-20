<?php
require_once("../config/auth.php");
require_once("../config/database.php");
require_once("../includes/ticket_functions.php");
ensureTicketSchema($pdo);
ensureAssetSchema($pdo);
requireITStaff($pdo);

$uid = (int)$currentUserId;
$id  = (int)($_GET['id'] ?? 0);
$a = ['name' => '', 'asset_type' => 'Laptop', 'brand' => '', 'model' => '', 'serial_number' => '', 'purchase_date' => '', 'warranty_end' => '',
      'status' => 'in_use', 'assigned_to' => '', 'department_id' => '', 'location' => '', 'notes' => ''];

if ($id) {
    $st = $pdo->prepare("SELECT * FROM assets WHERE id = ?");
    $st->execute([$id]);
    $found = $st->fetch(PDO::FETCH_ASSOC);
    if (!$found) { http_response_code(404); exit('Asset not found.'); }
    $a = $found;
}

$message = '';
if (isset($_POST['save'])) {
    csrfCheck();
    $date = fn($v) => (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v) && strtotime($v) !== false) ? $v : null;

    $a = [
        'name'          => trim($_POST['name'] ?? ''),
        'asset_type'    => in_array($_POST['asset_type'] ?? '', ASSET_TYPES, true) ? $_POST['asset_type'] : 'Other',
        'brand'         => trim($_POST['brand'] ?? ''),
        'model'         => trim($_POST['model'] ?? ''),
        'serial_number' => trim($_POST['serial_number'] ?? ''),
        'purchase_date' => $date($_POST['purchase_date'] ?? ''),
        'warranty_end'  => $date($_POST['warranty_end'] ?? ''),
        'status'        => isset(ASSET_STATUSES[$_POST['status'] ?? '']) ? $_POST['status'] : 'in_use',
        'assigned_to'   => (int)($_POST['assigned_to'] ?? 0) ?: null,
        'department_id' => (int)($_POST['department_id'] ?? 0) ?: null,
        'location'      => trim($_POST['location'] ?? ''),
        'notes'         => trim($_POST['notes'] ?? ''),
    ];

    if ($a['name'] === '') {
        $message = "<div class='alert alert-danger'>Asset name is required.</div>";
    } else {
        $now = date('Y-m-d H:i:s');
        if ($id) {
            $pdo->prepare("UPDATE assets SET name=?, asset_type=?, brand=?, model=?, serial_number=?, purchase_date=?, warranty_end=?,
                           status=?, assigned_to=?, department_id=?, location=?, notes=?, updated_at=? WHERE id=?")
                ->execute([$a['name'], $a['asset_type'], $a['brand'], $a['model'], $a['serial_number'], $a['purchase_date'], $a['warranty_end'],
                           $a['status'], $a['assigned_to'], $a['department_id'], $a['location'], $a['notes'], $now, $id]);
            logActivity($pdo, 'Updated asset: ' . $a['name']);
        } else {
            $pdo->prepare("INSERT INTO assets (name, asset_type, brand, model, serial_number, purchase_date, warranty_end, status,
                           assigned_to, department_id, location, notes, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$a['name'], $a['asset_type'], $a['brand'], $a['model'], $a['serial_number'], $a['purchase_date'], $a['warranty_end'], $a['status'],
                           $a['assigned_to'], $a['department_id'], $a['location'], $a['notes'], $uid, $now, $now]);
            $id = (int)$pdo->lastInsertId();
            $pdo->prepare("UPDATE assets SET asset_tag = ? WHERE id = ?")->execute(['AST-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT), $id]);
            logActivity($pdo, 'Added asset: ' . $a['name']);
        }
        header("Location: asset-view.php?id=$id&saved=1");
        exit;
    }
}

$users = $pdo->query("SELECT id, fullname FROM users WHERE status='active' ORDER BY fullname")->fetchAll(PDO::FETCH_ASSOC);
$depts = $pdo->query("SELECT id, department_name FROM departments WHERE status='active' ORDER BY department_name")->fetchAll(PDO::FETCH_ASSOC);

include("../includes/header.php");
include("../includes/navbar.php");
include("../includes/sidebar.php");
?>

<div class="content">
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><?= $id ? 'Edit Asset' : 'Add Asset' ?></h2>
    <a href="assets.php" class="btn btn-secondary">← Back</a>
</div>
<?= $message ?>

<div class="card shadow"><div class="card-body">
<form method="post">
<?= csrfField() ?>
<div class="row">
    <div class="col-md-6 mb-3"><label class="form-label">Name *</label>
        <input type="text" name="name" class="form-control" maxlength="150" value="<?= e($a['name']) ?>" placeholder="e.g. Dell Latitude 5420 — Accounts" required></div>
    <div class="col-md-3 mb-3"><label class="form-label">Type</label>
        <select name="asset_type" class="form-select"><?php foreach (ASSET_TYPES as $t): ?><option <?= $a['asset_type'] === $t ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3 mb-3"><label class="form-label">Status</label>
        <select name="status" class="form-select"><?php foreach (ASSET_STATUSES as $k => $s): ?><option value="<?= $k ?>" <?= $a['status'] === $k ? 'selected' : '' ?>><?= e($s['label']) ?></option><?php endforeach; ?></select></div>
</div>
<div class="row">
    <div class="col-md-3 mb-3"><label class="form-label">Brand</label><input type="text" name="brand" class="form-control" value="<?= e($a['brand']) ?>"></div>
    <div class="col-md-3 mb-3"><label class="form-label">Model</label><input type="text" name="model" class="form-control" value="<?= e($a['model']) ?>"></div>
    <div class="col-md-6 mb-3"><label class="form-label">Serial number</label><input type="text" name="serial_number" class="form-control" value="<?= e($a['serial_number']) ?>"></div>
</div>
<div class="row">
    <div class="col-md-3 mb-3"><label class="form-label">Purchase date</label><input type="date" name="purchase_date" class="form-control" value="<?= e($a['purchase_date']) ?>"></div>
    <div class="col-md-3 mb-3"><label class="form-label">Warranty ends</label><input type="date" name="warranty_end" class="form-control" value="<?= e($a['warranty_end']) ?>"></div>
    <div class="col-md-3 mb-3"><label class="form-label">Held by</label>
        <select name="assigned_to" class="form-select"><option value="">— nobody —</option>
        <?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>" <?= (int)$a['assigned_to'] === (int)$u['id'] ? 'selected' : '' ?>><?= e($u['fullname']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3 mb-3"><label class="form-label">Department</label>
        <select name="department_id" class="form-select"><option value="">— none —</option>
        <?php foreach ($depts as $d): ?><option value="<?= (int)$d['id'] ?>" <?= (int)$a['department_id'] === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['department_name']) ?></option><?php endforeach; ?></select></div>
</div>
<div class="mb-3"><label class="form-label">Location</label><input type="text" name="location" class="form-control" value="<?= e($a['location']) ?>" placeholder="e.g. 2nd floor, Accounts office"></div>
<div class="mb-3"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="3"><?= e($a['notes']) ?></textarea></div>
<button name="save" value="1" class="btn btn-primary">Save Asset</button>
<a href="assets.php" class="btn btn-secondary">Cancel</a>
</form>
</div></div>
</div>

<?php include("../includes/footer.php"); ?>
