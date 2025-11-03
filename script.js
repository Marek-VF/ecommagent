const dropZone = document.getElementById('drop-zone');
const fileInput = document.getElementById('file-input');
const selectFileButton = document.getElementById('select-file');
const previewList = document.getElementById('upload-previews');
const lightbox = document.getElementById('lightbox');
const lightboxImage = lightbox.querySelector('.lightbox__image');
const lightboxClose = lightbox.querySelector('.lightbox__close');
const processingIndicator = document.getElementById('processing-indicator');
const statusLogContainer = document.getElementById('status-log');
const articleNameInput = document.getElementById('article-name');
const articleDescriptionInput = document.getElementById('article-description');

let workflowIsRunning = false;

const uploadEndpoint = 'upload.php';
const initializationEndpoint = 'init.php';
const STATUS_LOG_LIMIT = 60;
const POLLING_INTERVAL = 2000;
const DATA_ENDPOINT = 'api/get-latest-item.php';

// zentrale Normalisierungstabelle
const STATUS_NORMALIZER = [
    {
        key: 'workflow_started',
        tests: [
            /workflow was started/i,
            /workflow gestartet/i,
            /upload.*workflow.*gestartet/i,
            /200/i,
        ],
        text: 'Workflow wurde gestartet.',
        level: 'ok',
    },
    {
        key: 'meta_saved',
        tests: [
            /name und beschreibung erfolgreich erstellt/i,
            /produktname/i,
            /beschreibung.*gespeichert/i,
        ],
        text: 'Name und Beschreibung erfolgreich erstellt.',
        level: 'ok',
    },
    {
        key: 'image_1_saved',
        tests: [
            /image[_\s]?1/i,
            /bild 1/i,
        ],
        text: 'Bild 1 erfolgreich gespeichert.',
        level: 'ok',
    },
    {
        key: 'image_2_saved',
        tests: [
            /image[_\s]?2/i,
            /bild 2/i,
        ],
        text: 'Bild 2 erfolgreich gespeichert.',
        level: 'ok',
    },
    {
        key: 'image_3_saved',
        tests: [
            /image[_\s]?3/i,
            /bild 3/i,
        ],
        text: 'Bild 3 erfolgreich gespeichert.',
        level: 'ok',
    },
    {
        key: 'workflow_finished',
        tests: [
            /workflow abgeschlossen/i,
            /workflow finished/i,
            /isrunning:? ?false/i,
        ],
        text: 'Workflow abgeschlossen.',
        level: 'ok',
    },
];

// schon ausgegebene Schlüssel
const SHOWN_STATUS_KEYS = new Set();

const normalizeStatusMessage = (rawMessage = '', httpStatus = null) => {
    const msg = String(rawMessage || '').trim();

    if (typeof httpStatus === 'number' && httpStatus >= 400) {
        return {
            key: `http_${httpStatus}`,
            text: msg || `Fehler vom Server (${httpStatus})`,
            level: 'error',
        };
    }

    for (const def of STATUS_NORMALIZER) {
        for (const re of def.tests) {
            if (re.test(msg)) {
                return {
                    key: def.key,
                    text: def.text,
                    level: def.level,
                };
            }
        }
    }

    return {
        key: msg.toLowerCase(),
        text: msg,
        level: 'info',
    };
};

const STATUS_LEVEL_CLASS_MAP = {
    ok: 'log-ok',
    error: 'log-err',
    info: 'log-info',
};

const renderNormalizedStatus = (normalized) => {
    if (!statusLogContainer || !normalized || !normalized.text) {
        return;
    }

    if (SHOWN_STATUS_KEYS.has(normalized.key)) {
        return;
    }

    SHOWN_STATUS_KEYS.add(normalized.key);

    if (statusLogContainer.childElementCount === 0) {
        const existingText = statusLogContainer.textContent;
        if (typeof existingText === 'string' && existingText.trim() !== '') {
            statusLogContainer.textContent = '';
        }
    }

    const finalClass = STATUS_LEVEL_CLASS_MAP[normalized.level] || STATUS_LEVEL_CLASS_MAP.info;
    const entry = document.createElement('p');
    entry.className = `log-entry ${finalClass}`;
    entry.textContent = normalized.text;

    statusLogContainer.prepend(entry);
    trimStatusLog();
    scrollStatusLogToTop();
};

const applyLevelHint = (normalized, levelHint) => {
    const mapped = mapTypeHint(levelHint);
    if (!mapped) {
        return normalized;
    }

    if (mapped === 'error') {
        return { ...normalized, level: 'error' };
    }

    if (mapped === 'ok' && normalized.level !== 'error') {
        return { ...normalized, level: 'ok' };
    }

    if (mapped === 'info' && normalized.level === undefined) {
        return { ...normalized, level: 'info' };
    }

    return normalized;
};

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

    if (statusLogContainer && statusLogContainer.childElementCount === 0) {
        statusLogContainer.textContent = resolveLatestStatusMessage(data);
    }
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

    if (payload && typeof payload === 'object' && payload.images && typeof payload.images === 'object') {
        gallerySlotKeys.forEach((key) => {
            const value = payload.images[key];
            if (typeof value === 'string') {
                const trimmed = value.trim();
                if (trimmed !== '') {
                    normalized[key] = trimmed;
                }
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
const INDICATOR_STATE_CLASSES = [
    'status-panel__indicator--running',
    'status-panel__indicator--success',
    'status-panel__indicator--warning',
    'status-panel__indicator--error',
];

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

    if (slot.container.dataset.currentSrc === sanitized && slot.container.dataset.hasContent === 'true') {
        slot.container.dataset.isLoading = 'false';
        return;
    }

    slot.container.dataset.currentSrc = sanitized;
    slot.container.dataset.hasContent = 'true';
    slot.container.dataset.isLoading = 'false';

    if (slot.content) {
        slot.content.src = sanitized;
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
let pollingTimer = null;
let isPollingActive = false;
let lastStatusText = '';
let hasShownCompletion = false;
let hasObservedActiveRun = false;

const TYPE_KEYWORDS = {
    ok: ['success', 'ok', 'positive', 'completed'],
    error: ['error', 'fail', 'failed', 'danger'],
    info: ['info', 'warning', 'warn', 'caution'],
};

const UNAUTHORIZED_PATTERNS = [
    /unauthorized/i,
    /anmeldung erforderlich/i,
    /ungültiger oder fehlender bearer-token/i,
];

const MESSAGE_PATHS = [
    ['message'],
    ['msg'],
    ['statusmessage'],
    ['status_message'],
    ['error'],
    ['errors', 0, 'message'],
    ['status', 'text'],
];

const CODE_PATHS = [
    ['statuscode'],
    ['status_code'],
    ['code'],
    ['status', 'code'],
    ['status', 'status_code'],
    ['httpstatus'],
    ['http_status'],
    ['httpcode'],
    ['http_code'],
];

const TYPE_PATHS = [
    ['type'],
    ['status', 'type'],
    ['status', 'status'],
    ['status', 'state'],
];

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

const mapTypeHint = (value) => {
    if (typeof value !== 'string') {
        return null;
    }

    const normalized = value.trim().toLowerCase();
    if (!normalized) {
        return null;
    }

    if (TYPE_KEYWORDS.ok.includes(normalized)) {
        return 'ok';
    }

    if (TYPE_KEYWORDS.error.includes(normalized)) {
        return 'error';
    }

    if (TYPE_KEYWORDS.info.includes(normalized)) {
        return 'info';
    }

    return null;
};

const getCaseInsensitive = (object, key) => {
    if (!object || typeof object !== 'object') {
        return undefined;
    }

    if (Object.prototype.hasOwnProperty.call(object, key)) {
        return object[key];
    }

    const lowerKey = key.toLowerCase();
    const matchingKey = Object.keys(object).find((candidate) => candidate.toLowerCase() === lowerKey);

    if (matchingKey) {
        return object[matchingKey];
    }

    return undefined;
};

const getValueByPath = (object, path) => {
    if (!object || typeof object !== 'object') {
        return undefined;
    }

    let current = object;

    for (const segment of path) {
        if (current === null || current === undefined) {
            return undefined;
        }

        if (typeof segment === 'number') {
            if (!Array.isArray(current) || current.length <= segment) {
                return undefined;
            }

            current = current[segment];
            continue;
        }

        current = getCaseInsensitive(current, segment);
    }

    return current;
};

const extractMessageAndCode = (input) => {
    if (input === null || input === undefined) {
        return { message: null, code: null, type: null };
    }

    if (['string', 'number', 'boolean'].includes(typeof input)) {
        const message = sanitizeLogMessage(input);
        return {
            message: message || null,
            code: null,
            type: null,
        };
    }

    const result = {
        message: null,
        code: null,
        type: null,
    };

    const visited = new Set();

    const traverse = (value) => {
        if (!value || typeof value !== 'object' || visited.has(value)) {
            return;
        }

        visited.add(value);

        if (result.message === null) {
            for (const path of MESSAGE_PATHS) {
                const candidate = getValueByPath(value, path);

                if (candidate === undefined || candidate === null) {
                    continue;
                }

                if (['string', 'number', 'boolean'].includes(typeof candidate)) {
                    const sanitized = sanitizeLogMessage(candidate);
                    if (sanitized) {
                        result.message = sanitized;
                        break;
                    }
                } else if (typeof candidate === 'object') {
                    traverse(candidate);
                    if (result.message !== null) {
                        break;
                    }
                }
            }
        }

        if (result.code === null) {
            for (const path of CODE_PATHS) {
                const candidate = getValueByPath(value, path);

                if (candidate === undefined || candidate === null || candidate === '') {
                    continue;
                }

                if (typeof candidate === 'string' || typeof candidate === 'number') {
                    const sanitized = String(candidate).trim();
                    if (sanitized) {
                        result.code = sanitized;
                        break;
                    }
                }
            }
        }

        if (result.type === null) {
            for (const path of TYPE_PATHS) {
                const candidate = getValueByPath(value, path);

                if (typeof candidate === 'string' && candidate.trim() !== '') {
                    const mapped = mapTypeHint(candidate);
                    if (mapped) {
                        result.type = mapped;
                        break;
                    }
                }
            }
        }

        Object.keys(value).forEach((key) => {
            if (result.message !== null && result.code !== null && result.type !== null) {
                return;
            }

            const child = value[key];

            if (Array.isArray(child)) {
                child.forEach((item) => traverse(item));
            } else if (child && typeof child === 'object') {
                traverse(child);
            } else if (result.message === null && (typeof child === 'string' || typeof child === 'number')) {
                const sanitized = sanitizeLogMessage(child);
                if (sanitized) {
                    result.message = sanitized;
                }
            }
        });
    };

    traverse(input);

    return result;
};

const isUnauthorized = (status, message) => {
    if (status === 401) {
        return true;
    }

    if (typeof message !== 'string') {
        return false;
    }

    return UNAUTHORIZED_PATTERNS.some((pattern) => pattern.test(message));
};

const buildUnauthorizedMessage = (message) => {
    const sanitized = sanitizeLogMessage(message) || 'Zugriff verweigert.';

    if (/^nicht autorisiert/i.test(sanitized)) {
        return sanitized;
    }

    return `Nicht autorisiert: ${sanitized}`;
};

const trimStatusLog = () => {
    if (!statusLogContainer) {
        return;
    }

    while (statusLogContainer.children.length > STATUS_LOG_LIMIT) {
        statusLogContainer.removeChild(statusLogContainer.lastElementChild);
    }
};

const scrollStatusLogToTop = () => {
    if (!statusLogContainer) {
        return;
    }

    if (typeof statusLogContainer.scrollTo === 'function') {
        statusLogContainer.scrollTo({ top: 0, behavior: 'smooth' });
    } else {
        statusLogContainer.scrollTop = 0;
    }
};

const logMessage = (message, type = 'info') => {
    const normalizedMessage = sanitizeLogMessage(message);
    if (!normalizedMessage) {
        return;
    }

    const normalized = applyLevelHint(normalizeStatusMessage(normalizedMessage), type);
    renderNormalizedStatus(normalized);
};

const logResponse = ({ httpStatus, payload, fallbackMessage, fallbackType }) => {
    const { message, type } = extractMessageAndCode(payload);
    const fallback = sanitizeLogMessage(fallbackMessage);
    const fromPayload = sanitizeLogMessage(message);
    const httpCode = Number.isFinite(httpStatus) ? httpStatus : Number(httpStatus);
    const effectiveStatus = Number.isFinite(httpCode) ? httpCode : null;

    if (isUnauthorized(effectiveStatus, fromPayload || fallback)) {
        const unauthorizedMessage = buildUnauthorizedMessage(message || fallbackMessage);
        const normalized = normalizeStatusMessage(unauthorizedMessage, effectiveStatus || 401);
        renderNormalizedStatus({ ...normalized, level: 'error' });
        return;
    }

    const baseMessage = fromPayload || fallback || '';
    const normalized = normalizeStatusMessage(baseMessage, effectiveStatus);
    const levelHint = type || fallbackType || (effectiveStatus && effectiveStatus >= 200 && effectiveStatus < 300
        ? 'ok'
        : null);
    const adjusted = applyLevelHint(normalized, levelHint);
    renderNormalizedStatus(adjusted);
};

const updateStatusLog = (entries) => {
    if (!statusLogContainer || !Array.isArray(entries)) {
        return;
    }

    entries.forEach((rawEntry) => {
        if (!rawEntry || typeof rawEntry !== 'object') {
            return;
        }

        const { message, type } = extractMessageAndCode(rawEntry);
        const fallbackMessage = typeof rawEntry.message === 'string' ? rawEntry.message : '';
        const sanitizedMessage = sanitizeLogMessage(message) || sanitizeLogMessage(fallbackMessage) || '';
        const httpStatus = Number.isFinite(rawEntry.status)
            ? rawEntry.status
            : Number(rawEntry.status);
        const effectiveStatus = Number.isFinite(httpStatus) ? httpStatus : null;

        const normalized = normalizeStatusMessage(sanitizedMessage, effectiveStatus);
        const adjusted = applyLevelHint(
            normalized,
            type || rawEntry.type || rawEntry.level || (effectiveStatus && effectiveStatus >= 200 && effectiveStatus < 300
                ? 'ok'
                : null),
        );

        renderNormalizedStatus(adjusted);
    });
};

const updateProcessingIndicator = (text, state = 'idle') => {
    if (!processingIndicator) {
        return;
    }

    processingIndicator.textContent = text;
    INDICATOR_STATE_CLASSES.forEach((className) => processingIndicator.classList.remove(className));

    if (state && state !== 'idle') {
        processingIndicator.classList.add(`status-panel__indicator--${state}`);
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
        if (loading && workflowIsRunning) {
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
    const item = document.createElement('figure');
    item.className = 'preview-item';
    item.tabIndex = 0;
    item.innerHTML = `<img src="${url}" alt="${name}">`;

    const openPreview = () => openLightbox(url, name);

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
        previewList.prepend(previewItem);
    });
};

const stopPolling = () => {
    if (pollingTimer !== null) {
        clearInterval(pollingTimer);
        pollingTimer = null;
    }
    isPollingActive = false;
};

const startPolling = () => {
    if (isPollingActive) {
        return;
    }

    isPollingActive = true;
    fetchLatestData();
    pollingTimer = setInterval(fetchLatestData, POLLING_INTERVAL);
};

const uploadFiles = async (files) => {
    const uploads = Array.from(files).map(async (file) => {
        const formData = new FormData();
        formData.append('image', file);

        logMessage(`Starte Upload: ${file.name}`, 'info');

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
                logResponse({
                    httpStatus: response.status,
                    payload,
                    fallbackMessage: `Upload fehlgeschlagen: ${file.name}`,
                    fallbackType: 'error',
                });
                setLoadingState(false, { indicatorText: 'Bereit.', indicatorState: 'idle' });
                return;
            }

            const result = typeof payload === 'object' && payload !== null ? payload : {};

            if (result.file) {
                addPreviews([{ url: result.file, name: result.name || file.name }]);
            }

            const isSuccessful = result.success !== false;
            const fallbackMessage = isSuccessful
                ? `Upload abgeschlossen: ${result.name || file.name}`
                : `Upload fehlgeschlagen: ${file.name}`;

            logResponse({
                httpStatus: response.status,
                payload,
                fallbackMessage,
                fallbackType: isSuccessful ? 'ok' : 'error',
            });

            if (!isSuccessful) {
                setLoadingState(false, { indicatorText: 'Bereit.', indicatorState: 'idle' });
                return;
            }

            setLoadingState(true);
            if (processingIndicator) {
                processingIndicator.textContent = 'Verarbeitung läuft…';
            }
            hasShownCompletion = false;
            startPolling();

            if (result.webhook_response !== undefined && result.webhook_response !== null && result.webhook_response !== '') {
                const webhookPayload = result.webhook_response;
                const webhookStatus = Number.isFinite(result.webhook_status)
                    ? result.webhook_status
                    : Number(result.webhook_status);
                const { message: webhookMessage, type: webhookType } = extractMessageAndCode(webhookPayload);
                const fallbackWebhookMessage = typeof result.webhook_response === 'string'
                    ? result.webhook_response
                    : webhookStatus
                        ? `Webhook ${String(webhookStatus).trim()}`
                        : 'Webhook-Antwort';
                const sanitizedWebhookMessage = sanitizeLogMessage(webhookMessage)
                    || sanitizeLogMessage(fallbackWebhookMessage)
                    || '';

                const normalizedWebhook = normalizeStatusMessage(
                    sanitizedWebhookMessage,
                    Number.isFinite(webhookStatus) ? webhookStatus : null,
                );
                const adjustedWebhook = applyLevelHint(
                    normalizedWebhook,
                    webhookType || (Number.isFinite(webhookStatus) && webhookStatus >= 200 && webhookStatus < 300 ? 'ok' : null),
                );

                renderNormalizedStatus(adjustedWebhook);
            }
        } catch (error) {
            console.error(error);
            const fallback = `Beim Upload ist ein Fehler aufgetreten (${file.name}).`;
            const message = sanitizeLogMessage(error?.message) || fallback;
            logMessage(message, 'error');
            setLoadingState(false, { indicatorText: 'Bereit.', indicatorState: 'idle' });
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

const openLightbox = (src, alt) => {
    lightboxImage.src = src;
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

    if (isRunning && !isProcessing) {
        setLoadingState(true, { indicatorText: 'Verarbeitung läuft…', indicatorState: 'running' });
    } else if (!isRunning && isProcessing) {
        setLoadingState(false, { indicatorText: 'Workflow abgeschlossen', indicatorState: 'success' });
    } else if (!isRunning && !isProcessing) {
        updateProcessingIndicator('Bereit.', 'idle');
    }

    if (!isRunning && hasObservedActiveRun && !hasShownCompletion) {
        logMessage('Workflow abgeschlossen.', 'ok');
        hasShownCompletion = true;
        updateProcessingIndicator('Workflow abgeschlossen', 'success');
        hasObservedActiveRun = false;
    } else if (isRunning) {
        hasShownCompletion = false;
        updateProcessingIndicator('Verarbeitung läuft…', 'running');
    }

    if (!isRunning && !hasShownCompletion && data.updated_at) {
        logMessage('Verarbeitung abgeschlossen.', 'ok');
        hasShownCompletion = true;
    }

    if (typeof data.status_message === 'string') {
        const trimmed = data.status_message.trim();
        if (trimmed && trimmed !== lastStatusText) {
            logMessage(trimmed, 'info');
            lastStatusText = trimmed;
        }
    }

    if (Array.isArray(data.statuslog)) {
        updateStatusLog(data.statuslog);
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

const fetchLatestData = async () => {
    try {
        const response = await fetch(`${DATA_ENDPOINT}?${Date.now()}`, {
            cache: 'no-store',
        });

        if (!response.ok) {
            throw new Error(`Serverantwort ${response.status}`);
        }

        const raw = await response.json();
        if (!raw || typeof raw !== 'object') {
            return;
        }

        if (raw.ok !== true) {
            return;
        }

        if (raw.has_data === false) {
            return;
        }

        const payload = raw.data && typeof raw.data === 'object' ? raw.data : {};

        // Workflowstatus vom Backend prüfen
        if (typeof payload.isrunning === 'boolean') {
            if (payload.isrunning && !workflowIsRunning) {
                workflowIsRunning = true;
                setLoadingState(true, {
                    indicatorText: 'Verarbeitung läuft…',
                    indicatorState: 'running',
                });
            } else if (!payload.isrunning && workflowIsRunning) {
                workflowIsRunning = false;
                setLoadingState(false, {
                    indicatorText: 'Workflow abgeschlossen',
                    indicatorState: 'success',
                });
                stopPolling();
            }
        }

        applyLatestItemData(payload);
        const normalized = mapLatestItemPayloadToLegacy(payload);
        updateInterfaceFromData(normalized);

        if (payload.images && typeof payload.images === 'object') {
            gallerySlots.forEach((slot) => {
                const src = payload.images[slot.key];
                if (typeof src === 'string' && src.trim() !== '') {
                    setSlotImageSource(slot, src.trim());
                }
            });
        }

    } catch (error) {
        console.error('Polling-Fehler:', error);
        const fallbackMessage = 'Verbindung zum Server fehlgeschlagen.';
        const sanitized = sanitizeLogMessage(error?.message) || fallbackMessage;
        const statusMatch = sanitized.match(/(\d{3})/);
        const statusCode = statusMatch ? Number(statusMatch[1]) : 500;
        const normalized = normalizeStatusMessage(sanitized, Number.isFinite(statusCode) ? statusCode : 500);
        renderNormalizedStatus({ ...normalized, level: 'error' });
    }
};

selectFileButton.addEventListener('click', () => fileInput.click());

fileInput.addEventListener('change', (event) => {
    handleFiles(event.target.files);
    event.target.value = '';
});

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
    const files = event.dataTransfer.files;
    handleFiles(files);
});

dropZone.addEventListener('click', () => fileInput.click());

lightboxClose.addEventListener('click', closeLightbox);
lightbox.addEventListener('click', (event) => {
    if (event.target === lightbox) {
        closeLightbox();
    }
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && lightbox.classList.contains('open')) {
        closeLightbox();
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

const initializeBackendState = async () => {
    try {
        const response = await fetch(`${initializationEndpoint}?${Date.now()}`, {
            cache: 'no-store',
        });

        if (!response.ok) {
            throw new Error(`Serverantwort ${response.status}`);
        }
    } catch (error) {
        console.error('Backend-Initialisierung fehlgeschlagen:', error);
        const sanitized = sanitizeLogMessage(error?.message) || 'Backend-Initialisierung fehlgeschlagen.';
        const normalized = normalizeStatusMessage(sanitized, 500);
        renderNormalizedStatus({ ...normalized, level: 'error' });
    }
};

const loadInitialState = async () => {
    setLoadingState(false);

    try {
        await initializeBackendState();

        const response = await fetch(`${DATA_ENDPOINT}?${Date.now()}`, {
            cache: 'no-store',
        });

        if (!response.ok) {
            return;
        }

        const raw = await response.json();
        if (!raw || typeof raw !== 'object') {
            return;
        }

        if (raw.ok !== true) {
            console.error('Fehler beim Abrufen der Artikeldaten', raw.error);
            return;
        }

        const payload = raw.data && typeof raw.data === 'object' ? raw.data : {};
        applyLatestItemData(payload);
        const normalized = mapLatestItemPayloadToLegacy(payload);
        updateInterfaceFromData(normalized);

        if (Object.prototype.hasOwnProperty.call(normalized, 'isrunning') && toBoolean(normalized.isrunning)) {
            hasShownCompletion = false;
            startPolling();
        }
    } catch (error) {
        console.error('Initialisierung fehlgeschlagen:', error);
        const sanitized = sanitizeLogMessage(error?.message) || 'Initialisierung fehlgeschlagen.';
        const normalized = normalizeStatusMessage(sanitized, 500);
        renderNormalizedStatus({ ...normalized, level: 'error' });
    }
};

loadInitialState();
