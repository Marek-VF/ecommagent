<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }

    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(static function (Throwable $throwable): void {
    http_response_code(500);
    echo json_encode([
        'ok'          => false,
        'status_code' => 500,
        'message'     => 'Internal server error',
        'user_id'     => null,
        'saved'       => [
            'status_logs_id'     => null,
            'user_state_updated' => false,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
});

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        respondAndExit([
            'ok'          => false,
            'status_code' => 405,
            'message'     => 'Method not allowed',
            'user_id'     => null,
            'saved'       => [
                'status_logs_id'     => null,
                'user_state_updated' => false,
            ],
        ], 405);
    }

    $token = getIncomingToken();
    if ($token === null) {
        http_response_code(401);
        header('WWW-Authenticate: Bearer');
        respondAndExit([
            'ok'          => false,
            'status_code' => 401,
            'message'     => 'Missing or invalid API token',
            'user_id'     => null,
            'saved'       => [
                'status_logs_id'     => null,
                'user_state_updated' => false,
            ],
        ], 401);
    }

    $pdo = getPDO();

    $userId = resolveUserIdByToken($pdo, $token);
    if ($userId === null) {
        http_response_code(403);
        respondAndExit([
            'ok'          => false,
            'status_code' => 403,
            'message'     => 'Token not accepted',
            'user_id'     => null,
            'saved'       => [
                'status_logs_id'     => null,
                'user_state_updated' => false,
            ],
        ], 403);
    }

    enforceRateLimit($pdo, $userId);

    $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
    $semicolonPos = strpos($contentType, ';');
    if ($semicolonPos !== false) {
        $contentType = substr($contentType, 0, $semicolonPos);
    }
    $contentType = strtolower(trim($contentType));

    $statusCode = 200;
    $level = 'info';
    $message = 'Received';
    $imageUrl = null;
    $payload = null;
    $payloadSummary = null;
    $statusLogId = null;
    $userStateUpdated = false;

    if ($contentType === 'application/json' || $contentType === '') {
        $rawBody = file_get_contents('php://input');
        if ($rawBody === false) {
            $rawBody = '';
        }

        if ($rawBody === '') {
            $payloadData = [];
        } else {
            $decoded = json_decode($rawBody, true);
            if (!is_array($decoded)) {
                $statusCode = 422;
                $level = 'error';
                $message = 'Invalid JSON payload';
                $payload = ['raw_excerpt' => mb_substr($rawBody, 0, 200)];
                $statusLogId = logStatus($pdo, $userId, $level, $statusCode, $message, $payload);
                updateUserState($pdo, $userId, [
                    'last_status'          => mapLevelToState($level),
                    'last_message'         => $message,
                    'last_image_url'       => null,
                    'last_payload_summary' => createPayloadSummary($payload),
                ]);
                respondAndExit([
                    'ok'          => false,
                    'status_code' => $statusCode,
                    'message'     => $message,
                    'user_id'     => $userId,
                    'saved'       => [
                        'status_logs_id'     => $statusLogId,
                        'user_state_updated' => true,
                    ],
                ], $statusCode);
            }
            $payloadData = $decoded;
        }

        $payload = $payloadData;
        $level = determineLevel($payloadData);
        $statusCode = determineStatusCode($payloadData, $level);
        $message = determineMessage($payloadData) ?? 'Received';
        $message = mb_substr($message, 0, 500);
        $imageUrl = extractImageUrl($payloadData);
        $payloadSummary = createPayloadSummary($payloadData);
    } elseif ($contentType === 'application/octet-stream' || $contentType === 'multipart/form-data') {
        $level = 'info';
        $statusCode = 200;
        $message = mb_substr('binary payload received', 0, 500);
        $payload = [
            'note'         => 'binary payload received',
            'content_type' => $contentType,
        ];
        $payloadSummary = $payload['note'];
    } else {
        $statusCode = 415;
        $level = 'error';
        $message = 'Unsupported Content-Type';
        $message = mb_substr($message, 0, 500);
        $payload = [
            'content_type' => $contentType,
        ];
        $statusLogId = logStatus($pdo, $userId, $level, $statusCode, $message, $payload);
        updateUserState($pdo, $userId, [
            'last_status'          => mapLevelToState($level),
            'last_message'         => $message,
            'last_image_url'       => null,
            'last_payload_summary' => createPayloadSummary($payload),
        ]);
        respondAndExit([
            'ok'          => false,
            'status_code' => $statusCode,
            'message'     => $message,
            'user_id'     => $userId,
            'saved'       => [
                'status_logs_id'     => $statusLogId,
                'user_state_updated' => true,
            ],
        ], $statusCode);
    }

    $statusLogId = logStatus($pdo, $userId, $level, $statusCode, $message, $payload);
    updateUserState($pdo, $userId, [
        'last_status'          => mapLevelToState($level),
        'last_message'         => $message,
        'last_image_url'       => $imageUrl,
        'last_payload_summary' => $payloadSummary ?? createPayloadSummary($payload),
    ]);
    $userStateUpdated = true;

    respondAndExit([
        'ok'          => $level !== 'error',
        'status_code' => $statusCode,
        'message'     => $message,
        'user_id'     => $userId,
        'saved'       => [
            'status_logs_id'     => $statusLogId,
            'user_state_updated' => $userStateUpdated,
        ],
    ], $statusCode);
} finally {
    restore_error_handler();
    restore_exception_handler();
}

function respondAndExit(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function getIncomingToken(): ?string
{
    $headers = [];
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
    }

    if (!is_array($headers)) {
        $headers = [];
    }

    foreach ($_SERVER as $key => $value) {
        if (strpos($key, 'HTTP_') === 0) {
            $headerName = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
            if (!array_key_exists($headerName, $headers)) {
                $headers[$headerName] = $value;
            }
        }
    }

    $normalized = [];
    foreach ($headers as $name => $value) {
        if (!is_string($name)) {
            continue;
        }
        $normalized[strtolower($name)] = is_array($value) ? implode(',', $value) : (string) $value;
    }

    $authHeader = $normalized['authorization'] ?? null;
    if (is_string($authHeader)) {
        $authHeader = trim($authHeader);
        if (stripos($authHeader, 'bearer ') === 0) {
            $token = trim(substr($authHeader, 7));
            if ($token !== '') {
                return $token;
            }
        }
    }

    $tokenHeaderNames = ['x-api-token', 'x-authorization', 'x-auth-token'];
    foreach ($tokenHeaderNames as $headerName) {
        if (!isset($normalized[$headerName])) {
            continue;
        }

        $candidate = trim((string) $normalized[$headerName]);
        if ($candidate !== '') {
            return $candidate;
        }
    }

    return null;
}

function resolveUserIdByToken(PDO $pdo, string $token): ?int
{
    $statement = $pdo->prepare('SELECT user_id, token_hash FROM webhook_tokens WHERE is_active = 1');
    $statement->execute();

    $binaryDigest = hash('sha256', $token, true);
    $hexDigest = hash('sha256', $token);

    while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
        if (!is_array($row)) {
            continue;
        }

        $userId = isset($row['user_id']) ? (int) $row['user_id'] : null;
        $storedHash = isset($row['token_hash']) ? (string) $row['token_hash'] : null;

        if ($userId === null || $storedHash === null) {
            continue;
        }

        if (hash_equals($binaryDigest, $storedHash)) {
            return $userId;
        }

        $normalized = $storedHash;
        // If the stored value contains printable characters, treat it as hexadecimal text.
        if ($normalized !== '' && preg_match('/^[0-9a-fA-F]+$/', $normalized) === 1) {
            $normalizedLower = strtolower($normalized);
            if (strlen($normalizedLower) === 64 && hash_equals($hexDigest, $normalizedLower)) {
                return $userId;
            }
            if (strlen($normalizedLower) === 32 && hash_equals(substr($hexDigest, 0, 32), $normalizedLower)) {
                return $userId;
            }
        }

        $hexFromBinary = strtolower(bin2hex($storedHash));
        if (hash_equals($hexDigest, $hexFromBinary)) {
            return $userId;
        }
    }

    return null;
}

function logStatus(PDO $pdo, int $userId, string $level, int $statusCode, string $message, ?array $payload = null, string $source = 'receiver'): int
{
    $message = mb_substr($message, 0, 500);
    $payloadExcerpt = null;
    if ($payload !== null) {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded !== false) {
            if (mb_strlen($encoded) > 800) {
                $encoded = mb_substr($encoded, 0, 800);
            }
            $payloadExcerpt = $encoded;
        }
    }

    $statement = $pdo->prepare(
        'INSERT INTO status_logs (user_id, level, status_code, message, payload_excerpt, source) VALUES (:user_id, :level, :status_code, :message, :payload_excerpt, :source)'
    );
    $statement->execute([
        'user_id'         => $userId,
        'level'           => $level,
        'status_code'     => $statusCode,
        'message'         => $message,
        'payload_excerpt' => $payloadExcerpt,
        'source'          => $source,
    ]);

    return (int) $pdo->lastInsertId();
}

function updateUserState(PDO $pdo, int $userId, array $patch): void
{
    $lastStatus = $patch['last_status'] ?? 'ok';
    $lastMessage = $patch['last_message'] ?? null;
    $lastImageUrl = $patch['last_image_url'] ?? null;
    $lastPayloadSummary = $patch['last_payload_summary'] ?? null;

    if ($lastMessage !== null) {
        $lastMessage = mb_substr($lastMessage, 0, 500);
    }

    if ($lastImageUrl !== null) {
        $lastImageUrl = mb_substr($lastImageUrl, 0, 500);
    }

    if ($lastPayloadSummary !== null) {
        $lastPayloadSummary = mb_substr($lastPayloadSummary, 0, 800);
    }

    $statement = $pdo->prepare(
        'INSERT INTO user_state (user_id, last_status, last_message, last_image_url, last_payload_summary, updated_at)
         VALUES (:user_id, :last_status, :last_message, :last_image_url, :last_payload_summary, NOW())
         ON DUPLICATE KEY UPDATE
            last_status = VALUES(last_status),
            last_message = VALUES(last_message),
            last_image_url = VALUES(last_image_url),
            last_payload_summary = VALUES(last_payload_summary),
            updated_at = NOW()'
    );

    $statement->execute([
        'user_id'              => $userId,
        'last_status'          => $lastStatus,
        'last_message'         => $lastMessage,
        'last_image_url'       => $lastImageUrl,
        'last_payload_summary' => $lastPayloadSummary,
    ]);
}

function enforceRateLimit(PDO $pdo, int $userId): void
{
    if (!statusLogsHasCreatedAtColumn($pdo)) {
        return;
    }

    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM status_logs WHERE user_id = :user_id AND created_at >= (NOW() - INTERVAL 60 SECOND)'
    );
    $statement->execute(['user_id' => $userId]);
    $count = (int) $statement->fetchColumn();

    if ($count >= 20) {
        $message = 'Rate limit exceeded';
        $statusCode = 429;
        $logId = logStatus($pdo, $userId, 'warn', $statusCode, $message, [
            'rate_limit' => '20 requests per 60 seconds',
            'count'      => $count,
        ]);

        updateUserState($pdo, $userId, [
            'last_status'          => mapLevelToState('warn'),
            'last_message'         => $message,
            'last_image_url'       => null,
            'last_payload_summary' => 'rate limited',
        ]);

        respondAndExit([
            'ok'          => false,
            'status_code' => $statusCode,
            'message'     => $message,
            'user_id'     => $userId,
            'saved'       => [
                'status_logs_id'     => $logId,
                'user_state_updated' => true,
            ],
        ], $statusCode);
    }
}

function statusLogsHasCreatedAtColumn(PDO $pdo): bool
{
    static $hasColumn = null;

    if ($hasColumn !== null) {
        return $hasColumn;
    }

    try {
        $statement = $pdo->query("SHOW COLUMNS FROM status_logs LIKE 'created_at'");
        if ($statement === false) {
            $hasColumn = false;
            return $hasColumn;
        }

        $hasColumn = $statement->fetchColumn() !== false;
    } catch (PDOException) {
        $hasColumn = false;
    }

    return $hasColumn;
}

function determineLevel(array $payload): string
{
    $candidate = null;

    $possibleKeys = ['level', 'severity', 'status_level'];
    foreach ($possibleKeys as $key) {
        if (isset($payload[$key]) && is_string($payload[$key])) {
            $candidate = strtolower(trim($payload[$key]));
            break;
        }
    }

    if ($candidate !== null) {
        if (in_array($candidate, ['error', 'err', 'fatal', 'fail'], true)) {
            return 'error';
        }
        if (in_array($candidate, ['warn', 'warning', 'caution'], true)) {
            return 'warn';
        }
    }

    if (!empty($payload['errors'])) {
        return 'error';
    }

    if (!empty($payload['error'])) {
        return 'error';
    }

    if (isset($payload['status']) && is_string($payload['status'])) {
        $status = strtolower($payload['status']);
        if (str_contains($status, 'error') || str_contains($status, 'fail')) {
            return 'error';
        }
        if (str_contains($status, 'warn')) {
            return 'warn';
        }
    }

    if (!empty($payload['warnings']) || !empty($payload['warning'])) {
        return 'warn';
    }

    return 'info';
}

function determineStatusCode(array $payload, string $level): int
{
    if (isset($payload['status_code']) && is_numeric($payload['status_code'])) {
        $code = (int) $payload['status_code'];
        if ($code >= 100 && $code < 600) {
            return $code;
        }
    }

    if ($level === 'error') {
        return 422;
    }

    return 200;
}

function determineMessage(array $payload): ?string
{
    $candidates = ['message', 'status', 'text', 'detail'];

    foreach ($candidates as $key) {
        if (isset($payload[$key]) && is_string($payload[$key])) {
            $value = trim($payload[$key]);
            if ($value !== '') {
                return $value;
            }
        }
    }

    return null;
}

function extractImageUrl(array $payload): ?string
{
    $candidates = ['image_url', 'imageUrl', 'image', 'imageLink', 'thumbnail'];

    foreach ($candidates as $key) {
        if (!isset($payload[$key])) {
            continue;
        }

        $value = $payload[$key];
        if (is_array($value)) {
            foreach ($value as $item) {
                if (is_string($item) && filter_var($item, FILTER_VALIDATE_URL)) {
                    return $item;
                }
            }
            continue;
        }

        if (is_string($value) && filter_var($value, FILTER_VALIDATE_URL)) {
            return $value;
        }
    }

    return null;
}

function createPayloadSummary(?array $payload): ?string
{
    if ($payload === null) {
        return null;
    }

    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded === false || $encoded === '') {
        return null;
    }

    if (mb_strlen($encoded) > 200) {
        return mb_substr($encoded, 0, 200) . '…';
    }

    return $encoded;
}

function mapLevelToState(string $level): string
{
    return match ($level) {
        'error' => 'error',
        'warn'  => 'warn',
        default => 'ok',
    };
}
