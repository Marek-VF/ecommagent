<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

session_start();
require_once __DIR__ . '/../db.php';

$userId = $_SESSION['user']['id'] ?? null;
if (!$userId) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'not authenticated'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$pdo = getPDO();

$runsStmt = $pdo->prepare(
    "SELECT
        wr.id,
        wr.started_at,
        wr.finished_at,
        wr.status,
        wr.last_message,
        wr.last_step_status,
        (
            SELECT inotes.product_name
              FROM item_notes inotes
             WHERE inotes.user_id = wr.user_id
               AND inotes.run_id = wr.id
               AND inotes.product_name IS NOT NULL
               AND TRIM(inotes.product_name) <> ''
             ORDER BY inotes.created_at DESC, inotes.id DESC
             LIMIT 1
        ) AS product_name
     FROM workflow_runs wr
     WHERE wr.user_id = :user_id
     ORDER BY wr.started_at DESC, wr.id DESC
     LIMIT 30"
);
$runsStmt->execute(['user_id' => $userId]);
$runs = $runsStmt->fetchAll(PDO::FETCH_ASSOC);

$items = [];
foreach ($runs as $run) {
    $runId = isset($run['id']) ? (int) $run['id'] : 0;

    $startedAtRaw = $run['started_at'] ?? null;
    $startedAtIso = null;
    $startedAtDate = null;
    if (is_string($startedAtRaw) && $startedAtRaw !== '') {
        try {
            $startedAtDate = new DateTimeImmutable($startedAtRaw);
            $startedAtIso = $startedAtDate->format(DATE_ATOM);
        } catch (Throwable $exception) {
            $startedAtDate = null;
            $startedAtIso = null;
        }
    }

    $finishedAtRaw = $run['finished_at'] ?? null;
    $finishedAtIso = null;
    $finishedAtDate = null;
    if (is_string($finishedAtRaw) && $finishedAtRaw !== '') {
        try {
            $finishedAtDate = new DateTimeImmutable($finishedAtRaw);
            $finishedAtIso = $finishedAtDate->format(DATE_ATOM);
        } catch (Throwable $exception) {
            $finishedAtDate = null;
            $finishedAtIso = null;
        }
    }

    $dateSource = $startedAtDate ?? $finishedAtDate ?? null;
    $dateLabel = $dateSource !== null ? $dateSource->format('d.m.Y') : date('d.m.Y');

    $rawTitle = '';
    if (isset($run['product_name']) && is_string($run['product_name'])) {
        $rawTitle = trim($run['product_name']);
    }

    if ($rawTitle === '') {
        $titleDate = $startedAtDate ?? $finishedAtDate;
        if ($titleDate !== null) {
            $rawTitle = 'Run vom ' . $titleDate->format('d.m.Y H:i');
        } else {
            $rawTitle = sprintf('Run #%d', $runId > 0 ? $runId : count($items) + 1);
        }
    }

    $items[] = [
        'id'              => $runId,
        'date'            => $dateLabel,
        'title'           => $rawTitle,
        'status'          => $run['status'] ?? null,
        'last_message'    => $run['last_message'] ?? null,
        'last_step_status'=> $run['last_step_status'] ?? null,
        'started_at'      => $startedAtRaw,
        'started_at_iso'  => $startedAtIso,
        'finished_at'     => $finishedAtRaw,
        'finished_at_iso' => $finishedAtIso,
    ];
}

echo json_encode([
    'ok'   => true,
    'data' => $items,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
