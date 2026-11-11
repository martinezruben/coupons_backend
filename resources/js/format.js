// Mismo formato que App\Support\Format: $13,095.50 (miles con coma, decimales con punto)
const moneyFormat = new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export function money(value) {
    const n = Number(value) || 0;
    return (n < 0 ? '-$' : '$') + moneyFormat.format(Math.abs(n));
}
