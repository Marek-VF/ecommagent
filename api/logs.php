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
        'ok'    => false,
        'error' => 'Anmeldung erforderlich.',
    ], 401);
}

$user = auth_user();
$userId = isset($user['id']) ? (int) $user['id'] : 0;

if ($userId <= 0) {
    $respond([
        'ok'    => false,
        'error' => 'Benutzer konnte nicht ermittelt werden.',
    ], 400);
}

$limit = 50;
if (isset($_GET['limit'])) {
    $limitCandidate = filter_var($_GET['limit'], FILTER_VALIDATE_INT, [
        'options' => [
            'default'   => $limit,
            'min_range' => 1,
            'max_range' => 200,
        ],
    ]);

    if ($limitCandidate !== false) {
        $limit = (int) $limitCandidate;
    }
}

$sinceRaw = isset($_GET['since']) ? trim((string) $_GET['since']) : '';
$sinceValue = null;

if ($sinceRaw !== '') {
    $parsedTime = strtotime($sinceRaw);
    if ($parsedTime !== false) {
        $sinceValue = date('Y-m-d H:i:s', $parsedTime);
    }
}

try {
    $pdo = getPDO();

    $sql = 'SELECT created_at, level, COALESCE(status_code, 200) AS status_code, message
            FROM status_logs
            WHERE user_id = :userId';

    if ($sinceValue !== null) {
        $sql .= ' AND created_at > :since';
    }

    $sql .= ' ORDER BY created_at DESC LIMIT :limit';

    $statement = $pdo->prepare($sql);
    $statement->bindValue(':userId', $userId, PDO::PARAM_INT);
    $statement->bindValue(':limit', $limit, PDO::PARAM_INT);

    if ($sinceValue !== null) {
        $statement->bindValue(':since', $sinceValue);
    }

    $statement->execute();

    $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $items = array_map(static function (array $row): array {
        return [
            'created_at'  => $row['created_at'] ?? null,
            'level'       => $row['level'] ?? null,
            'status_code' => isset($row['status_code']) ? (int) $row['status_code'] : 200,
            'message'     => $row['message'] ?? null,
        ];
    }, $rows);

    $respond([
        'ok'    => true,
        'items' => $items,
    ]);
} catch (Throwable $exception) {
    error_log('logs.php error: ' . $exception->getMessage());
    $respond([
        'ok'    => false,
        'error' => 'Protokoll konnte nicht geladen werden.',
    ], 500);
}
