const stepForField = (field) => {
    if (field === 'work_items') return { tab: 'work' };
    if (field === 'time') return { tab: 'time' };
    if (field.startsWith('no_photo')) return { step: 'check' };
    if (['representative_name', 'representative_role', 'ack_unavailable_category', 'ack_unavailable_detail', 'signature_data'].includes(field)) return { step: 'acknowledgment' };
    if (['outcome'].includes(field)) return { step: 'outcome' };
    return { step: 'summary' };
};

document.querySelectorAll('[data-field-workspace-v2]').forEach((root) => {
    const tabs = [...root.querySelectorAll('[data-v2-tab]')];
    const panels = [...root.querySelectorAll('[data-v2-panel]')];
    const swipeSurface = root.querySelector('[data-v2-swipe-surface]');
    const dialog = root.querySelector('[data-v2-finish-dialog]');
    const closeoutForm = root.querySelector('[data-v2-closeout-form]');
    const steps = [...root.querySelectorAll('[data-v2-step]')];
    const stepButtons = [...root.querySelectorAll('[data-v2-step-button]')];
    const stepNames = ['outcome', 'summary', 'check', 'acknowledgment', 'review'];
    let activeStep = 'outcome';
    let dialogLauncher = null;
    let readinessErrors = {};

    const activateTab = (name, focus = false) => {
        if (!tabs.some((tab) => tab.dataset.v2Tab === name)) name = 'overview';
        tabs.forEach((tab) => {
            const selected = tab.dataset.v2Tab === name;
            tab.setAttribute('aria-selected', selected ? 'true' : 'false');
            tab.tabIndex = selected ? 0 : -1;
            if (selected && focus) tab.focus();
        });
        panels.forEach((panel) => panel.hidden = panel.dataset.v2Panel !== name);
        history.replaceState({}, '', `${window.location.pathname}${window.location.search}#${name}`);
    };

    tabs.forEach((tab, index) => {
        tab.addEventListener('click', () => activateTab(tab.dataset.v2Tab));
        tab.addEventListener('keydown', (event) => {
            if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
            event.preventDefault();
            const next = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : (index + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
            activateTab(tabs[next].dataset.v2Tab, true);
        });
    });
    activateTab(window.location.hash.slice(1) || 'overview');

    const swipeIgnoreSelector = [
        '[data-v2-swipe-ignore]',
        '[data-v2-media-list]',
        '[data-photo-gallery]',
        '[data-gallery]',
        '[data-carousel]',
        'dialog',
        'a',
        'button',
        'input',
        'select',
        'textarea',
        'summary',
        '[contenteditable="true"]',
    ].join(',');
    const swipeThreshold = 64;
    let swipeStart = null;

    swipeSurface?.addEventListener('touchstart', (event) => {
        if (event.touches.length !== 1 || event.target.closest(swipeIgnoreSelector)) {
            swipeStart = null;
            return;
        }

        swipeStart = {
            x: event.touches[0].clientX,
            y: event.touches[0].clientY,
        };
    }, { passive: true });

    swipeSurface?.addEventListener('touchcancel', () => {
        swipeStart = null;
    }, { passive: true });

    swipeSurface?.addEventListener('touchend', (event) => {
        if (!swipeStart || event.changedTouches.length !== 1) {
            swipeStart = null;
            return;
        }

        const deltaX = event.changedTouches[0].clientX - swipeStart.x;
        const deltaY = event.changedTouches[0].clientY - swipeStart.y;
        swipeStart = null;

        if (Math.abs(deltaX) < swipeThreshold || Math.abs(deltaX) <= Math.abs(deltaY) * 1.25) return;

        const currentIndex = tabs.findIndex((tab) => tab.getAttribute('aria-selected') === 'true');
        const nextIndex = currentIndex + (deltaX < 0 ? 1 : -1);
        if (currentIndex < 0 || nextIndex < 0 || nextIndex >= tabs.length) return;

        activateTab(tabs[nextIndex].dataset.v2Tab);
    }, { passive: true });

    root.querySelectorAll('[data-v2-live-timer]').forEach((timer) => {
        const started = Date.parse(timer.dataset.startedAt);
        if (!Number.isFinite(started)) return;
        const tick = () => {
            const total = Math.max(0, Math.floor((Date.now() - started) / 1000));
            timer.textContent = [Math.floor(total / 3600), Math.floor((total % 3600) / 60), total % 60].map((value) => String(value).padStart(2, '0')).join(':');
        };
        tick();
        window.setInterval(tick, 1000);
    });

    const activateStep = (name, focus = false) => {
        if (!stepNames.includes(name)) name = 'outcome';
        activeStep = name;
        steps.forEach((step) => step.hidden = step.dataset.v2Step !== name);
        stepButtons.forEach((button) => button.setAttribute('aria-current', button.dataset.v2StepButton === name ? 'step' : 'false'));
        root.querySelector('[data-v2-draft-footer]')?.classList.toggle('hidden', name === 'review');
        if (focus) root.querySelector(`[data-v2-step="${CSS.escape(name)}"] h3`)?.setAttribute('tabindex', '-1');
        if (focus) root.querySelector(`[data-v2-step="${CSS.escape(name)}"] h3`)?.focus();
    };
    stepButtons.forEach((button) => button.addEventListener('click', () => activateStep(button.dataset.v2StepButton, true)));

    const updateOutcome = () => {
        const outcome = closeoutForm?.querySelector('[name="outcome"]:checked')?.value ?? '';
        root.querySelectorAll('[data-v2-outcomes]').forEach((group) => {
            const hidden = !group.dataset.v2Outcomes.split(' ').includes(outcome);
            group.hidden = hidden;
            group.querySelectorAll('input, select, textarea').forEach((control) => control.disabled = hidden);
        });
        const fallback = closeoutForm?.querySelector('[name="ack_unavailable_category"]')?.value;
        const signaturePath = root.querySelector('[data-v2-signature-path]');
        const fallbackPath = root.querySelector('[data-v2-fallback-path]');
        const signatureRequired = ['resolved', 'needs_return_trip', 'on_hold'].includes(outcome) && !fallback;
        const confirmation = signaturePath?.querySelector('[data-v2-acknowledgment-confirmation]');

        if (signaturePath) {
            signaturePath.hidden = !signatureRequired;
            signaturePath.classList.toggle('hidden', !signatureRequired);
            signaturePath.querySelectorAll('input, button').forEach((control) => control.disabled = !signatureRequired);
        }
        if (confirmation) confirmation.required = signatureRequired;
        if (fallbackPath) {
            fallbackPath.hidden = !fallback;
            fallbackPath.classList.toggle('hidden', !fallback);
        }
    };
    closeoutForm?.addEventListener('change', updateOutcome);
    updateOutcome();

    const openDialog = (launcher, step = 'outcome') => {
        dialogLauncher = launcher;
        activateStep(step);
        if (!dialog.open) dialog.showModal();
        requestAnimationFrame(() => dialog.querySelector('[data-v2-finish-close]')?.focus());
    };
    const closeDialog = () => {
        if (dialog?.open) dialog.close();
        dialogLauncher?.focus();
    };
    root.querySelectorAll('[data-v2-finish-open]').forEach((button) => button.addEventListener('click', () => openDialog(button)));
    root.querySelector('[data-v2-finish-close]')?.addEventListener('click', closeDialog);
    dialog?.addEventListener('cancel', (event) => {
        event.preventDefault();
        if (closeoutForm?.matches(':has(:focus)') && !window.confirm('Close the finish flow? Unsaved entries remain on this page but are not saved.')) return;
        closeDialog();
    });

    root.querySelectorAll('[data-v2-go-tab]').forEach((button) => button.addEventListener('click', () => {
        closeDialog();
        activateTab(button.dataset.v2GoTab, true);
    }));
    root.querySelector('[data-v2-step-previous]')?.addEventListener('click', () => activateStep(stepNames[Math.max(0, stepNames.indexOf(activeStep) - 1)], true));

    root.querySelectorAll('[data-v2-narrative-helper]').forEach((button) => button.addEventListener('click', () => {
        const target = document.getElementById(button.dataset.target);
        if (!target) return;
        if (target.value.trim() && !window.confirm('Replace the existing text with the Work Item summary?')) return;
        target.value = button.dataset.value;
        target.dispatchEvent(new Event('input', { bubbles: true }));
        target.focus();
    }));

    const renderReadiness = (errors = {}) => {
        readinessErrors = errors;
        const list = root.querySelector('[data-v2-readiness-list]');
        if (list) {
            list.replaceChildren();
            const entries = Object.entries(errors);
            if (!entries.length) {
                const item = document.createElement('li');
                item.className = 'rounded-lg border border-emerald-300 bg-emerald-50 p-3 text-sm font-semibold text-emerald-950';
                item.textContent = 'Server readiness checks are complete.';
                list.append(item);
            }
            entries.forEach(([field, message]) => {
                const item = document.createElement('li');
                item.className = 'flex items-center justify-between gap-3 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm';
                const text = document.createElement('span');
                text.textContent = message;
                const fix = document.createElement('button');
                fix.type = 'button';
                fix.className = 'font-bold text-brand-blue';
                fix.textContent = 'Fix';
                fix.addEventListener('click', () => fixReadiness(field));
                item.append(text, fix);
                list.append(item);
            });
        }
        const status = root.querySelector('[data-v2-tab-status="closeout"]');
        if (status) status.textContent = Object.keys(errors).length ? `${Object.keys(errors).length} missing` : 'Ready';
        const submit = root.querySelector('[data-v2-final-submit]');
        const blocking = Object.keys(errors).filter((field) => field !== 'signature_data');
        if (submit) submit.disabled = blocking.length > 0;
    };

    const fixReadiness = (field) => {
        const target = stepForField(field);
        if (target.tab) {
            closeDialog();
            activateTab(target.tab, true);
            return;
        }
        activateStep(target.step, true);
        const control = closeoutForm?.querySelector(`[name="${CSS.escape(field)}"]`);
        control?.focus();
    };
    root.querySelectorAll('[data-v2-fix]').forEach((button) => button.addEventListener('click', () => fixReadiness(button.dataset.v2Fix)));

    closeoutForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const status = root.querySelector('[data-v2-draft-status]');
        const submit = event.submitter;
        if (!navigator.onLine) {
            status.textContent = 'Offline. Your entries remain on this page. Reconnect, then explicitly retry Save & continue.';
            status.className = 'rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm font-semibold text-amber-950';
            status.focus();
            return;
        }
        submit.disabled = true;
        const originalLabel = submit.textContent;
        submit.textContent = 'Saving…';
        status.textContent = 'Saving shared Closeout draft…';
        status.className = 'rounded-lg border border-blue-200 bg-blue-50 p-3 text-sm font-semibold text-blue-950';
        try {
            const response = await fetch(closeoutForm.action, { method: 'POST', headers: { Accept: 'application/json' }, body: new FormData(closeoutForm) });
            const body = await response.json();
            if (body.content_version) closeoutForm.querySelector('[name="content_version"]').value = body.content_version;
            if (!response.ok) {
                status.textContent = body.message ?? Object.values(body.errors ?? {}).flat()[0] ?? 'The draft was not saved. Explicitly retry.';
                status.className = 'rounded-lg border border-red-300 bg-red-50 p-3 text-sm font-semibold text-red-950';
                status.focus();
                return;
            }
            renderReadiness(body.readiness_errors ?? {});
            status.textContent = body.message ?? 'Draft saved.';
            status.className = 'rounded-lg border border-emerald-300 bg-emerald-50 p-3 text-sm font-semibold text-emerald-950';
            closeoutForm.dispatchEvent(new CustomEvent('draft-saved'));
            updateOutcome();
            activateStep(stepNames[Math.min(stepNames.length - 1, stepNames.indexOf(activeStep) + 1)], true);
        } catch {
            status.textContent = 'The draft was not reported as saved. Your entries remain here; explicitly retry.';
            status.className = 'rounded-lg border border-red-300 bg-red-50 p-3 text-sm font-semibold text-red-950';
            status.focus();
        } finally {
            submit.disabled = false;
            submit.textContent = originalLabel;
        }
    });

    const uploadForm = root.querySelector('[data-v2-upload-form]');
    if (uploadForm) {
        const queue = [];
        const queueList = uploadForm.querySelector('[data-v2-upload-queue]');
        const summary = uploadForm.querySelector('[data-v2-upload-summary]');
        const offline = uploadForm.querySelector('[data-v2-upload-offline]');
        const camera = uploadForm.querySelector('[data-v2-photo-camera]');
        const gallery = uploadForm.querySelector('[data-v2-photo-gallery]');
        let activeUploads = 0;
        let sequence = 0;

        const summarize = () => {
            const count = (state) => queue.filter((item) => item.state === state).length;
            summary.textContent = queue.length ? `${count('uploaded')} uploaded · ${count('uploading')} uploading · ${count('queued')} queued · ${count('failed') + count('offline')} need retry` : 'No uploads queued.';
            offline.classList.toggle('hidden', navigator.onLine);
        };
        const rowFor = (item) => queueList.querySelector(`[data-queue-id="${item.id}"]`);
        const renderItem = (item) => {
            let row = rowFor(item);
            if (!row) {
                row = document.createElement('li');
                row.dataset.queueId = item.id;
                row.className = 'rounded-lg border border-slate-200 p-3';
                queueList.append(row);
            }
            row.replaceChildren();
            const line = document.createElement('div');
            line.className = 'flex items-center justify-between gap-3';
            const label = document.createElement('span');
            label.className = 'min-w-0 truncate text-sm font-semibold';
            label.textContent = `${item.file.name} · ${item.category.replaceAll('_', ' ')}`;
            const state = document.createElement('span');
            state.className = 'shrink-0 text-xs font-bold';
            state.textContent = item.state === 'uploading' ? `${item.progress}%` : item.state;
            line.append(label, state);
            row.append(line);
            if (['failed', 'offline'].includes(item.state)) {
                const retry = document.createElement('button');
                retry.type = 'button';
                retry.className = 'button-secondary mt-2';
                retry.textContent = 'Retry';
                retry.addEventListener('click', () => {
                    if (!navigator.onLine) return summarize();
                    item.state = 'queued';
                    item.error = '';
                    renderItem(item);
                    pump();
                });
                row.append(retry);
            }
            if (item.error) {
                const error = document.createElement('p');
                error.className = 'mt-2 text-xs font-semibold text-red-800';
                error.textContent = item.error;
                row.append(error);
            }
        };

        const appendMedia = (body) => {
            const list = root.querySelector('[data-v2-media-list]');
            list.querySelector('[data-v2-media-empty]')?.remove();
            const article = document.createElement('article');
            article.className = 'flex min-h-11 items-center justify-between gap-3 border-t border-slate-200 pt-3';
            article.dataset.v2MediaId = body.id;
            const details = document.createElement('div');
            details.className = 'min-w-0';
            const link = document.createElement('a');
            link.className = 'font-bold text-brand-blue';
            link.href = body.show_url;
            link.target = '_blank';
            link.rel = 'noopener';
            link.textContent = body.category.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
            details.append(link);
            if (body.caption) {
                const caption = document.createElement('p');
                caption.className = 'truncate text-xs text-slate-600';
                caption.textContent = body.caption;
                details.append(caption);
            }
            const form = document.createElement('form');
            form.action = body.remove_url;
            form.method = 'POST';
            form.dataset.v2MediaRemove = '';
            const csrf = document.createElement('input');
            csrf.type = 'hidden';
            csrf.name = '_token';
            csrf.value = uploadForm.querySelector('[name="_token"]').value;
            const method = document.createElement('input');
            method.type = 'hidden';
            method.name = '_method';
            method.value = 'DELETE';
            const button = document.createElement('button');
            button.className = 'button-secondary';
            button.textContent = 'Remove';
            form.append(csrf, method, button);
            article.append(details, form);
            list.append(article);
            bindRemove(form);
            const count = root.querySelectorAll('[data-v2-media-id]').length;
            root.querySelector('[data-v2-media-count]').textContent = `${count} photos`;
            root.querySelector('[data-v2-tab-status="evidence"]').textContent = `${count} photos`;
        };

        const upload = (item) => {
            item.state = 'uploading';
            item.progress = 0;
            activeUploads++;
            renderItem(item);
            summarize();
            const data = new FormData();
            data.append('_token', uploadForm.querySelector('[name="_token"]').value);
            data.append('photo', item.file, item.file.name);
            data.append('category', item.category);
            if (item.caption) data.append('caption', item.caption);
            const request = new XMLHttpRequest();
            request.timeout = 180000;
            request.upload.addEventListener('progress', (event) => {
                if (!event.lengthComputable) return;
                item.progress = Math.round(event.loaded / event.total * 100);
                renderItem(item);
            });
            const finish = () => {
                activeUploads--;
                renderItem(item);
                summarize();
                pump();
            };
            request.addEventListener('load', () => {
                let body = {};
                try { body = JSON.parse(request.responseText); } catch { /* Never infer success from non-JSON. */ }
                if (request.status >= 200 && request.status < 300 && body.id && body.show_url) {
                    item.state = 'uploaded';
                    item.progress = 100;
                    appendMedia(body);
                    renderReadiness(body.readiness_errors ?? readinessErrors);
                } else {
                    item.state = 'failed';
                    item.error = Object.values(body.errors ?? {}).flat()[0] ?? body.message ?? 'Upload failed. Explicitly retry.';
                }
                finish();
            });
            request.addEventListener('error', () => { item.state = 'failed'; item.error = 'Upload failed. Reconnect and explicitly retry.'; finish(); });
            request.addEventListener('timeout', () => { item.state = 'failed'; item.error = 'Upload timed out and was not reported as saved.'; finish(); });
            request.open('POST', uploadForm.action);
            request.setRequestHeader('Accept', 'application/json');
            request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            request.send(data);
        };
        const pump = () => {
            if (!navigator.onLine) return summarize();
            while (activeUploads < 2) {
                const item = queue.find((candidate) => candidate.state === 'queued');
                if (!item) break;
                upload(item);
            }
        };
        const enqueue = (files) => {
            const category = uploadForm.querySelector('[name="category"]:checked')?.value;
            const caption = uploadForm.querySelector('[name="caption"]')?.value.trim() ?? '';
            [...files].forEach((file) => {
                const item = { id: String(++sequence), file, category, caption, state: navigator.onLine ? 'queued' : 'offline', progress: 0, error: navigator.onLine ? '' : 'Reconnect, then select Retry.' };
                queue.push(item);
                renderItem(item);
            });
            summarize();
            pump();
        };
        camera.addEventListener('change', () => { if (camera.files.length) enqueue(camera.files); camera.value = ''; });
        gallery.addEventListener('change', () => { if (gallery.files.length) enqueue(gallery.files); gallery.value = ''; });
        window.addEventListener('online', summarize);
        window.addEventListener('offline', summarize);

        const bindRemove = (form) => form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (!navigator.onLine) return;
            const button = form.querySelector('button');
            button.disabled = true;
            try {
                const response = await fetch(form.action, { method: 'DELETE', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': form.querySelector('[name="_token"]').value } });
                const body = await response.json();
                if (!response.ok) throw new Error(body.message ?? 'remove-failed');
                form.closest('[data-v2-media-id]')?.remove();
                renderReadiness(body.readiness_errors ?? readinessErrors);
                const count = root.querySelectorAll('[data-v2-media-id]').length;
                root.querySelector('[data-v2-media-count]').textContent = `${count} photos`;
                root.querySelector('[data-v2-tab-status="evidence"]').textContent = `${count} photos`;
            } catch {
                button.disabled = false;
                button.textContent = 'Retry remove';
            }
        });
        root.querySelectorAll('[data-v2-media-remove]').forEach(bindRemove);
        summarize();
    }

    const initialReadiness = root.querySelector('[data-v2-initial-readiness]');
    if (initialReadiness) {
        try { renderReadiness(JSON.parse(initialReadiness.textContent)); } catch { renderReadiness({}); }
    }
    activateStep('outcome');
});
