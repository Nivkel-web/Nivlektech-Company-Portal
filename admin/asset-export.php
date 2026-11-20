<?php
require_once("../config/auth.php");
require_once("../config/database.php");
require_once("../includes/ticket_functions.php");
ensureTicketSchema($pdo);
ensureAssetSchema($pdo);
requireITStaff($pdo);

$rows = $pdo->query("
    SELECT a.asset_tag, a.name, a.asset_type, a.brand, a.model, a.serial_number, a.status,
           u.fullname AS held_by, d.department_name, a.location, a.purchase_date, a.warranty_end,
           (SELECT COUNT(*) FROM ticket_assets ta WHERE ta.asset_id = a.id) AS tickets
    FROM assets a LEFT JOIN users u ON u.id = a.assigned_to LEFT JOIN departments d ON d.id = a.department_id
    ORDER BY a.id")->fetchAll(PDO::FETCH_NUM);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="assets_' . date('Y-m-d') . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, ['Tag', 'Name', 'Type', 'Brand', 'Model', 'Serial', 'Status', 'Held by', 'Department', 'Location', 'Purchased', 'Warranty ends', 'Tickets']);
$safe = fn($v) => (is_string($v) && $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) ? "'" . $v : $v;
foreach ($rows as $r) {
    fputcsv($out, array_map($safe, $r));
}
fclose($out);
logActivity($pdo, 'Exported asset list');
