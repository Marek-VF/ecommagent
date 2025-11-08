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

$stmt = $pdo->prepare('SELECT id, user_id, status, last_message, original_image FROM workflow_runs WHERE id = :id AND user_id = :user_id LIMIT 1');
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

$baseUrlConfig = $config['base_url'] ?? '';
$baseUrl = '';
if (is_string($baseUrlConfig) && $baseUrlConfig !== '') {
    $baseUrl = rtrim($baseUrlConfig, '/');
}

$resolvePublicPath = static function (?string $path) use ($baseUrl): ?string {
    if ($path === null) {
        return null;
    }

    $trimmed = trim($path);
    if ($trimmed === '') {
        return null;
    }

    if (!preg_match('#^https?://#i', $trimmed) && !str_starts_with($trimmed, '/')) {
        if ($baseUrl !== '') {
            $trimmed = $baseUrl . '/' . ltrim($trimmed, '/');
        } else {
            $trimmed = '/' . ltrim($trimmed, '/');
        }
    }

    return $trimmed;
};

$originalImage = null;
if (isset($run['original_image'])) {
    $originalImage = $resolvePublicPath(is_string($run['original_image']) ? $run['original_image'] : null);
}

$run['original_image'] = $originalImage;

$images = array_map(function ($row) use ($resolvePublicPath) {
    if (!is_array($row)) {
        return $row;
    }

    $url = isset($row['url']) ? (string) $row['url'] : '';

    if ($url !== '') {
        $resolved = $resolvePublicPath($url);
        if ($resolved !== null) {
            $row['url'] = $resolved;
        }
    }

    return $row;
}, $images);

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
        'original_image' => $originalImage,
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
