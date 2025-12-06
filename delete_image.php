<?php
declare(strict_types=1);

require_once __DIR__ . '/auth/bootstrap.php';
require_once __DIR__ . '/status_logger.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$respond = static function (array $payload, int $statusCode = 200): never {
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

if (!auth_is_logged_in()) {
    $respond([
        'success' => false,
        'message' => 'Anmeldung erforderlich.',
        'logout'  => true,
    ], 401);
}

$userId = $_SESSION['user_id'] ?? null;
if (is_string($userId) && ctype_digit($userId)) {
    $userId = (int) $userId;
} elseif (!is_int($userId)) {
    $user = auth_user();
    $userId = isset($user['id']) && is_numeric($user['id']) ? (int) $user['id'] : 0;
}

if ($userId <= 0) {
    $respond([
        'success' => false,
        'message' => 'Ungültige Sitzung. Bitte erneut anmelden.',
        'logout'  => true,
    ], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $respond([
        'success' => false,
        'message' => 'Methode nicht erlaubt.',
    ], 405);
}

$input = json_decode((string) file_get_contents('php://input'), true);
$runId = null;

if (is_array($input) && array_key_exists('run_id', $input)) {
    $runId = filter_var($input['run_id'], FILTER_VALIDATE_INT);
}

if (!is_int($runId) || $runId <= 0) {
    $respond([
        'success' => false,
        'message' => 'Ungültige run_id.',
    ], 400);
}

try {
    $pdo = auth_pdo();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->beginTransaction();

    $runStmt = $pdo->prepare('SELECT id, user_id, original_image FROM workflow_runs WHERE id = :run_id LIMIT 1');
    $runStmt->execute([':run_id' => $runId]);
    $run = $runStmt->fetch(PDO::FETCH_ASSOC);

    if (!$run || (int) $run['user_id'] !== $userId) {
        $pdo->rollBack();
        $respond([
            'success' => false,
            'message' => 'Workflow-Run nicht gefunden.',
        ], 404);
    }

    $imageStmt = $pdo->prepare('SELECT id, file_path FROM run_images WHERE run_id = :run_id');
    $imageStmt->execute([':run_id' => $runId]);
    $images = $imageStmt->fetchAll(PDO::FETCH_ASSOC);

    $pathsToDelete = [];
    foreach ($images as $image) {
        if (!isset($image['file_path'])) {
            continue;
        }

        $path = trim((string) $image['file_path']);
        if ($path !== '') {
            $pathsToDelete[] = $path;
        }
    }

    $originalImagePath = isset($run['original_image']) ? trim((string) $run['original_image']) : '';
    if ($originalImagePath !== '') {
        $pathsToDelete[] = $originalImagePath;
    }

    foreach ($pathsToDelete as $path) {
        $absolutePath = __DIR__ . '/' . ltrim($path, '/');

        if (is_file($absolutePath) && file_exists($absolutePath)) {
            @unlink($absolutePath);
        }
    }

    $deleteImagesStmt = $pdo->prepare('DELETE FROM run_images WHERE run_id = :run_id');
    $deleteImagesStmt->execute([':run_id' => $runId]);

    $updateRunStmt = $pdo->prepare('UPDATE workflow_runs SET original_image = NULL WHERE id = :run_id AND user_id = :user_id');
    $updateRunStmt->execute([
        ':run_id'  => $runId,
        ':user_id' => $userId,
    ]);

    log_status_message($pdo, $runId, $userId, 'Originalbild entfernt', 'IMAGE_DELETED', 'warning');

    $pdo->commit();

    $respond(['success' => true]);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $respond([
        'success' => false,
        'message' => 'Löschen fehlgeschlagen.',
    ], 500);
}
