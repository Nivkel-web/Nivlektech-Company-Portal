<?php
require_once("../config/auth.php");
requireRole("admin");
require_once("../config/database.php");
require_once("../includes/ticket_functions.php");
ensureTicketSchema($pdo);

$validDate = fn($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d) && strtotime($d) !== false;
$to   = $validDate($_GET['to']   ?? '') ? $_GET['to']   : date('Y-m-d');
$from = $validDate($_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-d', strtotime('-29 days'));

$st = $pdo->prepare("
    SELECT t.ticket_no, t.subject, t.category, t.priority, t.status,
           c.fullname AS requester, a.fullname AS assignee, d.department_name,
           t.created_at, t.due_at, t.first_response_at, t.resolved_at
    FROM tickets t
    LEFT JOIN users c ON c.id = t.created_by
    LEFT JOIN users a ON a.id = t.assigned_to
    LEFT JOIN departments d ON d.id = t.department_id
    WHERE t.created_at >= ? AND t.created_at <= ?
    ORDER BY t.id
");
$st->execute([$from . ' 00:00:00', $to . ' 23:59:59']);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="tickets_' . $from . '_to_' . $to . '.csv"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");   // BOM so Excel reads UTF-8
fputcsv($out, ['Ticket', 'Subject', 'Category', 'Priority', 'Status', 'Requester', 'Assigned To', 'Department', 'Created', 'Due', 'First Response', 'Resolved']);

// stop spreadsheet formula injection from user-typed text
$safe = fn($v) => (is_string($v) && $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) ? "'" . $v : $v;

while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($out, array_map($safe, array_values($r)));
}
fclose($out);

logActivity($pdo, "Exported ticket report $from to $to");
