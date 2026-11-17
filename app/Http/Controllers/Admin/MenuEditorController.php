<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Support\AdminRbac;
use App\Support\MenuIcons;
use App\Support\MenuStructure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Editor del menú del panel (solo super-admin): grupos, opciones, orden, nombres, iconos, visibilidad y enlaces propios. */
class MenuEditorController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorizeSuper($request);

        $groups = collect(MenuStructure::load())->map(function (array $g) {
            return array_merge($g, ['items' => collect($g['items'])->map(function (array $i) {
                $meta = $i['kind'] === 'link' ? ['label' => '', 'icon' => 'link'] : MenuStructure::meta($i['kind'], $i['ref']);

                return $i + [
                    'defaultLabel' => $meta['label'], 'defaultIcon' => $meta['icon'],
                    'locked' => in_array($i['ref'], MenuStructure::LOCKED, true),
                ];
            })->values()->all()]);
        })->values()->all();

        return view('admin.menu.index', [
            'groups' => $groups,
            'icons' => MenuIcons::svgMap(collect($groups)->pluck('items')->flatten(1)->flatMap(fn ($i) => [$i['defaultIcon'], $i['icon']])->all()),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $admin = $this->authorizeSuper($request);

        $payload = json_decode((string) $request->input('payload'), true);
        if (! is_array($payload) || ! isset($payload['groups']) || ! is_array($payload['groups'])) {
            throw ValidationException::withMessages(['payload' => 'No se pudo leer el menú enviado. Recarga la página e inténtalo otra vez.']);
        }

        $before = $this->summary(MenuStructure::load());
        $groups = $this->normalize($payload['groups']);
        MenuStructure::saveStructure($groups);

        AdminAuditLog::record('updated', 'Menu', null, ['estructura' => [$before, $this->summary($groups)]], $admin, ['summary' => 'Actualizó el menú del panel']);

        return redirect()->route('admin.menu.index')->with('status', 'Menú actualizado.');
    }

    public function reset(Request $request): RedirectResponse
    {
        $admin = $this->authorizeSuper($request);
        $before = $this->summary(MenuStructure::load());
        MenuStructure::saveStructure(MenuStructure::defaults());
        AdminAuditLog::record('updated', 'Menu', null, ['estructura' => [$before, $this->summary(MenuStructure::defaults())]], $admin, ['summary' => 'Restableció el menú del panel al original']);

        return redirect()->route('admin.menu.index')->with('status', 'Menú restablecido al original.');
    }

    /**
     * Valida y normaliza lo enviado: toda pantalla y acceso aparece una sola vez, el editor no se oculta
     * y los enlaces propios solo apuntan a rutas del sitio o a https.
     *
     * @return list<array>
     */
    private function normalize(array $rawGroups): array
    {
        $errors = [];
        $groups = [];
        $seen = [];
        $keys = [];

        if ($rawGroups === [] || count($rawGroups) > 20) {
            throw ValidationException::withMessages(['payload' => 'El menú necesita entre 1 y 20 grupos.']);
        }

        foreach (array_values($rawGroups) as $gi => $g) {
            $label = trim((string) ($g['label'] ?? ''));
            if ($label === '' || mb_strlen($label) > 60) {
                $errors["groups.$gi.label"] = 'Cada grupo necesita un nombre (hasta 60 caracteres).';
            }
            $icon = $g['icon'] ?? 'squares-2x2';
            if (! MenuIcons::valid($icon)) {
                $icon = 'squares-2x2';
            }
            $key = Str::slug((string) ($g['key'] ?? '')) ?: Str::slug($label) ?: 'grupo';
            $base = $key;
            for ($n = 2; in_array($key, $keys, true); $n++) {
                $key = $base.'-'.$n;
            }
            $keys[] = $key;

            $items = [];
            foreach (array_values((array) ($g['items'] ?? [])) as $ii => $i) {
                $kind = $i['kind'] ?? '';
                $ref = $i['ref'] ?? null;
                $itemLabel = trim((string) ($i['label'] ?? ''));
                $itemIcon = MenuIcons::valid($i['icon'] ?? null) ? $i['icon'] : null;
                $row = ['kind' => $kind, 'ref' => null, 'label' => $itemLabel !== '' ? mb_substr($itemLabel, 0, 60) : null, 'icon' => $itemIcon,
                    'url' => null, 'permission' => null, 'new_tab' => false, 'visible' => (bool) ($i['visible'] ?? true)];

                if ($kind === 'screen' || $kind === 'special') {
                    $known = $kind === 'screen' ? MenuStructure::navigableScreen((string) $ref) : isset(MenuStructure::SPECIALS[$ref]);
                    if (! $known) {
                        $errors["groups.$gi.items.$ii"] = "La opción «{$ref}» no existe.";

                        continue;
                    }
                    $id = "$kind:$ref";
                    if (isset($seen[$id])) {
                        $errors["groups.$gi.items.$ii"] = 'Una opción no puede estar dos veces en el menú.';

                        continue;
                    }
                    $seen[$id] = true;
                    $row['ref'] = $ref;
                    if (in_array($ref, MenuStructure::LOCKED, true)) {
                        $row['visible'] = true; // el editor no se oculta
                    }
                } elseif ($kind === 'link') {
                    $url = trim((string) ($i['url'] ?? ''));
                    $validUrl = preg_match('#^/(?!/)[^\s]*$#', $url) || preg_match('#^https://[^\s]+$#i', $url);
                    if ($itemLabel === '') {
                        $errors["groups.$gi.items.$ii.label"] = 'Un enlace necesita un nombre.';
                    }
                    if (! $validUrl || mb_strlen($url) > 500) {
                        $errors["groups.$gi.items.$ii.url"] = 'La dirección debe empezar con «/» (una página de este sitio) o con «https://».';
                    }
                    $permission = trim((string) ($i['permission'] ?? ''));
                    if ($permission !== '' && ! preg_match('/^[a-z0-9_.\-]{1,100}$/i', $permission)) {
                        $errors["groups.$gi.items.$ii.permission"] = 'El permiso solo puede llevar letras, números, punto, guion y guion bajo.';
                    }
                    $row['url'] = $url;
                    $row['permission'] = $permission !== '' ? $permission : null;
                    $row['new_tab'] = (bool) ($i['new_tab'] ?? false);
                    $row['icon'] = $itemIcon ?? 'link';
                } else {
                    $errors["groups.$gi.items.$ii"] = 'Tipo de opción desconocido.';

                    continue;
                }
                $items[] = $row;
            }

            $groups[] = ['key' => $key, 'label' => $label, 'icon' => $icon, 'visible' => (bool) ($g['visible'] ?? true), 'items' => $items];
        }

        $missing = array_diff(MenuStructure::requiredRefs(), array_keys($seen));
        if ($missing !== []) {
            $errors['payload'] = 'Faltan opciones del menú: '.implode(', ', array_map(fn ($m) => explode(':', $m)[1], array_slice($missing, 0, 5))).'. Muévelas a un grupo en vez de quitarlas.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $groups;
    }

    /** Resumen legible para la auditoría. */
    private function summary(array $groups): string
    {
        return collect($groups)->map(fn ($g) => $g['label'].($g['visible'] ? '' : ' (oculto)').': '.collect($g['items'])->map(fn ($i) => ($i['label'] ?: ($i['ref'] ?? '?')).($i['visible'] ? '' : ' (oculta)'))->implode(', '))->implode(' | ');
    }

    private function authorizeSuper(Request $request)
    {
        $admin = $request->user('admin');
        abort_unless($admin && AdminRbac::isSuperAdmin($admin), 403);

        return $admin;
    }
}
