<?php
declare(strict_types=1);

if (!isset($pdo) || !($pdo instanceof PDO)) {
    $pdo = auth_pdo();
}

if (!isset($currentUser['id'])) {
    auth_redirect('/auth/login.php');
}

$userId = (int) $currentUser['id'];

$errors = [];
$successMessage = null;

$name = '';
$email = '';

$statement = $pdo->prepare('SELECT id, name, email FROM users WHERE id = :id LIMIT 1');
$statement->execute(['id' => $userId]);
$userRecord = $statement->fetch(PDO::FETCH_ASSOC);

if (!$userRecord) {
    auth_logout();
    auth_redirect('/auth/login.php');
}

$name = (string) ($userRecord['name'] ?? '');
$email = (string) ($userRecord['email'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['_token'] ?? null;

    if (!auth_validate_csrf(is_string($token) ? $token : null)) {
        $errors[] = 'Ungültiges Formular-Token. Bitte versuchen Sie es erneut.';
    }

    $name = trim((string) ($_POST['name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['new_password'] ?? '');
    $passwordRepeat = (string) ($_POST['new_password_repeat'] ?? '');

    if ($name === '') {
        $errors[] = 'Bitte geben Sie Ihren Namen ein.';
    }

    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $errors[] = 'Bitte geben Sie eine gültige E-Mail-Adresse ein.';
    }

    if ($email !== '') {
        $duplicateCheck = $pdo->prepare('SELECT id FROM users WHERE email = :email AND id <> :id LIMIT 1');
        $duplicateCheck->execute([
            'email' => $email,
            'id'    => $userId,
        ]);

        if ($duplicateCheck->fetch()) {
            $errors[] = 'Diese E-Mail-Adresse wird bereits verwendet.';
        }
    }

    $updatePassword = false;
    $passwordHash = null;

    if ($password !== '' || $passwordRepeat !== '') {
        if ($password === '' || $passwordRepeat === '') {
            $errors[] = 'Bitte füllen Sie beide Passwortfelder aus, um ein neues Passwort zu setzen.';
        } elseif ($password !== $passwordRepeat) {
            $errors[] = 'Die neuen Passwörter stimmen nicht überein.';
        } else {
            $passwordHash = auth_hash_password($password);
            $updatePassword = true;
        }
    }

    if ($errors === []) {
        $sql = 'UPDATE users SET name = :name, email = :email';
        $params = [
            'name' => $name,
            'email' => $email,
            'id' => $userId,
        ];

        if ($updatePassword && is_string($passwordHash)) {
            $sql .= ', password_hash = :password_hash';
            $params['password_hash'] = $passwordHash;
        }

        $sql .= ', updated_at = NOW() WHERE id = :id';

        $update = $pdo->prepare($sql);
        $update->execute($params);

        $statement = $pdo->prepare('SELECT id, name, email FROM users WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $userId]);
        $freshUser = $statement->fetch(PDO::FETCH_ASSOC);

        if ($freshUser) {
            auth_store_user($freshUser);
            $name = (string) ($freshUser['name'] ?? $name);
            $email = (string) ($freshUser['email'] ?? $email);
        }

        $successMessage = 'Profil gespeichert.';
    }
}
?>
<div class="settings-card">
    <h2>Profil</h2>
    <?php if ($successMessage !== null): ?>
    <div class="settings-message" role="status"><?php echo htmlspecialchars($successMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
    <?php endif; ?>
    <?php if ($errors !== []): ?>
    <div class="app__messages" aria-live="polite">
        <?php foreach ($errors as $error): ?>
        <div class="app__message app__message--error"><?php echo htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <form method="post" class="settings-form" novalidate>
        <input type="hidden" name="_token" value="<?php echo htmlspecialchars(auth_csrf_token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
        <div class="form-group">
            <label for="profile-name">Name</label>
            <input type="text" id="profile-name" name="name" value="<?php echo htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" required>
        </div>
        <div class="form-group">
            <label for="profile-email">E-Mail-Adresse</label>
            <input type="email" id="profile-email" name="email" value="<?php echo htmlspecialchars($email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" autocomplete="email" required>
        </div>
        <div class="form-group">
            <label for="profile-password">Neues Passwort</label>
            <input type="password" id="profile-password" name="new_password" autocomplete="new-password">
        </div>
        <div class="form-group">
            <label for="profile-password-repeat">Neues Passwort wiederholen</label>
            <input type="password" id="profile-password-repeat" name="new_password_repeat" autocomplete="new-password">
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn--primary">Speichern</button>
        </div>
    </form>
</div>
