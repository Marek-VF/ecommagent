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

$respond = static function (array $payload, int $statusCode = 200): never {
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

$timestamp = static fn (): string => (new DateTimeImmutable('now'))->format(DATE_ATOM);

$config = auth_config();

$apiToken = $config['receiver_api_token'] ?? '';
if (!is_string($apiToken) || $apiToken === '') {
    $respond([
        'success'   => false,
        'status'    => 'error',
        'message'   => 'API-Token ist nicht konfiguriert.',
        'timestamp' => $timestamp(),
    ], 500);
}

$allowedIps = $config['receiver_api_allowed_ips'] ?? [];
if (!is_array($allowedIps)) {
    $allowedIps = [];
}

$extractAuthorizationHeader = static function (): ?string {
    $candidates = [
        $_SERVER['HTTP_AUTHORIZATION'] ?? null,
        $_SERVER['Authorization'] ?? null,
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null,
    ];
    foreach ($candidates as $c) {
        if (is_string($c) && $c !== '') {
            return trim($c);
        }
    }
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        foreach ($headers as $k => $v) {
            if (strcasecmp((string) $k, 'Authorization') === 0 && is_string($v) && $v !== '') {
                return trim($v);
            }
        }
    }
    return null;
};

$parseBearerToken = static function (?string $header): ?string {
    if (!is_string($header)) {
        return null;
    }

    if (stripos($header, 'Bearer ') !== 0) {
        return null;
    }

    $token = trim(substr($header, 7));

    return $token !== '' ? $token : null;
};

$authorizationHeader = $extractAuthorizationHeader();
$providedToken = $parseBearerToken($authorizationHeader);

if ($providedToken === null) {
    $fallbackToken = null;
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            foreach ($headers as $key => $value) {
                if (!is_string($key)) {
                    continue;
                }

                if (strcasecmp($key, 'X-Api-Token') === 0 || strcasecmp($key, 'X-Authorization') === 0) {
                    if (is_string($value)) {
                        $candidate = trim($value);
                        if ($candidate !== '') {
                            $fallbackToken = $candidate;
                            break;
                        }
                    }
                }
            }
        }
    }

    if ($fallbackToken !== null) {
        $httpsEnabled = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && strtolower((string) $_SERVER['HTTPS']) !== 'off';
        if (!$httpsEnabled) {
            $respond([
                'success'   => false,
                'status'    => 'error',
                'message'   => 'Alternative Authentifizierung erfordert HTTPS.',
                'timestamp' => $timestamp(),
            ], 400);
        }

        $providedToken = $fallbackToken;
    }
}

if ($providedToken === null || !hash_equals($apiToken, $providedToken)) {
    header('WWW-Authenticate: Bearer');
    $respond([
        'success'   => false,
        'status'    => 'unauthorized',
        'message'   => 'Ungültiger oder fehlender Bearer-Token.',
        'timestamp' => $timestamp(),
    ], 401);
}

if ($allowedIps !== []) {
    $remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!is_string($remoteIp) || $remoteIp === '' || !in_array($remoteIp, array_map('strval', $allowedIps), true)) {
        $respond([
            'success'   => false,
            'status'    => 'forbidden',
            'message'   => 'Zugriff für diese IP-Adresse nicht erlaubt.',
            'timestamp' => $timestamp(),
        ], 403);
    }
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $respond([
            'success'   => false,
            'status'    => 'error',
            'message'   => 'Methode nicht erlaubt.',
            'timestamp' => $timestamp(),
        ], 405);
    }

    $uploadDir = rtrim($config['upload_dir'] ?? (__DIR__ . '/uploads/'), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $dataFile  = $config['data_file'] ?? (__DIR__ . '/data.json');
    $baseUrl   = rtrim((string) ($config['base_url'] ?? ''), '/');

    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0777, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Upload-Verzeichnis konnte nicht erstellt werden.');
    }

    $existingData = [];
    if (is_file($dataFile)) {
        $contents = file_get_contents($dataFile);
        if ($contents !== false && $contents !== '') {
            $decoded = json_decode($contents, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $existingData = $decoded;
            }
        }
    }

    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $newData = [];
    $logEntries = [];

    $normalizeLogType = static function (?string $type): ?string {
        if (!is_string($type)) {
            return null;
        }

        $normalized = strtolower(trim($type));

        return match ($normalized) {
            'success', 'ok', 'positive', 'completed' => 'success',
            'warning', 'warn', 'caution'            => 'warning',
            'error', 'fail', 'failed', 'danger'     => 'error',
            'info', 'neutral', 'note', 'message'    => 'info',
            default                                 => null,
        };
    };

    $determineTypeFromStatusCode = static function (?int $statusCode): string {
        if ($statusCode !== null) {
            if ($statusCode >= 200 && $statusCode < 300) {
                return 'success';
            }

            if ($statusCode >= 400 && $statusCode < 500) {
                return 'warning';
            }

            if ($statusCode >= 500 && $statusCode < 600) {
                return 'error';
            }
        }

        return 'info';
    };

    $isListArray = static function (array $array): bool {
        if (function_exists('array_is_list')) {
            return array_is_list($array);
        }

        $expectedKey = 0;
        foreach ($array as $key => $_) {
            if ($key !== $expectedKey) {
                return false;
            }

            $expectedKey++;
        }

        return true;
    };

    $extractStatusPayload = static function (mixed $value) use ($normalizeLogType, $determineTypeFromStatusCode): ?array {
        if (is_array($value)) {
            $message = null;
            if (array_key_exists('message', $value)) {
                $message = $value['message'];
            } elseif (array_key_exists('msg', $value)) {
                $message = $value['msg'];
            }

            if ($message !== null) {
                $trimmedMessage = trim((string) $message);
                if ($trimmedMessage === '') {
                    return null;
                }

                $statusCode = null;
                foreach (['statuscode', 'status_code', 'code'] as $codeKey) {
                    if (isset($value[$codeKey]) && is_numeric($value[$codeKey])) {
                        $statusCode = (int) $value[$codeKey];
                        break;
                    }
                }

                $type = $determineTypeFromStatusCode($statusCode);
                if (isset($value['type'])) {
                    $providedType = $normalizeLogType(is_string($value['type']) ? $value['type'] : null);
                    if ($providedType !== null) {
                        $type = $providedType;
                    }
                }

                return [
                    'message' => $trimmedMessage,
                    'type'    => $type,
                ];
            }
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return null;
            }

            return [
                'message' => $trimmed,
                'type'    => 'info',
            ];
        }

        return null;
    };

    $collectStatusPayloads = static function (mixed $value) use (&$collectStatusPayloads, $extractStatusPayload, $isListArray): array {
        if (is_array($value) && $isListArray($value)) {
            $collected = [];
            foreach ($value as $item) {
                $collected = array_merge($collected, $collectStatusPayloads($item));
            }

            return $collected;
        }

        $single = $extractStatusPayload($value);

        return $single !== null ? [$single] : [];
    };

    $appendLogEntry = static function (string $message, ?string $type = null) use (&$logEntries, $timestamp, $normalizeLogType): void {
        $trimmedMessage = trim($message);
        if ($trimmedMessage === '') {
            return;
        }

        $entry = [
            'timestamp' => $timestamp(),
            'message'   => $trimmedMessage,
        ];

        $normalizedType = $normalizeLogType($type);
        if ($normalizedType !== null) {
            $entry['type'] = $normalizedType;
        }

        $logEntries[] = $entry;
    };

    $storeFile = static function (array $file, string $uploadDir): string {
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload fehlgeschlagen: ' . ($file['error'] ?? 'unbekannt'));
        }

        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new RuntimeException('Ungültige Dateiübertragung.');
        }

        $originalName = $file['name'] ?? ('upload_' . date('Ymd_His'));
        $sanitized = preg_replace('/[\\\\\/\x00-\x1F\x7F]+/u', '_', (string) $originalName);
        $sanitized = trim($sanitized) === '' ? 'upload_' . date('Ymd_His') : trim($sanitized);
        $storedName = basename($sanitized);

        $destination = $uploadDir . $storedName;
        $nameWithoutExtension = pathinfo($storedName, PATHINFO_FILENAME);
        $extension = pathinfo($storedName, PATHINFO_EXTENSION);
        $extensionWithDot = $extension !== '' ? '.' . $extension : '';

        $counter = 1;
        while (file_exists($destination)) {
            $storedName = sprintf('%s_%d%s', $nameWithoutExtension, $counter, $extensionWithDot);
            $destination = $uploadDir . $storedName;
            $counter++;
        }

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            throw new RuntimeException('Datei konnte nicht gespeichert werden.');
        }

        return $storedName;
    };

    $normalizeValue = static function (string $key, mixed $value): mixed {
        if ($key === 'isrunning') {
            if (is_bool($value)) {
                return $value;
            }

            $normalized = strtolower(trim((string) $value));
            if (in_array($normalized, ['true', '1', 'yes'], true)) {
                return true;
            }
            if (in_array($normalized, ['false', '0', 'no'], true)) {
                return false;
            }
        }

        return $value;
    };

    $allowedImageFields = ['image_1', 'image_2', 'image_3'];

    if (str_contains($contentType, 'application/json')) {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            throw new RuntimeException('JSON-Daten fehlen.');
        }

        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            throw new RuntimeException('JSON konnte nicht verarbeitet werden.');
        }

        foreach ($decoded as $key => $value) {
            $normalizedKey = (string) $key;
            $newData[$normalizedKey] = $normalizeValue($normalizedKey, $value);

            if (in_array($normalizedKey, ['statusmessage', 'status_message'], true)) {
                $statusPayloads = $collectStatusPayloads($value);
                if ($statusPayloads !== []) {
                    $lastPayload = end($statusPayloads);
                    $newData[$normalizedKey] = $lastPayload['message'];
                    foreach ($statusPayloads as $payload) {
                        $appendLogEntry($payload['message'], $payload['type']);
                    }
                    continue;
                }
            }

            if ($normalizedKey === 'error' && is_string($value)) {
                $appendLogEntry($value, 'error');
            }
        }
    } else {
        $targetField = $_POST['field'] ?? 'image_1';
        if (!in_array($targetField, $allowedImageFields, true)) {
            $targetField = 'image_1';
        }

        foreach ($_POST as $key => $value) {
            if ($key === 'field') {
                continue;
            }

            $normalizedKey = (string) $key;
            $newData[$normalizedKey] = $normalizeValue($normalizedKey, $value);

            if (in_array($normalizedKey, ['statusmessage', 'status_message'], true)) {
                $statusPayloads = $collectStatusPayloads($value);
                if ($statusPayloads !== []) {
                    $lastPayload = end($statusPayloads);
                    $newData[$normalizedKey] = $lastPayload['message'];
                    foreach ($statusPayloads as $payload) {
                        $appendLogEntry($payload['message'], $payload['type']);
                    }
                    continue;
                }
            }

            if ($normalizedKey === 'error' && is_string($value)) {
                $appendLogEntry($value, 'error');
            }
        }

        $normalizeFiles = static function (array $files): array {
            $normalized = [];
            foreach ($files as $field => $data) {
                if (is_array($data['name'])) {
                    $fileCount = count($data['name']);
                    for ($i = 0; $i < $fileCount; $i++) {
                        $normalized[$field . ($fileCount > 1 ? '_' . ($i + 1) : '')] = [
                            'name'     => $data['name'][$i],
                            'type'     => $data['type'][$i] ?? null,
                            'tmp_name' => $data['tmp_name'][$i] ?? null,
                            'error'    => $data['error'][$i] ?? null,
                            'size'     => $data['size'][$i] ?? null,
                        ];
                    }
                } else {
                    $normalized[$field] = $data;
                }
            }

            return $normalized;
        };

        $files = $normalizeFiles($_FILES);

        if (isset($files['file']) && is_array($files['file'])) {
            $storedName = $storeFile($files['file'], $uploadDir);
            $origin = $baseUrl;
            if ($origin === '') {
                $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
                    || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
                $scheme = $isHttps ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST']
                    ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
                $origin = $scheme . '://' . $host;

                $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
                $scriptDir = ($scriptDir === '.' ? '' : $scriptDir);
                if ($scriptDir !== '') {
                    $origin .= ($scriptDir[0] === '/' ? '' : '/') . trim($scriptDir, '/');
                }
            }

            $fileUrl = rtrim($origin, '/') . '/uploads/' . $storedName;
            $newData[$targetField] = $fileUrl;
        }
    }

    $merged = array_merge($existingData, $newData);

    if (!empty($logEntries)) {
        $existingLog = [];
        if (isset($merged['statuslog']) && is_array($merged['statuslog'])) {
            $existingLog = $merged['statuslog'];
        }

        $merged['statuslog'] = array_merge($existingLog, $logEntries);
    }
    $merged['updated_at'] = $timestamp();

    file_put_contents($dataFile, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    $respond([
        'success'   => true,
        'status'    => 'ok',
        'message'   => 'Daten aktualisiert.',
        'timestamp' => $timestamp(),
        'data'      => $merged,
    ]);
} catch (Throwable $exception) {
    $respond([
        'success'   => false,
        'status'    => 'error',
        'message'   => $exception->getMessage(),
        'timestamp' => $timestamp(),
    ], $exception instanceof RuntimeException ? 400 : 500);
} finally {
    restore_error_handler();
}
