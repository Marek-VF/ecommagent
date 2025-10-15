<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$token = isset($_GET['token']) ? trim((string) $_GET['token']) : '';

if ($token === '') {
    auth_flash('error', 'Ungültiger oder fehlender Verifizierungslink.');
    auth_redirect('/auth/login.php');
}

$pdo = auth_pdo();
$statement = $pdo->prepare('SELECT id, verified FROM users WHERE verification_token = :token LIMIT 1');
$statement->execute(['token' => $token]);
$user = $statement->fetch();

if (!$user) {
    auth_flash('error', 'Der Verifizierungslink ist ungültig oder wurde bereits verwendet.');
    auth_redirect('/auth/login.php');
}

if ((int) ($user['verified'] ?? 0) === 1) {
    auth_flash('info', 'Ihr Konto ist bereits bestätigt. Bitte melden Sie sich an.');
    auth_redirect('/auth/login.php');
}

$update = $pdo->prepare('UPDATE users SET verified = 1, verification_token = NULL, updated_at = NOW() WHERE id = :id');
$update->execute(['id' => $user['id']]);

auth_flash('success', 'Ihre E-Mail-Adresse wurde bestätigt. Sie können sich jetzt anmelden.');
auth_redirect('/auth/login.php');
