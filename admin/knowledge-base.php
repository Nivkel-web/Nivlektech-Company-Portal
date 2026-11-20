<?php
require_once("../config/auth.php");
require_once("../config/database.php");
require_once("../includes/kb_functions.php");
ensureTicketSchema($pdo);
ensureKbSchema($pdo);
requireITStaff($pdo);

$q   = trim($_GET['q'] ?? '');
$cat = $_GET['category'] ?? '';

$where = []; $args = [];
if ($q !== '') {
    $where[] = '(title LIKE ? OR tags LIKE ? OR problem LIKE ? OR solution LIKE ?)';
    $like = '%' . $q . '%';
    array_push($args, $like, $like, $like, $like);
}
if (in_array($cat, TICKET_CATEGORIES, true)) {
    $where[] = 'category = ?'; $args[] = $cat;
}
$sql = "SELECT a.*, u.fullname FROM kb_articles a LEFT JOIN users u ON u.id = a.created_by"
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
     . " ORDER BY (a.helpful - a.not_helpful) DESC, a.id DESC LIMIT 200";
$st = $pdo->prepare($sql);
$st->execute($args);
$articles = $st->fetchAll(PDO::FETCH_ASSOC);

include("../includes/header.php");
include("../includes/navbar.php");
include("../includes/sidebar.php");
?>

<div class="content">

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-book"></i> IT Knowledge Base</h2>
    <a href="kb-edit.php" class="btn btn-primary"><i class="bi bi-plus-circle"></i> New Article</a>
</div>

<?php if (isset($_GET['saved'])):   ?><div class="alert alert-success">Article saved.</div><?php endif; ?>
<?php if (isset($_GET['deleted'])): ?><div class="alert alert-danger">Article deleted.</div><?php endif; ?>

<div class="card shadow mb-3"><div class="card-body">
<form method="get" class="row g-2 align-items-end">
    <div class="col-md-6"><label class="form-label">Search</label>
        <input type="text" name="q" value="<?= e($q) ?>" class="form-control" placeholder="e.g. printer, VPN, password reset"></div>
    <div class="col-md-3"><label class="form-label">Category</label>
        <select name="category" class="form-select"><option value="">All</option>
        <?php foreach (TICKET_CATEGORIES as $c): ?><option <?= $cat === $c ? 'selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?>
        </select></div>
    <div class="col-md-3"><button class="btn btn-dark">Search</button> <a href="knowledge-base.php" class="btn btn-secondary">Reset</a></div>
</form>
</div></div>

<div class="card shadow"><div class="card-body">
<table class="table table-bordered table-hover align-middle">
<thead class="table-dark"><tr><th>Article</th><th width="150">Category</th><th width="110">Helpful</th><th width="80">Views</th><th width="150">Updated</th></tr></thead>
<tbody>
<?php if ($articles): foreach ($articles as $a): ?>
<tr>
    <td>
        <a href="kb-article.php?id=<?= (int)$a['id'] ?>"><strong><?= e($a['title']) ?></strong></a>
        <?php if ($a['tags']): ?><br><small class="text-muted"><?= e($a['tags']) ?></small><?php endif; ?>
    </td>
    <td><?= e($a['category']) ?></td>
    <td><span class="text-success"><?= (int)$a['helpful'] ?> 👍</span> <span class="text-danger"><?= (int)$a['not_helpful'] ?> 👎</span></td>
    <td><?= (int)$a['views'] ?></td>
    <td><small><?= e(timeAgo($a['updated_at'])) ?></small></td>
</tr>
<?php endforeach; else: ?>
<tr><td colspan="5" class="text-center">No articles yet. Resolve a ticket and save the fix as an article, or click New Article.</td></tr>
<?php endif; ?>
</tbody></table>
</div></div>

</div>

<?php include("../includes/footer.php"); ?>
