<?php
/*
|--------------------------------------------------------------------------
| Nivlektech Company Portal
|--------------------------------------------------------------------------
| File : includes/assets.php
| IT asset tracking (laptops, printers, phones...) linked to tickets.
|--------------------------------------------------------------------------
*/

const ASSET_TYPES = ['Laptop', 'Desktop', 'Monitor', 'Printer / Scanner', 'Phone / Tablet', 'Router / Switch', 'Server', 'Software license', 'Other'];

const ASSET_STATUSES = [
    'in_use'    => ['label' => 'In use',    'badge' => 'success'],
    'in_stock'  => ['label' => 'In stock',  'badge' => 'primary'],
    'in_repair' => ['label' => 'In repair', 'badge' => 'warning'],
    'retired'   => ['label' => 'Retired',   'badge' => 'secondary'],
];

function ensureAssetSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $pk   = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
    $tail = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS assets (
            id $pk,
            asset_tag VARCHAR(20) DEFAULT NULL,
            name VARCHAR(150) NOT NULL,
            asset_type VARCHAR(40) NOT NULL DEFAULT 'Other',
            brand VARCHAR(80) DEFAULT '',
            model VARCHAR(80) DEFAULT '',
            serial_number VARCHAR(100) DEFAULT '',
            purchase_date DATE DEFAULT NULL,
            warranty_end DATE DEFAULT NULL,
            status VARCHAR(15) NOT NULL DEFAULT 'in_use',
            assigned_to INT DEFAULT NULL,
            department_id INT DEFAULT NULL,
            location VARCHAR(120) DEFAULT '',
            notes TEXT,
            created_by INT DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        )$tail
    ");
    // one device per ticket (a link table, so the existing tickets table is not altered)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ticket_assets (
            ticket_id INT PRIMARY KEY,
            asset_id INT NOT NULL
        )$tail
    ");
    try {
        $pdo->exec($sqlite ? "CREATE INDEX IF NOT EXISTS idx_ta_asset ON ticket_assets (asset_id)"
                           : "CREATE INDEX idx_ta_asset ON ticket_assets (asset_id)");
    } catch (Throwable $e) {
        // already exists
    }
    $done = true;
}

function assetStatusBadge(string $s): string
{
    $x = ASSET_STATUSES[$s] ?? ['label' => ucfirst($s), 'badge' => 'secondary'];
    return '<span class="badge bg-' . $x['badge'] . '">' . e($x['label']) . '</span>';
}

/* ['state' => none|ok|soon|expired, 'days' => int|null] — "soon" = within 60 days */
function warrantyState(?string $date): array
{
    if (!$date) {
        return ['state' => 'none', 'days' => null];
    }
    $days = (int)floor((strtotime($date . ' 23:59:59') - time()) / 86400);
    return ['state' => $days < 0 ? 'expired' : ($days <= 60 ? 'soon' : 'ok'), 'days' => $days];
}

function warrantyBadge(?string $date): string
{
    $w = warrantyState($date);
    if ($w['state'] === 'none')    return '<span class="text-muted">-</span>';
    $txt = e(date('d M Y', strtotime($date)));
    if ($w['state'] === 'expired') return $txt . ' <span class="badge bg-danger">Expired</span>';
    if ($w['state'] === 'soon')    return $txt . ' <span class="badge bg-warning">' . $w['days'] . ' days left</span>';
    return $txt;
}

function assetLabel(array $a): string
{
    return ($a['asset_tag'] ?: 'AST-' . str_pad((string)$a['id'], 5, '0', STR_PAD_LEFT)) . ' — ' . $a['name'];
}

/* Devices a person may pick on a new ticket: IT see everything, others only the ones assigned to them. */
function selectableAssets(PDO $pdo, int $userId, bool $isIT): array
{
    ensureAssetSchema($pdo);
    $sql = "SELECT a.*, u.fullname AS holder FROM assets a LEFT JOIN users u ON u.id = a.assigned_to
            WHERE a.status <> 'retired'";
    $args = [];
    if (!$isIT) {
        $sql .= " AND a.assigned_to = ?";
        $args[] = $userId;
    }
    $st = $pdo->prepare($sql . " ORDER BY a.name LIMIT 500");
    $st->execute($args);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/* Link (or re-link) a device to a ticket. Non-IT users may only pick their own devices. */
function linkTicketAsset(PDO $pdo, int $ticketId, int $assetId, int $userId): bool
{
    ensureAssetSchema($pdo);
    if ($assetId <= 0) {
        $pdo->prepare("DELETE FROM ticket_assets WHERE ticket_id = ?")->execute([$ticketId]);
        return true;
    }
    $st = $pdo->prepare("SELECT id, assigned_to FROM assets WHERE id = ?");
    $st->execute([$assetId]);
    $a = $st->fetch(PDO::FETCH_ASSOC);
    if (!$a) {
        return false;
    }
    if (!isITStaff($pdo) && (int)$a['assigned_to'] !== $userId) {
        return false;
    }
    $pdo->prepare("DELETE FROM ticket_assets WHERE ticket_id = ?")->execute([$ticketId]);
    $pdo->prepare("INSERT INTO ticket_assets (ticket_id, asset_id) VALUES (?,?)")->execute([$ticketId, $assetId]);
    return true;
}

function ticketAsset(PDO $pdo, int $ticketId): ?array
{
    ensureAssetSchema($pdo);
    $st = $pdo->prepare("SELECT a.* FROM ticket_assets ta JOIN assets a ON a.id = ta.asset_id WHERE ta.ticket_id = ?");
    $st->execute([$ticketId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}
