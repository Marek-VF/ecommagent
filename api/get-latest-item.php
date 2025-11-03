<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

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

$baseUrl = rtrim($config['base_url'] ?? '', '/') . '/';

$userId = 1;

try {
    $noteStatement = $pdo->prepare(
        'SELECT product_name, product_description
         FROM item_notes
         WHERE user_id = :user_id
         ORDER BY created_at DESC, id DESC
         LIMIT 1'
    );
    $noteStatement->execute(['user_id' => $userId]);
    $note = $noteStatement->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($note === null) {
        $fallbackNoteStatement = $pdo->query(
            'SELECT product_name, product_description
             FROM item_notes
             ORDER BY created_at DESC, id DESC
             LIMIT 1'
        );

        if ($fallbackNoteStatement !== false) {
            $note = $fallbackNoteStatement->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    }

    $productName = isset($note['product_name']) ? (string) $note['product_name'] : '';
    $productDescription = isset($note['product_description']) ? (string) $note['product_description'] : '';

    $stateStatement = $pdo->prepare(
        'SELECT last_status, last_message
         FROM user_state
         WHERE user_id = :user_id
         ORDER BY updated_at DESC
         LIMIT 1'
    );
    $stateStatement->execute(['user_id' => $userId]);
    $userState = $stateStatement->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($userState === null) {
        $fallbackStateStatement = $pdo->query(
            'SELECT last_status, last_message
             FROM user_state
             ORDER BY updated_at DESC
             LIMIT 1'
        );

        if ($fallbackStateStatement !== false) {
            $userState = $fallbackStateStatement->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    }

    $lastStatus = isset($userState['last_status']) ? (string) $userState['last_status'] : '';
    $lastMessage = isset($userState['last_message']) ? (string) $userState['last_message'] : '';

    $isRunning = true;
    if ($lastStatus !== '') {
        $normalizedStatus = strtolower(trim($lastStatus));
        if ($normalizedStatus === 'finished') {
            $isRunning = false;
        }
    }

    $imagesStatement = $pdo->prepare(
        'SELECT url, position
         FROM item_images
         WHERE user_id = :user_id
         ORDER BY created_at DESC, id DESC'
    );
    $imagesStatement->execute(['user_id' => $userId]);
    $imageRows = $imagesStatement->fetchAll(PDO::FETCH_ASSOC);

    if (!$imageRows) {
        $fallbackImagesStatement = $pdo->query(
            'SELECT url, position
             FROM item_images
             ORDER BY created_at DESC, id DESC'
        );

        if ($fallbackImagesStatement !== false) {
            $imageRows = $fallbackImagesStatement->fetchAll(PDO::FETCH_ASSOC);
        }
    }

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
            $url = $baseUrl . ltrim($url, '/');
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

    $hasData = ($productName !== '' || $productDescription !== '' || $lastMessage !== '');

    echo json_encode([
        'ok'       => true,
        'has_data' => $hasData,
        'data'     => [
            'product_name'        => $productName,
            'product_description' => $productDescription,
            'status'              => $lastStatus,
            'message'             => $lastMessage,
            'isrunning'           => $isRunning,
            'images'              => $images,
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
