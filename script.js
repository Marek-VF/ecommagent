const dropZone = document.getElementById('drop-zone');
const fileInput = document.getElementById('file-input');
const selectFileButton = document.getElementById('select-file');
const previewList = document.getElementById('upload-previews');
const lightbox = document.getElementById('lightbox');
const lightboxImage = lightbox.querySelector('.lightbox__image');
const lightboxClose = lightbox.querySelector('.lightbox__close');
const newButton = document.getElementById('btn-new');
const workflowFeedback = document.getElementById('workflow-feedback');
const articleNameInput = document.getElementById('article-name');
const articleDescriptionInput = document.getElementById('article-description');
const HISTORY_SIDEBAR = document.getElementById('history-sidebar');
const HISTORY_LIST = document.getElementById('history-list');
const HISTORY_TOGGLE = document.getElementById('history-toggle');
const HISTORY_CLOSE = document.getElementById('history-close');
const SIDEBAR_PROFILE = document.getElementById('sidebar-profile');
const SIDEBAR_PROFILE_MENU = document.getElementById('sidebar-profile-menu');
const SIDEBAR_PROFILE_TRIGGER =
    SIDEBAR_PROFILE && SIDEBAR_PROFILE instanceof HTMLElement
        ? SIDEBAR_PROFILE.querySelector('[data-profile-trigger]')
        : null;

const WORKFLOW_FEEDBACK_VISIBLE_CLASS = 'workflow-feedback--visible';
const WORKFLOW_FEEDBACK_ERROR_CLASS = 'workflow-feedback--error';
const WORKFLOW_FEEDBACK_SUCCESS_CLASS = 'workflow-feedback--success';
const WORKFLOW_FEEDBACK_INFO_CLASS = 'workflow-feedback--info';

window.currentRunId = Number.isFinite(Number(window.currentRunId)) && Number(window.currentRunId) > 0
    ? Number(window.currentRunId)
    : null;
window.currentUserId = Number.isFinite(Number(window.currentUserId)) && Number(window.currentUserId) > 0
    ? Number(window.currentUserId)
    : null;

let isPolling = false;
let pollInterval = null;
let workflowIsRunning = false;
let activeRunId = null;
let profileMenuInitialized = false;

const RUNS_ENDPOINT = 'api/get-runs.php';
const RUN_DETAILS_ENDPOINT = 'api/get-run-details.php';

const uploadEndpoint = 'upload.php';
const POLLING_INTERVAL = 2000;
const DATA_ENDPOINT = 'api/get-latest-item.php';

const SCAN_OVERLAY_SELECTOR = '.scan-overlay';
const SCAN_OVERLAY_ACTIVE_CLASS = 'active';

const setScanOverlayActive = (isActive) => {
    if (!previewList) {
        return;
    }

    const overlays = previewList.querySelectorAll(SCAN_OVERLAY_SELECTOR);
    let activated = false;

    overlays.forEach((overlay) => {
        if (!(overlay instanceof HTMLElement)) {
            return;
        }

        const shouldActivate = Boolean(isActive) && !activated;
        overlay.classList.toggle(SCAN_OVERLAY_ACTIVE_CLASS, shouldActivate);

        if (shouldActivate) {
            activated = true;
        }
    });
};

const toAbsoluteUrl = (path) => {
    if (path === undefined || path === null) {
        return '';
    }

    const raw = String(path).trim();
    if (raw === '') {
        return '';
    }

    if (/^(?:https?:)?\/\//i.test(raw) || raw.startsWith('/')) {
        return raw;
    }

    const configBase =
        window.APP_CONFIG && typeof window.APP_CONFIG.base_url === 'string'
            ? window.APP_CONFIG.base_url.trim()
            : '';
    const base = configBase || (typeof window.location === 'object' ? window.location.origin : '');

    if (base) {
        return `${base.replace(/\/+$/, '')}/${raw.replace(/^\/+/, '')}`;
    }

    return `/${raw.replace(/^\/+/, '')}`;
};

const pad2 = (value) => String(value).padStart(2, '0');

const parseDateValue = (value) => {
    if (typeof value !== 'string') {
        return null;
    }

    const trimmed = value.trim();
    if (trimmed === '') {
        return null;
    }

    let candidate = new Date(trimmed);
    if (!Number.isNaN(candidate.getTime())) {
        return candidate;
    }

    const normalized = trimmed.replace(' ', 'T');
    candidate = new Date(normalized);
    if (!Number.isNaN(candidate.getTime())) {
        return candidate;
    }

    return null;
};

const parseFirstDateValue = (...values) => {
    for (const value of values) {
        const parsed = parseDateValue(value);
        if (parsed) {
            return parsed;
        }
    }

    return null;
};

const formatDateLabel = (date) => {
    if (!(date instanceof Date) || Number.isNaN(date.getTime())) {
        return '';
    }

    return `${pad2(date.getDate())}.${pad2(date.getMonth() + 1)}.${date.getFullYear()}`;
};

const formatDateTimeLabel = (date) => {
    if (!(date instanceof Date) || Number.isNaN(date.getTime())) {
        return '';
    }

    return `${formatDateLabel(date)} ${pad2(date.getHours())}:${pad2(date.getMinutes())}`;
};

const extractRunDates = (run) => {
    if (!run || typeof run !== 'object') {
        return { started: null, finished: null };
    }

    const started = parseFirstDateValue(run.started_at_iso, run.started_at);
    const finished = parseFirstDateValue(run.finished_at_iso, run.finished_at);

    return { started, finished };
};

// merkt sich, welche Bild-URLs zuletzt im UI bekannt waren
const lastKnownImages = {
    image_1: null,
    image_2: null,
    image_3: null,
};

const FADE_IN_CLASS = 'fade-in';
const FADE_IN_VISIBLE_CLASS = 'is-visible';
const FADE_IN_HANDLER_KEY = '__fadeInHandler';

const runOnNextFrame = (callback) => {
    if (typeof callback !== 'function') {
        return;
    }

    if (typeof window !== 'undefined' && typeof window.requestAnimationFrame === 'function') {
        window.requestAnimationFrame(callback);
        return;
    }

    setTimeout(callback, 16);
};

const applyFadeInAnimation = (element) => {
    if (!(element instanceof HTMLElement)) {
        return;
    }

    const revealElement = () => {
        runOnNextFrame(() => {
            element.classList.add(FADE_IN_VISIBLE_CLASS);
        });
    };

    element.classList.add(FADE_IN_CLASS);
    element.classList.remove(FADE_IN_VISIBLE_CLASS);

    if (element.tagName !== 'IMG') {
        revealElement();
        return;
    }

    const img = element;
    const previousHandler = img[FADE_IN_HANDLER_KEY];

    if (typeof previousHandler === 'function') {
        img.removeEventListener('load', previousHandler);
        img.removeEventListener('error', previousHandler);
    }

    const handleLoadOrError = () => {
        img.removeEventListener('load', handleLoadOrError);
        img.removeEventListener('error', handleLoadOrError);
        img[FADE_IN_HANDLER_KEY] = null;
        revealElement();
    };

    img[FADE_IN_HANDLER_KEY] = handleLoadOrError;

    if (img.complete) {
        handleLoadOrError();
        return;
    }

    img.addEventListener('load', handleLoadOrError);
    img.addEventListener('error', handleLoadOrError);
};

let selectedHistoryRunId = null;

const sanitizeLatestItemString = (value) => {
    if (typeof value === 'string') {
        return value;
    }

    if (value === null || value === undefined) {
        return '';
    }

    return String(value);
};

const resolveLatestStatusMessage = (payload) => {
    if (!payload || typeof payload !== 'object') {
        return '';
    }

    const candidates = [payload.message, payload.status];

    for (const candidate of candidates) {
        if (typeof candidate === 'string') {
            const trimmed = candidate.trim();
            if (trimmed !== '') {
                return trimmed;
            }
        }
    }

    return '';
};

const applyLatestItemData = (payload) => {
    const data = payload && typeof payload === 'object' ? payload : {};

    if (articleNameInput) {
        const value = sanitizeLatestItemString(data.product_name).trim();
        if (value !== '') {
            articleNameInput.value = value;
        }
    }

    if (articleDescriptionInput) {
        const value = sanitizeLatestItemString(data.product_description).trim();
        if (value !== '') {
            articleDescriptionInput.value = value;
        }
    }

};

const normalizeLatestImagesObject = (input) => {
    const result = {};

    if (!input) {
        return result;
    }

    const assignToKey = (key, url) => {
        if (!key || typeof key !== 'string') {
            return;
        }

        const normalizedKey = key.trim().toLowerCase();
        if (!/^image_?[1-3]$/.test(normalizedKey)) {
            return;
        }

        const normalizedUrl = typeof url === 'string' ? url.trim() : '';
        if (normalizedUrl === '') {
            return;
        }

        const slotKey = `image_${normalizedKey.replace(/[^1-3]/g, '')}`;
        if (!Object.prototype.hasOwnProperty.call(result, slotKey)) {
            result[slotKey] = normalizedUrl;
        }
    };

    if (Array.isArray(input)) {
        input.forEach((entry) => {
            if (!entry || typeof entry !== 'object') {
                return;
            }

            const url = typeof entry.url === 'string' ? entry.url.trim() : '';
            if (url === '') {
                return;
            }

            if (Number.isFinite(Number(entry.position))) {
                const numeric = Number(entry.position);
                if (numeric >= 1 && numeric <= 3) {
                    const key = `image_${numeric}`;
                    if (!Object.prototype.hasOwnProperty.call(result, key)) {
                        result[key] = url;
                    }
                    return;
                }
            }

            if (typeof entry.slot === 'string') {
                assignToKey(entry.slot, url);
                return;
            }

            if (typeof entry.key === 'string') {
                assignToKey(entry.key, url);
            }
        });

        return result;
    }

    if (typeof input === 'object') {
        Object.entries(input).forEach(([key, value]) => {
            assignToKey(key, value);
        });
    }

    return result;
};

const mapLatestItemPayloadToLegacy = (payload) => {
    const normalized = payload && typeof payload === 'object' ? { ...payload } : {};

    if (Object.prototype.hasOwnProperty.call(normalized, 'product_name')) {
        normalized.product_name = sanitizeLatestItemString(normalized.product_name).trim();
    }

    if (Object.prototype.hasOwnProperty.call(normalized, 'product_description')) {
        normalized.product_description = sanitizeLatestItemString(normalized.product_description);
    }

    const statusMessage = resolveLatestStatusMessage(normalized);
    if (statusMessage) {
        normalized.status_message = statusMessage;
    }

    if (!Object.prototype.hasOwnProperty.call(normalized, 'isrunning')) {
        normalized.isrunning = false;
    }

    const imageMap = normalizeLatestImagesObject(payload && typeof payload === 'object' ? payload.images : null);
    normalized.images = imageMap;

    gallerySlotKeys.forEach((key) => {
        const value = imageMap[key];
        if (typeof value === 'string') {
            const trimmed = value.trim();
            if (trimmed !== '') {
                normalized[key] = trimmed;
            }
        }
    });

    return normalized;
};

const updateUiWithData = (payload) => {
    const data = payload && typeof payload === 'object' ? { ...payload } : {};
    const imageMap = normalizeLatestImagesObject(payload && typeof payload === 'object' ? payload.images : null);
    data.images = imageMap;

    applyLatestItemData(data);
    const normalized = mapLatestItemPayloadToLegacy(data);
    updateInterfaceFromData(normalized);

    if (data.images && typeof data.images === 'object') {
        gallerySlots.forEach((slot) => {
            const slotKey = slot?.key;
            if (!slotKey) {
                return;
            }

            const src = data.images[slotKey];

            if (typeof src === 'string' && src.trim() !== '') {
                const resolvedSrc = toAbsoluteUrl(src);
                if (!resolvedSrc) {
                    clearSlotContent(slot);
                    setSlotLoadingState(slot, false);
                    lastKnownImages[slotKey] = null;
                    return;
                }

                setSlotImageSource(slot, resolvedSrc);
                lastKnownImages[slotKey] = resolvedSrc;
            }
        });
    }

    return normalized;
};

const appConfig = window.APP_CONFIG || {};
const assetConfig = appConfig.assets || {};
const DEFAULT_ASSET_BASE = '/assets';

const resolveAssetBase = () => {
    const candidate = assetConfig.base || appConfig.assetBase;
    if (typeof candidate === 'string' && candidate.trim() !== '') {
        const normalized = candidate.replace(/\/+$/, '');
        return normalized !== '' ? normalized : DEFAULT_ASSET_BASE;
    }

    return DEFAULT_ASSET_BASE;
};

const assetBase = resolveAssetBase();
const PLACEHOLDER_SRC = assetConfig.placeholder || `${assetBase}/placeholder.png`;
const OVERLAY_SRC =
    assetConfig.loading_overlay ||
    assetConfig.loadingOverlay ||
    assetConfig.overlay ||
    assetConfig.pulse ||
    assetConfig.loading ||
    `${assetBase}/pulse.svg`;
const gallerySlotKeys = ['image_1', 'image_2', 'image_3'];

const gallerySlots = gallerySlotKeys
    .map((key, index) => {
        const container = document.querySelector(`[data-slot="${key}"]`);
        if (!container) {
            return null;
        }

        const placeholder = container.querySelector('[data-role="placeholder"]');
        const content = container.querySelector('[data-role="content"]');
        const overlay = container.querySelector('[data-role="overlay"]');

        const placeholderSrc = (container.dataset.placeholder || '').trim() || PLACEHOLDER_SRC;
        container.dataset.placeholder = placeholderSrc;
        container.dataset.currentSrc = '';
        container.dataset.hasContent = 'false';
        container.dataset.isLoading = 'false';

        if (placeholder) {
            placeholder.src = placeholderSrc;
        }

        if (content) {
            content.removeAttribute('src');
            content.alt = content.alt || `Produktbild ${index + 1}`;
        }

        if (overlay) {
            overlay.src = OVERLAY_SRC;
        }

        container.setAttribute('tabindex', '0');

        return {
            key,
            index,
            container,
            placeholder,
            content,
            overlay,
        };
    })
    .filter(Boolean);

const placeholderDimensions = appConfig.placeholderDimensions || null;

if (placeholderDimensions && placeholderDimensions.width && placeholderDimensions.height) {
    document.documentElement.style.setProperty(
        '--gallery-item-aspect-ratio',
        `${placeholderDimensions.width} / ${placeholderDimensions.height}`,
    );
}

const getPlaceholderForSlot = (slot) => {
    if (!slot || !slot.container) {
        return PLACEHOLDER_SRC;
    }

    const placeholder = slot.container.dataset?.placeholder;
    if (typeof placeholder === 'string' && placeholder.trim() !== '') {
        return placeholder.trim();
    }

    return PLACEHOLDER_SRC;
};

const ensurePlaceholderForSlot = (slot) => {
    if (!slot || !slot.placeholder) {
        return;
    }

    const placeholderSrc = getPlaceholderForSlot(slot);
    if (slot.placeholder.src !== placeholderSrc) {
        slot.placeholder.src = placeholderSrc;
    }
};

const setPulseState = (slot, shouldPulse) => {
    if (!slot || !slot.container) {
        return;
    }

    if (shouldPulse) {
        slot.container.classList.add('is-pulsing');
    } else {
        slot.container.classList.remove('is-pulsing');
    }
};

const clearSlotContent = (slot) => {
    if (!slot || !slot.container) {
        return;
    }

    slot.container.dataset.hasContent = 'false';
    slot.container.dataset.currentSrc = '';

    if (slot.content) {
        slot.content.removeAttribute('src');
    }

    ensurePlaceholderForSlot(slot);
};

const setSlotLoadingState = (slot, loading) => {
    if (!slot || !slot.container) {
        return;
    }

    slot.container.dataset.isLoading = loading ? 'true' : 'false';
    setPulseState(slot, Boolean(loading));

    if (slot.overlay && slot.overlay.src !== OVERLAY_SRC) {
        slot.overlay.src = OVERLAY_SRC;
    }

    if (loading) {
        ensurePlaceholderForSlot(slot);
    }
};

const setSlotImageSource = (slot, src) => {
    if (!slot || !slot.container || !src) {
        return;
    }

    const sanitized = String(src).trim();
    if (sanitized === '') {
        return;
    }

    const resolved = toAbsoluteUrl(sanitized);
    if (!resolved) {
        return;
    }

    if (slot.container.dataset.currentSrc === resolved && slot.container.dataset.hasContent === 'true') {
        slot.container.dataset.isLoading = 'false';
        return;
    }

    slot.container.dataset.currentSrc = resolved;
    slot.container.dataset.hasContent = 'true';
    slot.container.dataset.isLoading = 'false';
    setPulseState(slot, false);

    if (slot.content) {
        slot.content.src = resolved;
        applyFadeInAnimation(slot.content);
    }
};

const getSlotPreviewData = (slot) => {
    if (!slot || !slot.container) {
        return { src: PLACEHOLDER_SRC, alt: 'Bildvorschau' };
    }

    const hasContent = slot.container.dataset.hasContent === 'true' && slot.content && slot.content.src;
    const src = hasContent && slot.content ? slot.content.src : getPlaceholderForSlot(slot);
    const alt = hasContent && slot.content
        ? slot.content.alt || `Produktbild ${slot.index + 1}`
        : (slot.placeholder?.alt || `Platzhalter ${slot.index + 1}`);

    return { src, alt };
};

gallerySlots.forEach((slot) => {
    clearSlotContent(slot);
    setSlotLoadingState(slot, false);
});

let isProcessing = false;
let isStartingWorkflow = false;
let hasShownCompletion = false;
let hasObservedActiveRun = false;
let uploadHandlersInitialized = false;
let historyHandlersInitialized = false;

const toBoolean = (value) => {
    if (typeof value === 'boolean') {
        return value;
    }

    if (typeof value === 'number') {
        return value !== 0;
    }

    if (typeof value === 'string') {
        const normalized = value.trim().toLowerCase();
        if (['true', '1', 'yes', 'ja'].includes(normalized)) {
            return true;
        }
        if (['false', '0', 'no', 'nein'].includes(normalized)) {
            return false;
        }
    }

    return false;
};

const sanitizeLogMessage = (value) => {
    if (value === null || value === undefined) {
        return '';
    }

    return String(value).replace(/\s+/g, ' ').trim();
};

function updateWorkflowButtonState() {
    if (!newButton) {
        return;
    }

    const hasRun = Number.isFinite(window.currentRunId) && window.currentRunId > 0;
    const shouldDisable = !hasRun || isProcessing || isStartingWorkflow;

    newButton.disabled = shouldDisable;

    if (shouldDisable) {
        newButton.setAttribute('aria-disabled', 'true');
    } else {
        newButton.removeAttribute('aria-disabled');
    }
}

function clearWorkflowFeedback() {
    if (!workflowFeedback) {
        return;
    }

    workflowFeedback.textContent = '';
    workflowFeedback.classList.remove(
        WORKFLOW_FEEDBACK_VISIBLE_CLASS,
        WORKFLOW_FEEDBACK_ERROR_CLASS,
        WORKFLOW_FEEDBACK_SUCCESS_CLASS,
        WORKFLOW_FEEDBACK_INFO_CLASS,
    );
    workflowFeedback.setAttribute('hidden', 'hidden');
}

function showWorkflowFeedback(level, message) {
    if (!workflowFeedback) {
        return;
    }

    const normalizedMessage = sanitizeLogMessage(message);

    workflowFeedback.classList.remove(
        WORKFLOW_FEEDBACK_ERROR_CLASS,
        WORKFLOW_FEEDBACK_SUCCESS_CLASS,
        WORKFLOW_FEEDBACK_INFO_CLASS,
    );

    if (!normalizedMessage) {
        clearWorkflowFeedback();
        return;
    }

    let className = WORKFLOW_FEEDBACK_INFO_CLASS;
    const normalizedLevel = typeof level === 'string' ? level.trim().toLowerCase() : '';

    if (normalizedLevel === 'error') {
        className = WORKFLOW_FEEDBACK_ERROR_CLASS;
    } else if (normalizedLevel === 'success') {
        className = WORKFLOW_FEEDBACK_SUCCESS_CLASS;
    }

    workflowFeedback.textContent = normalizedMessage;
    workflowFeedback.classList.add(WORKFLOW_FEEDBACK_VISIBLE_CLASS, className);
    workflowFeedback.removeAttribute('hidden');
}

function setCurrentRun(runId, userId) {
    const numericRunId = Number(runId);
    window.currentRunId = Number.isFinite(numericRunId) && numericRunId > 0 ? numericRunId : null;

    if (userId !== undefined) {
        const numericUserId = Number(userId);
        window.currentUserId = Number.isFinite(numericUserId) && numericUserId > 0 ? numericUserId : null;
    }

    updateWorkflowButtonState();
}

const updateProcessingIndicator = (text, state = 'idle') => {
    if (text) {
        const normalizedState = state || 'idle';
        const message = `[Indicator:${normalizedState}] ${sanitizeLogMessage(text) || text}`;
        if (normalizedState === 'error') {
            console.error(message);
        } else if (normalizedState === 'success') {
            console.info(message);
        } else {
            console.log(message);
        }
    }
};

const setLoadingState = (loading, options = {}) => {
    isProcessing = loading;

    if (loading) {
        hasObservedActiveRun = true;
    } else if (!options || options.indicatorState !== 'success') {
        hasObservedActiveRun = false;
    }

    // Nur anzeigen, wenn der Workflow tatsächlich läuft
    gallerySlots.forEach((slot) => {
        if (loading) {
            clearSlotContent(slot);
            setSlotLoadingState(slot, true);
        } else {
            setSlotLoadingState(slot, false);
            if (!slot.container || slot.container.dataset.hasContent !== 'true') {
                clearSlotContent(slot);
            }
        }
    });

    if (loading) {
        hasShownCompletion = false;
    }

    if (options && options.indicatorText) {
        updateProcessingIndicator(options.indicatorText, options.indicatorState || 'idle');
    }

    updateWorkflowButtonState();
};

const getDataField = (data, key) => {
    if (!data || typeof data !== 'object') {
        return undefined;
    }

    const candidates = new Set([key]);

    if (key.includes('_')) {
        candidates.add(key.replace(/_/g, ''));
        candidates.add(key.replace('_', '-'));
    }

    if (key.startsWith('image_')) {
        const suffix = key.substring(6);
        candidates.add(`image${suffix}`);
        candidates.add(`image-${suffix}`);
    }

    for (const candidate of candidates) {
        if (Object.prototype.hasOwnProperty.call(data, candidate) && data[candidate] !== null && data[candidate] !== undefined) {
            return data[candidate];
        }
    }

    return undefined;
};

const createPreviewItem = (url, name) => {
    const resolvedUrl = toAbsoluteUrl(url) || String(url || '').trim();

    if (!resolvedUrl) {
        return null;
    }

    const item = document.createElement('figure');
    item.className = 'preview-item';
    item.tabIndex = 0;

    const wrapper = document.createElement('div');
    wrapper.className = 'original-image-wrapper';

    const image = document.createElement('img');
    image.src = resolvedUrl;
    image.alt = name || '';

    const overlay = document.createElement('div');
    overlay.className = 'scan-overlay';

    wrapper.appendChild(image);
    wrapper.appendChild(overlay);
    item.appendChild(wrapper);
    applyFadeInAnimation(image);

    const openPreview = () => openLightbox(resolvedUrl, name);

    item.addEventListener('click', openPreview);
    item.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            openPreview();
        }
    });

    return item;
};

const addPreviews = (files) => {
    files.forEach(({ url, name }) => {
        const previewItem = createPreviewItem(url, name);
        if (previewItem && previewList) {
            previewList.prepend(previewItem);
            setScanOverlayActive(true);
        }
    });
};

const uploadFiles = async (files) => {
    const fileList = Array.from(files || []);
    if (fileList.length === 0) {
        return;
    }

    resetFrontendState({ withPulse: true });
    setStatus('info', 'Bild wird hochgeladen …');
    updateProcessingIndicator('Bild wird hochgeladen …', 'running');

    const uploads = fileList.map(async (file) => {
        const formData = new FormData();
        formData.append('image', file);

        console.log(`Starte Upload: ${file.name}`);

        try {
            const response = await fetch(uploadEndpoint, {
                method: 'POST',
                body: formData,
            });

            const rawText = await response.text();
            let payload = {};

            if (rawText) {
                try {
                    payload = JSON.parse(rawText);
                } catch (parseError) {
                    payload = rawText;
                }
            }

            if (!response.ok) {
                const errorMessage = typeof payload === 'object' && payload !== null && typeof payload.message === 'string'
                    ? payload.message
                    : `Upload fehlgeschlagen: ${file.name}`;
                setStatus('error', errorMessage);
                console.error('Upload fehlgeschlagen', {
                    status: response.status,
                    payload,
                });
                setLoadingState(false, { indicatorText: 'Bereit.', indicatorState: 'idle' });
                return;
            }

            const result = typeof payload === 'object' && payload !== null ? payload : {};

            if (result.file) {
                addPreviews([{ url: result.file, name: result.name || file.name }]);
            }

            const isSuccessful = result.success !== false;
            console.log('Upload-Antwort', {
                status: response.status,
                payload,
            });

            if (!isSuccessful) {
                const errorMessage = typeof result.message === 'string'
                    ? result.message
                    : `Upload fehlgeschlagen: ${file.name}`;
                setStatus('error', errorMessage);
                setLoadingState(false, { indicatorText: 'Bereit.', indicatorState: 'idle' });
                return;
            }

            setStatus('success', 'Upload erfolgreich – Workflow kann gestartet werden.');
            setLoadingState(false, { indicatorText: 'Bereit für Workflow-Start', indicatorState: 'idle' });
            hasShownCompletion = false;

            if (result.run_id !== undefined && result.run_id !== null) {
                const parsedRunId = Number(result.run_id);
                activeRunId = Number.isFinite(parsedRunId) && parsedRunId > 0 ? parsedRunId : null;
                setCurrentRun(parsedRunId, result.user_id);
            } else {
                setCurrentRun(null, result.user_id);
            }

            showWorkflowFeedback('info', 'Upload erfolgreich. Bitte Workflow starten.');
            updateProcessingIndicator('Bereit für Workflow-Start', 'idle');
        } catch (error) {
            console.error(error);
            const fallback = `Beim Upload ist ein Fehler aufgetreten (${file.name}).`;
            const message = sanitizeLogMessage(error?.message) || fallback;
            console.error(message);
            setLoadingState(false, { indicatorText: 'Bereit.', indicatorState: 'idle' });
            setStatus('error', 'Uploadfehler – bitte erneut versuchen.');
        }
    });

    await Promise.all(uploads);
};

const handleFiles = (files) => {
    if (!files || files.length === 0) {
        return;
    }
    uploadFiles(files);
};

async function startWorkflow() {
    const runId = window.currentRunId;
    const numericRunId = Number(runId);

    if (!Number.isFinite(numericRunId) || numericRunId <= 0) {
        showWorkflowFeedback('error', 'Bitte zuerst ein Bild hochladen.');
        return;
    }

    if (isStartingWorkflow) {
        return;
    }

    clearWorkflowFeedback();
    isStartingWorkflow = true;
    updateWorkflowButtonState();

    if (newButton) {
        newButton.setAttribute('aria-busy', 'true');
    }

    try {
        const payload = {
            run_id: numericRunId,
        };

        if (Number.isFinite(window.currentUserId) && window.currentUserId > 0) {
            payload.user_id = window.currentUserId;
        }

        const response = await fetch('start-workflow.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
            },
            body: JSON.stringify(payload),
        });

        const rawText = await response.text();
        let parsed = {};

        if (rawText) {
            try {
                parsed = JSON.parse(rawText);
            } catch (parseError) {
                parsed = rawText;
            }
        }

        const result = typeof parsed === 'object' && parsed !== null ? parsed : {};

        if (!response.ok || result.success === false) {
            const message = typeof result.message === 'string' && result.message.trim() !== ''
                ? result.message
                : 'Workflow konnte nicht gestartet werden.';

            showWorkflowFeedback('error', message);
            setStatus('error', message);

            if (result.logout) {
                window.location.href = 'auth/logout.php';
            }

            return;
        }

        const successMessage = typeof result.message === 'string' && result.message.trim() !== ''
            ? result.message
            : 'Workflow gestartet.';

        setCurrentRun(result.run_id ?? numericRunId, result.user_id ?? window.currentUserId);
        const resolvedRunId = window.currentRunId ?? numericRunId;
        activeRunId = Number.isFinite(resolvedRunId) && resolvedRunId > 0 ? resolvedRunId : numericRunId;

        showWorkflowFeedback('success', successMessage);
        setStatus('info', 'Verarbeitung läuft …');
        setLoadingState(true, { indicatorText: 'Verarbeitung läuft…', indicatorState: 'running' });
        hasShownCompletion = false;

        startPolling();
    } catch (error) {
        const fallback = 'Workflow konnte nicht gestartet werden.';
        const message = sanitizeLogMessage(error?.message) || fallback;

        console.error('Workflow-Start fehlgeschlagen', error);
        showWorkflowFeedback('error', message);
        setStatus('error', message);
    } finally {
        isStartingWorkflow = false;
        if (newButton) {
            newButton.removeAttribute('aria-busy');
        }
        updateWorkflowButtonState();
    }
}

const openLightbox = (src, alt) => {
    if (!lightbox || !lightboxImage) {
        return;
    }

    lightboxImage.src = src;
    applyFadeInAnimation(lightboxImage);
    lightboxImage.alt = alt || 'Großansicht';
    lightbox.setAttribute('aria-hidden', 'false');
    lightbox.classList.add('open');
};

const closeLightbox = () => {
    lightbox.classList.remove('open');
    lightbox.setAttribute('aria-hidden', 'true');
    setTimeout(() => {
        lightboxImage.src = '';
    }, 250);
};

const updateInterfaceFromData = (data) => {
    if (!data || typeof data !== 'object') {
        return;
    }

    const hasIsRunning = Object.prototype.hasOwnProperty.call(data, 'isrunning');
    const isRunning = hasIsRunning ? toBoolean(data.isrunning) : false;

    workflowIsRunning = isRunning;

    const hasPreviewImage = Boolean(previewList && previewList.childElementCount > 0);
    setScanOverlayActive(isRunning && hasPreviewImage);

    if (isRunning && !isProcessing) {
        setLoadingState(true, { indicatorText: 'Verarbeitung läuft…', indicatorState: 'running' });
    } else if (!isRunning && isProcessing) {
        setLoadingState(false, { indicatorText: 'Workflow abgeschlossen', indicatorState: 'success' });
    } else if (!isRunning && !isProcessing) {
        updateProcessingIndicator('Bereit.', 'idle');
    }

    if (!isRunning && hasObservedActiveRun && !hasShownCompletion) {
        hasShownCompletion = true;
        updateProcessingIndicator('Workflow abgeschlossen', 'success');
        hasObservedActiveRun = false;
    } else if (isRunning) {
        hasShownCompletion = false;
        updateProcessingIndicator('Verarbeitung läuft…', 'running');
    }

    if (!isRunning && !hasShownCompletion && data.updated_at) {
        hasShownCompletion = true;
    }

    const nameValue = data.produktname ?? data.product_name ?? data.title;
    if (typeof nameValue === 'string' && nameValue.trim() !== '') {
        articleNameInput.value = nameValue;
    }

    const descriptionValue = data.produktbeschreibung ?? data.product_description ?? data.description;
    if (typeof descriptionValue === 'string' && descriptionValue.trim() !== '') {
        articleDescriptionInput.value = descriptionValue;
    }

    gallerySlots.forEach((slot) => {
        const value = getDataField(data, slot.key);
        if (value) {
            setSlotImageSource(slot, value);
        } else if (!isProcessing && slot.container && slot.container.dataset.hasContent !== 'true') {
            clearSlotContent(slot);
            setSlotLoadingState(slot, false);
        }
    });

    if (!isRunning) {
        stopPolling();
    }
};

const applyRunDataToUI = (payload) => {
    const data = payload && typeof payload === 'object' ? payload : {};
    const note = data.note && typeof data.note === 'object' ? data.note : {};
    const images = Array.isArray(data.images) ? data.images : [];
    const originalImage = typeof data.original_image === 'string' ? data.original_image.trim() : '';
    let runIsRunning = false;
    let statusRaw = '';
    let indicatorMessage = '';
    let indicatorState = 'idle';

    if (articleNameInput) {
        articleNameInput.value = (note.product_name || '').trim();
    }

    if (articleDescriptionInput) {
        articleDescriptionInput.value = note.product_description || '';
    }

    const normalizedImages = images.map((entry) => ({
        position: Number(entry.position),
        url: typeof entry.url === 'string' ? entry.url.trim() : '',
    }));

    gallerySlots.forEach((slot) => {
        const slotKey = slot?.key;
        if (!slotKey) {
            return;
        }

        let expectedPosition = null;
        if (slotKey === 'image_1') expectedPosition = 1;
        else if (slotKey === 'image_2') expectedPosition = 2;
        else if (slotKey === 'image_3') expectedPosition = 3;

        if (!expectedPosition) {
            return;
        }

        const image = normalizedImages.find((entry) => Number.isFinite(entry.position) && entry.position === expectedPosition);
        if (image && image.url) {
            const resolvedImageUrl = toAbsoluteUrl(image.url);
            if (resolvedImageUrl) {
                setSlotImageSource(slot, resolvedImageUrl);
                setSlotLoadingState(slot, false);
                lastKnownImages[slotKey] = resolvedImageUrl;
            } else {
                clearSlotContent(slot);
                setSlotLoadingState(slot, false);
                lastKnownImages[slotKey] = null;
            }
        } else {
            clearSlotContent(slot);
            setSlotLoadingState(slot, false);
            lastKnownImages[slotKey] = null;
        }
    });

    if (data.run) {
        statusRaw = typeof data.run.status === 'string' ? data.run.status.trim().toLowerCase() : '';
        indicatorMessage = sanitizeLogMessage(data.run.last_message) || sanitizeLogMessage(data.run.status) || 'Bereit.';

        if (Object.prototype.hasOwnProperty.call(data.run, 'isrunning')) {
            runIsRunning = toBoolean(data.run.isrunning);
        } else {
            runIsRunning = statusRaw === 'running';
        }

        if (statusRaw === 'running') {
            indicatorState = 'running';
        } else if (['finished', 'success', 'completed'].includes(statusRaw)) {
            indicatorState = 'success';
        } else if (statusRaw === 'pending') {
            indicatorState = 'idle';
            indicatorMessage = indicatorMessage || 'Bereit für Workflow-Start';
        } else if (['failed', 'error'].includes(statusRaw)) {
            indicatorState = 'error';
        }

        updateProcessingIndicator(indicatorMessage || 'Bereit.', indicatorState);
    } else if (Object.prototype.hasOwnProperty.call(data, 'isrunning')) {
        runIsRunning = toBoolean(data.isrunning);
    }

    if (previewList) {
        previewList.innerHTML = '';

        if (originalImage) {
            const previewItem = createPreviewItem(originalImage, note.product_name || '');
            if (previewItem) {
                previewList.appendChild(previewItem);
            }
        }

        setScanOverlayActive(Boolean(originalImage) && runIsRunning);
    }
};

const setActiveRun = (runId) => {
    const numericId = Number(runId);
    selectedHistoryRunId = Number.isFinite(numericId) ? numericId : null;

    if (!HISTORY_LIST) {
        return;
    }

    Array.from(HISTORY_LIST.children).forEach((element) => {
        if (!(element instanceof HTMLElement)) {
            return;
        }

        const candidateId = Number(element.dataset.runId);
        const isActive = selectedHistoryRunId !== null && Number.isFinite(candidateId) && candidateId === selectedHistoryRunId;

        if (isActive) {
            element.classList.add('history-list__item--active');
            element.setAttribute('aria-current', 'true');
        } else {
            element.classList.remove('history-list__item--active');
            element.removeAttribute('aria-current');
        }
    });
};

const loadRunDetails = async (runId) => {
    const numericId = Number(runId);
    if (!Number.isFinite(numericId) || numericId <= 0) {
        return;
    }

    try {
        const response = await fetch(`${RUN_DETAILS_ENDPOINT}?id=${encodeURIComponent(numericId)}&${Date.now()}`, {
            cache: 'no-store',
        });

        if (!response.ok) {
            return;
        }

        const json = await response.json();
        if (!json || typeof json !== 'object' || json.ok !== true) {
            return;
        }

        const data = json.data && typeof json.data === 'object' ? json.data : {};
        applyRunDataToUI(data);
        setActiveRun(numericId);
    } catch (error) {
        console.error('Run-Details laden fehlgeschlagen', error);
    }
};

const renderRuns = (runs, options = {}) => {
    if (!HISTORY_LIST) {
        return;
    }

    HISTORY_LIST.innerHTML = '';

    const emptyMessage = typeof options.emptyMessage === 'string' && options.emptyMessage.trim() !== ''
        ? options.emptyMessage.trim()
        : 'Keine Verläufe vorhanden.';

    if (!Array.isArray(runs) || runs.length === 0) {
        const empty = document.createElement('li');
        empty.className = 'history-list__empty';
        empty.textContent = emptyMessage;
        HISTORY_LIST.appendChild(empty);
        return;
    }

    runs.forEach((run) => {
        if (!run || typeof run !== 'object') {
            return;
        }

        const item = document.createElement('li');
        item.className = 'history-list__item';
        item.dataset.runId = run.id;
        item.tabIndex = 0;

        const { started, finished } = extractRunDates(run);
        const providedDate = typeof run.date === 'string' ? run.date.trim() : '';
        const computedDate = formatDateLabel(started || finished);
        const dateLabel = providedDate !== '' ? providedDate : computedDate;

        const fallbackTitle = (() => {
            const referenceDate = started || finished;
            if (referenceDate) {
                const formatted = formatDateTimeLabel(referenceDate);
                return formatted ? `Run vom ${formatted}` : 'Run';
            }

            if (run && (typeof run.id === 'number' || typeof run.id === 'string')) {
                const idValue = String(run.id).trim();
                return idValue !== '' ? `Run #${idValue}` : 'Run';
            }

            return 'Run';
        })();

        const rawTitle = typeof run.title === 'string' ? run.title.trim() : '';
        const resolvedTitle = rawTitle !== '' ? rawTitle : fallbackTitle;

        if (dateLabel && !resolvedTitle.startsWith(dateLabel)) {
            item.textContent = `${dateLabel} – ${resolvedTitle}`;
        } else {
            item.textContent = resolvedTitle;
        }

        if (run.last_message) {
            item.title = run.last_message;
        }

        item.addEventListener('click', () => {
            loadRunDetails(run.id);
        });

        item.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                loadRunDetails(run.id);
            }
        });

        if (selectedHistoryRunId !== null && Number(run.id) === selectedHistoryRunId) {
            item.classList.add('history-list__item--active');
            item.setAttribute('aria-current', 'true');
        }

        HISTORY_LIST.appendChild(item);
    });
};

const fetchRuns = async () => {
    if (!RUNS_ENDPOINT) {
        return;
    }

    try {
        const response = await fetch(`${RUNS_ENDPOINT}?${Date.now()}`, {
            cache: 'no-store',
        });

        if (!response.ok) {
            throw new Error(`Serverantwort ${response.status}`);
        }

        const json = await response.json();
        if (!json || typeof json !== 'object' || json.ok !== true) {
            renderRuns([], { emptyMessage: 'Keine Verläufe verfügbar.' });
            return;
        }

        renderRuns(json.data || []);
    } catch (error) {
        console.error('Runs laden fehlgeschlagen', error);
        renderRuns([], { emptyMessage: 'Verläufe konnten nicht geladen werden.' });
    }
};

const closeSidebarProfileMenu = () => {
    if (!SIDEBAR_PROFILE) {
        return;
    }

    SIDEBAR_PROFILE.classList.remove('open');

    if (SIDEBAR_PROFILE_TRIGGER instanceof HTMLElement) {
        SIDEBAR_PROFILE_TRIGGER.setAttribute('aria-expanded', 'false');
    }

    if (SIDEBAR_PROFILE_MENU instanceof HTMLElement) {
        SIDEBAR_PROFILE_MENU.setAttribute('aria-hidden', 'true');
    }
};

const openSidebarProfileMenu = () => {
    if (!SIDEBAR_PROFILE) {
        return;
    }

    if (!(SIDEBAR_PROFILE_TRIGGER instanceof HTMLElement) || !(SIDEBAR_PROFILE_MENU instanceof HTMLElement)) {
        return;
    }

    SIDEBAR_PROFILE.classList.add('open');
    SIDEBAR_PROFILE_TRIGGER.setAttribute('aria-expanded', 'true');
    SIDEBAR_PROFILE_MENU.setAttribute('aria-hidden', 'false');
};

const toggleSidebarProfileMenu = () => {
    if (!SIDEBAR_PROFILE) {
        return;
    }

    if (SIDEBAR_PROFILE.classList.contains('open')) {
        closeSidebarProfileMenu();
    } else {
        openSidebarProfileMenu();
    }
};

const setupSidebarProfileMenu = () => {
    if (profileMenuInitialized) {
        return;
    }

    if (!SIDEBAR_PROFILE || !(SIDEBAR_PROFILE_TRIGGER instanceof HTMLElement) || !(SIDEBAR_PROFILE_MENU instanceof HTMLElement)) {
        return;
    }

    profileMenuInitialized = true;

    SIDEBAR_PROFILE_TRIGGER.addEventListener('click', (event) => {
        event.preventDefault();
        toggleSidebarProfileMenu();
    });

    document.addEventListener('click', (event) => {
        if (!SIDEBAR_PROFILE.classList.contains('open')) {
            return;
        }

        const target = event.target;
        if (target instanceof Node && SIDEBAR_PROFILE.contains(target)) {
            return;
        }

        closeSidebarProfileMenu();
    });
};

const openHistory = () => {
    if (!HISTORY_SIDEBAR) {
        return;
    }

    HISTORY_SIDEBAR.classList.add('history-sidebar--open');
    HISTORY_SIDEBAR.setAttribute('aria-hidden', 'false');
    if (HISTORY_TOGGLE) {
        HISTORY_TOGGLE.setAttribute('aria-expanded', 'true');
    }

    fetchRuns();
};

const closeHistory = () => {
    if (!HISTORY_SIDEBAR) {
        return;
    }

    HISTORY_SIDEBAR.classList.remove('history-sidebar--open');
    HISTORY_SIDEBAR.setAttribute('aria-hidden', 'true');
    if (HISTORY_TOGGLE) {
        HISTORY_TOGGLE.setAttribute('aria-expanded', 'false');
    }

    closeSidebarProfileMenu();
};

async function fetchLatestItem() {
    try {
        const response = await fetch(DATA_ENDPOINT, {
            cache: 'no-store',
        });

        if (!response.ok) {
            throw new Error(`Serverantwort ${response.status}`);
        }

        const raw = await response.json();
        if (!raw || typeof raw !== 'object' || raw.ok !== true || !raw.data) {
            return;
        }

        const payload = raw.data && typeof raw.data === 'object' ? raw.data : {};

        if (payload.run_id !== undefined && payload.run_id !== null) {
            const numericRunId = Number(payload.run_id);
            activeRunId = Number.isFinite(numericRunId) && numericRunId > 0 ? numericRunId : null;
        }

        const normalized = updateUiWithData(payload);

        const hasIsRunning = normalized && Object.prototype.hasOwnProperty.call(normalized, 'isrunning');
        const isRunning = hasIsRunning ? toBoolean(normalized.isrunning) : toBoolean(payload.isrunning);

        const statusLabelRaw =
            (normalized && typeof normalized.status === 'string' && normalized.status.trim() !== '')
                ? normalized.status
                : (typeof payload.status === 'string' ? payload.status : '');
        const statusLabel = statusLabelRaw ? statusLabelRaw.trim().toLowerCase() : '';

        if (!isRunning) {
            if (statusLabel === 'pending') {
                setStatus('info', 'Bereit für Workflow-Start');
                showWorkflowFeedback('info', 'Workflow bereit zum Start.');
                updateProcessingIndicator('Bereit für Workflow-Start', 'idle');
                if (payload.run_id !== undefined && payload.run_id !== null) {
                    setCurrentRun(payload.run_id);
                }
            } else {
                setStatus('success', 'Workflow abgeschlossen');
                showWorkflowFeedback('success', 'Workflow abgeschlossen.');
                stopPolling();
                activeRunId = null;
                setCurrentRun(null);
            }
        } else {
            setStatus('info', 'Verarbeitung läuft …');
        }
    } catch (error) {
        console.error('Polling-Fehler:', error);
        setStatus('error', 'Fehler beim Abrufen des Status');
    }
}

function setupUploadHandler() {
    if (uploadHandlersInitialized) {
        return;
    }

    uploadHandlersInitialized = true;

    if (selectFileButton) {
        selectFileButton.addEventListener('click', () => {
            if (fileInput) {
                fileInput.click();
            }
        });
    }

    if (fileInput) {
        fileInput.addEventListener('change', (event) => {
            handleFiles(event.target.files);
            event.target.value = '';
        });
    }

    if (!dropZone) {
        return;
    }

    dropZone.addEventListener('dragenter', (event) => {
        event.preventDefault();
        dropZone.classList.add('dragover');
    });

    dropZone.addEventListener('dragover', (event) => {
        event.preventDefault();
    });

    dropZone.addEventListener('dragleave', (event) => {
        if (event.target === dropZone) {
            dropZone.classList.remove('dragover');
        }
    });

    dropZone.addEventListener('drop', (event) => {
        event.preventDefault();
        dropZone.classList.remove('dragover');
        const files = event.dataTransfer?.files;
        if (files) {
            handleFiles(files);
        }
    });

    dropZone.addEventListener('click', () => {
        if (fileInput) {
            fileInput.click();
        }
    });
}

function setupHistoryHandler() {
    if (historyHandlersInitialized) {
        return;
    }

    historyHandlersInitialized = true;

    if (HISTORY_TOGGLE) {
        HISTORY_TOGGLE.addEventListener('click', () => {
            if (HISTORY_SIDEBAR && HISTORY_SIDEBAR.classList.contains('history-sidebar--open')) {
                closeHistory();
            } else {
                openHistory();
            }
        });
    }

    if (HISTORY_CLOSE) {
        HISTORY_CLOSE.addEventListener('click', closeHistory);
    }
}

lightboxClose.addEventListener('click', closeLightbox);
lightbox.addEventListener('click', (event) => {
    if (event.target === lightbox) {
        closeLightbox();
    }
});

document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') {
        return;
    }

    if (lightbox.classList.contains('open')) {
        closeLightbox();
    }

    if (HISTORY_SIDEBAR && HISTORY_SIDEBAR.classList.contains('history-sidebar--open')) {
        closeHistory();
    }

    if (SIDEBAR_PROFILE && SIDEBAR_PROFILE.classList.contains('open')) {
        closeSidebarProfileMenu();
    }
});

gallerySlots.forEach((slot) => {
    if (!slot.container) {
        return;
    }

    const open = () => {
        const { src, alt } = getSlotPreviewData(slot);
        openLightbox(src, alt);
    };

    slot.container.addEventListener('click', open);
    slot.container.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            open();
        }
    });
});

function clearProductFields() {
    if (articleNameInput) {
        articleNameInput.value = '';
    }

    if (articleDescriptionInput) {
        articleDescriptionInput.value = '';
    }
}

function showPlaceholderImages(withPulse = false) {
    if (previewList) {
        previewList.innerHTML = '';
    }

    setScanOverlayActive(false);

    gallerySlots.forEach((slot) => {
        clearSlotContent(slot);
        setSlotLoadingState(slot, withPulse);
    });

    Object.keys(lastKnownImages).forEach((key) => {
        lastKnownImages[key] = null;
    });
}

function setStatus(level, message) {
    const normalizedMessage = sanitizeLogMessage(message);
    if (!normalizedMessage) {
        return;
    }

    const label = typeof level === 'string' && level.trim() !== '' ? level.trim().toLowerCase() : 'info';
    const logEntry = `[Status:${label}] ${normalizedMessage}`;

    if (label === 'error') {
        console.error(logEntry);
    } else if (label === 'success') {
        console.info(logEntry);
    } else {
        console.log(logEntry);
    }
}

function startPolling() {
    if (isPolling) {
        return;
    }

    isPolling = true;
    workflowIsRunning = true;
    pollInterval = setInterval(fetchLatestItem, POLLING_INTERVAL);
    fetchLatestItem();
}

function stopPolling() {
    if (pollInterval) {
        clearInterval(pollInterval);
    }

    pollInterval = null;
    isPolling = false;
    workflowIsRunning = false;
}

function resetFrontendState(options = {}) {
    const withPulse = Boolean(options.withPulse);
    stopPolling();
    activeRunId = null;
    setStatus('ready', 'Bereit zum Upload');
    clearProductFields();
    setLoadingState(false, { indicatorText: 'Bereit.', indicatorState: 'idle' });
    showPlaceholderImages(withPulse);
    hasShownCompletion = false;
    hasObservedActiveRun = false;
    workflowIsRunning = false;
    setCurrentRun(null, null);
    clearWorkflowFeedback();

    selectedHistoryRunId = null;
    if (HISTORY_LIST) {
        Array.from(HISTORY_LIST.children).forEach((element) => {
            if (element instanceof HTMLElement) {
                element.classList.remove('history-list__item--active');
                element.removeAttribute('aria-current');
            }
        });
    }
}

function setInitialUiState() {
    resetFrontendState();
}

document.addEventListener('DOMContentLoaded', () => {
    setInitialUiState();
    setupUploadHandler();
    setupHistoryHandler();
    setupSidebarProfileMenu();

    if (newButton) {
        newButton.addEventListener('click', startWorkflow);
    }

    updateWorkflowButtonState();
});
