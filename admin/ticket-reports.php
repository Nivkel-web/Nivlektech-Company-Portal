<?php
require_once("../config/auth.php");
requireRole("admin");
require_once("../config/database.php");
require_once("../includes/ticket_functions.php");
ensureTicketSchema($pdo);

/*
|--------------------------------------------------------------------------
| Date range (default: last 30 days)
|--------------------------------------------------------------------------
*/
$validDate = fn($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d) && strtotime($d) !== false;
$to   = $validDate($_GET['to']   ?? '') ? $_GET['to']   : date('Y-m-d');
$from = $validDate($_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-d', strtotime('-29 days'));
if ($from > $to) { [$from, $to] = [$to, $from]; }

$st = $pdo->prepare("
    SELECT t.*, a.fullname AS assignee_name, d.department_name
    FROM tickets t
    LEFT JOIN users a ON a.id = t.assigned_to
    LEFT JOIN departments d ON d.id = t.department_id
    WHERE t.created_at >= ? AND t.created_at <= ?
");
$st->execute([$from . ' 00:00:00', $to . ' 23:59:59']);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Aggregate in PHP (works the same on MySQL and SQLite)
|--------------------------------------------------------------------------
*/
$total = count($rows);
$byStatus   = array_fill_keys(array_keys(TICKET_STATUSES), 0);
$byPriority = array_fill_keys(array_keys(TICKET_PRIORITIES), 0);
$byCategory = [];
$byDept     = [];
$agents     = [];
$created    = [];
$resolvedPerDay = [];

$respTimes = []; $resTimes = []; $slaMet = 0; $slaTotal = 0; $overdueNow = 0;
$now = time();

foreach ($rows as $t) {
    $byStatus[$t['status']] = ($byStatus[$t['status']] ?? 0) + 1;
    $byPriority[$t['priority']] = ($byPriority[$t['priority']] ?? 0) + 1;
    $byCategory[$t['category']] = ($byCategory[$t['category']] ?? 0) + 1;
    $dn = $t['department_name'] ?: 'No department';
    $byDept[$dn] = ($byDept[$dn] ?? 0) + 1;

    $day = substr($t['created_at'], 0, 10);
    $created[$day] = ($created[$day] ?? 0) + 1;

    $closed = isTicketClosed($t['status']);
    if (!$closed && $t['due_at'] && strtotime($t['due_at']) < $now) {
        $overdueNow++;
    }

    if ($t['first_response_at']) {
        $respTimes[] = strtotime($t['first_response_at']) - strtotime($t['created_at']);
    }

    $an = $t['assignee_name'] ?: 'Unassigned';
    $agents[$an] = $agents[$an] ?? ['assigned' => 0, 'resolved' => 0, 'times' => []];
    $agents[$an]['assigned']++;

    if ($closed && $t['resolved_at']) {
        $secs = strtotime($t['resolved_at']) - strtotime($t['created_at']);
        $resTimes[] = $secs;
        $agents[$an]['resolved']++;
        $agents[$an]['times'][] = $secs;
        $rd = substr($t['resolved_at'], 0, 10);
        $resolvedPerDay[$rd] = ($resolvedPerDay[$rd] ?? 0) + 1;
        if ($t['due_at']) {
            $slaTotal++;
            if (strtotime($t['resolved_at']) <= strtotime($t['due_at'])) $slaMet++;
        }
    }
}

$avg = fn(array $a) => $a ? array_sum($a) / count($a) : 0;
$resolvedCount = ($byStatus['resolved'] ?? 0) + ($byStatus['closed'] ?? 0);
$activeCount   = $total - $resolvedCount;
$resolutionRate = $total ? round($resolvedCount / $total * 100) : 0;
$slaPct = $slaTotal ? round($slaMet / $slaTotal * 100) : null;

// continuous day axis
$labels = $seriesCreated = $seriesResolved = [];
for ($d = strtotime($from); $d <= strtotime($to); $d += 86400) {
    $k = date('Y-m-d', $d);
    $labels[] = date('d M', $d);
    $seriesCreated[]  = $created[$k] ?? 0;
    $seriesResolved[] = $resolvedPerDay[$k] ?? 0;
    if (count($labels) > 366) break;
}

$pd = $pdo->prepare("
    SELECT a.id, a.asset_tag, a.name, a.asset_type, COUNT(*) AS cnt
    FROM ticket_assets ta
    JOIN tickets t ON t.id = ta.ticket_id
    JOIN assets a ON a.id = ta.asset_id
    WHERE t.created_at >= ? AND t.created_at <= ?
    GROUP BY a.id, a.asset_tag, a.name, a.asset_type
    ORDER BY cnt DESC LIMIT 8");
$pd->execute([$from . ' 00:00:00', $to . ' 23:59:59']);
$problemDevices = $pd->fetchAll(PDO::FETCH_ASSOC);
$isITViewer = isITStaff($pdo);

arsort($byCategory);
arsort($byDept);
uasort($agents, fn($a, $b) => $b['assigned'] <=> $a['assigned']);

include("../includes/header.php");
include("../includes/navbar.php");
include("../includes/sidebar.php");
?>

<div class="content">

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h2><i class="bi bi-graph-up"></i> Report Analytics</h2>
    <a class="btn btn-success" href="ticket-export.php?from=<?= e($from) ?>&to=<?= e($to) ?>"><i class="bi bi-filetype-csv"></i> Export CSV</a>
</div>

<div class="card shadow mb-4">
<div class="card-body">
<form method="get" class="row g-2 align-items-end">
    <div class="col-md-3"><label class="form-label">From</label><input type="date" name="from" value="<?= e($from) ?>" class="form-control"></div>
    <div class="col-md-3"><label class="form-label">To</label><input type="date" name="to" value="<?= e($to) ?>" class="form-control"></div>
    <div class="col-md-6">
        <button class="btn btn-dark">Apply</button>
        <a class="btn btn-outline-secondary" href="?from=<?= date('Y-m-d', strtotime('-6 days')) ?>&to=<?= date('Y-m-d') ?>">7 days</a>
        <a class="btn btn-outline-secondary" href="?from=<?= date('Y-m-d', strtotime('-29 days')) ?>&to=<?= date('Y-m-d') ?>">30 days</a>
        <a class="btn btn-outline-secondary" href="?from=<?= date('Y-m-01') ?>&to=<?= date('Y-m-d') ?>">This month</a>
    </div>
</form>
</div>
</div>

<!-- KPIs -->
<div class="row g-3 mb-4">
<?php
$kpis = [
    ['Tickets',            $total,                                   'primary',   'ticket-perforated'],
    ['Still active',       $activeCount,                             'info',      'hourglass-split'],
    ['Resolved / closed',  $resolvedCount . " ({$resolutionRate}%)", 'success',   'check-circle'],
    ['Overdue now',        $overdueNow,                              'danger',    'exclamation-triangle'],
    ['Avg first response', humanDuration($avg($respTimes)),          'warning',   'chat-dots'],
    ['Avg resolution',     humanDuration($avg($resTimes)),           'secondary', 'stopwatch'],
    ['SLA met',            $slaPct === null ? '-' : $slaPct . '%',   'success',   'shield-check'],
];
foreach ($kpis as [$label, $value, $color, $icon]): ?>
    <div class="col-6 col-md-4 col-xl">
        <div class="card shadow border-0 h-100"><div class="card-body text-center">
            <i class="bi bi-<?= $icon ?> text-<?= $color ?> fs-3"></i>
            <div class="fs-4 fw-bold"><?= e($value) ?></div>
            <div class="text-muted small"><?= e($label) ?></div>
        </div></div>
    </div>
<?php endforeach; ?>
</div>

<?php if ($total === 0): ?>
<div class="alert alert-info">No tickets were created in this period.</div>
<?php else: ?>

<div class="row g-4 mb-4">
    <div class="col-lg-8"><div class="card shadow border-0"><div class="card-header bg-dark text-white">Tickets created vs resolved per day</div>
        <div class="card-body"><canvas id="cTrend" height="110"></canvas></div></div></div>
    <div class="col-lg-4"><div class="card shadow border-0"><div class="card-header bg-dark text-white">By status</div>
        <div class="card-body"><canvas id="cStatus" height="220"></canvas></div></div></div>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-4"><div class="card shadow border-0"><div class="card-header bg-dark text-white">By priority</div>
        <div class="card-body"><canvas id="cPriority" height="220"></canvas></div></div></div>
    <div class="col-lg-4"><div class="card shadow border-0"><div class="card-header bg-dark text-white">By category</div>
        <div class="card-body"><canvas id="cCategory" height="220"></canvas></div></div></div>
    <div class="col-lg-4"><div class="card shadow border-0"><div class="card-header bg-dark text-white">By department</div>
        <div class="card-body"><canvas id="cDept" height="220"></canvas></div></div></div>
</div>

<div class="card shadow border-0 mb-4">
<div class="card-header bg-primary text-white">Agent performance</div>
<div class="card-body">
<div class="table-responsive">
<table class="table table-hover align-middle mb-0">
<thead class="table-light"><tr><th>Agent</th><th>Assigned</th><th>Resolved</th><th>Resolution rate</th><th>Avg resolution time</th></tr></thead>
<tbody>
<?php foreach ($agents as $name => $a): ?>
<tr>
    <td><?= e($name) ?></td>
    <td><?= $a['assigned'] ?></td>
    <td><?= $a['resolved'] ?></td>
    <td><?= $a['assigned'] ? round($a['resolved'] / $a['assigned'] * 100) . '%' : '-' ?></td>
    <td><?= e(humanDuration($avg($a['times']))) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>
</div>

<?php if ($problemDevices): ?>
<div class="card shadow border-0 mb-4">
<div class="card-header bg-danger text-white">Most ticketed devices</div>
<div class="card-body">
<table class="table table-hover align-middle mb-0">
<thead class="table-light"><tr><th>Tag</th><th>Device</th><th>Type</th><th>Tickets in period</th></tr></thead>
<tbody>
<?php foreach ($problemDevices as $d): ?>
<tr>
    <td><?= $isITViewer ? '<a href="asset-view.php?id=' . (int)$d['id'] . '">' . e($d['asset_tag']) . '</a>' : e($d['asset_tag']) ?></td>
    <td><?= e($d['name']) ?></td><td><?= e($d['asset_type']) ?></td>
    <td><span class="badge bg-<?= (int)$d['cnt'] >= 3 ? 'danger' : 'secondary' ?>"><?= (int)$d['cnt'] ?></span></td>
</tr>
<?php endforeach; ?>
</tbody></table>
</div></div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
const palette = ['#0d6efd','#198754','#ffc107','#dc3545','#0dcaf0','#6f42c1','#fd7e14','#20c997','#6c757d'];
const statusLabels = <?= json_encode(array_values(array_map(fn($s) => $s['label'], TICKET_STATUSES))) ?>;

new Chart(document.getElementById('cTrend'), {
    type: 'line',
    data: { labels: <?= json_encode($labels) ?>, datasets: [
        { label: 'Created',  data: <?= json_encode($seriesCreated) ?>,  borderColor: '#0d6efd', backgroundColor: '#0d6efd22', fill: true, tension: .3 },
        { label: 'Resolved', data: <?= json_encode($seriesResolved) ?>, borderColor: '#198754', backgroundColor: '#19875422', fill: true, tension: .3 }
    ]},
    options: { responsive: true, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }, plugins: { legend: { position: 'bottom' } } }
});
new Chart(document.getElementById('cStatus'), {
    type: 'doughnut',
    data: { labels: statusLabels, datasets: [{ data: <?= json_encode(array_values($byStatus)) ?>, backgroundColor: ['#0d6efd','#0dcaf0','#ffc107','#198754','#6c757d'] }] },
    options: { plugins: { legend: { position: 'bottom' } } }
});
new Chart(document.getElementById('cPriority'), {
    type: 'bar',
    data: { labels: <?= json_encode(array_values(array_map(fn($p) => $p['label'], TICKET_PRIORITIES))) ?>, datasets: [{ data: <?= json_encode(array_values($byPriority)) ?>, backgroundColor: ['#6c757d','#0d6efd','#ffc107','#dc3545'] }] },
    options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
});
new Chart(document.getElementById('cCategory'), {
    type: 'bar',
    data: { labels: <?= json_encode(array_keys($byCategory)) ?>, datasets: [{ data: <?= json_encode(array_values($byCategory)) ?>, backgroundColor: palette }] },
    options: { indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, ticks: { precision: 0 } } } }
});
new Chart(document.getElementById('cDept'), {
    type: 'doughnut',
    data: { labels: <?= json_encode(array_keys($byDept)) ?>, datasets: [{ data: <?= json_encode(array_values($byDept)) ?>, backgroundColor: palette }] },
    options: { plugins: { legend: { position: 'bottom' } } }
});
</script>

<?php endif; ?>

</div>

<?php include("../includes/footer.php"); ?>
