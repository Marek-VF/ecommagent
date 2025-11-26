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
