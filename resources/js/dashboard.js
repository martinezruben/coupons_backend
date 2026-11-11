import { money } from './format';

// Cantidad de etiquetas del eje X según el ancho: en móvil no se amontonan las 30 fechas
function tickAmountFor(el, count) {
    // Descuenta el espacio de los ejes Y (~120px) y deja ~85px por fecha
    const plotWidth = Math.max(160, (el.clientWidth || 320) - 120);
    const byWidth = Math.max(2, Math.floor(plotWidth / 85));
    return Math.max(1, Math.min(count - 1, byWidth));
}

function readPayload() {
    const el = document.getElementById('dashboard-chart-data');
    if (!el?.textContent) {
        return null;
    }
    try {
        return JSON.parse(el.textContent);
    } catch {
        return null;
    }
}

function sliceTrend(full, period) {
    const { labels, sales, transactions } = full;
    if (period === '7d') {
        const n = Math.min(7, labels.length);

        return {
            labels: labels.slice(-n),
            sales: sales.slice(-n),
            transactions: transactions.slice(-n),
        };
    }

    return { labels, sales, transactions };
}

function mountSalesArea(ApexCharts, el, initial, onPeriodChange) {
    const fmtMoney = (v) => money(v);

    const chart = new ApexCharts(el, {
        chart: {
            type: 'area',
            height: 320,
            toolbar: { show: false },
            zoom: { enabled: false },
            fontFamily: 'Inter, ui-sans-serif, system-ui, sans-serif',
            animations: { enabled: true, easing: 'easeinout', speed: 450 },
        },
        dataLabels: { enabled: false },
        stroke: {
            curve: 'smooth',
            width: [3, 2],
        },
        fill: {
            type: 'gradient',
            gradient: {
                shadeIntensity: 1,
                opacityFrom: 0.5,
                opacityTo: 0.04,
                stops: [0, 92, 100],
            },
        },
        colors: ['#2563eb', '#22d3ee'],
        series: [
            { name: 'Ventas', data: initial.sales },
            { name: 'Transacciones', data: initial.transactions, yAxisIndex: 1 },
        ],
        xaxis: {
            categories: initial.labels,
            tickAmount: tickAmountFor(el, initial.labels.length),
            labels: {
                style: { colors: '#64748b', fontSize: '11px' },
                rotate: 0,
                hideOverlappingLabels: true,
            },
            axisBorder: { show: false },
            axisTicks: { show: false },
        },
        yaxis: [
            {
                title: { text: 'Ventas ($)', style: { color: '#64748b', fontSize: '11px' } },
                labels: {
                    style: { colors: '#64748b', fontSize: '11px' },
                    formatter: fmtMoney,
                },
            },
            {
                opposite: true,
                title: { text: 'Tickets', style: { color: '#64748b', fontSize: '11px' } },
                labels: {
                    style: { colors: '#64748b', fontSize: '11px' },
                    formatter: (v) => `${Math.round(v)}`,
                },
            },
        ],
        grid: {
            borderColor: '#e2e8f0',
            strokeDashArray: 4,
            padding: { left: 8, right: 8 },
        },
        legend: {
            position: 'top',
            horizontalAlign: 'right',
            fontSize: '11px',
            markers: { width: 8, height: 8, radius: 2 },
        },
        tooltip: {
            shared: true,
            theme: 'light',
            y: [
                { formatter: (v) => (v != null ? fmtMoney(v) : '') },
                { formatter: (v) => (v != null ? `${Math.round(v)} tickets` : '') },
            ],
        },
    });

    chart.render();

    const select = document.getElementById('dash-sales-period');
    if (select) {
        select.addEventListener('change', () => {
            const period = select.value;
            const t = onPeriodChange(period);
            chart.updateOptions({
                xaxis: { categories: t.labels, tickAmount: tickAmountFor(el, t.labels.length) },
                series: [
                    { name: 'Ventas', data: t.sales },
                    { name: 'Transacciones', data: t.transactions, yAxisIndex: 1 },
                ],
            });
        });
    }

    return chart;
}

function mountSalesHeatmap(ApexCharts, el, heatmap) {
    const metricEl = document.getElementById('dash-heatmap-metric');
    const periodEl = document.getElementById('dash-heatmap-period');

    // ApexCharts dibuja la primera serie abajo: se invierte para que el lunes quede arriba
    const buildSeries = (data, metric) => data.days
        .map((day, i) => ({
            name: day,
            data: data.hours.map((h, j) => ({ x: h, y: data[metric][i][j] })),
        }))
        .reverse();

    const current = () => heatmap[periodEl?.value === '90d' ? '90d' : '30d'];
    const metric = () => (metricEl?.value === 'tickets' ? 'tickets' : 'sales');
    const fmt = (v) => (metric() === 'tickets' ? `${Math.round(v)} transacciones` : money(v));

    const chart = new ApexCharts(el, {
        chart: {
            type: 'heatmap',
            height: 300,
            toolbar: { show: false },
            fontFamily: 'Inter, ui-sans-serif, system-ui, sans-serif',
        },
        series: buildSeries(current(), metric()),
        // Una sola tinta (la del acento): más oscuro = más ventas
        colors: ['#1e4b8d'],
        dataLabels: { enabled: false },
        stroke: { width: 2, colors: ['#fbfcfe'] },
        plotOptions: {
            heatmap: { radius: 3, enableShades: true, shadeIntensity: 0.85, useFillColorAsStroke: false },
        },
        xaxis: {
            type: 'category',
            labels: { style: { colors: '#64748b', fontSize: '11px' }, rotate: 0 },
            title: { text: 'Hora del día', style: { color: '#64748b', fontSize: '11px' } },
            tooltip: { enabled: false },
        },
        yaxis: { labels: { style: { colors: '#64748b', fontSize: '11px' } } },
        grid: { padding: { left: 4, right: 8 } },
        legend: { show: false },
        tooltip: {
            theme: 'light',
            y: { formatter: (v) => fmt(v) },
        },
        noData: { text: 'Sin ventas en este periodo', style: { color: '#64748b', fontSize: '12px' } },
    });
    chart.render();

    const refresh = () => chart.updateSeries(buildSeries(current(), metric()));
    metricEl?.addEventListener('change', refresh);
    periodEl?.addEventListener('change', refresh);

    return chart;
}

function mountFamilyDonut(ApexCharts, el, familyMix, palette) {
    const empty = !familyMix?.labels?.length;

    const baseOptions = (height) => ({
        chart: {
            type: 'donut',
            height,
            fontFamily: 'Inter, ui-sans-serif, system-ui, sans-serif',
        },
        labels: empty ? ['Sin datos'] : familyMix.labels,
        series: empty ? [1] : familyMix.series,
        colors: empty
            ? ['#e2e8f0']
            : (palette ?? ['#2563eb', '#22d3ee', '#38bdf8', '#0ea5e9', '#6366f1', '#8b5cf6']),
        plotOptions: {
            pie: {
                donut: {
                    size: '68%',
                    labels: {
                        show: true,
                        name: { fontSize: '11px' },
                        value: {
                            fontSize: '16px',
                            fontWeight: 600,
                            formatter: (v) => (empty ? '—' : money(parseFloat(v))),
                        },
                        total: {
                            show: !empty,
                            label: 'Total',
                            formatter: (w) => {
                                const sum = w.globals.seriesTotals.reduce((a, b) => a + b, 0);

                                return money(sum);
                            },
                        },
                    },
                },
            },
        },
        dataLabels: { enabled: false },
        legend: {
            position: 'bottom',
            fontSize: '11px',
            itemMargin: { vertical: 2 },
            offsetY: 2,
        },
        tooltip: {
            y: {
                formatter: (v) => money(v),
            },
        },
        states: {
            hover: { filter: { type: 'lighten', value: 0.08 } },
        },
    });

    const measureHeight = () => {
        const h = el.clientHeight;
        if (h > 0) {
            return Math.max(160, Math.min(270, Math.floor(h)));
        }

        return 250;
    };

    let chart = new ApexCharts(el, baseOptions(measureHeight()));
    chart.render();

    const resize = () => {
        const nh = measureHeight();
        if (nh > 0 && chart) {
            chart.updateOptions({ chart: { height: nh } }, false, true);
        }
    };

    requestAnimationFrame(() => {
        resize();
    });

    if (typeof ResizeObserver !== 'undefined') {
        const ro = new ResizeObserver(() => resize());
        ro.observe(el);
        el._familyDonutResizeObserver = ro;
    } else {
        window.addEventListener('resize', resize);
    }

    return chart;
}

function mountSyncStacked(ApexCharts, el, syncByDay) {
    const success = (syncByDay?.success ?? []).map((v) => Number(v) || 0);
    const failed = (syncByDay?.failed ?? []).map((v) => Number(v) || 0);
    const categories = syncByDay?.categories ?? [];
    const n = Math.max(success.length, failed.length, categories.length);
    while (success.length < n) {
        success.push(0);
    }
    while (failed.length < n) {
        failed.push(0);
    }

    const maxStack = success.reduce((m, v, i) => Math.max(m, v + (failed[i] ?? 0)), 0);
    const yMax = maxStack === 0 ? 5 : undefined;

    const chart = new ApexCharts(el, {
        chart: {
            type: 'bar',
            height: 300,
            stacked: true,
            toolbar: { show: false },
            fontFamily: 'Inter, ui-sans-serif, system-ui, sans-serif',
            animations: { enabled: true },
        },
        plotOptions: {
            bar: {
                horizontal: false,
                columnWidth: '58%',
                borderRadius: 2,
                dataLabels: { position: 'center' },
            },
        },
        dataLabels: { enabled: false },
        colors: ['#2563eb', '#f43f5e'],
        series: [
            { name: 'Correctas', data: success },
            { name: 'Fallidas', data: failed },
        ],
        xaxis: {
            type: 'category',
            categories: categories.length ? categories : Array.from({ length: n }, (_, i) => String(i + 1)),
            tickAmount: tickAmountFor(el, n),
            labels: {
                style: { colors: '#64748b', fontSize: '11px' },
                rotate: -35,
                rotateAlways: n > 7,
                hideOverlappingLabels: true,
            },
            axisBorder: { show: false },
            axisTicks: { show: false },
        },
        yaxis: {
            min: 0,
            max: yMax,
            forceNiceScale: true,
            labels: {
                style: { colors: '#64748b', fontSize: '11px' },
                formatter: (v) => `${Math.round(v)}`,
            },
        },
        grid: { borderColor: '#e2e8f0', strokeDashArray: 4, padding: { top: 0, right: 8, bottom: 0, left: 4 } },
        legend: { position: 'top', horizontalAlign: 'right', fontSize: '11px' },
        tooltip: { shared: true, theme: 'light', intersect: false },
        noData: {
            text: 'Sin sincronizaciones en este periodo',
            align: 'center',
            verticalAlign: 'middle',
            style: { color: '#64748b', fontSize: '12px' },
        },
        states: {
            hover: { filter: { type: 'darken', value: 0.08 } },
        },
    });
    chart.render();

    return chart;
}

async function init() {
    // Cada dashboard (comercial / técnico) trae solo los gráficos que muestra
    const data = readPayload();
    if (!data) {
        return;
    }

    const { default: ApexCharts } = await import('apexcharts');

    const salesEl = document.querySelector('[data-chart="sales-area"]');
    if (salesEl && data.salesTrend) {
        const full = data.salesTrend;
        const periodEl = document.getElementById('dash-sales-period');
        const initial = sliceTrend(full, periodEl?.value === '7d' ? '7d' : '30d');
        mountSalesArea(ApexCharts, salesEl, initial, (period) => sliceTrend(full, period));
    }

    const heatmapEl = document.querySelector('[data-chart="sales-heatmap"]');
    if (heatmapEl && data.heatmap) {
        mountSalesHeatmap(ApexCharts, heatmapEl, data.heatmap);
    }

    const donutEl = document.querySelector('[data-chart="family-donut"]');
    if (donutEl && data.familyMix) {
        mountFamilyDonut(ApexCharts, donutEl, data.familyMix);
    }

    const paymentEl = document.querySelector('[data-chart="payment-donut"]');
    if (paymentEl && data.paymentMix) {
        mountFamilyDonut(ApexCharts, paymentEl, data.paymentMix, ['#059669', '#0ea5e9', '#8b5cf6', '#f59e0b']);
    }

    const syncEl = document.querySelector('[data-chart="sync-stacked"]');
    if (syncEl && data.syncByDay) {
        mountSyncStacked(ApexCharts, syncEl, data.syncByDay);
    }
}

function boot() {
    void init();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
} else {
    boot();
}
