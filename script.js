const dropZone = document.getElementById('drop-zone');
const fileInput = document.getElementById('file-input');
const selectFileButton = document.getElementById('select-file');
const previewList = document.getElementById('upload-previews');
const lightbox = document.getElementById('lightbox');
const lightboxImage = lightbox.querySelector('.lightbox__image');
const lightboxClose = lightbox.querySelector('.lightbox__close');
const processingIndicator = document.getElementById('processing-indicator');
const statusLogContainer = document.getElementById('status-log');
const statusMessageElement = document.getElementById('status-message');
const statusPreviewContainer = document.getElementById('status-preview');
const statusPreviewImage = document.getElementById('status-preview-image');

const uploadEndpoint = 'upload.php';
const STATUS_LOG_LIMIT = 50;
const POLLING_INTERVAL = 5000;
const STATE_ENDPOINT = 'api/state.php';
const LOGS_ENDPOINT = 'api/logs.php';
const LOG_FETCH_LIMIT = 50;

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
let hasAuthError = false;
const statusLogState = {
    localEntries: [],
    apiEntries: [],
};

const LOG_TYPE_CLASSES = {
    ok: 'log-ok',
    error: 'log-err',
    info: 'log-info',
    warn: 'log-info',
};

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

const resolveLogType = ({ httpStatus, typeHint, fallback }) => {
    const mappedHint = mapTypeHint(typeHint);
    if (mappedHint) {
        return mappedHint;
    }

    const mappedFallback = mapTypeHint(fallback);
    if (mappedFallback) {
        return mappedFallback;
    }

    if (typeof httpStatus === 'number') {
        if (httpStatus >= 200 && httpStatus < 300) {
            return 'ok';
        }

        if (httpStatus >= 400 && httpStatus < 600) {
            return 'error';
        }
    }

    if (typeof fallback === 'string' && ['ok', 'error', 'info'].includes(fallback)) {
        return fallback;
    }

    return 'info';
};

const normalizeLogType = (type, message) => {
    const normalizedType = typeof type === 'string' ? type.trim().toLowerCase() : '';
    const messageText = typeof message === 'string' ? message : '';

    if (/\b(success|ok)\b/i.test(messageText)) {
        return 'ok';
    }

    if (normalizedType === 'ok' || normalizedType === 'success') {
        return 'ok';
    }

    if (normalizedType === 'warn' || normalizedType === 'warning') {
        return 'warn';
    }

    if (['error', 'err', 'danger', 'fail', 'failed'].includes(normalizedType)) {
        return 'error';
    }

    return 'info';
};

const buildStatusSuffix = (value) => {
    if (value === undefined || value === null) {
        return '';
    }

    const text = String(value).trim();
    if (!text) {
        return '';
    }

    const numeric = Number(text);
    if (Number.isFinite(numeric)) {
        return `HTTP ${numeric}`;
    }

    const httpMatch = text.match(/^\s*HTTP\s*(\d{3})\s*$/i);
    if (httpMatch) {
        return `HTTP ${httpMatch[1]}`;
    }

    return text;
};

const rebuildStatusLog = () => {
    if (!statusLogContainer) {
        return;
    }

    statusLogContainer.innerHTML = '';

    const combinedEntries = [
        ...statusLogState.apiEntries,
        ...statusLogState.localEntries,
    ];

    combinedEntries.slice(0, STATUS_LOG_LIMIT).forEach((entry) => {
        const className = LOG_TYPE_CLASSES[entry.type] || LOG_TYPE_CLASSES.info;
        const element = document.createElement('p');
        element.className = `log-entry ${className}`;
        element.dataset.origin = entry.origin;

        const suffix = entry.suffix ? ` (${entry.suffix})` : '';
        element.textContent = `${entry.message}${suffix}`;

        statusLogContainer.appendChild(element);
    });
};

const storeLocalLogEntry = (entry) => {
    statusLogState.localEntries.unshift(entry);
    statusLogState.localEntries = statusLogState.localEntries.slice(0, STATUS_LOG_LIMIT);
};

const renderLog = (type, message, code, options = {}) => {
    const normalizedMessage = sanitizeLogMessage(message);
    if (!normalizedMessage) {
        return;
    }

    const normalizedType = normalizeLogType(type, normalizedMessage);
    const suffix = buildStatusSuffix(code);
    const origin = typeof options.origin === 'string' ? options.origin : 'local';

    const entry = {
        message: normalizedMessage,
        type: normalizedType,
        suffix,
        origin,
    };

    if (origin === 'local') {
        storeLocalLogEntry(entry);
    }

    rebuildStatusLog();
};

const logMessage = (message, type = 'info', code) => {
    renderLog(type, message, code);
};

const logResponse = ({ httpStatus, payload, fallbackMessage, fallbackType }) => {
    const { message, code, type } = extractMessageAndCode(payload);

    let finalMessage = sanitizeLogMessage(message) || sanitizeLogMessage(fallbackMessage);
    let finalCode = code || (typeof httpStatus === 'number' ? String(httpStatus) : null);
    let finalType = resolveLogType({ httpStatus, typeHint: type, fallback: fallbackType });

    if (isUnauthorized(httpStatus, finalMessage)) {
        finalType = 'error';
        finalMessage = buildUnauthorizedMessage(message || fallbackMessage);
        if (!finalCode) {
            finalCode = httpStatus || 401;
        }
    }

    renderLog(finalType, finalMessage, finalCode);
};

const applyStatusLogs = (entries) => {
    if (!Array.isArray(entries)) {
        statusLogState.apiEntries = [];
        rebuildStatusLog();
        return;
    }

    const normalizedEntries = entries
        .map((rawEntry) => {
            if (!rawEntry || typeof rawEntry !== 'object') {
                return null;
            }

            const rawMessage =
                rawEntry.message ?? rawEntry.text ?? rawEntry.msg ?? rawEntry.status_message ?? rawEntry.statusMessage;
            const message = sanitizeLogMessage(rawMessage);
            if (!message) {
                return null;
            }

            const typeHint = rawEntry.level ?? rawEntry.type ?? rawEntry.status ?? '';
            const suffix = buildStatusSuffix(
                rawEntry.status_code ?? rawEntry.statusCode ?? rawEntry.code ?? rawEntry.status,
            );

            return {
                message,
                type: normalizeLogType(typeHint, message),
                suffix,
                origin: 'api',
            };
        })
        .filter(Boolean);

    statusLogState.apiEntries = normalizedEntries;
    rebuildStatusLog();
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
};

const setStatusMessageText = (message) => {
    if (!statusMessageElement) {
        return;
    }

    const sanitized = sanitizeLogMessage(message);
    statusMessageElement.textContent = sanitized || 'Noch keine Statusmeldung.';
};

const setStatusPreviewImage = (url) => {
    if (!statusPreviewContainer || !statusPreviewImage) {
        return;
    }

    if (typeof url === 'string') {
        const sanitized = url.trim();
        if (sanitized) {
            statusPreviewImage.src = sanitized;
            statusPreviewImage.alt = 'Letzte Vorschau';
            statusPreviewContainer.hidden = false;
            return;
        }
    }

    statusPreviewImage.removeAttribute('src');
    statusPreviewContainer.hidden = true;
};

const setStatusIndicatorState = (statusValue) => {
    if (!processingIndicator) {
        return;
    }

    const normalized = typeof statusValue === 'string' ? statusValue.trim().toLowerCase() : '';

    if (!normalized) {
        updateProcessingIndicator('Keine Statusdaten', 'warning');
        return;
    }

    switch (normalized) {
        case 'ok':
            updateProcessingIndicator('Status: OK', 'success');
            break;
        case 'warn':
        case 'warning':
            updateProcessingIndicator('Status: Warnung', 'warning');
            break;
        case 'error':
            updateProcessingIndicator('Status: Fehler', 'error');
            break;
        default:
            updateProcessingIndicator(`Status: ${normalized.toUpperCase()}`, 'warning');
            break;
    }
};

const applyStateData = (state) => {
    const lastStatus = state && typeof state === 'object'
        ? state.last_status ?? state.status ?? state.state
        : null;
    const lastMessage = state && typeof state === 'object'
        ? state.last_message ?? state.message ?? state.status_message ?? state.statusMessage
        : null;
    const lastImageUrl = state && typeof state === 'object'
        ? state.last_image_url ?? state.image ?? state.preview
        : null;

    setStatusIndicatorState(lastStatus);
    setStatusMessageText(lastMessage);
    setStatusPreviewImage(lastImageUrl);
    hasAuthError = false;
};

const handleUnauthorized = () => {
    updateProcessingIndicator('Bitte einloggen.', 'warning');
    setStatusMessageText('Bitte einloggen.');
    setStatusPreviewImage(null);

    if (!hasAuthError) {
        hasAuthError = true;
        stopPolling();
        statusLogState.apiEntries = [];
    }

    rebuildStatusLog();
};

const fetchStateData = async () => {
    try {
        const response = await fetch(`${STATE_ENDPOINT}?_=${Date.now()}`, {
            cache: 'no-store',
        });

        if (response.status === 401) {
            console.warn('Statusabfrage nicht autorisiert (401).');
            handleUnauthorized();
            return;
        }

        if (!response.ok) {
            throw new Error(`Serverantwort ${response.status}`);
        }

        const payload = await response.json();
        applyStateData(payload || {});
    } catch (error) {
        console.error('Status konnte nicht geladen werden:', error);
    }
};

const fetchLogData = async () => {
    try {
        const response = await fetch(`${LOGS_ENDPOINT}?limit=${LOG_FETCH_LIMIT}&_=${Date.now()}`, {
            cache: 'no-store',
        });

        if (response.status === 401) {
            console.warn('Logabfrage nicht autorisiert (401).');
            handleUnauthorized();
            return;
        }

        if (!response.ok) {
            throw new Error(`Serverantwort ${response.status}`);
        }

        const payload = await response.json();
        const logs = Array.isArray(payload) ? payload : payload.logs ?? [];
        applyStatusLogs(logs);
    } catch (error) {
        console.error('Statusprotokoll konnte nicht geladen werden:', error);
    }
};

const refreshStateAndLogs = async () => {
    await Promise.all([fetchStateData(), fetchLogData()]);
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
    refreshStateAndLogs();
    pollingTimer = setInterval(refreshStateAndLogs, POLLING_INTERVAL);
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
            startPolling();

            if (result.webhook_response !== undefined && result.webhook_response !== null && result.webhook_response !== '') {
                const webhookPayload = result.webhook_response;
                const webhookStatus = result.webhook_status;
                const { message: webhookMessage, code: webhookCode, type: webhookType } = extractMessageAndCode(webhookPayload);
                const fallbackWebhookMessage = webhookStatus
                    ? `Webhook ${String(webhookStatus).trim()}`
                    : 'Webhook-Antwort';
                const resolvedWebhookType = resolveLogType({
                    httpStatus: Number(webhookStatus),
                    typeHint: webhookType,
                    fallback: 'info',
                });
                const resolvedWebhookCode = webhookCode
                    || (webhookStatus !== undefined && webhookStatus !== null ? String(webhookStatus).trim() : null);
                const sanitizedWebhookMessage = sanitizeLogMessage(webhookMessage) || sanitizeLogMessage(fallbackWebhookMessage);

                if (sanitizedWebhookMessage) {
                    renderLog(resolvedWebhookType, sanitizedWebhookMessage, resolvedWebhookCode);
                }
            }
        } catch (error) {
            console.error(error);
            const fallback = `Beim Upload ist ein Fehler aufgetreten (${file.name}).`;
            const message = sanitizeLogMessage(error?.message) || fallback;
            renderLog('error', message, null);
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

startPolling();
