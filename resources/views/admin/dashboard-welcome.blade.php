<x-admin.layouts.app :title="'Inicio'">
    <div class="mx-auto mt-6 max-w-3xl">
        <h2 class="text-sm font-semibold text-slate-900">Bienvenido, {{ auth('admin')->user()->name }}</h2>
        <p class="mt-1 text-[11px] leading-relaxed text-slate-500">
            Panel de administración de Coupify. Usa el menú lateral para gestionar usuarios, roles y la infraestructura.
        </p>

        <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
            @foreach ($stats as $stat)
                <div class="snow-card rounded-xl border border-slate-200/90 bg-white p-4 shadow-hope-card">
                    <div class="text-[11px] text-slate-500">{{ $stat['label'] }}</div>
                    <div class="mt-1 text-xl font-semibold text-slate-900">{{ $stat['value'] }}</div>
                </div>
            @endforeach
        </div>
    </div>
</x-admin.layouts.app>
