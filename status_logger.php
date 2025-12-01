<?php
declare(strict_types=1);

/**
 * Extracts a status message from the provided payload (expects key `statusmeldung`).
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
 * Central helper to store workflow status updates in the canonical status_logs_new table.
 *
 * status_logs_new supersedes the legacy status_logs table; all n8n callbacks must use this helper
 * to keep future UI components (status icons, severity mapping) consistent.
 *
 * @param PDO         $pdo      Active database connection
 * @param int         $runId    ID of the workflow_runs record (must belong to the user)
 * @param int         $userId   Owner of the run
 * @param string      $message  Human readable status message
 * @param string      $source   Origin of the event (e.g. n8n, backend, frontend)
 * @param string      $severity info|success|warning|error — used for UI icon mapping
 * @param string|null $code     Optional machine-readable code (e.g. HTTP_404, UPLOAD_OK)
 */
function log_status_message(
    PDO $pdo,
    int $runId,
    int $userId,
    string $message,
    string $source = 'n8n',
    string $severity = 'info',
    ?string $code = null
): bool {
    $normalizedMessage = trim($message);
    $normalizedSource = trim($source) !== '' ? trim($source) : 'n8n';
    $allowedSeverities = ['info', 'success', 'warning', 'error'];
    $normalizedSeverity = in_array(strtolower($severity), $allowedSeverities, true)
        ? strtolower($severity)
        : 'info';

    if ($runId <= 0 || $userId <= 0 || $normalizedMessage === '') {
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
            'INSERT INTO status_logs_new (run_id, user_id, message, source, severity, code, created_at) '
            . 'VALUES (:rid, :uid, :message, :source, :severity, :code, NOW())'
        );
        $insert->execute([
            ':rid'      => $runId,
            ':uid'      => $userId,
            ':message'  => $normalizedMessage,
            ':source'   => $normalizedSource,
            ':severity' => $normalizedSeverity,
            ':code'     => $code,
        ]);

        return true;
    } catch (Throwable $exception) {
        // Logging errors must not break the main request; we optionally surface them via the PHP error log.
        error_log('[status_logger] ' . $exception->getMessage());

        return false;
    }
}
