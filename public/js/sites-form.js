(function () {
    'use strict';
    const root = document.querySelector('[data-sites-form]');
    if (!root) return;
    const list = root.querySelector('[data-endpoints]');
    const reindex = () => {
        [...list.querySelectorAll('[data-endpoint]')].forEach((card, index) => {
            card.querySelectorAll('[name]').forEach((field) => {
                field.name = field.name.replace(/endpoints\[\d+\]/, 'endpoints[' + index + ']');
            });
            const primary = card.querySelector('[data-field="is_primary"]');
            const hidden = card.querySelector('[data-primary-value]');
            if (primary && hidden) {
                primary.value = String(index);
                primary.checked = hidden.value === '1';
            }
            const title = card.querySelector('[data-endpoint-title]');
            if (title) title.textContent = 'Endpoint ' + (index + 1);
        });
    };
    root.addEventListener('change', (event) => {
        const radio = event.target.closest('[data-field="is_primary"]');
        if (!radio) return;
        list.querySelectorAll('[data-primary-value]').forEach((hidden) => { hidden.value = '0'; });
        const card = radio.closest('[data-endpoint]');
        const hidden = card && card.querySelector('[data-primary-value]');
        if (hidden) hidden.value = '1';
    });
    root.addEventListener('click', (event) => {
        const remove = event.target.closest('[data-remove-endpoint]');
        if (remove && list.querySelectorAll('[data-endpoint]').length > 1) {
            remove.closest('[data-endpoint]').remove(); reindex();
        }
        if (event.target.closest('[data-add-endpoint]')) {
            const first = list.querySelector('[data-endpoint]');
            if (!first) return;
            const clone = first.cloneNode(true);
            clone.querySelectorAll('input, textarea').forEach((field) => {
                if (field.type === 'radio' || field.type === 'checkbox') field.checked = false;
                else if (field.type !== 'hidden') field.value = '';
            });
            const hidden = clone.querySelector('[data-primary-value]');
            if (hidden) hidden.value = '0';
            list.appendChild(clone); reindex();
        }
    });
    reindex();
}());
