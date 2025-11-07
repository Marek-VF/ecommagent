<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$config = require __DIR__ . '/../config.php';

session_start();
require_once __DIR__ . '/../db.php';

/**
 * @param array<string,mixed> $config
 */
function resolveUploadBaseUrl(array $config): string
{
    $candidates = [];

    if (defined('UPLOAD_BASE_URL')) {
        $candidate = trim((string) UPLOAD_BASE_URL);
        if ($candidate !== '') {
            $candidates[] = $candidate;
        }
    }

    if (defined('BASE_URL')) {
        $candidate = trim((string) BASE_URL);
        if ($candidate !== '') {
            $candidates[] = $candidate;
        }
    }

    if (defined('APP_URL')) {
        $candidate = trim((string) APP_URL);
        if ($candidate !== '') {
            $candidates[] = $candidate;
        }
    }

    foreach (['upload_base_url', 'upload_base', 'base_url', 'app_url', 'asset_base_url'] as $key) {
        if (isset($config[$key]) && is_string($config[$key])) {
            $candidate = trim($config[$key]);
            if ($candidate !== '') {
                $candidates[] = $candidate;
            }
        }
    }

    foreach ($candidates as $candidate) {
        $normalized = rtrim($candidate, '/');
        if ($normalized !== '') {
            return $normalized;
        }
    }

    return '';
}

function buildAbsoluteImageUrl(string $path, string $baseUrl): string
{
    $trimmed = trim($path);
    if ($trimmed === '') {
        return '';
    }

    if (preg_match('#^(?:[a-z][a-z0-9+.-]*:|//)#i', $trimmed)) {
        return $trimmed;
    }

    if (strpos($trimmed, '/') === 0) {
        return $trimmed;
    }

    if ($baseUrl !== '') {
        return $baseUrl . '/' . ltrim($trimmed, '/');
    }

    return '/' . ltrim($trimmed, '/');
}

$baseUrl = resolveUploadBaseUrl(is_array($config) ? $config : []);

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
$runImageMap = [];

if (!empty($runs)) {
    $runIds = array_map(static fn ($row) => isset($row['id']) ? (int) $row['id'] : 0, $runs);
    $runIds = array_values(array_filter($runIds, static fn ($value) => $value > 0));

    if ($runIds !== []) {
        $placeholderList = implode(',', array_fill(0, count($runIds), '?'));
        $imageStmt = $pdo->prepare(
            "SELECT run_id, file_path FROM run_images WHERE run_id IN ($placeholderList) ORDER BY created_at ASC, id ASC"
        );
        $imageStmt->execute($runIds);

        while ($row = $imageStmt->fetch(PDO::FETCH_ASSOC)) {
            $runId = isset($row['run_id']) ? (int) $row['run_id'] : 0;
            if ($runId <= 0) {
                continue;
            }

            $filePath = isset($row['file_path']) ? trim((string) $row['file_path']) : '';
            if ($filePath === '') {
                continue;
            }

            $absolutePath = buildAbsoluteImageUrl($filePath, $baseUrl);
            if ($absolutePath === '') {
                continue;
            }

            if (!isset($runImageMap[$runId])) {
                $runImageMap[$runId] = [];
            }

            $runImageMap[$runId][] = $absolutePath;
        }
    }
}

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
        'started_at'      => $startedAtRaw,
        'started_at_iso'  => $startedAtIso,
        'finished_at'     => $finishedAtRaw,
        'finished_at_iso' => $finishedAtIso,
        'original_images' => array_values($runImageMap[$runId] ?? []),
    ];
}

echo json_encode([
    'ok'   => true,
    'data' => $items,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
