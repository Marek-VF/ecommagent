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

$rawInput = file_get_contents('php://input');
$payload = json_decode($rawInput ?: 'null', true);
$stagingId = isset($payload['staging_id']) ? (int) $payload['staging_id'] : 0;

if ($stagingId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing staging id']);
    exit;
}

$pdo = getPDO();

try {
    $pdo->beginTransaction();

    $select = $pdo->prepare('SELECT * FROM item_images_staging WHERE id = :id AND user_id = :user_id LIMIT 1');
    $select->execute([
        'id'      => $stagingId,
        'user_id' => $userId,
    ]);

    $stagingRow = $select->fetch(PDO::FETCH_ASSOC);
    if (!$stagingRow) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'staging image not found']);
        exit;
    }

    $insert = $pdo->prepare(
        'INSERT INTO item_images (user_id, run_id, note_id, url, position, badge, created_at)
         VALUES (:user_id, :run_id, :note_id, :url, :position, :badge, NOW())'
    );

    $insert->execute([
        'user_id'  => (int) $stagingRow['user_id'],
        'run_id'   => (int) $stagingRow['run_id'],
        'note_id'  => $stagingRow['note_id'] !== null ? (int) $stagingRow['note_id'] : null,
        'url'      => (string) $stagingRow['url'],
        'position' => $stagingRow['position'] !== null ? (int) $stagingRow['position'] : null,
        'badge'    => 'edit',
    ]);

    $delete = $pdo->prepare('DELETE FROM item_images_staging WHERE id = :id AND user_id = :user_id');
    $delete->execute([
        'id'      => $stagingId,
        'user_id' => $userId,
    ]);

    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'failed to publish edit']);
    exit;
}

echo json_encode(['ok' => true]);
