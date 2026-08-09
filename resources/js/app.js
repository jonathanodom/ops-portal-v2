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
    form.addEventListener('submit', (event) => {
        if (!navigator.onLine) return;
        if (form.matches('[data-manual-closeout-form]') && event.submitter?.formAction.includes('/manual-closeout/draft')) return;
        dirty = false;
        form.setAttribute('aria-busy', 'true');
        const button = form.querySelector('button[type="submit"], button:not([type])');
        if (button) {
            button.disabled = true;
            button.textContent = 'Saving…';
        }
    });
    form.addEventListener('draft-saved', () => dirty = false);
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

document.querySelectorAll('[data-dialog-open]').forEach((button) => button.addEventListener('click', () => {
    const dialog = document.getElementById(button.dataset.dialogOpen);
    if (!dialog) return;
    const url = new URL(window.location.href);
    url.searchParams.set('execution_visit', dialog.dataset.visitId);
    history.replaceState({}, '', url);
    dialog.showModal();
}));

document.querySelectorAll('[data-execution-dialog]').forEach((dialog) => {
    const close = () => {
        dialog.close();
        const url = new URL(window.location.href);
        url.searchParams.delete('execution_visit');
        history.replaceState({}, '', url);
    };
    dialog.querySelector('[data-dialog-close]')?.addEventListener('click', close);
    dialog.addEventListener('cancel', (event) => {
        event.preventDefault();
        close();
    });
    dialog.querySelectorAll('[data-modal-form]').forEach((form) => form.addEventListener('submit', () => {
        const url = new URL(window.location.href);
        url.searchParams.set('execution_visit', dialog.dataset.visitId);
        history.replaceState({}, '', url);
    }));
});

const requestedDialog = new URL(window.location.href).searchParams.get('execution_visit');
if (requestedDialog) {
    const dialog = document.querySelector(`[data-execution-dialog][data-visit-id="${CSS.escape(requestedDialog)}"]`);
    if (dialog) {
        dialog.showModal();
        dialog.querySelector('[data-dialog-status]')?.focus();
    }
}

document.querySelectorAll('[data-manual-closeout-open]').forEach((button) => button.addEventListener('click', () => {
    const dialog = document.getElementById(button.dataset.manualCloseoutOpen);
    if (!dialog) return;
    const url = new URL(window.location.href);
    url.searchParams.set('manual_closeout_visit', dialog.dataset.visitId);
    history.replaceState({}, '', url);
    dialog.showModal();
}));

document.querySelectorAll('[data-manual-closeout-dialog]').forEach((dialog) => {
    const close = () => {
        dialog.close();
        const url = new URL(window.location.href);
        url.searchParams.delete('manual_closeout_visit');
        history.replaceState({}, '', url);
    };
    dialog.querySelector('[data-manual-closeout-close]')?.addEventListener('click', close);
    dialog.addEventListener('cancel', (event) => {
        event.preventDefault();
        close();
    });
    dialog.querySelectorAll('form').forEach((form) => form.addEventListener('submit', () => {
        const url = new URL(window.location.href);
        url.searchParams.set('manual_closeout_visit', dialog.dataset.visitId);
        history.replaceState({}, '', url);
    }));
    dialog.querySelector('[data-manual-closeout-form]')?.addEventListener('submit', async (event) => {
        const saveAction = event.submitter?.formAction;
        if (!saveAction || !saveAction.includes('/manual-closeout/draft')) return;
        event.preventDefault();
        const form = event.currentTarget;
        const status = dialog.querySelector('[data-manual-closeout-status]');
        if (!navigator.onLine) {
            status.textContent = 'Offline. Your entries remain on this page. Reconnect, then explicitly retry Save draft.';
            status.focus();
            return;
        }
        event.submitter.disabled = true;
        status.textContent = 'Saving draft…';
        try {
            const response = await fetch(saveAction, {
                method: 'POST',
                headers: { Accept: 'application/json' },
                body: new FormData(form),
            });
            const result = await response.json();
            status.textContent = result.message ?? 'The draft could not be saved.';
            if (result.content_version) form.querySelector('[name="content_version"]').value = result.content_version;
            status.className = response.ok
                ? 'mb-4 rounded-lg border border-emerald-300 bg-emerald-50 p-4 font-semibold text-emerald-900'
                : 'mb-4 rounded-lg border border-orange-300 bg-orange-50 p-4 font-semibold text-orange-950';
            if (response.ok) form.dispatchEvent(new Event('draft-saved'));
            status.focus();
        } catch {
            status.textContent = 'Save failed. Your entries remain on this page; reconnect and explicitly retry.';
            status.className = 'mb-4 rounded-lg border border-red-300 bg-red-50 p-4 font-semibold text-red-900';
            status.focus();
        } finally {
            event.submitter.disabled = false;
        }
    });
});

const requestedManualCloseout = new URL(window.location.href).searchParams.get('manual_closeout_visit');
if (requestedManualCloseout) {
    const dialog = document.querySelector(`[data-manual-closeout-dialog][data-visit-id="${CSS.escape(requestedManualCloseout)}"]`);
    if (dialog) {
        dialog.showModal();
        dialog.querySelector('[data-manual-closeout-status]')?.focus();
    }
}

const firstInvalidField = document.querySelector('[aria-invalid="true"]');
if (firstInvalidField) {
    requestAnimationFrame(() => {
        firstInvalidField.focus({ preventScroll: true });
        firstInvalidField.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
}
