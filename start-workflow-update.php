<?php
declare(strict_types=1);

require_once __DIR__ . '/auth/bootstrap.php';
require_once __DIR__ . '/credits.php';
require_once __DIR__ . '/status_logger.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Error Handler für saubere JSON-Antworten bei PHP-Fehlern
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$respond = static function (array $payload, int $statusCode = 200): never {
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

$timestamp = static fn (): string => gmdate('c');

$normalizePositiveInt = static function (mixed $value): ?int {
    if (is_int($value) && $value > 0) return $value;
    if (is_string($value) && ctype_digit(trim($value))) {
        $val = (int) trim($value);
        return $val > 0 ? $val : null;
    }
    return null;
};

// 1. Authentifizierung
if (!auth_is_logged_in()) {
    $respond(['success' => false, 'message' => 'Anmeldung erforderlich.', 'logout' => true], 401);
}

$user = auth_user();
$userId = (int) ($user['id'] ?? 0);

if ($userId <= 0) {
    $respond(['success' => false, 'message' => 'Ungültige Benutzersession.'], 401);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $respond(['success' => false, 'message' => 'Methode nicht erlaubt.'], 405);
}

// 2. Input lesen & Parsen
$rawBody = file_get_contents('php://input');
$data = json_decode($rawBody ?: '', true) ?? $_POST;

// 3. Validierung der Pflichtfelder
$runId = $normalizePositiveInt($data['run_id'] ?? null);
$imageId = $normalizePositiveInt($data['image_id'] ?? null);
$position = $normalizePositiveInt($data['position'] ?? null);
$action = isset($data['action']) ? strtolower(trim((string)$data['action'])) : '';

if (!$runId) {
    $respond(['success' => false, 'message' => 'run_id fehlt.'], 400);
}
if (!$imageId) {
    $respond(['success' => false, 'message' => 'image_id fehlt (Bild nicht gefunden).'], 400);
}

$allowedActions = ['2k', '4k', 'edit'];
if (!in_array($action, $allowedActions, true)) {
    $respond(['success' => false, 'message' => 'Ungültige Aktion: ' . htmlspecialchars($action)], 400);
}

try {
    $config = auth_config();
    $webhookUrl = $config['workflow_webhook_update'] ?? null;

    if (!$webhookUrl || !is_string($webhookUrl)) {
        throw new RuntimeException('Update-Webhook ist in config.php nicht konfiguriert.');
    }

    $pdo = auth_pdo();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 4. Sicherheits-Check & URL aus DB holen (Single Source of Truth)
    // Wir prüfen: Existiert das Bild? Gehört es dem User? Gehört es zum angegebenen Run?
    $stmt = $pdo->prepare(
        "SELECT url FROM item_images WHERE id = :img_id AND user_id = :uid AND run_id = :rid LIMIT 1"
    );
    $stmt->execute([
        ':img_id' => $imageId,
        ':uid' => $userId,
        ':rid' => $runId
    ]);
    
    $dbImageUrl = $stmt->fetchColumn();

    if (!$dbImageUrl) {
        // Entweder Bild existiert nicht, oder gehört nicht dem User/Run -> 404/403
        $respond(['success' => false, 'message' => 'Bild nicht gefunden oder Zugriff verweigert.'], 404);
    }

    // URL Aufbereiten (Falls relativ gespeichert, Base URL davor setzen)
    $finalImageUrl = $dbImageUrl;
    if (!preg_match('#^https?://#i', $finalImageUrl)) {
        $baseUrl = rtrim((string)($config['base_url'] ?? ''), '/');
        $finalImageUrl = $baseUrl . '/' . ltrim($finalImageUrl, '/');
    }

    // 5. Workflow-Status prüfen (optional: erlauben wir Updates während Running?)
    // Aktuell lassen wir es zu, falls ein anderer Slot bearbeitet wird, aber sicherheitshalber prüfen wir den Run.
    $runCheck = $pdo->prepare("SELECT status FROM workflow_runs WHERE id = :rid");
    $runCheck->execute([':rid' => $runId]);
    $runStatus = $runCheck->fetchColumn();

    if ($runStatus === 'running') {
        // Optional: Hier könnte man abbrechen. Wir erlauben es vorerst, loggen aber.
    }

    // 6. Credits prüfen (Optional, hier vereinfacht)
    // $requiredCredits = get_credit_price($config, $action);
    // ... Credit Logik hier einfügen falls gewünscht ...

    // 7. Webhook Payload vorbereiten
    $payload = [
        'run_id' => $runId,
        'user_id' => $userId,
        'image_id' => $imageId,     // WICHTIG für den Rückweg
        'position' => $position,    // WICHTIG für UI Zuordnung
        'action' => $action,
        'image_url' => $finalImageUrl,
        'timestamp' => $timestamp(),
    ];

    // 8. Webhook senden (cURL)
    $ch = curl_init($webhookUrl);
    if ($ch === false) {
        throw new RuntimeException('cURL Initialisierung fehlgeschlagen.');
    }

    $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => $jsonPayload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json'
        ],
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5
    ]);

    $webhookResponse = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($webhookResponse === false) {
        throw new RuntimeException('Webhook Verbindungsfehler: ' . $curlError);
    }

    if ($httpCode >= 400) {
        throw new RuntimeException("Webhook antwortete mit Fehlercode $httpCode");
    }

    // 9. Status Update in DB
    $pdo->beginTransaction();
    try {
        $msg = 'Starte ' . strtoupper($action) . '...';
        
        // Workflow auf running setzen
        $updateRun = $pdo->prepare(
            "UPDATE workflow_runs SET status = 'running', last_message = :msg, started_at = NOW() WHERE id = :rid"
        );
        $updateRun->execute([':msg' => $msg, ':rid' => $runId]);

        // User State aktualisieren
        $updateState = $pdo->prepare(
            "INSERT INTO user_state (user_id, current_run_id, last_status, last_message, updated_at) 
             VALUES (:uid, :rid, 'running', :msg, NOW())
             ON DUPLICATE KEY UPDATE 
             current_run_id = VALUES(current_run_id), last_status = VALUES(last_status), last_message = VALUES(last_message), updated_at = NOW()"
        );
        $updateState->execute([':uid' => $userId, ':rid' => $runId, ':msg' => $msg]);

        // Loggen
        log_status_message($pdo, $runId, $userId, $msg);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    // 10. Erfolg melden
    $respond([
        'success' => true,
        'status' => 'ok',
        'message' => 'Update gestartet.',
        'run_id' => $runId,
        'action' => $action
    ]);

} catch (Throwable $e) {
    // Fehlerbehandlung
    $httpCode = ($e instanceof RuntimeException) ? 502 : 500; // 502 für Bad Gateway (Webhook Fehler)
    if ($e->getCode() === 400) $httpCode = 400; // Validierungsfehler

    error_log('[start-workflow-update] Error: ' . $e->getMessage());
    
    $respond([
        'success' => false,
        'status' => 'error',
        'message' => $e->getMessage()
    ], $httpCode);
}