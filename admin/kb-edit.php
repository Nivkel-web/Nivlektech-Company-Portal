<?php
require_once("../config/auth.php");
require_once("../config/database.php");
require_once("../includes/kb_functions.php");
ensureTicketSchema($pdo);
ensureKbSchema($pdo);
requireITStaff($pdo);

$uid = (int)$currentUserId;
$id  = (int)($_GET['id'] ?? 0);
$art = ['title' => '', 'category' => 'General', 'tags' => '', 'problem' => '', 'solution' => '', 'source_ticket_id' => null, 'created_by' => $uid];

if ($id) {
    $st = $pdo->prepare("SELECT * FROM kb_articles WHERE id = ?");
    $st->execute([$id]);
    $found = $st->fetch(PDO::FETCH_ASSOC);
    if (!$found) { http_response_code(404); exit('Article not found.'); }
    $art = $found;
} elseif (!empty($_GET['from_ticket'])) {
    // prefill from a resolved ticket
    $t = getTicket($pdo, (int)$_GET['from_ticket']);
    if ($t) {
        $art['title']    = $t['subject'];
        $art['category'] = $t['category'];
        $art['problem']  = $t['description'];
        $art['solution'] = latestResolutionNote($pdo, (int)$t['id']);
        $art['source_ticket_id'] = (int)$t['id'];
    }
}

$message = '';
if (isset($_POST['save'])) {
    csrfCheck();
    $art['title']    = trim($_POST['title'] ?? '');
    $art['category'] = in_array($_POST['category'] ?? '', TICKET_CATEGORIES, true) ? $_POST['category'] : 'General';
    $art['tags']     = trim($_POST['tags'] ?? '');
    $art['problem']  = trim($_POST['problem'] ?? '');
    $art['solution'] = trim($_POST['solution'] ?? '');

    if ($art['title'] === '' || $art['solution'] === '') {
        $message = "<div class='alert alert-danger'>Title and solution are required.</div>";
    } else {
        $now = date('Y-m-d H:i:s');
        if ($id) {
            $pdo->prepare("UPDATE kb_articles SET title=?, category=?, tags=?, problem=?, solution=?, updated_at=? WHERE id=?")
                ->execute([$art['title'], $art['category'], $art['tags'], $art['problem'], $art['solution'], $now, $id]);
            logActivity($pdo, 'Updated KB article: ' . $art['title']);
        } else {
            $pdo->prepare("INSERT INTO kb_articles (title, category, tags, problem, solution, source_ticket_id, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([$art['title'], $art['category'], $art['tags'], $art['problem'], $art['solution'], $art['source_ticket_id'] ?: null, $uid, $now, $now]);
            $id = (int)$pdo->lastInsertId();
            logActivity($pdo, 'Created KB article: ' . $art['title']);
        }
        header("Location: kb-article.php?id=$id&saved=1");
        exit;
    }
}

include("../includes/header.php");
include("../includes/navbar.php");
include("../includes/sidebar.php");
?>

<div class="content">
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><?= $id ? 'Edit Article' : 'New Article' ?></h2>
    <a href="knowledge-base.php" class="btn btn-secondary">← Back</a>
</div>

<?= $message ?>
<?php if (!$id && $art['source_ticket_id']): ?>
<div class="alert alert-info">Pre-filled from ticket <?= e(ticketNumber((int)$art['source_ticket_id'])) ?>. Tidy it up so the next person can follow it.</div>
<?php endif; ?>

<div class="card shadow"><div class="card-body">
<form method="post">
<?= csrfField() ?>
<div class="mb-3"><label class="form-label">Title *</label>
    <input type="text" name="title" class="form-control" maxlength="255" value="<?= e($art['title']) ?>" required></div>
<div class="row">
    <div class="col-md-4 mb-3"><label class="form-label">Category</label>
        <select name="category" class="form-select">
        <?php foreach (TICKET_CATEGORIES as $c): ?><option <?= $art['category'] === $c ? 'selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?>
        </select></div>
    <div class="col-md-8 mb-3"><label class="form-label">Tags (comma separated — used to match tickets)</label>
        <input type="text" name="tags" class="form-control" maxlength="255" value="<?= e($art['tags']) ?>" placeholder="wifi, network, no internet"></div>
</div>
<div class="mb-3"><label class="form-label">Problem / symptoms</label>
    <textarea name="problem" class="form-control" rows="4"><?= e($art['problem']) ?></textarea></div>
<div class="mb-3"><label class="form-label">Solution — step by step *</label>
    <textarea name="solution" class="form-control" rows="9" required><?= e($art['solution']) ?></textarea></div>
<button name="save" value="1" class="btn btn-primary">Save Article</button>
<a href="knowledge-base.php" class="btn btn-secondary">Cancel</a>
</form>
</div></div>
</div>

<?php include("../includes/footer.php"); ?>
