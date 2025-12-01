<?php
declare(strict_types=1);

// Status feed endpoint used by the Status-Updates-Card to poll recent status messages.
// Response shape (matching script.js expectations):
// {
//   "ok": true,
//   "data": {
//     "items": [...],
//     "last_id": 123
//   }
// }
header('Content-Type: application/json; charset=utf-8');

session_start();
require_once __DIR__ . '/../db.php';

$userId = $_SESSION['user']['id'] ?? null;
if (!$userId) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'not authenticated'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$defaultLimit = 30;
$maxLimit     = 100;
$limit        = $defaultLimit;

if (isset($_GET['limit'])) {
    $limit = (int) $_GET['limit'];
    if ($limit <= 0) {
        $limit = $defaultLimit;
    }
    if ($limit > $maxLimit) {
        $limit = $maxLimit;
    }
}

$runId = isset($_GET['run_id']) ? (int) $_GET['run_id'] : 0;
$runId = $runId > 0 ? $runId : null;

$afterId = isset($_GET['after_id']) ? (int) $_GET['after_id'] : 0;
$afterId = $afterId > 0 ? $afterId : null;

try {
    $pdo = getPDO();

    $sql = 'SELECT id, run_id, user_id, message, source, severity, code, created_at'
        . ' FROM status_logs_new'
        . ' WHERE user_id = :user_id';

    if ($runId !== null) {
        $sql .= ' AND run_id = :run_id';
    }

    if ($afterId !== null) {
        $sql .= ' AND id > :after_id';
    }

    $sql .= ' ORDER BY created_at DESC, id DESC LIMIT :limit';

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
    if ($runId !== null) {
        $stmt->bindValue(':run_id', $runId, PDO::PARAM_INT);
    }
    if ($afterId !== null) {
        $stmt->bindValue(':after_id', $afterId, PDO::PARAM_INT);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);

    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $exception) {
    error_log('get-status-feed failed: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'database error'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$items  = [];
$lastId = null;
$now    = new DateTimeImmutable('now');

$formatRelativeTime = static function (DateTimeImmutable $date) use ($now): string {
    $diffSeconds = $now->getTimestamp() - $date->getTimestamp();

    if ($diffSeconds < 0) {
        $diffSeconds = 0;
    }

    if ($diffSeconds < 60) {
        return 'vor ' . $diffSeconds . ' Sekunden';
    }

    $diffMinutes = (int) floor($diffSeconds / 60);
    if ($diffMinutes < 60) {
        return 'vor ' . $diffMinutes . ' Minuten';
    }

    $diffHours = (int) floor($diffMinutes / 60);
    if ($diffHours < 24) {
        return 'vor ' . $diffHours . ' Stunden';
    }

    $diffDays = (int) floor($diffHours / 24);
    if ($diffDays < 7) {
        return 'vor ' . $diffDays . ' Tagen';
    }

    return 'am ' . $date->format('d.m.Y H:i');
};

foreach ($rows as $row) {
    $id = isset($row['id']) ? (int) $row['id'] : null;

    $createdAtRaw  = $row['created_at'] ?? null;
    $createdAtIso  = null;
    $createdAtHuman = null;

    if (is_string($createdAtRaw) && $createdAtRaw !== '') {
        try {
            $createdAtDate  = new DateTimeImmutable($createdAtRaw);
            $createdAtIso   = $createdAtDate->format(DATE_ATOM);
            $createdAtHuman = $formatRelativeTime($createdAtDate);
        } catch (Throwable $exception) {
            $createdAtIso   = null;
            $createdAtHuman = null;
        }
    }

    if ($id !== null && ($lastId === null || $id > $lastId)) {
        $lastId = $id;
    }

    $items[] = [
        'id'               => $id,
        'run_id'           => isset($row['run_id']) ? (int) $row['run_id'] : null,
        'user_id'          => isset($row['user_id']) ? (int) $row['user_id'] : null,
        'message'          => $row['message'] ?? null,
        'source'           => $row['source'] ?? null,
        'severity'         => $row['severity'] ?? null,
        'code'             => $row['code'] ?? null,
        'created_at'       => $createdAtIso,
        'created_at_human' => $createdAtHuman,
    ];
}

echo json_encode([
    'ok'   => true,
    'data' => [
        'items'   => $items,
        'last_id' => $lastId,
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
