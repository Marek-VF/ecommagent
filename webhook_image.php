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

function resolveUserId(): ?int
{
    if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
        $userId = $_SESSION['user']['id'] ?? null;
        if ($userId !== null && is_numeric($userId)) {
            $userId = (int) $userId;
            if ($userId > 0) {
                return $userId;
            }
        }
    }

    if (isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id'])) {
        $userId = (int) $_SESSION['user_id'];
        if ($userId > 0) {
            return $userId;
        }
    }

    return 1;
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

    $noteId = normalizeNoteId($_POST['note_id'] ?? null);
    if ($noteId !== null) {
        $stmt = $pdo->prepare('SELECT id FROM item_notes WHERE id = :id AND user_id = :user LIMIT 1');
        $stmt->execute([
            ':id'   => $noteId,
            ':user' => $userId,
        ]);
        $found = $stmt->fetchColumn();
        if ($found === false) {
            jsonResponse(400, [
                'ok'      => false,
                'message' => 'note_id ist ungültig oder gehört nicht zum Benutzer.',
            ]);
        }
        $noteId = (int) $found;
    } else {
        $stmt = $pdo->prepare('SELECT id FROM item_notes WHERE user_id = :user ORDER BY created_at DESC LIMIT 1');
        $stmt->execute([':user' => $userId]);
        $latest = $stmt->fetchColumn();
        $noteId = $latest !== false ? (int) $latest : null;
    }

    $extension = $allowedMimeTypes[$mimeType];
    $now = new DateTimeImmutable('now');
    $year = $now->format('Y');
    $month = $now->format('m');

    $baseUploadDir = rtrim(__DIR__ . '/uploads', '/');
    $targetDirectory = sprintf('%s/%d/%s/%s', $baseUploadDir, $userId ?? 0, $year, $month);

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

    $relativeUrl = sprintf('/uploads/%d/%s/%s/%s', $userId ?? 0, $year, $month, $filename);

    $position = null;

    $pdo->beginTransaction();
    try {
        if ($noteId !== null) {
            $stmt = $pdo->prepare('SELECT COALESCE(MAX(position), 0) + 1 FROM item_images WHERE note_id = :note');
            $stmt->execute([':note' => $noteId]);
            $position = $stmt->fetchColumn();
            $position = $position !== false ? (int) $position : 1;

            $insertImage = $pdo->prepare('INSERT INTO item_images (user_id, note_id, url, position) VALUES (:user, :note, :url, :position)');
            $insertImage->execute([
                ':user'     => $userId,
                ':note'     => $noteId,
                ':url'      => $relativeUrl,
                ':position' => $position,
            ]);
        }

        $insertLog = $pdo->prepare('INSERT INTO status_logs (user_id, level, status_code, message, source) VALUES (:user, :level, :code, :message, :source)');
        $insertLog->execute([
            ':user'    => $userId,
            ':level'   => 'info',
            ':code'    => 200,
            ':message' => 'image received',
            ':source'  => 'webhook_image',
        ]);

        $stateSql = <<<'SQL'
INSERT INTO user_state (user_id, last_status, last_message, last_image_url, updated_at)
VALUES (:user, :status, :message, :image, NOW())
ON DUPLICATE KEY UPDATE
    last_status = VALUES(last_status),
    last_message = VALUES(last_message),
    last_image_url = VALUES(last_image_url),
    updated_at = NOW()
SQL;
        $updateState = $pdo->prepare($stateSql);
        $updateState->execute([
            ':user'    => $userId,
            ':status'  => 'ok',
            ':message' => 'Upload gespeichert',
            ':image'   => $relativeUrl,
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
        'position' => $position,
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
