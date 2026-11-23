/* Portal BUMK UMGO – chart helper (Chart.js lokal) */
(function () {
    function parse(el, key, fallback) {
        try { return JSON.parse(el.dataset[key]); } catch (e) { return fallback; }
    }
    function hexToRgba(hex, alpha) {
        var h = (hex || '#0B4F3E').replace('#', '');
        if (h.length === 3) h = h[0] + h[0] + h[1] + h[1] + h[2] + h[2];
        var n = parseInt(h, 16);
        return 'rgba(' + ((n >> 16) & 255) + ',' + ((n >> 8) & 255) + ',' + (n & 255) + ',' + alpha + ')';
    }
    function build(el) {
        if (typeof Chart === 'undefined') return;
        var type = el.dataset.chart || 'bar';
        var labels = parse(el, 'labels', []);
        var raw = parse(el, 'datasets', []);
        var datasets = raw.map(function (d) {
            var color = d.color || '#0B4F3E';
            if (type === 'line') {
                return {
                    label: d.label || 'Data', data: d.data || [],
                    borderColor: color, backgroundColor: hexToRgba(color, 0.14),
                    borderWidth: 2, pointBackgroundColor: color,
                    pointRadius: 3, pointHoverRadius: 5, tension: 0.35, fill: d.fill !== false
                };
            }
            if (type === 'doughnut' || type === 'pie') {
                return {
                    label: d.label || 'Data', data: d.data || [],
                    backgroundColor: d.data && d.data.map ? d.data.map(function (_, i) {
                        return (raw[i] && raw[i].color) || ['#0B4F3E', '#10B981', '#0284C7', '#0E7C5A', '#92400E', '#94A3B8'][i % 6];
                    }) : [],
                    borderColor: '#fff', borderWidth: 2, hoverOffset: 6
                };
            }
            return {
                label: d.label || 'Data', data: d.data || [],
                backgroundColor: (d.colors && d.colors.length) ? d.colors : color,
                borderRadius: 6, borderSkipped: false, maxBarThickness: 26
            };
        });
        if (!datasets.length) datasets = [{
            label: 'Data', data: parse(el, 'values', []),
            backgroundColor: el.dataset.color || '#0B4F3E', borderRadius: 6, maxBarThickness: 26
        }];
        var isDonut = type === 'doughnut' || type === 'pie';
        new Chart(el.getContext('2d'), {
            type: type,
            data: { labels: labels, datasets: datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: isDonut || datasets.length > 1, position: 'bottom', labels: { boxWidth: 10, usePointStyle: true, font: { size: 11, family: 'Inter, Segoe UI, sans-serif' }, color: '#475569' } },
                    tooltip: { backgroundColor: '#1B2A26', padding: 10, cornerRadius: 8, displayColors: true, titleFont: { family: 'Inter, Segoe UI, sans-serif' }, bodyFont: { family: 'Inter, Segoe UI, sans-serif' } }
                },
                scales: isDonut ? {} : {
                    x: { grid: { display: false }, ticks: { font: { size: 11, family: 'Inter, Segoe UI, sans-serif' }, color: '#64748B' } },
                    y: { beginAtZero: true, grid: { color: 'rgba(15,23,42,.055)' }, ticks: { font: { size: 11, family: 'Inter, Segoe UI, sans-serif' }, color: '#64748B', callback: function (v) { return v >= 1000 ? (v / 1000) + 'rb' : v; } } }
                }
            }
        });
    }
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('canvas[data-chart]').forEach(build);
    });
})();
