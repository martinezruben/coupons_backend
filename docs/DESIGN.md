# Diseño: «Panel claro» (rediseño vigente)

Desde noviembre de 2026 el sitio usa el rediseño **Panel claro** (guía y pantallas en el archivo de diseño entregado):

- **Color:** verde bosque `#0F3D33` (botones principales, menú activo), lima `#D4F76B` (acentos, avatar, cifras sobre verde), fondo `#F6F7F2`, superficies blancas, tinta `#10241E`, gris verdoso `#5B6B63`. Estados: verde `#0B6B3A` sobre `#E3F4E8`, ámbar `#8A4B00` sobre `#FFF0D1`, rojo `#A3261B` sobre `#FDE7E4`.
- **Tipografía** (autoalojada en `public/fonts`): Bricolage Grotesque para títulos, Figtree para texto, JetBrains Mono para códigos y etiquetas (`.eyebrow`).
- **Forma:** botones y etiquetas en píldora, tarjetas de 20 px, campos de 46 px con radio 14 px.
- **Motivo:** el cupón perforado — líneas punteadas y muescas (`.perf`, `.perfv`, `.ticket`) — en el saldo de Inicio, el reporte de lotes, el detalle de lote y de cupón, las tarjetas de Localidades, los paneles de rol y de usuario, el login y el logo.
- **Navegación:** barra horizontal con píldoras y desplegables al pasar el cursor; el grupo actual va en verde bosque con un punto lima.
- Los tokens viven en `resources/css/app.css` (variables `:root`) y `tailwind.config.js` (escalas `primary`, `slate`, `green`, `red`, `amber`, `lime`); las vistas siguen usando las mismas clases (`snow-*`), ya restyladas.

*A continuación, el documento del diseño anterior (Tabler/UI), conservado como referencia histórica.*

---

# Diseño del panel

El panel sigue el sistema de diseño **Tabler/UI** del proyecto (artefacto «Tabler/UI»): paneles densos, tipografía del sistema, neutros sin tinte y **un único acento azul**. No se carga ninguna fuente web.

## Dónde vive cada cosa
| Qué | Dónde |
|---|---|
| Tokens (color, radio, sombra) | `resources/css/app.css` (`:root`, valores OKLCH del sistema) y `tailwind.config.js` (escalas en hex para las utilidades) |
| Componentes (botón, tarjeta, tabla, badge, formulario, menú, alerta) | `resources/css/app.css` — clases `snow-*`, `sidenav*`, `topbar`, `dropdown-*`, `alert`, `page-header` |
| Navegación horizontal | `resources/views/admin/partials/navbar.blade.php` (grupos en `config/admin_nav_groups.php`) |
| Estructura de página | `resources/views/components/admin/layouts/app.blade.php` |

Los nombres de clase `snow-*` y `hope-*` se conservaron para no tocar cada vista; solo cambió su estilo.

## Reglas aplicadas
- **Color:** `blue` para enlaces, botón primario, foco y navegación activa; `green`/`yellow`/`red`/`azure` solo para estado. Texto blanco únicamente sobre azul y rojo.
- **Tipografía:** pila del sistema; cuerpo de 14px; títulos de página de 24px/600; títulos de tarjeta de 16px/500; cabeceras de tabla y rótulos (`subheader`) de 10px en mayúsculas con 0.04em; cifras de indicadores en 32px ligero.
- **Espaciado y forma:** radio de 6px (4px en controles pequeños); control de 36px de alto; rejilla de 1.5rem; tarjetas con borde de 1px + `shadow-sm`; contenedor máximo de 1320px.
- **Ancho:** todo el contenido usa el ancho del layout (`max-w-[1320px]` del `<main>`); ninguna página fija un ancho propio. Solo se limitan campos sueltos (p. ej. un selector), nunca la tarjeta o la página.
- **Foco:** contorno azul de 2px con separación de 2px, nunca se quita.
- **Navegación:** barra horizontal superior (navbar de Tabler/UI) con marca, Inicio y un desplegable por sección (Clientes, Cupones, Reportes, Infraestructura, Configuración), buscador y cuenta a la derecha; menos de 1024px se pliega en un panel apilado. Solo aparece lo que el usuario puede ver. Colores y componentes, los de Tabler/UI.
- **Texto:** frases cortas en minúscula inicial, sin emoji ni signos de exclamación; los botones empiezan con un verbo.
- **Tablas:** dentro de una tarjeta, sin relleno exterior; números a la derecha con cifras tabulares; estado con badge de una palabra.

## Pendiente
- Tema oscuro (`data-theme="dark"`): los tokens existen en el sistema pero el panel solo implementa el claro.
- Los correos (`resources/views/emails`) no se tocaron.
