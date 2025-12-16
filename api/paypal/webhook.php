<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../credits.php';
require_once __DIR__ . '/../../paypal/PayPalClient.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$config = require __DIR__ . '/../../config.php';
$paypalConfig = $config['paypal'] ?? [];
$rawBody = (string) file_get_contents('php://input');
$payload = json_decode($rawBody, true);

if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_payload']);
    exit;
}

$headers = [];
if (function_exists('getallheaders')) {
    foreach (getallheaders() as $name => $value) {
        $headers[strtoupper((string) $name)] = (string) $value;
    }
}

$webhookId = isset($paypalConfig['webhook_id']) ? (string) $paypalConfig['webhook_id'] : '';
$isVerified = true;

if ($webhookId !== '') {
    try {
        $client = new PayPalClient($paypalConfig);
        $isVerified = $client->verifyWebhookSignature($headers, $rawBody, $webhookId);
    } catch (Throwable $exception) {
        $isVerified = false;
    }
}

if (!$isVerified) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'verification_failed']);
    exit;
}

$eventType = isset($payload['event_type']) ? (string) $payload['event_type'] : '';
$resource = isset($payload['resource']) && is_array($payload['resource']) ? $payload['resource'] : [];

$orderId = '';
$captureId = '';
$status = isset($resource['status']) ? (string) $resource['status'] : '';

if (in_array($eventType, ['CHECKOUT.ORDER.APPROVED', 'CHECKOUT.ORDER.COMPLETED'], true)) {
    $orderId = isset($resource['id']) ? (string) $resource['id'] : '';
}

if ($eventType === 'PAYMENT.CAPTURE.COMPLETED') {
    $captureId = isset($resource['id']) ? (string) $resource['id'] : '';
    $orderId = isset($resource['supplementary_data']['related_ids']['order_id'])
        ? (string) $resource['supplementary_data']['related_ids']['order_id']
        : '';
}

if ($orderId === '') {
    echo json_encode(['ok' => true, 'ignored' => true]);
    exit;
}

$pdo = getPDO();

try {
    if ($eventType === 'PAYMENT.CAPTURE.COMPLETED' && strtoupper($status) === 'COMPLETED') {
        $pdo->beginTransaction();

        $paymentStmt = $pdo->prepare('SELECT * FROM paypal_payments WHERE paypal_order_id = :order_id FOR UPDATE');
        $paymentStmt->execute([':order_id' => $orderId]);
        $payment = $paymentStmt->fetch(PDO::FETCH_ASSOC);

        if (!$payment) {
            $pdo->commit();
            echo json_encode(['ok' => true, 'message' => 'order_not_tracked']);
            exit;
        }

        if (($payment['status'] ?? '') === 'completed') {
            $pdo->commit();
            echo json_encode(['ok' => true, 'alreadyCompleted' => true]);
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
            (int) $payment['user_id'],
            $creditsToAdd,
            'paypal_purchase',
            [
                'package_id'        => $payment['package_id'] ?? null,
                'paypal_order_id'   => $orderId,
                'paypal_capture_id' => $captureId,
                'webhook_event'     => $eventType,
            ]
        );

        $updateStmt = $pdo->prepare('UPDATE paypal_payments SET paypal_capture_id = :capture_id, status = :status, credit_transaction_id = :transaction_id, raw_payload = :payload WHERE id = :id');
        $updateStmt->execute([
            ':capture_id'       => $captureId,
            ':status'           => 'completed',
            ':transaction_id'   => $transactionId ?: null,
            ':payload'          => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':id'               => (int) $payment['id'],
        ]);

        $pdo->commit();
    } elseif ($eventType === 'CHECKOUT.ORDER.APPROVED') {
        $stmt = $pdo->prepare('UPDATE paypal_payments SET status = :status, raw_payload = :payload WHERE paypal_order_id = :order_id');
        $stmt->execute([
            ':status'   => 'approved',
            ':payload'  => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':order_id' => $orderId,
        ]);
    }

    echo json_encode(['ok' => true]);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'webhook_failed']);
}
