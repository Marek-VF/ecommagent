<?php
$baseUrl = 'https://vielfalter.digital/api-monday/ecommagent';
$webhookBearerToken = 'changeme';
return [
    'base_url'         => $baseUrl,
    'asset_base_url'   => $baseUrl . '/assets',
    'upload_dir'       => __DIR__ . '/uploads',
    'workflow_webhook' => 'https://tex305agency.app.n8n.cloud/webhook-test/9a217ab8-47fa-452c-9c65-fa7874a14fdd',
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
    'credits' => [
        'prices' => [
            // --- GRUPPE 1: Haupt-Workflow (Initiale Generierung) ---
            // Diese Kosten werden beim Klick auf "Start Workflow" zusammengerechnet.
            'analysis' => ['price' => 0.50, 'group_id' => 1],
            'image_1'  => ['price' => 0.75, 'group_id' => 1],
            'image_2'  => ['price' => 0.75, 'group_id' => 1],
            'image_3'  => ['price' => 0.75, 'group_id' => 1],

            // --- GRUPPE 2: Updates & Add-ons ---
            // Diese Kosten fallen erst an, wenn der User später auf 2K/4K/Edit klickt.
            '2K'   => ['price' => 1.00, 'group_id' => 2],
            '4K'   => ['price' => 1.00, 'group_id' => 2],
            'edit' => ['price' => 1.00, 'group_id' => 2],
        ],
    ],
];
