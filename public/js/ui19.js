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

    markNavigation();
})();
