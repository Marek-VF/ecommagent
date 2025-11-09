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

if (!auth_is_logged_in()) {
    $respond([
        'success'   => false,
        'status'    => 'unauthorized',
        'message'   => 'Anmeldung erforderlich.',
        'timestamp' => $timestamp(),
        'logout'    => true,
    ], 401);
}

$sessionUserId = $_SESSION['user_id'] ?? null;
if ($sessionUserId !== null && is_numeric($sessionUserId)) {
    $userId = (int) $sessionUserId;
} else {
    $user = auth_user();
    $userId = isset($user['id']) && is_numeric($user['id']) ? (int) $user['id'] : 0;
}

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

    $requestedRunId = null;
    if (isset($_POST['run_id']) && $_POST['run_id'] !== '') {
        $rawRunId = is_array($_POST['run_id']) ? null : trim((string) $_POST['run_id']);
        if ($rawRunId === null || $rawRunId === '' || !ctype_digit($rawRunId)) {
            throw new RuntimeException('Ungültige run_id übermittelt.');
        }

        $requestedRunId = (int) $rawRunId;
        if ($requestedRunId <= 0) {
            throw new RuntimeException('Ungültige run_id übermittelt.');
        }
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

    $pdo = auth_pdo();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $storedFilePath = null;
    $imageUrl = null;
    $runId = null;

    $pdo->beginTransaction();
    try {
        // IMPORTANT: only create a workflow run when no run_id was provided.
        // Additional images for the same run MUST reuse the existing run_id.
        if ($requestedRunId !== null) {
            $runStmt = $pdo->prepare('SELECT id, user_id FROM workflow_runs WHERE id = :run_id LIMIT 1');
            $runStmt->execute([':run_id' => $requestedRunId]);
            $run = $runStmt->fetch(PDO::FETCH_ASSOC);

            if (!$run) {
                throw new RuntimeException('run_id not found');
            }

            if ((int) $run['user_id'] !== $userId) {
                throw new RuntimeException('run_id does not belong to current user');
            }

            $runId = (int) $run['id'];
        } else {
            $stateStmt = $pdo->prepare('SELECT current_run_id FROM user_state WHERE user_id = :user_id FOR UPDATE');
            $stateStmt->execute([':user_id' => $userId]);
            $state = $stateStmt->fetch(PDO::FETCH_ASSOC);

            if ($state && $state['current_run_id'] !== null) {
                $stateRunId = (int) $state['current_run_id'];

                if ($stateRunId > 0) {
                    $existingRunStmt = $pdo->prepare('SELECT id FROM workflow_runs WHERE id = :run_id AND user_id = :user_id LIMIT 1');
                    $existingRunStmt->execute([
                        ':run_id'  => $stateRunId,
                        ':user_id' => $userId,
                    ]);

                    $existingRun = $existingRunStmt->fetch(PDO::FETCH_ASSOC);
                    if ($existingRun) {
                        $runId = (int) $existingRun['id'];
                    }
                }
            }

            if ($runId === null) {
                $insertRun = $pdo->prepare(
                    'INSERT INTO workflow_runs (user_id, status) VALUES (:user_id, :status)'
                );
                $insertRun->execute([
                    ':user_id' => $userId,
                    ':status'  => 'pending',
                ]);

                $runId = (int) $pdo->lastInsertId();
                if ($runId <= 0) {
                    throw new RuntimeException('run_id konnte nicht erzeugt werden.');
                }
            }
        }

        $targetDirectory = $baseUploadDir . DIRECTORY_SEPARATOR . $userId . DIRECTORY_SEPARATOR . $runId;
        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0775, true) && !is_dir($targetDirectory)) {
            throw new RuntimeException('Upload-Verzeichnis konnte nicht erstellt werden.');
        }

        $destination = $targetDirectory . DIRECTORY_SEPARATOR . $storedName;
        $nameWithoutExtension = pathinfo($storedName, PATHINFO_FILENAME);
        $extension = pathinfo($storedName, PATHINFO_EXTENSION);
        $extensionWithDot = $extension !== '' ? '.' . $extension : '';

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

        $publicPath = sprintf('/uploads/%d/%d/%s', $userId, $runId, $storedName);
        $imageUrl = $baseUrl !== '' ? $baseUrl . $publicPath : $publicPath;

        $insertImage = $pdo->prepare(
            'INSERT INTO run_images (run_id, file_path, original_name) VALUES (:run_id, :file_path, :original_name)'
        );
        $insertImage->execute([
            ':run_id'        => $runId,
            ':file_path'     => $publicPath,
            ':original_name' => $originalName,
        ]);

        $stateSql = <<<'SQL'
INSERT INTO user_state (user_id, current_run_id, last_image_url, updated_at)
VALUES (:user_id, :current_run_id, :last_image_url, NOW())
ON DUPLICATE KEY UPDATE
    current_run_id = VALUES(current_run_id),
    last_image_url = VALUES(last_image_url),
    updated_at = NOW()
SQL;
        $updateState = $pdo->prepare($stateSql);
        $updateState->execute([
            ':user_id'         => $userId,
            ':current_run_id'  => $runId,
            ':last_image_url'  => ltrim($publicPath, '/'),
        ]);

        $updateOriginal = $pdo->prepare(
            'UPDATE workflow_runs SET original_image = :original WHERE id = :run_id AND user_id = :user_id'
            . ' AND (original_image IS NULL OR original_image = "")'
        );
        $updateOriginal->execute([
            ':original' => $publicPath,
            ':run_id'   => $runId,
            ':user_id'  => $userId,
        ]);

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

    $respond([
        'success'    => true,
        'run_id'     => $runId,
        'image_url'  => $imageUrl ?? '',
        'message'    => 'Upload erfolgreich gespeichert.',
        'timestamp'  => $timestamp(),
    ]);
} catch (Throwable $exception) {
    $message = $exception->getMessage();

    if (in_array($message, ['run_id not found', 'run_id does not belong to user'], true)) {
        restore_error_handler();
        $respond([
            'success' => false,
            'message' => $message,
        ], 400);
    }

    restore_error_handler();
    $respond([
        'success'   => false,
        'status'    => 'error',
        'message'   => $message,
        'timestamp' => $timestamp(),
    ], $exception instanceof RuntimeException ? 400 : 500);
} finally {
    restore_error_handler();
}
