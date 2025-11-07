<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$config = require __DIR__ . '/../config.php';

session_start();
require_once __DIR__ . '/../db.php';

function make_abs_url(?string $path): string
{
    global $config;

    if ($path === null) {
        return '';
    }

    $trimmed = trim($path);
    if ($trimmed === '') {
        return '';
    }

    if (str_starts_with($trimmed, 'http://') || str_starts_with($trimmed, 'https://')) {
        return $trimmed;
    }

    if (defined('UPLOAD_BASE_URL')) {
        $base = (string) UPLOAD_BASE_URL;
    } elseif (defined('BASE_URL')) {
        $base = (string) BASE_URL;
    } elseif (defined('APP_URL')) {
        $base = (string) APP_URL;
    } else {
        $base = '';
        if (is_array($config)) {
            if (isset($config['upload_base_url']) && is_string($config['upload_base_url'])) {
                $base = $config['upload_base_url'];
            } elseif (isset($config['asset_base_url']) && is_string($config['asset_base_url'])) {
                $base = $config['asset_base_url'];
            } elseif (isset($config['base_url']) && is_string($config['base_url'])) {
                $base = $config['base_url'];
            }
        }
    }

    $base = trim($base);

    if ($base !== '') {
        return rtrim($base, '/') . '/' . ltrim($trimmed, '/');
    }

    return $trimmed;
}

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
    static function ($row) {
        if (!is_array($row)) {
            return $row;
        }

        $url = isset($row['url']) ? (string) $row['url'] : '';
        if ($url !== '') {
            $absolute = make_abs_url($url);
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
            static function ($row) {
                if (!is_array($row)) {
                    return null;
                }

                $path = isset($row['file_path']) ? (string) $row['file_path'] : '';
                $absolute = make_abs_url($path);

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

$response = [
    'ok' => true,
    'data' => [
        'run' => $run,
        'note' => $note,
        'images' => $images,
        'original_images' => $originalImages,
        'logs' => $logs,
    ],
];

if (isset($response['data']['images']) && is_array($response['data']['images'])) {
    foreach ($response['data']['images'] as $index => $imageRow) {
        if (is_array($imageRow) && isset($imageRow['url'])) {
            $response['data']['images'][$index]['url'] = make_abs_url($imageRow['url']);
        } elseif (is_string($imageRow)) {
            $response['data']['images'][$index] = make_abs_url($imageRow);
        }
    }
}

if (isset($response['data']['original_images']) && is_array($response['data']['original_images'])) {
    foreach ($response['data']['original_images'] as $index => $imagePath) {
        $response['data']['original_images'][$index] = make_abs_url(is_string($imagePath) ? $imagePath : '');
    }
}

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
