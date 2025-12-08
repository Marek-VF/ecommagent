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
                <a href="../index.php" class="history-toggle" aria-label="Zurück zur Übersicht">&#8592;</a>
                <h1>Ecom Studio</h1>
            </div>
            <div class="w-full flex md:w-auto md:block">
                <div class="profile" id="sidebar-profile">
                    <div class="profile-trigger" data-profile-trigger>
                        <div class="profile-avatar"><?php echo htmlspecialchars($userInitial, ENT_QUOTES); ?></div>
                        <div class="profile-info">
                            <span class="profile-name"><?php echo htmlspecialchars($userDisplayName, ENT_QUOTES); ?></span>
                        </div>
                    </div>
                    <div class="profile-menu" id="sidebar-profile-menu">
                        <a class="profile-item" href="../settings">Einstellungen</a>
                        <a class="profile-item profile-item--danger" href="../auth/logout.php">Abmelden</a>
                    </div>
                </div>
            </div>
        </header>

        <div class="app__status-row">
            <div id="status-bar" class="status-bar" role="status" aria-live="polite"></div>
        </div>

        <main class="grid">
            <section class="panel panel--upload" style="background: transparent; border: none; padding: 0; box-shadow: none;">
                <?php if ($error !== null): ?>
                    <div class="status-item status-item--error" data-severity="error">
                        <span class="status-icon-wrapper">
                            <span class="material-icons-outlined status-icon text-danger">error</span>
                        </span>
                        <div class="status-content"><p class="status-text"><?php echo htmlspecialchars($error, ENT_QUOTES); ?></p></div>
                    </div>
                <?php else: ?>
                    <div class="grid gap-6 lg:grid-cols-2">
                        <div class="panel card">
                            <div class="card__header">
                                <h3 class="output-title">Prompting</h3>
                            </div>
                            <div class="card__body">
                                <label class="block mb-4">
                                    <span class="text-sm font-medium text-gray-700">Prompt</span>
                                    <textarea class="w-full mt-1 rounded-md border-gray-300" rows="4" placeholder="Beschreibe die gewünschte Anpassung..."></textarea>
                                </label>
                                <div class="grid grid-cols-2 gap-4 mb-4">
                                    <label class="block">
                                        <span class="text-sm font-medium text-gray-700">Stil</span>
                                        <input type="text" class="w-full mt-1 rounded-md border-gray-300" placeholder="z. B. Modern">
                                    </label>
                                    <label class="block">
                                        <span class="text-sm font-medium text-gray-700">Farbschema</span>
                                        <input type="text" class="w-full mt-1 rounded-md border-gray-300" placeholder="z. B. Warm">
                                    </label>
                                </div>
                                <button type="button" class="btn-primary w-full" aria-label="Workflow starten">
                                    Workflow starten
                                </button>
                                <!-- TODO: Workflow-Logik implementieren -->
                            </div>
                        </div>

                        <div class="panel card image-edit__preview">
                            <div class="card__header">
                                <h3 class="output-title">Bildvorschau</h3>
                            </div>
                            <div class="card__body">
                                <?php if ($imageUrl !== null): ?>
                                    <img src="<?php echo htmlspecialchars($imageUrl, ENT_QUOTES); ?>" alt="Ausgewähltes Bild">
                                <?php else: ?>
                                    <p>Keine Bild-URL verfügbar.</p>
                                <?php endif; ?>
                            </div>
                            <div class="card__footer">
                                <form method="post" action="#">
                                    <button type="button" class="btn-secondary w-full" aria-label="Bild speichern">
                                        Speichern
                                    </button>
                                    <!-- TODO: Speichern-Logik implementieren -->
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>
</body>
</html>
