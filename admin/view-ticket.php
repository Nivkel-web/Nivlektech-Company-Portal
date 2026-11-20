<?php
require_once("../config/auth.php");
require_once("../config/database.php");
require_once("../includes/kb_functions.php");
ensureTicketSchema($pdo);

$uid = (int)$currentUserId;
$id  = (int)($_GET['id'] ?? 0);

$ticket = $id ? getTicket($pdo, $id) : null;

if (!$ticket || !canViewTicket($ticket)) {
    http_response_code(404);
    include("../includes/header.php");
    include("../includes/navbar.php");
    include("../includes/sidebar.php");
    echo '<div class="content"><div class="alert alert-danger">Ticket not found.</div><a href="tickets.php" class="btn btn-secondary">← Back</a></div>';
    include("../includes/footer.php");
    exit;
}

$staff   = isStaffFor($ticket);
$isAdmin = ($currentUserRole === 'admin');
$flash   = '';

/*
|--------------------------------------------------------------------------
| Actions
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    csrfCheck();
    $action = $_POST['action'] ?? '';

    if ($action === 'reply') {
        $msg = trim($_POST['message'] ?? '');
        if ($msg === '') {
            $flash = '<div class="alert alert-danger">Reply cannot be empty.</div>';
        } else {
            replyToTicket($pdo, $ticket, $uid, $msg, !empty($_POST['internal']));
            header("Location: view-ticket.php?id=$id#bottom");
            exit;
        }
    }

    if ($action === 'status' && $staff) {
        $newStatus = $_POST['status'] ?? '';
        $note = trim($_POST['resolution_note'] ?? '');
        if ($note !== '' && isTicketClosed($newStatus)) {
            addTicketReply($pdo, $id, $uid, 'Resolution: ' . $note, true);   // internal, IT only
        }
        changeTicketStatus($pdo, $ticket, $newStatus, $uid);
        header("Location: view-ticket.php?id=$id&updated=1");
        exit;
    }

    // requester can close their own ticket
    if ($action === 'close_own' && (int)$ticket['created_by'] === $uid) {
        changeTicketStatus($pdo, $ticket, 'closed', $uid);
        header("Location: view-ticket.php?id=$id&updated=1");
        exit;
    }

    if ($action === 'auto_assign' && $isAdmin) {
        $res = autoAssignTicket($pdo, $ticket, $uid, true);
        header("Location: view-ticket.php?id=$id&" . ($res ? 'updated=1' : 'noauto=1'));
        exit;
    }

    if ($action === 'link_asset' && isITStaff($pdo)) {
        linkTicketAsset($pdo, $id, (int)($_POST['asset_id'] ?? 0), $uid);
        logActivity($pdo, ticketNumber($id) . ' device link changed');
        header("Location: view-ticket.php?id=$id&updated=1");
        exit;
    }

    if ($action === 'assign' && $isAdmin) {
        assignTicket($pdo, $ticket, (int)($_POST['assigned_to'] ?? 0) ?: null, $uid);
        header("Location: view-ticket.php?id=$id&updated=1");
        exit;
    }
}

$isIT = isITStaff($pdo);
$device = ticketAsset($pdo, $id);
$allAssets = $isIT ? selectableAssets($pdo, $uid, true) : [];
$kbSuggestions = [];
if ($isIT) {
    ensureKbSchema($pdo);
    $kbSuggestions = kbSuggest($pdo, $ticket, 5);
}
$replies = fetchReplies($pdo, $id, $staff);
$lastId  = $replies ? (int)end($replies)['id'] : 0;
$users   = $isAdmin ? activeUsers($pdo) : [];
$closed  = isTicketClosed($ticket['status']);
$overdue = !$closed && $ticket['due_at'] && strtotime($ticket['due_at']) < time();

include("../includes/header.php");
include("../includes/navbar.php");
include("../includes/sidebar.php");
?>

<link rel="stylesheet" href="../assets/css/tickets.css">

<div class="content">

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2>
        <?= e($ticket['ticket_no']) ?>
        <span id="liveStatus"><?= statusBadge($ticket['status']) ?></span>
        <?= priorityBadge($ticket['priority']) ?>
        <?php if ($overdue): ?><span class="badge bg-danger">Overdue</span><?php endif; ?>
    </h2>
    <a href="tickets.php" class="btn btn-secondary">← Back</a>
</div>

<?php if (isset($_GET['created'])): ?><div class="alert alert-success">Ticket created. We will get back to you soon.</div><?php endif; ?>
<?php if (isset($_GET['updated'])): ?><div class="alert alert-success">Ticket updated.</div><?php endif; ?>
<?php if (isset($_GET['noauto'])): ?><div class="alert alert-warning">Nobody is eligible for auto-assign right now (check <a href="auto-assign.php">Auto-Assign</a> opt-ins, specialties, away and limits).</div><?php endif; ?>
<?= $flash ?>

<div class="row g-4">

<!-- Conversation -->
<div class="col-lg-8">

    <div class="card shadow mb-4">
        <div class="card-header bg-dark text-white"><?= e($ticket['subject']) ?></div>
        <div class="card-body">
            <p class="mb-1 text-muted">
                <small>Opened by <strong><?= e($ticket['creator_name']) ?></strong>
                on <?= e(date('d M Y h:i A', strtotime($ticket['created_at']))) ?></small>
            </p>
            <div class="ticket-description"><?= nl2br(e($ticket['description'])) ?></div>
        </div>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-2">
        <h5 class="mb-0">Conversation</h5>
        <small id="viewers" class="text-muted"></small>
    </div>

    <div id="thread">
        <?php foreach ($replies as $r) echo renderReplyHtml($r, (int)$ticket['created_by']); ?>
        <?php if (!$replies): ?><p class="text-muted" id="noReplies">No replies yet.</p><?php endif; ?>
    </div>
    <div id="bottom"></div>

    <div class="card shadow mt-3">
        <div class="card-body">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="reply">
                <label class="form-label">Add a reply</label>
                <textarea name="message" class="form-control mb-2" rows="4" required></textarea>
                <div class="d-flex justify-content-between align-items-center">
                    <?php if ($staff): ?>
                    <label class="form-check-label">
                        <input type="checkbox" name="internal" value="1" class="form-check-input">
                        Internal note (hidden from requester)
                    </label>
                    <?php else: ?><span></span><?php endif; ?>
                    <button class="btn btn-primary"><i class="bi bi-send"></i> Send</button>
                </div>
            </form>
        </div>
    </div>

</div>

<!-- Sidebar -->
<div class="col-lg-4">

    <div class="card shadow mb-4">
        <div class="card-header bg-primary text-white">Details</div>
        <ul class="list-group list-group-flush">
            <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Requester</span><span><?= e($ticket['creator_name']) ?></span></li>
            <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Assigned to</span><span id="liveAssignee"><?= e($ticket['assignee_name'] ?: 'Unassigned') ?></span></li>
            <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Device</span><span class="text-end"><?php
                if ($device) { echo $isIT ? '<a href="asset-view.php?id=' . (int)$device['id'] . '">' . e(assetLabel($device)) . '</a>' : e(assetLabel($device)); }
                else { echo '-'; } ?></span></li>
            <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Category</span><span><?= e($ticket['category']) ?></span></li>
            <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Department</span><span><?= e($ticket['department_name'] ?: '-') ?></span></li>
            <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Due</span><span class="<?= $overdue ? 'text-danger fw-bold' : '' ?>"><?= $ticket['due_at'] ? e(date('d M h:i A', strtotime($ticket['due_at']))) : '-' ?></span></li>
            <li class="list-group-item d-flex justify-content-between"><span class="text-muted">First response</span><span><?= $ticket['first_response_at'] ? e(timeAgo($ticket['first_response_at'])) : '-' ?></span></li>
            <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Resolved</span><span><?= $ticket['resolved_at'] ? e(date('d M h:i A', strtotime($ticket['resolved_at']))) : '-' ?></span></li>
        </ul>
    </div>

    <?php if ($isIT): ?>
    <div class="card shadow mb-4">
        <div class="card-header bg-info text-white"><i class="bi bi-lightbulb"></i> Suggested fixes (IT only)</div>
        <div class="list-group list-group-flush">
        <?php if ($kbSuggestions): foreach ($kbSuggestions as $k): ?>
            <div class="list-group-item">
                <a href="kb-article.php?id=<?= (int)$k['id'] ?>" target="_blank"><strong><?= e($k['title']) ?></strong></a>
                <div class="small text-muted"><?= e($k['category']) ?> · <?= (int)$k['helpful'] ?> 👍
                    <?php if ($k['hits']): ?> · matched: <?= e(implode(', ', $k['hits'])) ?><?php endif; ?></div>
                <button type="button" class="btn btn-sm btn-outline-primary mt-1 js-insert-kb"
                        data-text="<?= e($k['solution']) ?>">Insert into reply</button>
            </div>
        <?php endforeach; else: ?>
            <div class="list-group-item text-muted">No matching articles yet.</div>
        <?php endif; ?>
            <div class="list-group-item">
                <a href="knowledge-base.php" class="small">Browse all articles</a>
                <?php if ($closed): ?> · <a href="kb-edit.php?from_ticket=<?= (int)$id ?>" class="small fw-bold">Save this fix as an article</a><?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($staff): ?>
    <div class="card shadow mb-4">
        <div class="card-header bg-success text-white">Update Status</div>
        <div class="card-body">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="status">
                <div class="d-flex gap-2 mb-2">
                <select name="status" class="form-select">
                    <?php foreach (TICKET_STATUSES as $k => $s): ?>
                    <option value="<?= $k ?>" <?= $ticket['status'] === $k ? 'selected' : '' ?>><?= e($s['label']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-success">Save</button>
                </div>
                <textarea name="resolution_note" class="form-control" rows="2"
                          placeholder="What fixed it? (optional, shown to IT only — you can turn it into a KB article)"></textarea>
            </form>
        </div>
    </div>
    <?php elseif ((int)$ticket['created_by'] === $uid && $ticket['status'] !== 'closed'): ?>
    <div class="card shadow mb-4">
        <div class="card-body">
            <form method="post" onsubmit="return confirm('Close this ticket?');">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="close_own">
                <button class="btn btn-outline-secondary w-100">My problem is solved — close ticket</button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($isIT): ?>
    <div class="card shadow mb-4">
        <div class="card-header bg-secondary text-white">Affected device</div>
        <div class="card-body">
            <form method="post" class="d-flex gap-2">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="link_asset">
                <select name="asset_id" class="form-select">
                    <option value="">— none —</option>
                    <?php foreach ($allAssets as $d): ?>
                    <option value="<?= (int)$d['id'] ?>" <?= $device && (int)$device['id'] === (int)$d['id'] ? 'selected' : '' ?>><?= e(assetLabel($d)) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-secondary">Save</button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($isAdmin): ?>
    <div class="card shadow mb-4">
        <div class="card-header bg-dark text-white">Assign Ticket</div>
        <div class="card-body">
            <form method="post" class="d-flex gap-2">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="assign">
                <select name="assigned_to" class="form-select">
                    <option value="">Unassigned</option>
                    <?php foreach ($users as $u): ?>
                    <option value="<?= (int)$u['id'] ?>" <?= (int)$ticket['assigned_to'] === (int)$u['id'] ? 'selected' : '' ?>>
                        <?= e($u['fullname']) ?> (<?= e($u['role']) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-dark">Assign</button>
            </form>
            <?php if (!$ticket['assigned_to'] && !$closed): ?>
            <form method="post" class="mt-2">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="auto_assign">
                <button class="btn btn-outline-primary btn-sm w-100"><i class="bi bi-shuffle"></i> Auto-assign (by specialty)</button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

</div>
</div>

</div>

<script>
window.TICKET_LIVE = {
    id: <?= (int)$id ?>,
    lastId: <?= (int)$lastId ?>,
    csrf: <?= json_encode(csrfToken()) ?>
};
</script>
<script>
document.querySelectorAll('.js-insert-kb').forEach(function (b) {
    b.addEventListener('click', function () {
        var ta = document.querySelector('textarea[name=message]');
        ta.value = (ta.value ? ta.value + "\n\n" : '') + b.getAttribute('data-text');
        ta.focus(); ta.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
});
</script>
<script src="../assets/js/ticket-live.js"></script>

<?php include("../includes/footer.php"); ?>
