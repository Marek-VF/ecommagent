<?php
require_once __DIR__ . '/../auth/bootstrap.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

auth_require_login();

$config = auth_config();
$currentUser = auth_user();

require_once __DIR__ . '/../db.php';

$userId = $_SESSION['user']['id'] ?? null;
$imageId = isset($_GET['image_id']) ? (int) $_GET['image_id'] : 0;

$error = null;
$image = null;

if ($imageId <= 0) {
    http_response_code(400);
    $error = 'Ungültige Bild-ID.';
} elseif ($userId === null) {
    http_response_code(401);
    $error = 'Nicht authentifiziert.';
} else {
    $pdo = getPDO();
    $stmt = $pdo->prepare(
        'SELECT id, user_id, run_id, note_id, url, position, created_at FROM item_images WHERE id = :id LIMIT 1'
    );
    $stmt->execute(['id' => $imageId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || (int) $row['user_id'] !== (int) $userId) {
        http_response_code(404);
        $error = 'Bild nicht gefunden oder keine Berechtigung.';
    } else {
        $image = $row;
    }
}

$baseUrlConfig = $config['base_url'] ?? '';
$baseUrl = '';
if (is_string($baseUrlConfig) && $baseUrlConfig !== '') {
    $baseUrl = rtrim($baseUrlConfig, '/');
}

$resolvePublicPath = static function (?string $path) use ($baseUrl): ?string {
    if ($path === null) {
        return null;
    }

    $trimmed = trim($path);
    if ($trimmed === '') {
        return null;
    }

    if (!preg_match('#^https?://#i', $trimmed) && !str_starts_with($trimmed, '/')) {
        if ($baseUrl !== '') {
            $trimmed = $baseUrl . '/' . ltrim($trimmed, '/');
        } else {
            $trimmed = '/' . ltrim($trimmed, '/');
        }
    }

    return $trimmed;
};

$imageUrl = null;
if ($image !== null) {
    $imageUrl = $resolvePublicPath(is_string($image['url']) ? $image['url'] : null);
}

$userDisplayName = 'Benutzer';
if ($currentUser !== null) {
    $candidate = $currentUser['name'] ?? $currentUser['email'] ?? null;
    if (is_string($candidate)) {
        $trimmedCandidate = trim($candidate);
        if ($trimmedCandidate !== '') {
            $userDisplayName = $trimmedCandidate;
        }
    }
}

$userInitial = $userDisplayName !== '' ? $userDisplayName : 'B';
if (function_exists('mb_substr')) {
    $initial = mb_substr($userInitial, 0, 1, 'UTF-8');
} else {
    $initial = substr($userInitial, 0, 1);
}
if ($initial === false || $initial === '') {
    $initial = 'B';
}
if (function_exists('mb_strtoupper')) {
    $userInitial = mb_strtoupper($initial, 'UTF-8');
} else {
    $userInitial = strtoupper($initial);
}

$assetBaseUrl = $config['asset_base_url'] ?? ($baseUrl !== '' ? $baseUrl . '/assets' : '');
$assetBaseUrl = $assetBaseUrl !== '' ? rtrim((string) $assetBaseUrl, '/') : '/assets';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ecom Studio – Bild bearbeiten</title>

    <script src="https://cdn.tailwindcss.com?plugins=forms,typography"></script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined" rel="stylesheet"/>

    <link rel="stylesheet" href="../style.css">
</head>
<body>
    <div class="app">
        <header class="app__header flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
            <div class="app__header-left">
                <h1>Bild bearbeiten</h1>
            </div>
 
        </header>

        <main class="edit-main">
            <div class="edit-main__nav">
                <a href="../index.php<?php echo isset($image['run_id']) ? '?run_id=' . (int)$image['run_id'] : ''; ?>" class="app__back-link" aria-label="Zurück zu Ecom Studio">&larr; Ecom Studio</a>
            </div>
            <?php if ($error !== null): ?>
                <div class="status-item status-item--error" data-severity="error">
                    <span class="status-icon-wrapper">
                        <span class="material-icons-outlined status-icon text-danger">error</span>
                    </span>
                    <div class="status-content"><p class="status-text"><?php echo htmlspecialchars($error, ENT_QUOTES); ?></p></div>
                </div>
            <?php else: ?>
                <div class="edit-layout">
                    <section class="edit-card">
                        <div class="edit-card__header">
                            <p class="edit-card__title">Prompting</p>
                        </div>
                        <div class="edit-card__body">
                            <label class="edit-card__field">
                                <span class="edit-card__label">Prompt</span>
                                <textarea class="input" rows="4" placeholder="Beschreibe die gewünschte Anpassung..."></textarea>
                            </label>

                            <div class="edit-card__row edit-card__row--actions">
                                <button type="button" class="btn-primary w-full" aria-label="Workflow starten">
                                    Workflow starten
                                </button>
                            </div>
                        </div>
                    </section>

                    <section class="edit-card image-edit__preview">
                        <div class="edit-card__header">
                            <p class="edit-card__title">Bildvorschau</p>
                        </div>
                        <div class="edit-card__body">
                            <?php if ($imageUrl !== null): ?>
                                <img src="<?php echo htmlspecialchars($imageUrl, ENT_QUOTES); ?>" alt="Ausgewähltes Bild">
                            <?php else: ?>
                                <p>Keine Bild-URL verfügbar.</p>
                            <?php endif; ?>
                        </div>
                        <div class="edit-card__footer">
                            <form method="post" action="#">
                                <div class="image-edit__actions">
                                    <button type="button" class="btn-secondary" aria-label="Bild speichern">
                                        Speichern
                                    </button>
                                </div>
                                <!-- TODO: Speichern-Logik implementieren -->
                            </form>
                        </div>
                    </section>
                </div>
            <?php endif; ?>
        </main>
    </div>
</body>
</html>
