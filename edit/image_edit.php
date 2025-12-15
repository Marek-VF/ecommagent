<?php
require_once __DIR__ . '/../auth/bootstrap.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

auth_require_login();

$config = auth_config();
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

require_once __DIR__ . '/../db.php';

$userId = $_SESSION['user']['id'] ?? null;
$imageId = isset($_GET['image_id']) ? (int) $_GET['image_id'] : 0;

$error = null;
$image = null;

// --- DATEN LADEN (Wie zuvor) ---
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

// URL Helper Logic (Wie zuvor)
$baseUrlConfig = $config['base_url'] ?? '';
$baseUrl = '';
if (is_string($baseUrlConfig) && $baseUrlConfig !== '') {
    $baseUrl = rtrim($baseUrlConfig, '/');
}
$resolvePublicPath = static function (?string $path) use ($baseUrl): ?string {
    if ($path === null) return null;
    $trimmed = trim($path);
    if ($trimmed === '') return null;
    if (!preg_match('#^https?://#i', $trimmed) && !str_starts_with($trimmed, '/')) {
        if ($baseUrl !== '') $trimmed = $baseUrl . '/' . ltrim($trimmed, '/');
        else $trimmed = '/' . ltrim($trimmed, '/');
    }
    return $trimmed;
};

$imageUrl = null;
if ($image !== null) {
    $imageUrl = $resolvePublicPath(is_string($image['url']) ? $image['url'] : null);
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ecom Studio – Bild bearbeiten</title>
    
    <script src="https://cdn.tailwindcss.com?plugins=forms,typography"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined" rel="stylesheet"/>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="../style.css">
    
    <style>
        /* Modernere Inputs überschreiben Standard-Styles falls nötig */
        .modern-textarea {
            min-height: 140px;
        }
    </style>
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
            <button type="button" class="sidebar-profile__trigger" data-profile-trigger aria-haspopup="true" aria-expanded="false">
                <span class="sidebar-profile__avatar" aria-hidden="true">
                    <?php echo htmlspecialchars((string) $userInitial, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                </span>
                <span class="sidebar-profile__info">
                    <span class="sidebar-profile__name">
                        <?php echo htmlspecialchars((string) $userDisplayName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                    </span>
                </span>
                <span class="sidebar-profile__caret" aria-hidden="true">
                    <span class="material-icons-outlined" style="font-size: 16px;">expand_more</span>
                </span>
            </button>
            <div id="sidebar-profile-menu" class="sidebar-profile-menu" role="menu" aria-hidden="true">
                <a class="profile-item" href="../settings/index.php" role="menuitem">Einstellungen</a>
                <a class="profile-item profile-item--danger" href="../auth/logout.php" role="menuitem">Abmelden</a>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <div class="app">
        
        <header class="app__header">
            <div class="flex items-center gap-4">
                <button type="button" id="history-toggle" class="p-2 -ml-2 text-gray-600 hover:text-gray-900 rounded-full hover:bg-black/5 transition-colors">
                    <span class="material-icons-outlined block">menu</span>
                </button>
                <h1>Ecom Studio</h1>
            </div>

            <div class="app__header-actions">
                <?php
                    $backUrl = '../index.php';
                    if (!empty($image['run_id'])) {
                        $backUrl .= '?run_id=' . (int)$image['run_id'];
                    }
                ?>
                <a href="<?php echo $backUrl; ?>" class="btn btn-secondary flex items-center gap-2">
                    <span class="material-icons-outlined text-lg">arrow_back</span>
                    <span>Zurück</span>
                </a>
            </div>
        </header>

        <main class="edit-main">
            <div class="w-full max-w-7xl mx-auto">

                <?php if ($error !== null): ?>
                    <div class="status-item status-item--error mb-6">
                        <span class="material-icons-outlined status-icon text-danger">error</span>
                        <p class="status-text"><?php echo htmlspecialchars($error, ENT_QUOTES); ?></p>
                    </div>
                <?php else: ?>

                    <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
                        
                        <div class="lg:col-span-4 flex flex-col gap-6">
                            <div class="edit-card">
                                <div class="edit-card__header">
                                    <p class="edit-card__title">Prompting</p>
                                </div>
                                <div class="edit-card__body">
                                    <div class="relative">
                                        <textarea 
                                            id="edit-prompt" 
                                            class="w-full bg-white border border-gray-200 focus:border-orange-500 focus:ring-1 focus:ring-orange-500 rounded-xl p-4 text-gray-700 text-base shadow-sm transition-all resize-none modern-textarea" 
                                            placeholder="Beschreibe deine Änderungswünsche...&#10;z.B. 'Hintergrund entfernen' oder 'Model lächeln lassen'"
                                        ></textarea>
                                        <div class="absolute bottom-3 right-3 text-xs text-gray-400 font-medium" id="char-count">0 Zeichen</div>
                                    </div>

                                    <div id="edit-status-message" class="hidden mt-4 p-3 rounded-lg text-sm font-medium"></div>

                                    <div class="mt-6">
                                        <button type="button" id="btn-start-edit" class="btn-primary w-full flex items-center justify-center gap-2" disabled
                                            data-run-id="<?php echo (int)$image['run_id']; ?>"
                                            data-image-id="<?php echo (int)$image['id']; ?>"
                                            data-position="<?php echo (int)$image['position']; ?>">
                                            
                                            <svg class="loading-spinner animate-spin h-5 w-5 text-white hidden" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                            </svg>
                                            <span class="btn-text">Workflow starten</span>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div class="bg-blue-50/50 border border-blue-100 rounded-xl p-4 flex gap-3">
                                <span class="material-icons-outlined text-blue-500">info</span>
                                <p class="text-sm text-blue-800 leading-relaxed">
                                    Du kannst das Bild iterativ bearbeiten. Starte den Workflow mehrmals, bis das Ergebnis passt. Erst beim Speichern wird es übernommen.
                                </p>
                            </div>
                        </div>

                        <div class="lg:col-span-8">
                            <div class="edit-card h-full flex flex-col">
                                <div class="edit-card__header flex justify-between items-center">
                                    <p class="edit-card__title">Bildvorschau</p>
                                    
                                    <button type="button" id="btn-cancel-edit" class="text-xs text-red-500 hover:text-red-700 font-medium hidden flex items-center gap-1">
                                        <span class="material-icons-outlined text-sm">delete</span>
                                        Entwurf verwerfen
                                    </button>
                                </div>

                                <div class="edit-card__body flex-1 bg-gray-50/50 flex items-center justify-center p-6 min-h-[400px]">
                                    <?php if ($imageUrl !== null): ?>
                                        <img 
                                            id="preview-image" 
                                            src="<?php echo htmlspecialchars($imageUrl, ENT_QUOTES); ?>" 
                                            alt="Vorschau" 
                                            class="max-w-full max-h-[600px] w-auto h-auto object-contain rounded-lg shadow-sm transition-opacity duration-300"
                                        >
                                    <?php else: ?>
                                        <div class="text-center text-gray-400">
                                            <span class="material-icons-outlined text-4xl mb-2">image_not_supported</span>
                                            <p>Kein Bild geladen</p>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="edit-card__footer border-t border-gray-100 p-4 flex justify-end">
                                    <button type="button" id="btn-save-edit" class="btn-primary px-8 flex items-center justify-center gap-2 opacity-50 cursor-not-allowed transition-all shadow-sm" disabled>
                                        <span class="material-icons-outlined text-lg">save</span>
                                        Speichern
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                <?php endif; ?>
            </div>
        </main>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const promptInput = document.getElementById('edit-prompt');
            const startBtn = document.getElementById('btn-start-edit');
            const saveBtn = document.getElementById('btn-save-edit');
            const cancelBtn = document.getElementById('btn-cancel-edit');
            const charCount = document.getElementById('char-count');
            const statusMsg = document.getElementById('edit-status-message');
            const spinner = startBtn ? startBtn.querySelector('.loading-spinner') : null;
            const btnText = startBtn ? startBtn.querySelector('.btn-text') : null;
            const previewImage = document.getElementById('preview-image');

            // --- HISTORY SIDEBAR STATE ---
            let historyOffset = 0;
            let historyIsLoading = false;
            let historyFullyLoaded = false;

            const historySidebar = document.getElementById('history-sidebar');
            const historyToggle = document.getElementById('history-toggle');
            const historyClose = document.getElementById('history-close');
            const historyList = document.getElementById('history-list');
            const profileTrigger = document.querySelector('[data-profile-trigger]');
            const profileMenu = document.getElementById('sidebar-profile-menu');
            const profileContainer = document.getElementById('sidebar-profile');

            const formatDate = (dateStr) => {
                if (!dateStr) return '';
                const date = new Date(dateStr);
                if (Number.isNaN(date.getTime())) return dateStr;
                return date.toLocaleString('de-DE', {
                    dateStyle: 'medium',
                    timeStyle: 'short',
                });
            };

            const renderRuns = (runs, append = false) => {
                if (!historyList || !Array.isArray(runs)) return;
                if (!append) {
                    historyList.innerHTML = '';
                }

                runs.forEach((run) => {
                    const title = (typeof run.title === 'string' && run.title.trim() !== '')
                        ? run.title.trim()
                        : `Run #${run.id ?? ''}`;
                    const dateLabel = formatDate(run.started_at || run.finished_at || run.date || run.created_at);

                    const item = document.createElement('li');
                    item.className = 'history-list__item';
                    item.innerHTML = `
                        <div class="history-list__item-title">${title}</div>
                        <div class="history-list__item-subtitle">${dateLabel || ''}</div>
                    `;
                    item.addEventListener('click', () => {
                        window.location.href = '../index.php?run_id=' + run.id;
                    });
                    historyList.appendChild(item);
                });
            };

            const fetchRuns = async (append = false) => {
                if (!historyList || historyIsLoading || historyFullyLoaded) return;

                historyIsLoading = true;

                try {
                    const response = await fetch(`../api/get-runs.php?limit=20&offset=${historyOffset}`);
                    const data = await response.json();
                    const runs = Array.isArray(data) ? data : Array.isArray(data?.data) ? data.data : [];

                    if (runs.length < 20) {
                        historyFullyLoaded = true;
                    }

                    historyOffset += runs.length;
                    renderRuns(runs, append);
                } catch (error) {
                    console.error('Fehler beim Laden der Verläufe', error);
                } finally {
                    historyIsLoading = false;
                }
            };

            const setupSidebar = () => {
                if (historyToggle && historySidebar) {
                    historyToggle.addEventListener('click', () => {
                        historySidebar.classList.add('history-sidebar--open');
                        historySidebar.setAttribute('aria-hidden', 'false');

                        if (historyList && historyList.children.length === 0) {
                            fetchRuns();
                        }
                    });
                }

                if (historyClose && historySidebar) {
                    historyClose.addEventListener('click', () => {
                        historySidebar.classList.remove('history-sidebar--open');
                        historySidebar.setAttribute('aria-hidden', 'true');
                    });
                }

                if (historyList) {
                    historyList.addEventListener('scroll', () => {
                        if (historyList.scrollTop + historyList.clientHeight >= historyList.scrollHeight - 50) {
                            fetchRuns(true);
                        }
                    });
                }

                const closeProfileMenu = () => {
                    if (!profileContainer) return;
                    profileContainer.classList.remove('open');
                    if (profileTrigger) profileTrigger.setAttribute('aria-expanded', 'false');
                    if (profileMenu) profileMenu.setAttribute('aria-hidden', 'true');
                };

                if (profileTrigger && profileContainer) {
                    profileTrigger.addEventListener('click', (event) => {
                        event.stopPropagation();
                        const isOpen = profileContainer.classList.toggle('open');
                        profileTrigger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                        if (profileMenu) profileMenu.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
                    });

                    document.addEventListener('click', (event) => {
                        if (profileContainer && !profileContainer.contains(event.target)) {
                            closeProfileMenu();
                        }
                    });
                }
            };

            setupSidebar();

            let pollInterval = null;
            let stagingId = null;
            let createdImageFilename = null;

            if (!promptInput || !startBtn || !saveBtn) return;

            const runId = startBtn.dataset.runId;
            const position = startBtn.dataset.position;
            // Chain-of-Edit: Wir merken uns immer die ID des zuletzt sichtbaren Bildes
            let currentActiveImageId = parseInt(startBtn.dataset.imageId || 0, 10);

            // --- UI HELPER ---
            const resetStatus = () => {
                statusMsg.className = 'hidden mt-4 p-3 rounded-lg text-sm font-medium';
                statusMsg.textContent = '';
            };

            const setStatus = (message, type = 'info') => {
                statusMsg.textContent = message;
                statusMsg.className = 'mt-4 p-3 rounded-lg text-sm font-medium animate-fade-in';
                statusMsg.classList.remove('hidden');
                
                if (type === 'success') statusMsg.classList.add('bg-green-50', 'text-green-800', 'border', 'border-green-100');
                else if (type === 'error') statusMsg.classList.add('bg-red-50', 'text-red-800', 'border', 'border-red-100');
                else statusMsg.classList.add('bg-blue-50', 'text-blue-800', 'border', 'border-blue-100');
            };

            const stopPolling = () => {
                if (pollInterval !== null) {
                    clearInterval(pollInterval);
                    pollInterval = null;
                }
            };

            // --- POLLING & DATA HANDLER ---
            const onNewImageReceived = (image) => {
                if (!image) return;

                const isError = image.is_error === true || image.data?.is_error === true;
                if (isError) {
                    const message = (typeof image.message === 'string' && image.message.trim() !== '')
                        ? image.message
                        : 'Es ist ein Fehler aufgetreten.';

                    setStatus(message, 'error');

                    startBtn.disabled = false;
                    startBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                    if (spinner) spinner.classList.add('hidden');
                    if (btnText) btnText.textContent = 'Workflow starten';
                    promptInput.disabled = false;

                    if (saveBtn) {
                        if (stagingId) {
                            saveBtn.disabled = false;
                            saveBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                            saveBtn.classList.add('hover:shadow-md');
                        } else {
                            saveBtn.disabled = true;
                            saveBtn.classList.add('opacity-50', 'cursor-not-allowed');
                            saveBtn.classList.remove('hover:shadow-md');
                        }
                    }

                    return;
                }

                // State Update
                stagingId = image.id ?? null;
                if (image.id) currentActiveImageId = image.id; // Chain-Update!

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

                // Buttons aktivieren
                saveBtn.disabled = false;
                saveBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                saveBtn.classList.add('hover:shadow-md');
                
                if(cancelBtn) cancelBtn.classList.remove('hidden');

                // Start Button Reset
                startBtn.disabled = false;
                startBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                if (spinner) spinner.classList.add('hidden');
                if (btnText) btnText.textContent = 'Workflow starten';
                
                // Input entsperren
                promptInput.disabled = false;

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

            startBtn.addEventListener('click', async () => {
                if (startBtn.disabled) return;

                const prompt = promptInput.value.trim();
                
                // Baseline Check (Min-ID Ermittlung)
                let pollingThreshold = 0;
                try {
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
                promptInput.disabled = true;
                
                if (spinner) spinner.classList.remove('hidden');
                if (btnText) btnText.textContent = 'Verarbeitung läuft...';
                resetStatus();

                try {
                    const response = await fetch('../start-workflow-update.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                        body: JSON.stringify({
                            run_id: runId,
                            image_id: currentActiveImageId, // Wir starten vom aktuellen Bild (live oder staging)
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
                    promptInput.disabled = false;
                    if (spinner) spinner.classList.add('hidden');
                    if (btnText) btnText.textContent = 'Workflow starten';
                }
            });

            saveBtn.addEventListener('click', async () => {
                if (saveBtn.disabled || !stagingId) return;

                saveBtn.disabled = true;
                saveBtn.innerHTML = 'Speichere...';
                
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
                    setStatus('Fehler: ' + error.message, 'error');
                    saveBtn.disabled = false;
                    saveBtn.innerHTML = 'Speichern';
                }
            });

            if(cancelBtn) {
                cancelBtn.addEventListener('click', async () => {
                    if (!createdImageFilename) return;
                    if(!confirm('Entwurf verwerfen?')) return;

                    try {
                        await fetch('../delete_image.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({ run_id: runId, filename: createdImageFilename })
                        });
                        window.location.reload();
                    } catch(e) { console.error(e); }
                });
            }
        });
    </script>
</body>
</html>
