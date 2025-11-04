<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

session_start();
require_once __DIR__ . '/../db.php';

$userId = $_SESSION['user']['id'] ?? null;
if (!$userId) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'not authenticated']);
    exit;
}

$runId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($runId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing run id']);
    exit;
}

$pdo = getPDO();

$stmt = $pdo->prepare('SELECT id, user_id, status, last_message FROM workflow_runs WHERE id = :id AND user_id = :user_id LIMIT 1');
$stmt->execute(['id' => $runId, 'user_id' => $userId]);
$run = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$run) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'run not found']);
    exit;
}

$noteStmt = $pdo->prepare('
    SELECT product_name, product_description, created_at
    FROM item_notes
    WHERE user_id = :user_id AND run_id = :run_id
    ORDER BY created_at ASC
    LIMIT 1
');
$noteStmt->execute(['user_id' => $userId, 'run_id' => $runId]);
$note = $noteStmt->fetch(PDO::FETCH_ASSOC) ?: null;

$imgStmt = $pdo->prepare('
    SELECT url, position
    FROM item_images
    WHERE user_id = :user_id AND run_id = :run_id
    ORDER BY position ASC, created_at ASC
');
$imgStmt->execute(['user_id' => $userId, 'run_id' => $runId]);
$images = $imgStmt->fetchAll(PDO::FETCH_ASSOC);

$logStmt = $pdo->prepare('
    SELECT level, status_code, message, created_at
    FROM status_logs
    WHERE user_id = :user_id AND run_id = :run_id
    ORDER BY created_at DESC
    LIMIT 20
');
$logStmt->execute(['user_id' => $userId, 'run_id' => $runId]);
$logs = $logStmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'ok' => true,
    'data' => [
        'run' => $run,
        'note' => $note,
        'images' => $images,
        'logs' => $logs,
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
