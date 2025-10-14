<?php
$config = require __DIR__ . '/config.php';

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
    <title>Artikelverwaltung</title>
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
    <div class="app">
        <header class="app__header">
            <h1>Artikelverwaltung</h1>
        </header>
        <main class="grid">
            <section class="panel panel--upload">
                <h2>Bild hochladen</h2>
                <div id="drop-zone" class="drop-zone" tabindex="0" aria-label="Datei hierher ziehen oder klicken, um eine Datei auszuwählen">
                    <p>Ziehen Sie eine Bilddatei hierher oder klicken Sie, um eine Datei auszuwählen.</p>
                    <button id="select-file" type="button" class="btn">Datei hinzufügen</button>
                    <input id="file-input" type="file" name="image" accept="image/*" hidden>
                </div>
                <div id="upload-previews" class="preview-list" aria-live="polite"></div>
                <div class="status" aria-live="polite">
                    <h3>Status</h3>
                    <ul id="status-messages" class="status__messages"></ul>
                    <p id="processing-indicator" class="status__indicator">Bereit.</p>
                </div>
            </section>
            <section class="panel panel--details">
                <div class="gallery" aria-label="Platzhalter-Bilder">
<?php for ($i = 1; $i <= 3; $i++):
    $slotKey = 'image_' . $i;
?>
                    <div id="img<?php echo $i; ?>" class="gallery__item" data-preview data-slot="<?php echo htmlspecialchars($slotKey, ENT_QUOTES); ?>" data-placeholder="<?php echo htmlspecialchars($placeholderSrc, ENT_QUOTES); ?>" data-has-content="false" data-is-loading="false" role="button" aria-label="Bildvorschau <?php echo $i; ?>" tabindex="0">
                        <img class="gallery__image gallery__image--placeholder" src="<?php echo htmlspecialchars($placeholderSrc, ENT_QUOTES); ?>" alt="Platzhalter <?php echo $i; ?>" data-role="placeholder">
                        <img class="gallery__image gallery__image--content" alt="Produktbild <?php echo $i; ?>" data-role="content">
                        <img class="gallery__overlay" src="<?php echo htmlspecialchars($pulseOverlaySrc, ENT_QUOTES); ?>" alt="" data-role="overlay" aria-hidden="true">
                    </div>
<?php endfor; ?>
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
                    <button type="button" class="btn btn--primary">Speichern</button>
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
