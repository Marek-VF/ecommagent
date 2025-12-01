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

$userId = $_SESSION['user_id'] ?? null;
if (is_string($userId) && ctype_digit($userId)) {
    $userId = (int) $userId;
} elseif (!is_int($userId)) {
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

$pdo = auth_pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$statusLogCount = 0;
$runId = null;

$logBackendStatus = static function (
    ?int $logRunId,
    string $message,
    string $severity,
    ?string $code
) use ($pdo, $userId, &$statusLogCount): void {
    try {
        // Backend events: persist user-facing upload status in status_logs_new
        if (log_status_message($pdo, $logRunId, $userId, $message, 'backend', $severity, $code)) {
            $statusLogCount++;
        }
    } catch (Throwable $loggingException) {
        error_log('[upload_status_log] ' . $loggingException->getMessage());
    }
};

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $logBackendStatus(null, 'Methode nicht erlaubt.', 'error', 'METHOD_NOT_ALLOWED');
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

    $statusMessageFromRequest = extract_status_message($_POST);

    $storedFilePath = null;
    $imageUrl = null;

    $sessionRunId = $_SESSION['current_run_id'] ?? null;
    if (is_string($sessionRunId) && ctype_digit($sessionRunId)) {
        $sessionRunId = (int) $sessionRunId;
    } elseif (!is_int($sessionRunId)) {
        $sessionRunId = null;
    }

    $pdo->beginTransaction();
    try {
        if (is_int($sessionRunId) && $sessionRunId > 0) {
            $existingRunStmt = $pdo->prepare('SELECT id FROM workflow_runs WHERE id = :run_id AND user_id = :user_id LIMIT 1');
            $existingRunStmt->execute([
                ':run_id'  => $sessionRunId,
                ':user_id' => $userId,
            ]);

            $existingRun = $existingRunStmt->fetch(PDO::FETCH_ASSOC);
            if ($existingRun) {
                $runId = (int) $existingRun['id'];
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

            $_SESSION['current_run_id'] = $runId;
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
        $imageUrl = $baseUrl . $publicPath;

        $insertImage = $pdo->prepare(
            'INSERT INTO run_images (run_id, file_path, original_name) VALUES (:run_id, :file_path, :original_name)'
        );
        $insertImage->execute([
            ':run_id'        => $runId,
            ':file_path'     => $publicPath,
            ':original_name' => $originalName,
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

        if ($statusMessageFromRequest !== null) {
            $updateStatusMessage = $pdo->prepare(
                'UPDATE workflow_runs SET last_message = :message WHERE id = :run_id AND user_id = :user_id'
            );
            $updateStatusMessage->execute([
                ':message' => $statusMessageFromRequest,
                ':run_id'  => $runId,
                ':user_id' => $userId,
            ]);

            // Neues Statuslog-System: Speichert jede eingehende statusmeldung (run-bezogen).
            $logBackendStatus($runId, $statusMessageFromRequest, 'info', 'STATUS_MESSAGE');
        }

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

    // Run-bezogene Erfolgsmeldung für die Statusanzeige protokollieren.
    $logBackendStatus($runId, 'Upload erfolgreich.', 'success', 'UPLOAD_OK');

    $respond([
        'success'    => true,
        'run_id'     => $runId,
        'image_url'  => $imageUrl ?? '',
        'message'    => 'uploaded',
        'timestamp'  => $timestamp(),
    ]);
} catch (Throwable $exception) {
    $message = $exception->getMessage();

    // Log upload failures once with backend source + machine-readable code.
    $determineUploadCode = static function (string $errorMessage): string {
        $normalized = strtolower($errorMessage);
        return match (true) {
            str_contains($normalized, 'keine datei empfangen') => 'UPLOAD_NO_FILE',
            str_contains($normalized, 'nur bilddateien') => 'UPLOAD_INVALID_TYPE',
            str_contains($normalized, 'upload-verzeichnis') => 'UPLOAD_DIR_ERROR',
            str_contains($normalized, 'finfo') || str_contains($normalized, 'datei-info') => 'UPLOAD_FINFO_ERROR',
            str_contains($normalized, 'speichert') || str_contains($normalized, 'gespeichert werden') => 'UPLOAD_FAILED',
            str_contains($normalized, 'fehler beim upload') => 'UPLOAD_ERROR_CODE',
            default => 'UPLOAD_FAILED',
        };
    };

    $logBackendStatus($runId, $message, 'error', $determineUploadCode($message));

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
