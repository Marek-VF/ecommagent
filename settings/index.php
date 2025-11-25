<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth/bootstrap.php';

auth_require_login();

$pdo = auth_pdo();
$currentUser = auth_user();

if ($currentUser === null || !isset($currentUser['id'])) {
    auth_logout();
    auth_redirect('/auth/login.php');
}

$activePage = 'profile';

ob_start();
require __DIR__ . '/profile.php';
$content = ob_get_clean();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Einstellungen - Ecomm Agent</title>
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
            <a class="settings-back-link" href="../index.php">Zurück zu Artikelverwaltung</a>
        </header>
        <div class="settings-main">
            <nav class="settings-nav" aria-label="Einstellungsnavigation">
                <a
                    class="settings-nav-item<?php echo $activePage === 'profile' ? ' active' : ''; ?>"
                    href="index.php"
                >Profil</a>
                <a
                    class="settings-nav-item<?php echo $activePage === 'image' ? ' active' : ''; ?>"
                    href="image.php"
                >Seitenverhältnisse</a>
                <a
                    class="settings-nav-item<?php echo $activePage === 'image_variants' ? ' active' : ''; ?>"
                    href="image_variants.php"
                >Bildvarianten</a>
            </nav>
            <div class="settings-content">
                <?php echo $content; ?>
            </div>
        </div>
    </div>
</body>
</html>
