<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$config = require __DIR__ . '/../config.php';
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

function fetchOriginalImageUrls(PDO $pdo, int $runId): array
{
    if ($runId <= 0) {
        return [];
    }

    $statement = $pdo->prepare(
        'SELECT file_path
         FROM run_images
         WHERE run_id = :run_id
         ORDER BY created_at ASC, id ASC'
    );
    $statement->execute([':run_id' => $runId]);
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

    $images = [];
    foreach ($rows as $row) {
        $filePath = isset($row['file_path']) ? (string) $row['file_path'] : '';
        $absolute = make_abs_url($filePath);
        if ($absolute !== '') {
            $images[] = $absolute;
        }
    }

    return $images;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$sessionUserId = $_SESSION['user']['id'] ?? null;
if (!is_numeric($sessionUserId) || (int) $sessionUserId <= 0) {
    http_response_code(401);
    echo json_encode([
        'ok'     => false,
        'error'  => 'not authenticated',
        'logout' => true,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$userId = (int) $sessionUserId;
$requestedRunId = null;
if (isset($_GET['run_id'])) {
    $runIdParam = trim((string) $_GET['run_id']);
    if ($runIdParam !== '' && ctype_digit($runIdParam)) {
        $requestedRunId = (int) $runIdParam;
        if ($requestedRunId <= 0) {
            $requestedRunId = null;
        }
    }
}

try {
    $pdo = getPDO();
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => 'db error',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $stateStatement = $pdo->prepare(
        'SELECT current_run_id, last_status, last_message
         FROM user_state
         WHERE user_id = :user_id
         ORDER BY updated_at DESC
         LIMIT 1'
    );
    $stateStatement->execute([':user_id' => $userId]);
    $userState = $stateStatement->fetch(PDO::FETCH_ASSOC) ?: null;

    $stateRunId = null;
    $stateStatus = '';
    $stateMessage = '';

    if ($userState !== null) {
        if (array_key_exists('current_run_id', $userState) && $userState['current_run_id'] !== null) {
            $stateRunId = (int) $userState['current_run_id'];
        }

        if (isset($userState['last_status'])) {
            $stateStatus = (string) $userState['last_status'];
        }

        if (isset($userState['last_message'])) {
            $stateMessage = (string) $userState['last_message'];
        }
    }

    $runId = $requestedRunId;
    if ($runId === null && $stateRunId !== null) {
        $runId = (int) $stateRunId;
        if ($runId <= 0) {
            $runId = null;
        }
    }

    if ($runId === null) {
        $imageRunStatement = $pdo->prepare(
            'SELECT run_id
             FROM item_images
             WHERE user_id = :user_id
             ORDER BY created_at DESC, id DESC
             LIMIT 1'
        );
        $imageRunStatement->execute([':user_id' => $userId]);
        $imageRunId = $imageRunStatement->fetchColumn();

        if ($imageRunId !== false) {
            $runId = (int) $imageRunId;
            if ($runId <= 0) {
                $runId = null;
            }
        }

        if ($runId === null) {
            echo json_encode([
                'ok'   => true,
                'data' => [
                    'isrunning'           => false,
                    'status'              => 'idle',
                    'message'             => 'Bereit zum Upload',
                    'product_name'        => '',
                    'product_description' => '',
                    'images'              => [],
                    'original_images'     => [],
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
    }

    $runStatement = $pdo->prepare(
        'SELECT id, status, last_message
         FROM workflow_runs
         WHERE id = :run_id AND user_id = :user_id
         LIMIT 1'
    );
    $runStatement->execute([
        ':run_id'  => $runId,
        ':user_id' => $userId,
    ]);
    $run = $runStatement->fetch(PDO::FETCH_ASSOC) ?: null;

    $originalImages = fetchOriginalImageUrls($pdo, (int) $runId);

    if ($run === null) {
        if ($requestedRunId !== null) {
            http_response_code(404);
            echo json_encode([
                'ok'    => false,
                'error' => 'run not found',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        $statusValue = $stateStatus !== '' ? $stateStatus : 'running';
        $messageValue = $stateMessage !== '' ? $stateMessage : ($statusValue === 'finished' ? 'Workflow abgeschlossen' : 'Verarbeitung läuft …');

        $images = [];

        $imagesStatement = $pdo->prepare(
            'SELECT url, position
             FROM item_images
             WHERE user_id = :user_id AND run_id = :run_id
             ORDER BY position ASC, id ASC'
        );
        $imagesStatement->execute([
            ':user_id' => $userId,
            ':run_id'  => $runId,
        ]);
        $imageRows = $imagesStatement->fetchAll(PDO::FETCH_ASSOC);

        foreach ($imageRows as $row) {
            $url = isset($row['url']) ? (string) $row['url'] : '';
            if ($url === '') {
                continue;
            }

            $position = isset($row['position']) ? (int) $row['position'] : 0;
            $images[] = [
                'url'      => make_abs_url($url),
                'position' => $position,
            ];
        }

        $response = [
            'ok'   => true,
            'data' => [
                'run_id'              => $runId,
                'isrunning'           => strtolower($statusValue) === 'running',
                'status'              => $statusValue,
                'message'             => $messageValue,
                'product_name'        => '',
                'product_description' => '',
                'images'              => array_map(
                    static fn (array $row): array => [
                        'url'      => $row['url'],
                        'position' => (int) $row['position'],
                    ],
                    $images
                ),
                'original_images'     => $originalImages,
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
        exit;
    }

    if ($runId !== null) {
        $noteStatement = $pdo->prepare(
            'SELECT product_name, product_description
             FROM item_notes
             WHERE user_id = :user_id AND run_id = :run_id
             ORDER BY id DESC
             LIMIT 1'
        );
        $noteStatement->execute([
            ':user_id' => $userId,
            ':run_id'  => $runId,
        ]);
        $note = $noteStatement->fetch(PDO::FETCH_ASSOC) ?: null;
    } else {
        $note = null;
    }

    $productName = isset($note['product_name']) ? (string) $note['product_name'] : '';
    $productDescription = isset($note['product_description']) ? (string) $note['product_description'] : '';

    $images = [];

    if ($runId !== null) {
        $imagesStatement = $pdo->prepare(
            'SELECT url, position
             FROM item_images
             WHERE user_id = :user_id AND run_id = :run_id
             ORDER BY position ASC, id ASC'
        );
        $imagesStatement->execute([
            ':user_id' => $userId,
            ':run_id'  => $runId,
        ]);
        $imageRows = $imagesStatement->fetchAll(PDO::FETCH_ASSOC);

        foreach ($imageRows as $row) {
            $url = isset($row['url']) ? (string) $row['url'] : '';
            if ($url === '') {
                continue;
            }

            $position = isset($row['position']) ? (int) $row['position'] : 0;
            $images[] = [
                'url'      => make_abs_url($url),
                'position' => $position,
            ];
        }
    }

    $statusValue = isset($run['status']) ? (string) $run['status'] : '';
    if ($statusValue === '') {
        $statusValue = $stateStatus !== '' ? $stateStatus : 'running';
    }

    $messageValue = isset($run['last_message']) ? (string) $run['last_message'] : '';
    if ($messageValue === '') {
        $messageValue = $stateMessage !== '' ? $stateMessage : ($statusValue === 'finished' ? 'Workflow abgeschlossen' : 'Verarbeitung läuft …');
    }

    $isRunning = strtolower($statusValue) === 'running';

    $response = [
        'ok'   => true,
        'data' => [
            'run_id'              => $runId,
            'isrunning'           => $isRunning,
            'status'              => $statusValue,
            'message'             => $messageValue,
            'product_name'        => $productName,
            'product_description' => $productDescription,
            'images'              => array_map(
                static fn (array $row): array => [
                    'url'      => $row['url'],
                    'position' => (int) $row['position'],
                ],
                $images
            ),
            'original_images'     => $originalImages,
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
} catch (PDOException $exception) {
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => 'db error',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
