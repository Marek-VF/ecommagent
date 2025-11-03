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

function sanitizeUrl(?string $value): ?string
{
    $value = sanitizeString($value, 1024);
    if ($value === null) {
        return null;
    }

    return filter_var($value, FILTER_VALIDATE_URL) ? $value : null;
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

        return sanitizeString($value, $maxLength);
    }

    return null;
}

function normalizeLogType(?string $type): string
{
    if ($type === null) {
        return 'info';
    }

    return match (strtolower(trim($type))) {
        'warn', 'warning' => 'warn',
        'error', 'err', 'danger', 'fail', 'failed' => 'error',
        default => 'info',
    };
}

function aggregateLevel(array $logs): string
{
    $level = 'info';
    foreach ($logs as $log) {
        if (($log['type'] ?? 'info') === 'error') {
            return 'error';
        }
        if (($log['type'] ?? 'info') === 'warn') {
            $level = 'warn';
        }
    }

    return $level;
}

function levelToState(string $level): string
{
    return match ($level) {
        'error' => 'error',
        'warn'  => 'warn',
        default => 'ok',
    };
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

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        jsonResponse(400, [
            'ok'      => false,
            'message' => 'JSON konnte nicht gelesen werden.',
        ]);
    }

    $productName = extractSanitizedField($decoded, ['product_name', 'produktname'], 255);
    $productDescription = extractSanitizedField($decoded, ['product_description', 'produktbeschreibung']);
    $statusMessage = extractSanitizedField($decoded, ['statusmessage', 'status_message', 'status'], 255);

    $statusLogs = [];
    if (isset($decoded['statuslog']) && is_array($decoded['statuslog'])) {
        foreach ($decoded['statuslog'] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $message = isset($entry['message']) && is_string($entry['message'])
                ? sanitizeString($entry['message'], 255)
                : null;
            if ($message === null) {
                continue;
            }
            $type = normalizeLogType(isset($entry['type']) && is_string($entry['type']) ? $entry['type'] : null);

            $timestamp = null;
            if (isset($entry['timestamp']) && is_string($entry['timestamp'])) {
                try {
                    $timestamp = new DateTimeImmutable($entry['timestamp']);
                } catch (Exception) {
                    $timestamp = null;
                }
            }

            $statusLogs[] = [
                'message'   => $message,
                'type'      => $type,
                'timestamp' => $timestamp,
            ];
        }
    }

    $imageUrls = [];
    for ($i = 1; $i <= 3; $i++) {
        $key = 'image_' . $i;
        if (!isset($decoded[$key]) || !is_string($decoded[$key])) {
            continue;
        }
        $url = sanitizeUrl($decoded[$key]);
        if ($url !== null) {
            $imageUrls[] = [
                'url'      => $url,
                'position' => $i,
            ];
        }
    }

    $userId = resolveUserId();
    if ($userId === null || $userId <= 0) {
        jsonResponse(401, [
            'ok'      => false,
            'message' => 'Kein Benutzer ermittelt.',
        ]);
    }

    $pdo = auth_pdo();
    $pdo->beginTransaction();

    $shouldInsertNote = ($productName !== null || $productDescription !== null);
    $noteId = null;
    $imagesSaved = 0;

    if ($shouldInsertNote) {
        $insertNote = $pdo->prepare(
            'INSERT INTO item_notes (user_id, product_name, product_description, source) VALUES (:user_id, :product_name, :product_description, :source)'
        );
        $insertNote->execute([
            ':user_id'             => $userId,
            ':product_name'        => $productName,
            ':product_description' => $productDescription,
            ':source'              => 'n8n',
        ]);

        $noteId = (int) $pdo->lastInsertId();

        if ($imageUrls !== []) {
            $insertImage = $pdo->prepare(
                'INSERT INTO item_images (user_id, note_id, url, position) VALUES (:user_id, :note_id, :url, :position)'
            );
            foreach ($imageUrls as $image) {
                $insertImage->execute([
                    ':user_id' => $userId,
                    ':note_id' => $noteId,
                    ':url'     => $image['url'],
                    ':position'=> $image['position'],
                ]);
                $imagesSaved++;
            }
        }
    } elseif ($statusLogs === []) {
        $statusLogs[] = [
            'message'   => 'Payload ohne Produktinhalte empfangen',
            'type'      => 'info',
            'timestamp' => new DateTimeImmutable(),
        ];
    }

    $logsSaved = 0;
    if ($statusLogs !== []) {
        $insertLog = $pdo->prepare(
            'INSERT INTO status_logs (user_id, level, status_code, message, source, created_at) VALUES (:user_id, :level, NULL, :message, :source, :created_at)'
        );
        foreach ($statusLogs as $log) {
            $createdAt = $log['timestamp'] instanceof DateTimeImmutable
                ? $log['timestamp']->format('Y-m-d H:i:s')
                : (new DateTimeImmutable())->format('Y-m-d H:i:s');

            $insertLog->execute([
                ':user_id'    => $userId,
                ':level'      => $log['type'],
                ':message'    => $log['message'],
                ':source'     => 'receiver',
                ':created_at' => $createdAt,
            ]);
            $logsSaved++;
        }
    }

    $overallLevel = aggregateLevel($statusLogs);
    $lastImageUrl = null;
    if ($imageUrls !== []) {
        $last = end($imageUrls);
        if (is_array($last)) {
            $lastImageUrl = $last['url'];
        }
        reset($imageUrls);
    }

    $stateHasMeaningfulContent = $shouldInsertNote || $statusMessage !== null;
    if ($stateHasMeaningfulContent) {
        $payloadSummary = json_encode([
            'produktname'                => $productName,
            'produktbeschreibung_length' => $productDescription !== null ? mb_strlen($productDescription) : 0,
            'statusmessage_length'       => $statusMessage !== null ? mb_strlen($statusMessage) : 0,
            'images_count'               => count($imageUrls),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $upsertState = $pdo->prepare(
            'INSERT INTO user_state (user_id, last_status, last_message, last_image_url, last_payload_summary, updated_at)'
             . ' VALUES (:user_id, :last_status, :last_message, :last_image_url, :last_payload_summary, NOW())'
             . ' ON DUPLICATE KEY UPDATE'
             . '                last_status = VALUES(last_status),'
             . '                last_message = VALUES(last_message),'
             . '                last_image_url = VALUES(last_image_url),'
             . '                last_payload_summary = VALUES(last_payload_summary),'
             . '                updated_at = VALUES(updated_at)'
        );
        $upsertState->execute([
            ':user_id'               => $userId,
            ':last_status'           => levelToState($overallLevel),
            ':last_message'          => $statusMessage,
            ':last_image_url'        => $lastImageUrl,
            ':last_payload_summary'  => $payloadSummary,
        ]);
    }


    $pdo->commit();

    jsonResponse(200, [
        'ok'           => true,
        'message'      => $shouldInsertNote
            ? 'content saved'
            : 'payload received but contained no content fields',
        'user_id'      => $userId,
        'note_id'      => $noteId,
        'inserted'     => $shouldInsertNote,
        'images_saved' => $imagesSaved,
        'logs_saved'   => $logsSaved,
    ]);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $code = $exception instanceof RuntimeException ? 400 : 500;
    if ($exception instanceof PDOException) {
        $code = 500;
    }

    jsonResponse($code, [
        'ok'      => false,
        'message' => $exception->getMessage(),
    ]);
} finally {
    restore_error_handler();
}
