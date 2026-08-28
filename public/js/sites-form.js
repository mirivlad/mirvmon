(function () {
    'use strict';

    const root = document.querySelector('[data-sites-form]');
    if (!root) return;

    const list = root.querySelector('[data-endpoints]');
    const template = root.querySelector('[data-endpoint-template]');
    const endpointLabel = root.dataset.endpointLabel || 'Endpoint';
    const notConfiguredLabel = root.dataset.notConfiguredLabel || '—';

    const setVisible = (element, visible) => {
        if (!element) return;
        element.classList.toggle('d-none', !visible);
    };

    const syncEndpoint = (card) => {
        const name = card.querySelector('[data-field="name"]');
        const url = card.querySelector('[data-field="url"]');
        const title = card.querySelector('[data-endpoint-title]');
        const summary = card.querySelector('[data-endpoint-summary]');
        if (title) title.textContent = name && name.value.trim() ? name.value.trim() : endpointLabel;
        if (summary) summary.textContent = url && url.value.trim() ? url.value.trim() : notConfiguredLabel;

        const statusToggle = card.querySelector('[data-status-toggle]');
        setVisible(card.querySelector('[data-status-options]'), !!(statusToggle && statusToggle.checked));

        const method = card.querySelector('[data-method]');
        const contentToggle = card.querySelector('[data-content-toggle]');
        const contentOptions = card.querySelector('[data-content-options]');
        const contentField = card.querySelector('[data-content-field]');
        const contentAllowed = !method || method.value !== 'HEAD';
        if (contentToggle) {
            contentToggle.disabled = !contentAllowed;
            if (!contentAllowed) contentToggle.checked = false;
        }
        const contentEnabled = contentAllowed && !!(contentToggle && contentToggle.checked);
        setVisible(contentOptions, contentEnabled);
        if (contentField) contentField.disabled = !contentEnabled;

        const authType = card.querySelector('[data-auth-type]');
        const auth = authType ? authType.value : 'none';
        const usernameWrap = card.querySelector('[data-auth-username-wrap]');
        const secretWrap = card.querySelector('[data-auth-secret-wrap]');
        const username = card.querySelector('[data-auth-username]');
        const secret = card.querySelector('[data-auth-secret]');
        setVisible(usernameWrap, auth === 'basic');
        setVisible(secretWrap, auth !== 'none');
        if (username) username.disabled = auth !== 'basic';
        if (secret) secret.disabled = auth === 'none';
    };

    const syncPrimary = () => {
        const cards = [...list.querySelectorAll('[data-endpoint]')];
        if (cards.length === 0) return;
        let selected = cards.find((card) => card.querySelector('[data-field="is_primary"]:checked'));
        if (!selected) {
            selected = cards[0];
            const radio = selected.querySelector('[data-field="is_primary"]');
            if (radio) radio.checked = true;
        }
        cards.forEach((card) => {
            const primary = card === selected;
            const hidden = card.querySelector('[data-primary-value]');
            const badge = card.querySelector('[data-primary-badge]');
            if (hidden) hidden.value = primary ? '1' : '0';
            if (badge) badge.classList.toggle('d-none', !primary);
        });
    };

    const reindex = () => {
        [...list.querySelectorAll('[data-endpoint]')].forEach((card, index) => {
            card.querySelectorAll('[name]').forEach((field) => {
                field.name = field.name.replace(/endpoints\[(?:\d+|__INDEX__)\]/, 'endpoints[' + index + ']');
            });
            const primary = card.querySelector('[data-field="is_primary"]');
            if (primary) primary.value = String(index);
            syncEndpoint(card);
        });
        syncPrimary();
    };

    const addEndpoint = () => {
        if (!template) return;
        const index = list.querySelectorAll('[data-endpoint]').length;
        const fragment = template.content.cloneNode(true);
        fragment.querySelectorAll('[name]').forEach((field) => {
            field.name = field.name.replace(/endpoints\[__INDEX__\]/, 'endpoints[' + index + ']');
        });
        const card = fragment.querySelector('[data-endpoint]');
        list.appendChild(fragment);
        if (card) {
            syncEndpoint(card);
            const name = card.querySelector('[data-field="name"]');
            if (name) name.focus();
        }
        reindex();
    };

    root.addEventListener('input', (event) => {
        const card = event.target.closest('[data-endpoint]');
        if (card && (event.target.matches('[data-field="name"]') || event.target.matches('[data-field="url"]'))) {
            syncEndpoint(card);
        }
    });

    root.addEventListener('change', (event) => {
        const card = event.target.closest('[data-endpoint]');
        if (card) syncEndpoint(card);
        if (event.target.matches('[data-field="is_primary"]')) syncPrimary();

        if (event.target.matches('[data-domain-toggle]')) {
            const options = root.querySelector('[data-domain-options]');
            const domain = root.querySelector('#registration_domain');
            setVisible(options, event.target.checked);
            if (domain) domain.disabled = !event.target.checked;
        }
    });

    root.addEventListener('click', (event) => {
        const remove = event.target.closest('[data-remove-endpoint]');
        if (remove) {
            const cards = list.querySelectorAll('[data-endpoint]');
            if (cards.length > 1) {
                remove.closest('[data-endpoint]').remove();
                reindex();
            }
            return;
        }
        if (event.target.closest('[data-add-endpoint]')) addEndpoint();
    });

    [...list.querySelectorAll('[data-endpoint]')].forEach(syncEndpoint);
    reindex();
}());
