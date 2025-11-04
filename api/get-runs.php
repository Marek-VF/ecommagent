<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../db.php';

$userId = $_SESSION['user']['id'] ?? null;
if (!is_numeric($userId) || (int) $userId <= 0) {
    http_response_code(401);
    echo json_encode([
        'ok'     => false,
        'error'  => 'not authenticated',
        'logout' => true,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $pdo = getPDO();
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => 'db error',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$limit = 20;
if (isset($_GET['limit']) && is_scalar($_GET['limit'])) {
    $limitValue = (int) $_GET['limit'];
    if ($limitValue > 0 && $limitValue <= 100) {
        $limit = $limitValue;
    }
}

try {
    $statement = $pdo->prepare(
        'SELECT id, started_at, finished_at, status, last_message
         FROM workflow_runs
         WHERE user_id = :user_id
         ORDER BY started_at DESC, id DESC
         LIMIT :limit'
    );
    $statement->bindValue(':user_id', (int) $userId, PDO::PARAM_INT);
    $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
    $statement->execute();
    $runs = $statement->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok'   => true,
        'data' => $runs,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (PDOException $exception) {
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => 'db error',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
