<x-admin.layouts.app :title="'Inicio'">
    <div class="snow-card mx-auto mt-6 max-w-lg rounded-xl border border-slate-200/90 bg-white p-6 text-center shadow-hope-card">
        <h2 class="text-sm font-semibold text-slate-900">Bienvenido, {{ auth('admin')->user()->name }}</h2>
        <p class="mt-2 text-[11px] leading-relaxed text-slate-500">
            Tu rol no tiene acceso a los indicadores del dashboard. Usa el menú lateral para ir a las pantallas que tienes habilitadas.
        </p>
    </div>
</x-admin.layouts.app>
