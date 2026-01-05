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
$pdo = auth_pdo();
$defaultCurrency = isset($paypalConfig['currency']) && is_string($paypalConfig['currency'])
    ? strtoupper($paypalConfig['currency'])
    : 'EUR';

$input = json_decode((string) file_get_contents('php://input'), true) ?? [];
$packageId = isset($input['package_id']) && is_string($input['package_id']) ? trim($input['package_id']) : '';

if ($packageId === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'unknown_package']);
    exit;
}

$package = get_credit_package($pdo, $packageId);
if ($package === null) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'unknown_package']);
    exit;
}

$amount = isset($package['price']) ? (float) $package['price'] : 0.0;
$credits = isset($package['credits']) ? (float) $package['credits'] : 0.0;
$currency = isset($package['currency']) && is_string($package['currency']) && $package['currency'] !== ''
    ? strtoupper($package['currency'])
    : $defaultCurrency;
$description = isset($package['label']) && is_string($package['label']) && $package['label'] !== ''
    ? $package['label']
    : ('Credits ' . $packageId);

if ($amount <= 0 || $credits <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_package']);
    exit;
}

try {
    $client = new PayPalClient($paypalConfig);
    $accessToken = $client->getAccessToken();
    $orderData = $client->createOrder(
        $accessToken,
        $amount,
        $currency,
        $description,
        'user-' . (int) $user['id'] . '-' . $packageId
    );
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
    $response = [
        'ok' => false,
        'error' => 'paypal_unavailable',
        'message' => 'PayPal Bestellung konnte nicht erstellt werden.',
    ];

    if ($debugData !== null) {
        $response['debug'] = $debugData;
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$orderId = isset($orderData['id']) && is_string($orderData['id']) ? $orderData['id'] : '';
if ($orderId === '') {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'paypal_response_invalid']);
    exit;
}

$rawPayload = json_encode($orderData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($rawPayload === false) {
    $rawPayload = '{}';
}

try {
    $existingStmt = $pdo->prepare('SELECT user_id FROM paypal_payments WHERE paypal_order_id = :order_id LIMIT 1');
    $existingStmt->execute([':order_id' => $orderId]);
    $existingUserId = $existingStmt->fetchColumn();

    if ($existingUserId !== false && (int) $existingUserId !== (int) $user['id']) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'order_already_claimed']);
        exit;
    }

    $stmt = $pdo->prepare('
        INSERT INTO paypal_payments (user_id, package_id, paypal_order_id, amount, currency, credits, status, raw_payload)
        VALUES (:user_id, :package_id, :order_id, :amount, :currency, :credits, :status, :payload)
        ON DUPLICATE KEY UPDATE
            package_id = VALUES(package_id),
            amount = VALUES(amount),
            currency = VALUES(currency),
            credits = VALUES(credits),
            status = VALUES(status),
            raw_payload = VALUES(raw_payload),
            updated_at = CURRENT_TIMESTAMP
    ');

    $stmt->execute([
        ':user_id'  => (int) $user['id'],
        ':package_id' => $packageId,
        ':order_id' => $orderId,
        ':amount'   => $amount,
        ':currency' => $currency,
        ':credits'  => $credits,
        ':status'   => 'created',
        ':payload'  => $rawPayload,
    ]);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'database_error']);
    exit;
}

echo json_encode([
    'ok'      => true,
    'orderID' => $orderId,
]);
