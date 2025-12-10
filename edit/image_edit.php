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
                <?php
                    $backUrl = '../index.php';
                    if (!empty($image['run_id'])) {
                        $backUrl .= '?run_id=' . (int)$image['run_id'];
                    }
                ?>
                <a href="<?php echo $backUrl; ?>" class="app__back-link" aria-label="Zurück zu Ecom Studio">&larr; Ecom Studio</a>
            </div>

            <?php if ($error !== null): ?>
                <div class="status-item status-item--error">
                    <span class="material-icons-outlined status-icon text-danger">error</span>
                    <p class="status-text"><?php echo htmlspecialchars($error, ENT_QUOTES); ?></p>
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
                                <textarea id="edit-prompt" class="input" rows="4" placeholder="Beschreibe die gewünschte Anpassung (z.B. 'Hintergrund entfernen', 'Farbe rot machen')..."></textarea>
                                <span class="text-xs text-gray-400 mt-1 block text-right" id="char-count">0 Zeichen</span>
                            </label>

                            <div id="edit-status-message" class="hidden mb-4 p-3 rounded text-sm"></div>

                            <div class="edit-card__row edit-card__row--actions">
                                <button type="button" id="btn-start-edit" class="btn-primary w-full flex items-center justify-center" disabled
                                    data-run-id="<?php echo (int)$image['run_id']; ?>"
                                    data-image-id="<?php echo (int)$image['id']; ?>"
                                    data-position="<?php echo (int)$image['position']; ?>">
                                    
                                    <svg class="loading-spinner animate-spin -ml-1 mr-3 h-5 w-5 text-white hidden" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>

                                    <span class="btn-text">Workflow starten</span>
                                </button>
                                <button type="button" id="btn-save-edit" class="btn-primary w-full flex items-center justify-center hidden opacity-50 cursor-not-allowed" disabled>
                                    <span class="btn-text">Speichern</span>
                                </button>
                            </div>
                        </div>
                    </section>

                    <section class="edit-card image-edit__preview">
                        <div class="edit-card__header">
                            <p class="edit-card__title">Bildvorschau</p>
                        </div>
                        <div class="edit-card__body flex justify-center items-center bg-gray-50 min-h-[300px]">
                            <?php if ($imageUrl !== null): ?>
                                <img src="<?php echo htmlspecialchars($imageUrl, ENT_QUOTES); ?>" alt="Originalbild" class="max-w-full h-auto rounded shadow-sm">
                            <?php else: ?>
                                <p class="text-gray-400">Kein Bild geladen.</p>
                            <?php endif; ?>
                        </div>
                    </section>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const promptInput = document.getElementById('edit-prompt');
            const startBtn = document.getElementById('btn-start-edit');
            const saveBtn = document.getElementById('btn-save-edit');
            const charCount = document.getElementById('char-count');
            const statusMsg = document.getElementById('edit-status-message');
            const spinner = startBtn ? startBtn.querySelector('.loading-spinner') : null;
            const btnText = startBtn ? startBtn.querySelector('.btn-text') : null;
            const previewImage = document.querySelector('.image-edit__preview img');

            let pollInterval = null;
            let stagingId = null;

            if (!promptInput || !startBtn || !saveBtn) return;

            const runId = startBtn.dataset.runId;
            const imageId = startBtn.dataset.imageId;
            const position = startBtn.dataset.position;

            const resetStatus = () => {
                statusMsg.className = 'hidden mb-4 p-3 rounded text-sm';
                statusMsg.textContent = '';
            };

            const setStatus = (message, type = 'info') => {
                statusMsg.textContent = message;
                statusMsg.className = 'mb-4 p-3 rounded text-sm';
                statusMsg.classList.remove('hidden');

                if (type === 'success') {
                    statusMsg.classList.add('bg-green-100', 'text-green-800', 'border', 'border-green-200');
                } else if (type === 'error') {
                    statusMsg.classList.add('bg-red-100', 'text-red-800', 'border', 'border-red-200');
                } else {
                    statusMsg.classList.add('bg-blue-100', 'text-blue-800', 'border', 'border-blue-200');
                }
            };

            const stopPolling = () => {
                if (pollInterval !== null) {
                    clearInterval(pollInterval);
                    pollInterval = null;
                }
            };

            const startPollingForEdit = () => {
                stopPolling();
                pollInterval = window.setInterval(async () => {
                    try {
                        const response = await fetch(`../api/check-edit-polling.php?run_id=${encodeURIComponent(runId)}`);
                        const data = await response.json();

                        if (response.ok && data.ok && data.found) {
                            stopPolling();
                            stagingId = data.image?.id ?? null;

                            if (data.image?.url && previewImage) {
                                previewImage.src = data.image.url;
                            }

                            if (spinner) spinner.classList.add('hidden');
                            if (btnText) btnText.textContent = 'Entwurf bereit';

                            startBtn.classList.add('hidden');
                            saveBtn.classList.remove('hidden');
                            saveBtn.disabled = false;
                            saveBtn.classList.remove('opacity-50', 'cursor-not-allowed');

                            setStatus('Dein Entwurf ist fertig. Du kannst ihn jetzt speichern.', 'success');
                        }
                    } catch (error) {
                        console.error('Polling failed', error);
                    }
                }, 2000);
            };

            // Validierung: Button erst ab 3 Zeichen aktivieren
            promptInput.addEventListener('input', () => {
                const len = promptInput.value.trim().length;
                charCount.textContent = len + ' Zeichen';

                if (len >= 3) {
                    startBtn.removeAttribute('disabled');
                    startBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                } else {
                    startBtn.setAttribute('disabled', 'true');
                    startBtn.classList.add('opacity-50', 'cursor-not-allowed');
                }
            });

            // Workflow Starten
            startBtn.addEventListener('click', async () => {
                if (startBtn.disabled) return;

                const prompt = promptInput.value.trim();

                // UI Loading State
                startBtn.disabled = true;
                startBtn.classList.add('opacity-50', 'cursor-not-allowed');
                if (spinner) spinner.classList.remove('hidden');
                if (btnText) btnText.textContent = 'Verarbeitung läuft...';

                // Status Reset
                resetStatus();

                try {
                    const response = await fetch('../start-workflow-update.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json'
                        },
                        body: JSON.stringify({
                            run_id: runId,
                            image_id: imageId,
                            position: position,
                            action: 'edit',
                            userprompt: prompt
                        })
                    });

                    const result = await response.json();

                    if (response.ok && result.success) {
                        setStatus('Workflow gestartet. Wir prüfen regelmäßig auf deinen Entwurf...', 'info');
                        startPollingForEdit();
                    } else {
                        throw new Error(result.message || 'Unbekannter Fehler');
                    }
                } catch (error) {
                    console.error(error);
                    setStatus('Fehler: ' + error.message, 'error');

                    // Reset bei Fehler
                    startBtn.disabled = false;
                    startBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                    if (spinner) spinner.classList.add('hidden');
                    if (btnText) btnText.textContent = 'Workflow starten';
                }
            });

            saveBtn.addEventListener('click', async () => {
                if (saveBtn.disabled || !stagingId) {
                    setStatus('Kein Entwurf zum Speichern gefunden.', 'error');
                    return;
                }

                saveBtn.disabled = true;
                saveBtn.classList.add('opacity-50', 'cursor-not-allowed');
                resetStatus();
                setStatus('Speichern läuft...', 'info');

                try {
                    const response = await fetch('../api/publish-edit.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json'
                        },
                        body: JSON.stringify({ staging_id: stagingId })
                    });

                    const result = await response.json();

                    if (response.ok && result.ok) {
                        window.location.href = `../index.php?run_id=${encodeURIComponent(runId)}`;
                        return;
                    }

                    throw new Error(result.error || 'Speichern fehlgeschlagen');
                } catch (error) {
                    console.error(error);
                    setStatus('Fehler beim Speichern: ' + error.message, 'error');
                    saveBtn.disabled = false;
                    saveBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                }
            });
        });
    </script>
</body>
</html>
