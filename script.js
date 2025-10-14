const dropZone = document.getElementById('drop-zone');
const fileInput = document.getElementById('file-input');
const selectFileButton = document.getElementById('select-file');
const previewList = document.getElementById('upload-previews');
const lightbox = document.getElementById('lightbox');
const lightboxImage = lightbox.querySelector('.lightbox__image');
const lightboxClose = lightbox.querySelector('.lightbox__close');
const statusMessages = document.getElementById('status-messages');
const processingIndicator = document.getElementById('processing-indicator');
const articleNameInput = document.getElementById('article-name');
const articleDescriptionInput = document.getElementById('article-description');

const uploadEndpoint = 'upload.php';
const MAX_STATUS_ITEMS = 10;
const POLLING_INTERVAL = 2000;
const DATA_ENDPOINT = 'data.json';
const PLACEHOLDER_SRC = '/assets/placeholder.jpg';
const loadingImage = 'https://vielfalter.digital/api-monday/ecommagent/assets/loading.gif';
const LOADING_SRC = loadingImage;
const INDICATOR_STATE_CLASSES = ['status__indicator--running', 'status__indicator--success', 'status__indicator--error'];

const galleryImages = [
    { key: 'image_1', element: document.getElementById('img1') },
    { key: 'image_2', element: document.getElementById('img2') },
    { key: 'image_3', element: document.getElementById('img3') },
];

let isProcessing = false;
let pollingTimer = null;
let isPollingActive = false;
let lastStatusText = '';
let hasShownCompletion = false;

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

const addStatusMessage = (text, type = 'info', detail) => {
    if (!statusMessages) {
        return;
    }

    const item = document.createElement('li');
    item.className = `status__message status__message--${type}`;

    const content = document.createElement('div');
    content.className = 'status__message-content';
    content.textContent = text;
    item.appendChild(content);

    if (detail !== undefined) {
        const detailElement = document.createElement('pre');
        detailElement.className = 'status__message-detail';
        detailElement.textContent = detail;
        item.appendChild(detailElement);
    }

    statusMessages.prepend(item);

    while (statusMessages.children.length > MAX_STATUS_ITEMS) {
        statusMessages.removeChild(statusMessages.lastElementChild);
    }

    statusMessages.scrollTop = 0;
};

const setImageSource = (element, src) => {
    if (!element || !src) {
        return;
    }

    const sanitized = String(src).trim();
    if (sanitized === '') {
        return;
    }

    if (element.dataset.currentSrc === sanitized) {
        element.dataset.hasContent = 'true';
        element.dataset.isLoading = 'false';
        return;
    }

    element.dataset.hasContent = 'true';
    element.dataset.currentSrc = sanitized;
    element.dataset.isLoading = 'false';
    element.src = sanitized;
};

const updateProcessingIndicator = (text, state = 'idle') => {
    if (!processingIndicator) {
        return;
    }

    processingIndicator.textContent = text;
    INDICATOR_STATE_CLASSES.forEach((className) => processingIndicator.classList.remove(className));

    if (state && state !== 'idle') {
        processingIndicator.classList.add(`status__indicator--${state}`);
    }
};

const setLoadingState = (loading, options = {}) => {
    isProcessing = loading;

    if (loading) {
        hasObservedActiveRun = true;
    } else if (!options || options.indicatorState !== 'success') {
        hasObservedActiveRun = false;
    }

    galleryImages.forEach(({ element }) => {
        if (!element) {
            return;
        }

        if (loading) {
            element.dataset.isLoading = 'true';
            element.dataset.hasContent = 'false';
            element.dataset.currentSrc = '';
            element.src = LOADING_SRC;
        } else {
            element.dataset.isLoading = 'false';
            if (element.dataset.hasContent === 'true' && element.dataset.currentSrc) {
                element.src = element.dataset.currentSrc;
            } else {
                element.dataset.hasContent = 'false';
                element.dataset.currentSrc = '';
                element.src = PLACEHOLDER_SRC;
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

    const nameValue = data.produktname ?? data.product_name ?? data.title;
    if (typeof nameValue === 'string' && nameValue.trim() !== '') {
        articleNameInput.value = nameValue;
    }

    const descriptionValue = data.produktbeschreibung ?? data.product_description ?? data.description;
    if (typeof descriptionValue === 'string' && descriptionValue.trim() !== '') {
        articleDescriptionInput.value = descriptionValue;
    }

    galleryImages.forEach(({ key, element }) => {
        const value = getDataField(data, key);
        if (value) {
            setImageSource(element, value);
        } else if (!isProcessing && element && element.dataset.hasContent !== 'true') {
            element.src = PLACEHOLDER_SRC;
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

const galleryItems = document.querySelectorAll('[data-preview]');
galleryItems.forEach((item) => {
    const open = () => openLightbox(item.src, item.alt);
    item.addEventListener('click', open);
    item.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            open();
        }
    });
    item.tabIndex = 0;
});

const loadInitialState = async () => {
    setLoadingState(false);

    try {
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
