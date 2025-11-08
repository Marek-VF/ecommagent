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

    $runMessage = 'Workflow gestartet';
    $forwardResponse = null;
    $webhookStatus = null;
    $storedFilePath = null;
    $publicPath = null;

    $pdo->beginTransaction();
    try {
        $insertRun = $pdo->prepare(
            "INSERT INTO workflow_runs (user_id, started_at, status, last_message) " .
            "VALUES (:user_id, NOW(), 'running', :last_message)"
        );
        $insertRun->execute([
            ':user_id'      => $userId,
            ':last_message' => $runMessage,
        ]);

        $runId = (int) $pdo->lastInsertId();
        if ($runId <= 0) {
            throw new RuntimeException('run_id konnte nicht erzeugt werden.');
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

        $publicPath = sprintf('uploads/%d/%d/%s', $userId, $runId, $storedName);
        $publicUrl  = $baseUrl !== '' ? $baseUrl . '/' . $publicPath : $publicPath;

        $updateOriginal = $pdo->prepare('UPDATE workflow_runs SET original_image = :original WHERE id = :run_id AND user_id = :user_id');
        $updateOriginal->execute([
            ':original' => $publicPath,
            ':run_id'   => $runId,
            ':user_id'  => $userId,
        ]);

        $curlFile = new CURLFile($storedFilePath, $mimeType ?: 'application/octet-stream', $storedName);
        $postFields = [
            'file'      => $curlFile,
            'user_id'   => $userId,
            'run_id'    => $runId,
            'image_url' => $publicUrl,
            'file_name' => $storedName,
            'timestamp' => $timestamp(),
        ];

        $ch = curl_init($webhook);
        if ($ch === false) {
            throw new RuntimeException('Webhook konnte nicht initialisiert werden.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS     => $postFields,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => ['Accept: application/json, */*;q=0.8'],
        ]);

        $webhookResponse = curl_exec($ch);
        $webhookStatus   = curl_getinfo($ch, CURLINFO_RESPONSE_CODE) ?: null;
        $curlError       = curl_error($ch);
        curl_close($ch);

        if ($webhookResponse === false) {
            $errorMessage = $curlError !== '' ? $curlError : 'Unbekannter Fehler bei der Webhook-Ausführung.';
            throw new RuntimeException('Webhook-Aufruf fehlgeschlagen: ' . $errorMessage);
        }

        $forwardResponse = json_decode($webhookResponse, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $forwardResponse = $webhookResponse;
        }

        if (!is_int($webhookStatus) || $webhookStatus < 200 || $webhookStatus >= 300) {
            throw new RuntimeException('Workflow-Webhook antwortete mit Status ' . ($webhookStatus ?? 'unbekannt') . '.');
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
            ':last_status'    => 'running',
            ':last_message'   => $runMessage,
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
        'success'          => true,
        'status'           => 'ok',
        'message'          => 'Upload erfolgreich gespeichert und Workflow gestartet.',
        'file'             => $publicPath,
        'run_id'           => $runId,
        'timestamp'        => $timestamp(),
        'webhook_status'   => $webhookStatus,
        'webhook_response' => $forwardResponse,
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
