<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth/bootstrap.php';
require_once __DIR__ . '/../db.php';

auth_require_login();

$pdo = auth_pdo();
$currentUser = auth_user();

if ($currentUser === null || !isset($currentUser['id'])) {
    auth_logout();
    auth_redirect('/auth/login.php');
}

$currentUserId = (int) $currentUser['id'];

$categories = db_get_prompt_categories($pdo);
$categoryOptions = [];

foreach ($categories as $category) {
    $categoryOptions[(int) $category['id']] = [
        'key'   => $category['category_key'],
        'label' => $category['label'],
    ];
}

$currentPromptCategoryId = isset($currentUser['prompt_category_id'])
    ? (int) $currentUser['prompt_category_id']
    : null;

if ($currentPromptCategoryId === 0) {
    $currentPromptCategoryId = null;
}

if ($currentPromptCategoryId === null && $currentUserId > 0) {
    $stmt = $pdo->prepare('SELECT prompt_category_id FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $currentUserId]);
    $fetchedCategoryId = $stmt->fetchColumn();
    $currentPromptCategoryId = $fetchedCategoryId ? (int) $fetchedCategoryId : null;
}

$errors = [];
$successMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['_token'] ?? null;

    if (!auth_validate_csrf(is_string($token) ? $token : null)) {
        $errors[] = 'Ungültiges Formular-Token. Bitte versuchen Sie es erneut.';
    }

    $postedCategoryId = isset($_POST['prompt_category_id']) ? (int) $_POST['prompt_category_id'] : 0;

    if ($errors === []) {
        if ($postedCategoryId <= 0 || !isset($categoryOptions[$postedCategoryId])) {
            $errors[] = 'Bitte wählen Sie eine gültige Branche aus.';
        } else {
            $update = $pdo->prepare('UPDATE users SET prompt_category_id = :cid, updated_at = NOW() WHERE id = :uid');
            $update->execute([
                ':cid' => $postedCategoryId,
                ':uid' => $currentUserId,
            ]);

            $currentPromptCategoryId = $postedCategoryId;

            $refresh = $pdo->prepare('SELECT id, name, email, prompt_category_id FROM users WHERE id = :id LIMIT 1');
            $refresh->execute(['id' => $currentUserId]);
            $freshUser = $refresh->fetch(PDO::FETCH_ASSOC);

            if ($freshUser) {
                auth_store_user($freshUser);
                $currentUser = $freshUser;
            }

            $successMessage = 'Branche gespeichert.';
        }
    }
}

$activePage = 'industry';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Branche - Ecomm Agent</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Roboto+Mono:wght@400;500&display=swap"
        rel="stylesheet"
    >
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="settings.css">
</head>
<body>
    <div class="settings-app">
        <header class="settings-header">
            <h1>Einstellungen</h1>
            <a class="btn-primary app__status-row-button settings-back-button" href="../index.php">Zurück</a>
        </header>
        <div class="settings-main">
            <nav class="settings-nav" aria-label="Einstellungsnavigation">
                <a
                    class="settings-nav-item<?php echo $activePage === 'profile' ? ' active' : ''; ?>"
                    href="index.php"
                >Profil</a>
                <a
                    class="settings-nav-item<?php echo $activePage === 'industry' ? ' active' : ''; ?>"
                    href="industry.php"
                >Branche</a>
                <a
                    class="settings-nav-item<?php echo $activePage === 'image' ? ' active' : ''; ?>"
                    href="image.php"
                >Seitenverhältnis</a>
                <a
                    class="settings-nav-item<?php echo $activePage === 'image_variants' ? ' active' : ''; ?>"
                    href="image_variants.php"
                >Bildvarianten</a>
                <a
                    class="settings-nav-item<?php echo $activePage === 'credits' ? ' active' : ''; ?>"
                    href="credits.php"
                >Credits</a>
                <a
                    class="settings-nav-item<?php echo $activePage === 'pricelist' ? ' active' : ''; ?>"
                    href="pricelist.php"
                >Preisliste</a>
            </nav>
            <div class="settings-content">
                <div class="settings-card">
                    <h2 class="settings-section-title">Branche</h2>
                    <p class="settings-section-subtitle">
                        Wählen Sie Ihre primäre Branche. Diese Einstellung wird für Bildvarianten und zukünftige Funktionen verwendet.
                    </p>

                    <?php if ($successMessage !== null): ?>
                    <div class="settings-message" role="status">
                        <?php echo htmlspecialchars($successMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($errors !== []): ?>
                    <div class="app__messages" aria-live="polite">
                        <?php foreach ($errors as $error): ?>
                        <div class="app__message app__message--error">
                            <?php echo htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <form method="post" class="settings-form">
                        <input type="hidden" name="_token" value="<?php echo htmlspecialchars(auth_csrf_token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">

                        <div class="form-group">
                            <label for="prompt_category_id">Primäre Branche</label>
                            <select id="prompt_category_id" name="prompt_category_id" required>
                                <option value="">Bitte Branche auswählen …</option>
                                <?php foreach ($categoryOptions as $id => $cat): ?>
                                    <option value="<?php echo (int) $id; ?>" <?php echo ($currentPromptCategoryId === $id) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cat['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn btn--primary">Speichern</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
