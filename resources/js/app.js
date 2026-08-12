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

document.querySelectorAll('[data-copy-target]').forEach((button) => button.addEventListener('click', async () => {
    const input = document.getElementById(button.dataset.copyTarget);
    if (!input) return;
    await navigator.clipboard.writeText(input.value);
    button.textContent = 'Copied';
}));

document.querySelectorAll('[data-catalog-picker]').forEach((picker) => {
    const dialog = picker.querySelector('[data-catalog-dialog]');
    const launcher = picker.querySelector('[data-catalog-dialog-open]');
    const form = picker.querySelector('[data-catalog-form]');
    const search = picker.querySelector('[data-catalog-search]');
    const item = picker.querySelector('[data-catalog-item]');
    const status = picker.querySelector('[data-catalog-status]');
    const variantWrap = picker.querySelector('[data-catalog-variant-wrap]');
    const variant = picker.querySelector('[data-catalog-variant]');
    let dirty = false;
    let submitting = false;

    const updateVariant = () => {
        const selected = item?.selectedOptions[0];
        const serviceId = selected?.dataset.serviceId;
        const choices = [...(variant?.options ?? [])].filter((option) => option.value);
        choices.forEach((option) => {
            const available = Boolean(serviceId) && option.dataset.serviceId === serviceId;
            option.hidden = !available;
            option.disabled = !available;
        });
        const hasVariants = choices.some((option) => !option.disabled);
        variantWrap.hidden = !hasVariants;
        if (!hasVariants || variant?.selectedOptions[0]?.disabled) variant.value = '';
    };

    const filter = () => {
        const query = search.value.trim().toLowerCase();
        let count = 0;
        [...item.options].forEach((option) => {
            if (!option.value) return;
            const matches = !query || option.dataset.search.includes(query);
            option.hidden = !matches;
            option.disabled = !matches;
            if (matches) count += 1;
        });
        if (item.selectedOptions[0]?.disabled) item.value = '';
        status.textContent = query ? `${count} matching Catalog item${count === 1 ? '' : 's'}.` : `${count} active Catalog item${count === 1 ? '' : 's'} available.`;
        updateVariant();
    };

    const close = () => {
        if (!submitting && dirty && !window.confirm('Discard this Catalog selection?')) return;
        dialog.close();
        launcher?.focus();
    };

    launcher?.addEventListener('click', () => {
        dirty = false;
        submitting = false;
        dialog.showModal();
        filter();
        requestAnimationFrame(() => search?.focus());
    });
    picker.querySelectorAll('[data-catalog-dialog-close]').forEach((button) => button.addEventListener('click', close));
    dialog?.addEventListener('cancel', (event) => {
        event.preventDefault();
        close();
    });
    form?.addEventListener('input', () => dirty = true);
    form?.addEventListener('submit', () => {
        submitting = true;
        dirty = false;
    });
    search?.addEventListener('input', filter);
    item?.addEventListener('change', updateVariant);
});

const subscriptionService = document.querySelector('[data-subscription-service]');
const subscriptionVariant = document.querySelector('[data-subscription-variant]');
if (subscriptionService && subscriptionVariant) {
    const filterSubscriptionVariants = () => {
        const serviceId = subscriptionService.value;
        let selectedStillAvailable = !subscriptionVariant.value;
        [...subscriptionVariant.options].forEach((option) => {
            if (!option.value) return;
            option.hidden = option.dataset.serviceId !== serviceId;
            option.disabled = option.hidden;
            if (option.selected && !option.hidden) selectedStillAvailable = true;
        });
        if (!selectedStillAvailable) subscriptionVariant.value = '';
    };
    subscriptionService.addEventListener('change', filterSubscriptionVariants);
    filterSubscriptionVariants();
}

const customerPicker = document.querySelector('[data-ticket-customer-picker]');
if (customerPicker) {
    const searchInput = customerPicker.querySelector('#customer_search');
    const customerId = customerPicker.querySelector('#customer_id');
    const locationSelect = customerPicker.querySelector('#service_location_id');
    const contactSelect = customerPicker.querySelector('#contact_id');
    const results = customerPicker.querySelector('[data-customer-search-results]');
    const status = customerPicker.querySelector('[data-customer-search-status]');
    const empty = customerPicker.querySelector('[data-customer-empty]');
    const retry = customerPicker.querySelector('[data-customer-search-retry]');
    let resultCustomers = [];
    let activeResult = -1;
    let searchTimer;
    let searchRequest;

    const closeResults = () => {
        results.hidden = true;
        results.classList.add('hidden');
        searchInput.setAttribute('aria-expanded', 'false');
        searchInput.removeAttribute('aria-activedescendant');
        activeResult = -1;
    };

    const setOptions = (select, items, label, selectedId = null) => {
        select.replaceChildren(new Option(label, ''));
        items.forEach((item) => {
            const option = new Option(item.label, String(item.id), false, String(item.id) === String(selectedId ?? ''));
            Object.entries(item.data ?? {}).forEach(([key, value]) => option.dataset[key] = value ?? '');
            select.add(option);
        });
        select.disabled = false;
    };

    const selectCustomer = (customer) => {
        customerId.value = customer.id;
        searchInput.value = customer.display_name;
        const primaryLocation = customer.locations.find((location) => location.is_primary) ?? customer.locations[0];
        setOptions(locationSelect, customer.locations.map((location) => ({
            id: location.id,
            label: `${location.name} — ${location.address}`,
            data: { primaryContact: location.primary_contact_id },
        })), 'Select location', primaryLocation?.id);
        const defaultContactId = primaryLocation?.primary_contact_id
            ?? customer.contacts.find((contact) => contact.is_preferred)?.id;
        setOptions(contactSelect, customer.contacts.map((contact) => ({
            id: contact.id,
            label: contact.name,
            data: { preferred: contact.is_preferred ? '1' : '0' },
        })), 'Use location/customer default', defaultContactId);
        status.textContent = `${customer.display_name} selected.`;
        empty?.classList.add('hidden');
        retry?.classList.add('hidden');
        closeResults();
    };

    const updateActiveResult = (index) => {
        const options = [...results.querySelectorAll('[role="option"]')];
        if (!options.length) return;
        activeResult = (index + options.length) % options.length;
        options.forEach((option, optionIndex) => option.setAttribute('aria-selected', optionIndex === activeResult ? 'true' : 'false'));
        options[activeResult].scrollIntoView({ block: 'nearest' });
        searchInput.setAttribute('aria-activedescendant', options[activeResult].id);
    };

    const renderResults = (customers) => {
        results.replaceChildren();
        resultCustomers = customers;
        customers.forEach((customer, index) => {
            const option = document.createElement('button');
            option.type = 'button';
            option.id = `customer-search-option-${customer.id}`;
            option.className = 'block min-h-11 w-full rounded-md px-3 py-2 text-left hover:bg-blue-50 focus:bg-blue-50 focus:outline-none focus:ring-2 focus:ring-brand-blue';
            option.setAttribute('role', 'option');
            option.setAttribute('aria-selected', 'false');
            const name = document.createElement('span');
            name.className = 'block font-bold text-slate-950';
            name.textContent = customer.display_name;
            const detail = document.createElement('span');
            detail.className = 'block text-sm text-slate-600';
            detail.textContent = [customer.secondary, `${customer.locations.length} active location${customer.locations.length === 1 ? '' : 's'}`].filter(Boolean).join(' · ');
            option.append(name, detail);
            option.addEventListener('click', () => selectCustomer(resultCustomers[index]));
            results.append(option);
        });
        results.hidden = customers.length === 0;
        results.classList.toggle('hidden', customers.length === 0);
        searchInput.setAttribute('aria-expanded', customers.length ? 'true' : 'false');
        empty?.classList.toggle('hidden', customers.length !== 0);
    };

    const search = async () => {
        const term = searchInput.value.trim();
        retry?.classList.add('hidden');
        if (term.length < 2) {
            status.textContent = term ? 'Enter at least two characters.' : '';
            empty?.classList.add('hidden');
            closeResults();
            return;
        }
        if (!navigator.onLine) {
            status.textContent = 'Offline. Reconnect, then retry the customer search.';
            retry?.classList.remove('hidden');
            closeResults();
            return;
        }
        searchRequest?.abort();
        searchRequest = new AbortController();
        status.textContent = 'Searching customers…';
        try {
            const url = new URL(customerPicker.dataset.searchUrl, window.location.origin);
            url.searchParams.set('q', term);
            const response = await fetch(url, { headers: { Accept: 'application/json' }, signal: searchRequest.signal });
            if (!response.ok) throw new Error('search-failed');
            const body = await response.json();
            renderResults(body.customers ?? []);
            status.textContent = body.customers?.length
                ? `${body.customers.length} matching customer${body.customers.length === 1 ? '' : 's'} found.`
                : 'No matching active customer was found.';
        } catch (error) {
            if (error.name === 'AbortError') return;
            status.textContent = 'Customer search failed. Explicitly retry when ready.';
            retry?.classList.remove('hidden');
            closeResults();
        }
    };

    searchInput.addEventListener('input', () => {
        customerId.value = '';
        locationSelect.replaceChildren(new Option('Select location', ''));
        locationSelect.disabled = true;
        contactSelect.replaceChildren(new Option('Use location/customer default', ''));
        contactSelect.disabled = true;
        clearTimeout(searchTimer);
        searchTimer = setTimeout(search, 250);
    });
    searchInput.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            updateActiveResult(activeResult + 1);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            updateActiveResult(activeResult - 1);
        } else if (event.key === 'Enter' && activeResult >= 0) {
            event.preventDefault();
            selectCustomer(resultCustomers[activeResult]);
        } else if (event.key === 'Escape') {
            closeResults();
        }
    });
    retry?.addEventListener('click', search);
    document.addEventListener('click', (event) => {
        if (!customerPicker.contains(event.target)) closeResults();
    });
    locationSelect.addEventListener('change', () => {
        const primaryContact = locationSelect.selectedOptions[0]?.dataset.primaryContact;
        if (primaryContact) contactSelect.value = primaryContact;
    });

    const dialog = document.querySelector('[data-quick-customer-dialog]');
    const form = dialog?.querySelector('[data-quick-customer-form]');
    const modalStatus = dialog?.querySelector('[data-quick-customer-status]');
    const launcher = customerPicker.querySelector('[data-quick-customer-open]');
    let modalDirty = false;

    const clearModalErrors = () => {
        dialog.querySelectorAll('[data-quick-error-for]').forEach((element) => {
            element.textContent = '';
            element.classList.add('hidden');
        });
        form.querySelectorAll('[aria-invalid="true"]').forEach((field) => field.removeAttribute('aria-invalid'));
    };
    const requestClose = () => {
        if (modalDirty && !window.confirm('Discard the unsaved customer and location?')) return;
        dialog.close();
        launcher?.focus();
    };

    launcher?.addEventListener('click', () => {
        form.querySelector('[name="display_name"]').value = searchInput.value.trim();
        modalDirty = Boolean(form.querySelector('[name="display_name"]').value);
        clearModalErrors();
        modalStatus.classList.add('hidden');
        dialog.showModal();
        form.querySelector('[name="display_name"]').focus();
    });
    dialog?.querySelectorAll('[data-quick-customer-close]').forEach((button) => button.addEventListener('click', requestClose));
    dialog?.addEventListener('cancel', (event) => {
        event.preventDefault();
        requestClose();
    });
    form?.addEventListener('input', () => modalDirty = true);
    form?.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearModalErrors();
        if (!navigator.onLine) {
            modalStatus.textContent = 'Offline. Your entries remain here. Reconnect, then explicitly retry.';
            modalStatus.className = 'mb-5 rounded-lg border border-red-300 bg-red-50 p-4 font-semibold text-red-900';
            modalStatus.focus();
            return;
        }
        const submit = form.querySelector('[data-quick-customer-submit]');
        submit.disabled = true;
        submit.textContent = 'Saving…';
        try {
            const response = await fetch(form.action, { method: 'POST', headers: { Accept: 'application/json' }, body: new FormData(form) });
            const body = await response.json();
            if (response.status === 422) {
                let firstInvalid;
                Object.entries(body.errors ?? {}).forEach(([key, messages]) => {
                    const error = dialog.querySelector(`[data-quick-error-for="${CSS.escape(key)}"]`);
                    const fieldName = key.replace(/\.([^.]*)/g, '[$1]');
                    const field = form.querySelector(`[name="${CSS.escape(fieldName)}"]`);
                    if (error) {
                        error.textContent = messages[0];
                        error.classList.remove('hidden');
                    }
                    field?.setAttribute('aria-invalid', 'true');
                    firstInvalid ??= field;
                });
                modalStatus.textContent = 'Check the highlighted fields and try again.';
                modalStatus.className = 'mb-5 rounded-lg border border-red-300 bg-red-50 p-4 font-semibold text-red-900';
                modalStatus.focus();
                firstInvalid?.focus();
                return;
            }
            if (!response.ok) throw new Error('create-failed');
            selectCustomer(body.customer);
            modalDirty = false;
            form.reset();
            dialog.close();
            searchInput.focus();
            status.textContent = body.message;
        } catch {
            modalStatus.textContent = 'The customer was not reported as saved. Your entries remain here; explicitly retry.';
            modalStatus.className = 'mb-5 rounded-lg border border-red-300 bg-red-50 p-4 font-semibold text-red-900';
            modalStatus.focus();
        } finally {
            submit.disabled = false;
            submit.textContent = 'Save and select customer';
        }
    });
}
