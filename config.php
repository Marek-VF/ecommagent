<?php
$baseUrl = 'https://vielfalter.digital/api-monday/ecommagent';

return [
    'base_url'         => $baseUrl,
    'asset_base_url'   => $baseUrl . '/assets',
    'upload_dir'       => __DIR__ . '/uploads/',
    'data_file'        => __DIR__ . '/data.json',
    'workflow_webhook' => 'https://tex305agency.app.n8n.cloud/webhook-test/9a217ab8-47fa-452c-9c65-fa7874a14fdd',
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
];
