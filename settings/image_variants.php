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

$promptCategories = [
    'fashion' => 'Fashion',
    'deco'    => 'Deko',
];

$variantStmt = $pdo->prepare(
    'SELECT category, variant_slot, location, lighting, mood, season, model_type, model_pose, view_mode
     FROM prompt_variants
     WHERE user_id = :user_id'
);
$variantStmt->execute(['user_id' => $userId]);
$rows = $variantStmt->fetchAll(PDO::FETCH_ASSOC);

$promptVariants = [];
foreach ($rows as $row) {
    $cat = $row['category'];
    $slot = (int) $row['variant_slot'];
    if (!isset($promptVariants[$cat])) {
        $promptVariants[$cat] = [];
    }
    $promptVariants[$cat][$slot] = [
        'location'   => $row['location'],
        'lighting'   => $row['lighting'],
        'mood'       => $row['mood'],
        'season'     => $row['season'],
        'model_type' => $row['model_type'],
        'model_pose' => $row['model_pose'],
        'view_mode'  => $row['view_mode'],
    ];
}

$defaultPromptVariants = [
    1 => [
        'location'   => 'a professional photo studio with a clean backdrop',
        'lighting'   => 'soft, even studio lighting with subtle shadows',
        'mood'       => 'minimal, high-end, editorial',
        'season'     => 'all-season, neutral',
        'model_type' => 'mid-20s, natural look, german',
        'model_pose' => 'standing in a relaxed, confident posture, slightly angled towards the camera',
        'view_mode'  => 'full_body',
    ],
    2 => [
        'location'   => 'a cozy indoor café with large windows',
        'lighting'   => 'soft diffused daylight',
        'mood'       => 'calm, intimate, lifestyle',
        'season'     => 'autumn',
        'model_type' => 'mid-20s, natural look, german',
        'model_pose' => 'sitting casually at a café table',
        'view_mode'  => 'full_body',
    ],
    3 => [
        'location'   => 'a modern city sidewalk in front of a café',
        'lighting'   => 'golden hour light',
        'mood'       => 'vibrant, urban, editorial',
        'season'     => 'autumn',
        'model_type' => 'mid-20s, natural look, german',
        'model_pose' => 'walking casually, relaxed posture',
        'view_mode'  => 'full_body',
    ],
];

$promptVariantsJson = json_encode($promptVariants, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$defaultPromptVariantsJson = json_encode($defaultPromptVariants, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$activePage = 'image_variants';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bildvarianten - Ecomm Agent</title>
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
            <a class="settings-back-link" href="../index.php">Zurück zu Artikelverwaltung</a>
        </header>
        <div class="settings-main">
            <nav class="settings-nav" aria-label="Einstellungsnavigation">
                <a
                    class="settings-nav-item<?php echo $activePage === 'profile' ? ' active' : ''; ?>"
                    href="index.php"
                >Profil</a>
                <a
                    class="settings-nav-item<?php echo $activePage === 'image' ? ' active' : ''; ?>"
                    href="image.php"
                >Seitenverhältnisse</a>
                <a
                    class="settings-nav-item<?php echo $activePage === 'image_variants' ? ' active' : ''; ?>"
                    href="image_variants.php"
                >Bildvarianten</a>
            </nav>
            <div class="settings-content">
                <div
                    id="variant-status-message"
                    class="settings-status-message"
                    style="display:none;"
                    role="status"
                    aria-live="polite"
                ></div>
                <div class="settings-card">

                    <section class="prompt-variants-section">
                        <div class="prompt-variants-controls">
                            <label class="prompt-variants-category">
                                Kategorie
                                <select id="prompt-category-select" name="prompt_category">
                                    <?php foreach ($promptCategories as $value => $label): ?>
                                        <option value="<?php echo htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                                            <?php echo htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        </div>

                        <div class="prompt-variants-tabs" role="tablist">
                            <button type="button" class="prompt-variant-tab is-active" data-variant-slot="1">Bildvariante 1</button>
                            <button type="button" class="prompt-variant-tab" data-variant-slot="2">Bildvariante 2</button>
                            <button type="button" class="prompt-variant-tab" data-variant-slot="3">Bildvariante 3</button>
                        </div>

                        <form id="prompt-variant-form" class="prompt-variant-form" autocomplete="off">
                            <input type="hidden" name="_token" value="<?php echo htmlspecialchars(auth_csrf_token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                            <input type="hidden" name="category" id="prompt-category-input" value="fashion">
                            <input type="hidden" name="variant_slot" id="prompt-variant-slot-input" value="1">

                            <div class="prompt-variant-fields">
                                <label class="prompt-field">
                                    <span>Location</span>
                                    <textarea name="location" id="prompt-location" rows="2"></textarea>
                                </label>

                                <label class="prompt-field">
                                    <span>Lighting</span>
                                    <textarea name="lighting" id="prompt-lighting" rows="2"></textarea>
                                </label>

                                <label class="prompt-field">
                                    <span>Mood</span>
                                    <textarea name="mood" id="prompt-mood" rows="2"></textarea>
                                </label>

                                <label class="prompt-field">
                                    <span>Season</span>
                                    <textarea name="season" id="prompt-season" rows="2"></textarea>
                                </label>

                                <label class="prompt-field">
                                    <span>Model Type</span>
                                    <textarea name="model_type" id="prompt-model-type" rows="2"></textarea>
                                </label>

                                <label class="prompt-field">
                                    <span>Model Pose</span>
                                    <textarea name="model_pose" id="prompt-model-pose" rows="2"></textarea>
                                </label>

                                <label class="prompt-field">
                                    <span>View Mode</span>
                                    <select name="view_mode" id="prompt-view-mode">
                                        <option value="full_body">full_body</option>
                                        <option value="garment_closeup">garment_closeup</option>
                                    </select>
                                </label>
                            </div>

                            <div class="prompt-variant-actions">
                                <button type="submit" class="btn-primary">Variante speichern</button>
                                <button type="button" class="btn-secondary" id="prompt-variant-reset">
                                    Auf Standard zurücksetzen
                                </button>
                            </div>

                            <p class="prompt-variant-hint">
                                Die Einstellungen gelten pro Benutzer, Kategorie und Bildvariante.
                            </p>
                        </form>
                    </section>
                </div>
            </div>
        </div>
    </div>
    <script>
        window.PROMPT_VARIANTS = <?php echo $promptVariantsJson ?: '{}'; ?>;
        window.DEFAULT_PROMPT_VARIANTS = <?php echo $defaultPromptVariantsJson ?: '{}'; ?>;
    </script>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const categorySelect = document.getElementById('prompt-category-select');
        const hiddenCategoryInput = document.getElementById('prompt-category-input');
        const variantSlotInput = document.getElementById('prompt-variant-slot-input');

        const fields = {
            location:   document.getElementById('prompt-location'),
            lighting:   document.getElementById('prompt-lighting'),
            mood:       document.getElementById('prompt-mood'),
            season:     document.getElementById('prompt-season'),
            model_type: document.getElementById('prompt-model-type'),
            model_pose: document.getElementById('prompt-model-pose'),
            view_mode:  document.getElementById('prompt-view-mode')
        };

        const tabs = Array.from(document.querySelectorAll('.prompt-variant-tab'));
        const form = document.getElementById('prompt-variant-form');
        const resetButton = document.getElementById('prompt-variant-reset');
        const statusMessage = document.getElementById('variant-status-message');
        let statusTimeoutId = null;

        const stored = window.PROMPT_VARIANTS || {};
        const defaults = window.DEFAULT_PROMPT_VARIANTS || {};

        function showStatusMessage(text) {
            if (!statusMessage) {
                return;
            }

            statusMessage.textContent = text;
            statusMessage.style.display = 'block';
            statusMessage.classList.add('is-visible');

            if (statusTimeoutId) {
                clearTimeout(statusTimeoutId);
            }

            statusTimeoutId = window.setTimeout(function () {
                statusMessage.classList.remove('is-visible');
                statusMessage.style.display = 'none';
            }, 3000);
        }

        function getCurrentCategory() {
            return hiddenCategoryInput.value || categorySelect.value;
        }

        function getCurrentSlot() {
            return parseInt(variantSlotInput.value || '1', 10);
        }

        function getDataFor(cat, slot) {
            const fromUser = stored[cat] && stored[cat][slot] ? stored[cat][slot] : null;
            const fromDefault = defaults[slot] || {};
            return fromUser || fromDefault;
        }

        function loadVariantIntoForm() {
            const cat = getCurrentCategory();
            const slot = getCurrentSlot();
            const data = getDataFor(cat, slot);

            fields.location.value   = data.location || '';
            fields.lighting.value   = data.lighting || '';
            fields.mood.value       = data.mood || '';
            fields.season.value     = data.season || '';
            fields.model_type.value = data.model_type || '';
            fields.model_pose.value = data.model_pose || '';
            fields.view_mode.value  = data.view_mode || 'full_body';
        }

        function setActiveTabForSlot(slot) {
            tabs.forEach(function (tab) {
                const tabSlot = parseInt(tab.getAttribute('data-variant-slot'), 10);
                tab.classList.toggle('is-active', tabSlot === slot);
            });
        }

        categorySelect.addEventListener('change', function () {
            hiddenCategoryInput.value = categorySelect.value;
            loadVariantIntoForm();
        });

        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                const slot = parseInt(tab.getAttribute('data-variant-slot'), 10);
                variantSlotInput.value = String(slot);
                setActiveTabForSlot(slot);
                loadVariantIntoForm();
            });
        });

        resetButton.addEventListener('click', function (event) {
            event.preventDefault();
            const cat = getCurrentCategory();
            const slot = getCurrentSlot();
            const data = defaults[slot] || {};
            stored[cat] = stored[cat] || {};
            stored[cat][slot] = data;
            loadVariantIntoForm();
        });

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            const formData = new FormData(form);

            fetch('update_prompt_variants.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
            })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('Fehler beim Speichern der Variante');
                }
                return response.json();
            })
            .then(function (data) {
                if (!data.success) {
                    throw new Error(data.message || 'Speichern fehlgeschlagen');
                }
                const cat = getCurrentCategory();
                const slot = getCurrentSlot();
                stored[cat] = stored[cat] || {};
                stored[cat][slot] = {
                    location:   fields.location.value,
                    lighting:   fields.lighting.value,
                    mood:       fields.mood.value,
                    season:     fields.season.value,
                    model_type: fields.model_type.value,
                    model_pose: fields.model_pose.value,
                    view_mode:  fields.view_mode.value,
                };
                showStatusMessage(data.message || 'Bildvariante wurde erfolgreich gespeichert.');
            })
            .catch(function (error) {
                console.error(error);
                alert(error.message || 'Fehler beim Speichern der Variante.');
            });
        });

        hiddenCategoryInput.value = categorySelect.value;
        variantSlotInput.value = '1';
        setActiveTabForSlot(1);
        loadVariantIntoForm();
    });
    </script>
</body>
</html>
