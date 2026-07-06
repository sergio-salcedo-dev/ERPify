---
name: 'Experiencia de acceso / identidad — delta visual'
status: final
updated: 2026-07-06
inherits: ../../../../pwa/DESIGN.md
scope: 'Landing (puntos de entrada) + superficies de auth/identidad (login, recuperación, aceptación de invitación, estados de cuenta, emails de seguridad)'
note: >
  Delta sobre el design system vigente (pwa/DESIGN.md = autoridad visual). Define solo los tokens,
  variantes y componentes NUEVOS del eje de acceso; todo lo demás se hereda. Donde un composite del
  sistema ya sirve, se reutiliza tal cual y no se redefine aquí. En conflicto entre cualquier mock y
  esta espina, gana la espina.
components:
  access-wall:
    surface: '{color.bg-elevated}'
    icon: '{color.text-subtle}'
    heading: '{color.text}'
    body: '{color.text-muted}'
    border: '{color.border}'
    radius: '{radius.lg}'
  token-action-screen:
    surface: '{color.bg-elevated}'
    field-radius: '{radius.md}'
    reveal-toggle: '{color.text-subtle}'
    radius: '{radius.lg}'
  connectivity-button:
    background: '{color.brand}'
    foreground: '{color.text-on-accent}'
    spinner: '{color.text-on-accent}'
    radius: '{radius.md}'
  offline-notice:
    surface: '{color.bg-subtle}'
    accent: '{color.warning}'
    text: '{color.warning-strong}'
    radius: '{radius.md}'
  security-signal:
    dot: '{color.success}'
    text: '{color.success-strong}'
    heading: '{color.text}'
    next-step: '{color.text-muted}'
    radius: '{radius.md}'
  security-email:
    # Email clients no resuelven variables CSS ni webfonts: literales inline con paridad de rama.
    surface: '#ffffff'
    surface-dark: '#11151f'
    text: '#08090a'
    text-dark: '#e7eaf3'
    link: '#2f5cd9'
    link-dark: '#6c9bff'
    button-bg: '{color.brand}'
    button-text: '{color.text-on-accent}'
    button-radius: '{radius.md}'
    border: '{color.border}'
---

# Experiencia de acceso / identidad — DESIGN (delta)

> **Hereda** todo de [`pwa/DESIGN.md`](../../../../pwa/DESIGN.md): rampas de color, tipografía (Geist /
> Geist Mono), escala de espaciado, radios, densidad, elevación mode-aware, motion (`prefers-reduced-motion`
> al nivel de token), accesibilidad AA y dark mode, más los composites `<FormField>`, `<ProblemDisplay>`,
> `<MutationError>`, `<AsyncBoundary>`, `<EmptyState>`, `<StatusBadge>`, `<CorrelationIdChip>`, `<CopyButton>`,
> `<Logo>`, `<ThemeToggle>` y las variantes de botón (Brand / Ghost / Subtle / Icon / Pill / Destructive).
> Reutiliza el shell `AuthLayout` (tarjeta centrada `min-h-dvh`, `max-w-sm`, `noindex`, `<Logo>` sobre la
> tarjeta) tal cual. Este documento añade **solo** lo específico del eje de acceso. En conflicto entre
> cualquier mock y esta espina, gana la espina.

## Brand & Style (delta)

El eje de acceso es una **superficie pública de confianza, no de operación**: sobria, serena, más *banca*
que *marketing*. La seguridad se comunica **sin dramatismo** — nada de rojos de alarma, iconos de peligro ni
lenguaje acusatorio; un estado suspendido o un enlace muerto son hechos neutros, no errores del usuario. La
calma **es** la señal de que el sistema controla la situación.

Coexisten **dos lenguajes visuales a propósito** (ver `pwa/CLAUDE.md`): la **landing / marketing**
(raw-palette + `tw-animate-css`, en `app/_components/`) y la **tarjeta de auth token-driven** (Shadcn +
`@/components/erpify`). Este delta gobierna **la tarjeta de auth, los muros de estado y los emails de
seguridad**; de la landing solo toca sus **puntos de entrada** (el CTA «Iniciar sesión» del `Navbar`), que se
resuelven en el lenguaje marketing y no se redefinen aquí. Las superficies de auth son `noindex` y de baja
densidad — la única excepción deliberada a la regla «density is a feature» del back-office: se leen una vez,
a menudo con prisa y una sola mano.

## Colors (delta)

**Ninguna hue nueva.** El eje reutiliza la rampa neutra, el azul de marca interactivo y `{color.security}`.
Deltas de aplicación:

| Uso nuevo                                   | Token (heredado)                                          | Regla                                                                                                              |
| ------------------------------------------- | -------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------- |
| Muro de estado (`AccessWall`)               | icono `{color.text-subtle}` · texto `{color.text-muted}` | Neutro. **Nunca** `{color.danger}` para suspendido / bloqueo temporal / enlace no válido: no son errores.        |
| Aviso sin conexión (`OfflineNotice`)        | acento `{color.warning}` · texto `{color.warning-strong}` | Condición transitoria recuperable, no fallo. Semántico-como-texto ⇒ `-strong` (AA).                              |
| Confirmación de seguridad (`SecuritySignal`) | dot `{color.success}` · texto `{color.success-strong}`   | Éxito calmado. Dot = shade gráfica; el texto legible va a `-strong` (AA).                                         |
| Error real de la propia UI                  | `{color.danger}` vía `<ProblemDisplay>` / `<MutationError>` | Reservado a fallos técnicos (red, 5xx, validación), **no** a estados de identidad ni a la muerte de un token.  |
| Eje de seguridad (acento parco)             | `{color.security}` (`#7589ad`, igual en claro y oscuro)  | Matiz opcional para señalar «esto es seguridad» sin alarmar; nunca como único canal.                             |

> **Por qué `SUSPENDED`/`LockedUntil` no son rojos.** Son **estados de ciclo de vida / condición transitoria**,
> no severidad. Teñirlos de `{color.danger}` mentiría sobre su naturaleza (una cuenta en pausa no es un error)
> y elevaría la ansiedad del usuario justo cuando necesita instrucciones claras. El neutro + el copy resuelven.

## Typography (delta)

Hereda la escala y las tres pesas (400 / 500 / 600). Reglas propias:

- **Tamaño cómodo, no compacto.** Al contrario que el back-office denso (`--text-base` 14 px por defecto),
  las superficies de acceso usan el paso cómodo `--text-md` (16 px) para el body y **Heading 2 / Heading 1**
  para el título de la tarjeta: se leen una vez y con prisa; la legibilidad gana a la densidad.
- **Sans siempre** para copy de auth. **Geist Mono** solo aparecería en un identificador técnico (p. ej. el
  `correlation-id` de un `<ProblemDisplay>`); **el token del enlace nunca se renderiza** (opacidad total del token).
- **Email = pila de sistema.** Los clientes de correo no cargan webfonts de forma fiable: `SecurityEmail`
  degrada a `-apple-system, system-ui, "Segoe UI", Roboto, sans-serif` en vez de `{font.sans}`. Es la única
  superficie donde la familia se abandona conscientemente. Por el mismo motivo tampoco resuelven variables CSS
  de color: el enlace del email usa un **par literal inline claro/oscuro** (`#2f5cd9` / `#6c9bff`) para
  cumplir AA en ambas ramas — la excepción sancionada, mismo razonamiento ya dado para `surface`/`text`.

## Layout & Spacing (delta)

- **Tarjeta de auth = `AuthLayout` heredado** sin cambios: `min-h-dvh`, centrada, `max-w-sm`, `px-4 py-10`,
  `<Logo href={HOME}>` sobre una tarjeta `{radius.lg}` con borde `{color.border}` y `shadow-sm`. `AccessWall`,
  `TokenActionScreen` y `SecuritySignal` **viven dentro de este shell** — no son layouts nuevos.
- **Ergonomía de una mano (móvil, crítico).** Campos grandes (altura cómoda ≥ 44 px táctil), acción primaria
  **al fondo del flujo, dentro del alcance del pulgar**; el teclado en pantalla **nunca** debe tapar el botón
  primario (botón bajo los campos + `scroll-into-view` al enfocar). Orden de tabulación natural, autofocus en
  el primer campo, **retorno del foco al primer campo inválido** tras error.
- **`TokenActionScreen`:** un solo campo de contraseña visible con toggle de visibilidad, ayuda de reglas
  breve, `ConnectivityButton` al pie, `OfflineNotice` intercalado sobre el botón cuando aplica.
- **`AccessWall`:** columna centrada — icono neutro · título · una-dos líneas de copy · **pila de acciones**
  (primaria «Iniciar sesión» + secundarias). Sin ruido, sin ilustración decorativa.
- **Email:** una sola columna, ~600 px máx., interlineado generoso, **un** enlace/botón prominente (área de
  toque grande en móvil), pie legal mínimo. Sin hero, sin banners, sin rejilla multi-columna.

## Elevation & Depth (delta)

Heredada. La tarjeta y los muros comparten la elevación de card (nivel 2: borde + `shadow-sm` en claro,
superficie subtle en oscuro). **El email es plano**: los clientes de correo descartan sombras — la jerarquía
la dan el borde `{color.border}` y el espaciado, nunca `box-shadow`.

## Shapes (delta)

Heredados. Tarjeta y muro `{radius.lg}`; campos y botones `{radius.md}`; el botón del email usa `{radius.md}`
inline (email exige estilos «bulletproof», no clases utilitarias). Sin radios nuevos.

## Components (nuevos — contrato de nombres compartido con EXPERIENCE.md)

Los seis componentes se nombran igual en ambas espinas. Los de pantalla (`AccessWall`, `TokenActionScreen`,
`SecuritySignal`) son **UI de la capacidad de acceso** y se componen sobre primitivos heredados; los de
resiliencia (`ConnectivityButton`, `OfflineNotice`) son candidatos a graduar a `@/components/erpify` si un
segundo consumidor aparece (Regla de Tres). `SecurityEmail` no es un componente React de la app: es el
**contrato visual** de las plantillas de correo.

### `AccessWall`

Pantalla-muro dentro de `AuthLayout` para cualquier proyección de estado a superficie pública: enlace no
válido · cuenta suspendida · bloqueo temporal · sesión expirada · cuenta desactivada. **No es un error** → no
usa `<ProblemDisplay>`; comparte el registro honesto y no-culpabilizador de `<EmptyState variant="permission-denied">`.

- **Anatomía:** icono neutro (`lucide`, `aria-hidden`, `{color.text-subtle}`) · título (Heading 2) · copy
  sereno (`{color.text-muted}`) · **pila de acciones**.
- **Acciones (siempre presentes):** **siempre** ofrece «Iniciar sesión» (botón Brand) más secundarias genéricas
  *«Solicitar nueva invitación»* / *«Contactar con tu administrador»* (Ghost). La acción se ofrece siempre,
  no revela el estado.
- **Variantes** (`invalid-link` · `suspended` · `locked` · `session-expired` · `deactivated`): cambian **solo
  el copy**, no la paleta. Todos los tokens no elegibles (caducado / revocado / usado / ya aceptado /
  eliminado) muestran **exactamente** el muro `invalid-link` con el mismo mensaje opaco *«Este enlace ya no es
  válido»* — nunca el motivo (opacidad total del token). Los muros post-identidad (`suspended`, `locked`) pueden dar copy algo más
  específico y orientado a resolver, pero **igual de calmado** (nunca rojo, nunca alarma).
- **Estados:** estático (sin carga). **Dark mode** heredado; paridad total.

### `TokenActionScreen`

Shell de las pantallas dirigidas por token: **aceptar invitación** y **fijar / restablecer contraseña**.
Compone `AuthLayout` + `<FormField>` + input de contraseña con toggle de visibilidad + `ConnectivityButton`.

- **Anatomía:** título (Heading 2) · copy breve de contexto · `<FormField>` de contraseña con **toggle de
  visibilidad** (botón Icon, `aria-label` estático «Mostrar / ocultar contraseña», icono `Eye`/`EyeOff`
  `aria-hidden`) · ayuda de reglas mínima · `ConnectivityButton` al pie.
- **Reglas de contraseña** en el Zod schema de la entidad (nunca `maxLength` en el input — el límite vive en
  `.max()` para que el error se vea).
- **Estados:** idle · enviando (delegado a `ConnectivityButton`, anti doble-envío) · sin conexión
  (`OfflineNotice` sobre el botón, datos intactos) · error de validación (`violations[]` → `<FormField>`, foco
  al primer inválido) · error técnico (`<ProblemDisplay panel>` sobre el form) · éxito → `SecuritySignal`.
- **Ergonomía:** una mano; el teclado no tapa el botón; **el token nunca se muestra**. **Dark mode** heredado.

### `ConnectivityButton`

Botón de envío resiliente a conectividad. Compone la variante **Brand** heredada; el delta es la
**máquina de estados de red**, no el aspecto.

- **Estados:** idle · **loading** (spinner en el sitio, la etiqueta permanece — submit pesimista heredado;
  el botón no se mueve) · **disabled en vuelo** (`aria-busy`, bloquea el **doble envío**) · **retry** (tras
  fallo / timeout, ofrece «Reintentar» de forma **idempotente**, sin duplicar la operación).
- **Reglas:** conserva el foco entre estados; no lanza toast de éxito (la confirmación es `SecuritySignal`);
  el reintento reutiliza la misma petición idempotente. **Dark mode** vía tokens Brand heredados.

### `OfflineNotice`

Aviso in-form de red caída que **nunca pierde lo tecleado**. No es un toast (debe persistir y no arrastrar el
foco): banner inline **sobre** el `ConnectivityButton`.

- **Anatomía:** franja `{color.bg-subtle}` con acento `{color.warning}` y texto `{color.warning-strong}`,
  `aria-live="polite"`, copy recuperable *«Sin conexión. Reintenta cuando recuperes señal.»*
- **Reglas:** el estado del formulario se preserva íntegro (solo es un aviso ambiental); se empareja con el
  estado `retry` del `ConnectivityButton`. Tono de advertencia, **no** de error (no es rojo). **Dark mode**
  heredado.

### `SecuritySignal`

Confirmación calmada tras una acción de seguridad, **siempre con el siguiente paso** y sin dramatismo.

- **Anatomía:** dot / icono `{color.success}` · título (Heading 3) `{color.text}` · una línea de siguiente
  paso `{color.text-muted}` (`{color.success-strong}` cuando el texto porta la semántica) · acción primaria
  para continuar (Brand).
- **Variantes:** `password-changed` — dos contextos distintos con dos mensajes distintos: **reset (J2)**
  *«Tu contraseña se ha actualizado. Por seguridad, hemos cerrado las demás sesiones abiertas.»* y **cambio
  desde Mi cuenta (J6)** *«Contraseña modificada correctamente. Hemos cerrado las demás sesiones; esta sigue
  activa.»* · `invitation-accepted` (*«Invitación aceptada»* → entrar al ERP) · `sessions-closed`
  (*«Sesiones cerradas»*). Reutiliza el registro de `<StatusBadge>` success; nunca celebración ni animación.
  **Dark mode** heredado.

### `SecurityEmail`

Lenguaje visual de los correos de seguridad — **estilo bancario, mínimo**. Contrato de plantilla, no
componente de la app.

- **Anatomía:** cabecera plana (wordmark pequeño o texto, **sin** hero) · saludo breve · una frase de propósito
  · **un** enlace/botón prominente (bulletproof, estilos inline, área de toque grande en móvil) · pie legal
  mínimo.
- **Remitente:** **nunca `no-reply`** → `soporte@` / `seguridad@` (aunque derive en tickets).
- **Estilo:** sin marketing, imágenes grandes, banners ni colores llamativos. Botón `{color.brand}` /
  `{color.text-on-accent}`, enlace con **par literal inline claro/oscuro** (`#2f5cd9` claro / `#6c9bff`
  oscuro — AA en ambas ramas; los clientes no resuelven `{color.accent}`, misma excepción sancionada que
  `surface`/`text`), borde `{color.border}`, `{radius.md}` inline.
- **Fuente:** pila de sistema (webfont no fiable en correo). **Dark mode aware** (`prefers-color-scheme` con
  literales de rama: `surface-dark`/`text-dark`/`link-dark`) y legible aunque el cliente lo ignore — abrir un email a las
  6:30 desde el móvil en oscuro debe sentirse el mismo producto.
- **Variantes por disparador:** invitación · restablecer contraseña · aviso «tu contraseña ha cambiado» ·
  (opcional) aviso de invitación revocada.

## Do's and Don'ts (access-specific)

**Do**

- Mantén los emails **planos y bancarios**: un solo enlace prominente, pie legal mínimo, remitente no-`no-reply`.
- Ofrece **siempre «Iniciar sesión»** en todo `AccessWall` de enlace muerto — rescata la reapertura y la
  cobertura perdida sin romper la opacidad.
- Muestra **estado de carga** y **bloquea el doble envío** en cada acción (`ConnectivityButton`).
- Preserva **lo tecleado** ante caída de red (`OfflineNotice`); el formulario nunca se vacía.
- Devuelve el **foco al primer campo inválido** y respeta la ergonomía de una mano (botón al alcance, teclado
  que no lo tapa).
- Confirma cada acción de seguridad con `SecuritySignal` **y su siguiente paso**, en tono calmado.
- Conserva la **paridad de dark mode** en tarjeta, muros y emails.

**Don't**

- ❌ Dramatizar estados `SUSPENDED` / `LockedUntil` / enlace no válido con `{color.danger}`, iconos de alarma o
  lenguaje acusatorio: son estados neutros, no errores.
- ❌ Revelar el motivo de muerte de un token (caducado / usado / revocado / ya aceptado / eliminado): todos
  colapsan al mismo muro opaco.
- ❌ Dar en `forgot-password` un mensaje distinto según exista o no el email (indistinguibilidad pre-identidad):
  siempre el mismo neutro.
- ❌ Renderizar nunca el token del enlace ni meter secretos/JWT en `localStorage`/`sessionStorage`.
- ❌ Introducir una hue nueva o animaciones específicas de auth; el eje vive de la rampa heredada y del motion
  discreto (`prefers-reduced-motion`).
- ❌ Sacar `AccessWall`/`SecuritySignal` del shell `AuthLayout` ni sintetizar un `<ProblemDisplay>` para lo que
  no es un error técnico.
- ❌ Usar `maxLength` en el input de contraseña (el límite vive en el Zod schema).
