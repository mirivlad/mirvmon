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
        }

        if (path.startsWith('/admin/')) {
            const settings = document.querySelector('[data-nav-section="settings"]');
            if (settings) settings.classList.add('active');
        }
    }

    function sectionClass(path) {
        if (path === '/') return 'ui-section-dashboard';
        if (path.startsWith('/groups')) return 'ui-section-groups';
        if (path.startsWith('/servers')) return 'ui-section-servers';
        if (path.startsWith('/sites')) return 'ui-section-websites';
        if (path.startsWith('/alerts')) return 'ui-section-incidents';
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
    prepareResponsiveIncidentTables();
})();
