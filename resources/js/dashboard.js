import ApexCharts from 'apexcharts';

/*
 * Gráficas del inicio. Colores y tipografía del sistema Tabler/UI (azul = marca; verde, azul claro y
 * amarillo para estado). Los datos llegan como JSON en #dashboard-data.
 */
const el = document.getElementById('dashboard-data');

if (el) {
    const data = JSON.parse(el.textContent);
    const css = getComputedStyle(document.documentElement);
    const font = getComputedStyle(document.body).fontFamily;
    const colors = { blue: '#066fd1', green: '#2fb344', azure: '#4299e1', yellow: '#f59f00', red: '#d63939', gray: '#a3a3a3' };
    const money = (v) => '$' + Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const day = (iso) => new Date(iso + 'T00:00:00').toLocaleDateString('es-DO', { day: '2-digit', month: 'short' });
    const base = {
        chart: { fontFamily: font, toolbar: { show: false }, animations: { enabled: false }, background: 'transparent' },
        grid: { borderColor: css.getPropertyValue('--border-color') || '#e5e5e5', strokeDashArray: 3 },
        dataLabels: { enabled: false },
        legend: { position: 'top', horizontalAlign: 'left', fontSize: '12px' },
        noData: { text: 'Sin datos en el periodo' },
    };

    const mount = (id, options) => {
        const node = document.getElementById(id);
        if (node) new ApexCharts(node, { ...base, ...options, chart: { ...base.chart, ...options.chart } }).render();
    };

    mount('chart-redeemed', {
        chart: { type: 'bar', height: 280 },
        series: [
            { name: 'Cobrado neto', data: data.redeemed },
            { name: 'Recargado', data: data.topups },
        ],
        colors: [colors.blue, colors.azure],
        plotOptions: { bar: { columnWidth: '55%', borderRadius: 2 } },
        xaxis: { categories: data.labels.map(day), labels: { rotate: -45, style: { fontSize: '11px' } } },
        yaxis: { labels: { formatter: (v) => money(v).replace('.00', '') } },
        tooltip: { y: { formatter: money } },
    });

    mount('chart-activated', {
        chart: { type: 'area', height: 280 },
        series: [{ name: 'Cupones activados', data: data.activated }],
        colors: [colors.green],
        stroke: { curve: 'straight', width: 2 },
        fill: { type: 'solid', opacity: 0.15 },
        xaxis: { categories: data.labels.map(day), labels: { rotate: -45, style: { fontSize: '11px' } } },
        yaxis: { labels: { formatter: (v) => Math.round(v).toLocaleString('en-US') } },
        tooltip: { y: { formatter: (v) => Number(v).toLocaleString('en-US') + ' cupones' } },
    });

    const total = data.status.values.reduce((a, b) => a + b, 0);
    mount('chart-status', {
        chart: { type: 'donut', height: 280 },
        series: total > 0 ? data.status.values : [],
        labels: data.status.labels,
        colors: [colors.gray, colors.azure, colors.green, colors.yellow, colors.red],
        legend: { position: 'bottom', fontSize: '12px' },
        plotOptions: { pie: { donut: { size: '68%', labels: { show: true, total: { show: true, label: 'Cupones', formatter: () => total.toLocaleString('en-US') } } } } },
        tooltip: { y: { formatter: (v) => Number(v).toLocaleString('en-US') } },
    });
}
