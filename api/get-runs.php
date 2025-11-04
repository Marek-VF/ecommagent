<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

session_start();
require_once __DIR__ . '/../db.php';

$userId = $_SESSION['user']['id'] ?? null;
if (!$userId) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'not authenticated'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$pdo = getPDO();

$runsStmt = $pdo->prepare('
    SELECT id, started_at, finished_at, status, last_message
    FROM workflow_runs
    WHERE user_id = :user_id
    ORDER BY started_at DESC
    LIMIT 30
');
$runsStmt->execute(['user_id' => $userId]);
$runs = $runsStmt->fetchAll(PDO::FETCH_ASSOC);

$runIds = array_column($runs, 'id');
$namesByRun = [];
if (!empty($runIds)) {
    $in = implode(',', array_fill(0, count($runIds), '?'));
    $notesStmt = $pdo->prepare("
        SELECT run_id, product_name
        FROM item_notes
        WHERE run_id IN ($in)
        ORDER BY created_at ASC
    ");
    $notesStmt->execute($runIds);
    while ($row = $notesStmt->fetch(PDO::FETCH_ASSOC)) {
        $rid = (int) $row['run_id'];
        if (!isset($namesByRun[$rid]) && !empty($row['product_name'])) {
            $namesByRun[$rid] = $row['product_name'];
        }
    }
}

$items = [];
foreach ($runs as $run) {
    $rid = (int) $run['id'];
    $startedAt = $run['started_at'] ?? null;
    $timestamp = $startedAt ? strtotime((string) $startedAt) : null;
    if (!$timestamp && !empty($run['finished_at'])) {
        $timestamp = strtotime((string) $run['finished_at']);
    }
    if (!$timestamp) {
        $timestamp = time();
    }
    $dateLabel = date('d.m.Y', $timestamp);
    $title = $namesByRun[$rid] ?? 'Ohne Titel';
    $items[] = [
        'id' => $rid,
        'date' => $dateLabel,
        'title' => $title,
        'status' => $run['status'],
        'last_message' => $run['last_message'],
    ];
}

echo json_encode([
    'ok' => true,
    'data' => $items,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
