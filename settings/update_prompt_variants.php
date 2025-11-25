<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth/bootstrap.php';

auth_require_login();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$token = $_POST['_token'] ?? null;
if (!auth_validate_csrf(is_string($token) ? $token : null)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Ungültiger CSRF-Token']);
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
$userId = (int) $currentUser['id'];

$category = trim((string) ($_POST['category'] ?? ''));
$slot = (int) ($_POST['variant_slot'] ?? 0);

$location   = trim((string) ($_POST['location'] ?? ''));
$lighting   = trim((string) ($_POST['lighting'] ?? ''));
$mood       = trim((string) ($_POST['mood'] ?? ''));
$season     = trim((string) ($_POST['season'] ?? ''));
$modelType  = trim((string) ($_POST['model_type'] ?? ''));
$modelPose  = trim((string) ($_POST['model_pose'] ?? ''));
$viewMode   = trim((string) ($_POST['view_mode'] ?? 'full_body'));

if ($category === '' || mb_strlen($category) > 100) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Ungültige Kategorie.']);
    exit;
}

if ($slot < 1 || $slot > 3) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Ungültiger Varianten-Slot.']);
    exit;
}

if (!in_array($viewMode, ['full_body', 'garment_closeup'], true)) {
    $viewMode = 'full_body';
}

$location = mb_substr($location, 0, 5000);
$lighting = mb_substr($lighting, 0, 5000);
$mood = mb_substr($mood, 0, 5000);
$season = mb_substr($season, 0, 255);
$modelType = mb_substr($modelType, 0, 500);
$modelPose = mb_substr($modelPose, 0, 5000);

$stmt = $pdo->prepare(
    'INSERT INTO prompt_variants
        (user_id, category, variant_slot, location, lighting, mood, season, model_type, model_pose, view_mode, created_at, updated_at)
     VALUES
        (:user_id, :category, :slot, :location, :lighting, :mood, :season, :model_type, :model_pose, :view_mode, NOW(), NOW())
     ON DUPLICATE KEY UPDATE
        location   = VALUES(location),
        lighting   = VALUES(lighting),
        mood       = VALUES(mood),
        season     = VALUES(season),
        model_type = VALUES(model_type),
        model_pose = VALUES(model_pose),
        view_mode  = VALUES(view_mode),
        updated_at = NOW()'
);

$stmt->execute([
    ':user_id'     => $userId,
    ':category'    => $category,
    ':slot'        => $slot,
    ':location'    => $location,
    ':lighting'    => $lighting,
    ':mood'        => $mood,
    ':season'      => $season,
    ':model_type'  => $modelType,
    ':model_pose'  => $modelPose,
    ':view_mode'   => $viewMode,
]);

echo json_encode([
    'success' => true,
    'message' => 'Bildvariante wurde erfolgreich gespeichert.',
]);
