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

$requestedNote = isset($_GET['note_id']) ? trim((string) $_GET['note_id']) : '';

try {
    $pdo = getPDO();

    $noteId = null;

    if ($requestedNote === '' || $requestedNote === 'latest') {
        $noteStatement = $pdo->prepare(
            'SELECT id
             FROM item_notes
             WHERE user_id = :userId
             ORDER BY created_at DESC, id DESC
             LIMIT 1'
        );
        $noteStatement->bindValue(':userId', $userId, PDO::PARAM_INT);
        $noteStatement->execute();

        $noteRow = $noteStatement->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($noteRow !== null) {
            $noteId = (int) $noteRow['id'];
        }
    } else {
        $noteIdCandidate = filter_var($requestedNote, FILTER_VALIDATE_INT, [
            'options' => [
                'min_range' => 1,
            ],
        ]);

        if ($noteIdCandidate !== false) {
            $noteId = (int) $noteIdCandidate;

            $noteExistsStatement = $pdo->prepare(
                'SELECT id
                 FROM item_notes
                 WHERE id = :noteId AND user_id = :userId
                 LIMIT 1'
            );
            $noteExistsStatement->bindValue(':noteId', $noteId, PDO::PARAM_INT);
            $noteExistsStatement->bindValue(':userId', $userId, PDO::PARAM_INT);
            $noteExistsStatement->execute();

            if (!$noteExistsStatement->fetch(PDO::FETCH_ASSOC)) {
                $noteId = null;
            }
        }
    }

    if ($noteId === null) {
        $respond([
            'ok'    => false,
            'error' => 'Keine Notiz gefunden.',
        ], 404);
    }

    $statement = $pdo->prepare(
        'SELECT id, url, position, created_at
         FROM item_images
         WHERE user_id = :userId AND note_id = :noteId
         ORDER BY position ASC, created_at ASC, id ASC'
    );
    $statement->bindValue(':userId', $userId, PDO::PARAM_INT);
    $statement->bindValue(':noteId', $noteId, PDO::PARAM_INT);
    $statement->execute();

    $items = array_map(static function (array $row): array {
        return [
            'id'         => isset($row['id']) ? (int) $row['id'] : null,
            'url'        => $row['url'] ?? null,
            'position'   => isset($row['position']) ? (int) $row['position'] : null,
            'created_at' => $row['created_at'] ?? null,
        ];
    }, $statement->fetchAll(PDO::FETCH_ASSOC) ?: []);

    $respond([
        'ok'      => true,
        'note_id' => $noteId,
        'items'   => $items,
    ]);
} catch (Throwable $exception) {
    error_log('images.php error: ' . $exception->getMessage());
    $respond([
        'ok'    => false,
        'error' => 'Bilder konnten nicht geladen werden.',
    ], 500);
}
