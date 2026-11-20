<?php
require_once("../config/auth.php");
require_once("../config/database.php");
require_once("../includes/kb_functions.php");
ensureTicketSchema($pdo);
ensureKbSchema($pdo);
requireITStaff($pdo);

$uid = (int)$currentUserId;
$id  = (int)($_GET['id'] ?? 0);

$load = function () use ($pdo, $id) {
    $st = $pdo->prepare("SELECT a.*, u.fullname FROM kb_articles a LEFT JOIN users u ON u.id = a.created_by WHERE a.id = ?");
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC);
};
$a = $load();
if (!$a) { http_response_code(404); exit('Article not found.'); }

$canEdit = ($currentUserRole === 'admin') || (int)$a['created_by'] === $uid;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $action = $_POST['action'] ?? '';

    if ($action === 'vote') {
        $v = ($_POST['vote'] ?? '') === 'up' ? 1 : -1;
        $old = $pdo->prepare("SELECT vote FROM kb_votes WHERE article_id = ? AND user_id = ?");
        $old->execute([$id, $uid]);
        $prev = $old->fetchColumn();
        if ($prev === false) {
            $pdo->prepare("INSERT INTO kb_votes (article_id, user_id, vote) VALUES (?,?,?)")->execute([$id, $uid, $v]);
        } elseif ((int)$prev !== $v) {
            $pdo->prepare("UPDATE kb_votes SET vote = ? WHERE article_id = ? AND user_id = ?")->execute([$v, $id, $uid]);
        }
        // recount from the votes table so numbers can never drift
        $pdo->prepare("UPDATE kb_articles SET
            helpful = (SELECT COUNT(*) FROM kb_votes WHERE article_id = ? AND vote = 1),
            not_helpful = (SELECT COUNT(*) FROM kb_votes WHERE article_id = ? AND vote = -1)
            WHERE id = ?")->execute([$id, $id, $id]);
        header("Location: kb-article.php?id=$id&voted=1");
        exit;
    }
    if ($action === 'delete' && $canEdit) {
        $pdo->prepare("DELETE FROM kb_votes WHERE article_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM kb_articles WHERE id = ?")->execute([$id]);
        logActivity($pdo, 'Deleted KB article: ' . $a['title']);
        header("Location: knowledge-base.php?deleted=1");
        exit;
    }
}

// count a view only on a normal page load
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_GET['saved']) && !isset($_GET['voted'])) {
    $pdo->prepare("UPDATE kb_articles SET views = views + 1 WHERE id = ?")->execute([$id]);
    $a['views']++;
}
$my = $pdo->prepare("SELECT vote FROM kb_votes WHERE article_id = ? AND user_id = ?");
$my->execute([$id, $uid]);
$myVote = $my->fetchColumn();

include("../includes/header.php");
include("../includes/navbar.php");
include("../includes/sidebar.php");
?>

<div class="content">

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2><?= e($a['title']) ?></h2>
    <div>
        <?php if ($canEdit): ?><a href="kb-edit.php?id=<?= $id ?>" class="btn btn-warning">Edit</a><?php endif; ?>
        <a href="knowledge-base.php" class="btn btn-secondary">← Back</a>
    </div>
</div>

<?php if (isset($_GET['saved'])): ?><div class="alert alert-success">Article saved.</div><?php endif; ?>
<?php if (isset($_GET['voted'])): ?><div class="alert alert-success">Thanks for the feedback.</div><?php endif; ?>

<p class="text-muted">
    <span class="badge bg-primary"><?= e($a['category']) ?></span>
    <?php if ($a['tags']): ?> · <?= e($a['tags']) ?><?php endif; ?>
    · by <?= e($a['fullname'] ?: 'Unknown') ?> · updated <?= e(timeAgo($a['updated_at'])) ?> · <?= (int)$a['views'] ?> views
    <?php if ($a['source_ticket_id']): ?> · from ticket <a href="view-ticket.php?id=<?= (int)$a['source_ticket_id'] ?>"><?= e(ticketNumber((int)$a['source_ticket_id'])) ?></a><?php endif; ?>
</p>

<?php if (trim((string)$a['problem']) !== ''): ?>
<div class="card shadow mb-3"><div class="card-header bg-dark text-white">Problem / symptoms</div>
<div class="card-body"><?= nl2br(e($a['problem'])) ?></div></div>
<?php endif; ?>

<div class="card shadow mb-3"><div class="card-header bg-success text-white">Solution</div>
<div class="card-body" style="font-size:15px"><?= nl2br(e($a['solution'])) ?></div></div>

<div class="card shadow"><div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-2">
    <form method="post" class="d-flex align-items-center gap-2">
        <?= csrfField() ?><input type="hidden" name="action" value="vote">
        <span>Did this fix help?</span>
        <button name="vote" value="up"   class="btn btn-sm <?= (int)$myVote === 1  ? 'btn-success' : 'btn-outline-secondary' ?>">👍 Helped (<?= (int)$a['helpful'] ?>)</button>
        <button name="vote" value="down" class="btn btn-sm <?= (int)$myVote === -1 ? 'btn-danger'  : 'btn-outline-secondary' ?>">👎 Didn't help (<?= (int)$a['not_helpful'] ?>)</button>
    </form>
    <?php if ($canEdit): ?>
    <form method="post" onsubmit="return confirm('Delete this article?');">
        <?= csrfField() ?><input type="hidden" name="action" value="delete">
        <button class="btn btn-sm btn-outline-danger">Delete</button>
    </form>
    <?php endif; ?>
</div></div>

</div>

<?php include("../includes/footer.php"); ?>
