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

$runIdParam = null;
if (isset($_GET['run_id'])) {
    $rawRunId = $_GET['run_id'];
    if (is_scalar($rawRunId)) {
        $normalizedRunId = filter_var($rawRunId, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($normalizedRunId !== false) {
            $runIdParam = (int) $normalizedRunId;
        }
    }
}

try {
    if ($runIdParam !== null) {
        $runStatement = $pdo->prepare(
            'SELECT id, status, last_message
             FROM workflow_runs
             WHERE id = :run_id AND user_id = :user_id
             LIMIT 1'
        );
        $runStatement->execute([
            ':run_id'  => $runIdParam,
            ':user_id' => $userId,
        ]);
        $run = $runStatement->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($run === null) {
            echo json_encode([
                'ok'    => false,
                'error' => 'run_not_found',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        $noteStatement = $pdo->prepare(
            'SELECT product_name, product_description
             FROM item_notes
             WHERE user_id = :user_id AND run_id = :run_id
             ORDER BY created_at DESC, id DESC
             LIMIT 1'
        );
        $noteStatement->execute([
            ':user_id' => $userId,
            ':run_id'  => $runIdParam,
        ]);
        $note = $noteStatement->fetch(PDO::FETCH_ASSOC) ?: null;

        $productName = isset($note['product_name']) ? (string) $note['product_name'] : '';
        $productDescription = isset($note['product_description']) ? (string) $note['product_description'] : '';

        $imagesStatement = $pdo->prepare(
            'SELECT url, position
             FROM item_images
             WHERE user_id = :user_id AND run_id = :run_id
             ORDER BY position ASC, id ASC'
        );
        $imagesStatement->execute([
            ':user_id' => $userId,
            ':run_id'  => $runIdParam,
        ]);
        $imageRows = $imagesStatement->fetchAll(PDO::FETCH_ASSOC);

        $images = [
            'image_1' => null,
            'image_2' => null,
            'image_3' => null,
        ];

        foreach ($imageRows as $row) {
            $pos = isset($row['position']) ? (string) $row['position'] : '';
            $url = isset($row['url']) ? (string) $row['url'] : '';

            if ($url === '') {
                continue;
            }

            if ($baseUrl !== '' && !preg_match('#^https?://#i', $url)) {
                $url = $baseUrl . '/' . ltrim($url, '/');
            }

            $key = null;
            if ($pos === '1') {
                $key = 'image_1';
            } elseif ($pos === '2') {
                $key = 'image_2';
            } elseif ($pos === '3') {
                $key = 'image_3';
            } elseif (preg_match('/^image_?([1-3])$/i', $pos, $matches) === 1) {
                $key = 'image_' . $matches[1];
            }

            if ($key === null) {
                continue;
            }

            if ($images[$key] === null) {
                $images[$key] = $url;
            }
        }

        $status = isset($run['status']) ? (string) $run['status'] : '';
        $message = isset($run['last_message']) ? (string) $run['last_message'] : '';
        $isRunning = strtolower($status) === 'running';
        $hasImageData = array_filter($images, static fn ($value) => $value !== null) !== [];
        $hasData = ($productName !== '' || $productDescription !== '' || $message !== '' || $hasImageData);

        echo json_encode([
            'ok'       => true,
            'has_data' => $hasData,
            'data'     => [
                'run_id'             => (int) $run['id'],
                'product_name'       => $productName,
                'product_description'=> $productDescription,
                'status'             => $status,
                'message'            => $message,
                'isrunning'          => $isRunning,
                'images'             => $images,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $stateStatement = $pdo->prepare(
        'SELECT last_status, last_message, current_run_id
         FROM user_state
         WHERE user_id = :user_id
         ORDER BY updated_at DESC
         LIMIT 1'
    );
    $stateStatement->execute([':user_id' => $userId]);
    $userState = $stateStatement->fetch(PDO::FETCH_ASSOC) ?: null;

    $currentRunId = null;
    $lastStatus = '';
    $lastMessage = '';

    if ($userState !== null) {
        if (isset($userState['current_run_id']) && $userState['current_run_id'] !== null) {
            $currentRunId = (int) $userState['current_run_id'];
        }
        if (isset($userState['last_status'])) {
            $lastStatus = (string) $userState['last_status'];
        }
        if (isset($userState['last_message'])) {
            $lastMessage = (string) $userState['last_message'];
        }
    }

    $noteSql = 'SELECT product_name, product_description FROM item_notes WHERE user_id = :user_id';
    $noteParams = [':user_id' => $userId];
    if ($currentRunId !== null) {
        $noteSql .= ' AND run_id = :run_id';
        $noteParams[':run_id'] = $currentRunId;
    }
    $noteSql .= ' ORDER BY created_at DESC, id DESC LIMIT 1';

    $noteStatement = $pdo->prepare($noteSql);
    $noteStatement->execute($noteParams);
    $note = $noteStatement->fetch(PDO::FETCH_ASSOC) ?: null;

    $productName = isset($note['product_name']) ? (string) $note['product_name'] : '';
    $productDescription = isset($note['product_description']) ? (string) $note['product_description'] : '';

    $imagesSql = 'SELECT url, position FROM item_images WHERE user_id = :user_id';
    $imageParams = [':user_id' => $userId];
    if ($currentRunId !== null) {
        $imagesSql .= ' AND run_id = :run_id';
        $imageParams[':run_id'] = $currentRunId;
    }
    $imagesSql .= ' ORDER BY created_at DESC, id DESC';

    $imagesStatement = $pdo->prepare($imagesSql);
    $imagesStatement->execute($imageParams);
    $imageRows = $imagesStatement->fetchAll(PDO::FETCH_ASSOC);

    $images = [
        'image_1' => null,
        'image_2' => null,
        'image_3' => null,
    ];

    foreach ($imageRows as $row) {
        $pos = isset($row['position']) ? (string) $row['position'] : '';
        $url = isset($row['url']) ? (string) $row['url'] : '';

        if ($url === '') {
            continue;
        }

        if ($baseUrl !== '' && !preg_match('#^https?://#i', $url)) {
            $url = $baseUrl . '/' . ltrim($url, '/');
        }

        $key = null;
        if ($pos === '1') {
            $key = 'image_1';
        } elseif ($pos === '2') {
            $key = 'image_2';
        } elseif ($pos === '3') {
            $key = 'image_3';
        } elseif (preg_match('/^image_?([1-3])$/i', $pos, $matches) === 1) {
            $key = 'image_' . $matches[1];
        }

        if ($key === null) {
            continue;
        }

        if ($images[$key] === null) {
            $images[$key] = $url;
        }
    }

    $normalizedStatus = strtolower(trim($lastStatus));
    $isRunning = $normalizedStatus === 'running';

    $hasImageData = array_filter($images, static fn ($value) => $value !== null) !== [];
    $hasData = ($productName !== '' || $productDescription !== '' || $lastMessage !== '' || $hasImageData);

    echo json_encode([
        'ok'       => true,
        'has_data' => $hasData,
        'data'     => [
            'run_id'             => $currentRunId,
            'product_name'       => $productName,
            'product_description'=> $productDescription,
            'status'             => $lastStatus,
            'message'            => $lastMessage,
            'isrunning'          => $isRunning,
            'images'             => $images,
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
