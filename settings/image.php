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

$imageRatioOptions = [
    'original' => [
        'label'       => 'Am Originalbild orientieren',
        'ratio_text'  => '1:1 (Symbol)',
        'icon_class'  => 'ratio-1-1',
        'resolution'  => '–',
    ],
    '1:1' => [
        'label'       => '1:1',
        'ratio_text'  => '1:1',
        'icon_class'  => 'ratio-1-1',
        'resolution'  => '1024×1024',
    ],
    '2:3' => [
        'label'       => '2:3',
        'ratio_text'  => '2:3',
        'icon_class'  => 'ratio-2-3',
        'resolution'  => '832×1248',
    ],
    '3:2' => [
        'label'       => '3:2',
        'ratio_text'  => '3:2',
        'icon_class'  => 'ratio-3-2',
        'resolution'  => '1248×832',
    ],
    '3:4' => [
        'label'       => '3:4',
        'ratio_text'  => '3:4',
        'icon_class'  => 'ratio-3-4',
        'resolution'  => '864×1184',
    ],
    '4:3' => [
        'label'       => '4:3',
        'ratio_text'  => '4:3',
        'icon_class'  => 'ratio-4-3',
        'resolution'  => '1184×864',
    ],
    '4:5' => [
        'label'       => '4:5',
        'ratio_text'  => '4:5',
        'icon_class'  => 'ratio-4-5',
        'resolution'  => '896×1152',
    ],
    '5:4' => [
        'label'       => '5:4',
        'ratio_text'  => '5:4',
        'icon_class'  => 'ratio-5-4',
        'resolution'  => '1152×896',
    ],
    '9:16' => [
        'label'       => '9:16',
        'ratio_text'  => '9:16',
        'icon_class'  => 'ratio-9-16',
        'resolution'  => '768×1344',
    ],
    '16:9' => [
        'label'       => '16:9',
        'ratio_text'  => '16:9',
        'icon_class'  => 'ratio-16-9',
        'resolution'  => '1344×768',
    ],
    '21:9' => [
        'label'       => '21:9',
        'ratio_text'  => '21:9',
        'icon_class'  => 'ratio-21-9',
        'resolution'  => '1536×672',
    ],
];

$statement = $pdo->prepare('SELECT image_ratio_preference FROM users WHERE id = :id LIMIT 1');
$statement->execute(['id' => $userId]);
$preference = $statement->fetchColumn();

$currentPreference = is_string($preference) ? $preference : 'original';
if (!array_key_exists($currentPreference, $imageRatioOptions)) {
    $currentPreference = 'original';
}

$activePage = 'image';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Seitenverhältnisse - Ecomm Agent</title>
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
            </nav>
            <div class="settings-content">
                <div class="settings-card">
                    <h2>Seitenverhältnisse</h2>
                    <p class="image-settings-description">Wählen Sie das Seitenverhältnis für zukünftige Bildgenerierungen.</p>
                    <form id="image-settings-form" class="image-settings-form" autocomplete="off">
                        <input type="hidden" name="_token" value="<?php echo htmlspecialchars(auth_csrf_token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                        <div class="ratio-grid">
                            <div class="ratio-grid-header">
                                <span>Auswahl</span>
                                <span>Seitenverhältnis</span>
                                <span>Auflösung</span>
                            </div>
                            <?php foreach ($imageRatioOptions as $value => $option):
                                $isSelected = $value === $currentPreference;
                                $inputId = 'ratio-' . preg_replace('/[^a-z0-9]+/i', '-', $value);
                            ?>
                            <label class="ratio-row<?php echo $isSelected ? ' selected' : ''; ?>" data-ratio-value="<?php echo htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" for="<?php echo htmlspecialchars($inputId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                                <input
                                    type="radio"
                                    name="image_ratio"
                                    id="<?php echo htmlspecialchars($inputId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                    value="<?php echo htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                    <?php echo $isSelected ? 'checked' : ''; ?>
                                >
                                <span class="ratio-row-cell ratio-row-choice">
                                    <span class="ratio-row-indicator" aria-hidden="true"></span>
                                    <span class="ratio-row-label"><?php echo htmlspecialchars($option['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
                                </span>
                                <span class="ratio-row-cell ratio-row-ratio">
                                    <div class="ratio-icon <?php echo htmlspecialchars($option['icon_class'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" aria-hidden="true"></div>
                                </span>
                                <span class="ratio-row-cell ratio-row-resolution"><?php echo htmlspecialchars($option['resolution'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </form>
                    <div id="image-settings-message" class="settings-message image-settings-message" role="status" aria-live="polite"></div>
                </div>
            </div>
        </div>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const form = document.getElementById('image-settings-form');
        const messageBox = document.getElementById('image-settings-message');
        const ratioInputs = form ? Array.from(form.querySelectorAll('input[name="image_ratio"]')) : [];

        const showMessage = function (text, isError = false) {
            if (!messageBox) {
                return;
            }

            messageBox.textContent = text;
            messageBox.classList.toggle('settings-message--error', isError);
            messageBox.classList.add('is-visible');
        };

        const clearMessage = function () {
            if (!messageBox) {
                return;
            }

            messageBox.textContent = '';
            messageBox.classList.remove('settings-message--error');
            messageBox.classList.remove('is-visible');
        };

        const updateSelectedClasses = function (activeValue) {
            const rows = document.querySelectorAll('.ratio-row');
            rows.forEach(function (row) {
                if (row instanceof HTMLElement) {
                    const rowValue = row.getAttribute('data-ratio-value');
                    if (rowValue === activeValue) {
                        row.classList.add('selected');
                    } else {
                        row.classList.remove('selected');
                    }
                }
            });
        };

        if (form) {
            form.addEventListener('submit', function (event) {
                event.preventDefault();
            });
        }

        ratioInputs.forEach(function (input) {
            input.addEventListener('change', function () {
                if (!form) {
                    return;
                }

                clearMessage();

                const formData = new FormData(form);

                fetch('update_image_settings.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: formData
                })
                    .then(function (response) {
                        return response.json().then(function (data) {
                            return { ok: response.ok, data: data };
                        }).catch(function () {
                            return { ok: response.ok, data: { success: false } };
                        });
                    })
                    .then(function (result) {
                        if (!result) {
                            showMessage('Es ist ein Fehler aufgetreten.', true);
                            return;
                        }

                        if (result.ok && result.data && result.data.success) {
                            const selectedValue = input.value;
                            updateSelectedClasses(selectedValue);
                            showMessage('Einstellung gespeichert.');
                            window.setTimeout(clearMessage, 3000);
                        } else {
                            const errorMessage = result.data && result.data.message ? result.data.message : 'Es ist ein Fehler aufgetreten.';
                            showMessage(errorMessage, true);
                        }
                    })
                    .catch(function () {
                        showMessage('Es ist ein Fehler aufgetreten.', true);
                    });
            });
        });
    });
    </script>
</body>
</html>
