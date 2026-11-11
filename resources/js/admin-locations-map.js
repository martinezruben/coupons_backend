import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import '../css/admin-locations-map.css';

// Estado operativo de la localidad: color + glifo (no depender solo del color) y etiqueta
const STATUSES = {
    online: { fill: '#16a34a', glyph: '✓', label: 'En línea' },
    stale: { fill: '#d97706', glyph: '!', label: 'Sin sincronizar' },
    contingency: { fill: '#dc2626', glyph: '▲', label: 'En contingencia' },
    inactive: { fill: '#94a3b8', glyph: '–', label: 'Inactiva' },
};
const REFRESH_MS = 60_000;

function statusOf(pin) {
    return STATUSES[pin.status] ?? STATUSES.inactive;
}

function escapeHtml(s) {
    const d = document.createElement('div');
    d.textContent = s;

    return d.innerHTML;
}

function pinSvg(fill) {
    return `<svg viewBox="0 0 40 48" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
  <path d="M20 0C11.16 0 4 7.16 4 16c0 11 16 32 16 32s16-21 16-32C36 7.16 28.84 0 20 0z" fill="${fill}"/>
  <circle cx="20" cy="15" r="5.5" fill="white" fill-opacity="0.95"/>
</svg>`;
}

function makeMarkerIcon(p) {
    const st = statusOf(p);
    const n = Math.max(0, Math.min(999, parseInt(p.activeDevices, 10) || 0));
    const label = n > 99 ? '99+' : String(n);

    return L.divIcon({
        className: 'admin-loc-marker',
        html: `<div class="admin-loc-marker__pin" title="${escapeHtml(p.name ?? '')} · ${escapeHtml(p.statusLabel ?? st.label)}">${pinSvg(st.fill)}<span class="admin-loc-marker__count">${label}</span><span class="admin-loc-marker__badge" style="background:${st.fill}" aria-hidden="true">${st.glyph}</span></div>`,
        iconSize: [40, 44],
        iconAnchor: [20, 44],
        popupAnchor: [0, -42],
    });
}

function popupHtml(p, periodKey) {
    const st = statusOf(p);
    const name = escapeHtml(p.name ?? '—');
    const inPeriod = p.syncInPeriod?.[periodKey] === true ? 'Sí' : 'No';
    const rows = [
        `<p class="mt-0.5"><span class="font-semibold" style="color:${st.fill}">${st.glyph} ${escapeHtml(p.statusLabel ?? st.label)}</span></p>`,
        `<p class="mt-1 text-slate-500">Dispositivos activos: <span class="font-semibold text-slate-700">${parseInt(p.enabledDevices, 10) || 0}</span>${p.staleDevices > 0 ? ` · <span class="font-semibold text-amber-700">${p.staleDevices} sin sincronizar</span>` : ''}</p>`,
        `<p class="text-slate-500">Última sincronización: <span class="font-semibold text-slate-700">${escapeHtml(p.lastSyncHuman ?? 'nunca')}</span></p>`,
        `<p class="text-slate-500">Sync exitosa en el periodo: <span class="font-semibold text-slate-700">${inPeriod}</span></p>`,
    ];
    if (p.contingencySinceHuman) {
        rows.push(`<p class="text-red-700">En contingencia desde ${escapeHtml(p.contingencySinceHuman)}</p>`);
    }
    if (p.editUrl) {
        rows.push(`<p class="mt-1.5"><a class="font-semibold text-primary-600 hover:text-primary-700" href="${escapeHtml(p.editUrl)}">Editar localidad</a></p>`);
    }

    return `<div class="text-[11px] text-slate-800"><p class="font-semibold">${name}</p>${rows.join('')}</div>`;
}

function initLocationsMap() {
    const mount = document.getElementById('admin-locations-map');
    const dataEl = document.getElementById('admin-locations-map-data');
    const periodSelect = document.getElementById('admin-locations-map-period');
    if (!mount || !dataEl?.textContent) {
        return;
    }

    let pins;
    try {
        pins = JSON.parse(dataEl.textContent);
    } catch {
        return;
    }

    if (!Array.isArray(pins)) {
        return;
    }

    const map = L.map(mount, {
        zoomControl: true,
        scrollWheelZoom: true,
        attributionControl: true,
    });

    // Tiles estándar de OSM: máxima disponibilidad (CARTO suele estar bloqueado
    // por firewalls corporativos / adblockers en redes locales)
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution:
            '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
        maxZoom: 19,
    }).addTo(map);

    const valid = pins.filter(
        (p) =>
            p.lat != null &&
            p.lng != null &&
            !Number.isNaN(Number(p.lat)) &&
            !Number.isNaN(Number(p.lng)),
    );

    const latlngs = [];
    /** @type {{ marker: L.Marker; pin: object }[]} */
    const markerEntries = [];
    const hidden = new Set();
    const currentPeriod = () => periodSelect?.value || 'today';

    const updateCounts = () => {
        const counts = {};
        pins.forEach((p) => {
            counts[p.status] = (counts[p.status] || 0) + 1;
        });
        document.querySelectorAll('[data-map-count]').forEach((el) => {
            el.textContent = String(counts[el.dataset.mapCount] || 0);
        });
    };

    const applyVisibility = () => {
        markerEntries.forEach(({ marker, pin }) => {
            if (hidden.has(pin.status)) {
                map.removeLayer(marker);
            } else if (!map.hasLayer(marker)) {
                marker.addTo(map);
            }
        });
    };

    const updateList = (pin) => {
        const row = document.querySelector(`[data-loc-id="${CSS.escape(pin.id)}"]`);
        if (!row) {
            return;
        }
        const st = statusOf(pin);
        const label = row.querySelector('[data-loc-status-label]');
        if (label) {
            label.textContent = `${st.glyph} ${pin.statusLabel ?? st.label}`;
            label.style.color = st.fill;
        }
        const last = row.querySelector('[data-loc-last-sync]');
        if (last) {
            last.textContent = pin.lastSyncHuman ?? 'nunca';
        }
    };

    const refreshMarker = ({ marker, pin }) => {
        marker.setIcon(makeMarkerIcon(pin));
        marker.setPopupContent(popupHtml(pin, currentPeriod()));
        updateList(pin);
    };

    valid.forEach((p) => {
        const lat = Number(p.lat);
        const lng = Number(p.lng);
        const marker = L.marker([lat, lng], { icon: makeMarkerIcon(p) }).addTo(map);
        latlngs.push([lat, lng]);
        markerEntries.push({ marker, pin: p });
        marker.bindPopup(popupHtml(p, currentPeriod()));
    });

    updateCounts();

    // Filtro por estado desde la leyenda (botones con aria-pressed)
    document.querySelectorAll('[data-map-filter]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const status = btn.dataset.mapFilter;
            const nowHidden = !hidden.has(status);
            if (nowHidden) {
                hidden.add(status);
            } else {
                hidden.delete(status);
            }
            btn.setAttribute('aria-pressed', nowHidden ? 'false' : 'true');
            btn.classList.toggle('opacity-50', nowHidden);
            applyVisibility();
        });
    });

    if (periodSelect) {
        periodSelect.addEventListener('change', () => {
            markerEntries.forEach((entry) => entry.marker.setPopupContent(popupHtml(entry.pin, currentPeriod())));
        });
    }

    // Estado en vivo: se vuelve a pedir cada minuto (con los mismos filtros de la tabla)
    const statusUrl = mount.dataset.statusUrl;
    const refresh = async () => {
        if (!statusUrl || document.hidden) {
            return;
        }
        try {
            const res = await fetch(`${statusUrl}${window.location.search}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            if (!res.ok) {
                return;
            }
            const fresh = await res.json();
            const byId = new Map(fresh.map((p) => [p.id, p]));
            markerEntries.forEach((entry) => {
                const next = byId.get(entry.pin.id);
                if (next) {
                    entry.pin = next;
                    refreshMarker(entry);
                }
            });
            pins.splice(0, pins.length, ...fresh);
            updateCounts();
            applyVisibility();
        } catch {
            // Sin red momentánea: se conserva el último estado mostrado
        }
    };
    window.setInterval(refresh, REFRESH_MS);

    if (latlngs.length > 0) {
        map.fitBounds(latlngs, { padding: [36, 36], maxZoom: 14 });
    } else {
        map.setView([4.65, -74.05], 5);
    }

    setTimeout(() => {
        map.invalidateSize();
    }, 0);
    window.addEventListener('resize', () => {
        map.invalidateSize();
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initLocationsMap);
} else {
    initLocationsMap();
}
