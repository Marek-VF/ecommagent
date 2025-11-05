<?php
require_once __DIR__ . '/auth/bootstrap.php';

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

function check_authorization(array $config): ?string
{
    $token = trim((string)($config['receiver_api_token'] ?? ''));
    if ($token === '') {
        return null;
    }

    $header = '';
    if (isset($_SERVER['HTTP_AUTHORIZATION']) && is_string($_SERVER['HTTP_AUTHORIZATION'])) {
        $header = trim($_SERVER['HTTP_AUTHORIZATION']);
    } elseif (isset($_SERVER['HTTP_X_API_TOKEN']) && is_string($_SERVER['HTTP_X_API_TOKEN'])) {
        $header = trim($_SERVER['HTTP_X_API_TOKEN']);
    }

    if ($header !== '' && stripos($header, 'Bearer ') === 0) {
        $header = substr($header, 7);
    }

    if ($header === '' || !hash_equals($token, $header)) {
        return 'Unauthorized';
    }

    return null;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(false, 'Method not allowed', [], 405);
    return;
}

$config = auth_config();
if ($error = check_authorization($config)) {
    json_response(false, $error, [], 401);
    return;
}

$payload = read_json_body();
$userId = isset($payload['user_id']) ? (int) $payload['user_id'] : 0;
$runId = normalize_positive_int($payload['run_id'] ?? null);
$isRunning = array_key_exists('isrunning', $payload)
    ? to_bool($payload['isrunning'], true)
    : (array_key_exists('is_running', $payload) ? to_bool($payload['is_running'], true) : true);
$hasNameField = array_key_exists('product_name', $payload);
$hasDescField = array_key_exists('product_description', $payload);
$name = $hasNameField ? trim((string) $payload['product_name']) : '';
$desc = $hasDescField ? trim((string) $payload['product_description']) : '';
$message = isset($payload['message']) ? trim((string) $payload['message']) : '';
if ($message === '' && isset($payload['status'])) {
    $message = trim((string) $payload['status']);
}
if ($message === '') {
    $message = 'aktualisiert';
}

if ($userId <= 0) {
    json_response(false, 'user_id missing', [], 400);
    return;
}

if ($runId === null) {
    $errorMessage = 'run_id missing';

    try {
        $pdo = auth_pdo();
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $stateStmt = $pdo->prepare(
            'INSERT INTO user_state (user_id, current_run_id, last_status, last_message, updated_at) VALUES (:uid, NULL, :status, :message, NOW())'
            . ' ON DUPLICATE KEY UPDATE'
            . '     last_status = VALUES(last_status),'
            . '     last_message = VALUES(last_message),'
            . '     updated_at = NOW()'
        );
        $stateStmt->execute([
            ':uid'     => $userId,
            ':status'  => 'error',
            ':message' => $errorMessage,
        ]);
    } catch (Throwable $stateException) {
        error_log('[receiver] failed to persist missing run_id state: ' . $stateException->getMessage());
    }

    json_response(false, $errorMessage, ['run_id' => null], 400);
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
            "INSERT INTO item_notes (user_id, run_id, product_name, product_description, source) VALUES (:uid, :rid, '', '', 'n8n')"
        );
        $noteInsert->execute([
            ':uid' => $userId,
            ':rid' => $runId,
        ]);
        $noteId = (int) $pdo->lastInsertId();
        $existingNoteName = '';
        $existingNoteDescription = '';
    }

    if ($noteId <= 0) {
        throw new RuntimeException('note could not be created');
    }

    if ($hasNameField || $hasDescField) {
        $newName = $hasNameField ? $name : $existingNoteName;
        $newDesc = $hasDescField ? $desc : $existingNoteDescription;

        $updateNote = $pdo->prepare("UPDATE item_notes SET product_name = :pname, product_description = :pdesc, source = 'n8n' WHERE id = :nid");
        $updateNote->execute([
            ':pname' => $newName,
            ':pdesc' => $newDesc,
            ':nid'   => $noteId,
        ]);
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

    if ($updateRun->rowCount() === 0) {
        throw new RuntimeException('run not found');
    }

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

    $pdo->commit();
} catch (RuntimeException $runtimeException) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    json_response(false, $runtimeException->getMessage(), [
        'run_id'   => $runId,
        'status'   => $statusStr,
        'message'  => $message,
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

json_response(true, 'state updated', [
    'run_id'    => $runId,
    'isrunning' => $isRunning,
    'status'    => $statusStr,
    'message'   => $message,
]);
