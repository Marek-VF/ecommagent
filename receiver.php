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

function sanitizeString(?string $value, ?int $maxLength = null): ?string
{
    if ($value === null) {
        return null;
    }

    $trimmed = trim($value);
    if ($trimmed === '') {
        return null;
    }

    if ($maxLength !== null) {
        $trimmed = mb_substr($trimmed, 0, $maxLength);
    }

    return $trimmed;
}

function extractSanitizedField(array $payload, array $keys, ?int $maxLength = null): ?string
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $payload)) {
            continue;
        }

        $value = $payload[$key];
        if (!is_string($value)) {
            continue;
        }

        $sanitized = sanitizeString($value, $maxLength);
        if ($sanitized !== null) {
            return $sanitized;
        }
    }

    return null;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(405, [
            'ok'      => false,
            'message' => 'Methode nicht erlaubt.',
        ]);
    }

    $config = auth_config();
    ensureAuthorized($config);

    $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
    if (!str_contains(strtolower($contentType), 'application/json')) {
        jsonResponse(415, [
            'ok'      => false,
            'message' => 'Nur application/json wird unterstützt.',
        ]);
    }

    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        jsonResponse(400, [
            'ok'      => false,
            'message' => 'Leerer Request-Body.',
        ]);
    }

    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        jsonResponse(400, [
            'ok'      => false,
            'message' => 'JSON konnte nicht gelesen werden.',
        ]);
    }

    $productName = extractSanitizedField($payload, ['product_name', 'produktname'], 255);
    $productDescription = extractSanitizedField($payload, ['product_description', 'produktbeschreibung']);
    $statusMessage = extractSanitizedField($payload, ['statusmessage', 'status_message', 'status'], 255);
    $isRunningField = $payload['isrunning'] ?? $payload['is_running'] ?? null;

    $pdo = auth_pdo();
    $userId = resolveUserId();
    if ($userId === null || $userId <= 0) {
        jsonResponse(401, [
            'ok'      => false,
            'message' => 'Kein Benutzer ermittelt.',
        ]);
    }

    if ($isRunningField !== null) {
        $isRunning = filter_var($isRunningField, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($isRunning === null) {
            $stringValue = is_string($isRunningField) ? strtolower(trim($isRunningField)) : '';
            if ($stringValue === 'false' || $stringValue === '0') {
                $isRunning = false;
            } else {
                $isRunning = true;
            }
        }

        $lastStatus = $isRunning ? 'running' : 'finished';
        $lastMessage = $isRunning ? 'Workflow läuft…' : 'Workflow abgeschlossen.';

        $statement = $pdo->prepare(
            'INSERT INTO user_state (user_id, last_status, last_message, last_payload_summary, updated_at)
             VALUES (:user_id, :last_status, :last_message, :last_payload_summary, NOW())
             ON DUPLICATE KEY UPDATE
                 last_status = VALUES(last_status),
                 last_message = VALUES(last_message),
                 last_payload_summary = VALUES(last_payload_summary),
                 updated_at = NOW()'
        );

        $statement->execute([
            ':user_id'              => $userId,
            ':last_status'          => $lastStatus,
            ':last_message'         => $lastMessage,
            ':last_payload_summary' => $raw,
        ]);

        jsonResponse(200, [
            'ok'        => true,
            'message'   => 'state updated',
            'isrunning' => $isRunning,
        ]);
    }

    $hasContentPayload = $productName !== null || $productDescription !== null || $statusMessage !== null;

    if ($hasContentPayload) {
        $pdo->beginTransaction();

        $noteId = null;
        if ($productName !== null || $productDescription !== null) {
            $insertNote = $pdo->prepare(
                'INSERT INTO item_notes (user_id, product_name, product_description, source, created_at)
                 VALUES (:user_id, :product_name, :product_description, :source, NOW())'
            );
            $insertNote->execute([
                ':user_id'             => $userId,
                ':product_name'        => $productName,
                ':product_description' => $productDescription,
                ':source'              => 'n8n',
            ]);
            $noteId = (int) $pdo->lastInsertId();
        }

        $summaryPayload = [
            'product_name'               => $productName,
            'product_description_length' => $productDescription !== null ? mb_strlen($productDescription) : 0,
            'status_message_length'      => $statusMessage !== null ? mb_strlen($statusMessage) : 0,
            'note_id'                    => $noteId,
        ];

        $payloadSummary = json_encode($summaryPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null;
        $lastMessage = $statusMessage ?? 'Daten empfangen.';

        $upsertState = $pdo->prepare(
            'INSERT INTO user_state (user_id, last_status, last_message, last_payload_summary, updated_at)
             VALUES (:user_id, :last_status, :last_message, :last_payload_summary, NOW())
             ON DUPLICATE KEY UPDATE
                 last_status = VALUES(last_status),
                 last_message = VALUES(last_message),
                 last_payload_summary = VALUES(last_payload_summary),
                 updated_at = NOW()'
        );
        $upsertState->execute([
            ':user_id'              => $userId,
            ':last_status'          => 'running',
            ':last_message'         => $lastMessage,
            ':last_payload_summary' => $payloadSummary,
        ]);

        $pdo->commit();

        jsonResponse(200, [
            'ok'      => true,
            'message' => 'content saved',
        ]);
    }

    jsonResponse(200, [
        'ok'      => true,
        'message' => 'payload without content ignored',
    ]);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $statusCode = $exception instanceof RuntimeException ? 400 : 500;
    if ($exception instanceof PDOException) {
        $statusCode = 500;
    }

    jsonResponse($statusCode, [
        'ok'      => false,
        'message' => $exception->getMessage(),
    ]);
} finally {
    restore_error_handler();
}
