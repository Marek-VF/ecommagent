<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../status_logger.php';

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
$requestedRunId = null;

if (isset($_GET['run_id'])) {
    $runIdParam = trim((string) $_GET['run_id']);
    if ($runIdParam !== '' && ctype_digit($runIdParam)) {
        $requestedRunId = (int) $runIdParam;
        if ($requestedRunId <= 0) {
            $requestedRunId = null;
        }
    }
}

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

try {
    $runId = $requestedRunId;

    if ($runId === null) {
        $stateStatement = $pdo->prepare(
            'SELECT current_run_id FROM user_state WHERE user_id = :user_id ORDER BY updated_at DESC LIMIT 1'
        );
        $stateStatement->execute([':user_id' => $userId]);
        $stateRunId = $stateStatement->fetchColumn();

        if ($stateRunId !== false) {
            $runId = (int) $stateRunId;
            if ($runId <= 0) {
                $runId = null;
            }
        }
    }

    if ($runId === null) {
        $lastRunStatement = $pdo->prepare(
            'SELECT id FROM workflow_runs WHERE user_id = :user_id ORDER BY id DESC LIMIT 1'
        );
        $lastRunStatement->execute([':user_id' => $userId]);
        $lastRunId = $lastRunStatement->fetchColumn();

        if ($lastRunId !== false) {
            $runId = (int) $lastRunId;
            if ($runId <= 0) {
                $runId = null;
            }
        }
    }

    if ($runId === null) {
        echo json_encode([
            'ok'     => true,
            'run_id' => null,
            'items'  => [],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
//läuft auf fehler wenn noch keine session gesetzt ist
    $logsStatement = $pdo->prepare(
        'SELECT id, message, severity, code, created_at FROM status_logs_new WHERE user_id = :user_id AND run_id = :run_id ORDER BY created_at DESC, id DESC LIMIT 20'
    );
    $logsStatement->execute([
        ':user_id' => $userId,
        ':run_id'  => $runId,
    ]);
    $logRows = $logsStatement->fetchAll(PDO::FETCH_ASSOC);

    $logs = [];

    foreach ($logRows as $row) {
        $event = resolve_status_event($row['code'] ?? '');
        $createdAt = null;

        if (isset($row['created_at'])) {
            try {
                $date = new DateTime((string) $row['created_at']);
                $createdAt = $date->format(DATE_ATOM);
            } catch (Throwable $dateException) {
                $createdAt = null;
            }
        }

        $logs[] = [
            'id'         => isset($row['id']) ? (int) $row['id'] : null,
            'message'    => isset($row['message']) && trim((string) $row['message']) !== ''
                ? (string) $row['message']
                : $event['label'],
            'code'       => $event['code'],
            'severity'   => isset($row['severity']) && (string) $row['severity'] !== ''
                ? (string) $row['severity']
                : $event['severity'],
            'icon_html'  => $event['icon_html'],
            'created_at' => $createdAt,
        ];
    }

    echo json_encode([
        'ok'     => true,
        'run_id' => $runId,
        'items'  => $logs,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => 'server error',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
