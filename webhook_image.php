<?php
declare(strict_types=1);

require_once __DIR__ . '/auth/bootstrap.php';
require_once __DIR__ . '/status_logger.php';
require_once __DIR__ . '/credits.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }

    throw new ErrorException($message, 0, $severity, $file, $line);
});

function slugify_filename(string $name): string
{
    $normalized = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
    if ($normalized === false) {
        $normalized = $name;
    }

    $normalized = strtolower($normalized);
    $normalized = strtr($normalized, [
        'ä' => 'ae',
        'ö' => 'oe',
        'ü' => 'ue',
        'ß' => 'ss',
    ]);

    $normalized = preg_replace('/[^a-z0-9\-\s]+/u', ' ', $normalized ?? '');
    $normalized = preg_replace('/[\s]+/', '-', $normalized ?? '');
    $normalized = preg_replace('/-{2,}/', '-', $normalized ?? '');
    $normalized = trim($normalized ?? '', '-');

    if ($normalized === '') {
        $normalized = 'image';
    }

    return substr($normalized, 0, 80);
}

function jsonResponse(int $statusCode, array $payload): never
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ensureAuthorized(array $config): void
{
    $apiToken = trim((string)($config['receiver_api_token'] ?? ''));
    if ($apiToken === '') {
        jsonResponse(500, [
            'ok'      => false,
            'message' => 'API-Token ist nicht konfiguriert.',
        ]);
    }

    $authorizationHeader = null;

    if (isset($_SERVER['HTTP_AUTHORIZATION']) && is_string($_SERVER['HTTP_AUTHORIZATION'])) {
        $authorizationHeader = trim($_SERVER['HTTP_AUTHORIZATION']);
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

    if (!is_string($authorizationHeader) || stripos($authorizationHeader, 'Bearer ') !== 0) {
        header('WWW-Authenticate: Bearer');
        jsonResponse(401, [
            'ok'      => false,
            'message' => 'Ungültiger oder fehlender Bearer-Token.',
        ]);
    }

    $providedToken = trim(substr($authorizationHeader, 7));
    if ($providedToken === '' || !hash_equals($apiToken, $providedToken)) {
        header('WWW-Authenticate: Bearer');
        jsonResponse(401, [
            'ok'      => false,
            'message' => 'Ungültiger oder fehlender Bearer-Token.',
        ]);
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

function toBooleanFlag(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    if (is_int($value) || is_float($value)) {
        return (int) $value !== 0;
    }

    if (is_string($value)) {
        $normalized = strtolower(trim($value));

        return in_array($normalized, ['1', 'true', 'yes', 'ja', 'on'], true);
    }

    return false;
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
$statusMessageFromRequest = extract_status_message($_POST);
$statusEventFromRequest = $statusMessageFromRequest !== null ? resolve_status_event($statusMessageFromRequest) : null;
$executedSuccessfully = false;
$stepType = null;
$stepTypeRaw = null;
$runIdValue = null;

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(405, [
            'ok'      => false,
            'message' => 'Methode nicht erlaubt.',
        ]);
    }

    $config = auth_config();
    ensureAuthorized($config);

    // Form-Data Felder explizit auslesen, um sie später für die Kreditabbuchung zu verwenden
    $executedRaw = $_POST['executed_successfully'] ?? null;
    if ($executedRaw !== null) {
        $executedSuccessfully = in_array($executedRaw, ['true', '1', 1, true], true);
    }

    $badge = null;
    if (array_key_exists('badge', $_POST) && !is_array($_POST['badge'])) {
        $badgeCandidate = trim((string) $_POST['badge']);
        if ($badgeCandidate !== '') {
            if (mb_strlen($badgeCandidate) > 10) {
                jsonResponse(400, [
                    'ok'      => false,
                    'message' => 'badge ist zu lang (maximal 10 Zeichen).',
                ]);
            }

            $badge = $badgeCandidate;
        }
    }

    $stepTypeRaw = $_POST['step_type'] ?? null;
    if (is_string($stepTypeRaw) || is_numeric($stepTypeRaw)) {
        $stepType = trim((string) $stepTypeRaw);
        if ($stepType === '') {
            $stepType = null;
        }
    }

    $isRunningInput = $_POST['isrunning'] ?? $_POST['is_running'] ?? null;

    $userId = resolveUserId();

    $userIdFromPost = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
    if ($userIdFromPost > 0) {
        $userId = $userIdFromPost;
    }

    $runIdValue = normalizeRunId($_POST['run_id'] ?? null);
    $runId = $runIdValue !== null ? (int) $runIdValue : null;

    $pdo = auth_pdo();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if ($runIdValue === null) {
        jsonResponse(400, [
            'ok'      => false,
            'message' => 'run_id required',
        ]);
    }

    if ($runId !== null && !runBelongsToUser($pdo, $userId, $runId)) {
        jsonResponse(404, [
            'ok'      => false,
            'message' => 'run_id does not belong to user',
        ]);
    }

    if ($executedSuccessfully === false) {
        // 1. Logging
        $serverMessage = isset($_POST['server_api_message']) ? trim((string) $_POST['server_api_message']) : 'Ein Fehler ist aufgetreten.';

        $logStmt = $pdo->prepare(
            'INSERT INTO status_logs_new (run_id, user_id, message, source, severity, code, created_at)
             VALUES (:run_id, :user_id, :message, :source, :severity, :code, NOW())'
        );
        $logStmt->execute([
            ':run_id'   => $runId,
            ':user_id'  => $userId,
            ':message'  => $serverMessage,
            ':source'   => 'n8n',
            ':severity' => 'error',
            ':code'     => 'WORKFLOW_ERROR'
        ]);

        // 2. Status Update (Zombie-Prevention)
        if ($isRunningInput !== null && toBooleanFlag($isRunningInput) === false) {
            $finishStmt = $pdo->prepare(
                "UPDATE workflow_runs SET status = 'failed', last_step_status = 'error', last_message = :message, finished_at = NOW() WHERE id = :run_id AND user_id = :user_id"
            );
            $finishStmt->execute([
                ':run_id'  => $runId,
                ':user_id' => $userId,
                ':message' => $serverMessage, // Wir nutzen die oben definierte Variable $serverMessage
            ]);
        }

        // 3. Weiche: Update vs. Main Run
        // Badge wurde oben im Skript bereits aus $_POST['badge'] gelesen
        $isUpdate = in_array(strtolower((string) $badge), ['edit', '2k', '4k']);

        if ($isUpdate) {
            // Fall A: Update (Edit/Upscale)
            // Wir speichern NICHTS, damit das alte Bild im Frontend stehen bleibt.
            jsonResponse(200, ['ok' => true]);
        } else {
            // Fall B: Haupt-Run (Initial)
            // Wir MÜSSEN einen Platzhalter speichern.

            // 1. Note ID ermitteln (lokal für diesen Block, da globale Variable noch nicht existiert)
            $localNoteId = null;
            $noteIdInput = normalizeNoteId($_POST['note_id'] ?? null);

            if ($noteIdInput !== null) {
                // Check ob übergebene Note ID existiert
                $noteCheck = $pdo->prepare('SELECT id FROM item_notes WHERE id = :id AND user_id = :user AND run_id = :run LIMIT 1');
                $noteCheck->execute([':id' => $noteIdInput, ':user' => $userId, ':run' => $runId]);
                $localNoteId = $noteCheck->fetchColumn();
            }

            if (!$localNoteId) {
                // Fallback: Existierende Note suchen oder neue anlegen
                $existingNote = $pdo->prepare('SELECT id FROM item_notes WHERE user_id = :user AND run_id = :run ORDER BY id ASC LIMIT 1');
                $existingNote->execute([':user' => $userId, ':run' => $runId]);
                $localNoteId = $existingNote->fetchColumn();

                if (!$localNoteId) {
                    $createNote = $pdo->prepare("INSERT INTO item_notes (user_id, run_id, product_name, product_description, source) VALUES (:user, :run, '', '', 'n8n')");
                    $createNote->execute([':user' => $userId, ':run' => $runId]);
                    $localNoteId = (int)$pdo->lastInsertId();
                }
            }

            // 2. Position ermitteln
            $finalPosition = 1;
            $posInput = $_POST['position'] ?? null;

            if ($posInput !== null && ctype_digit((string)$posInput)) {
                $finalPosition = (int)$posInput;
            } else {
                // Automatisch nächste Position berechnen
                $stmt = $pdo->prepare('SELECT COALESCE(MAX(position), 0) + 1 FROM item_images WHERE note_id = :note');
                $stmt->execute([':note' => $localNoteId]);
                $calcPos = (int) $stmt->fetchColumn();
                $finalPosition = $calcPos > 0 ? $calcPos : 1;
            }

            // 3. Insert mit den ermittelten Werten
            $insertErrorImg = $pdo->prepare("INSERT INTO item_images (user_id, run_id, note_id, url, position, badge, created_at) VALUES (:user, :run, :note, :url, :position, 'error', NOW())");
            $insertErrorImg->execute([
                ':user'     => $userId,
                ':run'      => $runId,
                ':note'     => $localNoteId,
                ':url'      => 'assets/default-image1.jpg', // Platzhalterpfad
                ':position' => $finalPosition
            ]);

            jsonResponse(200, ['ok' => true]);
        }
    }
    $contentType = $_SERVER['CONTENT_TYPE'] ?? ($_SERVER['HTTP_CONTENT_TYPE'] ?? '');
    if (!is_string($contentType) || stripos($contentType, 'multipart/form-data') !== 0) {
        jsonResponse(415, [
            'ok'      => false,
            'message' => 'Nur multipart/form-data wird unterstützt.',
        ]);
    }

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

    if ((float) $width * (float) $height > 24_000_000) {
        jsonResponse(413, [
            'ok'      => false,
            'message' => 'Bildauflösung zu hoch (maximal 24 Megapixel).',
        ]);
    }

    $noteIdInput = normalizeNoteId($_POST['note_id'] ?? null);
    $noteId = null;
    $productName = '';

    if ($noteIdInput !== null) {
        $noteCheck = $pdo->prepare('SELECT id, product_name FROM item_notes WHERE id = :id AND user_id = :user AND run_id = :run LIMIT 1');
        $noteCheck->execute([
            ':id'   => $noteIdInput,
            ':user' => $userId,
            ':run'  => $runId,
        ]);
        $noteRow = $noteCheck->fetch(PDO::FETCH_ASSOC);
        if ($noteRow === false) {
            jsonResponse(400, [
                'ok'      => false,
                'message' => 'note_id ist ungültig oder gehört nicht zum Run.',
            ]);
        }

        $noteId = (int) $noteRow['id'];
        $productName = isset($noteRow['product_name']) ? (string) $noteRow['product_name'] : '';
    } else {
        $existingNote = $pdo->prepare('SELECT id, product_name FROM item_notes WHERE user_id = :user AND run_id = :run ORDER BY id ASC LIMIT 1');
        $existingNote->execute([
            ':user' => $userId,
            ':run'  => $runId,
        ]);
        $existingNoteRow = $existingNote->fetch(PDO::FETCH_ASSOC);

        if ($existingNoteRow !== false) {
            $noteId = (int) $existingNoteRow['id'];
            $productName = isset($existingNoteRow['product_name']) ? (string) $existingNoteRow['product_name'] : '';
        } else {
            $createNote = $pdo->prepare("INSERT INTO item_notes (user_id, run_id, product_name, product_description, source) VALUES (:user, :run, '', '', 'n8n')");
            $createNote->execute([
                ':user' => $userId,
                ':run'  => $runId,
            ]);
            $noteId = (int) $pdo->lastInsertId();
        }
    }

    if ($noteId === null || $noteId <= 0) {
        jsonResponse(500, [
            'ok'      => false,
            'message' => 'note could not be determined.',
        ]);
    }

    $extension = $allowedMimeTypes[$mimeType];

    $uploadDirConfig = $config['upload_dir'] ?? (__DIR__ . '/uploads/');
    $baseUploadDir = rtrim((string) $uploadDirConfig, " \\t\n\r\0\x0B/\\");
    if ($baseUploadDir === '') {
        $baseUploadDir = __DIR__ . '/uploads';
    }

    $targetDirectory = sprintf('%s/%d/%d', $baseUploadDir, $userId, $runId);

    if (!is_dir($targetDirectory)) {
        if (!mkdir($targetDirectory, 0775, true) && !is_dir($targetDirectory)) {
            jsonResponse(500, [
                'ok'      => false,
                'message' => 'Upload-Verzeichnis konnte nicht erstellt werden.',
            ]);
        }
    }

    $sluggedName = trim($productName) !== '' ? slugify_filename($productName) : null;
    if ($sluggedName !== null) {
        // Dateiname aus item_notes.product_name generieren und für gespeichertes Bild verwenden
        $filename = sprintf('%s-%d-%d-%s.%s', $sluggedName, $runId, $userId, bin2hex(random_bytes(3)), $extension);
    } else {
        $filename = sprintf('ts%d_%s.%s', time(), bin2hex(random_bytes(4)), $extension);
    }

    $targetPath = $targetDirectory . '/' . $filename;

    if (!move_uploaded_file($tmpPath, $targetPath)) {
        jsonResponse(500, [
            'ok'      => false,
            'message' => 'Upload konnte nicht gespeichert werden.',
        ]);
    }

    $storedFilePath = $targetPath;

    @chmod($targetPath, 0644);

    $relativeUrl = sprintf('uploads/%d/%d/%s', $userId, $runId, $filename);

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

        $targetTable = 'item_images';
        if (is_string($badge) && strcasecmp($badge, 'edit') === 0) {
            $targetTable = 'item_images_staging';
        }

        $insertImage = $pdo->prepare("INSERT INTO {$targetTable} (user_id, run_id, note_id, url, position, badge, created_at) VALUES (:user, :run, :note, :url, :position, :badge, NOW())");
        $insertImage->execute([
            ':user'     => $userId,
            ':run'      => $runId,
            ':note'     => $noteId,
            ':url'      => $relativeUrl,
            ':position' => $position,
            ':badge'    => $badge,
        ]);

        $statusCode = $statusMessageFromRequest;
        if ($statusCode === null && $position !== null && $position >= 1 && $position <= 4) {
            $statusCode = 'IMAGE_SLOT_' . (int) $position;
        }

        $eventForLogging = $statusEventFromRequest ?? resolve_status_event($statusCode ?? 'IMAGE_SLOT_1');
        $loggedMessage = $eventForLogging['label'] ?? 'image received';
        // Neues Statuslog-System: Speichert jede eingehende statusmeldung.
        log_event($pdo, $runId, $userId, $eventForLogging['code'] ?? 'IMAGE_SLOT_1', 'n8n');

        if ($loggedMessage !== '') {
            $updateRunMessage = $pdo->prepare(
                'UPDATE workflow_runs SET last_message = :message WHERE id = :run_id AND user_id = :user_id'
            );
            $updateRunMessage->execute([
                ':message' => $loggedMessage,
                ':run_id'  => $runId,
                ':user_id' => $userId,
            ]);
        }

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
            ':current_run_id'  => $runId,
        ]);

        $pdo->commit();
    } catch (Throwable $transactionException) {
        $pdo->rollBack();
        throw $transactionException;
    }

    $shouldFinish = $executedSuccessfully
        && $isRunningInput !== null
        && toBooleanFlag($isRunningInput) === false;

    if ($shouldFinish && $runId !== null && $userId > 0) {
        $finishRun = $pdo->prepare(
            "UPDATE workflow_runs SET status = 'finished', last_step_status = :status, finished_at = NOW() WHERE id = :run_id AND user_id = :user_id"
        );
        $finishRun->execute([
            ':status'  => 'success',
            ':run_id'  => $runId,
            ':user_id' => $userId,
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
            ':user_id'         => $userId,
            ':current_run_id'  => $runId,
            ':last_status'     => 'finished',
            ':last_message'    => $loggedMessage ?? '',
        ]);
    } elseif ($executedSuccessfully && $runId !== null && $userId > 0) {
        $updateStepStatus = $pdo->prepare(
            'UPDATE workflow_runs SET last_step_status = :status WHERE id = :run_id AND user_id = :user_id'
        );
        $updateStepStatus->execute([
            ':status'  => 'success',
            ':run_id'  => $runId,
            ':user_id' => $userId,
        ]);
    }

    if ($executedSuccessfully && $stepType !== null && $stepTypeRaw !== null && $userId > 0 && $runIdValue !== null) {
        // Credits für erfolgreich generiertes Bild gemäß step_type aus Form-Data abbuchen
        try {
            $meta = [
                'source'    => 'webhook_image.php',
                'image_url' => $relativeUrl,
                'step_type' => $stepTypeRaw,
            ];
            charge_credits($pdo, $config, $userId, $runId, $stepTypeRaw, $meta);
        } catch (Throwable $creditException) {
            error_log('[webhook_image][credits] ' . $creditException->getMessage());
        }
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
