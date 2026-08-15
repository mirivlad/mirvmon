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

    window.MirvMon = Object.assign(window.MirvMon || {}, { formatMetricValue, formatDuration });

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

    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((element) => {
        bootstrap.Tooltip.getOrCreateInstance(element);
    });

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
})();
