(() => {
    'use strict';

    const RATE_UNITS = ['B/s', 'KB/s', 'MB/s', 'GB/s', 'TB/s'];
    const RATE_STEP = 1024;

    const trimNumber = (value) => {
        const rounded = Math.round(value * 100) / 100;
        if (Number.isInteger(rounded) && Math.abs(rounded) < 1000) {
            return String(rounded);
        }
        return String(Number(rounded.toFixed(2)));
    };

    const formatDuration = (seconds) => {
        let remaining = Math.max(0, Math.round(Number(seconds)));
        const days = Math.floor(remaining / 86400);
        remaining %= 86400;
        const hours = Math.floor(remaining / 3600);
        remaining %= 3600;
        const minutes = Math.floor(remaining / 60);
        if (days > 0) {
            return days + ' д ' + hours + ' ч';
        }
        if (hours > 0) {
            return hours + ' ч ' + minutes + ' мин';
        }
        return minutes + ' мин';
    };

    // Keeps throughput readable: 12500000 B/s reads as 11.92 MB/s.
    const formatMetricValue = (value, unit) => {
        const numeric = Number(value);
        if (!Number.isFinite(numeric)) {
            return '—';
        }
        if (unit === 'uptime') {
            return formatDuration(numeric);
        }
        if (unit !== 'B/s') {
            return trimNumber(numeric) + (unit || '');
        }

        let scaled = numeric;
        let index = 0;
        while (Math.abs(scaled) >= RATE_STEP && index < RATE_UNITS.length - 1) {
            scaled /= RATE_STEP;
            index += 1;
        }
        return trimNumber(scaled) + ' ' + RATE_UNITS[index];
    };

    const initTooltips = (root = document) => {
        if (root instanceof Element && root.matches('[data-bs-toggle="tooltip"]')) {
            bootstrap.Tooltip.getOrCreateInstance(root);
        }
        root.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((element) => {
            bootstrap.Tooltip.getOrCreateInstance(element);
        });
    };

    window.MirvMon = Object.assign(window.MirvMon || {}, {
        formatMetricValue,
        formatDuration,
        initTooltips
    });

    document.addEventListener('submit', (event) => {
        const submitter = event.submitter;
        if (!(submitter instanceof HTMLElement)) {
            return;
        }
        const message = submitter.dataset.confirm;
        if (message && !window.confirm(message)) {
            event.preventDefault();
        }
    });

    initTooltips();

    document.querySelectorAll('[data-server-filter-form]').forEach((form) => {
        let timeoutId;
        Array.from(form.elements).filter((element) => (
            element instanceof HTMLInputElement && element.type === 'search'
        )).forEach((input) => {
            input.addEventListener('input', () => {
                window.clearTimeout(timeoutId);
                timeoutId = window.setTimeout(() => form.requestSubmit(), 350);
            });
        });
    });


    const liveFragments = Array.from(document.querySelectorAll('[data-live-fragment]'))
        .map((element) => ({
            key: element.dataset.liveFragment || '',
            url: element.dataset.liveUrl || window.location.href,
            interval: Math.min(300000, Math.max(5000, Number.parseInt(element.dataset.liveIntervalMs || '30000', 10) || 30000))
        }))
        .filter((item) => item.key !== '');

    const liveGroups = new Map();
    liveFragments.forEach((item) => {
        const url = new URL(item.url, window.location.href).toString();
        const groupKey = `${url}\n${item.interval}`;
        if (!liveGroups.has(groupKey)) {
            liveGroups.set(groupKey, { url, interval: item.interval, keys: [] });
        }
        const group = liveGroups.get(groupKey);
        if (!group.keys.includes(item.key)) group.keys.push(item.key);
    });

    const findLiveFragment = (root, key) => Array.from(root.querySelectorAll('[data-live-fragment]'))
        .find((element) => element.dataset.liveFragment === key) || null;

    const liveFragmentHasEditableFocus = (fragment) => {
        const active = document.activeElement;
        if (!(active instanceof Element) || !fragment.contains(active)) return false;
        return active.matches('input, select, textarea, [contenteditable="true"], [contenteditable=""]');
    };

    liveGroups.forEach((group) => {
        let timer = null;
        let inFlight = false;
        const schedule = (delay = group.interval) => {
            window.clearTimeout(timer);
            timer = window.setTimeout(refresh, delay);
        };
        const refresh = async () => {
            if (inFlight || document.visibilityState !== 'visible') {
                schedule();
                return;
            }
            inFlight = true;
            try {
                const response = await fetch(group.url, {
                    headers: {
                        Accept: 'text/html',
                        'X-MirvMon-Live-Fragment': '1'
                    },
                    credentials: 'same-origin',
                    cache: 'no-store'
                });
                if (!response.ok) throw new Error('Live fragment refresh failed.');
                const freshDocument = new DOMParser().parseFromString(await response.text(), 'text/html');
                group.keys.forEach((key) => {
                    const current = findLiveFragment(document, key);
                    const fresh = findLiveFragment(freshDocument, key);
                    if (!current || !fresh || current.isEqualNode(fresh)) return;
                    if (liveFragmentHasEditableFocus(current)) return;
                    const beforeUpdate = new CustomEvent('mirvmon:live-fragment-before-update', {
                        bubbles: true,
                        cancelable: true,
                        detail: { key }
                    });
                    if (!current.dispatchEvent(beforeUpdate)) return;
                    const replacement = document.importNode(fresh, true);
                    current.replaceWith(replacement);
                    initTooltips(replacement);
                    replacement.dispatchEvent(new CustomEvent('mirvmon:live-fragment-updated', {
                        bubbles: true,
                        detail: { key }
                    }));
                });
            } catch {
                // Keep the last known state. The next scheduled refresh retries.
            } finally {
                inFlight = false;
                schedule();
            }
        };
        schedule();
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') schedule(250);
        });
    });

})();
