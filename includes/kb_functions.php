<?php
/*
|--------------------------------------------------------------------------
| Nivlektech Company Portal
|--------------------------------------------------------------------------
| File : includes/kb_functions.php
| IT-only Knowledge Base: schema, access rule, ticket suggestions.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/ticket_functions.php';

/*
| Who counts as "IT team": every admin, plus any active user whose
| department is named "IT" (case-insensitive).
*/
function isITStaff(PDO $pdo): bool
{
    static $cache = [];
    $uid = (int)($_SESSION['id'] ?? 0);
    if (!$uid) {
        return false;
    }
    if (isset($cache[$uid])) {
        return $cache[$uid];
    }
    if (strtolower($_SESSION['role'] ?? '') === 'admin') {
        return $cache[$uid] = true;
    }
    $st = $pdo->prepare("
        SELECT d.department_name FROM users u
        JOIN departments d ON d.id = u.department_id
        WHERE u.id = ? AND u.status = 'active'
    ");
    $st->execute([$uid]);
    $dept = $st->fetchColumn();
    return $cache[$uid] = ($dept !== false && strtolower(trim($dept)) === 'it');
}

function requireITStaff(PDO $pdo): void
{
    if (!isITStaff($pdo)) {
        http_response_code(403);
        include __DIR__ . '/header.php';
        include __DIR__ . '/navbar.php';
        include __DIR__ . '/sidebar.php';
        echo '<div class="content"><div class="alert alert-danger">The Knowledge Base is only available to the IT team.</div>'
           . '<a href="dashboard.php" class="btn btn-secondary">Back</a></div>';
        include __DIR__ . '/footer.php';
        exit;
    }
}

function ensureKbSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $pk   = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
    $tail = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS kb_articles (
            id $pk,
            title VARCHAR(255) NOT NULL,
            category VARCHAR(60) DEFAULT 'General',
            tags VARCHAR(255) DEFAULT '',
            problem TEXT,
            solution TEXT NOT NULL,
            source_ticket_id INT DEFAULT NULL,
            created_by INT DEFAULT NULL,
            views INT NOT NULL DEFAULT 0,
            helpful INT NOT NULL DEFAULT 0,
            not_helpful INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        )$tail
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS kb_votes (
            article_id INT NOT NULL,
            user_id INT NOT NULL,
            vote TINYINT NOT NULL,
            PRIMARY KEY (article_id, user_id)
        )$tail
    ");
    $done = true;
}

function kbWords(string $text): array
{
    static $stop = ['the','and','for','not','with','this','that','from','have','has','are','was','but','you','can','cant',
                    'cannot','when','what','will','been','into','our','out','its','all','any','get','got','able','need',
                    'please','help','after','again','still','very','some','they','them','than','then','just','only'];
    $text = strtolower(preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text));
    $words = array_filter(preg_split('/\s+/', $text), fn($w) => mb_strlen($w) >= 3 && !in_array($w, $stop, true));
    return array_values(array_unique($words));
}

/*
| Suggest articles for a ticket. Simple keyword scoring, no external service:
|   title / tag hit = 3, problem / solution hit = 1, same category = +2,
|   a small boost for articles that have helped people before.
*/
function kbSuggest(PDO $pdo, array $ticket, int $limit = 5): array
{
    $words = kbWords($ticket['subject'] . ' ' . $ticket['description']);
    $rows = $pdo->query("SELECT * FROM kb_articles ORDER BY id DESC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($rows as $a) {
        $head = strtolower($a['title'] . ' ' . $a['tags']);
        $body = strtolower(($a['problem'] ?? '') . ' ' . $a['solution']);
        $score = 0; $hits = [];
        foreach ($words as $w) {
            if (strpos($head, $w) !== false)      { $score += 3; $hits[] = $w; }
            elseif (strpos($body, $w) !== false)  { $score += 1; $hits[] = $w; }
        }
        if ($score === 0) {
            continue;
        }
        if (strcasecmp($a['category'], $ticket['category']) === 0) {
            $score += 2;
        }
        $score += min(3, max(0, $a['helpful'] - $a['not_helpful']) * 0.5);
        $a['score'] = $score;
        $a['hits']  = array_slice($hits, 0, 4);
        $out[] = $a;
    }
    usort($out, fn($x, $y) => $y['score'] <=> $x['score']);
    return array_slice($out, 0, $limit);
}

/* Latest "Resolution:" internal note of a ticket (used to prefill a new article) */
function latestResolutionNote(PDO $pdo, int $ticketId): string
{
    $st = $pdo->prepare("
        SELECT message FROM ticket_replies
        WHERE ticket_id = ? AND is_internal = 1 AND message LIKE 'Resolution:%'
        ORDER BY id DESC LIMIT 1
    ");
    $st->execute([$ticketId]);
    $m = $st->fetchColumn();
    return $m ? trim(preg_replace('/^Resolution:\s*/i', '', $m)) : '';
}
