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

function fetchCurrentRunId(PDO $pdo, int $userId): ?int
{
    $stmt = $pdo->prepare('SELECT current_run_id FROM user_state WHERE user_id = :user_id LIMIT 1');
    $stmt->execute([':user_id' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row === false) {
        return null;
    }

    if ($row['current_run_id'] === null) {
        return null;
    }

    return (int) $row['current_run_id'];
}

function upsertUserState(PDO $pdo, int $userId, string $lastStatus, string $lastMessage, string $payloadSummary, ?int $currentRunId): void
{
    $statement = $pdo->prepare(
        'INSERT INTO user_state (user_id, last_status, last_message, last_payload_summary, current_run_id, updated_at)
         VALUES (:user_id, :last_status, :last_message, :last_payload_summary, :current_run_id, NOW())
         ON DUPLICATE KEY UPDATE
             last_status = VALUES(last_status),
             last_message = VALUES(last_message),
             last_payload_summary = VALUES(last_payload_summary),
             current_run_id = VALUES(current_run_id),
             updated_at = NOW()'
    );

    $statement->execute([
        ':user_id'              => $userId,
        ':last_status'          => $lastStatus,
        ':last_message'         => $lastMessage,
        ':last_payload_summary' => $payloadSummary,
        ':current_run_id'       => $currentRunId,
    ]);
}

function startWorkflowRun(PDO $pdo, int $userId, string $statusMessage): int
{
    $insertRun = $pdo->prepare(
        'INSERT INTO workflow_runs (user_id, status, last_message, started_at)
         VALUES (:user_id, :status, :last_message, NOW())'
    );
    $insertRun->execute([
        ':user_id'      => $userId,
        ':status'       => 'running',
        ':last_message' => $statusMessage,
    ]);

    return (int) $pdo->lastInsertId();
}

function updateWorkflowRun(PDO $pdo, int $userId, int $runId, string $status, string $message, bool $markFinished): void
{
    $sql = 'UPDATE workflow_runs SET status = :status, last_message = :last_message';
    if ($markFinished) {
        $sql .= ', finished_at = NOW()';
    }
    $sql .= ' WHERE id = :id AND user_id = :user_id';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':status'    => $status,
        ':last_message' => $message,
        ':id'        => $runId,
        ':user_id'   => $userId,
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

        $payloadRunId = normalizeRunId($payload['run_id'] ?? null);
        $currentRunId = fetchCurrentRunId($pdo, $userId);
        $targetRunId = $payloadRunId ?? $currentRunId;
        if ($targetRunId !== null && !runBelongsToUser($pdo, $userId, $targetRunId)) {
            $targetRunId = null;
        }

        $message = $statusMessage ?? ($isRunning ? 'Workflow läuft…' : 'Workflow abgeschlossen.');
        $lastStatus = normalizeStatusLabel($isRunning ? 'running' : 'finished');

        $shouldUpdateState = false;
        $stateRunId = $currentRunId;

        if ($payloadRunId !== null) {
            if ($currentRunId === null || $currentRunId === $payloadRunId) {
                $shouldUpdateState = true;
                $stateRunId = $payloadRunId;
            }
        } elseif ($currentRunId !== null) {
            $shouldUpdateState = true;
            $stateRunId = $currentRunId;
        }

        if ($shouldUpdateState) {
            $nextRunId = $isRunning ? $stateRunId : null;
            upsertUserState($pdo, $userId, $lastStatus, $message, $raw, $nextRunId);
        }

        if ($targetRunId !== null) {
            updateWorkflowRun($pdo, $userId, $targetRunId, $isRunning ? 'running' : 'finished', $message, !$isRunning);
        }

        jsonResponse(200, [
            'ok'        => true,
            'message'   => 'state updated',
            'isrunning' => $isRunning,
        ]);
    }

    $hasContentPayload = $productName !== null || $productDescription !== null || $statusMessage !== null;

    if ($hasContentPayload) {
        $pdo->beginTransaction();

        $message = $statusMessage ?? 'Daten empfangen.';
        $payloadRunId = normalizeRunId($payload['run_id'] ?? null);
        $runId = $payloadRunId;
        $createdNewRun = false;

        if ($runId !== null && !runBelongsToUser($pdo, $userId, $runId)) {
            $runId = null;
        }

        if ($runId === null) {
            $runId = startWorkflowRun($pdo, $userId, $message);
            $createdNewRun = true;
        } else {
            updateWorkflowRun($pdo, $userId, $runId, 'running', $message, false);
        }

        $insertNote = $pdo->prepare(
            'INSERT INTO item_notes (user_id, run_id, product_name, product_description, source, created_at)
             VALUES (:user_id, :run_id, :product_name, :product_description, :source, NOW())'
        );
        $insertNote->execute([
            ':user_id'             => $userId,
            ':run_id'              => $runId,
            ':product_name'        => $productName,
            ':product_description' => $productDescription,
            ':source'              => 'n8n',
        ]);

        $payloadExcerpt = mb_substr($raw, 0, 65535);
        $insertLog = $pdo->prepare(
            'INSERT INTO status_logs (user_id, run_id, level, status_code, message, payload_excerpt, source, created_at)
             VALUES (:user_id, :run_id, :level, :status_code, :message, :payload_excerpt, :source, NOW())'
        );
        $insertLog->execute([
            ':user_id'         => $userId,
            ':run_id'          => $runId,
            ':level'           => 'info',
            ':status_code'     => 200,
            ':message'         => 'Workflow gestartet',
            ':payload_excerpt' => $payloadExcerpt,
            ':source'          => 'receiver',
        ]);

        $currentRunId = fetchCurrentRunId($pdo, $userId);
        $shouldUpdateState = $createdNewRun || $currentRunId === null || $currentRunId === $runId;
        if ($shouldUpdateState) {
            upsertUserState($pdo, $userId, 'running', $message, $raw, $runId);
        }

        $pdo->commit();

        jsonResponse(200, [
            'ok'      => true,
            'message' => 'content saved',
            'run_id'  => $runId,
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
