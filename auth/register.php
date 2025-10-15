<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/mail.php';

use function AuthModule\sendMail;

if (auth_is_logged_in()) {
    auth_redirect('/');
}

$errors = [];
$name = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string) ($_POST['name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $token = $_POST['_token'] ?? null;

    if (!auth_validate_csrf(is_string($token) ? $token : null)) {
        $errors[] = 'Ungültiges Formular-Token. Bitte versuchen Sie es erneut.';
    }

    if ($name === '') {
        $errors[] = 'Bitte geben Sie Ihren Namen ein.';
    }

    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $errors[] = 'Bitte geben Sie eine gültige E-Mail-Adresse ein.';
    }

    $passwordPattern = '/^(?=.*\d)(?=.*[^\w\s]).{8,}$/u';
    if ($password === '' || !preg_match($passwordPattern, $password)) {
        $errors[] = 'Das Passwort muss mindestens 8 Zeichen lang sein und sowohl eine Zahl als auch ein Sonderzeichen enthalten.';
    }

    if ($errors === []) {
        $pdo = auth_pdo();

        $statement = $pdo->prepare('SELECT id, verified FROM users WHERE email = :email LIMIT 1');
        $statement->execute(['email' => $email]);
        $existing = $statement->fetch();

        if ($existing) {
            if ((int) ($existing['verified'] ?? 0) === 1) {
                $errors[] = 'Für diese E-Mail-Adresse besteht bereits ein verifiziertes Konto.';
            } else {
                $errors[] = 'Für diese E-Mail-Adresse wurde bereits ein Konto angelegt. Bitte prüfen Sie Ihre E-Mails.';
            }
        }
    }

    if ($errors === []) {
        $verificationToken = auth_generate_token();
        $passwordHash = auth_hash_password($password);

        $insert = $pdo->prepare(
            'INSERT INTO users (name, email, password_hash, verification_token, verified, created_at, updated_at)
             VALUES (:name, :email, :password_hash, :verification_token, 0, NOW(), NOW())'
        );

        $insert->execute([
            'name'               => $name,
            'email'              => $email,
            'password_hash'      => $passwordHash,
            'verification_token' => $verificationToken,
        ]);

        $verificationLink = auth_url('/auth/verify.php?token=' . urlencode($verificationToken));

        $htmlBody = '<p>Hallo ' . htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ',</p>' .
            '<p>bitte bestätigen Sie Ihre Registrierung, indem Sie auf den folgenden Link klicken:</p>' .
            '<p><a href="' . htmlspecialchars($verificationLink, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">Registrierung bestätigen</a></p>' .
            '<p>Falls Sie sich nicht registriert haben, können Sie diese E-Mail ignorieren.</p>';

        $textBody = "Hallo {$name},\n\n" .
            "bitte bestätigen Sie Ihre Registrierung über den folgenden Link:\n" .
            $verificationLink . "\n\n" .
            'Falls Sie sich nicht registriert haben, können Sie diese E-Mail ignorieren.';

        $mailSent = sendMail($email, $name, 'Registrierung bestätigen', $htmlBody, $textBody);

        if (!$mailSent) {
            auth_flash('warning', 'Registrierung erstellt, aber der Versand der Bestätigungs-E-Mail ist fehlgeschlagen. Kontaktieren Sie den Support.');
        } else {
            auth_flash('success', 'Registrierung erfolgreich. Bitte bestätigen Sie Ihre E-Mail-Adresse.');
        }

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
    <title>Registrieren - Artikelverwaltung</title>
    <link rel="stylesheet" href="../style.css">
</head>
<body>
    <div class="auth-card">
        <header class="auth-card__header">
            <h1>Konto erstellen</h1>
            <p>Verwalten Sie Ihre Artikel nach der schnellen Registrierung.</p>
        </header>
        <?php renderMessages($flashes, $errors); ?>
        <form method="post" class="auth-form" novalidate>
            <input type="hidden" name="_token" value="<?php echo htmlspecialchars(auth_csrf_token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
            <div class="form-group">
                <label for="name">Name</label>
                <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" required>
            </div>
            <div class="form-group">
                <label for="email">E-Mail-Adresse</label>
                <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" autocomplete="email" required>
            </div>
            <div class="form-group">
                <label for="password">Passwort</label>
                <input type="password" id="password" name="password" minlength="8" required aria-describedby="password-help">
                <small id="password-help" class="input-help">Mindestens 8 Zeichen, eine Zahl und ein Sonderzeichen.</small>
            </div>
            <div class="form-actions form-actions--stacked">
                <button type="submit" class="btn btn--primary">Registrieren</button>
            </div>
        </form>
        <p class="auth-card__footer">Bereits registriert? <a href="login.php">Hier anmelden</a>.</p>
    </div>
</body>
</html>
