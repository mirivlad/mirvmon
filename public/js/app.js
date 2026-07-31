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

    // Keeps throughput readable: 12500000 B/s reads as 11.92 MB/s.
    const formatMetricValue = (value, unit) => {
        const numeric = Number(value);
        if (!Number.isFinite(numeric)) {
            return '—';
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

    window.MirvMon = Object.assign(window.MirvMon || {}, { formatMetricValue });

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
})();
