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

$userId = (int) $currentUser['id'];

$statement = $pdo->prepare('SELECT credits_balance FROM users WHERE id = :id LIMIT 1');
$statement->execute(['id' => $userId]);
$creditsBalance = $statement->fetchColumn();

$creditsBalance = is_numeric($creditsBalance) ? (float) $creditsBalance : 0.0;

$formattedCredits = number_format($creditsBalance, 3, ',', '.');

$activePage = 'credits';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Credits - Ecomm Agent</title>
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
            </nav>
            <div class="settings-content">
                <div class="settings-card credits-card">
                    <div class="credits-card-header">
                        <div>
                            <h2 class="settings-section-title">Credits</h2>
                            <p class="settings-section-subtitle">
                                Hier sehen Sie Ihren aktuellen Credit-Kontostand für die Nutzung der KI-Workflows.
                            </p>
                        </div>
                    </div>
                    <div class="credits-balance-panel">
                        <div class="credits-label">Aktueller Kontostand</div>
                        <div class="credits-value"><?php echo htmlspecialchars($formattedCredits, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
