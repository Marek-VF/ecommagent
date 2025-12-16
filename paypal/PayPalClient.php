<?php
declare(strict_types=1);

class PayPalApiException extends RuntimeException
{
    private ?array $responseData;
    private string $rawResponse;
    private int $statusCode;

    public function __construct(string $message, int $statusCode, ?Throwable $previous, ?array $responseData, string $rawResponse)
    {
        parent::__construct($message, $statusCode, $previous);
        $this->statusCode = $statusCode;
        $this->responseData = $responseData;
        $this->rawResponse = $rawResponse;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getResponseData(): ?array
    {
        return $this->responseData;
    }

    public function getRawResponse(): string
    {
        return $this->rawResponse;
    }

    public function getDebugId(): ?string
    {
        return isset($this->responseData['debug_id']) ? (string) $this->responseData['debug_id'] : null;
    }

    /**
     * @return array<int, mixed>
     */
    public function getDetails(): array
    {
        $details = $this->responseData['details'] ?? [];
        return is_array($details) ? $details : [];
    }
}

class PayPalClient
{
    private string $clientId;
    private string $clientSecret;
    private string $environment;
    private string $baseUrl;

    public function __construct(array $config)
    {
        $this->clientId = trim((string) ($config['client_id'] ?? ''));
        $this->clientSecret = trim((string) ($config['client_secret'] ?? ''));
        $this->environment = strtolower(trim((string) ($config['environment'] ?? 'sandbox')));

        if ($this->clientId === '' || $this->clientSecret === '') {
            throw new InvalidArgumentException('PayPal-Zugangsdaten fehlen.');
        }

        $this->baseUrl = $this->environment === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }

    public function getAccessToken(): string
    {
        $ch = curl_init($this->baseUrl . '/v1/oauth2/token');

        $headers = [
            'Accept: application/json',
            'Accept-Language: en_US',
        ];

        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => $this->clientId . ':' . $this->clientSecret,
            CURLOPT_POSTFIELDS     => http_build_query(['grant_type' => 'client_credentials']),
            CURLOPT_TIMEOUT        => 20,
        ]);

        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($errno) {
            throw new RuntimeException('PayPal OAuth Fehler: ' . $errno);
        }

        $data = json_decode((string) $response, true);
        if (!is_array($data) || !isset($data['access_token'])) {
            throw new RuntimeException('PayPal OAuth Antwort ungültig.');
        }

        return (string) $data['access_token'];
    }

    /**
     * @param array<string, string> $headers
     * @param array<mixed>|null     $body
     */
    private function sendRequest(string $method, string $path, array $headers, ?array $body, bool $jsonBody = true): array
    {
        $url = $this->baseUrl . $path;
        $ch = curl_init($url);

        $requestHeaders = $headers;
        $payload = null;

        if ($body !== null) {
            if ($jsonBody) {
                $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                if (!is_string($payload)) {
                    $jsonError = json_last_error_msg();
                    throw new RuntimeException('PayPal API Fehler: Request-Body konnte nicht serialisiert werden: ' . $jsonError);
                }

                $requestHeaders[] = 'Content-Type: application/json';
            } else {
                $payload = http_build_query($body);
            }
        } elseif (strtoupper($method) === 'POST') {
            $requestHeaders[] = 'Content-Type: application/json';
            $requestHeaders[] = 'Content-Length: 0';
            $payload = '';
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $requestHeaders,
            CURLOPT_TIMEOUT        => 25,
        ]);

        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $rawResponse = (string) $response;

        if ($errno) {
            throw new RuntimeException('PayPal API Fehler: ' . $errno);
        }

        $data = json_decode($rawResponse, true);

        if ($status >= 400) {
            $messageParts = ['PayPal API HTTP ' . $status];

            if (is_array($data)) {
                if (isset($data['name'])) {
                    $messageParts[] = 'name=' . (string) $data['name'];
                }

                if (isset($data['message'])) {
                    $messageParts[] = 'message=' . (string) $data['message'];
                }

                $messageParts[] = 'debug_id=' . (string) ($data['debug_id'] ?? '');

                if (isset($data['details'])) {
                    $messageParts[] = 'details=' . json_encode($data['details'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
            }

            $messageParts[] = 'raw=' . $rawResponse;

            throw new PayPalApiException(implode(' | ', $messageParts), $status, null, is_array($data) ? $data : null, $rawResponse);
        }

        if (!is_array($data)) {
            throw new RuntimeException('PayPal API Antwort ungültig: ' . $rawResponse);
        }

        return $data;
    }

    public function createOrder(string $accessToken, float $amount, string $currency, string $description, string $customId): array
    {
        $headers = [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
            'Prefer: return=representation',
        ];

        $requestId = bin2hex(random_bytes(16));
        $headers[] = 'PayPal-Request-Id: ' . $requestId;

        $body = [
            'intent' => 'CAPTURE',
            'purchase_units' => [
                [
                    'custom_id'   => $customId,
                    'description' => $description,
                    'amount'      => [
                        'currency_code' => $currency,
                        'value'         => number_format($amount, 2, '.', ''),
                    ],
                ],
            ],
            'application_context' => [
                'shipping_preference' => 'NO_SHIPPING',
            ],
        ];

        return $this->sendRequest('POST', '/v2/checkout/orders', $headers, $body);
    }

    public function captureOrder(string $accessToken, string $orderId): array
    {
        $headers = [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
        ];

        $path = '/v2/checkout/orders/' . rawurlencode($orderId) . '/capture';

        $headers[] = 'PayPal-Request-Id: ' . bin2hex(random_bytes(16));

        return $this->sendRequest('POST', $path, $headers, null);
    }

    /**
     * @param array<string, string> $headers
     */
    public function verifyWebhookSignature(array $headers, string $body, string $webhookId): bool
    {
        $required = [
            'PAYPAL-TRANSMISSION-ID',
            'PAYPAL-TRANSMISSION-TIME',
            'PAYPAL-TRANSMISSION-SIG',
            'PAYPAL-CERT-URL',
            'PAYPAL-AUTH-ALGO',
        ];

        foreach ($required as $header) {
            if (!isset($headers[$header])) {
                return false;
            }
        }

        $accessToken = $this->getAccessToken();

        $verificationBody = [
            'transmission_id'    => $headers['PAYPAL-TRANSMISSION-ID'],
            'transmission_time'  => $headers['PAYPAL-TRANSMISSION-TIME'],
            'cert_url'           => $headers['PAYPAL-CERT-URL'],
            'auth_algo'          => $headers['PAYPAL-AUTH-ALGO'],
            'transmission_sig'   => $headers['PAYPAL-TRANSMISSION-SIG'],
            'webhook_id'         => $webhookId,
            'webhook_event'      => json_decode($body, true),
        ];

        $headers = [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
        ];

        $response = $this->sendRequest('POST', '/v1/notifications/verify-webhook-signature', $headers, $verificationBody);

        return ($response['verification_status'] ?? '') === 'SUCCESS';
    }
}
