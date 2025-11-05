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

function jsonResponse(int $statusCode, array $payload): never
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ensureAuthorized(array $config): void
{
    $apiToken = $config['receiver_api_token'] ?? '';
    if (!is_string($apiToken) || $apiToken === '') {
        jsonResponse(500, [
            'ok'      => false,
            'message' => 'API-Token ist nicht konfiguriert.',
        ]);
    }

    $headerCandidates = [
        $_SERVER['HTTP_AUTHORIZATION'] ?? null,
        $_SERVER['Authorization'] ?? null,
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null,
    ];

    $authorizationHeader = null;
    foreach ($headerCandidates as $candidate) {
        if (is_string($candidate) && $candidate !== '') {
            $authorizationHeader = trim($candidate);
            break;
        }
    }

    if ($authorizationHeader === null && function_exists('getallheaders')) {
        foreach (getallheaders() as $key => $value) {
            if (!is_string($key) || strcasecmp($key, 'Authorization') !== 0) {
                continue;
            }
            if (is_string($value) && $value !== '') {
                $authorizationHeader = trim($value);
                break;
            }
        }
    }

    $providedToken = null;
    if (is_string($authorizationHeader) && stripos($authorizationHeader, 'Bearer ') === 0) {
        $token = trim(substr($authorizationHeader, 7));
        $providedToken = $token !== '' ? $token : null;
    }

    if ($providedToken === null) {
        $alternativeHeaders = [
            $_SERVER['HTTP_X_API_TOKEN'] ?? null,
            $_SERVER['HTTP_X_AUTHORIZATION'] ?? null,
        ];
        foreach ($alternativeHeaders as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                $providedToken = trim($candidate);
                break;
            }
        }
    }

    if ($providedToken === null && function_exists('getallheaders')) {
        foreach (getallheaders() as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            if (in_array(strtolower($key), ['x-api-token', 'x-authorization'], true) && is_string($value) && $value !== '') {
                $providedToken = trim($value);
                break;
            }
        }
    }

    if ($providedToken === null || !hash_equals($apiToken, $providedToken)) {
        header('WWW-Authenticate: Bearer');
        jsonResponse(401, [
            'ok'      => false,
            'message' => 'Ungültiger oder fehlender Bearer-Token.',
        ]);
    }

    $allowedIps = $config['receiver_api_allowed_ips'] ?? [];
    if (!is_array($allowedIps)) {
        $allowedIps = [];
    }

    if ($allowedIps !== []) {
        $remoteIp = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        if ($remoteIp === '' || !in_array($remoteIp, array_map('strval', $allowedIps), true)) {
            jsonResponse(403, [
                'ok'      => false,
                'message' => 'Zugriff für diese IP-Adresse nicht erlaubt.',
            ]);
        }
    }
}

function resolveUserId(): int
{
    if (isset($_POST['user_id']) && is_numeric($_POST['user_id'])) {
        $userId = (int) $_POST['user_id'];
        if ($userId > 0) {
            return $userId;
        }
    }

    if (isset($_GET['user_id']) && is_numeric($_GET['user_id'])) {
        $userId = (int) $_GET['user_id'];
        if ($userId > 0) {
            return $userId;
        }
    }

    if (isset($_SESSION['user']['id']) && is_numeric($_SESSION['user']['id'])) {
        $userId = (int) $_SESSION['user']['id'];
        if ($userId > 0) {
            return $userId;
        }
    }

    jsonResponse(401, [
        'ok'      => false,
        'message' => 'user_id missing',
        'logout'  => true,
    ]);

    return 0;
}

function normalizeNoteId(mixed $value): ?int
{
    if ($value === null) {
        return null;
    }

    if (is_array($value)) {
        return null;
    }

    $value = trim((string) $value);
    if ($value === '' || !ctype_digit($value)) {
        return null;
    }

    $noteId = (int) $value;

    return $noteId > 0 ? $noteId : null;
}

function mapUploadError(int $errorCode): string
{
    return match ($errorCode) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Die Datei überschreitet die zulässige Größe.',
        UPLOAD_ERR_PARTIAL => 'Die Datei wurde nur teilweise hochgeladen.',
        UPLOAD_ERR_NO_FILE => 'Es wurde keine Datei hochgeladen.',
        UPLOAD_ERR_NO_TMP_DIR => 'Temporäres Verzeichnis fehlt.',
        UPLOAD_ERR_CANT_WRITE => 'Datei konnte nicht gespeichert werden.',
        UPLOAD_ERR_EXTENSION => 'Upload durch PHP-Erweiterung gestoppt.',
        default => 'Unbekannter Upload-Fehler.',
    };
}

function normalizeRunId(mixed $value): ?int
{
    if ($value === null) {
        return null;
    }

    if (is_int($value) && $value > 0) {
        return $value;
    }

    if (is_string($value)) {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        if (ctype_digit($trimmed)) {
            $runId = (int) $trimmed;

            return $runId > 0 ? $runId : null;
        }
    }

    return null;
}

function runBelongsToUser(PDO $pdo, int $userId, int $runId): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM workflow_runs WHERE id = :run AND user_id = :user LIMIT 1');
    $stmt->execute([
        ':run'  => $runId,
        ':user' => $userId,
    ]);

    return $stmt->fetchColumn() !== false;
}

$storedFilePath = null;

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(405, [
            'ok'      => false,
            'message' => 'Methode nicht erlaubt.',
        ]);
    }

    $config = auth_config();
    ensureAuthorized($config);

    $contentType = $_SERVER['CONTENT_TYPE'] ?? ($_SERVER['HTTP_CONTENT_TYPE'] ?? '');
    if (!is_string($contentType) || stripos($contentType, 'multipart/form-data') !== 0) {
        jsonResponse(415, [
            'ok'      => false,
            'message' => 'Nur multipart/form-data wird unterstützt.',
        ]);
    }

    $userId = resolveUserId();

    if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
        jsonResponse(400, [
            'ok'      => false,
            'message' => 'Feld "file" fehlt oder ist ungültig.',
        ]);
    }

    $upload = $_FILES['file'];
    $error = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        jsonResponse(400, [
            'ok'      => false,
            'message' => mapUploadError($error),
        ]);
    }

    $tmpPath = $upload['tmp_name'] ?? '';
    if (!is_string($tmpPath) || $tmpPath === '' || !is_uploaded_file($tmpPath)) {
        jsonResponse(400, [
            'ok'      => false,
            'message' => 'Ungültiger Upload-Pfad.',
        ]);
    }

    $maxFileSize = 15 * 1024 * 1024; // 15 MB
    $size = (int) ($upload['size'] ?? 0);
    if ($size <= 0) {
        jsonResponse(400, [
            'ok'      => false,
            'message' => 'Die hochgeladene Datei ist leer.',
        ]);
    }

    if ($size > $maxFileSize) {
        jsonResponse(413, [
            'ok'      => false,
            'message' => 'Die Datei überschreitet die maximale Größe von 15 MB.',
        ]);
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = $finfo !== false ? (finfo_file($finfo, $tmpPath) ?: '') : '';
    if ($finfo !== false) {
        finfo_close($finfo);
    }

    $allowedMimeTypes = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];

    if (!is_string($mimeType) || !isset($allowedMimeTypes[$mimeType])) {
        jsonResponse(415, [
            'ok'      => false,
            'message' => 'Der Dateityp wird nicht unterstützt.',
        ]);
    }

    $imageInfo = @getimagesize($tmpPath);
    if ($imageInfo === false) {
        jsonResponse(415, [
            'ok'      => false,
            'message' => 'Die Datei ist kein gültiges Bild.',
        ]);
    }

    $width = (int) ($imageInfo[0] ?? 0);
    $height = (int) ($imageInfo[1] ?? 0);
    if ($width <= 0 || $height <= 0) {
        jsonResponse(415, [
            'ok'      => false,
            'message' => 'Bildabmessungen konnten nicht ermittelt werden.',
        ]);
    }

    if ((float) $width * (float) $height > 12_000_000) {
        jsonResponse(413, [
            'ok'      => false,
            'message' => 'Bildauflösung zu hoch (maximal 12 Megapixel).',
        ]);
    }

    $pdo = auth_pdo();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stateStmt = $pdo->prepare('SELECT current_run_id FROM user_state WHERE user_id = :user LIMIT 1');
    $stateStmt->execute([':user' => $userId]);
    $stateRow = $stateStmt->fetch(PDO::FETCH_ASSOC);
    $currentRunId = null;
    if ($stateRow !== false && $stateRow['current_run_id'] !== null) {
        $currentRunId = (int) $stateRow['current_run_id'];
    }

    $requestedRunId = normalizeRunId($_POST['run_id'] ?? ($_GET['run_id'] ?? null));
    $runId = $requestedRunId;
    if ($runId !== null && !runBelongsToUser($pdo, $userId, $runId)) {
        $runId = null;
    }

    if ($runId === null && $currentRunId !== null) {
        $runId = $currentRunId;
    }

    $noteId = normalizeNoteId($_POST['note_id'] ?? null);
    if ($noteId !== null) {
        $stmt = $pdo->prepare('SELECT id, run_id FROM item_notes WHERE id = :id AND user_id = :user LIMIT 1');
        $stmt->execute([
            ':id'   => $noteId,
            ':user' => $userId,
        ]);
        $noteRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($noteRow === false) {
            jsonResponse(400, [
                'ok'      => false,
                'message' => 'note_id ist ungültig oder gehört nicht zum Benutzer.',
            ]);
        }

        $noteId = (int) $noteRow['id'];
        if (isset($noteRow['run_id']) && $noteRow['run_id'] !== null) {
            $runId = (int) $noteRow['run_id'];
        }
    } else {
        if ($runId !== null) {
            $stmt = $pdo->prepare('SELECT id FROM item_notes WHERE user_id = :user AND run_id = :run ORDER BY created_at DESC, id DESC LIMIT 1');
            $stmt->execute([
                ':user' => $userId,
                ':run'  => $runId,
            ]);
            $latest = $stmt->fetchColumn();
            if ($latest !== false) {
                $noteId = (int) $latest;
            }
        }

        if ($noteId === null) {
            $stmt = $pdo->prepare('SELECT id, run_id FROM item_notes WHERE user_id = :user ORDER BY created_at DESC LIMIT 1');
            $stmt->execute([':user' => $userId]);
            $latestRow = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($latestRow !== false) {
                $noteId = (int) $latestRow['id'];
                if ($runId === null && isset($latestRow['run_id']) && $latestRow['run_id'] !== null) {
                    $runId = (int) $latestRow['run_id'];
                }
            }
        }
    }

    $extension = $allowedMimeTypes[$mimeType];
    if ($runId === null) {
        jsonResponse(400, [
            'ok'      => false,
            'message' => 'run_id missing',
        ]);
    }

    $runId = (int) $runId;
    $shouldUpdateState = $currentRunId === null || $currentRunId === $runId;

    $uploadDirConfig = $config['upload_dir'] ?? (__DIR__ . '/uploads/');
    $baseUploadDir = rtrim((string) $uploadDirConfig, " \\t\n\r\0\x0B/\\");
    if ($baseUploadDir === '') {
        $baseUploadDir = __DIR__ . '/uploads';
    }

    $targetDirectory = sprintf('%s/%d/%d', $baseUploadDir, $userId, $runId);

    if (!is_dir($targetDirectory)) {
        if (!mkdir($targetDirectory, 0755, true) && !is_dir($targetDirectory)) {
            jsonResponse(500, [
                'ok'      => false,
                'message' => 'Upload-Verzeichnis konnte nicht erstellt werden.',
            ]);
        }
    }

    $filename = sprintf('ts%d_%s.%s', time(), bin2hex(random_bytes(4)), $extension);
    $targetPath = $targetDirectory . '/' . $filename;

    if (!move_uploaded_file($tmpPath, $targetPath)) {
        jsonResponse(500, [
            'ok'      => false,
            'message' => 'Upload konnte nicht gespeichert werden.',
        ]);
    }

    $storedFilePath = $targetPath;

    @chmod($targetPath, 0644);

    $relativeUrl = sprintf('/uploads/%d/%d/%s', $userId, $runId, $filename);

    $positionInput = $_POST['position'] ?? null;
    $requestedPosition = null;
    if ($positionInput !== null && (is_string($positionInput) || is_numeric($positionInput))) {
        $positionValue = trim((string) $positionInput);
        if ($positionValue !== '' && ctype_digit($positionValue)) {
            $requestedPosition = (int) $positionValue;
            if ($requestedPosition <= 0) {
                $requestedPosition = null;
            }
        }
    }

    $pdo->beginTransaction();
    try {
        $position = $requestedPosition;
        if ($noteId !== null) {
            $stmt = $pdo->prepare('SELECT COALESCE(MAX(position), 0) + 1 FROM item_images WHERE note_id = :note');
            $stmt->execute([':note' => $noteId]);
            $calculated = $stmt->fetchColumn();
            $nextPosition = $calculated !== false ? (int) $calculated : 1;
            if ($nextPosition <= 0) {
                $nextPosition = 1;
            }

            if ($requestedPosition === null) {
                $position = $nextPosition;
            }
        }

        $insertImage = $pdo->prepare('INSERT INTO item_images (user_id, run_id, note_id, url, position, created_at) VALUES (:user, :run, :note, :url, :position, NOW())');
        $insertImage->execute([
            ':user'     => $userId,
            ':run'      => $runId,
            ':note'     => $noteId,
            ':url'      => $relativeUrl,
            ':position' => $position,
        ]);

        $insertLog = $pdo->prepare('INSERT INTO status_logs (user_id, run_id, level, status_code, message, source, created_at) VALUES (:user, :run, :level, :code, :message, :source, NOW())');
        $insertLog->execute([
            ':user'    => $userId,
            ':run'     => $runId,
            ':level'   => 'info',
            ':code'    => 200,
            ':message' => 'image received',
            ':source'  => 'webhook_image',
        ]);

        $stateSql = <<<'SQL'
INSERT INTO user_state (user_id, last_image_url, current_run_id, updated_at)
VALUES (:user, :image, :current_run_id, NOW())
ON DUPLICATE KEY UPDATE
    last_image_url = VALUES(last_image_url),
    current_run_id = IFNULL(VALUES(current_run_id), current_run_id),
    updated_at = NOW()
SQL;
        $updateState = $pdo->prepare($stateSql);
        $updateState->execute([
            ':user'            => $userId,
            ':image'           => $relativeUrl,
            ':current_run_id'  => $shouldUpdateState ? $runId : null,
        ]);

        $pdo->commit();
    } catch (Throwable $transactionException) {
        $pdo->rollBack();
        throw $transactionException;
    }

    jsonResponse(200, [
        'ok'       => true,
        'url'      => $relativeUrl,
        'note_id'  => $noteId,
        'position' => $requestedPosition ?? $position ?? null,
        'run_id'   => $runId,
    ]);
} catch (Throwable $exception) {
    if (is_string($storedFilePath) && $storedFilePath !== '' && file_exists($storedFilePath)) {
        @unlink($storedFilePath);
    }
    error_log('[webhook_image] ' . $exception->getMessage());
    jsonResponse(500, [
        'ok'      => false,
        'message' => 'Interner Serverfehler.',
    ]);
}
