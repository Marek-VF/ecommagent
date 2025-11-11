<?php
require_once __DIR__ . '/auth/bootstrap.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
unset($_SESSION['current_run_id']);

auth_require_login();

$config = auth_config();
$flashes = auth_get_flashes();
$currentUser = auth_user();

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

$baseUrl = rtrim((string)($config['base_url'] ?? ''), '/');
$assetBaseUrl = $config['asset_base_url'] ?? ($baseUrl !== '' ? $baseUrl . '/assets' : '');
$assetBaseUrl = $assetBaseUrl !== '' ? rtrim((string)$assetBaseUrl, '/') : '/assets';

$placeholderSrc = $assetBaseUrl . '/placeholder.png';
$pulseOverlaySrc = $assetBaseUrl . '/pulse.svg';

$placeholderDimensions = null;
$placeholderFile = __DIR__ . '/assets/placeholder.png';
if (is_file($placeholderFile)) {
    $size = @getimagesize($placeholderFile);
    if ($size !== false) {
        $placeholderDimensions = [
            'width'  => $size[0],
            'height' => $size[1],
        ];
    }
}

$appConfig = [
    'base_url' => $baseUrl !== '' ? $baseUrl : null,
    'assets' => [
        'base'        => $assetBaseUrl,
        'placeholder' => $placeholderSrc,
        'overlay'     => $pulseOverlaySrc,
        'loading'     => $pulseOverlaySrc,
        'pulse'       => $pulseOverlaySrc,
    ],
    'placeholderDimensions' => $placeholderDimensions,
];
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ecomm Agent</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Roboto+Mono:wght@400;500&display=swap"
        rel="stylesheet"
    >
    <link rel="stylesheet" href="style.css">
<?php if ($placeholderDimensions !== null): ?>
    <style>
        :root {
            --gallery-item-aspect-ratio: <?php echo htmlspecialchars($placeholderDimensions['width'] . ' / ' . $placeholderDimensions['height'], ENT_QUOTES); ?>;
        }
    </style>
<?php endif; ?>
</head>
<body>
    <div id="history-sidebar" class="history-sidebar" aria-hidden="true">
        <div class="history-sidebar__header">
            <h2>Verläufe</h2>
            <button id="history-close" class="history-sidebar__close" type="button" aria-label="Schließen">&times;</button>
        </div>
        <ul id="history-list" class="history-list"></ul>
        <?php if ($currentUser !== null): ?>
        <div id="sidebar-profile" class="sidebar-profile" data-profile>
            <button
                type="button"
                class="sidebar-profile__trigger"
                data-profile-trigger
                aria-haspopup="true"
                aria-expanded="false"
            >
                <span class="sidebar-profile__avatar" aria-hidden="true">
                    <?php echo htmlspecialchars((string) $userInitial, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                </span>
                <span class="sidebar-profile__info">
                    <span class="sidebar-profile__name">
                        <?php echo htmlspecialchars((string) $userDisplayName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                    </span>
                </span>
                <span class="sidebar-profile__caret" aria-hidden="true">
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M4 6l4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </span>
            </button>
            <div
                id="sidebar-profile-menu"
                class="sidebar-profile-menu"
                role="menu"
                aria-hidden="true"
            >
                <a
                    class="profile-item"
                    href="<?php echo htmlspecialchars(auth_url('/settings/index.php'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                    role="menuitem"
                >Einstellungen</a>
                <a class="profile-item profile-item--danger" href="auth/logout.php" role="menuitem">Abmelden</a>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <div class="app">
        <header class="app__header">
            <div class="app__header-left">
                <button
                    id="history-toggle"
                    class="history-toggle"
                    type="button"
                    aria-label="Historie öffnen"
                    aria-controls="history-sidebar"
                    aria-expanded="false"
                >&#9776;</button>
                <h1>Ecomm Agent</h1>
            </div>
        </header>
        <?php if ($flashes !== []): ?>
        <div class="app__messages" aria-live="polite">
            <?php foreach ($flashes as $message): ?>
            <?php
                $type = htmlspecialchars((string) ($message['type'] ?? 'info'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $text = htmlspecialchars((string) ($message['message'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            ?>
            <div class="app__message app__message--<?php echo $type; ?>"><?php echo $text; ?></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <main class="grid">
            <section class="panel panel--upload">
                <div class="panel__section panel__section--upload">
                  
                    <div id="drop-zone" class="drop-zone" tabindex="0" aria-label="Datei hierher ziehen oder klicken, um eine Datei auszuwählen">
                        <p>Ziehen Sie eine Bilddatei hierher oder klicken Sie, um eine Datei auszuwählen.</p>
                        <button id="select-file" type="button" class="btn">Datei hinzufügen</button>
                        <input id="file-input" type="file" name="image" accept="image/*" hidden>
                    </div>
                    <div class="original-image-wrapper" data-original-images aria-live="polite"></div>
                    <div id="upload-previews" class="preview-list" aria-live="polite"></div>
                </div>
            </section>
            <section class="panel panel--details">
                <div class="generated-grid" aria-label="Generierte Bilder">
                    <div class="generated-slot" data-slot="1" role="button" aria-label="Bildvorschau 1" tabindex="0">
                        <div class="render-shell">
                            <div class="render-box"></div>
                        </div>
                    </div>
                    <div class="generated-slot" data-slot="2" role="button" aria-label="Bildvorschau 2" tabindex="0">
                        <div class="render-shell">
                            <div class="render-box"></div>
                        </div>
                    </div>
                    <div class="generated-slot" data-slot="3" role="button" aria-label="Bildvorschau 3" tabindex="0">
                        <div class="render-shell">
                            <div class="render-box"></div>
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label for="article-name">Artikelname</label>
                    <input type="text" id="article-name" name="article-name" placeholder="Name eingeben">
                </div>
                <div class="form-group">
                    <label for="article-description">Artikelbeschreibung</label>
                    <textarea id="article-description" name="article-description" rows="6" placeholder="Beschreibung eingeben"></textarea>
                </div>
                <div class="form-actions">
                    <button id="btn-new" type="button" class="btn btn--primary">Workflow starten</button>
                </div>
                <div id="workflow-feedback" class="workflow-feedback" role="alert" aria-live="polite"></div>
            </section>
        </main>
    </div>

    <div id="lightbox" class="lightbox" role="dialog" aria-modal="true" aria-hidden="true">
        <button class="lightbox__close" aria-label="Schließen">&times;</button>
        <img src="" alt="Großansicht" class="lightbox__image">
    </div>

    <script>
        window.APP_CONFIG = <?php echo json_encode($appConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    </script>
    <script src="script.js"></script>
</body>
</html>
