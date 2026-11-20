<?php
require_once("../config/auth.php");
requireRole("admin");
require_once("../config/database.php");
require_once("../includes/ticket_functions.php");
ensureTicketSchema($pdo);

$users = activeUsers($pdo);

include("../includes/header.php");
include("../includes/navbar.php");
include("../includes/sidebar.php");
?>

<link rel="stylesheet" href="../assets/css/tickets.css">

<div class="content">

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h2><i class="bi bi-broadcast text-danger"></i> Live Management</h2>
    <div class="d-flex align-items-center gap-3">
        <small class="text-muted">Last update: <span id="lastUpdate">--</span></small>
        <div class="form-check form-switch mb-0">
            <input class="form-check-input" type="checkbox" id="liveToggle" checked>
            <label class="form-check-label" for="liveToggle">Live</label>
        </div>
        <div class="form-check form-switch mb-0">
            <input class="form-check-input" type="checkbox" id="soundToggle">
            <label class="form-check-label" for="soundToggle">Sound on new ticket</label>
        </div>
        <a href="add-ticket.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle"></i> New Ticket</a>
    </div>
</div>

<div id="boardError" class="alert alert-danger d-none"></div>

<!-- Live counters -->
<div class="row g-3 mb-4" id="counters">
    <?php
    $tiles = [
        ['open',       'Open',        'primary'],
        ['in_progress','In Progress', 'info'],
        ['on_hold',    'On Hold',     'warning'],
        ['unassigned', 'Unassigned',  'secondary'],
        ['overdue',    'Overdue',     'danger'],
        ['urgent',     'Urgent',      'danger'],
    ];
    foreach ($tiles as [$key, $label, $color]): ?>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card shadow border-0 text-center live-tile">
            <div class="card-body py-3">
                <div class="text-muted small"><?= $label ?></div>
                <div class="display-6 text-<?= $color ?>" data-count="<?= $key ?>">0</div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="row g-4">

    <!-- Board -->
    <div class="col-xl-9">
        <div class="row g-3" id="board">
            <?php foreach (['open' => 'Open', 'in_progress' => 'In Progress', 'on_hold' => 'On Hold', 'resolved' => 'Resolved (24h)'] as $k => $label): ?>
            <div class="col-md-6 col-xxl-3">
                <div class="board-col">
                    <div class="board-col-head board-<?= $k ?>"><?= $label ?> <span class="badge bg-light text-dark" data-colcount="<?= $k ?>">0</span></div>
                    <div class="board-col-body" data-col="<?= $k ?>"></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Side panels -->
    <div class="col-xl-3">
        <div class="card shadow mb-3">
            <div class="card-header bg-success text-white"><i class="bi bi-circle-fill small"></i> Online now</div>
            <ul class="list-group list-group-flush" id="onlineList"><li class="list-group-item text-muted">Loading...</li></ul>
        </div>
        <div class="card shadow">
            <div class="card-header bg-dark text-white">Workload (active tickets)</div>
            <ul class="list-group list-group-flush" id="loadList"><li class="list-group-item text-muted">Loading...</li></ul>
        </div>
    </div>

</div>
</div>

<script>
window.LIVE_BOARD = {
    csrf: <?= json_encode(csrfToken()) ?>,
    users: <?= json_encode(array_map(fn($u) => ['id' => (int)$u['id'], 'name' => $u['fullname']], $users)) ?>,
    statuses: <?= json_encode(array_map(fn($s) => $s['label'], TICKET_STATUSES)) ?>
};
</script>
<script src="../assets/js/ticket-live.js"></script>

<?php include("../includes/footer.php"); ?>
