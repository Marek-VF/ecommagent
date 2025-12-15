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

$runId = isset($_GET['run_id']) ? (int) $_GET['run_id'] : 0;
$minId = isset($_GET['min_id']) ? (int) $_GET['min_id'] : 0;
if ($runId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing run id']);
    exit;
}

$pdo = getPDO();

$stmt = $pdo->prepare(
    'SELECT id, url FROM item_images_staging WHERE user_id = :user_id AND run_id = :run_id AND id > :min_id ORDER BY id DESC LIMIT 1'
);
$stmt->execute([
    'user_id' => $userId,
    'run_id'  => $runId,
    'min_id'  => $minId,
]);

$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    $runStmt = $pdo->prepare('SELECT status, last_message FROM workflow_runs WHERE id = :run_id');
    $runStmt->execute(['run_id' => $runId]);
    $runRow = $runStmt->fetch(PDO::FETCH_ASSOC);

    $status = isset($runRow['status']) ? strtolower((string) $runRow['status']) : '';
    $hasFailed = in_array($status, ['failed', 'error', 'cancelled'], true);

    if ($hasFailed) {
        echo json_encode([
            'ok'       => true,
            'found'    => true,
            'is_error' => true,
            'message'  => (string) ($runRow['last_message'] ?? ''),
            'image'    => null,
        ]);
        exit;
    }

    echo json_encode([
        'ok'    => true,
        'found' => false,
    ]);
    exit;
}

$baseUrlConfig = $config['base_url'] ?? '';
$baseUrl = '';
if (is_string($baseUrlConfig) && $baseUrlConfig !== '') {
    $baseUrl = rtrim($baseUrlConfig, '/');
}

$url = (string) ($row['url'] ?? '');
if ($url !== '' && !preg_match('#^https?://#i', $url) && !str_starts_with($url, '/')) {
    $url = ($baseUrl !== '' ? $baseUrl : '') . '/' . ltrim($url, '/');
}

echo json_encode([
    'ok'       => true,
    'found'    => true,
    'is_error' => false,
    'image'    => [
        'id'  => (int) $row['id'],
        'url' => $url,
    ],
]);
