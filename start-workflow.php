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

$timestamp = static fn (): string => gmdate('c');

$normalizePositiveInt = static function (mixed $value): ?int {
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

    if (is_numeric($value)) {
        $intValue = (int) $value;
        return $intValue > 0 ? $intValue : null;
    }

    return null;
};

if (!auth_is_logged_in()) {
    $respond([
        'success'   => false,
        'status'    => 'unauthorized',
        'message'   => 'Anmeldung erforderlich.',
        'timestamp' => $timestamp(),
        'logout'    => true,
    ], 401);
}

$user = auth_user();
$userId = isset($user['id']) && is_numeric($user['id']) ? (int) $user['id'] : 0;

if ($userId <= 0) {
    $respond([
        'success'   => false,
        'status'    => 'unauthorized',
        'message'   => 'Nicht angemeldet. Bitte erneut einloggen.',
        'timestamp' => $timestamp(),
        'logout'    => true,
    ], 401);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $respond([
        'success'   => false,
        'status'    => 'error',
        'message'   => 'Methode nicht erlaubt.',
        'timestamp' => $timestamp(),
    ], 405);
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

$runId = $normalizePositiveInt($data['run_id'] ?? null);
if ($runId === null) {
    $respond([
        'success'   => false,
        'status'    => 'error',
        'message'   => 'run_id fehlt.',
        'timestamp' => $timestamp(),
    ], 400);
}

$requestedUserId = $normalizePositiveInt($data['user_id'] ?? null);
if ($requestedUserId !== null && $requestedUserId !== $userId) {
    $respond([
        'success'   => false,
        'status'    => 'forbidden',
        'message'   => 'Keine Berechtigung für diesen Workflow.',
        'timestamp' => $timestamp(),
    ], 403);
}

try {
    $config = auth_config();
    $webhook = $config['workflow_webhook'] ?? null;
    if (!is_string($webhook) || trim($webhook) === '') {
        throw new RuntimeException('Workflow-Webhook ist nicht konfiguriert.');
    }

    $baseUploadDir = $config['upload_dir'] ?? (__DIR__ . '/uploads');
    $baseUploadDir = rtrim((string) $baseUploadDir, " \\\t\n\r\0\x0B/\\");
    if ($baseUploadDir === '') {
        $baseUploadDir = __DIR__ . '/uploads';
    }

    $baseUrl = rtrim((string) ($config['base_url'] ?? ''), '/');

    $pdo = auth_pdo();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $runStmt = $pdo->prepare('SELECT id, status, last_message, original_image FROM workflow_runs WHERE id = :run_id AND user_id = :user_id LIMIT 1');
    $runStmt->execute([
        ':run_id'  => $runId,
        ':user_id' => $userId,
    ]);
    $run = $runStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($run === null) {
        $respond([
            'success'   => false,
            'status'    => 'not_found',
            'message'   => 'Workflow nicht gefunden.',
            'timestamp' => $timestamp(),
        ], 404);
    }

    $statusValue = isset($run['status']) ? strtolower(trim((string) $run['status'])) : '';
    if ($statusValue === 'running') {
        $respond([
            'success'   => false,
            'status'    => 'conflict',
            'message'   => 'Workflow läuft bereits.',
            'timestamp' => $timestamp(),
        ], 409);
    }

    if ($statusValue === 'finished') {
        $respond([
            'success'   => false,
            'status'    => 'conflict',
            'message'   => 'Workflow wurde bereits abgeschlossen.',
            'timestamp' => $timestamp(),
        ], 409);
    }

    $normalizeImagePath = static function (mixed $value) use ($baseUrl): ?string {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        $normalized = str_replace('\\', '/', $trimmed);

        if ($baseUrl !== '') {
            $baseCandidates = [$baseUrl, rtrim($baseUrl, '/')];
            foreach ($baseCandidates as $candidate) {
                if ($candidate !== '' && str_starts_with($normalized, $candidate)) {
                    $normalized = substr($normalized, strlen($candidate));
                    break;
                }
            }
        }

        if (preg_match('#^https?://#i', $normalized)) {
            $parsed = parse_url($normalized);
            if ($parsed !== false && isset($parsed['path'])) {
                $normalized = $parsed['path'];
            }
        }

        $normalized = '/' . ltrim($normalized, '/');
        return $normalized !== '/' ? $normalized : null;
    };

    $requestedImages = [];
    if (isset($data['images'])) {
        $rawImages = $data['images'];
        if (is_string($rawImages)) {
            $decoded = json_decode($rawImages, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $rawImages = $decoded;
            }
        }

        if (is_array($rawImages)) {
            foreach ($rawImages as $candidate) {
                if (is_array($candidate) && isset($candidate['path'])) {
                    $candidate = $candidate['path'];
                } elseif (is_array($candidate) && isset($candidate['url'])) {
                    $candidate = $candidate['url'];
                }

                $normalized = $normalizeImagePath($candidate);
                if ($normalized !== null) {
                    $requestedImages[] = $normalized;
                }
            }
        }
    }

    $requestedImages = array_values(array_unique($requestedImages));

    $imagesStmt = $pdo->prepare('SELECT file_path, original_name FROM run_images WHERE run_id = :run_id ORDER BY created_at ASC, id ASC');
    $imagesStmt->execute([
        ':run_id' => $runId,
    ]);
    $dbImages = $imagesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $dbImageMap = [];
    foreach ($dbImages as $row) {
        $path = isset($row['file_path']) ? trim((string) $row['file_path']) : '';
        if ($path === '') {
            continue;
        }

        $normalized = '/' . ltrim(str_replace('\\', '/', $path), '/');
        $dbImageMap[$normalized] = [
            'path'          => $normalized,
            'stored_path'   => $path,
            'original_name' => isset($row['original_name']) ? (string) $row['original_name'] : null,
        ];
    }

    $legacyImage = isset($run['original_image']) ? trim((string) $run['original_image']) : '';
    $legacyNormalized = $legacyImage !== '' ? '/' . ltrim(str_replace('\\', '/', $legacyImage), '/') : null;

    $selectedImages = [];
    if ($requestedImages !== []) {
        foreach ($requestedImages as $path) {
            if (isset($dbImageMap[$path])) {
                $selectedImages[] = $dbImageMap[$path];
            } elseif ($legacyNormalized !== null && $path === $legacyNormalized) {
                $selectedImages[] = [
                    'path'        => $legacyNormalized,
                    'stored_path' => ltrim($legacyNormalized, '/'),
                    'original_name' => basename($legacyNormalized),
                ];
            }
        }
    }

    if ($selectedImages === []) {
        $selectedImages = array_values($dbImageMap);
    }

    if ($selectedImages === [] && $legacyNormalized !== null) {
        $selectedImages[] = [
            'path'        => $legacyNormalized,
            'stored_path' => ltrim($legacyNormalized, '/'),
            'original_name' => basename($legacyNormalized),
        ];
    }

    $expectedPrefix = sprintf('uploads/%d/%d/', $userId, $runId);
    $absoluteImages = [];
    foreach ($selectedImages as $image) {
        $path = isset($image['path']) ? (string) $image['path'] : '';
        if ($path === '') {
            continue;
        }

        $relativePath = ltrim(str_replace('\\', '/', $path), '/');
        if (!str_starts_with($relativePath, $expectedPrefix)) {
            continue;
        }

        $filename = basename($relativePath);
        if ($filename === '') {
            continue;
        }

        $absolutePath = $baseUploadDir . DIRECTORY_SEPARATOR . $userId . DIRECTORY_SEPARATOR . $runId . DIRECTORY_SEPARATOR . $filename;
        if (!is_file($absolutePath)) {
            continue;
        }

        $absoluteImages[] = [
            'path'        => '/' . ltrim($relativePath, '/'),
            'absolute'    => $absolutePath,
            'original_name' => isset($image['original_name']) ? (string) $image['original_name'] : $filename,
        ];
    }

    if ($absoluteImages === []) {
        $respond([
            'success'   => false,
            'status'    => 'error',
            'message'   => 'Kein Originalbild vorhanden.',
            'timestamp' => $timestamp(),
        ], 400);
    }

    $publicImagePaths = array_values(array_unique(array_map(
        static fn (array $image): string => $image['path'],
        $absoluteImages
    )));

    $publicImageUrls = array_map(
        static function (string $path) use ($baseUrl): string {
            $normalized = '/' . ltrim($path, '/');
            if ($baseUrl !== '' && !preg_match('#^https?://#i', $normalized)) {
                return rtrim($baseUrl, '/') . $normalized;
            }

            return $normalized;
        },
        $publicImagePaths
    );

    $postPayload = [
        'user_id'    => $userId,
        'run_id'     => $runId,
        'images'     => $publicImagePaths,
        'image_urls' => $publicImageUrls,
        'timestamp'  => $timestamp(),
    ];

    $postBody = json_encode($postPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($postBody === false) {
        throw new RuntimeException('Webhook-Payload konnte nicht serialisiert werden.');
    }

    $ch = curl_init($webhook);
    if ($ch === false) {
        throw new RuntimeException('Webhook konnte nicht initialisiert werden.');
    }

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => $postBody,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json, */*;q=0.8',
            'Content-Type: application/json',
        ],
    ]);

    $webhookResponse = curl_exec($ch);
    $webhookStatus   = curl_getinfo($ch, CURLINFO_RESPONSE_CODE) ?: null;
    $curlError       = curl_error($ch);
    curl_close($ch);

    if ($webhookResponse === false) {
        $errorMessage = $curlError !== '' ? $curlError : 'Unbekannter Fehler bei der Webhook-Ausführung.';
        throw new RuntimeException('Webhook-Aufruf fehlgeschlagen: ' . $errorMessage);
    }

    $forwardResponse = json_decode($webhookResponse, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $forwardResponse = $webhookResponse;
    }

    if (!is_int($webhookStatus) || $webhookStatus < 200 || $webhookStatus >= 300) {
        throw new RuntimeException('Workflow-Webhook antwortete mit Status ' . ($webhookStatus ?? 'unbekannt') . '.');
    }

    $pdo->beginTransaction();
    try {
        $runMessage = 'Workflow gestartet';
        $updateRun = $pdo->prepare(
            'UPDATE workflow_runs SET status = :status, last_message = :last_message, started_at = COALESCE(started_at, NOW()) WHERE id = :run_id AND user_id = :user_id'
        );
        $updateRun->execute([
            ':status'       => 'running',
            ':last_message' => $runMessage,
            ':run_id'       => $runId,
            ':user_id'      => $userId,
        ]);

        $stateStmt = $pdo->prepare(
            'INSERT INTO user_state (user_id, current_run_id, last_status, last_message, updated_at) VALUES (:user_id, :current_run_id, :last_status, :last_message, NOW())'
            . ' ON DUPLICATE KEY UPDATE'
            . '     current_run_id = VALUES(current_run_id),'
            . '     last_status = VALUES(last_status),'
            . '     last_message = VALUES(last_message),'
            . '     updated_at = NOW()'
        );
        $stateStmt->execute([
            ':user_id'        => $userId,
            ':current_run_id' => $runId,
            ':last_status'    => 'running',
            ':last_message'   => $runMessage,
        ]);

        $pdo->commit();
    } catch (Throwable $transactionException) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $transactionException;
    }

    $respond([
        'success'          => true,
        'status'           => 'ok',
        'message'          => 'Workflow gestartet.',
        'run_id'           => $runId,
        'user_id'          => $userId,
        'images'           => $publicImagePaths,
        'image_urls'       => $publicImageUrls,
        'timestamp'        => $timestamp(),
        'webhook_status'   => $webhookStatus,
        'webhook_response' => $forwardResponse,
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
