<?php
declare(strict_types=1);

require_once __DIR__ . '/auth/bootstrap.php';
require_once __DIR__ . '/credits.php';
require_once __DIR__ . '/status_logger.php';

header('Content-Type: application/json; charset=utf-8');

// 1. Authentifizierung prüfen
if (!auth_is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Nicht eingeloggt.']);
    exit;
}

$user = auth_user();
$userId = (int) $user['id'];

// 2. Input lesen
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$runId = (int) ($input['run_id'] ?? 0);
$imageId = (int) ($input['image_id'] ?? 0);
$action = strtolower(trim((string)($input['action'] ?? '')));
$position = (int) ($input['position'] ?? 0);

// 3. Validierung
if ($runId <= 0 || $imageId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Fehlende ID (Run oder Bild).']);
    exit;
}

if (!in_array($action, ['2k', '4k', 'edit'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Ungültige Aktion.']);
    exit;
}

try {
    $pdo = auth_pdo();
    $config = auth_config();

    // 4. Sicherheits-Check: Gehört das Bild dem User?
    // Wir holen uns direkt die URL aus der DB (Single Source of Truth)
    $stmt = $pdo->prepare("SELECT url FROM item_images WHERE id = :img_id AND user_id = :uid AND run_id = :rid LIMIT 1");
    $stmt->execute([':img_id' => $imageId, ':uid' => $userId, ':rid' => $runId]);
    $imageUrl = $stmt->fetchColumn();

    if (!$imageUrl) {
        throw new Exception('Bild nicht gefunden oder Zugriff verweigert.');
    }

    // 5. Config Check
    $webhookUrl = $config['workflow_webhook_update'] ?? null;
    if (!$webhookUrl) {
        throw new Exception('Update-Webhook nicht konfiguriert.');
    }

    // 6. Webhook senden (an n8n)
    $payload = [
        'run_id' => $runId,
        'user_id' => $userId,
        'image_id' => $imageId,
        'image_url' => $imageUrl, // URL aus DB, nicht vom Client!
        'action' => $action,
        'position' => $position
    ];

    $ch = curl_init($webhookUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($httpCode >= 400) {
        throw new Exception("Webhook Fehler: HTTP $httpCode");
    }

    // 7. Status Update in DB
    $msg = "Starte $action...";
    $update = $pdo->prepare("UPDATE workflow_runs SET status = 'running', last_message = :msg WHERE id = :rid");
    $update->execute([':msg' => $msg, ':rid' => $runId]);
    
    log_status_message($pdo, $runId, $userId, $msg);

    echo json_encode(['success' => true, 'message' => $msg]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}