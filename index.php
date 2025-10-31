<?php
require_once __DIR__ . '/auth/bootstrap.php';

auth_require_login();

$flashes = auth_get_flashes();
$currentUser = auth_user();
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
</head>
<body>
    <div class="app">
        <header class="app__header">
            <h1>Ecomm Agent</h1>
            <?php if ($currentUser !== null): ?>
            <div class="app__user">
                <span class="app__user-name">
                    Angemeldet als <?php echo htmlspecialchars((string) ($currentUser['name'] ?? $currentUser['email']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                </span>
                <a class="app__logout" href="auth/logout.php">Abmelden</a>
            </div>
            <?php endif; ?>
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
            <section class="panel panel--product">
                <div class="panel__header">
                    <h2>Produktdetails</h2>
                    <div class="status-pill" id="statusBadge" aria-live="polite"></div>
                </div>
                <div class="product-content" aria-live="polite">
                    <h3 id="productName" class="product-content__name"></h3>
                    <p id="productDescription" class="product-content__description"></p>
                </div>
                <p id="statusText" class="status-text" aria-live="polite"></p>
            </section>
            <section class="panel panel--gallery">
                <div class="panel__header">
                    <h2>Bilder</h2>
                    <span id="imageCount" class="image-count" aria-live="polite"></span>
                </div>
                <div id="imageGallery" class="image-gallery" aria-live="polite"></div>
            </section>
            <section class="panel panel--logs">
                <div class="panel__header">
                    <h2>Logs</h2>
                </div>
                <ul id="logsList" class="logs-list" aria-live="polite" aria-label="Aktivitätsprotokoll"></ul>
            </section>
        </main>
    </div>
    <script src="script.js"></script>
</body>
</html>
