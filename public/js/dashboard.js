(() => {
    'use strict';

    const search = document.getElementById('dashboard-search');
    const statusFilter = document.getElementById('dashboard-status-filter');
    const sort = document.getElementById('dashboard-sort');
    const resultCount = document.getElementById('dashboard-result-count');
    const noResults = document.getElementById('dashboard-no-results');
    const liveRegion = document.getElementById('dashboard-live-region');

    if (!search || !statusFilter || !sort || !resultCount || !noResults) {
        return;
    }

    const locale = resultCount.dataset.locale || document.documentElement.lang || 'ru';
    const countTemplate = resultCount.dataset.countTemplate || '__COUNT__';
    const noUpdates = resultCount.dataset.noUpdates || '—';
    const relativeTime = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' });
    const statusLabels = Object.fromEntries(
        Array.from(statusFilter.options)
            .filter((option) => option.value !== 'all')
            .map((option) => [option.value, option.textContent.trim()])
    );
    const statusOrder = {
        critical: 0,
        offline: 1,
        warning: 2,
        online: 3
    };

    const items = () => Array.from(document.querySelectorAll('[data-server-item]'));
    const groups = () => Array.from(document.querySelectorAll('[data-dashboard-group]'));

    function normalizedSearch() {
        return search.value.trim().toLocaleLowerCase(locale);
    }

    function compareItems(left, right) {
        if (sort.value === 'status') {
            const statusDifference =
                statusOrder[left.dataset.serverStatus] -
                statusOrder[right.dataset.serverStatus];
            if (statusDifference !== 0) {
                return statusDifference;
            }
        }
        if (sort.value === 'updated') {
            const updateDifference =
                Number(right.dataset.lastUpdate) -
                Number(left.dataset.lastUpdate);
            if (updateDifference !== 0) {
                return updateDifference;
            }
        }

        return left.dataset.serverName.localeCompare(
            right.dataset.serverName,
            locale,
            { sensitivity: 'base', numeric: true }
        );
    }

    function formatCount(count) {
        return countTemplate.replace('__COUNT__', String(count));
    }

    function applyView() {
        const query = normalizedSearch();
        const selectedStatus = statusFilter.value;
        let visibleTotal = 0;

        groups().forEach((group) => {
            const grid = group.querySelector('[data-server-grid]');
            const count = group.querySelector('[data-group-count]');
            if (!grid || !count) {
                return;
            }

            const groupItems = Array.from(
                grid.querySelectorAll('[data-server-item]')
            ).sort(compareItems);
            let visibleInGroup = 0;
            groupItems.forEach((item) => {
                grid.appendChild(item);
                const matchesQuery =
                    query === '' || item.dataset.serverName.includes(query);
                const matchesStatus =
                    selectedStatus === 'all' ||
                    item.dataset.serverStatus === selectedStatus;
                item.hidden = !(matchesQuery && matchesStatus);
                if (!item.hidden) {
                    visibleInGroup += 1;
                }
            });

            count.textContent = String(visibleInGroup);
            group.hidden = visibleInGroup === 0;
            visibleTotal += visibleInGroup;
        });

        const label = formatCount(visibleTotal);
        resultCount.textContent = label;
        noResults.hidden = visibleTotal !== 0;
        if (liveRegion) {
            liveRegion.textContent = label;
        }
    }

    function formatRelativeTime(seconds) {
        if (!Number.isFinite(seconds) || seconds < 0) {
            return noUpdates;
        }
        if (seconds < 60) {
            return relativeTime.format(-Math.floor(seconds), 'second');
        }
        if (seconds < 3600) {
            return relativeTime.format(-Math.floor(seconds / 60), 'minute');
        }
        if (seconds < 86400) {
            return relativeTime.format(-Math.floor(seconds / 3600), 'hour');
        }

        return relativeTime.format(-Math.floor(seconds / 86400), 'day');
    }

    function refreshRelativeTimes() {
        const now = Math.floor(Date.now() / 1000);
        items().forEach((item) => {
            const serverId = item.dataset.serverId;
            const target = document.getElementById(`updated-at-${serverId}`);
            const timestamp = Number(item.dataset.lastUpdate);
            if (target) {
                target.textContent = timestamp > 0
                    ? formatRelativeTime(Math.max(0, now - timestamp))
                    : noUpdates;
            }
        });
    }

    function updateMetric(serverId, name, metric) {
        const target = document.getElementById(`${name}-val-${serverId}`);
        if (!target) {
            return;
        }
        target.textContent = metric && Number.isFinite(Number(metric.value))
            ? window.MirvMon.formatMetricValue(metric.value, metric.unit || '%')
            : '—';
    }

    function updateStatus(server) {
        const item = document.querySelector(
            `[data-server-item][data-server-id="${server.id}"]`
        );
        const card = document.getElementById(`server-card-${server.id}`);
        const label = document.getElementById(`status-label-${server.id}`);
        if (!item || !card || !label || !statusLabels[server.status]) {
            return;
        }

        item.dataset.serverStatus = server.status;
        item.dataset.lastUpdate = server.last_metrics_at
            ? String(Math.floor(Date.parse(server.last_metrics_at) / 1000))
            : '0';
        Object.keys(statusLabels).forEach((status) => {
            card.classList.toggle(
                `server-status-${status}`,
                status === server.status
            );
        });
        label.textContent = statusLabels[server.status];

        updateMetric(server.id, 'cpu', server.metrics.cpu_load);
        updateMetric(server.id, 'ram', server.metrics.ram_used);
        updateMetric(server.id, 'disk', server.metrics.disk);

        const alertCount = document.getElementById(`alert-count-${server.id}`);
        if (alertCount) {
            const value = alertCount.querySelector('span:not(.visually-hidden)');
            if (value) {
                value.textContent = String(server.active_alerts);
            }
            alertCount.classList.toggle('d-none', server.active_alerts === 0);
        }
    }

    function updateSummary() {
        const counts = {
            total: 0,
            online: 0,
            warning: 0,
            critical: 0,
            offline: 0
        };
        items().forEach((item) => {
            counts.total += 1;
            if (Object.hasOwn(counts, item.dataset.serverStatus)) {
                counts[item.dataset.serverStatus] += 1;
            }
        });
        Object.entries(counts).forEach(([status, count]) => {
            const target = document.querySelector(`[data-summary="${status}"]`);
            if (target) {
                target.textContent = String(count);
            }
        });
    }

    async function refreshDashboard() {
        if (document.visibilityState !== 'visible') {
            return;
        }
        try {
            const response = await fetch('/api/dashboard/stats', {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin'
            });
            if (!response.ok) {
                return;
            }
            const servers = await response.json();
            if (!Array.isArray(servers)) {
                return;
            }
            servers.forEach(updateStatus);
            updateSummary();
            refreshRelativeTimes();
            applyView();
        } catch {
            // The next interval retries. Existing server state remains visible.
        }
    }

    document.querySelectorAll('[data-group-toggle]').forEach((button) => {
        const groupKey = button.dataset.groupToggle;
        const panel = document.getElementById(button.getAttribute('aria-controls'));
        if (!panel) {
            return;
        }
        const storageKey = `mirvmon.dashboard.group.${groupKey}`;
        const collapsed = localStorage.getItem(storageKey) === 'collapsed';
        button.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        panel.hidden = collapsed;

        button.addEventListener('click', () => {
            const expanded = button.getAttribute('aria-expanded') === 'true';
            button.setAttribute('aria-expanded', expanded ? 'false' : 'true');
            panel.hidden = expanded;
            localStorage.setItem(
                storageKey,
                expanded ? 'collapsed' : 'expanded'
            );
        });
    });

    search.addEventListener('input', applyView);
    statusFilter.addEventListener('change', applyView);
    sort.addEventListener('change', applyView);
    refreshRelativeTimes();
    applyView();
    window.setInterval(refreshRelativeTimes, 30000);
    window.setInterval(refreshDashboard, 30000);
})();
