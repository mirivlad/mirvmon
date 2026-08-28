(function () {
    'use strict';
    const root = document.querySelector('[data-website-chart]');
    if (!root || typeof Chart === 'undefined') return;
    const id = window.location.pathname.split('/')[2];
    const endpoint = document.getElementById('website-metrics-endpoint');
    const charts = [];
    const load = () => {
        const query = new URLSearchParams({ period: '24h' });
        if (endpoint && endpoint.value) query.set('endpoint_id', endpoint.value);
        fetch('/api/sites/' + encodeURIComponent(id) + '/metrics?' + query.toString(), { headers: { Accept: 'application/json' } })
            .then((response) => response.ok ? response.json() : Promise.reject(new Error('metrics unavailable')))
            .then((data) => {
                charts.forEach((chart) => chart.destroy()); charts.length = 0;
                const labels = [...new Set((data.series.total_ms || []).map((point) => point.time))];
                document.querySelectorAll('[data-website-chart]').forEach((canvas) => {
                    const key = canvas.dataset.websiteChart === 'availability' ? 'transport_availability' : 'total_ms';
                    const points = data.series[key] || [];
                    charts.push(new Chart(canvas, { type: 'line', data: { labels, datasets: [{ label: key, data: labels.map((label) => { const point = points.find((item) => item.time === label); return point ? point.value : null; }) }] }, options: { responsive: true, maintainAspectRatio: false } }));
                });
            }).catch(() => {});
    };
    if (endpoint) endpoint.addEventListener('change', load);
    load();
}());
