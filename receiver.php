<?php
declare(strict_types=1);

require_once __DIR__ . '/auth/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function respond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ensureAuthorized(array $config): void
{
    $token = $config['receiver_api_token'] ?? '';
    if (!is_string($token) || $token === '') {
        respond(500, [
            'ok'      => false,
            'message' => 'API token missing in configuration.',
        ]);
    }

    $authorizationHeader = null;

    if (isset($_SERVER['HTTP_AUTHORIZATION']) && is_string($_SERVER['HTTP_AUTHORIZATION'])) {
        $authorizationHeader = trim($_SERVER['HTTP_AUTHORIZATION']);
    }

    if ($authorizationHeader === null && function_exists('getallheaders')) {
        foreach (getallheaders() as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            if (strcasecmp($key, 'Authorization') === 0 && is_string($value)) {
                $authorizationHeader = trim($value);
                break;
            }
        }
    }

    $providedToken = null;
    if (is_string($authorizationHeader) && stripos($authorizationHeader, 'Bearer ') === 0) {
        $candidate = trim(substr($authorizationHeader, 7));
        if ($candidate !== '') {
            $providedToken = $candidate;
        }
    }

    if ($providedToken === null && function_exists('getallheaders')) {
        foreach (getallheaders() as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            $normalizedKey = strtolower($key);
            if (in_array($normalizedKey, ['x-api-token', 'x-authorization'], true) && is_string($value) && $value !== '') {
                $providedToken = trim($value);
                break;
            }
        }
    }

    if ($providedToken === null || !hash_equals($token, $providedToken)) {
        header('WWW-Authenticate: Bearer');
        respond(401, [
            'ok'      => false,
            'message' => 'Unauthorized',
        ]);
    }

    $allowedIps = $config['receiver_api_allowed_ips'] ?? [];
    if (is_array($allowedIps) && $allowedIps !== []) {
        $remoteIp = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        if ($remoteIp === '' || !in_array($remoteIp, array_map('strval', $allowedIps), true)) {
            respond(403, [
                'ok'      => false,
                'message' => 'IP not allowed',
            ]);
        }
    }
}

function toBool(mixed $value, bool $default = true): bool
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

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(405, [
            'ok'      => false,
            'message' => 'Method not allowed',
        ]);
    }

    $config = auth_config();
    ensureAuthorized($config);

    $rawBody = file_get_contents('php://input');
    if ($rawBody === false || trim($rawBody) === '') {
        respond(400, [
            'ok'      => false,
            'message' => 'Empty request body',
        ]);
    }

    $payload = json_decode($rawBody, true);
    if (!is_array($payload)) {
        respond(400, [
            'ok'      => false,
            'message' => 'Invalid JSON payload',
        ]);
    }

    $userId = isset($payload['user_id']) ? (int) $payload['user_id'] : 0;
    if ($userId <= 0) {
        respond(400, [
            'ok'      => false,
            'message' => 'user_id missing',
        ]);
    }

    $isRunningRaw = $payload['isrunning'] ?? $payload['is_running'] ?? null;
    $isRunning = toBool($isRunningRaw, true);

    $productName = '';
    if (isset($payload['product_name']) && is_string($payload['product_name'])) {
        $productName = trim($payload['product_name']);
    }

    $productDescription = '';
    if (isset($payload['product_description']) && is_string($payload['product_description'])) {
        $productDescription = trim($payload['product_description']);
    }

    $message = '';
    if (isset($payload['message']) && is_string($payload['message'])) {
        $message = trim($payload['message']);
    } elseif (isset($payload['status']) && is_string($payload['status'])) {
        $message = trim($payload['status']);
    }

    if ($message === '') {
        $message = 'aktualisiert';
    }

    $pdo = auth_pdo();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT current_run_id FROM user_state WHERE user_id = :uid');
    $stmt->execute([':uid' => $userId]);
    $currentRunId = (int) ($stmt->fetchColumn() ?? 0);

    if ($currentRunId <= 0) {
        $stmt = $pdo->prepare("SELECT id FROM workflow_runs WHERE user_id = :uid AND status = 'running' ORDER BY started_at DESC, id DESC LIMIT 1");
        $stmt->execute([':uid' => $userId]);
        $currentRunId = (int) ($stmt->fetchColumn() ?? 0);
    }

    if ($currentRunId <= 0) {
        $stmt = $pdo->prepare(
            'INSERT INTO workflow_runs (user_id, started_at, status, last_message) VALUES (:uid, NOW(), :status, :message)'
        );
        $stmt->execute([
            ':uid'     => $userId,
            ':status'  => 'running',
            ':message' => $message,
        ]);
        $currentRunId = (int) $pdo->lastInsertId();
    }

    if ($productName !== '' || $productDescription !== '') {
        $stmt = $pdo->prepare('SELECT id FROM item_notes WHERE user_id = :uid AND run_id = :rid LIMIT 1');
        $stmt->execute([
            ':uid' => $userId,
            ':rid' => $currentRunId,
        ]);
        $noteId = $stmt->fetchColumn();
        $noteId = $noteId !== false && $noteId !== null ? (int) $noteId : 0;

        if ($noteId > 0) {
            $stmt = $pdo->prepare(
                'UPDATE item_notes SET product_name = :pname, product_description = :pdesc, source = :source WHERE id = :nid'
            );
            $stmt->execute([
                ':pname'  => $productName,
                ':pdesc'  => $productDescription,
                ':source' => 'n8n',
                ':nid'    => $noteId,
            ]);
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO item_notes (user_id, run_id, product_name, product_description, source) VALUES (:uid, :rid, :pname, :pdesc, :source)'
            );
            $stmt->execute([
                ':uid'    => $userId,
                ':rid'    => $currentRunId,
                ':pname'  => $productName,
                ':pdesc'  => $productDescription,
                ':source' => 'n8n',
            ]);
        }
    }

    $statusString = $isRunning ? 'running' : 'finished';
    $stmt = $pdo->prepare(
        'INSERT INTO user_state (user_id, current_run_id, last_status, last_message)
         VALUES (:uid, :rid, :status, :message)
         ON DUPLICATE KEY UPDATE
            current_run_id = VALUES(current_run_id),
            last_status = VALUES(last_status),
            last_message = VALUES(last_message)'
    );
    $stmt->execute([
        ':uid'     => $userId,
        ':rid'     => $currentRunId,
        ':status'  => $statusString,
        ':message' => $message,
    ]);

    if (!$isRunning) {
        $stmt = $pdo->prepare(
            "UPDATE workflow_runs SET status = 'finished', finished_at = NOW(), last_message = :message WHERE id = :rid AND user_id = :uid"
        );
        $stmt->execute([
            ':message' => $message,
            ':rid'     => $currentRunId,
            ':uid'     => $userId,
        ]);
    }

    $pdo->commit();

    respond(200, [
        'ok'        => true,
        'message'   => 'state updated',
        'run_id'    => $currentRunId,
        'isrunning' => $isRunning,
    ]);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    respond(500, [
        'ok'      => false,
        'message' => $exception->getMessage(),
    ]);
}
