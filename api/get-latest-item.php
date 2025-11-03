<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

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

$userId = 1;

try {
    $latestNoteStatement = $pdo->prepare(
        'SELECT product_name, product_description
         FROM item_notes
         WHERE user_id = :user_id
         ORDER BY created_at DESC, id DESC
         LIMIT 1'
    );
    $latestNoteStatement->execute(['user_id' => $userId]);
    $latestNote = $latestNoteStatement->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($latestNote === null) {
        $fallbackNoteStatement = $pdo->query(
            'SELECT product_name, product_description
             FROM item_notes
             ORDER BY created_at DESC, id DESC
             LIMIT 1'
        );

        if ($fallbackNoteStatement !== false) {
            $latestNote = $fallbackNoteStatement->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    }

    $productName = '';
    $productDescription = '';

    if (is_array($latestNote)) {
        $productName = isset($latestNote['product_name']) ? (string) $latestNote['product_name'] : '';
        $productDescription = isset($latestNote['product_description']) ? (string) $latestNote['product_description'] : '';
    }

    $userStateStatement = $pdo->prepare(
        'SELECT last_status, last_message
         FROM user_state
         WHERE user_id = :user_id
         ORDER BY updated_at DESC
         LIMIT 1'
    );
    $userStateStatement->execute(['user_id' => $userId]);
    $userState = $userStateStatement->fetch(PDO::FETCH_ASSOC) ?: null;

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

    $status = '';
    $message = '';

    if (is_array($userState)) {
        $status = isset($userState['last_status']) ? (string) $userState['last_status'] : '';
        $message = isset($userState['last_message']) ? (string) $userState['last_message'] : '';
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
        $url = isset($row['url']) ? (string) $row['url'] : '';
        if ($url === '') {
            continue;
        }

        $positionValue = $row['position'] ?? null;
        $key = null;

        if (is_numeric($positionValue)) {
            $index = (int) $positionValue;
            if ($index >= 1 && $index <= 3) {
                $key = 'image_' . $index;
            }
        } elseif (is_string($positionValue)) {
            $normalized = trim($positionValue);
            if ($normalized !== '') {
                if (preg_match('/^image[_-]?(\d)$/i', $normalized, $matches) === 1) {
                    $index = (int) $matches[1];
                    if ($index >= 1 && $index <= 3) {
                        $key = 'image_' . $index;
                    }
                } elseif (in_array(strtolower($normalized), ['1', '2', '3'], true)) {
                    $index = (int) $normalized;
                    if ($index >= 1 && $index <= 3) {
                        $key = 'image_' . $index;
                    }
                }
            }
        }

        if ($key === null) {
            continue;
        }

        if ($images[$key] === null) {
            $images[$key] = $url;
        }
    }

    $statusNormalized = strtolower(trim($status));
    $hasUserState = is_array($userState);
    $isRunning = $hasUserState ? true : false;

    if (!$hasUserState) {
        $isRunning = false;
    } elseif ($statusNormalized !== '') {
        $runningStates = ['running', 'processing', 'in_progress', 'in-progress'];
        $finishedStates = ['finished', 'done', 'completed', 'stopped', 'success', 'idle', 'ok', 'error', 'failed'];

        if (in_array($statusNormalized, $runningStates, true)) {
            $isRunning = true;
        } elseif (in_array($statusNormalized, $finishedStates, true)) {
            $isRunning = false;
        }
    }

    $hasData = trim($productName) !== '' || trim($productDescription) !== '';

    echo json_encode([
        'ok'       => true,
        'has_data' => $hasData,
        'data'     => [
            'product_name'        => $productName,
            'product_description' => $productDescription,
            'status'              => $status,
            'message'             => $message,
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
