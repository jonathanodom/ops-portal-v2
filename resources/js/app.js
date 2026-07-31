import './bootstrap';

const connectivityBanner = document.querySelector('[data-connectivity-banner]');

function updateConnectivity() {
    if (connectivityBanner) {
        connectivityBanner.hidden = navigator.onLine;
    }

    document.querySelectorAll('form').forEach((form) => {
        if ((form.method || 'get').toLowerCase() !== 'get') {
            form.querySelectorAll('button, input[type="submit"]').forEach((control) => {
                control.disabled = !navigator.onLine;
            });
        }
    });
}

document.addEventListener('submit', (event) => {
    if ((event.target.method || 'get').toLowerCase() !== 'get' && !navigator.onLine) {
        event.preventDefault();
        if (connectivityBanner) connectivityBanner.hidden = false;
    }
});

window.addEventListener('online', updateConnectivity);
window.addEventListener('offline', updateConnectivity);
updateConnectivity();

document.querySelectorAll('[data-dirty-form]').forEach((form) => {
    let dirty = false;
    form.addEventListener('input', () => dirty = true);
    form.addEventListener('submit', () => {
        if (!navigator.onLine) return;
        dirty = false;
        form.setAttribute('aria-busy', 'true');
        const button = form.querySelector('button[type="submit"], button:not([type])');
        if (button) {
            button.disabled = true;
            button.textContent = 'Saving…';
        }
    });
    window.addEventListener('beforeunload', (event) => {
        if (dirty) {
            event.preventDefault();
            event.returnValue = '';
        }
    });
});

document.querySelectorAll('[data-upload-form]').forEach((form) => form.addEventListener('submit', (event) => {
    event.preventDefault();
    const progress = form.querySelector('progress');
    const status = form.querySelector('[data-upload-status]');

    if (!navigator.onLine) {
        status.textContent = 'Offline. Reconnect, then retry this upload.';
        return;
    }

    const request = new XMLHttpRequest();
    progress.hidden = false;
    request.upload.addEventListener('progress', (progressEvent) => {
        if (progressEvent.lengthComputable) {
            progress.value = (progressEvent.loaded / progressEvent.total) * 100;
        }
    });
    request.addEventListener('load', () => {
        if (request.status >= 200 && request.status < 300) {
            status.textContent = 'Upload complete. Reloading…';
            location.reload();
        } else {
            status.textContent = 'Upload failed. Check the file and explicitly retry.';
        }
    });
    request.addEventListener('error', () => status.textContent = 'Upload failed. Your photo was not saved. Reconnect and retry.');
    request.open('POST', form.action);
    request.send(new FormData(form));
}));
