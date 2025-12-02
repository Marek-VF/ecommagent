<?php
require_once __DIR__ . '/auth/bootstrap.php';
require_once __DIR__ . '/status_logger.php';
require_once __DIR__ . '/credits.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function json_response(bool $ok, string $message, array $data = [], int $status = 200): void
{
    http_response_code($status);
    echo json_encode(array_merge([
        'ok'      => $ok,
        'message' => $message,
    ], $data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function to_bool(mixed $value, bool $default = true): bool
{
    if (is_bool($value)) {
        return $value;
    }

    if (is_int($value)) {
        return $value !== 0;
    }

    if (is_string($value)) {
        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return $default;
        }

        if (in_array($normalized, ['false', '0', 'nein', 'no'], true)) {
            return false;
        }

        if (in_array($normalized, ['true', '1', 'ja', 'yes'], true)) {
            return true;
        }
    }

    return $default;
}

function normalize_positive_int(mixed $value): ?int
{
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

    if (is_float($value) || is_numeric($value)) {
        $intValue = (int) $value;

        return $intValue > 0 ? $intValue : null;
    }

    return null;
}

function check_authorization(array $config): array
{
    $token = trim((string)($config['receiver_api_token'] ?? ''));
    if ($token === '') {
        return ['error' => 'Receiver token not configured', 'status' => 500];
    }

    $headerValue = null;

    if (isset($_SERVER['HTTP_AUTHORIZATION']) && is_string($_SERVER['HTTP_AUTHORIZATION'])) {
        $headerValue = trim($_SERVER['HTTP_AUTHORIZATION']);
    }

    if ($headerValue === null && function_exists('getallheaders')) {
        foreach (getallheaders() as $key => $value) {
            if (!is_string($key) || strcasecmp($key, 'Authorization') !== 0) {
                continue;
            }

            if (is_string($value) && $value !== '') {
                $headerValue = trim($value);
                break;
            }
        }
    }

    if (!is_string($headerValue) || stripos($headerValue, 'Bearer ') !== 0) {
        return ['error' => 'Unauthorized', 'status' => 401];
    }

    $providedToken = trim(substr($headerValue, 7));
    if ($providedToken === '' || !hash_equals($token, $providedToken)) {
        return ['error' => 'Unauthorized', 'status' => 401];
    }

    return ['error' => null, 'status' => 200];
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(false, 'Method not allowed', [], 405);
    return;
}

$config = auth_config();
$authCheck = check_authorization($config);
if ($authCheck['error'] !== null) {
    json_response(false, $authCheck['error'], [], $authCheck['status']);
    return;
}

$payload = read_json_body();
$userId = isset($payload['user_id']) ? (int) $payload['user_id'] : 0;
$runId = normalize_positive_int($payload['run_id'] ?? null);
$stepType = isset($payload['step_type']) ? trim((string) $payload['step_type']) : null;
if ($stepType === '') {
    $stepType = null;
}
$executed = isset($payload['executed_successfully']) ? to_bool($payload['executed_successfully'], false) : false;
$isRunning = array_key_exists('isrunning', $payload)
    ? to_bool($payload['isrunning'], true)
    : (array_key_exists('is_running', $payload) ? to_bool($payload['is_running'], true) : true);

$statusCodeFromPayload = extract_status_message($payload);
$statusEventFromPayload = $statusCodeFromPayload !== null ? resolve_status_event($statusCodeFromPayload) : null;

$nameRaw = $payload['product_name'] ?? $payload['produktname'] ?? null;
$descRaw = $payload['product_description'] ?? $payload['produktbeschreibung'] ?? null;
$statusRaw = $payload['statusmessage'] ?? $payload['message'] ?? $payload['status'] ?? null;

$hasNameField = array_key_exists('product_name', $payload) || array_key_exists('produktname', $payload);
$hasDescField = array_key_exists('product_description', $payload) || array_key_exists('produktbeschreibung', $payload);

$name = null;
if ($hasNameField) {
    $name = $nameRaw !== null ? trim((string) $nameRaw) : '';
}

$desc = null;
if ($hasDescField) {
    $desc = $descRaw !== null ? trim((string) $descRaw) : '';
}

$message = $statusEventFromPayload['label'] ?? ($statusRaw !== null ? trim((string) $statusRaw) : '');
if ($message === '') {
    $message = 'aktualisiert';
}

if ($userId <= 0) {
    json_response(false, 'user_id missing', [], 400);
    return;
}

if ($runId === null) {
    json_response(false, 'run_id required', ['run_id' => null], 400);
    return;
}

try {
    $pdo = auth_pdo();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $connectionException) {
    json_response(false, 'database connection failed', ['run_id' => $runId], 500);
    return;
}

$statusStr = $isRunning ? 'running' : 'finished';

try {
    $pdo->beginTransaction();

    $runCheck = $pdo->prepare('SELECT id FROM workflow_runs WHERE id = :rid AND user_id = :uid LIMIT 1 FOR UPDATE');
    $runCheck->execute([
        ':rid' => $runId,
        ':uid' => $userId,
    ]);
    $runExists = $runCheck->fetchColumn();

    if ($runExists === false) {
        throw new RuntimeException('run not found');
    }

    $noteId = null;
    $existingNoteName = '';
    $existingNoteDescription = '';

    $noteLookup = $pdo->prepare('SELECT id, product_name, product_description FROM item_notes WHERE user_id = :uid AND run_id = :rid ORDER BY id ASC LIMIT 1 FOR UPDATE');
    $noteLookup->execute([
        ':uid' => $userId,
        ':rid' => $runId,
    ]);
    $noteRow = $noteLookup->fetch(PDO::FETCH_ASSOC);

    if ($noteRow !== false) {
        $noteId = (int) $noteRow['id'];
        $existingNoteName = isset($noteRow['product_name']) ? (string) $noteRow['product_name'] : '';
        $existingNoteDescription = isset($noteRow['product_description']) ? (string) $noteRow['product_description'] : '';
    } else {
        $noteInsert = $pdo->prepare(
            "INSERT INTO item_notes (user_id, run_id, product_name, product_description, source) VALUES (:uid, :rid, COALESCE(:pname, ''), COALESCE(:pdesc, ''), 'n8n')"
        );
        $noteInsert->execute([
            ':uid'   => $userId,
            ':rid'   => $runId,
            ':pname' => $name,
            ':pdesc' => $desc,
        ]);
        $noteId = (int) $pdo->lastInsertId();
        $existingNoteName = $name ?? '';
        $existingNoteDescription = $desc ?? '';
    }

    if ($noteId <= 0) {
        throw new RuntimeException('note could not be created');
    }

    if ($hasNameField || $hasDescField) {
        $updateParts = [];
        $updateParams = [
            ':nid' => $noteId,
        ];

        if ($hasNameField) {
            $updateParts[] = 'product_name = :pname';
            $updateParams[':pname'] = $name ?? '';
        }

        if ($hasDescField) {
            $updateParts[] = 'product_description = :pdesc';
            $updateParams[':pdesc'] = $desc ?? '';
        }

        if (!empty($updateParts)) {
            $updateSql = 'UPDATE item_notes SET ' . implode(', ', $updateParts) . ", source = 'n8n' WHERE id = :nid";
            $updateNote = $pdo->prepare($updateSql);
            $updateNote->execute($updateParams);
        }
    }

    $runSql = $isRunning
        ? "UPDATE workflow_runs SET status = :status, last_message = :message, finished_at = NULL WHERE id = :rid AND user_id = :uid"
        : "UPDATE workflow_runs SET status = :status, last_message = :message, finished_at = NOW() WHERE id = :rid AND user_id = :uid";

    $updateRun = $pdo->prepare($runSql);
    $updateRun->execute([
        ':status' => $statusStr,
        ':message'=> $message,
        ':rid'    => $runId,
        ':uid'    => $userId,
    ]);

    $stateStmt = $pdo->prepare(
        'INSERT INTO user_state (user_id, current_run_id, last_status, last_message, updated_at) VALUES (:uid, :rid, :status, :message, NOW())'
        . ' ON DUPLICATE KEY UPDATE'
        . '     current_run_id = VALUES(current_run_id),'
        . '     last_status = VALUES(last_status),'
        . '     last_message = VALUES(last_message),'
        . '     updated_at = NOW()'
    );
    $stateStmt->execute([
        ':uid'     => $userId,
        ':rid'     => $runId,
        ':status'  => $statusStr,
        ':message' => $message,
    ]);

    // Neues Statuslog-System: Speichert jede eingehende Statusmeldung als Event.
    if ($statusCodeFromPayload !== null) {
        log_event($pdo, $runId, $userId, $statusCodeFromPayload, 'n8n');
    }

    $pdo->commit();
} catch (RuntimeException $runtimeException) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    json_response(false, $runtimeException->getMessage(), [
        'run_id' => $runId,
        'status' => $statusStr,
    ], 404);
    return;
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('[receiver] ' . $exception->getMessage());
    json_response(false, 'internal error', [
        'run_id'  => $runId,
        'status'  => $statusStr,
    ], 500);
    return;
}

if ($executed && $stepType !== null) {
    try {
        $meta = ['source' => 'receiver.php'];
        charge_credits($pdo, $config, $userId, $runId, $stepType, $meta);
    } catch (Throwable $creditException) {
        error_log('[receiver][credits] ' . $creditException->getMessage());
    }
}

json_response(true, 'state updated', [
    'run_id'    => $runId,
    'isrunning' => $isRunning,
    'status'    => $statusStr,
    'message'   => $message,
]);
