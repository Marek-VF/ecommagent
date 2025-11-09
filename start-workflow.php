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

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $respond([
        'success'   => false,
        'status'    => 'error',
        'message'   => 'Methode nicht erlaubt.',
        'timestamp' => $timestamp(),
    ], 405);
}

$rawBody = file_get_contents('php://input');
$data = null;
if (is_string($rawBody) && trim($rawBody) !== '') {
    $decoded = json_decode($rawBody, true);
    if (is_array($decoded)) {
        $data = $decoded;
    }
}

if (!is_array($data)) {
    $data = $_POST;
}

$runId = $normalizePositiveInt($data['run_id'] ?? null);
if ($runId === null) {
    $respond([
        'success'   => false,
        'status'    => 'error',
        'message'   => 'run_id fehlt.',
        'timestamp' => $timestamp(),
    ], 400);
}

$requestedUserId = $normalizePositiveInt($data['user_id'] ?? null);
if ($requestedUserId !== null && $requestedUserId !== $userId) {
    $respond([
        'success'   => false,
        'status'    => 'forbidden',
        'message'   => 'Keine Berechtigung für diesen Workflow.',
        'timestamp' => $timestamp(),
    ], 403);
}

try {
    $config = auth_config();
    $webhook = $config['workflow_webhook'] ?? null;
    if (!is_string($webhook) || trim($webhook) === '') {
        throw new RuntimeException('Workflow-Webhook ist nicht konfiguriert.');
    }

    $baseUploadDir = $config['upload_dir'] ?? (__DIR__ . '/uploads');
    $baseUploadDir = rtrim((string) $baseUploadDir, " \\\t\n\r\0\x0B/\\");
    if ($baseUploadDir === '') {
        $baseUploadDir = __DIR__ . '/uploads';
    }

    $baseUrl = rtrim((string) ($config['base_url'] ?? ''), '/');

    $buildAbsoluteUrl = static function (string $path) use ($baseUrl): string {
        $trimmed = trim($path);
        if ($trimmed === '') {
            return '';
        }

        $normalized = str_replace('\\', '/', $trimmed);
        if (preg_match('#^(?:[a-z][a-z0-9+.-]*:)?//#i', $normalized)) {
            return $normalized;
        }

        $relative = ltrim($normalized, '/');
        if ($relative === '') {
            return $baseUrl !== '' ? $baseUrl : '/';
        }

        if ($baseUrl !== '') {
            return $baseUrl . '/' . $relative;
        }

        return '/' . $relative;
    };

    $resolveStoredFilePath = static function (string $path) use ($baseUploadDir, $userId, $runId): string {
        $normalized = str_replace('\\', '/', trim($path));
        if ($normalized === '') {
            return '';
        }

        $filename = basename($normalized);
        if ($filename === '') {
            return '';
        }

        $storedFile = $baseUploadDir
            . DIRECTORY_SEPARATOR . $userId
            . DIRECTORY_SEPARATOR . $runId
            . DIRECTORY_SEPARATOR . $filename;

        return is_file($storedFile) ? $storedFile : '';
    };

    $pdo = auth_pdo();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $runStmt = $pdo->prepare('SELECT id, status, last_message, original_image FROM workflow_runs WHERE id = :run_id AND user_id = :user_id LIMIT 1');
    $runStmt->execute([
        ':run_id'  => $runId,
        ':user_id' => $userId,
    ]);
    $run = $runStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($run === null) {
        $respond([
            'success'   => false,
            'status'    => 'not_found',
            'message'   => 'Workflow nicht gefunden.',
            'timestamp' => $timestamp(),
        ], 404);
    }

    $statusValue = isset($run['status']) ? strtolower(trim((string) $run['status'])) : '';
    if ($statusValue === 'running') {
        $respond([
            'success'   => false,
            'status'    => 'conflict',
            'message'   => 'Workflow läuft bereits.',
            'timestamp' => $timestamp(),
        ], 409);
    }

    if ($statusValue === 'finished') {
        $respond([
            'success'   => false,
            'status'    => 'conflict',
            'message'   => 'Workflow wurde bereits abgeschlossen.',
            'timestamp' => $timestamp(),
        ], 409);
    }

    $originalPath = isset($run['original_image']) ? trim((string) $run['original_image']) : '';

    $imageStmt = $pdo->prepare('SELECT file_path FROM run_images WHERE run_id = :run_id ORDER BY id ASC LIMIT 2');
    $imageStmt->execute([':run_id' => $runId]);
    $imageRows = $imageStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

    $imagePaths = [];
    foreach ($imageRows as $rowPath) {
        $trimmedPath = trim((string) $rowPath);
        if ($trimmedPath !== '') {
            $imagePaths[] = $trimmedPath;
        }
    }

    if ($imagePaths === [] && $originalPath !== '') {
        $imagePaths[] = $originalPath;
    }

    if ($imagePaths === []) {
        $respond([
            'success'   => false,
            'status'    => 'error',
            'message'   => 'Kein Originalbild vorhanden.',
            'timestamp' => $timestamp(),
        ], 400);
    }

    $primaryPath = $imagePaths[0];
    $storedFilePath = $resolveStoredFilePath($primaryPath);
    if ($storedFilePath === '') {
        $respond([
            'success'   => false,
            'status'    => 'error',
            'message'   => 'Originalbild wurde nicht gefunden.',
            'timestamp' => $timestamp(),
        ], 404);
    }

    $filename = basename(str_replace('\\', '/', $primaryPath));
    if ($filename === '') {
        $respond([
            'success'   => false,
            'status'    => 'error',
            'message'   => 'Dateiname des Originalbildes konnte nicht ermittelt werden.',
            'timestamp' => $timestamp(),
        ], 400);
    }

    $publicUrl = $buildAbsoluteUrl($primaryPath);
    if ($publicUrl === '') {
        $respond([
            'success'   => false,
            'status'    => 'error',
            'message'   => 'Öffentliche Bild-URL konnte nicht erzeugt werden.',
            'timestamp' => $timestamp(),
        ], 500);
    }

    $secondaryUrl = null;
    if (isset($imagePaths[1])) {
        $secondUrl = $buildAbsoluteUrl($imagePaths[1]);
        if ($secondUrl !== '') {
            $secondaryUrl = $secondUrl;
        }
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = $finfo !== false ? finfo_file($finfo, $storedFilePath) : null;
    if ($finfo !== false) {
        finfo_close($finfo);
    }

    $curlFile = new CURLFile($storedFilePath, $mimeType ?: 'application/octet-stream', $filename);
    $postFields = [
        'file'      => $curlFile,
        'user_id'   => $userId,
        'run_id'    => $runId,
        'image_url' => $publicUrl,
        'file_name' => $filename,
        'timestamp' => $timestamp(),
    ];

    if ($secondaryUrl !== null) {
        $postFields['image_url_2'] = $secondaryUrl;
    }

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

    $pdo->beginTransaction();
    try {
        $runMessage = 'Workflow gestartet';
        $updateRun = $pdo->prepare(
            'UPDATE workflow_runs SET status = :status, last_message = :last_message, started_at = COALESCE(started_at, NOW()) WHERE id = :run_id AND user_id = :user_id'
        );
        $updateRun->execute([
            ':status'       => 'running',
            ':last_message' => $runMessage,
            ':run_id'       => $runId,
            ':user_id'      => $userId,
        ]);

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
    } catch (Throwable $transactionException) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $transactionException;
    }

    $respond([
        'success'          => true,
        'status'           => 'ok',
        'message'          => 'Workflow gestartet.',
        'run_id'           => $runId,
        'user_id'          => $userId,
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
