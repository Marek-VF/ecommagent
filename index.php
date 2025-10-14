<?php
$config = require __DIR__ . '/config.php';

$baseUrl = rtrim((string)($config['base_url'] ?? ''), '/');
$assetBaseUrl = $config['asset_base_url'] ?? ($baseUrl !== '' ? $baseUrl . '/assets' : '');
$assetBaseUrl = $assetBaseUrl !== '' ? rtrim((string)$assetBaseUrl, '/') : '/assets';

$placeholderSrc = $assetBaseUrl . '/placeholder.png';
$loadingSrc = $assetBaseUrl . '/loading.gif';

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
        'loading'     => $loadingSrc,
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
                    <img id="img1" src="<?php echo htmlspecialchars($placeholderSrc, ENT_QUOTES); ?>" alt="Platzhalter 1" class="gallery__item" data-preview data-placeholder="<?php echo htmlspecialchars($placeholderSrc, ENT_QUOTES); ?>">
                    <img id="img2" src="<?php echo htmlspecialchars($placeholderSrc, ENT_QUOTES); ?>" alt="Platzhalter 2" class="gallery__item" data-preview data-placeholder="<?php echo htmlspecialchars($placeholderSrc, ENT_QUOTES); ?>">
                    <img id="img3" src="<?php echo htmlspecialchars($placeholderSrc, ENT_QUOTES); ?>" alt="Platzhalter 3" class="gallery__item" data-preview data-placeholder="<?php echo htmlspecialchars($placeholderSrc, ENT_QUOTES); ?>">
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
