<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/mail.php';

use function AuthModule\sendMail;

if (auth_is_logged_in()) {
    auth_redirect('/');
}

$errors = [];
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $token = $_POST['_token'] ?? null;

    if (!auth_validate_csrf(is_string($token) ? $token : null)) {
        $errors[] = 'Ungültiges Formular-Token. Bitte versuchen Sie es erneut.';
    }

    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $errors[] = 'Bitte geben Sie eine gültige E-Mail-Adresse ein.';
    }

    if ($errors === []) {
        $pdo = auth_pdo();
        $statement = $pdo->prepare('SELECT id, name, verified FROM users WHERE email = :email LIMIT 1');
        $statement->execute(['email' => $email]);
        $user = $statement->fetch();

        if ($user && (int) ($user['verified'] ?? 0) === 1) {
            $resetToken = auth_generate_token();
            $update = $pdo->prepare('UPDATE users SET reset_token = :token, updated_at = NOW() WHERE id = :id');
            $update->execute([
                'token' => $resetToken,
                'id'    => $user['id'],
            ]);

            $resetLink = auth_url('/auth/reset_password.php?token=' . urlencode($resetToken));

            $name = (string) ($user['name'] ?? '');
            $greeting = $name !== ''
                ? 'Hallo ' . htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ','
                : 'Hallo,';
            $htmlBody = '<p>' . $greeting . '</p>' .
                '<p>Sie haben ein neues Passwort angefordert. Nutzen Sie den folgenden Link, um ein neues Passwort zu vergeben:</p>' .
                '<p><a href="' . htmlspecialchars($resetLink, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">Passwort jetzt zurücksetzen</a></p>' .
                '<p>Falls Sie diese Anfrage nicht gestellt haben, ignorieren Sie diese E-Mail.</p>';

            $textGreeting = $name !== '' ? "Hallo {$name}," : 'Hallo,';
            $textBody = $textGreeting . "\n\n" .
                "Sie haben ein neues Passwort angefordert. Nutzen Sie den folgenden Link, um ein neues Passwort zu vergeben:\n" .
                $resetLink . "\n\n" .
                'Falls Sie diese Anfrage nicht gestellt haben, ignorieren Sie diese E-Mail.';

            sendMail($email, $name, 'Passwort zurücksetzen', $htmlBody, $textBody);
        }

        auth_flash('info', 'Wenn diese E-Mail-Adresse bei uns registriert ist, erhalten Sie gleich eine Nachricht zum Zurücksetzen des Passworts.');
        auth_redirect('/auth/login.php');
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
    <title>Passwort vergessen - Artikelverwaltung</title>
    <link rel="stylesheet" href="../style.css">
</head>
<body>
    <div class="auth-card">
        <header class="auth-card__header">
            <h1>Passwort vergessen</h1>
            <p>Wir senden Ihnen einen Link zum Zurücksetzen.</p>
        </header>
        <?php renderMessages($flashes, $errors); ?>
        <form method="post" class="auth-form" novalidate>
            <input type="hidden" name="_token" value="<?php echo htmlspecialchars(auth_csrf_token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
            <div class="form-group">
                <label for="email">E-Mail-Adresse</label>
                <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" autocomplete="email" required>
            </div>
            <div class="form-actions form-actions--stacked">
                <button type="submit" class="btn btn--primary">Link anfordern</button>
            </div>
        </form>
        <p class="auth-card__footer"><a href="login.php">Zurück zum Login</a></p>
    </div>
</body>
</html>
