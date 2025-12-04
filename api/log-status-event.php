<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../auth/bootstrap.php';
require_once __DIR__ . '/../status_logger.php';

$respond = static function (array $payload, int $statusCode = 200): never {
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $respond([
        'ok'      => false,
        'message' => 'method not allowed',
    ], 405);
}

if (!auth_is_logged_in()) {
    $respond([
        'ok'     => false,
        'error'  => 'not authenticated',
        'logout' => true,
    ], 401);
}

$user = auth_user();
$userId = isset($user['id']) && is_numeric($user['id']) ? (int) $user['id'] : 0;
if ($userId <= 0) {
    $respond([
        'ok'     => false,
        'error'  => 'invalid user',
        'logout' => true,
    ], 401);
}

$rawBody = file_get_contents('php://input');
$data = null;
if (is_string($rawBody) && trim($rawBody) !== '') {
    $decoded = json_decode($rawBody, true);
    if (is_array($decoded)) {
        $data = $decoded;
    }
}

if (!is_array($data)) {
    $data = $_POST;
}

$statusCodeRaw = isset($data['status_code']) ? (string) $data['status_code'] : '';
$statusCode = strtoupper(trim($statusCodeRaw));

if ($statusCode === '') {
    $respond([
        'ok'      => false,
        'message' => 'status_code missing',
    ], 400);
}

$runId = null;
if (isset($data['run_id'])) {
    $runIdCandidate = (string) $data['run_id'];
    if ($runIdCandidate !== '' && ctype_digit($runIdCandidate)) {
        $parsedRunId = (int) $runIdCandidate;
        if ($parsedRunId > 0) {
            $runId = $parsedRunId;
        }
    }
}

$source = 'frontend';
if (isset($data['source']) && is_string($data['source'])) {
    $candidate = strtolower(trim($data['source']));
    if (in_array($candidate, ['frontend', 'backend'], true)) {
        $source = $candidate;
    }
}

try {
    $pdo = auth_pdo();
    $event = resolve_status_event($statusCode);

    $statement = $pdo->prepare(
        'INSERT INTO status_logs_new (run_id, user_id, message, source, severity, code, created_at) VALUES (:run_id, :user_id, :message, :source, :severity, :code, NOW())'
    );

    $statement->execute([
        ':run_id'   => $runId,
        ':user_id'  => $userId,
        ':message'  => $event['label'],
        ':source'   => $source,
        ':severity' => $event['severity'],
        ':code'     => $event['code'],
    ]);

    $respond([
        'ok'      => true,
        'run_id'  => $runId,
        'event'   => $event,
        'user_id' => $userId,
    ]);
} catch (Throwable $exception) {
    $respond([
        'ok'      => false,
        'message' => 'no run id',
    ], 200);
}
