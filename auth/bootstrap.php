<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? null) == 443;
    session_set_cookie_params([
        'httponly' => true,
        'secure'   => $secure,
        'samesite' => 'Lax',
        'path'     => '/',
    ]);
    session_start();
}

function auth_config(): array
{
    static $config;

    if ($config === null) {
        $config = require __DIR__ . '/../config.php';
    }

    return $config;
}

function auth_base_url(): string
{
    $config = auth_config();
    $baseUrl = $config['base_url'] ?? '';

    if (!is_string($baseUrl)) {
        $baseUrl = '';
    }

    return rtrim($baseUrl, '/');
}

function auth_url(string $path): string
{
    $baseUrl = auth_base_url();
    $normalizedPath = '/' . ltrim($path, '/');

    return $baseUrl !== '' ? $baseUrl . $normalizedPath : $normalizedPath;
}

function auth_csrf_token(): string
{
    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['_csrf_token'];
}

function auth_validate_csrf(?string $token): bool
{
    if (!is_string($token) || $token === '') {
        return false;
    }

    return hash_equals(auth_csrf_token(), $token);
}

function auth_flash(string $type, string $message): void
{
    $_SESSION['flash_messages'][] = [
        'type'    => $type,
        'message' => $message,
    ];
}

function auth_get_flashes(): array
{
    $messages = $_SESSION['flash_messages'] ?? [];
    unset($_SESSION['flash_messages']);

    return array_map(static function (array $message): array {
        return [
            'type'    => $message['type'] ?? 'info',
            'message' => $message['message'] ?? '',
        ];
    }, $messages);
}

function auth_redirect(string $path): never
{
    header('Location: ' . auth_url($path));
    exit;
}

function auth_pdo(): PDO
{
    return getPDO();
}

function auth_is_logged_in(): bool
{
    return isset($_SESSION['user']) && is_array($_SESSION['user']);
}

function auth_user(): ?array
{
    return auth_is_logged_in() ? $_SESSION['user'] : null;
}

function auth_store_user(array $user): void
{
    $_SESSION['user'] = [
        'id'    => $user['id'] ?? null,
        'name'  => $user['name'] ?? null,
        'email' => $user['email'] ?? null,
    ];
}

function auth_logout(): void
{
    unset($_SESSION['user']);
    session_regenerate_id(true);
}

function auth_require_login(string $redirectPath = '/auth/login.php'): void
{
    if (!auth_is_logged_in()) {
        auth_flash('warning', 'Bitte melden Sie sich an, um fortzufahren.');
        auth_redirect($redirectPath);
    }
}

function auth_hash_password(string $password): string
{
    return password_hash($password, PASSWORD_DEFAULT);
}

function auth_verify_password(string $password, string $hash): bool
{
    return password_verify($password, $hash);
}

function auth_generate_token(): string
{
    return bin2hex(random_bytes(32));
}
