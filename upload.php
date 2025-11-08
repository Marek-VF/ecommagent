<?php
declare(strict_types=1);

require_once __DIR__ . '/auth/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }

    throw new ErrorException($message, 0, $severity, $file, $line);
});

$respond = static function (array $payload, int $statusCode = 200): never {
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

$timestamp = static fn (): string => gmdate('c');

$normalizePositiveInt = static function (mixed $value): ?int {
    if (is_int($value) && $value > 0) {
        return $value;
    }

    if (is_string($value)) {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        if (ctype_digit($trimmed)) {
            $intValue = (int) $trimmed;
            return $intValue > 0 ? $intValue : null;
        }
    }

    if (is_numeric($value)) {
        $intValue = (int) $value;
        return $intValue > 0 ? $intValue : null;
    }

    return null;
};

if (!auth_is_logged_in()) {
    $respond([
        'success'   => false,
        'status'    => 'unauthorized',
        'message'   => 'Anmeldung erforderlich.',
        'timestamp' => $timestamp(),
        'logout'    => true,
    ], 401);
}

$user = auth_user();
$userId = isset($user['id']) && is_numeric($user['id']) ? (int) $user['id'] : 0;

if ($userId <= 0) {
    $respond([
        'success'   => false,
        'status'    => 'unauthorized',
        'message'   => 'Nicht angemeldet. Bitte erneut einloggen.',
        'timestamp' => $timestamp(),
        'logout'    => true,
    ], 401);
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $respond([
            'success'   => false,
            'status'    => 'error',
            'message'   => 'Methode nicht erlaubt.',
            'timestamp' => $timestamp(),
        ], 405);
    }

    $config = require __DIR__ . '/config.php';

    $baseUploadDir = $config['upload_dir'] ?? (__DIR__ . '/uploads');
    $baseUploadDir = rtrim((string) $baseUploadDir, " \\t\n\r\0\x0B/\\");
    if ($baseUploadDir === '') {
        $baseUploadDir = __DIR__ . '/uploads';
    }

    $webhook = $config['workflow_webhook'] ?? null;
    $baseUrl = rtrim((string)($config['base_url'] ?? ''), '/');

    if (!is_dir($baseUploadDir) && !mkdir($baseUploadDir, 0775, true) && !is_dir($baseUploadDir)) {
        throw new RuntimeException('Upload-Verzeichnis konnte nicht erstellt werden.');
    }

    if (!isset($_FILES['image']) || !is_uploaded_file($_FILES['image']['tmp_name'])) {
        throw new RuntimeException('Keine Datei empfangen.');
    }

    $file = $_FILES['image'];

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Fehler beim Upload: ' . ($file['error'] ?? 'unbekannt'));
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo === false) {
        throw new RuntimeException('Datei-Info konnte nicht gelesen werden.');
    }

    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $allowedMime = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];
    if (!in_array($mimeType, $allowedMime, true)) {
        throw new RuntimeException('Nur Bilddateien sind erlaubt.');
    }

    $originalName = $file['name'] ?? 'upload';
    $sanitized = preg_replace('/[\\\\\/\x00-\x1F\x7F]+/u', '_', (string) $originalName);
    $sanitized = trim($sanitized) === '' ? 'upload_' . date('Ymd_His') : trim($sanitized);
    $storedName = basename($sanitized);

    if ($webhook === null || $webhook === '') {
        throw new RuntimeException('Workflow-Webhook ist nicht konfiguriert.');
    }

    $pdo = auth_pdo();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $storedFilePath = null;
    $publicPath = null;
    $runId = null;
    $runMessage = 'Bereit für Workflow-Start';

    $requestedRunId = $normalizePositiveInt($_POST['run_id'] ?? $_REQUEST['run_id'] ?? null);

    $pdo->beginTransaction();
    try {
        $creatingNewRun = $requestedRunId === null;

        if ($creatingNewRun) {
            $insertRun = $pdo->prepare(
                "INSERT INTO workflow_runs (user_id, started_at, status, last_message) " .
                "VALUES (:user_id, NOW(), :status, :last_message)"
            );
            $insertRun->execute([
                ':user_id'      => $userId,
                ':status'       => 'pending',
                ':last_message' => $runMessage,
            ]);

            $runId = (int) $pdo->lastInsertId();
            if ($runId <= 0) {
                throw new RuntimeException('run_id konnte nicht erzeugt werden.');
            }

            $stateStmt = $pdo->prepare(
                'INSERT INTO user_state (user_id, current_run_id, last_status, last_message, updated_at) VALUES (:user_id, :current_run_id, :last_status, :last_message, NOW())'
                . ' ON DUPLICATE KEY UPDATE'
                . '     current_run_id = VALUES(current_run_id),'
                . '     last_status = VALUES(last_status),'
                . '     last_message = VALUES(last_message),'
                . '     updated_at = NOW()'
            );
            $stateStmt->execute([
                ':user_id'        => $userId,
                ':current_run_id' => $runId,
                ':last_status'    => 'pending',
                ':last_message'   => $runMessage,
            ]);
        } else {
            $runCheck = $pdo->prepare('SELECT id, user_id, status FROM workflow_runs WHERE id = :run_id FOR UPDATE');
            $runCheck->execute([
                ':run_id' => $requestedRunId,
            ]);
            $existingRun = $runCheck->fetch(PDO::FETCH_ASSOC) ?: null;

            if ($existingRun === null || (int) $existingRun['user_id'] !== $userId) {
                throw new RuntimeException('Workflow konnte nicht gefunden werden.');
            }

            $status = isset($existingRun['status']) ? strtolower((string) $existingRun['status']) : '';
            if ($status !== 'pending') {
                throw new RuntimeException('Für diesen Workflow können keine weiteren Bilder hochgeladen werden.');
            }

            $runId = (int) $existingRun['id'];
        }

        $targetDirectory = $baseUploadDir . DIRECTORY_SEPARATOR . $userId . DIRECTORY_SEPARATOR . $runId;
        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0775, true) && !is_dir($targetDirectory)) {
            throw new RuntimeException('Upload-Verzeichnis konnte nicht erstellt werden.');
        }

        $destination = $targetDirectory . DIRECTORY_SEPARATOR . $storedName;
        $nameWithoutExtension = pathinfo($storedName, PATHINFO_FILENAME);
        $extension = pathinfo($storedName, PATHINFO_EXTENSION);
        $extensionWithDot = $extension !== '' ? '.' . $extension : '';
        $originalStoredName = $storedName;

        $counter = 1;
        while (file_exists($destination)) {
            $storedName = sprintf('%s_%d%s', $nameWithoutExtension, $counter, $extensionWithDot);
            $destination = $targetDirectory . DIRECTORY_SEPARATOR . $storedName;
            $counter++;
        }

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            throw new RuntimeException('Datei konnte nicht gespeichert werden.');
        }

        $storedFilePath = $destination;
        @chmod($storedFilePath, 0644);

        $publicPath = sprintf('uploads/%d/%d/%s', $userId, $runId, $storedName);
        $publicUrl  = $baseUrl !== '' ? $baseUrl . '/' . $publicPath : '/' . ltrim($publicPath, '/');

        $insertImage = $pdo->prepare(
            'INSERT INTO run_images (run_id, file_path, original_name) VALUES (:run_id, :file_path, :original_name)'
        );
        $insertImage->execute([
            ':run_id'        => $runId,
            ':file_path'     => $publicPath,
            ':original_name' => $originalStoredName,
        ]);

        $imagesStmt = $pdo->prepare(
            'SELECT file_path FROM run_images WHERE run_id = :run_id ORDER BY created_at ASC, id ASC'
        );
        $imagesStmt->execute([
            ':run_id' => $runId,
        ]);
        $allImages = $imagesStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $pdo->commit();
    } catch (Throwable $databaseException) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if (is_string($storedFilePath) && $storedFilePath !== '' && file_exists($storedFilePath)) {
            @unlink($storedFilePath);
        }

        throw $databaseException;
    }

    $normalizedImages = array_values(array_filter(array_map(
        static fn ($path) => '/' . ltrim(str_replace('\\', '/', (string) $path), '/'),
        $allImages
    ), static fn ($path) => is_string($path) && $path !== '/'));

    $respond([
        'success'     => true,
        'status'      => 'ok',
        'message'     => 'Upload erfolgreich gespeichert.',
        'file'        => $publicPath,
        'image_path'  => '/' . ltrim($publicPath, '/'),
        'image_url'   => $publicUrl,
        'run_id'      => $runId,
        'user_id'     => $userId,
        'images'      => $normalizedImages,
        'timestamp'   => $timestamp(),
        'is_new_run'  => $requestedRunId === null,
    ]);
} catch (Throwable $exception) {
    $respond([
        'success'   => false,
        'status'    => 'error',
        'message'   => $exception->getMessage(),
        'timestamp' => $timestamp(),
    ], $exception instanceof RuntimeException ? 400 : 500);
} finally {
    restore_error_handler();
}
