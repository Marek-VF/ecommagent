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

const uploadEndpoint = 'upload.php';
const initializationEndpoint = 'init.php';
const STATUS_LOG_LIMIT = 60;
const POLLING_INTERVAL = 2000;
const DATA_ENDPOINT = 'data.json';

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
const statusLogState = {
    seen: new Set(),
};

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

const normalizeLogType = (value) => {
    const normalized = typeof value === 'string' ? value.trim().toLowerCase() : '';

    if (normalized === 'success') {
        return 'success';
    }

    if (normalized === 'error') {
        return 'error';
    }

    return 'neutral';
};

const trimStatusLog = () => {
    if (!statusLogContainer) {
        return;
    }

    while (statusLogContainer.children.length > STATUS_LOG_LIMIT) {
        statusLogContainer.removeChild(statusLogContainer.firstElementChild);
    }
};

const createStatusLogEntryElement = ({ message, type, timestamp, detail }) => {
    const entry = document.createElement('article');
    const normalizedType = normalizeLogType(type);

    entry.className = 'status-panel__entry';
    entry.dataset.type = normalizedType;

    const timestampElement = document.createElement('span');
    timestampElement.className = 'status-panel__timestamp';
    timestampElement.textContent = formatLogTimestamp(timestamp);

    const body = document.createElement('div');
    body.className = 'status-panel__body';

    const textElement = document.createElement('p');
    textElement.className = 'status-panel__text';
    textElement.textContent = message;
    body.appendChild(textElement);

    if (typeof detail === 'string' && detail.trim() !== '') {
        const detailElement = document.createElement('pre');
        detailElement.className = 'status-panel__detail';
        detailElement.textContent = detail.trim();
        body.appendChild(detailElement);
    }

    entry.append(timestampElement, body);

    return entry;
};

const appendStatusLogEntry = (entryElement) => {
    if (!statusLogContainer || !entryElement) {
        return;
    }

    statusLogContainer.appendChild(entryElement);
    trimStatusLog();
    statusLogContainer.scrollTop = statusLogContainer.scrollHeight;
};

const addStatusMessage = (text, type = 'neutral', detail) => {
    const message = typeof text === 'string' ? text.trim() : '';

    if (!message) {
        return;
    }

    const entry = createStatusLogEntryElement({
        message,
        type,
        detail,
        timestamp: new Date(),
    });

    appendStatusLogEntry(entry);
};

const padTwo = (value) => String(value).padStart(2, '0');

const formatLogTimestamp = (value) => {
    let date = null;

    if (value instanceof Date) {
        date = value;
    } else if (typeof value === 'number') {
        date = new Date(value);
    } else if (typeof value === 'string' && value.trim() !== '') {
        const parsed = new Date(value);
        if (!Number.isNaN(parsed.getTime())) {
            date = parsed;
        } else {
            return value.trim();
        }
    }

    if (!(date instanceof Date) || Number.isNaN(date.getTime())) {
        date = new Date();
    }

    const day = padTwo(date.getDate());
    const month = padTwo(date.getMonth() + 1);
    const year = date.getFullYear();
    const hours = padTwo(date.getHours());
    const minutes = padTwo(date.getMinutes());
    const seconds = padTwo(date.getSeconds());

    return `${day}.${month}.${year} ${hours}:${minutes}:${seconds}`;
};

const updateStatusLog = (entries) => {
    if (!statusLogContainer || !Array.isArray(entries)) {
        return;
    }

    const fragment = document.createDocumentFragment();
    let hasNewEntries = false;

    entries.forEach((rawEntry) => {
        if (!rawEntry || typeof rawEntry !== 'object') {
            return;
        }

        const message = typeof rawEntry.message === 'string' ? rawEntry.message.trim() : '';
        if (message === '') {
            return;
        }

        const timestamp = rawEntry.timestamp ?? '';
        const type = normalizeLogType(rawEntry.type);
        const entryKey = `${timestamp}::${message}::${type}`;

        if (statusLogState.seen.has(entryKey)) {
            return;
        }

        statusLogState.seen.add(entryKey);

        const item = createStatusLogEntryElement({
            message,
            type,
            timestamp,
        });

        fragment.appendChild(item);
        hasNewEntries = true;
    });

    if (hasNewEntries) {
        statusLogContainer.appendChild(fragment);
        trimStatusLog();
        statusLogContainer.scrollTop = statusLogContainer.scrollHeight;
    }
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

        addStatusMessage(`Starte Upload: ${file.name}`, 'info');

        try {
            const response = await fetch(uploadEndpoint, {
                method: 'POST',
                body: formData,
            });

            const rawText = await response.text();
            if (!response.ok) {
                throw new Error(`Upload fehlgeschlagen (${response.status}). ${rawText}`);
            }

            let result;
            try {
                result = rawText ? JSON.parse(rawText) : {};
            } catch (parseError) {
                throw new Error(`Antwort konnte nicht gelesen werden: ${rawText}`);
            }

            if (result.success) {
                if (result.file) {
                    addPreviews([{ url: result.file, name: result.name || file.name }]);
                }
                addStatusMessage(result.message || `Upload abgeschlossen: ${result.name || file.name}`, 'success');
                setLoadingState(true);
                if (processingIndicator) {
                    processingIndicator.textContent = 'Verarbeitung läuft…';
                }
                hasShownCompletion = false;
                startPolling();

                if (typeof result.webhook_response !== 'undefined' && result.webhook_response !== null && result.webhook_response !== '') {
                    const formatted = typeof result.webhook_response === 'object'
                        ? JSON.stringify(result.webhook_response, null, 2)
                        : String(result.webhook_response);
                    const statusLabel = result.webhook_status ? `Webhook (${result.webhook_status})` : 'Webhook';
                    addStatusMessage(`${statusLabel} Antwort`, 'info', formatted);
                }
            } else {
                addStatusMessage(result.message || `Upload fehlgeschlagen: ${file.name}`, 'error');
                setLoadingState(false, { indicatorText: 'Bereit.', indicatorState: 'idle' });
            }
        } catch (error) {
            console.error(error);
            addStatusMessage(error.message || `Beim Upload ist ein Fehler aufgetreten (${file.name}).`, 'error');
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
        addStatusMessage('Workflow abgeschlossen.', 'success');
        hasShownCompletion = true;
        updateProcessingIndicator('Workflow abgeschlossen', 'success');
        hasObservedActiveRun = false;
    } else if (isRunning) {
        hasShownCompletion = false;
        updateProcessingIndicator('Verarbeitung läuft…', 'running');
    }

    if (!isRunning && !hasShownCompletion && data.updated_at) {
        addStatusMessage('Verarbeitung abgeschlossen.', 'success');
        hasShownCompletion = true;
    }

    if (typeof data.status_message === 'string') {
        const trimmed = data.status_message.trim();
        if (trimmed && trimmed !== lastStatusText) {
            addStatusMessage(trimmed, isRunning ? 'info' : 'success');
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

        const data = await response.json();
        updateInterfaceFromData(data);
    } catch (error) {
        console.error('Polling-Fehler:', error);
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

        const data = await response.json();
        updateInterfaceFromData(data);

        if (Object.prototype.hasOwnProperty.call(data, 'isrunning') && toBoolean(data.isrunning)) {
            hasShownCompletion = false;
            startPolling();
        }
    } catch (error) {
        console.error('Initialisierung fehlgeschlagen:', error);
    }
};

loadInitialState();
