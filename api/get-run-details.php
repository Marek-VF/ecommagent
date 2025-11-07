<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$config = require __DIR__ . '/../config.php';

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

$originalStmt = $pdo->prepare('
    SELECT file_path, original_name
    FROM run_images
    WHERE run_id = :run_id
    ORDER BY created_at ASC, id ASC
');
$originalStmt->execute(['run_id' => $runId]);
$originalImages = $originalStmt->fetchAll(PDO::FETCH_ASSOC);

$baseUrlConfig = $config['base_url'] ?? '';
$baseUrl = '';
if (is_string($baseUrlConfig) && $baseUrlConfig !== '') {
    $baseUrl = rtrim($baseUrlConfig, '/');
}

$images = array_map(function ($row) use ($baseUrl) {
    if (!is_array($row)) {
        return $row;
    }

    $url = isset($row['url']) ? (string) $row['url'] : '';

    if ($url !== '') {
        $trimmed = trim($url);

        if ($trimmed !== '') {
            if ($baseUrl !== '' && !preg_match('#^https?://#i', $trimmed)) {
                $trimmed = $baseUrl . '/' . ltrim($trimmed, '/');
            } elseif ($baseUrl === '' && !preg_match('#^https?://#i', $trimmed)) {
                $trimmed = '/' . ltrim($trimmed, '/');
            }
        }

        $row['url'] = $trimmed;
    }

    return $row;
}, $images);

$originalImages = array_values(array_filter(array_map(function ($row) use ($baseUrl) {
    if (!is_array($row)) {
        return null;
    }

    $path = isset($row['file_path']) ? (string) $row['file_path'] : '';
    $trimmed = trim($path);
    if ($trimmed === '') {
        return null;
    }

    if ($baseUrl !== '' && !preg_match('#^https?://#i', $trimmed) && strpos($trimmed, '/') !== 0) {
        return $baseUrl . '/' . ltrim($trimmed, '/');
    }

    if ($baseUrl === '' && !preg_match('#^https?://#i', $trimmed) && strpos($trimmed, '/') !== 0) {
        return '/' . ltrim($trimmed, '/');
    }

    return $trimmed;
}, $originalImages), static fn ($value) => $value !== null));

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
        'original_images' => $originalImages,
        'logs' => $logs,
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
