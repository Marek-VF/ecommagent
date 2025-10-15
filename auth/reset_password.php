<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

if (auth_is_logged_in()) {
    auth_redirect('/');
}

$token = isset($_GET['token']) ? trim((string) $_GET['token']) : '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = isset($_POST['reset_token']) ? trim((string) $_POST['reset_token']) : $token;
}

if ($token === '') {
    auth_flash('error', 'Der Link zum Zurücksetzen des Passworts ist ungültig.');
    auth_redirect('/auth/login.php');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tokenValue = $token;
    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');
    $csrf = $_POST['_token'] ?? null;

    if (!auth_validate_csrf(is_string($csrf) ? $csrf : null)) {
        $errors[] = 'Ungültiges Formular-Token. Bitte versuchen Sie es erneut.';
    }

    $passwordPattern = '/^(?=.*\d)(?=.*[^\w\s]).{8,}$/u';
    if ($password === '' || !preg_match($passwordPattern, $password)) {
        $errors[] = 'Das neue Passwort muss mindestens 8 Zeichen lang sein und sowohl eine Zahl als auch ein Sonderzeichen enthalten.';
    }

    if ($password !== $passwordConfirm) {
        $errors[] = 'Die eingegebenen Passwörter stimmen nicht überein.';
    }

    if ($errors === []) {
        $pdo = auth_pdo();
        $statement = $pdo->prepare('SELECT id FROM users WHERE reset_token = :token LIMIT 1');
        $statement->execute(['token' => $tokenValue]);
        $user = $statement->fetch();

        if (!$user) {
            $errors[] = 'Der Link zum Zurücksetzen ist ungültig oder wurde bereits verwendet.';
        } else {
            $update = $pdo->prepare('UPDATE users SET password_hash = :password, reset_token = NULL, updated_at = NOW() WHERE id = :id');
            $update->execute([
                'password' => auth_hash_password($password),
                'id'       => $user['id'],
            ]);

            auth_flash('success', 'Ihr Passwort wurde aktualisiert. Bitte melden Sie sich mit dem neuen Passwort an.');
            auth_redirect('/auth/login.php');
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
    <title>Neues Passwort festlegen - Artikelverwaltung</title>
    <link rel="stylesheet" href="../style.css">
</head>
<body>
    <div class="auth-card">
        <header class="auth-card__header">
            <h1>Neues Passwort festlegen</h1>
            <p>Bitte wählen Sie ein sicheres Passwort.</p>
        </header>
        <?php renderMessages($flashes, $errors); ?>
        <form method="post" class="auth-form" novalidate>
            <input type="hidden" name="_token" value="<?php echo htmlspecialchars(auth_csrf_token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
            <input type="hidden" name="reset_token" value="<?php echo htmlspecialchars($token, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
            <div class="form-group">
                <label for="password">Neues Passwort</label>
                <input type="password" id="password" name="password" minlength="8" required aria-describedby="password-info">
                <small id="password-info" class="input-help">Mindestens 8 Zeichen, eine Zahl und ein Sonderzeichen.</small>
            </div>
            <div class="form-group">
                <label for="password_confirm">Passwort bestätigen</label>
                <input type="password" id="password_confirm" name="password_confirm" minlength="8" required>
            </div>
            <div class="form-actions form-actions--stacked">
                <button type="submit" class="btn btn--primary">Passwort speichern</button>
            </div>
        </form>
        <p class="auth-card__footer"><a href="login.php">Zurück zum Login</a></p>
    </div>
</body>
</html>
