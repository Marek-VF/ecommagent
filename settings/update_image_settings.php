<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth/bootstrap.php';

auth_require_login();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Ungültige Anfragemethode.']);
    exit;
}

$token = $_POST['_token'] ?? null;
if (!auth_validate_csrf(is_string($token) ? $token : null)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Ungültiges Formular-Token.']);
    exit;
}

$allowedRatios = [
    'original',
    '1:1',
    '2:3',
    '3:2',
    '3:4',
    '4:3',
    '4:5',
    '5:4',
    '9:16',
    '16:9',
    '21:9',
];

$ratio = $_POST['image_ratio'] ?? '';
$ratio = is_string($ratio) ? trim($ratio) : '';

if ($ratio === '' || !in_array($ratio, $allowedRatios, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Ungültiges Seitenverhältnis.']);
    exit;
}

$currentUser = auth_user();
if ($currentUser === null || !isset($currentUser['id'])) {
    auth_logout();
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Nicht autorisiert.']);
    exit;
}

$pdo = auth_pdo();

$statement = $pdo->prepare('UPDATE users SET image_ratio_preference = :ratio, updated_at = NOW() WHERE id = :user_id');
$statement->execute([
    'ratio'   => $ratio,
    'user_id' => (int) $currentUser['id'],
]);

echo json_encode(['success' => true]);
