import './bootstrap';

import Alpine from 'alpinejs';

window.Alpine = Alpine;

document.addEventListener('alpine:init', () => {
    // Cascada de selects: los selects con data-depends solo muestran opciones
    // cuyo data-parent coincida con el valor del select padre; al cambiar el
    // padre se limpia la selección del hijo. Funciona en filtros de grid y form.
    Alpine.data('selectCascade', () => ({
        init() {
            const root = this.$el;
            const byId = (id) => root.querySelector('#' + CSS.escape(id));

            root.querySelectorAll('select[data-depends]').forEach((child) => {
                const parent = byId(child.dataset.depends);
                if (!parent || parent.tagName !== 'SELECT') {
                    return;
                }
                const apply = () => {
                    const val = parent.value;
                    [...child.options].forEach((opt) => {
                        if (opt.dataset.parent === undefined) {
                            return;
                        }
                        const show = val === '' || opt.dataset.parent === val;
                        opt.hidden = !show;
                        if (!show && opt.selected) {
                            opt.selected = false;
                        }
                    });
                };
                parent.addEventListener('change', apply);
                apply();
            });
        },
    }));

    Alpine.data('locationPairingToken', () => ({
        open: false,
        loading: false,
        code: '',
        expiresAt: null,
        locationId: null,
        locationName: '',
        remainingLabel: '15:00',
        _interval: null,

        csrfToken() {
            return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
        },

        pairingUrl() {
            if (!this.locationId) {
                return '';
            }

            return `/admin/locations/${encodeURIComponent(this.locationId)}/pairing-token`;
        },

        openFor(detail) {
            if (!detail?.locationId) {
                return;
            }
            this.locationId = detail.locationId;
            this.locationName = typeof detail.locationName === 'string' ? detail.locationName : '';
            this.openModal();
        },

        async openModal() {
            this.open = true;
            this.loading = true;
            try {
                await this.fetchStatus();
                if (!this.code) {
                    await this.postAction('ensure');
                }
                this.startTimer();
            } finally {
                this.loading = false;
            }
        },

        closeModal() {
            this.open = false;
            this.stopTimer();
        },

        async fetchStatus() {
            const res = await fetch(this.pairingUrl(), {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            });
            const data = await res.json();
            this.applyPayload(data);
        },

        applyPayload(data) {
            this.code = data.code ? String(data.code) : '';
            this.expiresAt = data.expires_at != null ? Number(data.expires_at) : null;
            this.tick();
        },

        async postAction(action) {
            const res = await fetch(this.pairingUrl(), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': this.csrfToken(),
                },
                credentials: 'same-origin',
                body: JSON.stringify({ action }),
            });
            if (!res.ok) {
                throw new Error('Pairing request failed');
            }
            const data = await res.json();
            this.applyPayload(data);
        },

        async regenerate() {
            this.loading = true;
            try {
                await this.postAction('regenerate');
                this.startTimer();
            } finally {
                this.loading = false;
            }
        },

        startTimer() {
            this.stopTimer();
            this._interval = window.setInterval(() => this.tick(), 1000);
            this.tick();
        },

        stopTimer() {
            if (this._interval != null) {
                window.clearInterval(this._interval);
                this._interval = null;
            }
        },

        tick() {
            if (!this.expiresAt) {
                this.remainingLabel = '—';
                return;
            }
            const endMs = this.expiresAt * 1000;
            const sec = Math.max(0, Math.floor((endMs - Date.now()) / 1000));
            if (sec === 0) {
                this.code = '';
                this.expiresAt = null;
                this.remainingLabel = 'Caducado';
                this.stopTimer();
                return;
            }
            const m = Math.floor(sec / 60);
            const s = sec % 60;
            this.remainingLabel = `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
        },
    }));

    Alpine.data('auditLogDetail', (detailBaseUrl = '') => ({
        detailBaseUrl: typeof detailBaseUrl === 'string' ? detailBaseUrl : '',
        open: false,
        loading: false,
        error: '',
        row: null,

        actionLabel(action) {
            const labels = {
                created: 'Creación',
                updated: 'Edición',
                deleted: 'Eliminación',
                login: 'Login',
                logout: 'Logout',
            };

            return labels[action] ?? action;
        },

        changeEntries(changes) {
            if (changes == null || typeof changes !== 'object') {
                return [];
            }

            return Object.entries(changes).map(([field, pair]) => ({
                field,
                before: Array.isArray(pair) ? String(pair[0] ?? '—') : '—',
                after: Array.isArray(pair) ? String(pair[1] ?? '—') : '—',
            }));
        },

        async openDetail(id) {
            this.open = true;
            this.loading = true;
            this.error = '';
            this.row = null;
            const base = this.detailBaseUrl.replace(/\/$/, '');
            try {
                const res = await fetch(`${base}/${encodeURIComponent(id)}`, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });
                if (!res.ok) {
                    this.error = 'No se pudo cargar el detalle.';

                    return;
                }
                this.row = await res.json();
            } catch {
                this.error = 'Error de red.';
            } finally {
                this.loading = false;
            }
        },

        closeDetail() {
            this.open = false;
            this.row = null;
            this.error = '';
        },
    }));

    Alpine.data('apiRequestLogDetail', (detailBaseUrl = '') => ({
        detailBaseUrl: typeof detailBaseUrl === 'string' ? detailBaseUrl : '',
        open: false,
        loading: false,
        error: '',
        row: null,

        jsonBlock(formatted, raw) {
            const hasFormatted =
                formatted !== null &&
                formatted !== undefined &&
                String(formatted).length > 0;
            const s = hasFormatted ? formatted : raw ?? '';
            const t = s === null || s === undefined ? '' : String(s);

            return t === '' ? '(vacío)' : t;
        },

        async openDetail(id) {
            this.open = true;
            this.loading = true;
            this.error = '';
            this.row = null;
            const base = this.detailBaseUrl.replace(/\/$/, '');
            try {
                const res = await fetch(`${base}/${encodeURIComponent(id)}`, {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                });
                if (!res.ok) {
                    this.error =
                        res.status === 403
                            ? 'No autorizado.'
                            : res.status === 404
                              ? 'Registro no encontrado.'
                              : `Error ${res.status}`;

                    return;
                }
                this.row = await res.json();
            } catch {
                this.error = 'No se pudo cargar el detalle.';
            } finally {
                this.loading = false;
            }
        },

        closeDetail() {
            this.open = false;
            this.row = null;
            this.error = '';
        },
    }));

    Alpine.data('transactionLineItemsDetail', (baseUrl = '') => ({
        baseUrl: typeof baseUrl === 'string' ? baseUrl : '',
        open: false,
        loading: false,
        error: '',
        detail: null,

        async openDetail(id) {
            this.open = true;
            this.loading = true;
            this.error = '';
            this.detail = null;
            const base = this.baseUrl.replace(/\/$/, '');
            try {
                const res = await fetch(`${base}/${encodeURIComponent(id)}/line-items`, {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                });
                if (!res.ok) {
                    this.error =
                        res.status === 403
                            ? 'No autorizado.'
                            : res.status === 404
                              ? 'Registro no encontrado.'
                              : `Error ${res.status}`;

                    return;
                }
                this.detail = await res.json();
            } catch {
                this.error = 'No se pudo cargar el detalle.';
            } finally {
                this.loading = false;
            }
        },

        closeDetail() {
            this.open = false;
            this.detail = null;
            this.error = '';
        },
    }));

    Alpine.data('transactionExcelModal', (locations = []) => {
        const localYmd = (d) => {
            const y = d.getFullYear();
            const m = String(d.getMonth() + 1).padStart(2, '0');
            const day = String(d.getDate()).padStart(2, '0');

            return `${y}-${m}-${day}`;
        };

        return {
            open: false,
            dateFrom: '',
            dateTo: '',
            locationId: '',
            error: '',
            loading: false,
            locations: Array.isArray(locations) ? locations : [],

            init() {
                const t = new Date();
                this.dateTo = localYmd(t);
                const f = new Date(t);
                f.setDate(f.getDate() - 7);
                this.dateFrom = localYmd(f);
            },

            csrfToken() {
                return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
            },

            async submit() {
                this.error = '';
                this.loading = true;
                try {
                    const res = await fetch('/admin/transactions/excel/export', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            Accept: 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': this.csrfToken(),
                        },
                        credentials: 'same-origin',
                        body: JSON.stringify({
                            date_from: this.dateFrom,
                            date_to: this.dateTo,
                            location_id: this.locationId || null,
                        }),
                    });

                    if (res.status === 422) {
                        const data = await res.json();
                        const fromErrors = data.errors ? Object.values(data.errors).flat() : [];
                        this.error =
                            (typeof data.message === 'string' && data.message && data.message !== 'The given data was invalid.')
                                ? data.message
                                : fromErrors[0] ?? 'Datos no válidos.';

                        return;
                    }

                    if (!res.ok) {
                        this.error = 'No se pudo generar el archivo.';

                        return;
                    }

                    const blob = await res.blob();
                    const url = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    const cd = res.headers.get('Content-Disposition');
                    const m = cd && /filename="?([^";\n]+)"?/i.exec(cd);
                    a.download = m ? m[1].trim() : 'transacciones.xlsx';
                    document.body.appendChild(a);
                    a.click();
                    a.remove();
                    URL.revokeObjectURL(url);
                    this.open = false;
                } catch {
                    this.error = 'Error de red.';
                } finally {
                    this.loading = false;
                }
            },
        };
    });

    Alpine.data('transactionsReportModal', (locations = []) => ({
        open: false,
        dateFrom: '',
        dateTo: '',
        locationId: '',
        includeDetail: false,
        error: '',
        loading: false,
        locations: Array.isArray(locations) ? locations : [],

        csrfToken() {
            return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
        },

        async submit() {
            this.error = '';
            this.loading = true;
            try {
                const res = await fetch('/admin/transactions/report/export', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': this.csrfToken(),
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        date_from: this.dateFrom || null,
                        date_to: this.dateTo || null,
                        location_id: this.locationId || null,
                        include_detail: this.includeDetail,
                    }),
                });

                if (res.status === 422) {
                    const data = await res.json();
                    const fromErrors = data.errors ? Object.values(data.errors).flat() : [];
                    this.error =
                        (typeof data.message === 'string' && data.message && data.message !== 'The given data was invalid.')
                            ? data.message
                            : fromErrors[0] ?? 'Datos no válidos.';

                    return;
                }

                if (!res.ok) {
                    this.error = 'No se pudo generar el archivo.';

                    return;
                }

                const blob = await res.blob();
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                const cd = res.headers.get('Content-Disposition');
                const m = cd && /filename="?([^";\n]+)"?/i.exec(cd);
                a.download = m ? m[1].trim() : 'reporte-transacciones.xlsx';
                document.body.appendChild(a);
                a.click();
                a.remove();
                URL.revokeObjectURL(url);
                this.open = false;
            } catch {
                this.error = 'Error de red.';
            } finally {
                this.loading = false;
            }
        },
    }));
});

Alpine.start();
