# Diseño del panel

El panel sigue el sistema de diseño **Tabler/UI** del proyecto (artefacto «Tabler/UI»): paneles densos, tipografía del sistema, neutros sin tinte y **un único acento azul**. No se carga ninguna fuente web.

## Dónde vive cada cosa
| Qué | Dónde |
|---|---|
| Tokens (color, radio, sombra) | `resources/css/app.css` (`:root`, valores OKLCH del sistema) y `tailwind.config.js` (escalas en hex para las utilidades) |
| Componentes (botón, tarjeta, tabla, badge, formulario, menú, alerta) | `resources/css/app.css` — clases `snow-*`, `sidenav*`, `topbar`, `dropdown-*`, `alert`, `page-header` |
| Navegación lateral | `resources/views/admin/partials/sidebar.blade.php` (grupos en `config/admin_nav_groups.php`) |
| Estructura de página | `resources/views/components/admin/layouts/app.blade.php` |

Los nombres de clase `snow-*` y `hope-*` se conservaron para no tocar cada vista; solo cambió su estilo.

## Reglas aplicadas
- **Color:** `blue` para enlaces, botón primario, foco y navegación activa; `green`/`yellow`/`red`/`azure` solo para estado. Texto blanco únicamente sobre azul y rojo.
- **Tipografía:** pila del sistema; cuerpo de 14px; títulos de página de 24px/600; títulos de tarjeta de 16px/500; cabeceras de tabla y rótulos (`subheader`) de 10px en mayúsculas con 0.04em; cifras de indicadores en 32px ligero.
- **Espaciado y forma:** radio de 6px (4px en controles pequeños); control de 36px de alto; rejilla de 1.5rem; tarjetas con borde de 1px + `shadow-sm`; contenedor máximo de 1320px.
- **Ancho:** todo el contenido usa el ancho del layout (`max-w-[1320px]` del `<main>`); ninguna página fija un ancho propio. Solo se limitan campos sueltos (p. ej. un selector), nunca la tarjeta o la página.
- **Foco:** contorno azul de 2px con separación de 2px, nunca se quita.
- **Navegación:** barra lateral fija de 15rem (Tabler/UI admite barra lateral o superior) con Inicio y secciones plegables (Clientes, Cupones, Reportes, Infraestructura, Configuración), buscador y cuenta en la barra superior; menos de 992px la barra pasa a un panel deslizable. La estructura sigue la referencia de módulos del proyecto; los colores y componentes, los de Tabler/UI.
- **Texto:** frases cortas en minúscula inicial, sin emoji ni signos de exclamación; los botones empiezan con un verbo.
- **Tablas:** dentro de una tarjeta, sin relleno exterior; números a la derecha con cifras tabulares; estado con badge de una palabra.

## Pendiente
- Tema oscuro (`data-theme="dark"`): los tokens existen en el sistema pero el panel solo implementa el claro.
- Los correos (`resources/views/emails`) no se tocaron.
