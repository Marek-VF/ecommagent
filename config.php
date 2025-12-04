<?php
$baseUrl = 'https://vielfalter.digital/api-monday/ecommagent';
$webhookBearerToken = 'changeme';
return [
    'base_url'         => $baseUrl,
    'asset_base_url'   => $baseUrl . '/assets',
    'upload_dir'       => __DIR__ . '/uploads',
    'workflow_webhook' => 'https://tex305agency.app.n8n.cloud/webhook-test/9a217ab8-47fa-452c-9c65-fa7874a14fdd',
    'workflow_webhook_update' => 'https://vielfalter.app.n8n.cloud/webhook-test/ddce0e4a-58b8-4284-adeb-6ae73ebda070',
    
    'receiver_api_token'      => $webhookBearerToken,
    'receiver_api_allowed_ips' => [],
    'db'               => [
        'dsn'      => 'mysql:host=localhost;dbname=ecommagent;charset=utf8mb4',
        'username' => 'root',
        'password' => '',
        'options'  => [],
    ],
    'smtp'             => [
        'host'       => 'smtp.example.com',
        'port'       => 587,
        'username'   => 'smtp-user',
        'password'   => 'smtp-password',
        'encryption' => 'tls',
        'auth'       => true,
    ],
    'mail'             => [
        'from_address' => 'no-reply@example.com',
        'from_name'    => 'Artikelverwaltung',
    ],
    'credits'          => [
        'prices' => [
            // Beispielpreise – können später im Projekt angepasst werden
            'analysis' => 0.50,
            'image_1'  => 0.75,
            'image_2'  => 0.75,
            'image_3'  => 0.75,
            // weitere step_type-Werte können später ergänzt werden, z.B. 'text', 'product_photo', ...
        ],
    ],
];
