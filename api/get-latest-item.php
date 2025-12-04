<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

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

$baseUrlConfig = $config['base_url'] ?? '';
$baseUrl = '';
if (is_string($baseUrlConfig) && $baseUrlConfig !== '') {
    $baseUrl = rtrim($baseUrlConfig, '/');
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

    if ($run === null) {
        if ($requestedRunId !== null) {
            http_response_code(404);
            echo json_encode([
                'ok'    => false,
                'error' => 'run not found',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        $statusValue = $stateStatus !== '' ? $stateStatus : 'pending';
        $statusNormalized = strtolower($statusValue);

        if ($stateMessage !== '') {
            $messageValue = $stateMessage;
        } elseif ($statusNormalized === 'finished') {
            $messageValue = 'Workflow abgeschlossen';
        } elseif ($statusNormalized === 'pending') {
            $messageValue = 'Bereit für Workflow-Start';
        } else {
            $messageValue = 'Verarbeitung läuft …';
        }

        $images = [];

        $imagesStatement = $pdo->prepare(
            'SELECT id, url, position, badge
             FROM item_images
             WHERE user_id = :user_id AND run_id = :run_id
             ORDER BY position ASC, id ASC'
        );
        $imagesStatement->execute([
            ':user_id' => $userId,
            ':run_id'  => $runId,
        ]);
        $imageRows = $imagesStatement->fetchAll(PDO::FETCH_ASSOC);

        $latestByPos = [];
        foreach ($imageRows as $row) {
            $pos = isset($row['position']) ? (int) $row['position'] : 0;
            $latestByPos[$pos] = $row;
        }

        if ($latestByPos !== []) {
            ksort($latestByPos);
        }

        foreach ($latestByPos as $row) {
            $url = isset($row['url']) ? (string) $row['url'] : '';
            if ($url === '') {
                continue;
            }

            if ($baseUrl !== '' && !preg_match('#^https?://#i', $url)) {
                $url = $baseUrl . '/' . ltrim($url, '/');
            }

            $position = isset($row['position']) ? (int) $row['position'] : 0;
            $images[] = [
                'id'       => isset($row['id']) ? (int) $row['id'] : null,
                'url'      => $url,
                'position' => $position,
                'badge'    => array_key_exists('badge', $row) && $row['badge'] !== null ? (string) $row['badge'] : null,
            ];
        }

        echo json_encode([
            'ok'   => true,
            'data' => [
                'run_id'              => $runId,
                'isrunning'           => $statusNormalized === 'running',
                'status'              => $statusValue,
                'message'             => $messageValue,
                'product_name'        => '',
                'product_description' => '',
                'images'              => array_map(
                    static fn (array $row): array => [
                        'id'       => $row['id'],
                        'url'      => $row['url'],
                        'position' => (int) $row['position'],
                        'badge'    => $row['badge'],
                    ],
                    $images
                ),
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
            'SELECT id, url, position, badge
             FROM item_images
             WHERE user_id = :user_id AND run_id = :run_id
             ORDER BY position ASC, id ASC'
        );
        $imagesStatement->execute([
            ':user_id' => $userId,
            ':run_id'  => $runId,
        ]);
        $imageRows = $imagesStatement->fetchAll(PDO::FETCH_ASSOC);

        $latestByPos = [];
        foreach ($imageRows as $row) {
            $pos = isset($row['position']) ? (int) $row['position'] : 0;
            $latestByPos[$pos] = $row;
        }

        if ($latestByPos !== []) {
            ksort($latestByPos);
        }

        foreach ($latestByPos as $row) {
            $url = isset($row['url']) ? (string) $row['url'] : '';
            if ($url === '') {
                continue;
            }

            if ($baseUrl !== '' && !preg_match('#^https?://#i', $url)) {
                $url = $baseUrl . '/' . ltrim($url, '/');
            }

            $position = isset($row['position']) ? (int) $row['position'] : 0;
            $images[] = [
                'id'       => isset($row['id']) ? (int) $row['id'] : null,
                'url'      => $url,
                'position' => $position,
                'badge'    => array_key_exists('badge', $row) && $row['badge'] !== null ? (string) $row['badge'] : null,
            ];
        }
    }

    $statusValue = isset($run['status']) ? (string) $run['status'] : '';
    if ($statusValue === '') {
        $statusValue = $stateStatus !== '' ? $stateStatus : 'pending';
    }

    $statusNormalized = strtolower($statusValue);

    $messageValue = isset($run['last_message']) ? (string) $run['last_message'] : '';
    if ($messageValue === '') {
        if ($stateMessage !== '') {
            $messageValue = $stateMessage;
        } elseif ($statusNormalized === 'finished') {
            $messageValue = 'Workflow abgeschlossen';
        } elseif ($statusNormalized === 'pending') {
            $messageValue = 'Bereit für Workflow-Start';
        } else {
            $messageValue = 'Verarbeitung läuft …';
        }
    }

    $isRunning = $statusNormalized === 'running';

    echo json_encode([
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
                    'id'       => $row['id'],
                    'url'      => $row['url'],
                    'position' => (int) $row['position'],
                    'badge'    => $row['badge'],
                ],
                $images
            ),
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (PDOException $exception) {
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => 'db error',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
