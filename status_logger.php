<?php
declare(strict_types=1);

/**
 * Extracts a status code from the provided payload (expects key `statusmeldung`).
 */
function extract_status_message(array $payload): ?string
{
    if (!array_key_exists('statusmeldung', $payload)) {
        return null;
    }

    $raw = $payload['statusmeldung'];
    if (is_array($raw)) {
        return null;
    }

    $message = (string) $raw;
    if (trim($message) === '') {
        return null;
    }

    return $message;
}

/**
 * Resolves a status code into its configured label, severity and icon markup.
 */
function resolve_status_event(string $code): array
{
    static $events = null;

    if ($events === null) {
        $events = require __DIR__ . '/settings/status_events.php';
        if (!is_array($events)) {
            $events = [];
        }
    }

    $normalizedCode = strtoupper(trim($code));
    if ($normalizedCode === '' || !array_key_exists($normalizedCode, $events)) {
        $normalizedCode = 'UNKNOWN';
    }

    $event = $events[$normalizedCode] ?? $events['UNKNOWN'] ?? [];

    return [
        'code'      => $normalizedCode,
        'label'     => isset($event['label']) ? (string) $event['label'] : 'Unbekannter Status.',
        'severity'  => isset($event['severity']) ? (string) $event['severity'] : 'info',
        'icon_html' => isset($event['icon_html']) ? (string) $event['icon_html'] : '<span class="material-icons-outlined status-icon text-info">info</span>',
    ];
}

/**
 * Stores a structured status event in the new status log table if run and user IDs are valid.
 */
function log_event(PDO $pdo, int $runId, int $userId, string $code, string $source = 'n8n'): ?array
{
    if ($runId <= 0 || $userId <= 0) {
        return null;
    }

    $event = resolve_status_event($code);
    $normalizedSource = in_array($source, ['n8n', 'backend', 'frontend'], true) ? $source : 'backend';

    try {
        $userStmt = $pdo->prepare('SELECT 1 FROM users WHERE id = :uid LIMIT 1');
        $userStmt->execute([':uid' => $userId]);
        if ($userStmt->fetchColumn() === false) {
            return null;
        }

        $runStmt = $pdo->prepare('SELECT 1 FROM workflow_runs WHERE id = :rid AND user_id = :uid LIMIT 1');
        $runStmt->execute([
            ':rid' => $runId,
            ':uid' => $userId,
        ]);

        if ($runStmt->fetchColumn() === false) {
            return null;
        }

        $insert = $pdo->prepare(
            'INSERT INTO status_logs_new (run_id, user_id, message, source, severity, code, created_at) VALUES (:rid, :uid, :message, :source, :severity, :code, NOW())'
        );
        $insert->execute([
            ':rid'      => $runId,
            ':uid'      => $userId,
            ':message'  => $event['label'],
            ':source'   => $normalizedSource,
            ':severity' => $event['severity'],
            ':code'     => $event['code'],
        ]);

        return $event;
    } catch (Throwable $exception) {
        return null;
    }
}

/**
 * Stores a status message in the new status log table if run and user IDs are valid.
 */
function log_status_message(PDO $pdo, int $runId, int $userId, string $message): bool
{
    if ($runId <= 0 || $userId <= 0 || trim($message) === '') {
        return false;
    }

    try {
        $userStmt = $pdo->prepare('SELECT 1 FROM users WHERE id = :uid LIMIT 1');
        $userStmt->execute([':uid' => $userId]);
        if ($userStmt->fetchColumn() === false) {
            return false;
        }

        $runStmt = $pdo->prepare('SELECT 1 FROM workflow_runs WHERE id = :rid AND user_id = :uid LIMIT 1');
        $runStmt->execute([
            ':rid' => $runId,
            ':uid' => $userId,
        ]);

        if ($runStmt->fetchColumn() === false) {
            return false;
        }

        $insert = $pdo->prepare(
            'INSERT INTO status_logs_new (run_id, user_id, message, created_at) VALUES (:rid, :uid, :message, NOW())'
        );
        $insert->execute([
            ':rid'     => $runId,
            ':uid'     => $userId,
            ':message' => $message,
        ]);

        return true;
    } catch (Throwable $exception) {
        return false;
    }
}
