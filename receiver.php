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
$userId = isset($payload['user_id']) ? (int)$payload['user_id'] : 0;
$isRunning = array_key_exists('isrunning', $payload)
    ? to_bool($payload['isrunning'], true)
    : (array_key_exists('is_running', $payload) ? to_bool($payload['is_running'], true) : true);
$hasNameField = array_key_exists('product_name', $payload);
$hasDescField = array_key_exists('product_description', $payload);
$name = $hasNameField ? trim((string)$payload['product_name']) : '';
$desc = $hasDescField ? trim((string)$payload['product_description']) : '';
$message = isset($payload['message']) ? trim((string)$payload['message']) : '';
if ($message === '' && isset($payload['status'])) {
    $message = trim((string)$payload['status']);
}
if ($message === '') {
    $message = 'aktualisiert';
}

if ($userId <= 0) {
    json_response(false, 'user_id missing', [], 400);
    return;
}

$pdo = auth_pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$errors = [];
$runId = 0;
$noteId = null;
$existingNoteName = '';
$existingNoteDescription = '';

// 1) aktuellen Run ermitteln
try {
    $stmt = $pdo->prepare('SELECT current_run_id FROM user_state WHERE user_id = :uid');
    $stmt->execute([':uid' => $userId]);
    $runId = (int)($stmt->fetchColumn() ?? 0);
} catch (Throwable $e) {
    $errors[] = 'user_state lookup failed: ' . $e->getMessage();
}

if ($runId === 0) {
    try {
        $stmt = $pdo->prepare("SELECT id FROM workflow_runs WHERE user_id = :uid AND status = 'running' ORDER BY started_at DESC, id DESC LIMIT 1");
        $stmt->execute([':uid' => $userId]);
        $runId = (int)($stmt->fetchColumn() ?? 0);
    } catch (Throwable $e) {
        $errors[] = 'workflow_runs lookup failed: ' . $e->getMessage();
    }
}

// 2) Notiz für den aktuellen Run sicherstellen
if ($runId > 0) {
    try {
        $stmt = $pdo->prepare('SELECT id, product_name, product_description FROM item_notes WHERE user_id = :uid AND run_id = :rid ORDER BY id ASC LIMIT 1');
        $stmt->execute([':uid' => $userId, ':rid' => $runId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row !== false) {
            $noteId = (int) $row['id'];
            $existingNoteName = isset($row['product_name']) ? (string) $row['product_name'] : '';
            $existingNoteDescription = isset($row['product_description']) ? (string) $row['product_description'] : '';
        } else {
            $stmt = $pdo->prepare("INSERT INTO item_notes (user_id, run_id, product_name, product_description, source) VALUES (:uid, :rid, '', '', 'n8n')");
            $stmt->execute([
                ':uid' => $userId,
                ':rid' => $runId,
            ]);
            $noteId = (int) $pdo->lastInsertId();
            $existingNoteName = '';
            $existingNoteDescription = '';
        }
    } catch (Throwable $e) {
        $errors[] = 'item_notes ensure failed: ' . $e->getMessage();
    }
} else {
    $errors[] = 'item_notes skipped: run_id missing';
}

// 3) Artikeldaten speichern (optional)
if ($noteId !== null && ($hasNameField || $hasDescField)) {
    try {
        $newName = $hasNameField ? $name : $existingNoteName;
        $newDesc = $hasDescField ? $desc : $existingNoteDescription;

        $stmt = $pdo->prepare("UPDATE item_notes SET product_name = :pname, product_description = :pdesc, source = 'n8n' WHERE id = :nid");
        $stmt->execute([
            ':pname' => $newName,
            ':pdesc' => $newDesc,
            ':nid'   => $noteId,
        ]);
    } catch (Throwable $e) {
        $errors[] = 'item_notes update failed: ' . $e->getMessage();
    }
}

// 4) user_state + workflow_runs aktualisieren
if ($runId > 0) {
    try {
        $statusStr = $isRunning ? 'running' : 'finished';
        $stmt = $pdo->prepare('INSERT INTO user_state (user_id, current_run_id, last_status, last_message) VALUES (:uid, :rid, :st, :msg) ON DUPLICATE KEY UPDATE current_run_id = VALUES(current_run_id), last_status = VALUES(last_status), last_message = VALUES(last_message)');
        $stmt->execute([
            ':uid' => $userId,
            ':rid' => $runId,
            ':st'  => $statusStr,
            ':msg' => $message,
        ]);
    } catch (Throwable $e) {
        $errors[] = 'user_state write failed: ' . $e->getMessage();
    }

    try {
        $statusStr = $isRunning ? 'running' : 'finished';
        $sql = $isRunning
            ? "UPDATE workflow_runs SET status = :status, last_message = :msg, finished_at = NULL WHERE id = :rid AND user_id = :uid"
            : "UPDATE workflow_runs SET status = :status, finished_at = NOW(), last_message = :msg WHERE id = :rid AND user_id = :uid";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':status' => $statusStr,
            ':msg'    => $message,
            ':rid'    => $runId,
            ':uid'    => $userId,
        ]);
    } catch (Throwable $e) {
        $errors[] = 'workflow_runs update failed: ' . $e->getMessage();
    }
} else {
    $errors[] = 'user_state skipped: run_id missing';
}

if ($runId <= 0) {
    json_response(false, 'Run could not be determined', [
        'errors'   => $errors,
        'run_id'   => $runId,
        'isrunning'=> $isRunning,
    ]);
    return;
}

if ($errors !== []) {
    json_response(false, 'state updated with warnings', [
        'errors'   => $errors,
        'run_id'   => $runId,
        'isrunning'=> $isRunning,
    ]);
    return;
}

json_response(true, 'state updated', [
    'run_id'   => $runId,
    'isrunning'=> $isRunning,
]);
