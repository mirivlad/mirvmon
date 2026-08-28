(function () {
    'use strict';

    const root = document.querySelector('[data-website-metrics]');
    if (!root || typeof Chart === 'undefined') return;

    const endpoint = root.querySelector('[data-metrics-endpoint]');
    const period = root.querySelector('[data-metrics-period]');
    const state = root.querySelector('[data-metrics-state]');
    const incidents = root.querySelector('[data-metrics-incidents]');
    const charts = [];
    const endpointNames = new Map();
    const palette = ['#0d6efd', '#198754', '#6f42c1', '#fd7e14', '#0dcaf0', '#dc3545', '#6c757d'];

    if (endpoint) {
        [...endpoint.options].forEach((option) => {
            if (option.value) endpointNames.set(String(option.value), option.textContent.trim());
        });
    }

    const formatPercent = (value) => value === null || value === undefined
        ? '—'
        : (Number(value) * 100).toLocaleString(undefined, { minimumFractionDigits: 1, maximumFractionDigits: 2 }) + '%';
    const formatMs = (value) => value === null || value === undefined
        ? '—'
        : Number(value).toLocaleString(undefined, { maximumFractionDigits: 0 }) + ' ms';

    const formatBucket = (seconds) => {
        const value = Number(seconds || 0);
        if (value >= 86400 && value % 86400 === 0) return (value / 86400) + ' d';
        if (value >= 3600 && value % 3600 === 0) return (value / 3600) + ' h';
        if (value >= 60 && value % 60 === 0) return (value / 60) + ' min';
        return value + ' s';
    };

    const formatTime = (value) => {
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) return value;
        const longRange = period && ['7d', '30d', '365d'].includes(period.value);
        return new Intl.DateTimeFormat(undefined, longRange
            ? { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }
            : { hour: '2-digit', minute: '2-digit' }).format(date);
    };

    const destroyCharts = () => {
        charts.forEach((chart) => chart.destroy());
        charts.length = 0;
    };

    const datasets = (points, labels, percent) => {
        const grouped = new Map();
        points.forEach((point) => {
            const id = String(point.endpoint_id);
            if (!grouped.has(id)) grouped.set(id, new Map());
            grouped.get(id).set(point.time, point.value);
        });

        return [...grouped.entries()].map(([id, values], index) => {
            const color = palette[index % palette.length];
            return {
                label: endpointNames.get(id) || ('Endpoint ' + id),
                data: labels.map((label) => {
                    const value = values.has(label) ? values.get(label) : null;
                    return value === null || value === undefined ? null : Number(value) * (percent ? 100 : 1);
                }),
                borderColor: color,
                backgroundColor: color + '22',
                pointRadius: 0,
                borderWidth: 2,
                tension: 0.15,
                spanGaps: true,
            };
        });
    };

    const drawChart = (canvas, points, percent) => {
        const labels = [...new Set(points.map((point) => point.time))].sort();
        const chart = new Chart(canvas.getContext('2d'), {
            type: 'line',
            data: {
                labels: labels.map(formatTime),
                datasets: datasets(points, labels, percent),
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                scales: {
                    y: percent
                        ? { min: 0, max: 100, ticks: { callback: (value) => value + '%' } }
                        : { beginAtZero: true, ticks: { callback: (value) => value + ' ms' } },
                },
                plugins: {
                    legend: { display: endpointNames.size > 1 && !(endpoint && endpoint.value) },
                },
            },
        });
        charts.push(chart);
    };

    const renderKpis = (summary) => {
        const values = summary || {};
        root.querySelectorAll('[data-metric-kpi]').forEach((element) => {
            const key = element.dataset.metricKpi;
            element.textContent = key === 'transport_availability' || key === 'assertion_success'
                ? formatPercent(values[key])
                : formatMs(values[key]);
        });
    };

    const renderMeta = (payload) => {
        if (!state) return;
        const count = payload.summary ? Number(payload.summary.sample_count || 0) : 0;
        if (count === 0) {
            state.textContent = root.dataset.emptyText || '';
            return;
        }
        state.textContent = (root.dataset.metaTemplate || '')
            .replace('__SOURCE__', payload.source || '—')
            .replace('__BUCKET__', formatBucket(payload.bucket_seconds))
            .replace('__SAMPLES__', count.toLocaleString());
    };

    const renderIncidents = (payload) => {
        if (!incidents) return;
        incidents.textContent = (root.dataset.incidentsTemplate || '')
            .replace('__COUNT__', String((payload.incidents || []).length));
    };

    const render = (payload) => {
        destroyCharts();
        renderKpis(payload.summary);
        renderMeta(payload);
        renderIncidents(payload);

        const series = payload.series || {};
        root.querySelectorAll('[data-website-chart]').forEach((canvas) => {
            const key = canvas.dataset.websiteChart;
            drawChart(canvas, series[key] || [], key === 'transport_availability' || key === 'assertion_success');
        });
    };

    const load = () => {
        const query = new URLSearchParams({ period: period ? period.value : '24h' });
        if (endpoint && endpoint.value) query.set('endpoint_id', endpoint.value);
        if (state) state.textContent = root.dataset.loadingText || '';

        fetch(root.dataset.apiUrl + '?' + query.toString(), { headers: { Accept: 'application/json' } })
            .then((response) => response.ok ? response.json() : Promise.reject(new Error('metrics unavailable')))
            .then(render)
            .catch(() => {
                destroyCharts();
                renderKpis(null);
                if (state) state.textContent = root.dataset.errorText || '';
                if (incidents) incidents.textContent = '';
            });
    };

    if (endpoint) endpoint.addEventListener('change', load);
    if (period) period.addEventListener('change', load);
    load();
}());
