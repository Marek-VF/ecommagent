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
    <style>
        body { background-color: #F3F4F6; font-family: 'Inter', sans-serif; }
        .btn-header {
            padding: 0.5rem 1rem;
            border-radius: 0.75rem;
            font-size: 0.875rem;
            font-weight: 500;
            color: #4B5563;
            background-color: #FFFFFF;
            border: 1px solid #E5E7EB;
            transition: background-color 150ms ease;
        }
        .btn-header:hover { background-color: #F3F4F6; }
    </style>
</head>
<body class="h-screen flex flex-col overflow-hidden">

    <header class="bg-white border-b border-gray-200 h-16 shrink-0 z-20">
        <div class="h-full px-6 flex items-center justify-between">
            <div class="flex items-center gap-4">
                <a href="../index.php" class="p-2 -ml-2 text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded-full transition-colors" title="Zurück zur Historie">
                    <span class="material-icons-outlined block">menu</span>
                </a>
                <span class="font-bold text-xl text-gray-800 tracking-tight">Ecom Studio</span>
            </div>

            <div>
                <?php
                    $backUrl = '../index.php';
                    if (!empty($image['run_id'])) {
                        $backUrl .= '?run_id=' . (int)$image['run_id'];
                    }
                ?>
                <a href="<?php echo $backUrl; ?>" class="btn-header flex items-center gap-2">
                    <span class="material-icons-outlined text-lg">arrow_back</span>
                    <span>Zurück</span>
                </a>
            </div>
        </div>
    </header>

    <main class="flex-1 overflow-y-auto p-6">
        <div class="max-w-7xl mx-auto h-full">
            
            <?php if ($error !== null): ?>
                <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-xl flex items-center gap-3">
                    <span class="material-icons-outlined">error</span>
                    <p><?php echo htmlspecialchars($error, ENT_QUOTES); ?></p>
                </div>
            <?php else: ?>

                <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 h-full">
                    
                    <div class="lg:col-span-4 flex flex-col gap-6">
                        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                            <h2 class="text-sm font-semibold text-gray-400 uppercase tracking-wider mb-4">Prompting</h2>
                            
                            <div class="relative">
                                <textarea 
                                    id="edit-prompt" 
                                    class="w-full bg-gray-50 border-0 focus:ring-2 focus:ring-orange-500 rounded-xl p-4 text-gray-800 placeholder-gray-400 text-base leading-relaxed resize-none transition-shadow shadow-sm min-h-[160px]" 
                                    placeholder="Beschreibe deine Änderungswünsche...&#10;z.B. 'Hintergrund entfernen' oder 'Model lächeln lassen'"
                                ></textarea>
                                <div class="absolute bottom-3 right-3 text-xs text-gray-400 font-medium" id="char-count">0 Zeichen</div>
                            </div>

                            <div id="edit-status-message" class="hidden mt-4 p-3 rounded-lg text-sm font-medium"></div>

                            <div class="mt-6">
                                <button type="button" id="btn-start-edit" class="btn-primary w-full py-3 rounded-xl flex items-center justify-center gap-2 shadow-md hover:shadow-lg transition-all" disabled
                                    data-run-id="<?php echo isset($image['run_id']) ? (int)$image['run_id'] : 0; ?>"
                                    data-image-id="<?php echo isset($image['id']) ? (int)$image['id'] : 0; ?>"
                                    data-position="<?php echo isset($image['position']) ? (int)$image['position'] : 0; ?>"
                                >
                                    <svg class="loading-spinner animate-spin h-5 w-5 text-white hidden" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    <span class="btn-text font-medium">Workflow starten</span>
                                </button>
                            </div>
                        </div>
                        
                        <div class="bg-blue-50 rounded-xl p-4 border border-blue-100">
                            <div class="flex items-start gap-3">
                                <span class="material-icons-outlined text-blue-500 text-xl">info</span>
                                <p class="text-sm text-blue-700 leading-relaxed">
                                    Du kannst das Bild iterativ bearbeiten. Starte den Workflow mehrmals, bis das Ergebnis passt. Erst beim Speichern wird das Ergebnis übernommen.
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="lg:col-span-8 flex flex-col">
                        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 flex flex-col h-full overflow-hidden relative">
                            <div class="px-6 py-4 border-b border-gray-50 flex justify-between items-center bg-white">
                                <h2 class="text-sm font-semibold text-gray-400 uppercase tracking-wider">Bildvorschau</h2>
                                <button type="button" id="btn-cancel-edit" class="text-xs text-red-500 hover:text-red-700 font-medium hidden">
                                    Entwurf verwerfen
                                </button>
                            </div>

                            <div class="flex-1 bg-gray-50 flex items-center justify-center p-8 overflow-hidden relative group">
                                <?php if ($imageUrl !== null): ?>
                                    <img 
                                        id="preview-image" 
                                        src="<?php echo htmlspecialchars($imageUrl, ENT_QUOTES); ?>" 
                                        alt="Originalbild" 
                                        class="max-w-full max-h-[600px] w-auto h-auto object-contain rounded-lg shadow-sm transition-opacity duration-300"
                                    >
                                <?php else: ?>
                                    <div class="text-center text-gray-400">
                                        <span class="material-icons-outlined text-4xl mb-2">image_not_supported</span>
                                        <p>Kein Bild geladen</p>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="px-6 py-4 border-t border-gray-100 bg-white flex justify-end">
                                <button type="button" id="btn-save-edit" class="btn-primary px-8 py-2.5 rounded-xl font-medium shadow-md transition-all opacity-50 cursor-not-allowed" disabled>
                                    Speichern
                                </button>
                            </div>
                        </div>
                    </div>

                </div>
            <?php endif; ?>
        </div>
    </main>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // Elemente holen
            const promptInput = document.getElementById('edit-prompt');
            const startBtn = document.getElementById('btn-start-edit');
            const saveBtn = document.getElementById('btn-save-edit');
            const cancelBtn = document.getElementById('btn-cancel-edit');
            const charCount = document.getElementById('char-count');
            const statusMsg = document.getElementById('edit-status-message');
            const spinner = startBtn ? startBtn.querySelector('.loading-spinner') : null;
            const btnText = startBtn ? startBtn.querySelector('.btn-text') : null;
            const previewImage = document.getElementById('preview-image');

            // State
            let pollInterval = null;
            let stagingId = null;
            let createdImageFilename = null;

            if (!promptInput || !startBtn || !saveBtn) return;

            // Daten aus PHP Attributen lesen
            const runId = startBtn.dataset.runId;
            const position = startBtn.dataset.position;
            // Initial ID (kann Original oder Staging sein, hier starten wir mit Original)
            let currentActiveImageId = parseInt(startBtn.dataset.imageId || 0, 10);

            // --- HELPER FUNKTIONEN ---

            const resetStatus = () => {
                statusMsg.className = 'hidden mt-4 p-3 rounded-lg text-sm font-medium';
                statusMsg.textContent = '';
            };

            const setStatus = (message, type = 'info') => {
                statusMsg.textContent = message;
                statusMsg.className = 'mt-4 p-3 rounded-lg text-sm font-medium animate-fade-in';
                statusMsg.classList.remove('hidden');

                if (type === 'success') {
                    statusMsg.classList.add('bg-green-50', 'text-green-700', 'border', 'border-green-100');
                } else if (type === 'error') {
                    statusMsg.classList.add('bg-red-50', 'text-red-700', 'border', 'border-red-100');
                } else {
                    statusMsg.classList.add('bg-blue-50', 'text-blue-700', 'border', 'border-blue-100');
                }
            };

            // --- POLLING LOGIK ---

            const stopPolling = () => {
                if (pollInterval !== null) {
                    clearInterval(pollInterval);
                    pollInterval = null;
                }
            };

            const onNewImageReceived = (image) => {
                if (!image) return;

                // State Updates
                stagingId = image.id ?? null;
                if (image.id) currentActiveImageId = image.id;
                
                if (image.url) {
                    createdImageFilename = image.url.substring(image.url.lastIndexOf('/') + 1);
                    if (previewImage) {
                        previewImage.style.opacity = '0.5';
                        setTimeout(() => {
                            previewImage.src = image.url;
                            previewImage.style.opacity = '1';
                        }, 200);
                    }
                }

                // UI Updates: Buttons aktivieren
                saveBtn.disabled = false;
                saveBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                saveBtn.classList.add('hover:brightness-95', 'hover:shadow-lg'); // Hover effekt dazu
                
                if(cancelBtn) cancelBtn.classList.remove('hidden');

                // Start Button Reset
                startBtn.disabled = false;
                startBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                if (spinner) spinner.classList.add('hidden');
                if (btnText) btnText.textContent = 'Workflow starten';

                setStatus('Vorschau aktualisiert. Gefällt es dir?', 'success');
            };

            const startPolling = (pollRunId, minId) => {
                stopPolling();
                pollInterval = window.setInterval(async () => {
                    try {
                        const response = await fetch(`../api/check-edit-polling.php?run_id=${encodeURIComponent(pollRunId)}&min_id=${encodeURIComponent(minId)}&t=${Date.now()}`);
                        const data = await response.json();

                        if (response.ok && data.ok && data.found) {
                            stopPolling();
                            onNewImageReceived(data.image ?? null);
                        }
                    } catch (error) {
                        console.error('Polling failed', error);
                    }
                }, 2000);
            };

            // --- EVENT LISTENERS ---

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

            // Workflow Start
            startBtn.addEventListener('click', async () => {
                if (startBtn.disabled) return;

                const prompt = promptInput.value.trim();
                
                // Baseline Check
                let pollingThreshold = 0;
                try {
                    // Wir fragen nach dem aktuellsten Staging Bild VOR dem Start
                    const checkRes = await fetch(`../api/check-edit-polling.php?run_id=${runId}&min_id=0&t=${Date.now()}`);
                    const checkData = await checkRes.json();
                    if (checkData.ok && checkData.found && checkData.image) {
                        pollingThreshold = checkData.image.id;
                    }
                } catch(e) { console.warn('Baseline check failed', e); }

                // UI Sperren
                startBtn.disabled = true;
                startBtn.classList.add('opacity-50', 'cursor-not-allowed');
                saveBtn.disabled = true;
                saveBtn.classList.add('opacity-50', 'cursor-not-allowed');
                
                if (spinner) spinner.classList.remove('hidden');
                if (btnText) btnText.textContent = 'Verarbeitung läuft...';
                resetStatus();

                try {
                    const response = await fetch('../start-workflow-update.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                        body: JSON.stringify({
                            run_id: runId,
                            image_id: currentActiveImageId,
                            position: position,
                            action: 'edit',
                            userprompt: prompt
                        })
                    });

                    const result = await response.json();

                    if (response.ok && result.success) {
                        setStatus('Workflow gestartet. Bitte warten...', 'info');
                        startPolling(runId, pollingThreshold);
                    } else {
                        throw new Error(result.message || 'Fehler beim Starten');
                    }
                } catch (error) {
                    console.error(error);
                    setStatus('Fehler: ' + error.message, 'error');
                    startBtn.disabled = false;
                    startBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                    if (spinner) spinner.classList.add('hidden');
                    if (btnText) btnText.textContent = 'Workflow starten';
                }
            });

            // Speichern
            saveBtn.addEventListener('click', async () => {
                if (saveBtn.disabled || !stagingId) return;

                saveBtn.disabled = true;
                saveBtn.innerHTML = 'Speichere...'; // Simpler Text Change
                
                try {
                    const response = await fetch('../api/publish-edit.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ staging_id: stagingId })
                    });
                    const result = await response.json();

                    if (response.ok && result.ok) {
                        window.location.href = `../index.php?run_id=${encodeURIComponent(runId)}`;
                    } else {
                        throw new Error(result.error || 'Speichern fehlgeschlagen');
                    }
                } catch (error) {
                    setStatus('Fehler beim Speichern: ' + error.message, 'error');
                    saveBtn.disabled = false;
                    saveBtn.innerHTML = 'Speichern';
                }
            });

            // Entwurf verwerfen
            if(cancelBtn) {
                cancelBtn.addEventListener('click', async () => {
                    if (!createdImageFilename) return;
                    if(!confirm('Möchtest du den aktuellen Entwurf wirklich verwerfen?')) return;

                    try {
                        await fetch('../delete_image.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({ run_id: runId, filename: createdImageFilename })
                        });
                        window.location.reload(); // Reload um auf Originalzustand zu kommen
                    } catch(e) { console.error(e); }
                });
            }
        });
    </script>
</body>
</html>
