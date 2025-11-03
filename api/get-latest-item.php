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
        'SELECT product_name, product_description FROM item_notes WHERE user_id = :user_id ORDER BY created_at DESC, id DESC LIMIT 1'
    );
    $latestNoteStatement->execute(['user_id' => $userId]);
    $latestNote = $latestNoteStatement->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($latestNote === null) {
        $fallbackNoteStatement = $pdo->query(
            'SELECT product_name, product_description FROM item_notes ORDER BY created_at DESC, id DESC LIMIT 1'
        );

        if ($fallbackNoteStatement !== false) {
            $latestNote = $fallbackNoteStatement->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    }

    $userStateStatement = $pdo->prepare(
        'SELECT last_status, last_message FROM user_state WHERE user_id = :user_id LIMIT 1'
    );
    $userStateStatement->execute(['user_id' => $userId]);
    $userState = $userStateStatement->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($userState === null) {
        $fallbackStateStatement = $pdo->query(
            'SELECT last_status, last_message FROM user_state ORDER BY updated_at DESC LIMIT 1'
        );

        if ($fallbackStateStatement !== false) {
            $userState = $fallbackStateStatement->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    }

    $productName = '';
    $productDescription = '';

    if (is_array($latestNote)) {
        $productName = isset($latestNote['product_name']) ? (string) $latestNote['product_name'] : '';
        $productDescription = isset($latestNote['product_description']) ? (string) $latestNote['product_description'] : '';
    }

    $status = '';
    $message = '';

    if (is_array($userState)) {
        $status = isset($userState['last_status']) ? (string) $userState['last_status'] : '';
        $message = isset($userState['last_message']) ? (string) $userState['last_message'] : '';
    }

    $hasData = is_array($latestNote) || is_array($userState);

    if (!$hasData) {
        echo json_encode([
            'ok'       => true,
            'has_data' => false,
            'data'     => [
                'product_name'        => '',
                'product_description' => '',
                'status'              => '',
                'message'             => '',
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    echo json_encode([
        'ok'       => true,
        'has_data' => true,
        'data'     => [
            'product_name'        => $productName,
            'product_description' => $productDescription,
            'status'              => $status,
            'message'             => $message,
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
