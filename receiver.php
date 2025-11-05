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

function resolveUserId(array $payload): int
{
    if (isset($payload['user_id']) && is_numeric($payload['user_id'])) {
        $userId = (int) $payload['user_id'];
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
        'message' => 'user_id missing in payload and no session',
        'logout'  => true,
    ]);

    return 0;
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

function normalizeStatusLabel(?string $value): string
{
    if ($value === null) {
        return 'idle';
    }

    $normalized = strtolower(trim($value));
    if ($normalized === '') {
        return 'idle';
    }

    $directMatches = ['running', 'finished', 'idle', 'error'];
    if (in_array($normalized, $directMatches, true)) {
        return $normalized;
    }

    if ($normalized === 'true' || $normalized === '1') {
        return 'running';
    }

    if ($normalized === 'false' || $normalized === '0') {
        return 'finished';
    }

    if (
        str_contains($normalized, 'läuft') ||
        str_contains($normalized, 'processing') ||
        str_contains($normalized, 'in arbeit') ||
        str_contains($normalized, 'in bearbeitung') ||
        str_contains($normalized, 'bearbeitung') ||
        str_contains($normalized, 'working') ||
        str_contains($normalized, 'busy') ||
        str_contains($normalized, 'gestartet') ||
        str_contains($normalized, 'startet') ||
        str_contains($normalized, 'aktiv') ||
        str_contains($normalized, 'aktiviert')
    ) {
        return 'running';
    }

    if (
        str_contains($normalized, 'error') ||
        str_contains($normalized, 'fehler') ||
        str_contains($normalized, 'fail') ||
        str_contains($normalized, 'fatal') ||
        str_contains($normalized, 'exception') ||
        str_contains($normalized, 'abgebrochen') ||
        str_contains($normalized, 'abbruch')
    ) {
        return 'error';
    }

    if (
        str_contains($normalized, 'fertig') ||
        str_contains($normalized, 'abgeschlossen') ||
        str_contains($normalized, 'done') ||
        str_contains($normalized, 'complete') ||
        str_contains($normalized, 'success') ||
        str_contains($normalized, 'erfolg') ||
        str_contains($normalized, 'erledigt') ||
        str_contains($normalized, 'stop')
    ) {
        return 'finished';
    }

    if (
        str_contains($normalized, 'idle') ||
        str_contains($normalized, 'bereit') ||
        str_contains($normalized, 'warten') ||
        str_contains($normalized, 'wartet') ||
        str_contains($normalized, 'wartend') ||
        str_contains($normalized, 'pending') ||
        str_contains($normalized, 'warte') ||
        str_contains($normalized, 'warteschlange') ||
        str_contains($normalized, 'queued') ||
        str_contains($normalized, 'pause') ||
        str_contains($normalized, 'pausiert') ||
        str_contains($normalized, 'angehalten') ||
        str_contains($normalized, 'hold')
    ) {
        return 'idle';
    }

    return 'idle';
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
    $stmt = $pdo->prepare('SELECT 1 FROM workflow_runs WHERE id = :run_id AND user_id = :user_id LIMIT 1');
    $stmt->execute([
        ':run_id'  => $runId,
        ':user_id' => $userId,
    ]);

    return $stmt->fetchColumn() !== false;
}

function fetchStateRunId(PDO $pdo, int $userId): ?int
{
    $stmt = $pdo->prepare(
        "SELECT wr.id\n           FROM user_state us\n           JOIN workflow_runs wr ON wr.id = us.current_run_id\n          WHERE us.user_id = :user_id\n            AND wr.user_id = :user_id\n            AND wr.status = 'running'\n          LIMIT 1"
    );
    $stmt->execute([':user_id' => $userId]);
    $value = $stmt->fetchColumn();

    if ($value === false || $value === null) {
        return null;
    }

    return (int) $value;
}

function fetchLatestRunId(PDO $pdo, int $userId, ?string $status = null): ?int
{
    $sql = 'SELECT id FROM workflow_runs WHERE user_id = :user_id';
    $params = [':user_id' => $userId];

    if ($status !== null) {
        $sql .= ' AND status = :status';
        $params[':status'] = $status;
    }

    $sql .= ' ORDER BY started_at DESC, id DESC LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $value = $stmt->fetchColumn();

    if ($value === false || $value === null) {
        return null;
    }

    return (int) $value;
}

function findExistingRunId(PDO $pdo, int $userId, ?int $preferredRunId): ?int
{
    if ($preferredRunId !== null && runBelongsToUser($pdo, $userId, $preferredRunId)) {
        return $preferredRunId;
    }

    $stateRunId = fetchStateRunId($pdo, $userId);
    if ($stateRunId !== null) {
        return $stateRunId;
    }

    return fetchLatestRunId($pdo, $userId, 'running');
}

function resolveMessageFromPayload(array $payload, string $fallback): string
{
    $candidates = [
        $payload['message'] ?? null,
        $payload['status'] ?? null,
        $payload['status_message'] ?? null,
        $payload['statusMessage'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        if (!is_string($candidate)) {
            continue;
        }

        $sanitized = sanitizeString($candidate, 255);
        if ($sanitized !== null) {
            return $sanitized;
        }
    }

    return sanitizeString($fallback, 255) ?? 'aktualisiert';
}

function upsertUserState(PDO $pdo, int $userId, string $lastStatus, string $lastMessage, string $payloadSummary, ?int $currentRunId): void
{
    $statement = $pdo->prepare(
        'INSERT INTO user_state (user_id, last_status, last_message, last_payload_summary, current_run_id, updated_at)
         VALUES (?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE
             last_status = VALUES(last_status),
             last_message = VALUES(last_message),
             last_payload_summary = VALUES(last_payload_summary),
             current_run_id = VALUES(current_run_id),
             updated_at = NOW()'
    );

    $statement->execute([
        $userId,
        $lastStatus,
        $lastMessage,
        $payloadSummary,
        $currentRunId,
    ]);
}

function createWorkflowRun(PDO $pdo, int $userId, string $status, string $message): int
{
    $normalizedStatus = in_array($status, ['running', 'finished', 'error'], true) ? $status : 'running';
    $finishedExpression = $normalizedStatus === 'running' ? 'NULL' : 'NOW()';

    $sql = sprintf(
        'INSERT INTO workflow_runs (user_id, status, last_message, started_at, finished_at)
         VALUES (:user_id, :status, :last_message, NOW(), %s)',
        $finishedExpression
    );

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':user_id'      => $userId,
        ':status'       => $normalizedStatus,
        ':last_message' => $message,
    ]);

    return (int) $pdo->lastInsertId();
}

function updateWorkflowRun(PDO $pdo, int $userId, int $runId, string $status, string $message): void
{
    $normalizedStatus = in_array($status, ['running', 'finished', 'error'], true) ? $status : 'running';

    $sql = 'UPDATE workflow_runs SET status = :status, last_message = :last_message';
    if ($normalizedStatus === 'running') {
        $sql .= ', finished_at = NULL';
    } else {
        $sql .= ', finished_at = NOW()';
    }
    $sql .= ' WHERE id = :id AND user_id = :user_id';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':status'       => $normalizedStatus,
        ':last_message' => $message,
        ':id'           => $runId,
        ':user_id'      => $userId,
    ]);
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
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $userId = resolveUserId($payload);
    if ($userId <= 0) {
        jsonResponse(401, [
            'ok'      => false,
            'message' => 'Kein Benutzer ermittelt.',
            'logout'  => true,
        ]);
    }

    $payloadRunId = normalizeRunId($payload['run_id'] ?? null);
    $runId = findExistingRunId($pdo, $userId, $payloadRunId);
    $payloadSummary = mb_substr($raw, 0, 65535);

    $normalizedIsRunning = null;
    if ($isRunningField !== null) {
        $normalizedIsRunning = filter_var($isRunningField, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($normalizedIsRunning === null) {
            $stringValue = is_string($isRunningField) ? strtolower(trim($isRunningField)) : '';
            if ($stringValue === 'false' || $stringValue === '0') {
                $normalizedIsRunning = false;
            } else {
                $normalizedIsRunning = true;
            }
        }
    }

    $hasContentPayload = $productName !== null || $productDescription !== null || $statusMessage !== null;
    $handled = false;
    $responseData = null;

    if ($hasContentPayload) {
        $pdo->beginTransaction();

        $message = $statusMessage ?? 'Daten empfangen.';
        $targetRunId = $runId;
        $createdNewRun = false;

        if ($targetRunId === null) {
            $targetRunId = createWorkflowRun($pdo, $userId, 'running', $message);
            $createdNewRun = true;
        } else {
            updateWorkflowRun($pdo, $userId, $targetRunId, 'running', $message);
        }

        if ($productName !== null || $productDescription !== null) {
            $noteId = null;
            $selectNote = $pdo->prepare(
                'SELECT id FROM item_notes WHERE user_id = :user AND run_id = :run AND source = :source ORDER BY created_at DESC, id DESC LIMIT 1'
            );
            $selectNote->execute([
                ':user'   => $userId,
                ':run'    => $targetRunId,
                ':source' => 'n8n',
            ]);
            $existingNoteId = $selectNote->fetchColumn();
            if ($existingNoteId !== false && $existingNoteId !== null) {
                $noteId = (int) $existingNoteId;
            }

            if ($noteId !== null) {
                $updateNote = $pdo->prepare(
                    'UPDATE item_notes
                        SET product_name = ?,
                            product_description = ?,
                            source = ?
                      WHERE id = ?'
                );
                $updateNote->execute([
                    $productName,
                    $productDescription,
                    'n8n',
                    $noteId,
                ]);
            } else {
                $insertNote = $pdo->prepare(
                    'INSERT INTO item_notes (user_id, run_id, product_name, product_description, source, created_at)
                     VALUES (?, ?, ?, ?, ?, NOW())'
                );
                $insertNote->execute([
                    $userId,
                    $targetRunId,
                    $productName,
                    $productDescription,
                    'n8n',
                ]);
            }
        }

        if ($createdNewRun) {
            $insertLog = $pdo->prepare(
                'INSERT INTO status_logs (user_id, run_id, level, status_code, message, payload_excerpt, source, created_at)
                 VALUES (:user_id, :run_id, :level, :status_code, :message, :payload_excerpt, :source, NOW())'
            );
            $insertLog->execute([
                ':user_id'         => $userId,
                ':run_id'          => $targetRunId,
                ':level'           => 'info',
                ':status_code'     => 200,
                ':message'         => 'Workflow gestartet',
                ':payload_excerpt' => $payloadSummary,
                ':source'          => 'receiver',
            ]);
        }

        $stateMessage = resolveMessageFromPayload($payload, $message);
        upsertUserState($pdo, $userId, 'running', $stateMessage, $payloadSummary, $targetRunId);

        $pdo->commit();

        $runId = $targetRunId;
        $handled = true;
        $responseData = [
            'ok'      => true,
            'message' => 'content saved',
            'run_id'  => $runId,
        ];
    }

    if ($isRunningField !== null) {
        $isRunning = $normalizedIsRunning ?? true;
        $status = $isRunning ? 'running' : 'finished';
        $messageDefault = $statusMessage ?? ($isRunning ? 'Workflow läuft…' : 'Workflow abgeschlossen.');
        $message = resolveMessageFromPayload($payload, $messageDefault);

        $pdo->beginTransaction();

        $targetRunId = $runId;
        if ($targetRunId === null) {
            $targetRunId = createWorkflowRun($pdo, $userId, $status, $message);
        } else {
            updateWorkflowRun($pdo, $userId, $targetRunId, $status, $message);
        }

        $currentRunIdForState = $status === 'running' ? $targetRunId : null;
        upsertUserState($pdo, $userId, $status, $message, $payloadSummary, $currentRunIdForState);

        $pdo->commit();

        $runId = $targetRunId;
        $handled = true;
        $responseData = [
            'ok'        => true,
            'message'   => 'state updated',
            'isrunning' => $isRunning,
            'run_id'    => $runId,
        ];
    }

    if ($handled && $responseData !== null) {
        jsonResponse(200, $responseData);
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
