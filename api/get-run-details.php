<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$config = require __DIR__ . '/../config.php';

session_start();
require_once __DIR__ . '/../db.php';

/**
 * @param array<string,mixed> $config
 */
function resolveUploadBaseUrl(array $config): string
{
    $candidates = [];

    if (defined('UPLOAD_BASE_URL')) {
        $candidate = trim((string) UPLOAD_BASE_URL);
        if ($candidate !== '') {
            $candidates[] = $candidate;
        }
    }

    if (defined('BASE_URL')) {
        $candidate = trim((string) BASE_URL);
        if ($candidate !== '') {
            $candidates[] = $candidate;
        }
    }

    if (defined('APP_URL')) {
        $candidate = trim((string) APP_URL);
        if ($candidate !== '') {
            $candidates[] = $candidate;
        }
    }

    foreach (['upload_base_url', 'upload_base', 'base_url', 'app_url', 'asset_base_url'] as $key) {
        if (isset($config[$key]) && is_string($config[$key])) {
            $candidate = trim($config[$key]);
            if ($candidate !== '') {
                $candidates[] = $candidate;
            }
        }
    }

    foreach ($candidates as $candidate) {
        $normalized = rtrim($candidate, '/');
        if ($normalized !== '') {
            return $normalized;
        }
    }

    return '';
}

function buildAbsoluteImageUrl(string $path, string $baseUrl): string
{
    $trimmed = trim($path);
    if ($trimmed === '') {
        return '';
    }

    if (preg_match('#^(?:[a-z][a-z0-9+.-]*:|//)#i', $trimmed)) {
        return $trimmed;
    }

    if (strpos($trimmed, '/') === 0) {
        return $trimmed;
    }

    if ($baseUrl !== '') {
        return $baseUrl . '/' . ltrim($trimmed, '/');
    }

    return '/' . ltrim($trimmed, '/');
}

$baseUrl = resolveUploadBaseUrl(is_array($config) ? $config : []);

$userId = $_SESSION['user']['id'] ?? null;
if (!$userId) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'not authenticated']);
    exit;
}

$runId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($runId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing run id']);
    exit;
}

$pdo = getPDO();

$stmt = $pdo->prepare('SELECT id, user_id, status, last_message FROM workflow_runs WHERE id = :id AND user_id = :user_id LIMIT 1');
$stmt->execute(['id' => $runId, 'user_id' => $userId]);
$run = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$run) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'run not found']);
    exit;
}

$noteStmt = $pdo->prepare('
    SELECT product_name, product_description, created_at
    FROM item_notes
    WHERE user_id = :user_id AND run_id = :run_id
    ORDER BY created_at ASC
    LIMIT 1
');
$noteStmt->execute(['user_id' => $userId, 'run_id' => $runId]);
$note = $noteStmt->fetch(PDO::FETCH_ASSOC) ?: null;

$imgStmt = $pdo->prepare('
    SELECT url, position
    FROM item_images
    WHERE user_id = :user_id AND run_id = :run_id
    ORDER BY position ASC, created_at ASC
');
$imgStmt->execute(['user_id' => $userId, 'run_id' => $runId]);
$images = $imgStmt->fetchAll(PDO::FETCH_ASSOC);

$originalStmt = $pdo->prepare('
    SELECT file_path, original_name
    FROM run_images
    WHERE run_id = :run_id
    ORDER BY created_at ASC, id ASC
');
$originalStmt->execute(['run_id' => $runId]);
$originalImages = $originalStmt->fetchAll(PDO::FETCH_ASSOC);

$images = array_map(
    static function ($row) use ($baseUrl) {
        if (!is_array($row)) {
            return $row;
        }

        $url = isset($row['url']) ? (string) $row['url'] : '';
        if ($url !== '') {
            $absolute = buildAbsoluteImageUrl($url, $baseUrl);
            if ($absolute !== '') {
                $row['url'] = $absolute;
            }
        }

        return $row;
    },
    $images
);

$originalImages = array_values(
    array_filter(
        array_map(
            static function ($row) use ($baseUrl) {
                if (!is_array($row)) {
                    return null;
                }

                $path = isset($row['file_path']) ? (string) $row['file_path'] : '';
                $absolute = buildAbsoluteImageUrl($path, $baseUrl);

                return $absolute !== '' ? $absolute : null;
            },
            $originalImages
        ),
        static fn ($value) => $value !== null
    )
);

$logStmt = $pdo->prepare('
    SELECT level, status_code, message, created_at
    FROM status_logs
    WHERE user_id = :user_id AND run_id = :run_id
    ORDER BY created_at DESC
    LIMIT 20
');
$logStmt->execute(['user_id' => $userId, 'run_id' => $runId]);
$logs = $logStmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'ok' => true,
    'data' => [
        'run' => $run,
        'note' => $note,
        'images' => $images,
        'original_images' => $originalImages,
        'logs' => $logs,
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
