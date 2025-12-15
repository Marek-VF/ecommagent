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
    'SELECT id, url, error_message FROM item_images_staging WHERE user_id = :user_id AND run_id = :run_id AND id > :min_id ORDER BY id DESC LIMIT 1'
);
$stmt->execute([
    'user_id' => $userId,
    'run_id'  => $runId,
    'min_id'  => $minId,
]);

$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    echo json_encode([
        'ok'    => true,
        'found' => false,
    ]);
    exit;
}

$isError = isset($row['error_message']) && (string) $row['error_message'] !== '';

if ($isError) {
    echo json_encode([
        'ok'       => true,
        'found'    => true,
        'is_error' => true,
        'message'  => (string) $row['error_message'],
        'image'    => [
            'id' => (int) $row['id'],
        ],
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
