<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../status_logger.php';
require_once __DIR__ . '/../credits.php';

$expectedToken = isset($config['receiver_api_token']) ? (string) $config['receiver_api_token'] : '';
$providedToken = isset($_SERVER['HTTP_X_API_TOKEN']) ? (string) $_SERVER['HTTP_X_API_TOKEN'] : '';

if ($providedToken === '' || $expectedToken === '' || !hash_equals($expectedToken, $providedToken)) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$rawInput = file_get_contents('php://input');
$inputData = json_decode($rawInput, true);
$statusCodeFromRequest = is_array($inputData) ? extract_status_message($inputData) : null;

$runId = null;
$userId = null;
$stepType = null;
$executedSuccessfully = false;

if (is_array($inputData)) {
    if (isset($inputData['run_id'])) {
        if (is_int($inputData['run_id'])) {
            $runId = $inputData['run_id'];
        } elseif (is_string($inputData['run_id']) && ctype_digit(trim($inputData['run_id']))) {
            $runId = (int) trim($inputData['run_id']);
        }
    }

    if (isset($inputData['user_id'])) {
        if (is_int($inputData['user_id'])) {
            $userId = $inputData['user_id'];
        } elseif (is_string($inputData['user_id']) && ctype_digit(trim($inputData['user_id']))) {
            $userId = (int) trim($inputData['user_id']);
        }
    }

    $stepTypeRaw = $inputData['step_type'] ?? null;
    if (is_string($stepTypeRaw) || is_numeric($stepTypeRaw)) {
        $stepType = trim((string) $stepTypeRaw);
        if ($stepType === '') {
            $stepType = null;
        }
    }

    $executedSuccessfully = filter_var($inputData['executed_successfully'] ?? false, FILTER_VALIDATE_BOOLEAN);
}

if ($runId === null || $userId === null || $runId <= 0 || $userId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_input'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $pdo = getPDO();
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['error' => 'db_error'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $runStatement = $pdo->prepare(
        'SELECT user_id FROM workflow_runs WHERE id = :run_id LIMIT 1'
    );
    $runStatement->execute([':run_id' => $runId]);
    $runRow = $runStatement->fetch(PDO::FETCH_ASSOC);

    if ($runRow === false) {
        http_response_code(404);
        echo json_encode(['error' => 'run_not_found'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $runUserId = isset($runRow['user_id']) ? (int) $runRow['user_id'] : 0;
    if ($runUserId !== $userId) {
        http_response_code(404);
        echo json_encode(['error' => 'run_user_mismatch'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($statusCodeFromRequest !== null) {
        // Neues Statuslog-System: Speichert jede eingehende statusmeldung.
        $event = log_event($pdo, $runId, $userId, $statusCodeFromRequest, 'n8n');

        $updateRunStatus = $pdo->prepare(
            'UPDATE workflow_runs SET last_message = :message WHERE id = :run_id AND user_id = :user_id'
        );
        $updateRunStatus->execute([
            ':message' => $event['label'] ?? '',
            ':run_id'  => $runId,
            ':user_id' => $userId,
        ]);
    }

    $userCategoryStmt = $pdo->prepare('SELECT prompt_category_id FROM users WHERE id = :id LIMIT 1');
    $userCategoryStmt->execute([':id' => $userId]);
    $preferredCategoryId = $userCategoryStmt->fetchColumn();

    $variantSql = 'SELECT pv.variant_slot, pv.location, pv.lighting, pv.mood, pv.season, pv.model_type, pv.model_pose, pv.view_mode, pc.category_key
                     FROM prompt_variants pv
                     INNER JOIN prompt_categories pc ON pc.id = pv.category_id
                    WHERE pv.user_id = :user_id';

    $params = [':user_id' => $userId];

    if ($preferredCategoryId !== false && $preferredCategoryId !== null) {
        $variantSql .= ' AND pv.category_id = :category_id';
        $params[':category_id'] = (int) $preferredCategoryId;
    }

    $variantSql .= ' ORDER BY pv.variant_slot ASC';

    $variantsStatement = $pdo->prepare($variantSql);
    $variantsStatement->execute($params);
    $variantRows = $variantsStatement->fetchAll(PDO::FETCH_ASSOC);

    $variants = [];
    foreach ($variantRows as $row) {
        $slot = isset($row['variant_slot']) ? (int) $row['variant_slot'] : 0;
        if ($slot < 1 || $slot > 3) {
            continue;
        }

        $variants[] = [
            'id'         => $slot,
            'CATEGORY'   => $row['category_key'] ?? '',
            'LOCATION'   => $row['location'] ?? '',
            'LIGHTING'   => $row['lighting'] ?? '',
            'MOOD'       => $row['mood'] ?? '',
            'SEASON'     => $row['season'] ?? '',
            'MODEL_TYPE' => $row['model_type'] ?? '',
            'MODEL_POSE' => $row['model_pose'] ?? '',
            'VIEW_MODE'  => $row['view_mode'] ?? 'full_body',
        ];
    }

    if ($variants === []) {
        http_response_code(404);
        echo json_encode(['error' => 'no_prompt_variants'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($executedSuccessfully && $stepType !== null) {
        try {
            $meta = ['source' => 'get-prompt-variants.php'];
            charge_credits($pdo, $userId, $runId, $stepType, $meta);
        } catch (Throwable $creditException) {
            error_log('[get-prompt-variants][credits] ' . $creditException->getMessage());
        }
    }

    http_response_code(200);
    echo json_encode($variants, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['error' => 'server_error'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
