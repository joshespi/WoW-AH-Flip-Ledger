// Alpine comes from Livewire (@livewireScripts in the layouts). Importing a second copy
// here would race it and break wire:* binding. Register Alpine components on 'alpine:init'
// instead, which Alpine dispatches before it walks the DOM.

import '@fontsource-variable/inter';
import '@fontsource-variable/jetbrains-mono';
document.addEventListener('alpine:init', () => {
    // Light / system / dark buttons. The theme logic itself (reading the saved choice, setting
    // <html class="dark">, following the OS setting) lives in partials/theme-init.blade.php as
    // window.theme, so it runs before first paint; this only stores the choice.
    window.Alpine.data('themeToggle', () => ({
        pref: window.theme.pref(),

        set(pref) {
            this.pref = pref;

            try {
                localStorage.setItem('theme', pref);
            } catch (e) {}

            window.theme.apply(pref);
        },
    }));

    // Shift-click a checkbox to tick or untick every checkbox between it and the last one
    // clicked. Boxes are marked data-range and bound to the Livewire array property `selected`,
    // which is set in one assignment (per-box change events race the server round trip and
    // can drop the clicked box).
    window.Alpine.data('rangeSelect', () => ({
        last: null,

        pick(event) {
            const boxes = [...this.$root.querySelectorAll('input[data-range]')];
            const index = boxes.indexOf(event.target);

            if (event.shiftKey && this.last !== null && this.last !== index) {
                window.getSelection()?.removeAllRanges();

                const ids = new Set(this.$wire.selected.map(String));

                boxes.slice(Math.min(index, this.last), Math.max(index, this.last) + 1).forEach((box) => {
                    event.target.checked ? ids.add(box.value) : ids.delete(box.value);
                });

                this.$wire.selected = [...ids];
            }

            this.last = index;
        },
    }));

    // Click-to-sort for server-rendered tables. Headers call sort(columnIndex, 'num' | 'text');
    // body cells carry data-v with the sort key (money cells render colored markup, so the
    // number lives in the attribute). Reorders the existing <tr>s, so no client-side formatting.
    window.Alpine.data('sortableTable', () => ({
        col: null,
        asc: false,

        sort(index, type) {
            // Same column flips direction; a new column starts descending (ascending for text).
            this.asc = this.col === index ? ! this.asc : type === 'text';
            this.col = index;

            const sign = this.asc ? 1 : -1;
            const keyed = [...this.$refs.body.children].map((row) => {
                const raw = row.children[index]?.dataset.v ?? '';
                return { row, key: raw === '' ? null : (type === 'num' ? Number(raw) : raw.toLowerCase()) };
            });

            // Rows with no value always sink to the bottom, whichever way it sorts.
            keyed.sort((a, b) => {
                if (a.key === null || b.key === null) return (a.key === null) - (b.key === null);
                return a.key < b.key ? -sign : (a.key > b.key ? sign : 0);
            });

            keyed.forEach(({ row }) => this.$refs.body.appendChild(row));
        },

        arrow(index) {
            return this.col === index ? (this.asc ? ' ▲' : ' ▼') : '';
        },

        // For aria-sort on the <th>, so screen readers announce the order.
        ariaSort(index) {
            return this.col === index ? (this.asc ? 'ascending' : 'descending') : 'none';
        },
    }));

    // Price chart for the item page. Chart.js is loaded on demand so other pages don't carry it.
    // `d` is built by ItemController: x is epoch seconds, y copper per unit.
    window.Alpine.data('itemChart', (d) => ({
        chart: null,

        async init() {
            const { default: Chart } = await import('chart.js/auto');
            const text = getComputedStyle(this.$el).color;
            const grid = 'rgba(128, 128, 128, 0.25)';
            const gold = (c) => c / 10000;
            const pts = (rows, y) => rows.map((r) => ({ x: r.ts * 1000, y: gold(y(r)) }));
            const line = (label, data, color, extra = {}) => ({
                label, data, borderColor: color, backgroundColor: color, borderWidth: 2, pointRadius: 0, tension: 0, ...extra,
            });

            const xs = d.daily.map((r) => r.ts * 1000);
            const span = xs.length ? [Math.min(...xs), Math.max(...xs)] : null;
            const flat = (v) => span ? span.map((x) => ({ x, y: gold(v) })) : [];

            const sets = [
                line(d.labels.median, pts(d.daily, (r) => r.median), '#6366f1'),
                line(d.labels.min, pts(d.daily, (r) => r.min), '#94a3b8', { borderWidth: 1 }),
            ];

            if (d.typical) sets.push(line(d.labels.typical, flat(d.typical), '#f59e0b', { borderDash: [6, 4], borderWidth: 1 }));
            if (d.hist60) sets.push(line(d.labels.hist60, flat(d.hist60), '#a855f7', { borderDash: [2, 4], borderWidth: 1 }));
            if (d.steps.length) sets.push(line(d.labels.avg, d.steps.filter((s) => s.avg !== null).map((s) => ({ x: s.ts * 1000, y: gold(s.avg) })), '#0ea5e9', { stepped: 'after', borderWidth: 1 }));

            sets.push({ type: 'scatter', label: d.labels.buys, data: pts(d.buys, (r) => r.unit), backgroundColor: '#2563eb', pointRadius: 4, pointStyle: 'triangle' });
            sets.push({ type: 'scatter', label: d.labels.sales, data: pts(d.sales, (r) => r.unit), backgroundColor: '#16a34a', pointRadius: 4, pointStyle: 'rectRot' });

            this.chart = new Chart(this.$refs.canvas, {
                type: 'line',
                data: { datasets: sets },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    parsing: false,
                    interaction: { mode: 'nearest', intersect: false },
                    scales: {
                        x: {
                            type: 'linear',
                            grid: { color: grid },
                            ticks: { color: text, maxTicksLimit: 8, callback: (v) => new Date(v).toLocaleDateString(undefined, { month: 'short', day: 'numeric' }) },
                        },
                        y: { grid: { color: grid }, ticks: { color: text, callback: (v) => v.toLocaleString() + 'g' } },
                    },
                    plugins: {
                        legend: { labels: { color: text, usePointStyle: true } },
                        tooltip: { callbacks: { label: (c) => `${c.dataset.label}: ${c.parsed.y.toLocaleString(undefined, { maximumFractionDigits: 2 })}g` } },
                    },
                },
            });
        },

        destroy() {
            this.chart?.destroy();
        },
    }));
});
