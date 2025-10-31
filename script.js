const POLLING_INTERVAL_MS = 5000;
const STATE_ENDPOINT = 'api/state.php';
const LOGS_ENDPOINT = 'api/logs.php';
const IMAGES_ENDPOINT = 'api/images.php';
const LOG_FETCH_LIMIT = 50;

const productNameElement = document.getElementById('productName');
const productDescriptionElement = document.getElementById('productDescription');
const statusTextElement = document.getElementById('statusText');
const statusBadgeElement = document.getElementById('statusBadge');
const imageGalleryElement = document.getElementById('imageGallery');
const imageCountElement = document.getElementById('imageCount');
const logsListElement = document.getElementById('logsList');

let pollingTimer = null;
let isUnauthorized = false;
let currentNoteId = null;
let knownImageUrls = new Set();
let lastLogTimestampIso = null;
let seenLogKeys = new Set();

const STATUS_BADGE_CLASSES = ['status-pill--ok', 'status-pill--warn', 'status-pill--error'];

const sanitizeText = (value) => {
    if (value === null || value === undefined) {
        return '';
    }

    const text = String(value).trim();
    return text;
};

const updateProductDetails = (note) => {
    const name = note ? sanitizeText(note.product_name ?? note.name) : '';
    const description = note ? sanitizeText(note.product_description ?? note.description) : '';

    if (productNameElement) {
        productNameElement.textContent = name;
    }

    if (productDescriptionElement) {
        productDescriptionElement.textContent = description;
    }
};

const setStatusBadge = (status) => {
    if (!statusBadgeElement) {
        return;
    }

    STATUS_BADGE_CLASSES.forEach((className) => statusBadgeElement.classList.remove(className));

    const normalized = sanitizeText(status).toLowerCase();
    let label = '—';

    if (normalized === 'ok') {
        statusBadgeElement.classList.add('status-pill--ok');
        label = 'OK';
    } else if (normalized === 'warn' || normalized === 'warning') {
        statusBadgeElement.classList.add('status-pill--warn');
        label = 'Warnung';
    } else if (normalized === 'error') {
        statusBadgeElement.classList.add('status-pill--error');
        label = 'Fehler';
    }

    statusBadgeElement.textContent = label;
};

const updateStatusMessage = (message) => {
    if (!statusTextElement) {
        return;
    }

    const text = sanitizeText(message);
    statusTextElement.textContent = text;
};

const setImageCountText = (count) => {
    if (!imageCountElement) {
        return;
    }

    const numericValue = Number(count);
    const numeric = Number.isFinite(numericValue) ? numericValue : 0;
    if (numeric <= 0) {
        imageCountElement.textContent = '0 Bilder';
        return;
    }

    imageCountElement.textContent = numeric === 1 ? '1 Bild' : `${numeric} Bilder`;
};

const clearImageGallery = () => {
    if (!imageGalleryElement) {
        return;
    }

    imageGalleryElement.innerHTML = '';
    const empty = document.createElement('p');
    empty.className = 'image-gallery__empty empty-state';
    empty.textContent = 'Noch keine Bilder vorhanden.';
    imageGalleryElement.appendChild(empty);
};

const ensureGalleryHasContent = () => {
    if (!imageGalleryElement) {
        return;
    }

    if (imageGalleryElement.querySelector('.image-gallery__empty')) {
        imageGalleryElement.innerHTML = '';
    }
};

const appendImageCard = (item) => {
    if (!imageGalleryElement || !item) {
        return;
    }

    const url = sanitizeText(item.url);
    if (!url || knownImageUrls.has(url)) {
        return;
    }

    knownImageUrls.add(url);
    ensureGalleryHasContent();

    const card = document.createElement('div');
    card.className = 'image-card';

    const img = document.createElement('img');
    img.src = url;
    img.alt = `Produktbild ${knownImageUrls.size}`;

    const positionLabel = document.createElement('span');
    positionLabel.className = 'image-card__badge';
    const positionValue = Number(item.position);
    const position = Number.isFinite(positionValue) ? positionValue : knownImageUrls.size;
    positionLabel.textContent = `#${position}`;

    card.appendChild(img);
    card.appendChild(positionLabel);
    imageGalleryElement.appendChild(card);
};

const resetGalleryState = () => {
    knownImageUrls = new Set();
    clearImageGallery();
};

const toIsoString = (value) => {
    if (!value) {
        return null;
    }

    const trimmed = sanitizeText(value);
    if (!trimmed) {
        return null;
    }

    const directDate = new Date(trimmed);
    if (!Number.isNaN(directDate.getTime())) {
        return directDate.toISOString();
    }

    const normalized = trimmed.replace(' ', 'T');
    const utcCandidate = new Date(`${normalized}Z`);
    if (!Number.isNaN(utcCandidate.getTime())) {
        return utcCandidate.toISOString();
    }

    return null;
};

const handleUnauthorized = () => {
    isUnauthorized = true;
    updateProductDetails(null);
    setStatusBadge('warn');
    updateStatusMessage('Bitte einloggen');
    setImageCountText(0);
    resetGalleryState();
    seenLogKeys = new Set();
    lastLogTimestampIso = null;
    currentNoteId = null;
    if (logsListElement) {
        logsListElement.innerHTML = '';
    }
    stopPolling();
};

const appendLogs = (items) => {
    if (!Array.isArray(items) || !logsListElement) {
        return;
    }

    const classifyLog = (level, message) => {
        const normalizedLevel = sanitizeText(level).toLowerCase();
        const normalizedMessage = sanitizeText(message).toLowerCase();

        if (/(error|fail|fatal)/.test(normalizedLevel)) {
            return 'error';
        }

        if (/warn/.test(normalizedLevel)) {
            return 'warn';
        }

        if (/(success|ok)/.test(normalizedMessage) || /(success|ok)/.test(normalizedLevel)) {
            return 'success';
        }

        return 'info';
    };

    const fragment = document.createDocumentFragment();
    const reversed = [...items].reverse();

    reversed.forEach((entry) => {
        if (!entry || typeof entry !== 'object') {
            return;
        }

        const createdAt = sanitizeText(entry.created_at);
        const message = sanitizeText(entry.message);
        if (!message) {
            return;
        }

        const statusCodeValue = Number(entry.status_code);
        const statusCode = Number.isFinite(statusCodeValue) ? statusCodeValue : 200;
        const logKey = `${createdAt}|${message}|${statusCode}`;
        if (seenLogKeys.has(logKey)) {
            return;
        }

        seenLogKeys.add(logKey);

        if (!lastLogTimestampIso || (toIsoString(createdAt) ?? '') > lastLogTimestampIso) {
            const candidate = toIsoString(createdAt);
            if (candidate) {
                lastLogTimestampIso = candidate;
            }
        }

        const listItem = document.createElement('li');
        const variant = classifyLog(entry.level, message);
        listItem.className = `log-item log-item--${variant}`;
        listItem.textContent = `${message} (HTTP ${statusCode})`;

        fragment.appendChild(listItem);
    });

    const nodes = Array.from(fragment.childNodes);
    nodes.forEach((node) => {
        logsListElement.insertBefore(node, logsListElement.firstChild);
    });
};

const fetchLatestImages = async () => {
    if (!currentNoteId) {
        resetGalleryState();
        setImageCountText(0);
        return;
    }

    try {
        const response = await fetch(`${IMAGES_ENDPOINT}?note_id=latest`, {
            cache: 'no-store',
        });

        if (response.status === 401) {
            handleUnauthorized();
            return;
        }

        if (response.status === 404) {
            resetGalleryState();
            setImageCountText(0);
            return;
        }

        if (!response.ok) {
            throw new Error(`Serverantwort ${response.status}`);
        }

        const payload = await response.json();
        const items = Array.isArray(payload.items) ? payload.items : [];

        const payloadNoteIdValue = Number(payload.note_id);
        if (Number.isFinite(payloadNoteIdValue) && payloadNoteIdValue !== currentNoteId) {
            currentNoteId = payloadNoteIdValue;
            resetGalleryState();
        }

        items.forEach((item) => appendImageCard(item));
        const totalCount = Array.isArray(payload.items) ? payload.items.length : knownImageUrls.size;
        setImageCountText(totalCount);

        if (items.length === 0) {
            resetGalleryState();
            setImageCountText(0);
        }
    } catch (error) {
        console.error('Bilder konnten nicht geladen werden:', error);
    }
};

const bindState = async (payload) => {
    if (!payload || payload.ok === false) {
        updateProductDetails(null);
        setStatusBadge(null);
        updateStatusMessage('');
        setImageCountText(0);
        resetGalleryState();
        return;
    }

    const note = payload.note ?? null;
    const state = payload.state ?? {};

    const noteIdValue = Number(note && note.id ? note.id : null);
    const noteId = Number.isFinite(noteIdValue) ? noteIdValue : null;
    const noteChanged = noteId && noteId !== currentNoteId;

    if (!noteId) {
        currentNoteId = null;
        updateProductDetails(null);
        setStatusBadge(state.last_status ?? null);
        updateStatusMessage(state.last_message ?? '');
        setImageCountText(0);
        resetGalleryState();
        return;
    }

    if (noteChanged) {
        currentNoteId = noteId;
        resetGalleryState();
    } else if (!currentNoteId) {
        currentNoteId = noteId;
    }

    updateProductDetails(note);
    setStatusBadge(state.last_status ?? null);
    updateStatusMessage(state.last_message ?? '');

    if (payload.images && Number.isFinite(payload.images.count)) {
        setImageCountText(payload.images.count);
    }

    await fetchLatestImages();
};

const fetchState = async () => {
    try {
        const response = await fetch(STATE_ENDPOINT, {
            cache: 'no-store',
        });

        if (response.status === 401) {
            handleUnauthorized();
            return;
        }

        if (!response.ok) {
            throw new Error(`Serverantwort ${response.status}`);
        }

        const payload = await response.json();
        await bindState(payload);
        isUnauthorized = false;
    } catch (error) {
        console.error('Status konnte nicht geladen werden:', error);
    }
};

const fetchLogs = async () => {
    try {
        const params = new URLSearchParams();
        params.set('limit', String(LOG_FETCH_LIMIT));
        if (lastLogTimestampIso) {
            params.set('since', lastLogTimestampIso);
        }

        const response = await fetch(`${LOGS_ENDPOINT}?${params.toString()}`, {
            cache: 'no-store',
        });

        if (response.status === 401) {
            handleUnauthorized();
            return;
        }

        if (!response.ok) {
            throw new Error(`Serverantwort ${response.status}`);
        }

        const payload = await response.json();
        const items = Array.isArray(payload.items) ? payload.items : [];
        appendLogs(items);
    } catch (error) {
        console.error('Logs konnten nicht geladen werden:', error);
    }
};

const stopPolling = () => {
    if (pollingTimer !== null) {
        clearInterval(pollingTimer);
        pollingTimer = null;
    }
};

const pollOnce = async () => {
    await Promise.all([fetchState(), fetchLogs()]);
};

const startPolling = () => {
    if (pollingTimer !== null || isUnauthorized) {
        return;
    }

    pollOnce();
    pollingTimer = setInterval(pollOnce, POLLING_INTERVAL_MS);
};

updateProductDetails(null);
setStatusBadge(null);
updateStatusMessage('');
setImageCountText(0);
resetGalleryState();

if (typeof document !== 'undefined') {
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            stopPolling();
        } else if (!isUnauthorized) {
            startPolling();
        }
    });
}

startPolling();
