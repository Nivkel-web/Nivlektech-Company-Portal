<?php
/*
|--------------------------------------------------------------------------
| Due-time SMS check  (run every 5-10 minutes)
|--------------------------------------------------------------------------
| Linux cron :  every 10 minutes ->  php /path/to/portal/cron/check-due.php
|              (crontab line: star-slash-10 followed by four stars)
| Windows    :  Task Scheduler -> Run  php.exe  with argument  C:\path\to\portal\cron\check-due.php
|
| Texts the assignee (or the IT team if unassigned) once when a ticket is
| within 30 minutes of its due time and once when it becomes overdue.
*/

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this script from the command line / scheduler only.');
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/ticket_functions.php';

ensureTicketSchema($pdo);
$r = smsCheckDueTickets($pdo);

echo date('Y-m-d H:i:s') . ' ' . ($r['skipped'] ?: "due soon: {$r['due_soon']}, overdue: {$r['overdue']}") . PHP_EOL;
