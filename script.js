const dropZone = document.getElementById('drop-zone');
const fileInput = document.getElementById('file-input');
const selectFileButton = document.getElementById('select-file');
const previewList = document.getElementById('upload-previews');
const lightbox = document.getElementById('lightbox');
const lightboxImage = lightbox.querySelector('.lightbox__image');
const lightboxClose = lightbox.querySelector('.lightbox__close');
const statusMessages = document.getElementById('status-messages');

const uploadEndpoint = 'upload.php';
const MAX_STATUS_ITEMS = 10;

const addStatusMessage = (text, type = 'info', detail) => {
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
                addPreviews([{ url: result.url, name: result.name || file.name }]);
                addStatusMessage(result.message || `Upload abgeschlossen: ${result.name || file.name}`, 'success');

                if (typeof result.forward_response !== 'undefined' && result.forward_response !== null && result.forward_response !== '') {
                    const formatted = typeof result.forward_response === 'object'
                        ? JSON.stringify(result.forward_response, null, 2)
                        : String(result.forward_response);
                    const statusLabel = result.forward_status ? `Zielserver (${result.forward_status})` : 'Zielserver';
                    addStatusMessage(`${statusLabel} Antwort`, 'info', formatted);
                }
            } else {
                addStatusMessage(result.error || `Upload fehlgeschlagen: ${file.name}`, 'error');
            }
        } catch (error) {
            console.error(error);
            addStatusMessage(error.message || `Beim Upload ist ein Fehler aufgetreten (${file.name}).`, 'error');
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

// Enable gallery items in lightbox
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
