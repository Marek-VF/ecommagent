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

try {
    $pdo = getPDO();
    $statement = $pdo->prepare(
        'SELECT user_id, last_status, last_message, last_image_url, last_payload_summary, updated_at
         FROM user_state
         WHERE user_id = :userId
         LIMIT 1'
    );
    $statement->bindValue(':userId', $userId, PDO::PARAM_INT);
    $statement->execute();

    $row = $statement->fetch(PDO::FETCH_ASSOC) ?: null;

    $state = [
        'user_id'             => $userId,
        'last_status'         => $row['last_status'] ?? null,
        'last_message'        => $row['last_message'] ?? null,
        'last_image_url'      => $row['last_image_url'] ?? null,
        'last_payload_summary'=> $row['last_payload_summary'] ?? null,
        'updated_at'          => $row['updated_at'] ?? null,
    ];

    $respond($state);
} catch (Throwable $exception) {
    error_log('state.php error: ' . $exception->getMessage());
    $respond([
        'error' => 'Status konnte nicht geladen werden.',
    ], 500);
}
