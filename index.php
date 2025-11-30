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
    <title>Ecom Studio</title>
    
    <script src="https://cdn.tailwindcss.com?plugins=forms,typography"></script>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined" rel="stylesheet"/>

    <script>
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        primary: "#ff5722", // Das Orange aus dem Design
                        secondary: "#00bcd4", // Das Türkis
                        "background-light": "#f0f2f5", // Hellgrauer Hintergrund
                        "card-light": "#ffffff", // Weiße Karten
                        "border-light": "#e5e7eb",
                        "text-primary-light": "#111827",
                        "text-secondary-light": "#6b7280",
                    },
                    fontFamily: {
                        sans: ["Inter", "sans-serif"], // Inter als Standard setzen
                        display: ["Inter", "sans-serif"],
                    },
                },
            },
        };
    </script>

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
        <header class="app__header flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
            <div class="app__header-left">
                <button
                    id="history-toggle"
                    class="history-toggle"
                    type="button"
                    aria-label="Historie öffnen"
                    aria-controls="history-sidebar"
                    aria-expanded="false"
                >&#9776;</button>
                <h1>Ecom Studio</h1>
            </div>
            <div class="w-full flex md:w-auto md:block">
                <!-- Workflow-Button sitzt nun im Header; IDs/Klassen bleiben für script.js unverändert. -->
                <button
                    id="start-workflow-btn"
                    type="button"
                    class="btn-primary app__status-row-button inline-flex items-center justify-center rounded-lg px-4 py-2 text-sm font-medium w-full max-w-[400px] mx-auto md:mx-0"
                >
                    <!-- Eigene Zeile & zentriert auf Mobile via w-full/max-w + mx-auto; im Desktop per Flex rechts ausgerichtet. -->
                    Workflow starten
                </button>
            </div>
        </header>
        <div class="app__status-row">
            <div id="status-bar" class="status-bar" role="status" aria-live="polite"></div>
        </div>
 
        <main class="grid">
<!--
            <section class="panel panel--upload">
                <div class="panel__section panel__section--upload">
                  
                                <div id="drop-zone" class="drop-zone" tabindex="0" aria-label="Datei hierher ziehen...">
                                    
                                    <span class="material-icons-outlined" style="font-size: 48px; color: var(--accent); margin-bottom: 16px;">
                                        upload_file
                                    </span>
                                    <p>Ziehen Sie eine Bilddatei hierher oder klicken Sie, um eine Datei auszuwählen.</p>
                                    
                                    <button id="select-file" type="button" class="btn">Datei hinzufügen</button>
                                    <input id="file-input" type="file" name="image" accept="image/*" hidden>
                                </div>
                    <div class="original-image-wrapper" data-original-images aria-live="polite"></div>
                    <div id="upload-previews" class="preview-list" aria-live="polite"></div>
                </div>
            </section>
        -->
<section class="panel panel--upload" style="background: transparent; border: none; padding: 0; box-shadow: none;">
    
    <div class="status-upload-card">
        
        <div class="status-area">
            <div class="status-header-row">
                <h3 class="status-header">Status Updates</h3>
            </div>
            <div class="status-list">
                <div class="status-item">
                    <span class="material-icons-outlined status-icon text-success">check_circle</span>
                    <div class="status-content">
                        <p class="status-text">Bild hochgeladen: product_1.jpg</p>
                        <p class="status-time">vor 2 Minuten</p>
                    </div>
                </div>
                <div class="status-item">
                    <span class="material-icons-outlined status-icon text-info">sync</span>
                    <div class="status-content">
                        <p class="status-text">Bestellung #123 wird verarbeitet</p>
                        <p class="status-time">vor 15 Minuten</p>
                    </div>
                </div>
                <div class="status-item">
                    <span class="material-icons-outlined status-icon text-error">error</span>
                    <div class="status-content">
                        <p class="status-text">Zahlung fehlgeschlagen #122</p>
                        <p class="status-time">vor 1 Stunde</p>
                    </div>
                </div>
                <div class="status-item">
                    <span class="material-icons-outlined status-icon text-success">waving_hand</span>
                    <div class="status-content">
                        <p class="status-text">Neuer Account erstellt</p>
                        <p class="status-time">vor 3 Stunden</p>
                    </div>
                </div>
            </div>
        </div>



        <div class="drop-area">
            <div id="drop-zone" class="drop-zone drop-zone--cta" tabindex="0" aria-label="Datei hochladen">
                <div class="cta-content">
                   <span class="material-icons-outlined cta-icon">
cloud_upload
</span>
                    <span class="cta-text">Datei hochladen</span>
                </div>
                <input id="file-input" type="file" name="image" accept="image/*" hidden>
                <button id="select-file" type="button" style="display:none;">Button</button>
            </div>
        </div>

    </div>

    <div class="original-image-wrapper" data-original-images aria-live="polite" style="margin-top: 24px;"></div>
    <div id="upload-previews" class="preview-list" aria-live="polite"></div>
</section>
            <section class="panel panel--details">
                <div id="workflow-output" class="workflow-output is-idle">
                    <div class="workflow-body">
                 <!--      
                    
                    <div class="generated-grid" aria-label="Generierte Bilder">
                            <div
                                class="generated-slot"
                                data-slot="1"
                                role="button"
                                aria-label="Bildvorschau 1"
                                tabindex="0"
                            >
                                <div class="render-shell"><div class="render-box"></div></div>
                            </div>
                            <div
                                class="generated-slot"
                                data-slot="2"
                                role="button"
                                aria-label="Bildvorschau 2"
                                tabindex="0"
                            >
                                <div class="render-shell"><div class="render-box"></div></div>
                            </div>
                            <div
                                class="generated-slot"
                                data-slot="3"
                                role="button"
                                aria-label="Bildvorschau 3"
                                tabindex="0"
                            >
                                <div class="render-shell"><div class="render-box"></div></div>
                            </div>
                        </div>
        -->



<div class="generated-grid" aria-label="Generierte Bilder">
    <div class="generated-card">
        <div class="generated-slot" data-slot="1" role="button" aria-label="Bildvorschau 1" tabindex="0">
            <div class="render-shell"><div class="render-box"></div></div>
        </div>
    <div class="slot-actions">
        <button type="button" class="action-btn btn-toggle" data-type="2k" title="Upscale 2K">2K</button>
        <button type="button" class="action-btn btn-toggle" data-type="4k" title="Upscale 4K">4K</button>
        <button type="button" class="action-btn btn-toggle" data-type="edit" title="Bearbeiten">
            <span class="material-icons-outlined">edit</span>
        </button>
        
    
    </div>
    </div>

    <div class="generated-card">
        <div class="generated-slot" data-slot="2" role="button" aria-label="Bildvorschau 2" tabindex="0">
            <div class="render-shell"><div class="render-box"></div></div>
        </div>
        <div class="slot-actions">
        <button type="button" class="action-btn btn-toggle" data-type="2k" title="Upscale 2K">2K</button>
        <button type="button" class="action-btn btn-toggle" data-type="4k" title="Upscale 4K">4K</button>
        <button type="button" class="action-btn btn-toggle" data-type="edit" title="Bearbeiten">
            <span class="material-icons-outlined">edit</span>
        </button>
   
    </div>
    </div>

    <div class="generated-card">
        <div class="generated-slot" data-slot="3" role="button" aria-label="Bildvorschau 3" tabindex="0">
            <div class="render-shell"><div class="render-box"></div></div>
        </div>
<div class="slot-actions">
        <button type="button" class="action-btn btn-toggle" data-type="2k" title="Upscale 2K">2K</button>
        <button type="button" class="action-btn btn-toggle" data-type="4k" title="Upscale 4K">4K</button>
        <button type="button" class="action-btn btn-toggle" data-type="edit" title="Bearbeiten">
            <span class="material-icons-outlined">edit</span>
        </button>
    
    </div>
    </div>
</div>


                        <div class="field-group is-loading" id="article-name-group">
                            <div class="field-content output-panel">
                                <div class="output-header">
                                    <span class="output-title">Artikelname</span>
                                    <button
                                        type="button"
                                        class="output-copy"
                                        data-copy-target="#article-name-content"
                                    >Kopieren</button>
                                </div>
                                <pre class="output-body" id="article-name-content"></pre>
                            </div>
                            <div class="skeleton-text" aria-hidden="true">
                                <div class="skeleton-line short"></div>
                                <div class="skeleton-line shorter"></div>
                            </div>
                        </div>

                        <div class="field-group is-loading" id="article-description-group">
                            <div class="field-content output-panel">
                                <div class="output-header">
                                    <span class="output-title">Artikelbeschreibung</span>
                                    <button
                                        type="button"
                                        class="output-copy"
                                        data-copy-target="#article-description-content"
                                    >Kopieren</button>
                                </div>
                                <pre class="output-body" id="article-description-content"></pre>
                            </div>
                            <div class="skeleton-text" aria-hidden="true">
                                <div class="skeleton-line long"></div>
                                <div class="skeleton-line medium"></div>
                                <div class="skeleton-line"></div>
                                <div class="skeleton-line short"></div>
                            </div>
                        </div>
                    </div>
                </div>
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
