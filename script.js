const dropZone = document.getElementById('drop-zone');
const fileInput = document.getElementById('file-input');
const selectFileButton = document.getElementById('select-file');
const previewList = document.getElementById('upload-previews');
const originalImagesWrapper = document.querySelector('[data-original-images]');
const lightbox = document.getElementById('lightbox');
const lightboxImage = lightbox.querySelector('.lightbox__image');
const lightboxClose = lightbox.querySelector('.lightbox__close');
const startWorkflowButton =
    document.getElementById('start-workflow-btn') || document.getElementById('btn-new');
const statusBar = document.getElementById('status-bar');
const articleNameOutput = document.getElementById('article-name-content');
const articleDescriptionOutput = document.getElementById('article-description-content');
const articleNameGroup = document.getElementById('article-name-group');
const articleDescriptionGroup = document.getElementById('article-description-group');
const workflowOutput = document.getElementById('workflow-output');
const statusFeedContainer = document.getElementById('status-feed');
const statusFeedEmpty = document.getElementById('status-feed-empty');
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

const FIELD_GROUP_LOADING_CLASS = 'is-loading';
const FIELD_GROUP_STATE = {
    idle: 'idle',
    running: 'running',
    ready: 'ready',
};
const SKELETON_SHINE_CLASS = 'skeleton-shine';

let workflowOutputController = null;
let statusAnimationInterval = null;
let statusDotCount = 0;
let baseStatusMessage = '';

const isStatusAnimationActive = () => statusAnimationInterval !== null;

function setStatusMessage(text, options = {}) {
    const bar = statusBar || document.getElementById('status-bar');
    if (!bar) {
        return;
    }

    const forceUpdate = Boolean(options && typeof options === 'object' && options.force === true);
    if (!forceUpdate && isStatusAnimationActive()) {
        return;
    }

    const rawText = typeof text === 'string' ? text : '';
    const normalized = rawText.trim();

    bar.classList.remove('status-bar--active', 'status-bar--info', 'status-bar--success', 'status-bar--error');

    if (!normalized) {
        bar.textContent = '';
        return;
    }

    bar.textContent = rawText;
    bar.classList.add('status-bar--active');
}

const resolveStatusBarMessage = (payload) => {
    const candidates = [];

    if (payload && typeof payload === 'object') {
        if (typeof payload.last_status_message === 'string') {
            candidates.push(payload.last_status_message);
        }

        if (payload.run && typeof payload.run === 'object') {
            const runPayload = payload.run;
            if (typeof runPayload.last_status_message === 'string') {
                candidates.push(runPayload.last_status_message);
            }

            if (typeof runPayload.last_message === 'string') {
                candidates.push(runPayload.last_message);
            }
        }

        if (typeof payload.message === 'string') {
            candidates.push(payload.message);
        }
    }

    for (const candidate of candidates) {
        if (typeof candidate === 'string' && candidate.trim() !== '') {
            return candidate;
        }
    }

    return '';
};

const asRunningBoolean = (value) => {
    if (typeof value === 'boolean') {
        return value;
    }

    if (typeof value === 'number') {
        return value === 1;
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

const resolveIsRunningFromPayload = (payload) => {
    if (!payload || typeof payload !== 'object') {
        return false;
    }

    if (Object.prototype.hasOwnProperty.call(payload, 'isrunning')) {
        return asRunningBoolean(payload.isrunning);
    }

    if (payload.run && typeof payload.run === 'object' && Object.prototype.hasOwnProperty.call(payload.run, 'isrunning')) {
        return asRunningBoolean(payload.run.isrunning);
    }

    return false;
};

// Render current frame of 4-State Statusanimation: "", ".", "..", "..." – jeweils 1 Sekunde
const renderStatusAnimationFrame = () => {
    const bar = statusBar || document.getElementById('status-bar');
    if (!bar) {
        return;
    }

    if (statusDotCount === 0) {
        bar.textContent = baseStatusMessage;
        return;
    }

    bar.textContent = `${baseStatusMessage} ${'.'.repeat(statusDotCount)}`;
};

// 4-State Statusanimation: "", ".", "..", "..." – jeweils 1 Sekunde
const animateStatus = () => {
    statusDotCount = (statusDotCount + 1) % 4;
    renderStatusAnimationFrame();
};

const startStatusAnimation = (message) => {
    baseStatusMessage = typeof message === 'string' ? message.trim() : '';
    statusDotCount = 0;

    setStatusMessage(baseStatusMessage, { force: true });
    renderStatusAnimationFrame();

    if (statusAnimationInterval === null) {
        statusAnimationInterval = window.setInterval(animateStatus, 1000);
    }
};

const updateStatusAnimationMessage = (message) => {
    baseStatusMessage = typeof message === 'string' ? message.trim() : '';
    renderStatusAnimationFrame();
};

const stopStatusAnimation = (message) => {
    if (statusAnimationInterval !== null) {
        window.clearInterval(statusAnimationInterval);
    }

    statusAnimationInterval = null;
    statusDotCount = 0;
    baseStatusMessage = typeof message === 'string' ? message.trim() : '';

    renderStatusAnimationFrame();
};

const applyStatusBarMessage = (payload, options = {}) => {
    const message = resolveStatusBarMessage(payload);
    const hasExplicitIsRunning = Object.prototype.hasOwnProperty.call(options, 'isRunning');
    const isRunning = hasExplicitIsRunning ? Boolean(options.isRunning) : resolveIsRunningFromPayload(payload);

    if (isRunning) {
        if (isStatusAnimationActive()) {
            updateStatusAnimationMessage(message);
        } else {
            startStatusAnimation(message);
        }
        return;
    }

    stopStatusAnimation(message);
};

window.currentRunId = Number.isFinite(Number(window.currentRunId)) && Number(window.currentRunId) > 0
    ? Number(window.currentRunId)
    : null;
window.currentUserId = Number.isFinite(Number(window.currentUserId)) && Number(window.currentUserId) > 0
    ? Number(window.currentUserId)
    : null;
window.currentOriginalImages = Array.isArray(window.currentOriginalImages)
    ? window.currentOriginalImages.filter((url) => typeof url === 'string' && url.trim() !== '')
    : [];

let isPolling = false;
let pollInterval = null;
let workflowIsRunning = false;
let activeRunId = null;
let profileMenuInitialized = false;
const lastHandledStepStatusByRun = {};

const RUNS_ENDPOINT = 'api/get-runs.php';
const RUN_DETAILS_ENDPOINT = 'api/get-run-details.php';
const STATUS_FEED_ENDPOINT = 'api/get-status-feed.php';

const uploadEndpoint = 'upload.php';
const POLLING_INTERVAL = 2000;
const DATA_ENDPOINT = 'api/get-latest-item.php';

const SCAN_OVERLAY_SELECTOR = '.scan-overlay';
const SCAN_OVERLAY_ACTIVE_CLASS = 'active';


async function logFrontendStatus(statusCode) {
    try {
        const payload = {
            status_code: statusCode,
        };

        // run_id hängt in deinem Frontend an currentRunId / activeRunId
        if (window.currentRunId) {
            payload.run_id = window.currentRunId;
        } else if (typeof activeRunId !== 'undefined' && activeRunId) {
            payload.run_id = activeRunId;
        }

        await fetch('api/log-status-event.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(payload),
        });
    } catch (error) {
        console.error('Frontend-Status konnte nicht geloggt werden:', error);
    }
}



function updateStartButtonState(hasUpload) {
    const btn = document.getElementById('start-workflow-btn');
    if (!btn) {
        return;
    }

    if (hasUpload) {
        btn.removeAttribute('disabled');
        btn.classList.remove('is-disabled');
        btn.setAttribute('aria-disabled', 'false');
    } else {
        btn.setAttribute('disabled', 'true');
        btn.classList.add('is-disabled');
        btn.setAttribute('aria-disabled', 'true');
    }
}

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

function renderStatusFeed(items) {
    if (!statusFeedContainer) {
        return;
    }

    statusFeedContainer.innerHTML = '';

    const emptyNode = statusFeedEmpty || null;

    if (!Array.isArray(items) || items.length === 0) {
        if (emptyNode) {
            emptyNode.style.display = '';
        }
        return;
    }

    if (emptyNode) {
        emptyNode.style.display = 'none';
    }

    items.forEach((item) => {
        const entry = document.createElement('div');
        const severity = item && typeof item.severity === 'string' && item.severity.trim() !== ''
            ? item.severity.trim()
            : 'info';
        entry.className = `status-item status-item--${severity}`;

        entry.dataset.severity = severity;

        const iconWrapper = document.createElement('span');
        iconWrapper.className = 'status-icon-wrapper';
        if (item && typeof item.icon_html === 'string') {
            iconWrapper.innerHTML = item.icon_html;
        }

        const content = document.createElement('div');
        content.className = 'status-content';

        const text = document.createElement('p');
        text.className = 'status-text';
        text.textContent = item && typeof item.message === 'string' ? item.message : '';

        content.appendChild(text);
        entry.appendChild(iconWrapper);
        entry.appendChild(content);

        statusFeedContainer.appendChild(entry);
    });
}

async function fetchStatusFeed() {
    if (!statusFeedContainer) {
        return;
    }

    const params = new URLSearchParams();
    if (activeRunId) {
        params.set('run_id', String(activeRunId));
    }

    const url = params.toString() ? `${STATUS_FEED_ENDPOINT}?${params.toString()}` : STATUS_FEED_ENDPOINT;
    const response = await fetch(url, {
        cache: 'no-store',
    });

    if (!response.ok) {
        throw new Error(`Serverantwort ${response.status}`);
    }

    const raw = await response.json();
    if (!raw || typeof raw !== 'object' || raw.ok !== true || !Array.isArray(raw.items)) {
        return;
    }

    renderStatusFeed(raw.items);
}

const toAbsoluteUrl = (path) => {
    if (path === undefined || path === null) {
        return '';
    }

    const raw = String(path).trim();
    if (raw === '') {
        return '';
    }

    if (/^(?:[a-z][a-z0-9+.-]*:)?\/\//i.test(raw)) {
        return raw;
    }

    const configBase =
        window.APP_CONFIG && typeof window.APP_CONFIG.base_url === 'string'
            ? window.APP_CONFIG.base_url.trim()
            : '';
    const base = configBase || (typeof window.location === 'object' ? window.location.origin : '');

    const normalizedPath = raw.replace(/^\/+/, '');

    if (base) {
        return `${base.replace(/\/+$/, '')}/${normalizedPath}`;
    }

    return `/${normalizedPath}`;
};

function attachLightboxToImage(imgEl, srcOverride) {
    if (!imgEl) return;
    const src = srcOverride || imgEl.getAttribute('data-full') || imgEl.src;
    if (!src) return;

    imgEl.style.cursor = 'pointer';
    imgEl.addEventListener(
        'click',
        function () {
            if (typeof openLightbox === 'function') {
                openLightbox(src);
            }
        },
        { once: false },
    );
}

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

const ensureOriginalImagesState = () => {
    if (!Array.isArray(window.currentOriginalImages)) {
        window.currentOriginalImages = [];
    }
};

const updateOriginalImageLayoutMode = () => {
    if (!originalImagesWrapper) {
        return;
    }

    const images = originalImagesWrapper.querySelectorAll('img.original-image-preview');
    const count = images.length;

    originalImagesWrapper.classList.remove('single', 'multi');

    if (count === 1) {
        originalImagesWrapper.classList.add('single');
    } else if (count >= 2) {
        originalImagesWrapper.classList.add('multi');
    }
};

const clearOriginalImagePreviews = () => {
    if (!originalImagesWrapper) {
        return;
    }

    originalImagesWrapper.innerHTML = '';
    updateOriginalImageLayoutMode();
};

const appendOriginalImagePreview = (url, options = {}) => {
    if (!originalImagesWrapper) {
        return;
    }

    const rawUrl = typeof url === 'string' ? url.trim() : '';
    if (rawUrl === '') {
        return;
    }

    const img = document.createElement('img');
    img.src = rawUrl;
    img.alt = 'Originalbild';
    img.classList.add('original-image-preview');
    img.setAttribute('data-full', rawUrl);
    originalImagesWrapper.appendChild(img);
    applyFadeInAnimation(img);
    attachLightboxToImage(img, rawUrl);
    updateOriginalImageLayoutMode();

    if (options.updateState !== false) {
        ensureOriginalImagesState();
        window.currentOriginalImages.push(rawUrl);
    }
};

const renderOriginalImagePreviews = (urls) => {
    ensureOriginalImagesState();
    clearOriginalImagePreviews();

    const normalized = Array.isArray(urls) ? urls : [];
    const nextState = [];

    normalized.forEach((entry) => {
        const rawUrl = typeof entry === 'string' ? entry.trim() : '';
        if (rawUrl === '') {
            return;
        }

        appendOriginalImagePreview(rawUrl, { updateState: false });
        nextState.push(rawUrl);
    });

    window.currentOriginalImages = nextState;
    updateOriginalImageLayoutMode();
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

const setFieldGroupState = (group, state = FIELD_GROUP_STATE.idle) => {
    if (!(group instanceof HTMLElement)) {
        return;
    }

    const normalizedState = Object.values(FIELD_GROUP_STATE).includes(state)
        ? state
        : FIELD_GROUP_STATE.idle;
    const isLoadingState = normalizedState === FIELD_GROUP_STATE.idle || normalizedState === FIELD_GROUP_STATE.running;

    group.classList.toggle(FIELD_GROUP_LOADING_CLASS, isLoadingState);

    const skeleton = group.querySelector('.skeleton-text');
    if (skeleton) {
        skeleton.classList.toggle(SKELETON_SHINE_CLASS, normalizedState === FIELD_GROUP_STATE.running);
    }
};

const setFieldGroupLoading = (group, isLoading) => {
    setFieldGroupState(group, isLoading ? FIELD_GROUP_STATE.running : FIELD_GROUP_STATE.idle);
};

const setArticleFieldsLoading = (isLoading) => {
    setFieldGroupLoading(articleNameGroup, isLoading);
    setFieldGroupLoading(articleDescriptionGroup, isLoading);
};

setFieldGroupState(articleNameGroup, FIELD_GROUP_STATE.idle);
setFieldGroupState(articleDescriptionGroup, FIELD_GROUP_STATE.idle);

const collectArticleFieldSources = (input) => {
    const sources = [];
    const visited = new Set();
    const queue = [];

    const enqueue = (candidate) => {
        if (!candidate || typeof candidate !== 'object') {
            return;
        }

        if (visited.has(candidate)) {
            return;
        }

        visited.add(candidate);
        queue.push(candidate);
        sources.push(candidate);
    };

    enqueue(input);

    const nestedKeys = ['data', 'item', 'latest', 'latest_item', 'latestItem', 'note', 'payload', 'result', 'entry', 'article', 'product'];

    while (queue.length > 0) {
        const current = queue.shift();

        nestedKeys.forEach((key) => {
            if (Object.prototype.hasOwnProperty.call(current, key)) {
                enqueue(current[key]);
            }
        });

        if (Array.isArray(current.items)) {
            current.items.forEach((value) => enqueue(value));
        }

        if (Array.isArray(current.notes)) {
            current.notes.forEach((value) => enqueue(value));
        }

        if (Array.isArray(current.entries)) {
            current.entries.forEach((value) => enqueue(value));
        }
    }

    return sources;
};

const findArticleFieldValue = (sources, keys) => {
    for (const source of sources) {
        if (!source || typeof source !== 'object') {
            continue;
        }

        for (const key of keys) {
            if (!Object.prototype.hasOwnProperty.call(source, key)) {
                continue;
            }

            const value = sanitizeLatestItemString(source[key]).trim();
            if (value !== '') {
                return value;
            }
        }
    }

    return '';
};

const updateArticleFieldsFromData = (data) => {
    const sources = collectArticleFieldSources(data);
    const articleName = findArticleFieldValue(sources, [
        'product_name',
        'article_name',
        'produktname',
        'title',
        'name',
    ]);
    const articleDescription = findArticleFieldValue(sources, [
        'product_description',
        'article_description',
        'produktbeschreibung',
        'description',
        'details',
        'text',
    ]);

    const hasName = articleName.trim() !== '';
    const hasDescription = articleDescription.trim() !== '';

    if (articleNameOutput) {
        articleNameOutput.textContent = hasName ? articleName : '';
    }

    const nameState = hasName
        ? FIELD_GROUP_STATE.ready
        : isProcessing
            ? FIELD_GROUP_STATE.running
            : FIELD_GROUP_STATE.idle;
    setFieldGroupState(articleNameGroup, nameState);

    if (articleDescriptionOutput) {
        articleDescriptionOutput.textContent = hasDescription ? articleDescription : '';
    }

    const descriptionState = hasDescription
        ? FIELD_GROUP_STATE.ready
        : isProcessing
            ? FIELD_GROUP_STATE.running
            : FIELD_GROUP_STATE.idle;
    setFieldGroupState(articleDescriptionGroup, descriptionState);

    if (workflowOutputController) {
        workflowOutputController.sync();
    }
};

function initCopyButtons() {
    document.querySelectorAll('.output-copy').forEach((btn) => {
        btn.addEventListener('click', () => {
            const targetSelector = btn.getAttribute('data-copy-target');
            const targetEl = targetSelector ? document.querySelector(targetSelector) : null;
            if (!targetEl) {
                return;
            }

            const text = targetEl.textContent || '';
            if (!text) {
                return;
            }

            navigator.clipboard
                .writeText(text)
                .then(() => {
                    btn.textContent = 'Kopiert';
                    setTimeout(() => {
                        btn.textContent = 'Kopieren';
                    }, 1200);
                })
                .catch(() => {
                    btn.textContent = 'Fehler';
                    setTimeout(() => {
                        btn.textContent = 'Kopieren';
                    }, 1200);
                });
        });
    });
}

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
    updateArticleFieldsFromData(payload);
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

    const hasIsRunning = Object.prototype.hasOwnProperty.call(normalized, 'isrunning');
    const isRunning = hasIsRunning ? toBoolean(normalized.isrunning) : false;
    applyStatusBarMessage(data, { isRunning });

    if (data.images) {
        renderGeneratedImages(data.images);
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
const gallerySlotKeys = ['image_1', 'image_2', 'image_3'];

const gallerySlots = Array.from(document.querySelectorAll('.generated-slot')).map((container, index) => {
    const key = gallerySlotKeys[index] || `image_${index + 1}`;
    const card = container.closest('.generated-card');
    const skeletonActions = card ? card.querySelector('.slot-actions--skeleton') : null;
    const menuActions = card ? card.querySelector('.slot-actions--menu') : null;
    const skeletonLine = skeletonActions ? skeletonActions.querySelector('.slot-actions__skeleton-line') : null;

    container.dataset.slotKey = key;
    container.dataset.currentSrc = '';
    container.dataset.hasContent = 'false';
    container.dataset.isLoading = 'false';

    container.classList.remove('is-hidden', 'preload', 'is-pulsing');

    const renderBox = container.querySelector('.render-box');
    if (renderBox) {
        renderBox.classList.remove('preload');
    }

    if (!container.hasAttribute('tabindex')) {
        container.setAttribute('tabindex', '0');
    }

    if (!container.hasAttribute('role')) {
        container.setAttribute('role', 'button');
    }

    return {
        key,
        index,
        container,
        actions: {
            skeleton: skeletonActions,
            skeletonLine,
            menu: menuActions,
        },
    };
});

const placeholderDimensions = appConfig.placeholderDimensions || null;

if (placeholderDimensions && placeholderDimensions.width && placeholderDimensions.height) {
    document.documentElement.style.setProperty(
        '--gallery-item-aspect-ratio',
        `${placeholderDimensions.width} / ${placeholderDimensions.height}`,
    );
}

const getPlaceholderForSlot = (slot) => {
    return PLACEHOLDER_SRC;
};

const ensurePlaceholderForSlot = (slot) => {
    // Platzhalter werden nun über die Render-Animation bereitgestellt.
};

const setPulseState = (slot, shouldPulse) => {
    if (!slot || !slot.container) {
        return;
    }

    slot.container.classList.toggle('is-pulsing', Boolean(shouldPulse));
};

const clearSlotContent = (slot) => {
    if (!slot || !slot.container) {
        return;
    }

    slot.container.dataset.hasContent = 'false';
    slot.container.dataset.currentSrc = '';
    slot.container.classList.remove('has-image');
    slot.container.classList.remove('has-shadow');
    slot.container.classList.remove('first-active');

    const existingImage = slot.container.querySelector('img');
    if (existingImage) {
        existingImage.remove();
    }

    ensurePlaceholderForSlot(slot);
};

const setSlotLoadingState = (slot, loading) => {
    if (!slot || !slot.container) {
        return;
    }

    slot.container.dataset.isLoading = loading ? 'true' : 'false';

    slot.container.classList.toggle('preload', Boolean(loading));

    const renderBox = slot.container.querySelector('.render-box');
    if (renderBox) {
        renderBox.classList.toggle('preload', Boolean(loading));
    }
};

const setSlotActionState = (slot, options = {}) => {
    if (!slot) {
        return;
    }

    const hasImage = Boolean(options.hasImage);
    const isRunning = Boolean(options.isRunning);
    const skeletonBar = slot.actions?.skeleton;
    const skeletonLine = slot.actions?.skeletonLine || (skeletonBar ? skeletonBar.querySelector('.slot-actions__skeleton-line') : null);
    const menuBar = slot.actions?.menu;

    if (skeletonBar) {
        skeletonBar.classList.toggle('is-hidden', hasImage);
    }

    if (skeletonLine) {
        skeletonLine.classList.toggle('skeleton-line--shimmer', isRunning && !hasImage);
    }

    if (menuBar) {
        menuBar.classList.toggle('is-hidden', !hasImage);
    }
};

const updateSlotActions = (isRunning = workflowIsRunning) => {
    gallerySlots.forEach((slot) => {
        if (!slot || !slot.container) {
            return;
        }

        const hasImage = slot.container.dataset.hasContent === 'true';
        setSlotActionState(slot, { isRunning, hasImage });
    });
};

// Preload-Animation vom aktuellen Bild-Slot entfernen und ggf. auf den nächsten Slot verschieben
function movePreloadToNextSlot() {
    const boxes = Array.from(document.querySelectorAll('.generated-grid .render-shell .render-box'));
    if (!boxes.length) return;

    const currentIndex = boxes.findIndex((box) => box.classList.contains('preload'));
    if (currentIndex === -1) {
        return;
    }

    const currentBox = boxes[currentIndex];
    const currentContainer = currentBox.closest('.generated-slot');
    const currentShell = currentBox.closest('.render-shell');

    currentBox.classList.remove('preload');
    if (currentContainer) {
        currentContainer.classList.remove('preload');
    }
    if (currentShell) {
        currentShell.classList.remove('preload');
    }

    const nextIndex = currentIndex + 1;
    if (nextIndex < boxes.length) {
        const nextBox = boxes[nextIndex];
        const nextContainer = nextBox.closest('.generated-slot');
        const nextShell = nextBox.closest('.render-shell');

        nextBox.classList.add('preload');
        if (nextContainer) {
            nextContainer.classList.add('preload');
        }
        if (nextShell) {
            nextShell.classList.add('preload');
        }
    }
}

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
    setSlotLoadingState(slot, false);

    let imageElement = slot.container.querySelector('img');
    if (!imageElement) {
        imageElement = document.createElement('img');
        slot.container.appendChild(imageElement);
    }

    imageElement.src = resolved;
    imageElement.alt = imageElement.alt || `Produktbild ${slot.index + 1}`;
    slot.container.classList.add('has-image');
    slot.container.classList.add('has-shadow');
    slot.container.classList.remove('is-hidden');
    applyFadeInAnimation(imageElement);
};

const createWorkflowOutputController = () => {
    const STATE_CLASSES = {
        idle: 'is-idle',
        running: 'is-running',
        complete: 'is-complete',
    };

    let currentState = 'idle';

    const applyState = (nextState) => {
        currentState = nextState;

        if (!workflowOutput) {
            return;
        }

        Object.values(STATE_CLASSES).forEach((className) => {
            workflowOutput.classList.remove(className);
        });

        const className = STATE_CLASSES[nextState];
        if (className) {
            workflowOutput.classList.add(className);
        }

        const isActiveState = nextState === 'running' || nextState === 'complete';
        workflowOutput.classList.toggle('is-active', isActiveState);
    };

    const getFilledCount = () =>
        gallerySlots.reduce(
            (count, slot) => (slot?.container?.dataset.hasContent === 'true' ? count + 1 : count),
            0,
        );

    const hasTextContent = () => {
        const nameText = articleNameOutput ? articleNameOutput.textContent.trim() : '';
        const descriptionText = articleDescriptionOutput ? articleDescriptionOutput.textContent.trim() : '';
        return nameText !== '' || descriptionText !== '';
    };

    const ensureSequentialSlots = () => {
        const filledCount = getFilledCount();
        const nextIndex = currentState === 'running' && filledCount < gallerySlots.length ? filledCount : null;

        gallerySlots.forEach((slot, index) => {
            if (!slot?.container) {
                return;
            }

            const hasImage = slot.container.dataset.hasContent === 'true';
            const shouldLoad = nextIndex !== null && index === nextIndex;

            slot.container.classList.remove('is-hidden');

            if (hasImage) {
                setSlotLoadingState(slot, false);
            } else {
                setSlotLoadingState(slot, shouldLoad);
            }
        });

        const firstSlot = gallerySlots[0];
        if (firstSlot?.container) {
            const shouldHighlightFirst = currentState === 'running' && filledCount === 0;
            firstSlot.container.classList.toggle('first-active', shouldHighlightFirst);
        }

        if (currentState === 'running' && nextIndex === null) {
            applyState('complete');
        }
    };

    return {
        init() {
            if (!workflowOutput) {
                return;
            }

            if (getFilledCount() > 0 || hasTextContent()) {
                applyState('complete');
                ensureSequentialSlots();
                return;
            }

            applyState('idle');
            gallerySlots.forEach((slot) => {
                if (slot?.container) {
                    slot.container.classList.remove('is-hidden', 'preload', 'first-active');
                    setSlotLoadingState(slot, false);
                }
            });
        },
        start() {
            if (!workflowOutput) {
                return;
            }

            applyState('running');
            gallerySlots.forEach((slot) => {
                if (!slot?.container) {
                    return;
                }

                clearSlotContent(slot);
            });
            ensureSequentialSlots();
        },
        sync() {
            if (!workflowOutput) {
                return;
            }

            if (currentState === 'idle' && (getFilledCount() > 0 || hasTextContent())) {
                applyState('complete');
            }

            ensureSequentialSlots();
        },
        finish() {
            if (!workflowOutput) {
                return;
            }

            if (getFilledCount() > 0 || hasTextContent()) {
                applyState('complete');
            } else {
                applyState('idle');
                gallerySlots.forEach((slot) => {
                    if (!slot?.container) {
                        return;
                    }

                    setSlotLoadingState(slot, false);
                });
            }

            ensureSequentialSlots();
        },
        reset() {
            if (!workflowOutput) {
                return;
            }

            applyState('idle');
            gallerySlots.forEach((slot) => {
                if (!slot?.container) {
                    return;
                }

                clearSlotContent(slot);
                setSlotLoadingState(slot, false);
                slot.container.classList.remove('is-hidden', 'preload', 'first-active');
            });
        },
    };
};

workflowOutputController = createWorkflowOutputController();
if (workflowOutputController) {
    workflowOutputController.init();
}

const getSlotPreviewData = (slot) => {
    if (!slot || !slot.container) {
        return { src: PLACEHOLDER_SRC, alt: 'Bildvorschau' };
    }

    const imageElement = slot.container.querySelector('img');
    const hasContent = slot.container.dataset.hasContent === 'true' && imageElement && imageElement.src;
    const src = hasContent && imageElement ? imageElement.src : getPlaceholderForSlot(slot);
    const alt = hasContent && imageElement
        ? imageElement.alt || `Produktbild ${slot.index + 1}`
        : `Platzhalter ${slot.index + 1}`;

    return { src, alt };
};

function renderGeneratedImages(images) {
    const imageList = Array.isArray(images)
        ? images
        : gallerySlotKeys.map((key) => {
              if (!images || typeof images !== 'object') {
                  return null;
              }

              return images[key] ?? null;
          });

    gallerySlots.forEach((slot, index) => {
        if (!slot || !slot.container) {
            return;
        }

        const rawData = imageList[index];

        if (rawData) {
            let imageUrl = '';
            let altText = `Generiertes Bild ${index + 1}`;

            if (typeof rawData === 'string') {
                imageUrl = rawData;
            } else if (typeof rawData === 'object') {
                imageUrl = rawData.url || rawData.src || '';
                altText = rawData.alt || rawData.title || altText;
            }

            const resolvedUrl = imageUrl ? toAbsoluteUrl(imageUrl) : '';

            if (resolvedUrl) {
                let imageElement = slot.container.querySelector('img');
                if (!imageElement) {
                    imageElement = document.createElement('img');
                    slot.container.appendChild(imageElement);
                }

                imageElement.src = resolvedUrl;
                imageElement.alt = altText;

                slot.container.classList.add('has-image');
                slot.container.classList.add('has-shadow');
                slot.container.classList.remove('is-hidden');
                slot.container.dataset.hasContent = 'true';
                slot.container.dataset.currentSrc = resolvedUrl;
                slot.container.dataset.isLoading = 'false';
                lastKnownImages[slot.key] = resolvedUrl;

                applyFadeInAnimation(imageElement);
                return;
            }
        }

        clearSlotContent(slot);
        slot.container.dataset.isLoading = 'false';
        lastKnownImages[slot.key] = null;
    });

    updateSlotActions(workflowIsRunning);

    if (workflowOutputController) {
        workflowOutputController.sync();
    }
}

gallerySlots.forEach((slot) => {
    clearSlotContent(slot);
    setSlotLoadingState(slot, false);
});

updateSlotActions(workflowIsRunning);

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
    const hasRun = Number.isFinite(window.currentRunId) && window.currentRunId > 0;
    updateStartButtonState(hasRun);

    if (!startWorkflowButton) {
        return;
    }

    if (isProcessing || isStartingWorkflow) {
        startWorkflowButton.setAttribute('disabled', 'true');
        startWorkflowButton.classList.add('is-disabled');
        startWorkflowButton.setAttribute('aria-disabled', 'true');
    }
}

function clearWorkflowFeedback() {
    setStatusMessage('', 'info');
}

function showWorkflowFeedback(level, message) {
    const normalizedMessage = sanitizeLogMessage(message);

    if (!normalizedMessage) {
        clearWorkflowFeedback();
        return;
    }

    const normalizedLevel = typeof level === 'string' ? level.trim().toLowerCase() : '';
    let statusType = 'info';

    if (normalizedLevel === 'error') {
        statusType = 'error';
    } else if (normalizedLevel === 'success') {
        statusType = 'success';
    }

    setStatusMessage(normalizedMessage, statusType);
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

    setArticleFieldsLoading(Boolean(loading));

    if (loading) {
        hasObservedActiveRun = true;
        if (workflowOutputController) {
            workflowOutputController.start();
        }
    } else if (!options || options.indicatorState !== 'success') {
        hasObservedActiveRun = false;
        if (workflowOutputController) {
            workflowOutputController.finish();
        }
    } else if (workflowOutputController) {
        workflowOutputController.finish();
    }

    if (loading) {
        hasShownCompletion = false;
    }

    if (options && options.indicatorText) {
        updateProcessingIndicator(options.indicatorText, options.indicatorState || 'idle');
    }

    if (loading) {
        workflowIsRunning = true;
    }

    updateSlotActions(workflowIsRunning || Boolean(loading));

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
    wrapper.className = 'preview-item__media';

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
        }
    });
};

const uploadFiles = async (files) => {
    const fileList = Array.from(files || []);
    if (fileList.length === 0) {
        return;
    }

    stopPolling();
    hasObservedActiveRun = false;
    workflowIsRunning = false;

    setStatusAndLog('info', 'Bild wird hochgeladen …', 'UPLOAD_STARTED');
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
                setStatusAndLog('error', errorMessage, 'UPLOAD_FAILED');
                console.error('Upload fehlgeschlagen', {
                    status: response.status,
                    payload,
                });
                setLoadingState(false, { indicatorText: 'Bereit.', indicatorState: 'idle' });
                return;
            }

            const result = typeof payload === 'object' && payload !== null ? payload : {};

            const successValue = result.success;
            const isSuccessful =
                successValue === true ||
                successValue === 'true' ||
                successValue === 1 ||
                successValue === '1';

            console.log('Upload-Antwort', {
                status: response.status,
                payload,
            });

            if (!isSuccessful) {
                const errorMessage = typeof result.message === 'string'
                    ? result.message
                    : `Upload fehlgeschlagen: ${file.name}`;
                setStatusAndLog('error', errorMessage, 'UPLOAD_FAILED');
                setLoadingState(false, { indicatorText: 'Bereit.', indicatorState: 'idle' });
                return;
            }

            setStatusAndLog('success', 'Upload erfolgreich – Workflow kann gestartet werden..', 'UPLOAD_SUCCESS');
            setLoadingState(false, { indicatorText: 'Bereit für Workflow-Start', indicatorState: 'idle' });
            hasShownCompletion = false;

            const previousRunId = window.currentRunId;
            const rawImageUrl = typeof result.image_url === 'string' ? result.image_url.trim() : '';

            if (result.file) {
                addPreviews([{ url: result.file, name: result.name || file.name }]);
            }

            if (result.run_id !== undefined && result.run_id !== null) {
                const parsedRunId = Number(result.run_id);
                activeRunId = Number.isFinite(parsedRunId) && parsedRunId > 0 ? parsedRunId : null;
                setCurrentRun(parsedRunId, result.user_id);
            } else {
                activeRunId = null;
                setCurrentRun(null, result.user_id);
            }

            try {
                logFrontendStatus('UPLOAD_SUCCESS');
                // auch wenn das Polling noch nicht läuft, können wir den Feed einmalig ziehen
                fetchStatusFeed().catch((err) => {
                    console.error('Status-Feed-Fehler nach Upload:', err);
                });
            } catch (e) {
                console.error('Fehler beim Logging von UPLOAD_SUCCESS:', e);
            }


            const runChanged = window.currentRunId !== previousRunId && window.currentRunId !== null;

            if (runChanged) {
                window.currentOriginalImages = [];
                clearOriginalImagePreviews();
            }

            showWorkflowFeedback('info', 'Upload erfolgreich. Bitte Workflow starten.');
            updateProcessingIndicator('Bereit für Workflow-Start', 'idle');

            if (rawImageUrl) {
                ensureOriginalImagesState();

                if (window.currentOriginalImages.length === 0) {
                    clearOriginalImagePreviews();
                }

                appendOriginalImagePreview(rawImageUrl);
            }
        } catch (error) {
            console.error(error);
            const fallback = `Beim Upload ist ein Fehler aufgetreten (${file.name}).`;
            const message = sanitizeLogMessage(error?.message) || fallback;
            console.error(message);
            setLoadingState(false, { indicatorText: 'Bereit.', indicatorState: 'idle' });
            setStatusAndLog('error', 'Uploadfehler – bitte erneut versuchen.', 'UPLOAD_FAILED');
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

function onWorkflowStarted() {
    const out = document.getElementById('workflow-output');
    if (out) {
        out.classList.remove('is-idle');
    }
}

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
    onWorkflowStarted();
    isStartingWorkflow = true;
    updateWorkflowButtonState();

    if (startWorkflowButton) {
        startWorkflowButton.setAttribute('aria-busy', 'true');
    }

    try {
        const payload = {
            run_id: numericRunId,
            user_id: window.currentUserId,
        };

        const firstImage = Array.isArray(window.currentOriginalImages)
            ? window.currentOriginalImages[0]
            : null;
        const secondImage = Array.isArray(window.currentOriginalImages)
            ? window.currentOriginalImages[1]
            : null;

        const resolvedFirstImage = firstImage ? toAbsoluteUrl(firstImage) : '';
        const resolvedSecondImage = secondImage ? toAbsoluteUrl(secondImage) : '';

        if (resolvedFirstImage) {
            payload.image_url = resolvedFirstImage;
        }

        if (resolvedSecondImage) {
            payload.image_url_2 = resolvedSecondImage;
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
            setStatusAndLog('error', message, 'WORKFLOW_START_FAILED');

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
        setStatusAndLog('info', 'Verarbeitung läuft …', 'WORKFLOW_STARTED');
        setLoadingState(true, { indicatorText: 'Verarbeitung läuft…', indicatorState: 'running' });
        clearProductFields({ loading: true });
        const hasPreviewImage = Boolean(previewList && previewList.childElementCount > 0);
        if (hasPreviewImage) {
            setScanOverlayActive(true);
        }
        hasShownCompletion = false;

        startPolling();
    } catch (error) {
        const fallback = 'Workflow konnte nicht gestartet werden.';
        const message = sanitizeLogMessage(error?.message) || fallback;

        console.error('Workflow-Start fehlgeschlagen', error);
        showWorkflowFeedback('error', message);
        setStatusAndLog('error', message, 'WORKFLOW_START_FAILED');
        //mb

                logFrontendStatus('WORKFLOW_START_FAILED');
                // auch wenn das Polling noch nicht läuft, können wir den Feed einmalig ziehen
                fetchStatusFeed().catch((err) => {
                    console.error('Status-Feed-Fehler nach Upload:', err);
                });     

    } finally {
        isStartingWorkflow = false;
        if (startWorkflowButton) {
            startWorkflowButton.removeAttribute('aria-busy');
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

    updateArticleFieldsFromData(data);

    gallerySlots.forEach((slot) => {
        const value = getDataField(data, slot.key);
        if (value) {
            setSlotImageSource(slot, value);
        } else if (!isProcessing && slot.container && slot.container.dataset.hasContent !== 'true') {
            clearSlotContent(slot);
            setSlotLoadingState(slot, false);
        }
    });

    updateSlotActions(isRunning);

    if (workflowOutputController) {
        workflowOutputController.sync();
    }

    if (!isRunning) {
        stopPolling();
    }
};

const applyRunDataToUI = (payload) => {
    const data = payload && typeof payload === 'object' ? payload : {};
    const note = data.note && typeof data.note === 'object' ? data.note : {};
    const images = Array.isArray(data.images) ? data.images : [];
    const originalImage = typeof data.original_image === 'string' ? data.original_image.trim() : '';
    const originalImagesFromPayload = Array.isArray(data.original_images) ? data.original_images : [];
    const originalImagesToDisplay = originalImagesFromPayload.length > 0
        ? originalImagesFromPayload
        : (originalImage ? [originalImage] : []);
    renderOriginalImagePreviews(originalImagesToDisplay);
    let runIsRunning = false;
    let statusRaw = '';
    let indicatorMessage = '';
    let indicatorState = 'idle';

    updateArticleFieldsFromData({ ...data, note });

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

    workflowIsRunning = runIsRunning;
    updateSlotActions(runIsRunning);

    if (workflowOutputController) {
        workflowOutputController.sync();
    }

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

    applyStatusBarMessage(data, { isRunning: runIsRunning });

    if (previewList) {
        previewList.innerHTML = '';
    }

    const hasOriginalPreviews = window.currentOriginalImages.length > 0;
    setScanOverlayActive(runIsRunning && hasOriginalPreviews);
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

        const runs = Array.isArray(json.data) ? json.data : [];

        const activeIdCandidate = [activeRunId, window.currentRunId, selectedHistoryRunId]
            .map((value) => (Number.isFinite(Number(value)) ? Number(value) : null))
            .find((value) => value !== null && value > 0);

        if (activeIdCandidate) {
            const activeRun = runs.find((run) => Number(run?.id) === Number(activeIdCandidate));
            if (activeRun) {
                const key = String(activeRun.id);
                const previousStatus = lastHandledStepStatusByRun[key] || null;
                const currentStatus = activeRun.last_step_status || null;

                if (currentStatus === 'error' && previousStatus !== 'error') {
                    movePreloadToNextSlot();
                }

                lastHandledStepStatusByRun[key] = currentStatus;
            }
        }

        renderRuns(runs);
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

        try {
            await fetchStatusFeed();
        } catch (feedError) {
            console.error('Status-Feed-Fehler:', feedError);
        }

        const hasIsRunning = normalized && Object.prototype.hasOwnProperty.call(normalized, 'isrunning');
        const isRunning = hasIsRunning ? toBoolean(normalized.isrunning) : toBoolean(payload.isrunning);
        const statusBarText = resolveStatusBarMessage(payload);

        const statusLabelRaw =
            (normalized && typeof normalized.status === 'string' && normalized.status.trim() !== '')
                ? normalized.status
                : (typeof payload.status === 'string' ? payload.status : '');
        const statusLabel = statusLabelRaw ? statusLabelRaw.trim().toLowerCase() : '';

        if (!isRunning) {
            if (statusLabel === 'pending') {
                setStatusAndLog('info', 'Bereit für Workflow-Start', 'WORKFLOW_PENDING');
                if (!statusBarText) {
                    showWorkflowFeedback('info', 'Workflow bereit zum Start.');
                }
                updateProcessingIndicator('Bereit für Workflow-Start', 'idle');
                if (payload.run_id !== undefined && payload.run_id !== null) {
                    setCurrentRun(payload.run_id);
                }
            } else {
                setStatusAndLog('success', 'Workflow abgeschlossen', 'WORKFLOW_COMPLETED');
                if (!statusBarText) {
                    showWorkflowFeedback('success', 'Workflow abgeschlossen.');
                }
                stopPolling();
                activeRunId = null;
                setCurrentRun(null);
            }
        } else {
            setStatusAndLog('info', 'Verarbeitung läuft …', 'WORKFLOW_RUNNING');
            if (!statusBarText) {
                showWorkflowFeedback('info', 'Verarbeitung läuft …');
            }
        }
    } catch (error) {
        console.error('Polling-Fehler:', error);
        setStatusAndLog('error', 'Fehler beim Abrufen des Status', 'STATUS_POLLING_ERROR');
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
        if (!slot.container.classList.contains('has-image')) {
            return;
        }

        const { src, alt } = getSlotPreviewData(slot);
        if (!src || src === PLACEHOLDER_SRC) {
            return;
        }

        openLightbox(src, alt);
    };

    slot.container.addEventListener('click', open);
    slot.container.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
            if (!slot.container.classList.contains('has-image')) {
                return;
            }

            event.preventDefault();
            open();
        }
    });
});

function clearProductFields(options = {}) {
    const shouldLoad = Boolean(options.loading);

    if (articleNameOutput) {
        articleNameOutput.textContent = '';
    }

    if (articleDescriptionOutput) {
        articleDescriptionOutput.textContent = '';
    }

    setArticleFieldsLoading(shouldLoad);
}

function showPlaceholderImages(withPulse = false) {
    if (previewList) {
        previewList.innerHTML = '';
    }

    setScanOverlayActive(false);

    if (workflowOutputController) {
        workflowOutputController.reset();
    } else {
        gallerySlots.forEach((slot) => {
            clearSlotContent(slot);
            setSlotLoadingState(slot, Boolean(withPulse));
        });
    }

    Object.keys(lastKnownImages).forEach((key) => {
        lastKnownImages[key] = null;
    });
}

async function logFrontendStatus(statusCode) {
    try {
        const payload = { status_code: statusCode };

        if (window.currentRunId) {
            payload.run_id = window.currentRunId;
        } else if (typeof activeRunId !== 'undefined' && activeRunId) {
            payload.run_id = activeRunId;
        }

        const response = await fetch('api/log-status-event.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });

        if (!response.ok) {
            console.error('log-status-event.php antwortet mit HTTP', response.status);
        }
    } catch (error) {
        console.error('Frontend-Status konnte nicht geloggt werden:', error);
    }
}

function setStatusAndLog(level, message, statusCode) {
    setStatus(level, message);

    if (!statusCode) {
        return;
    }

    try {
        const result = logFrontendStatus(statusCode);
        if (result && typeof result.catch === 'function') {
            result.catch((error) => {
                console.error('Fehler beim Logging des Frontend-Status:', error);
            });
        }
    } catch (error) {
        console.error('Fehler beim Aufruf von logFrontendStatus:', error);
    }
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
    setStatusAndLog('ready', 'Bereit zum Upload', 'READY_FOR_UPLOAD');
    clearProductFields({ loading: false });
    setLoadingState(false, { indicatorText: 'Bereit.', indicatorState: 'idle' });
    showPlaceholderImages(withPulse);
    hasShownCompletion = false;
    hasObservedActiveRun = false;
    workflowIsRunning = false;
    renderOriginalImagePreviews([]);
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

document.addEventListener('DOMContentLoaded', initCopyButtons);

document.addEventListener('DOMContentLoaded', () => {
    setInitialUiState();
    setupUploadHandler();
    setupHistoryHandler();
    setupSidebarProfileMenu();

    if (startWorkflowButton) {
        startWorkflowButton.addEventListener('click', startWorkflow);
    }

    updateWorkflowButtonState();
});

document.addEventListener('DOMContentLoaded', () => {
    // Finde alle Toggle-Buttons (2K, 4K, Edit)
    const toggleButtons = document.querySelectorAll('.btn-toggle');

    toggleButtons.forEach(btn => {
        btn.addEventListener('click', (e) => {
            // Verhindere, dass der Klick das Bild öffnet (Bubbling)
            e.stopPropagation();

            // Hole die Gruppe von Buttons (innerhalb derselben Karte)
            const parentBar = btn.closest('.slot-actions');
            
            // OPTIONAL: Wenn 2K und 4K sich gegenseitig ausschließen sollen (Radio-Verhalten):
            
            if (btn.dataset.type === '2k' || btn.dataset.type === '4k') {
                const siblings = parentBar.querySelectorAll('.btn-toggle');
                siblings.forEach(sibling => {
                    if (sibling !== btn && (sibling.dataset.type === '2k' || sibling.dataset.type === '4k')) {
                        sibling.classList.remove('is-active');
                    }
                });
            }
            

            // Toggle die Klasse 'is-active'
            // Wenn sie da ist, wird sie entfernt (deaktiviert). 
            // Wenn sie nicht da ist, wird sie hinzugefügt (aktiviert).
            btn.classList.toggle('is-active');
        });
    });

    // Logik für den Play Button (Optional: Feedback beim Klick)
    const playButtons = document.querySelectorAll('.btn-primary');
    playButtons.forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            // Kurze Animation oder Logik hier starten
            console.log("Workflow starten für diesen Slot");
        });
    });
});
