<?php
declare(strict_types=1);

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
                $requestHeaders[] = 'Content-Type: application/json';
            } else {
                $payload = http_build_query($body);
            }
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

        if ($errno) {
            throw new RuntimeException('PayPal API Fehler: ' . $errno);
        }

        $data = json_decode((string) $response, true);

        if ($status >= 400) {
            $message = is_array($data) && isset($data['message']) ? (string) $data['message'] : 'Unbekannter Fehler';
            throw new RuntimeException('PayPal API HTTP ' . $status . ': ' . $message);
        }

        if (!is_array($data)) {
            throw new RuntimeException('PayPal API Antwort ungültig.');
        }

        return $data;
    }

    public function createOrder(string $accessToken, float $amount, string $currency, string $description, string $customId): array
    {
        $headers = [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
        ];

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

        $path = '/v2/checkout/orders/' . urlencode($orderId) . '/capture';

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
