<?php
require_once("../config/auth.php");
require_once("../config/database.php");
require_once("../includes/ticket_functions.php");
ensureTicketSchema($pdo);

$message = "";
$old = ['subject' => '', 'description' => '', 'category' => 'General', 'priority' => 'medium', 'department_id' => '', 'asset_id' => ''];

if (isset($_POST['save'])) {

    csrfCheck();

    $old = [
        'subject'       => trim($_POST['subject'] ?? ''),
        'description'   => trim($_POST['description'] ?? ''),
        'category'      => in_array($_POST['category'] ?? '', TICKET_CATEGORIES, true) ? $_POST['category'] : 'General',
        'priority'      => $_POST['priority'] ?? 'medium',
        'department_id' => (int)($_POST['department_id'] ?? 0),
        'asset_id'      => (int)($_POST['asset_id'] ?? 0),
    ];

    if ($old['subject'] === '' || $old['description'] === '') {
        $message = "<div class='alert alert-danger'>Please fill all required fields.</div>";
    } elseif (mb_strlen($old['subject']) > 255) {
        $message = "<div class='alert alert-danger'>Subject is too long (max 255 characters).</div>";
    } else {
        $id = createTicket($pdo, $old, (int)$currentUserId);
        header("Location: view-ticket.php?id=$id&created=1");
        exit;
    }
}

$myAssets = selectableAssets($pdo, (int)$currentUserId, isITStaff($pdo));
$departments = $pdo->query("SELECT id, department_name FROM departments WHERE status = 'active' ORDER BY department_name")
                   ->fetchAll(PDO::FETCH_ASSOC);

include("../includes/header.php");
include("../includes/navbar.php");
include("../includes/sidebar.php");
?>

<div class="content">

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>New Ticket</h2>
    <a href="tickets.php" class="btn btn-secondary">← Back</a>
</div>

<?= $message ?>

<div class="card shadow">
<div class="card-body">

<form method="post">
<?= csrfField() ?>

<div class="mb-3">
    <label class="form-label">Subject *</label>
    <input type="text" name="subject" class="form-control" maxlength="255" value="<?= e($old['subject']) ?>" required>
</div>

<div class="row">
    <div class="col-md-4 mb-3">
        <label class="form-label">Category</label>
        <select name="category" class="form-select">
            <?php foreach (TICKET_CATEGORIES as $c): ?>
            <option <?= $old['category'] === $c ? 'selected' : '' ?>><?= e($c) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-4 mb-3">
        <label class="form-label">Priority</label>
        <select name="priority" class="form-select">
            <?php foreach (TICKET_PRIORITIES as $k => $p): ?>
            <option value="<?= $k ?>" <?= $old['priority'] === $k ? 'selected' : '' ?>>
                <?= e($p['label']) ?> (reply within <?= $p['sla_hours'] ?>h)
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-4 mb-3">
        <label class="form-label">Department</label>
        <select name="department_id" class="form-select">
            <option value="">-- None --</option>
            <?php foreach ($departments as $d): ?>
            <option value="<?= (int)$d['id'] ?>" <?= (int)$old['department_id'] === (int)$d['id'] ? 'selected' : '' ?>>
                <?= e($d['department_name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
</div>

<?php if ($myAssets): ?>
<div class="mb-3">
    <label class="form-label">Affected device (optional)</label>
    <select name="asset_id" class="form-select">
        <option value="">— not about a specific device —</option>
        <?php foreach ($myAssets as $d): ?>
        <option value="<?= (int)$d['id'] ?>" <?= (int)$old['asset_id'] === (int)$d['id'] ? 'selected' : '' ?>>
            <?= e(assetLabel($d)) ?><?= $d['holder'] ? ' (' . e($d['holder']) . ')' : '' ?>
        </option>
        <?php endforeach; ?>
    </select>
</div>
<?php endif; ?>

<div class="mb-3">
    <label class="form-label">Describe the problem *</label>
    <textarea name="description" class="form-control" rows="7" required><?= e($old['description']) ?></textarea>
</div>

<button type="submit" name="save" class="btn btn-primary">Submit Ticket</button>
<a href="tickets.php" class="btn btn-secondary">Cancel</a>

</form>
</div>
</div>

</div>

<?php include("../includes/footer.php"); ?>
