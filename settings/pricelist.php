<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth/bootstrap.php';
require_once __DIR__ . '/../credits.php';

auth_require_login();

$pdo = auth_pdo();
$currentUser = auth_user();

if ($currentUser === null || !isset($currentUser['id'])) {
    auth_logout();
    auth_redirect('/auth/login.php');
}

$workflowCredits = get_required_credits_for_run($pdo);
$workflowCreditsDisplay = number_format($workflowCredits, 2, ',', '.');

$updateKeys = ['2K', '4K', 'edit'];
$placeholders = implode(', ', array_fill(0, count($updateKeys), '?'));

$updateRows = [];
$updateStmt = $pdo->prepare(
    "SELECT product_key, label\n     FROM products\n     WHERE type = 'step' AND active = 1 AND product_key IN ($placeholders)\n     ORDER BY CASE product_key\n        WHEN '2K' THEN 1\n        WHEN '4K' THEN 2\n        WHEN 'edit' THEN 3\n        ELSE 99\n     END"
);
$updateStmt->execute($updateKeys);
$updateResults = $updateStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$labelMap = [];
foreach ($updateResults as $row) {
    $key = isset($row['product_key']) ? trim((string) $row['product_key']) : '';
    if ($key === '') {
        continue;
    }

    $labelMap[$key] = isset($row['label']) && trim((string) $row['label']) !== ''
        ? trim((string) $row['label'])
        : $key;
}

foreach ($updateKeys as $key) {
    $credits = get_credit_price($pdo, $key);
    $updateRows[] = [
        'key' => $key,
        'label' => $labelMap[$key] ?? $key,
        'credits' => $credits,
    ];
}

$activePage = 'pricelist';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Preisliste - Ecomm Agent</title>
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
            <a class="btn-primary app__status-row-button settings-back-button" href="../index.php">Zur&uuml;ck</a>
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
                >Seitenverh&auml;ltnis</a>
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
                    <div>
                        <h2 class="settings-section-title">Preisliste</h2>
                        <p class="settings-section-subtitle">
                            Aktuelle Credit-Kosten basierend auf den hinterlegten Einkaufspreisen.
                        </p>
                    </div>
                    <div class="price-list-panel">
                        <table class="price-list-table">
                            <thead>
                                <tr>
                                    <th>Leistung</th>
                                    <th>Credits</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>Haupt-Workflow (gesamt)</td>
                                    <td><?php echo htmlspecialchars($workflowCreditsDisplay, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?> Credits</td>
                                </tr>
                                <?php foreach ($updateRows as $row) : ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($row['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></td>
                                        <td>
                                            <?php if ($row['credits'] !== null) : ?>
                                                <?php echo htmlspecialchars(number_format((float) $row['credits'], 2, ',', '.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?> Credits
                                            <?php else : ?>
                                                nicht konfiguriert
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
