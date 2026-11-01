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
    // `d` is built by ItemController: x is epoch seconds, y copper per unit. The range buttons
    // only move the x window; Chart.js refits the y axis to what is visible.
    window.Alpine.data('itemChart', (d) => ({
        chart: null,
        range: 30,
        ranges: [7, 30, 90],

        async init() {
            const { default: Chart } = await import('chart.js/auto');
            const text = getComputedStyle(this.$el).color;
            const grid = 'rgba(128, 128, 128, 0.18)';
            const gold = (c) => c / 10000;
            const g = (v) => v.toLocaleString(undefined, { maximumFractionDigits: v < 10 ? 2 : 0 }) + 'g';
            const now = Date.now();
            const pts = (rows, y) => rows.map((r) => ({ x: r.ts * 1000, y: gold(y(r)), q: r.qty }));
            const line = (label, data, color, extra = {}) => ({
                label, data, borderColor: color, backgroundColor: color, borderWidth: 2, pointRadius: 0, pointHoverRadius: 4, hitRadius: 8, tension: 0, ...extra,
            });
            // Marker area follows the quantity, so a 500-unit sale reads bigger than a 5-unit one.
            const radius = (c) => 3 + Math.min(7, Math.sqrt(c.raw?.q ?? 1) / 2.5);
            // Reference lines are not hover targets.
            const ref = { hitRadius: 0, pointHoverRadius: 0 };
            const flat = (v) => [{ x: now - Math.max(...this.ranges) * 864e5, y: gold(v) }, { x: now, y: gold(v) }];

            const avg = d.steps.filter((s) => s.avg !== null).map((s) => ({ x: s.ts * 1000, y: gold(s.avg) }));
            // Runs to now while stock is held; ends at the last sale once the position is closed.
            const lastStep = d.steps[d.steps.length - 1];
            if (avg.length) avg.push({ x: lastStep.avg === null ? lastStep.ts * 1000 : now, y: avg[avg.length - 1].y });

            // Trailing 7-day mean of the daily medians; smooths the day-to-day noise.
            let sum = 0;
            const ma = d.daily.filter((r) => !r.live).map((r, i, a) => {
                sum += r.median - (i >= 7 ? a[i - 7].median : 0);
                return { x: r.ts * 1000, y: gold(sum / Math.min(i + 1, 7)) };
            });

            const sets = [
                line(d.labels.median, pts(d.daily, (r) => r.median), '#e5b64a', { borderWidth: 2.5 }),
                line(d.labels.ma, ma, '#fde68a', { borderWidth: 1.5, borderDash: [3, 3] }),
                line(d.labels.min, pts(d.daily, (r) => r.min), '#94a3b8', { borderWidth: 1, hidden: true }),
            ];

            if (d.typical) sets.push(line(d.labels.typical, flat(d.typical), '#94a3b8', { borderDash: [6, 4], borderWidth: 1, ...ref }));
            if (d.hist60) sets.push(line(d.labels.hist60, flat(d.hist60), '#64748b', { borderDash: [2, 4], borderWidth: 1, ...ref }));
            if (avg.length) sets.push(line(d.labels.avg, avg, '#38bdf8', { stepped: 'after', borderWidth: 1.5, borderDash: [4, 3], ...ref }));

            sets.push({ type: 'scatter', label: d.labels.buys, data: pts(d.buys, (r) => r.unit), backgroundColor: '#38bdf8', borderColor: '#0c4a6e', pointRadius: radius, pointHoverRadius: 10, pointStyle: 'triangle' });
            sets.push({ type: 'scatter', label: d.labels.sales, data: pts(d.sales, (r) => r.unit), backgroundColor: 'rgba(34, 197, 94, 0.75)', borderColor: '#14532d', pointRadius: radius, pointHoverRadius: 10, pointStyle: 'circle' });

            this.chart = new Chart(this.$refs.canvas, {
                type: 'line',
                data: { datasets: sets },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    parsing: false,
                    animation: false,
                    interaction: { mode: 'nearest', intersect: true },
                    scales: {
                        x: {
                            type: 'linear',
                            min: now - this.range * 864e5,
                            max: now,
                            grid: { color: grid },
                            ticks: { color: text, maxTicksLimit: 8, callback: (v) => new Date(v).toLocaleDateString(undefined, { month: 'short', day: 'numeric' }) },
                        },
                        y: { grace: '8%', grid: { color: grid }, ticks: { color: text, callback: (v) => g(v) } },
                    },
                    plugins: {
                        legend: { position: 'bottom', labels: { color: text, usePointStyle: true, boxWidth: 8 } },
                        tooltip: {
                            callbacks: {
                                title: (items) => new Date(items[0].parsed.x).toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }),
                                label: (c) => `${c.dataset.label}: ${g(c.parsed.y)}` + (c.raw?.q ? ` × ${c.raw.q.toLocaleString()}` : ''),
                            },
                        },
                    },
                },
            });
        },

        setRange(days) {
            this.range = days;
            const x = this.chart.options.scales.x;
            x.min = Date.now() - days * 864e5;
            x.max = Date.now();
            this.chart.update();
        },

        destroy() {
            this.chart?.destroy();
        },
    }));
});
