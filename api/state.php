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

try {
    $pdo = getPDO();

    $noteStatement = $pdo->prepare(
        'SELECT id, product_name, product_description, created_at
         FROM item_notes
         WHERE user_id = :userId
         ORDER BY created_at DESC, id DESC
         LIMIT 1'
    );
    $noteStatement->bindValue(':userId', $userId, PDO::PARAM_INT);
    $noteStatement->execute();

    $noteRow = $noteStatement->fetch(PDO::FETCH_ASSOC) ?: null;

    $note = null;
    $noteId = null;

    if ($noteRow !== null) {
        $noteId = (int) $noteRow['id'];
        $note = [
            'id'                  => $noteId,
            'product_name'        => $noteRow['product_name'] ?? null,
            'product_description' => $noteRow['product_description'] ?? null,
            'created_at'          => $noteRow['created_at'] ?? null,
        ];
    }

    $stateStatement = $pdo->prepare(
        'SELECT last_status, last_message, last_image_url, updated_at
         FROM user_state
         WHERE user_id = :userId
         LIMIT 1'
    );
    $stateStatement->bindValue(':userId', $userId, PDO::PARAM_INT);
    $stateStatement->execute();
    $stateRow = $stateStatement->fetch(PDO::FETCH_ASSOC) ?: null;

    $state = [
        'last_status'    => $stateRow['last_status'] ?? null,
        'last_message'   => $stateRow['last_message'] ?? null,
        'last_image_url' => $stateRow['last_image_url'] ?? null,
        'updated_at'     => $stateRow['updated_at'] ?? null,
    ];

    $images = [
        'count'      => 0,
        'latest_url' => null,
    ];

    if ($noteId !== null) {
        $countStatement = $pdo->prepare(
            'SELECT COUNT(*) AS image_count
             FROM item_images
             WHERE user_id = :userId AND note_id = :noteId'
        );
        $countStatement->bindValue(':userId', $userId, PDO::PARAM_INT);
        $countStatement->bindValue(':noteId', $noteId, PDO::PARAM_INT);
        $countStatement->execute();

        $countRow = $countStatement->fetch(PDO::FETCH_ASSOC) ?: ['image_count' => 0];
        $images['count'] = isset($countRow['image_count']) ? (int) $countRow['image_count'] : 0;

        $latestImageStatement = $pdo->prepare(
            'SELECT url
             FROM item_images
             WHERE user_id = :userId AND note_id = :noteId
             ORDER BY position ASC, created_at DESC, id DESC
             LIMIT 1'
        );
        $latestImageStatement->bindValue(':userId', $userId, PDO::PARAM_INT);
        $latestImageStatement->bindValue(':noteId', $noteId, PDO::PARAM_INT);
        $latestImageStatement->execute();

        $latestImageRow = $latestImageStatement->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($latestImageRow !== null && isset($latestImageRow['url'])) {
            $images['latest_url'] = $latestImageRow['url'];
        }
    }

    $respond([
        'ok'      => true,
        'user_id' => $userId,
        'note'    => $note,
        'state'   => $state,
        'images'  => $images,
    ]);
} catch (Throwable $exception) {
    error_log('state.php error: ' . $exception->getMessage());
    $respond([
        'ok'    => false,
        'error' => 'Status konnte nicht geladen werden.',
    ], 500);
}
