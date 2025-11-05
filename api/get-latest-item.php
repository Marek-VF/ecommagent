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

    $runId = $stateRunId !== null ? (int) $stateRunId : null;
    if ($runId !== null && $runId <= 0) {
        $runId = null;
    }

    if ($runId === null) {
        $normalizedStatus = strtolower(trim($stateStatus));
        $isRunning = $normalizedStatus === 'running';
        $statusLabel = $isRunning ? 'running' : 'idle';
        $message = $stateMessage !== ''
            ? $stateMessage
            : ($isRunning ? 'Workflow gestartet – warte auf Ergebnisse …' : 'Bereit zum Upload');

        echo json_encode([
            'ok'   => true,
            'data' => [
                'isrunning'           => $isRunning,
                'status'              => $statusLabel,
                'message'             => $message,
                'product_name'        => '',
                'product_description' => '',
                'images'              => [],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
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

            if ($baseUrl !== '' && !preg_match('#^https?://#i', $url)) {
                $url = $baseUrl . '/' . ltrim($url, '/');
            }

            $position = isset($row['position']) ? (int) $row['position'] : 0;
            $images[] = [
                'url'      => $url,
                'position' => $position,
            ];
        }
    }

    if ($runId !== null) {
        $statusValue = isset($run['status']) ? (string) $run['status'] : '';
        $messageValue = isset($run['last_message']) ? (string) $run['last_message'] : '';
    } else {
        $statusValue = '';
        $messageValue = '';
    }

    if ($statusValue === '') {
        $statusValue = $stateStatus !== '' ? $stateStatus : 'running';
    }

    if ($messageValue === '') {
        $messageValue = $stateMessage;
    }

    $isRunning = false;
    if ($run !== null && isset($run['status'])) {
        $isRunning = strtolower((string) $run['status']) === 'running';
    } elseif ($statusValue !== '') {
        $isRunning = strtolower((string) $statusValue) === 'running';
    }

    if ($runId !== null) {
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
                        'url'      => $row['url'],
                        'position' => (int) $row['position'],
                    ],
                    $images
                ),
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    echo json_encode([
        'ok'   => true,
        'data' => [
            'isrunning'           => false,
            'status'              => 'idle',
            'message'             => 'kein aktueller Lauf',
            'product_name'        => '',
            'product_description' => '',
            'images'              => [],
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
