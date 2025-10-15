<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

if (auth_is_logged_in()) {
    auth_redirect('/');
}

$errors = [];
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $token = $_POST['_token'] ?? null;

    if (!auth_validate_csrf(is_string($token) ? $token : null)) {
        $errors[] = 'Ungültiges Formular-Token. Bitte versuchen Sie es erneut.';
    }

    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $errors[] = 'Bitte geben Sie eine gültige E-Mail-Adresse ein.';
    }

    if ($password === '') {
        $errors[] = 'Bitte geben Sie Ihr Passwort ein.';
    }

    if ($errors === []) {
        $pdo = auth_pdo();
        $statement = $pdo->prepare('SELECT id, name, email, password_hash, verified FROM users WHERE email = :email LIMIT 1');
        $statement->execute(['email' => $email]);
        $user = $statement->fetch();

        if (!$user || !auth_verify_password($password, (string) ($user['password_hash'] ?? ''))) {
            $errors[] = 'Anmeldedaten ungültig.';
        } elseif ((int) ($user['verified'] ?? 0) !== 1) {
            $errors[] = 'Bitte bestätigen Sie zuerst Ihre E-Mail-Adresse.';
        } else {
            session_regenerate_id(true);
            auth_store_user($user);
            auth_flash('success', 'Erfolgreich angemeldet.');
            auth_redirect('/');
        }
    }
}

$flashes = auth_get_flashes();

function renderMessages(array $flashes, array $errors): void
{
    $allMessages = [];

    foreach ($flashes as $flash) {
        $allMessages[] = [
            'type'    => $flash['type'] ?? 'info',
            'message' => $flash['message'] ?? '',
        ];
    }

    foreach ($errors as $error) {
        $allMessages[] = [
            'type'    => 'error',
            'message' => $error,
        ];
    }

    if ($allMessages === []) {
        return;
    }

    echo '<div class="auth-messages" aria-live="polite">';
    foreach ($allMessages as $message) {
        $type = htmlspecialchars((string) $message['type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $text = htmlspecialchars((string) $message['message'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        echo '<div class="auth-message auth-message--' . $type . '">' . $text . '</div>';
    }
    echo '</div>';
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Anmelden - Artikelverwaltung</title>
    <link rel="stylesheet" href="../style.css">
</head>
<body>
    <div class="auth-card">
        <header class="auth-card__header">
            <h1>Willkommen zurück</h1>
            <p>Melden Sie sich an, um Ihre Artikel zu verwalten.</p>
        </header>
        <?php renderMessages($flashes, $errors); ?>
        <form method="post" class="auth-form" novalidate>
            <input type="hidden" name="_token" value="<?php echo htmlspecialchars(auth_csrf_token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
            <div class="form-group">
                <label for="email">E-Mail-Adresse</label>
                <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" autocomplete="email" required>
            </div>
            <div class="form-group">
                <label for="password">Passwort</label>
                <input type="password" id="password" name="password" autocomplete="current-password" required>
            </div>
            <div class="form-actions form-actions--stacked">
                <button type="submit" class="btn btn--primary">Anmelden</button>
            </div>
        </form>
        <p class="auth-card__footer">
            <a href="forgot_password.php">Passwort vergessen?</a>
            <span> &bull; </span>
            <a href="register.php">Jetzt registrieren</a>
        </p>
    </div>
</body>
</html>
