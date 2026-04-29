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

$errors = [];
$successMessage = null;

$billing = [
    'company_name' => '',
    'street' => '',
    'house_number' => '',
    'postal_code' => '',
    'city' => '',
    'vat_id' => '',
];

$statement = $pdo->prepare('SELECT company_name, street, house_number, postal_code, city, vat_id FROM billing_addresses WHERE user_id = :id LIMIT 1');
$statement->execute(['id' => $userId]);
$existing = $statement->fetch(PDO::FETCH_ASSOC);

if (is_array($existing)) {
    foreach ($billing as $key => $value) {
        if (isset($existing[$key])) {
            $billing[$key] = (string) $existing[$key];
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['_token'] ?? null;

    if (!auth_validate_csrf(is_string($token) ? $token : null)) {
        $errors[] = 'Ungültiges Formular-Token. Bitte versuchen Sie es erneut.';
    }

    $billing['company_name'] = trim((string) ($_POST['company_name'] ?? ''));
    $billing['street'] = trim((string) ($_POST['street'] ?? ''));
    $billing['house_number'] = trim((string) ($_POST['house_number'] ?? ''));
    $billing['postal_code'] = trim((string) ($_POST['postal_code'] ?? ''));
    $billing['city'] = trim((string) ($_POST['city'] ?? ''));
    $billing['vat_id'] = trim((string) ($_POST['vat_id'] ?? ''));

    if ($billing['company_name'] === '') {
        $errors[] = 'Bitte geben Sie den Firmennamen ein.';
    }
    if ($billing['street'] === '') {
        $errors[] = 'Bitte geben Sie die Straße ein.';
    }
    if ($billing['house_number'] === '') {
        $errors[] = 'Bitte geben Sie die Hausnummer ein.';
    }
    if ($billing['postal_code'] === '') {
        $errors[] = 'Bitte geben Sie die PLZ ein.';
    }
    if ($billing['city'] === '') {
        $errors[] = 'Bitte geben Sie den Ort ein.';
    }
    if ($billing['vat_id'] === '') {
        $errors[] = 'Bitte geben Sie die USt-IdNr. ein.';
    }

    if ($errors === []) {
        $upsert = $pdo->prepare(
            'INSERT INTO billing_addresses (user_id, company_name, street, house_number, postal_code, city, vat_id, created_at, updated_at)
             VALUES (:user_id, :company_name, :street, :house_number, :postal_code, :city, :vat_id, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                company_name = VALUES(company_name),
                street = VALUES(street),
                house_number = VALUES(house_number),
                postal_code = VALUES(postal_code),
                city = VALUES(city),
                vat_id = VALUES(vat_id),
                updated_at = NOW()'
        );

        $upsert->execute([
            'user_id' => $userId,
            'company_name' => $billing['company_name'],
            'street' => $billing['street'],
            'house_number' => $billing['house_number'],
            'postal_code' => $billing['postal_code'],
            'city' => $billing['city'],
            'vat_id' => $billing['vat_id'],
        ]);

        $successMessage = 'Rechnungsadresse gespeichert.';
    }
}

$activePage = 'billing';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rechnungsadresse - Ecomm Agent</title>
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
                    class="settings-nav-item<?php echo $activePage === 'billing' ? ' active' : ''; ?>"
                    href="billing.php"
                >Rechnungsadresse</a>
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
                    <h2 class="settings-section-title">Rechnungsadresse</h2>
                    <p class="settings-section-subtitle">
                        Hinterlegen Sie die Adresse, die für Rechnungen verwendet werden soll.
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

                    <form method="post" class="settings-form" novalidate>
                        <input type="hidden" name="_token" value="<?php echo htmlspecialchars(auth_csrf_token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                        <div class="form-group">
                            <label for="billing-company-name">Firmenname</label>
                            <input type="text" id="billing-company-name" name="company_name" value="<?php echo htmlspecialchars($billing['company_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="billing-street">Straße</label>
                            <input type="text" id="billing-street" name="street" value="<?php echo htmlspecialchars($billing['street'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="billing-house-number">Hausnummer</label>
                            <input type="text" id="billing-house-number" name="house_number" value="<?php echo htmlspecialchars($billing['house_number'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="billing-postal-code">PLZ</label>
                            <input type="text" id="billing-postal-code" name="postal_code" value="<?php echo htmlspecialchars($billing['postal_code'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="billing-city">Ort</label>
                            <input type="text" id="billing-city" name="city" value="<?php echo htmlspecialchars($billing['city'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="billing-vat-id">USt-IdNr.</label>
                            <input type="text" id="billing-vat-id" name="vat_id" value="<?php echo htmlspecialchars($billing['vat_id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" required>
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
