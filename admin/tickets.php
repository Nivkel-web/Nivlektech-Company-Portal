<?php
require_once("../config/auth.php");
require_once("../config/database.php");
require_once("../includes/ticket_functions.php");
ensureTicketSchema($pdo);

$uid     = (int)$currentUserId;
$isAdmin = ($currentUserRole === 'admin');

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/
$status   = $_GET['status']   ?? '';
$priority = $_GET['priority'] ?? '';
$q        = trim($_GET['q']   ?? '');
$scope    = $_GET['scope']    ?? 'all';      // all | mine | assigned
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = 15;

$where = [];
$args  = [];

if (!$isAdmin) {
    $where[] = '(t.created_by = ? OR t.assigned_to = ?)';
    $args[] = $uid; $args[] = $uid;
}
if ($scope === 'mine')     { $where[] = 't.created_by = ?';  $args[] = $uid; }
if ($scope === 'assigned') { $where[] = 't.assigned_to = ?'; $args[] = $uid; }

if ($status === 'active') {
    $where[] = "t.status NOT IN ('resolved','closed')";
} elseif (isset(TICKET_STATUSES[$status])) {
    $where[] = 't.status = ?'; $args[] = $status;
}
if (isset(TICKET_PRIORITIES[$priority])) {
    $where[] = 't.priority = ?'; $args[] = $priority;
}
if ($q !== '') {
    $where[] = '(t.subject LIKE ? OR t.ticket_no LIKE ? OR c.fullname LIKE ?)';
    $like = '%' . $q . '%';
    array_push($args, $like, $like, $like);
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$cnt = $pdo->prepare("SELECT COUNT(*) FROM tickets t LEFT JOIN users c ON c.id = t.created_by $whereSql");
$cnt->execute($args);
$total = (int)$cnt->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));
$page  = min($page, $pages);
$offset = ($page - 1) * $perPage;

$st = $pdo->prepare("
    SELECT t.*, c.fullname AS creator_name, a.fullname AS assignee_name
    FROM tickets t
    LEFT JOIN users c ON c.id = t.created_by
    LEFT JOIN users a ON a.id = t.assigned_to
    $whereSql
    ORDER BY t.id DESC
    LIMIT $perPage OFFSET $offset
");
$st->execute($args);
$tickets = $st->fetchAll(PDO::FETCH_ASSOC);

function ticketsUrl(array $over = []): string {
    $p = array_merge($_GET, $over);
    return 'tickets.php?' . http_build_query(array_filter($p, fn($v) => $v !== '' && $v !== null));
}

include("../includes/header.php");
include("../includes/navbar.php");
include("../includes/sidebar.php");
?>

<div class="content">

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-ticket-perforated"></i> Ticketing System</h2>
    <div>
        <?php if ($isAdmin): ?>
        <a href="live-management.php" class="btn btn-outline-danger me-2"><i class="bi bi-broadcast"></i> Live Management</a>
        <?php endif; ?>
        <a href="add-ticket.php" class="btn btn-primary"><i class="bi bi-plus-circle"></i> New Ticket</a>
    </div>
</div>

<?php if (isset($_GET['created'])): ?>
<div class="alert alert-success">Ticket created successfully.</div>
<?php endif; ?>

<div class="card shadow mb-3">
<div class="card-body">
<form method="get" class="row g-2 align-items-end">
    <div class="col-md-3">
        <label class="form-label">Search</label>
        <input type="text" name="q" value="<?= e($q) ?>" class="form-control" placeholder="Subject, ticket no, requester">
    </div>
    <div class="col-md-2">
        <label class="form-label">Status</label>
        <select name="status" class="form-select">
            <option value="">All</option>
            <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active (not closed)</option>
            <?php foreach (TICKET_STATUSES as $k => $s): ?>
            <option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= e($s['label']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2">
        <label class="form-label">Priority</label>
        <select name="priority" class="form-select">
            <option value="">All</option>
            <?php foreach (TICKET_PRIORITIES as $k => $p): ?>
            <option value="<?= $k ?>" <?= $priority === $k ? 'selected' : '' ?>><?= e($p['label']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2">
        <label class="form-label">Show</label>
        <select name="scope" class="form-select">
            <option value="all" <?= $scope === 'all' ? 'selected' : '' ?>><?= $isAdmin ? 'All tickets' : 'My tickets' ?></option>
            <option value="mine" <?= $scope === 'mine' ? 'selected' : '' ?>>Created by me</option>
            <option value="assigned" <?= $scope === 'assigned' ? 'selected' : '' ?>>Assigned to me</option>
        </select>
    </div>
    <div class="col-md-3">
        <button class="btn btn-dark"><i class="bi bi-funnel"></i> Filter</button>
        <a href="tickets.php" class="btn btn-secondary">Reset</a>
    </div>
</form>
</div>
</div>

<div class="card shadow">
<div class="card-body">
<div class="table-responsive">
<table class="table table-bordered table-hover align-middle">
<thead class="table-dark">
<tr>
    <th width="100">Ticket</th>
    <th>Subject</th>
    <th>Requester</th>
    <th>Assigned To</th>
    <th>Priority</th>
    <th>Status</th>
    <th width="150">Updated</th>
    <th width="80">Action</th>
</tr>
</thead>
<tbody>
<?php if ($tickets): foreach ($tickets as $t):
    $overdue = !isTicketClosed($t['status']) && $t['due_at'] && strtotime($t['due_at']) < time(); ?>
<tr>
    <td><strong><?= e($t['ticket_no']) ?></strong></td>
    <td>
        <?= e($t['subject']) ?>
        <?php if ($overdue): ?><span class="badge bg-danger ms-1">Overdue</span><?php endif; ?>
        <br><small class="text-muted"><?= e($t['category']) ?></small>
    </td>
    <td><?= e($t['creator_name'] ?? 'Unknown') ?></td>
    <td><?= $t['assignee_name'] ? e($t['assignee_name']) : '<span class="text-muted">Unassigned</span>' ?></td>
    <td><?= priorityBadge($t['priority']) ?></td>
    <td><?= statusBadge($t['status']) ?></td>
    <td><small><?= e(timeAgo($t['updated_at'])) ?></small></td>
    <td><a href="view-ticket.php?id=<?= (int)$t['id'] ?>" class="btn btn-primary btn-sm">View</a></td>
</tr>
<?php endforeach; else: ?>
<tr><td colspan="8" class="text-center">No tickets found</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>

<?php if ($pages > 1): ?>
<nav>
<ul class="pagination justify-content-center mb-0">
    <?php for ($i = 1; $i <= $pages; $i++): ?>
    <li class="page-item <?= $i === $page ? 'active' : '' ?>">
        <a class="page-link" href="<?= e(ticketsUrl(['page' => $i])) ?>"><?= $i ?></a>
    </li>
    <?php endfor; ?>
</ul>
</nav>
<?php endif; ?>

<small class="text-muted"><?= $total ?> ticket(s)</small>
</div>
</div>

</div>

<?php include("../includes/footer.php"); ?>
