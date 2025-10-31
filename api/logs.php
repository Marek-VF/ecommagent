<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth/bootstrap.php';
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$respond = static function (array $payload, int $statusCode = 200): never {
    http_response_code($statusCode);

    if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') {
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    exit;
};

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    $respond([
        'error' => 'Methode nicht erlaubt.',
    ], 405);
}

if (!auth_is_logged_in()) {
    $respond([
        'error' => 'Anmeldung erforderlich.',
    ], 401);
}

$user = auth_user();
$userId = isset($user['id']) ? (int) $user['id'] : 0;

if ($userId <= 0) {
    $respond([
        'error' => 'Benutzer konnte nicht ermittelt werden.',
    ], 400);
}

$limit = 50;
if (isset($_GET['limit'])) {
    $limitCandidate = filter_var($_GET['limit'], FILTER_VALIDATE_INT, [
        'options' => [
            'default' => $limit,
            'min_range' => 1,
        ],
    ]);
    if ($limitCandidate !== false) {
        $limit = (int) $limitCandidate;
    }
}

$limit = max(1, min($limit, 200));

try {
    $pdo = getPDO();
    $statement = $pdo->prepare(
        'SELECT created_at, level, status_code, message
         FROM status_logs
         WHERE user_id = :userId
         ORDER BY created_at DESC
         LIMIT :limit'
    );
    $statement->bindValue(':userId', $userId, PDO::PARAM_INT);
    $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
    $statement->execute();

    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    $logs = array_map(static function (array $row): array {
        $statusCode = $row['status_code'];
        if ($statusCode === null || $statusCode === '') {
            $statusCode = null;
        } else {
            $statusCode = (int) $statusCode;
        }

        return [
            'created_at'  => $row['created_at'] ?? null,
            'level'       => $row['level'] ?? null,
            'status_code' => $statusCode,
            'message'     => $row['message'] ?? null,
        ];
    }, $rows ?: []);

    $respond([
        'logs'  => $logs,
        'count' => count($logs),
        'limit' => $limit,
    ]);
} catch (Throwable $exception) {
    error_log('logs.php error: ' . $exception->getMessage());
    $respond([
        'error' => 'Protokoll konnte nicht geladen werden.',
    ], 500);
}
