# Diseño del panel

El panel sigue el sistema de diseño **Tabler/UI** del proyecto (artefacto «Tabler/UI»): paneles densos, tipografía del sistema, neutros sin tinte y **un único acento azul**. No se carga ninguna fuente web.

## Dónde vive cada cosa
| Qué | Dónde |
|---|---|
| Tokens (color, radio, sombra) | `resources/css/app.css` (`:root`, valores OKLCH del sistema) y `tailwind.config.js` (escalas en hex para las utilidades) |
| Componentes (botón, tarjeta, tabla, badge, formulario, menú, alerta) | `resources/css/app.css` — clases `snow-*`, `hmenu*`, `dropdown-*`, `alert`, `page-header` |
| Menú horizontal | `resources/views/admin/partials/hmenu.blade.php` |
| Estructura de página | `resources/views/components/admin/layouts/app.blade.php` |

Los nombres de clase `snow-*` y `hope-*` se conservaron para no tocar cada vista; solo cambió su estilo.

## Reglas aplicadas
- **Color:** `blue` para enlaces, botón primario, foco y navegación activa; `green`/`yellow`/`red`/`azure` solo para estado. Texto blanco únicamente sobre azul y rojo.
- **Tipografía:** pila del sistema; cuerpo de 14px; títulos de página de 24px/600; títulos de tarjeta de 16px/500; cabeceras de tabla y rótulos (`subheader`) de 10px en mayúsculas con 0.04em; cifras de indicadores en 32px ligero.
- **Espaciado y forma:** radio de 6px (4px en controles pequeños); control de 36px de alto; rejilla de 1.5rem; tarjetas con borde de 1px + `shadow-sm`; contenedor máximo de 1320px.
- **Foco:** contorno azul de 2px con separación de 2px, nunca se quita.
- **Menú:** barra de 56px, máximo 6 elementos de primer nivel, desplegables al hacer clic (Esc o clic fuera para cerrar), subrayado azul de 2px en lo activo; menos de 992px se colapsa como acordeón. Un desplegable de 6 o más enlaces usa dos columnas y cada enlace lleva una descripción de una línea (`description` en `config/admin_screens.php`).
- **Texto:** frases cortas en minúscula inicial, sin emoji ni signos de exclamación; los botones empiezan con un verbo.
- **Tablas:** dentro de una tarjeta, sin relleno exterior; números a la derecha con cifras tabulares; estado con badge de una palabra.

## Pendiente
- Tema oscuro (`data-theme="dark"`): los tokens existen en el sistema pero el panel solo implementa el claro.
- Los correos (`resources/views/emails`) no se tocaron.
