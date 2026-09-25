(() => {
    'use strict';

    function markNavigation() {
        const path = window.location.pathname;
        const candidates = Array.from(document.querySelectorAll('[data-nav-prefix], [data-nav-exact]'));
        let best = null;
        let bestLength = -1;

        candidates.forEach((element) => {
            const exact = element.getAttribute('data-nav-exact');
            const prefix = element.getAttribute('data-nav-prefix');
            const matches = exact !== null ? path === exact : (prefix !== null && path.startsWith(prefix));
            if (!matches) return;
            const length = exact !== null ? exact.length + 1000 : prefix.length;
            if (length > bestLength) {
                best = element;
                bestLength = length;
            }
        });

        if (best) {
            best.classList.add('active');
            best.setAttribute('aria-current', 'page');
            const dropdown = best.closest('.dropdown');
            if (dropdown) {
                const toggle = dropdown.querySelector(':scope > .dropdown-toggle');
                if (toggle) toggle.classList.add('active');
            }
        }
    }

    function sectionClass(path) {
        if (path === '/') return 'ui-section-dashboard';
        if (path.startsWith('/groups') || path.startsWith('/servers') || path.startsWith('/agents')) {
            return 'ui-section-servers';
        }
        if (path.startsWith('/sites')) return 'ui-section-websites';
        if (path.startsWith('/alerts')) return 'ui-section-incidents';
        if (path.startsWith('/observations') || path.startsWith('/reports')) return 'ui-section-analytics';
        if (path.startsWith('/admin/system')) return 'ui-section-system';
        if (path.startsWith('/admin/')) return 'ui-section-settings';
        return null;
    }

    function markSection() {
        const main = document.querySelector('.app-main');
        if (!main) return;
        const className = sectionClass(window.location.pathname);
        if (className) main.classList.add(className);
    }

    function prepareWebsiteProbeToggles() {
        document.querySelectorAll('[data-probe-toggle-form]').forEach((form) => {
            const input = form.querySelector('[data-probe-toggle-input]');
            const state = form.querySelector('[data-probe-toggle-state]');
            const usage = form.querySelector('[data-probe-toggle-usage]');
            if (!(input instanceof HTMLInputElement)) return;

            input.addEventListener('change', async () => {
                const previous = !input.checked;
                const enabled = input.checked;
                const siteCount = Number.parseInt(form.dataset.probeSites || '0', 10) || 0;
                if (!enabled && siteCount > 0) {
                    const message = (form.dataset.confirmDisable || '')
                        .replace('__COUNT__', String(siteCount));
                    if (message && !window.confirm(message)) {
                        input.checked = previous;
                        return;
                    }
                }

                input.disabled = true;
                form.classList.add('is-saving');
                const body = new FormData(form);
                body.set('enabled', enabled ? '1' : '0');

                try {
                    const response = await fetch(form.action, {
                        method: 'POST',
                        body,
                        headers: { Accept: 'application/json' },
                    });
                    const payload = await response.json().catch(() => ({}));
                    if (!response.ok || typeof payload.enabled !== 'boolean') {
                        throw new Error(payload.error || 'probe_toggle_failed');
                    }

                    input.checked = payload.enabled;
                    if (state) {
                        state.classList.toggle('is-enabled', payload.enabled);
                        state.textContent = payload.enabled
                            ? state.dataset.enabledLabel
                            : state.dataset.disabledLabel;
                    }
                    if (!payload.enabled && payload.removed_assignments > 0) {
                        form.dataset.probeSites = '0';
                        if (usage) {
                            usage.classList.add('d-none');
                            usage.textContent = '';
                        }
                    }
                } catch (error) {
                    input.checked = previous;
                    window.alert(form.dataset.errorMessage || 'Unable to save probe setting.');
                } finally {
                    form.classList.remove('is-saving');
                    input.disabled = form.dataset.probeCapable !== '1'
                        || form.dataset.probeEditable !== '1';
                }
            });
        });
    }

    function prepareResponsiveIncidentTables() {
        if (!window.location.pathname.startsWith('/alerts')) return;

        document.querySelectorAll('.app-main table').forEach((table) => {
            const headers = Array.from(table.querySelectorAll('thead th')).map((header) =>
                (header.textContent || '').trim()
            );
            if (headers.length === 0) return;

            table.classList.add('ui-responsive-table');
            table.querySelectorAll('tbody tr').forEach((row) => {
                Array.from(row.children).forEach((cell, index) => {
                    if (!(cell instanceof HTMLTableCellElement)) return;
                    if (!cell.dataset.label && headers[index]) {
                        cell.dataset.label = headers[index];
                    }
                });
            });
        });
    }

    markNavigation();
    markSection();
    prepareWebsiteProbeToggles();
    prepareResponsiveIncidentTables();
})();
