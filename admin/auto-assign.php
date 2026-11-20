<?php
require_once("../config/auth.php");
requireRole("admin");
require_once("../config/database.php");
require_once("../includes/ticket_functions.php");
ensureTicketSchema($pdo);
ensureAutoAssignSchema($pdo);

$message = "";
$cfg  = getAutoAssignSettings($pdo);
$team = itTeamMembers($pdo);
$uid  = (int)$currentUserId;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    csrfCheck();

    if (isset($_POST['save'])) {
        $pdo->prepare("UPDATE autoassign_settings SET enabled = ?, fallback_any = ?, updated_at = ? WHERE id = 1")
            ->execute([isset($_POST['enabled']) ? 1 : 0, isset($_POST['fallback_any']) ? 1 : 0, date('Y-m-d H:i:s')]);

        foreach ($team as $m) {
            $id   = (int)$m['id'];
            $spec = array_values(array_intersect(TICKET_CATEGORIES, (array)($_POST['spec'][$id] ?? [])));
            $vals = [
                isset($_POST['auto'][$id]) ? 1 : 0,
                isset($_POST['away'][$id]) ? 1 : 0,
                max(0, min(200, (int)($_POST['max_open'][$id] ?? 0))),
                implode(',', $spec),
            ];
            $has = $pdo->prepare("SELECT COUNT(*) FROM it_agents WHERE user_id = ?");
            $has->execute([$id]);
            if ($has->fetchColumn()) {
                $pdo->prepare("UPDATE it_agents SET auto_assign=?, away=?, max_open=?, specialties=? WHERE user_id=?")
                    ->execute(array_merge($vals, [$id]));
            } else {
                $pdo->prepare("INSERT INTO it_agents (auto_assign, away, max_open, specialties, user_id) VALUES (?,?,?,?,?)")
                    ->execute(array_merge($vals, [$id]));
            }
        }
        logActivity($pdo, 'Updated auto-assign settings');
        $message = "<div class='alert alert-success'>Auto-assign settings saved.</div>";
    }

    if (isset($_POST['run_now'])) {
        [$done, $left] = autoAssignAllUnassigned($pdo, $uid);
        $message = "<div class='alert alert-" . ($done ? 'success' : 'info') . "'>Assigned <strong>$done</strong> ticket(s)."
                 . ($left ? " $left could not be assigned (nobody eligible — check opt-ins, specialties, away and caps)." : '') . "</div>";
        logActivity($pdo, "Auto-assigned $done unassigned ticket(s)");
    }

    $cfg  = getAutoAssignSettings($pdo);
    $team = itTeamMembers($pdo);
}

$unassigned = (int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE assigned_to IS NULL AND status NOT IN ('resolved','closed')")->fetchColumn();

include("../includes/header.php");
include("../includes/navbar.php");
include("../includes/sidebar.php");
?>

<div class="content">

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-shuffle"></i> Auto-Assign</h2>
    <a href="tickets.php?status=active" class="btn btn-outline-dark">Tickets</a>
</div>

<?= $message ?>

<form method="post">
<?= csrfField() ?>

<div class="card shadow mb-4">
    <div class="card-header bg-dark text-white">How it works</div>
    <div class="card-body">
        <div class="form-check form-switch mb-2">
            <input class="form-check-input" type="checkbox" name="enabled" id="enabled" <?= $cfg['enabled'] ? 'checked' : '' ?>>
            <label class="form-check-label" for="enabled"><strong>Automatically assign new tickets</strong></label>
        </div>
        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" name="fallback_any" id="fb" <?= $cfg['fallback_any'] ? 'checked' : '' ?>>
            <label class="form-check-label" for="fb">If no specialist is free, give it to the least busy IT person</label>
        </div>
        <small class="text-muted">
            Each new ticket goes to an IT person who is <strong>ticked</strong>, <strong>not away</strong> and <strong>under their open-ticket limit</strong>.
            Specialists for the ticket's category come first; among them the person with the fewest open tickets gets it (ties go to whoever was assigned longest ago).
            Admins can still reassign by hand.
        </small>
    </div>
</div>

<div class="card shadow mb-4">
    <div class="card-header bg-primary text-white">IT team</div>
    <div class="card-body">
    <div class="table-responsive">
    <table class="table align-middle mb-2">
        <thead><tr>
            <th>Person</th><th width="80" class="text-center">Auto-assign</th><th width="70" class="text-center">Away</th>
            <th width="110">Max open<br><small class="text-muted fw-normal">0 = no limit</small></th>
            <th>Specialties (ticket categories)</th><th width="70" class="text-center">Open now</th>
        </tr></thead>
        <tbody>
        <?php foreach ($team as $m): $id = (int)$m['id']; ?>
        <tr>
            <td><?= e($m['fullname']) ?><br><small class="text-muted"><?= $m['role'] === 'admin' ? 'Admin' : 'IT' ?></small></td>
            <td class="text-center"><input type="checkbox" class="form-check-input" name="auto[<?= $id ?>]" <?= $m['auto_assign'] ? 'checked' : '' ?>></td>
            <td class="text-center"><input type="checkbox" class="form-check-input" name="away[<?= $id ?>]" <?= $m['away'] ? 'checked' : '' ?>></td>
            <td><input type="number" min="0" max="200" class="form-control form-control-sm" name="max_open[<?= $id ?>]" value="<?= (int)$m['max_open'] ?>"></td>
            <td>
                <div class="d-flex flex-wrap column-gap-3">
                <?php foreach (TICKET_CATEGORIES as $c): ?>
                <label class="d-inline-flex align-items-center gap-1 my-1 small fw-normal text-nowrap"><input type="checkbox" class="form-check-input mt-0" name="spec[<?= $id ?>][]" value="<?= e($c) ?>"
                    <?= in_array($c, $m['spec_list'], true) ? 'checked' : '' ?>> <?= e($c) ?></label>
                <?php endforeach; ?>
                </div>
            </td>
            <td class="text-center"><span class="badge bg-<?= ($m['max_open'] && $m['open_count'] >= $m['max_open']) ? 'danger' : 'secondary' ?>"><?= (int)$m['open_count'] ?></span></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <small class="text-muted">A person with no specialties ticked is only used by the "least busy" fallback.</small>
    </div>
</div>

<button name="save" value="1" class="btn btn-primary"><i class="bi bi-save"></i> Save Settings</button>
</form>

<div class="card shadow mt-4">
    <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div><strong><?= $unassigned ?></strong> active ticket(s) are currently unassigned.</div>
        <form method="post" onsubmit="return confirm('Assign all unassigned tickets using the rules above?');">
            <?= csrfField() ?>
            <button name="run_now" value="1" class="btn btn-warning" <?= $unassigned ? '' : 'disabled' ?>>Auto-assign them now</button>
        </form>
    </div>
</div>

</div>

<?php include("../includes/footer.php"); ?>
