(function () {
    'use strict';

    const root = document.querySelector('[data-connectivity-settings]');
    if (!root) return;

    const rows = root.querySelector('[data-connectivity-targets]');
    const template = root.querySelector('[data-connectivity-target-template]');
    const add = root.querySelector('[data-connectivity-target-add]');
    const quorum = root.querySelector('[name="quorum"]');
    if (!rows || !template || !add || !quorum) return;

    function targetRows() {
        return Array.from(rows.querySelectorAll('[data-connectivity-target-row]'));
    }

    function sync() {
        const count = targetRows().length;
        quorum.max = String(Math.max(1, count));
        if (Number(quorum.value) > count) quorum.value = String(count);
        targetRows().forEach((row) => {
            const remove = row.querySelector('[data-connectivity-target-remove]');
            if (remove) remove.disabled = count <= 1;
        });
    }

    add.addEventListener('click', function () {
        rows.appendChild(template.content.cloneNode(true));
        const inputs = targetRows();
        inputs[inputs.length - 1]?.querySelector('input')?.focus();
        sync();
    });

    rows.addEventListener('click', function (event) {
        const button = event.target.closest('[data-connectivity-target-remove]');
        if (!button || targetRows().length <= 1) return;
        button.closest('[data-connectivity-target-row]')?.remove();
        sync();
    });

    sync();
}());
