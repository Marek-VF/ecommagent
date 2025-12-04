<?php
declare(strict_types=1);

require_once __DIR__ . '/auth/bootstrap.php';
require_once __DIR__ . '/credits.php';
require_once __DIR__ . '/status_logger.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

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
    if (is_int($value) && $value > 0) {
        return $value;
    }

    if (is_string($value)) {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        if (ctype_digit($trimmed)) {
            $intValue = (int) $trimmed;
            return $intValue > 0 ? $intValue : null;
        }
    }

    if (is_numeric($value)) {
        $intValue = (int) $value;
        return $intValue > 0 ? $intValue : null;
    }

    return null;
};

if (!auth_is_logged_in()) {
    $respond([
        'success'   => false,
        'status'    => 'unauthorized',
        'message'   => 'Anmeldung erforderlich.',
        'timestamp' => $timestamp(),
        'logout'    => true,
    ], 401);
}

$user = auth_user();
$userId = isset($user['id']) && is_numeric($user['id']) ? (int) $user['id'] : 0;

if ($userId <= 0) {
    $respond([
        'success'   => false,
        'status'    => 'unauthorized',
        'message'   => 'Nicht angemeldet. Bitte erneut einloggen.',
        'timestamp' => $timestamp(),
        'logout'    => true,
    ], 401);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $respond([
        'success'   => false,
        'status'    => 'error',
        'message'   => 'Methode nicht erlaubt.',
        'timestamp' => $timestamp(),
    ], 405);
}

$rawBody = file_get_contents('php://input');
$data = null;
if (is_string($rawBody) && trim($rawBody) !== '') {
    $decoded = json_decode($rawBody, true);
    if (is_array($decoded)) {
        $data = $decoded;
    }
}

if (!is_array($data)) {
    $data = $_POST;
}

$runId = $normalizePositiveInt($data['run_id'] ?? null);
if ($runId === null) {
    $respond([
        'success'   => false,
        'status'    => 'error',
        'message'   => 'run_id fehlt.',
        'timestamp' => $timestamp(),
    ], 400);
}

$requestedUserId = $normalizePositiveInt($data['user_id'] ?? null);
if ($requestedUserId !== null && $requestedUserId !== $userId) {
    $respond([
        'success'   => false,
        'status'    => 'forbidden',
        'message'   => 'Keine Berechtigung für diesen Workflow.',
        'timestamp' => $timestamp(),
    ], 403);
}

$imageId = $normalizePositiveInt($data['image_id'] ?? null);
$position = $normalizePositiveInt($data['position'] ?? null);

$action = isset($data['action']) && is_string($data['action']) ? strtolower(trim($data['action'])) : '';
$allowedActions = ['2k', '4k', 'edit'];
if (!in_array($action, $allowedActions, true)) {
    $respond([
        'success'   => false,
        'status'    => 'error',
        'message'   => 'Ungültige Aktion.',
        'timestamp' => $timestamp(),
    ], 400);
}

$imageUrl = isset($data['image_url']) ? trim((string) $data['image_url']) : '';
if ($imageUrl === '') {
    $respond([
        'success'   => false,
        'status'    => 'error',
        'message'   => 'image_url fehlt.',
        'timestamp' => $timestamp(),
    ], 400);
}

try {
    $config = auth_config();
    $webhook = $config['workflow_webhook_update'] ?? null;
    if (!is_string($webhook) || trim($webhook) === '') {
        throw new RuntimeException('Workflow-Update-Webhook ist nicht konfiguriert.');
    }

    $pdo = auth_pdo();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $runStmt = $pdo->prepare('SELECT id, status FROM workflow_runs WHERE id = :run_id AND user_id = :user_id LIMIT 1');
    $runStmt->execute([
        ':run_id'  => $runId,
        ':user_id' => $userId,
    ]);
    $run = $runStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($run === null) {
        $respond([
            'success'   => false,
            'status'    => 'not_found',
            'message'   => 'Workflow nicht gefunden.',
            'timestamp' => $timestamp(),
        ], 404);
    }

    $statusValue = isset($run['status']) ? strtolower(trim((string) $run['status'])) : '';
    if ($statusValue === 'running') {
        $respond([
            'success'   => false,
            'status'    => 'conflict',
            'message'   => 'Workflow läuft bereits.',
            'timestamp' => $timestamp(),
        ], 409);
    }

    // Credits prüfen (Platzhalter: pro Aktion kann eine eigene Logik hinterlegt werden)
    $balanceStmt = $pdo->prepare('SELECT credits_balance FROM users WHERE id = :user LIMIT 1');
    $balanceStmt->execute([':user' => $userId]);
    $creditsBalance = $balanceStmt->fetchColumn();

    if ($creditsBalance === false) {
        $respond([
            'success'   => false,
            'ok'        => false,
            'status'    => 'error',
            'message'   => 'Benutzer konnte nicht gefunden werden.',
            'timestamp' => $timestamp(),
        ], 500);
    }

    $requiredCredits = get_credit_price($config, $action) ?? 0.0;

    if ($requiredCredits > 0 && (float) $creditsBalance < $requiredCredits) {
        $requiredDisplay = number_format((float) $requiredCredits, 2, ',', '.');
        $balanceDisplay  = number_format((float) $creditsBalance, 2, ',', '.');

        $message = sprintf(
            'Nicht genügend Credits für %s: benötigt %s, verfügbar %s. Bitte Guthaben aufladen.',
            strtoupper($action),
            $requiredDisplay,
            $balanceDisplay
        );

        log_status_message($pdo, $runId, $userId, $message);

        $updateRun = $pdo->prepare('UPDATE workflow_runs SET last_message = :message WHERE id = :run_id AND user_id = :user_id');
        $updateRun->execute([
            ':message' => $message,
            ':run_id'  => $runId,
            ':user_id' => $userId,
        ]);

        $respond([
            'success'            => false,
            'ok'                 => false,
            'status'             => 'not_enough_credits',
            'message'            => $message,
            'timestamp'          => $timestamp(),
            'not_enough_credits' => true,
            'required'           => $requiredCredits,
            'balance'            => (float) $creditsBalance,
        ], 400);
    }

    $payload = [
        'run_id'      => $runId,
        'user_id'     => $userId,
        'action'      => $action,
        'image_url'   => $imageUrl,
        'image_id'    => $imageId,
        'position'    => $position,
        'timestamp'   => $timestamp(),
        'requested_at'=> $timestamp(),
    ];

    $ch = curl_init($webhook);
    if ($ch === false) {
        throw new RuntimeException('Webhook konnte nicht initialisiert werden.');
    }

    $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => $jsonPayload,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json, */*;q=0.8',
        ],
    ]);

    $webhookResponse = curl_exec($ch);
    $webhookStatus   = curl_getinfo($ch, CURLINFO_RESPONSE_CODE) ?: null;
    $curlError       = curl_error($ch);
    curl_close($ch);

    if ($webhookResponse === false) {
        $errorMessage = $curlError !== '' ? $curlError : 'Unbekannter Fehler bei der Webhook-Ausführung.';
        throw new RuntimeException('Webhook-Aufruf fehlgeschlagen: ' . $errorMessage);
    }

    $forwardResponse = json_decode($webhookResponse, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $forwardResponse = $webhookResponse;
    }

    if (!is_int($webhookStatus) || $webhookStatus < 200 || $webhookStatus >= 300) {
        throw new RuntimeException('Workflow-Update-Webhook antwortete mit Status ' . ($webhookStatus ?? 'unbekannt') . '.');
    }

    $pdo->beginTransaction();
    try {
        $runMessage = 'Starte ' . strtoupper($action) . '...';
        $updateRun = $pdo->prepare(
            'UPDATE workflow_runs SET status = :status, last_message = :last_message, started_at = COALESCE(started_at, NOW()) WHERE id = :run_id AND user_id = :user_id'
        );
        $updateRun->execute([
            ':status'       => 'running',
            ':last_message' => $runMessage,
            ':run_id'       => $runId,
            ':user_id'      => $userId,
        ]);

        $stateStmt = $pdo->prepare(
            'INSERT INTO user_state (user_id, current_run_id, last_status, last_message, updated_at) VALUES (:user_id, :current_run_id, :last_status, :last_message, NOW())'
            . ' ON DUPLICATE KEY UPDATE'
            . '     current_run_id = VALUES(current_run_id),'
            . '     last_status = VALUES(last_status),'
            . '     last_message = VALUES(last_message),'
            . '     updated_at = NOW()'
        );
        $stateStmt->execute([
            ':user_id'        => $userId,
            ':current_run_id' => $runId,
            ':last_status'    => 'running',
            ':last_message'   => $runMessage,
        ]);

        log_status_message($pdo, $runId, $userId, $runMessage);

        $pdo->commit();
    } catch (Throwable $transactionException) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $transactionException;
    }

    $respond([
        'success'          => true,
        'status'           => 'ok',
        'message'          => 'Update gestartet.',
        'run_id'           => $runId,
        'user_id'          => $userId,
        'timestamp'        => $timestamp(),
        'webhook_status'   => $webhookStatus,
        'webhook_response' => $forwardResponse,
    ]);
} catch (Throwable $exception) {
    $respond([
        'success'   => false,
        'status'    => 'error',
        'message'   => $exception->getMessage(),
        'timestamp' => $timestamp(),
    ], $exception instanceof RuntimeException ? 400 : 500);
} finally {
    restore_error_handler();
}
