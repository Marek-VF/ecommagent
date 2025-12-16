<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../auth/bootstrap.php';
require_once __DIR__ . '/../../credits.php';
require_once __DIR__ . '/../../paypal/PayPalClient.php';

if (!function_exists('paypal_log_exception')) {
    function paypal_log_exception(Throwable $exception): void
    {
        $logDir = __DIR__ . '/../../logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }

        $message = '[' . date('c') . '] ' . $exception->getMessage() . "\n" . $exception->getTraceAsString() . "\n";
        error_log($message, 3, $logDir . '/paypal.log');
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$user = auth_user();
if ($user === null || !isset($user['id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'not_authenticated']);
    exit;
}

$config = auth_config();
$paypalConfig = $config['paypal'] ?? [];
$paypalEnv = strtolower((string) ($paypalConfig['environment'] ?? 'sandbox'));
$paypalDebug = $paypalEnv === 'sandbox' || !empty($paypalConfig['debug']);

$input = json_decode((string) file_get_contents('php://input'), true) ?? [];
$orderId = isset($input['orderID']) && is_string($input['orderID']) ? trim($input['orderID']) : '';

if ($orderId === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing_order_id']);
    exit;
}

$pdo = auth_pdo();

try {
    $client = new PayPalClient($paypalConfig);
    $accessToken = $client->getAccessToken();
    $captureResponse = $client->captureOrder($accessToken, $orderId);
} catch (Throwable $exception) {
    paypal_log_exception($exception);

    $debugData = null;
    if ($paypalDebug) {
        $debugData = ['message' => $exception->getMessage()];

        if ($exception instanceof PayPalApiException) {
            $debugData['paypal'] = [
                'status'   => $exception->getStatusCode(),
                'debug_id' => $exception->getDebugId(),
                'details'  => $exception->getDetails(),
                'raw'      => $exception->getRawResponse(),
            ];
        }
    }

    http_response_code(500);
    $response = ['ok' => false, 'error' => 'paypal_capture_failed'];

    if ($debugData !== null) {
        $response['debug'] = $debugData;
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$orderStatus = isset($captureResponse['status']) ? (string) $captureResponse['status'] : '';
$purchaseUnits = $captureResponse['purchase_units'] ?? [];
$captureId = null;
$captureStatus = '';

if (is_array($purchaseUnits) && isset($purchaseUnits[0]['payments']['captures'][0])) {
    $captureData = $purchaseUnits[0]['payments']['captures'][0];
    $captureId = isset($captureData['id']) ? (string) $captureData['id'] : null;
    $captureStatus = isset($captureData['status']) ? (string) $captureData['status'] : '';
}

if ($captureId === null || ($orderStatus !== 'COMPLETED' && $captureStatus !== 'COMPLETED')) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'capture_not_completed']);
    exit;
}

$rawPayload = json_encode($captureResponse, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($rawPayload === false) {
    $rawPayload = '{}';
}

try {
    $pdo->beginTransaction();

    $paymentStmt = $pdo->prepare('SELECT * FROM paypal_payments WHERE paypal_order_id = :order_id AND user_id = :user_id FOR UPDATE');
    $paymentStmt->execute([
        ':order_id' => $orderId,
        ':user_id'  => (int) $user['id'],
    ]);

    $payment = $paymentStmt->fetch(PDO::FETCH_ASSOC);

    if (!$payment) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'order_not_found']);
        exit;
    }

    if (($payment['status'] ?? '') === 'completed') {
        $pdo->commit();
        $balanceStmt = $pdo->prepare('SELECT credits_balance FROM users WHERE id = :user LIMIT 1');
        $balanceStmt->execute([':user' => (int) $user['id']]);
        $balance = (float) $balanceStmt->fetchColumn();

        echo json_encode(['ok' => true, 'alreadyCaptured' => true, 'balance' => $balance]);
        exit;
    }

    $creditsToAdd = (float) ($payment['credits'] ?? 0);

    if (!empty($payment['paypal_capture_id']) && $payment['paypal_capture_id'] !== $captureId) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'capture_conflict']);
        exit;
    }

    $transactionId = add_credits(
        $pdo,
        (int) $user['id'],
        $creditsToAdd,
        'paypal_purchase',
        [
            'package_id'        => $payment['package_id'] ?? null,
            'paypal_order_id'   => $orderId,
            'paypal_capture_id' => $captureId,
        ]
    );

    $updateStmt = $pdo->prepare('UPDATE paypal_payments SET paypal_capture_id = :capture_id, status = :status, credit_transaction_id = :transaction_id, raw_payload = :payload WHERE id = :id');
    $updateStmt->execute([
        ':capture_id'       => $captureId,
        ':status'           => 'completed',
        ':transaction_id'   => $transactionId ?: null,
        ':payload'          => $rawPayload,
        ':id'               => (int) $payment['id'],
    ]);

    $pdo->commit();

    $balanceStmt = $pdo->prepare('SELECT credits_balance FROM users WHERE id = :user LIMIT 1');
    $balanceStmt->execute([':user' => (int) $user['id']]);
    $balance = (float) $balanceStmt->fetchColumn();

    echo json_encode([
        'ok'           => true,
        'balance'      => $balance,
        'creditsAdded' => $creditsToAdd,
        'captureId'    => $captureId,
    ]);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'capture_persist_failed']);
}
