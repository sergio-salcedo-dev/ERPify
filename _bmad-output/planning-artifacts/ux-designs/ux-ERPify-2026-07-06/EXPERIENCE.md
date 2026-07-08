---
name: 'Experiencia de acceso / identidad — experiencia (IA · comportamiento · estados · flujos)'
status: final
updated: 2026-07-06
design_ref: ./DESIGN.md
system_ref: ../../../../pwa/DESIGN.md
sources:
  - ../../arch-addendum-auth-rbac.md (subsistema auth/RBAC · SI-1..SI-5)
  - ../../../../docs/adr/auth-rbac-subsystem.md (D1 firewall sesión · D2 identidad · D3 roles)
  - ../../../../docs/adr/rbac-authorization-model.md (RBAC — ortogonal, ya cerrado)
scope: 'Experiencia pública de acceso e identidad (landing entry + login + recuperación + aceptación de invitación + estados de cuenta)'
note: >
  Espina de comportamiento. Referencia los tokens y componentes de DESIGN.md por nombre con sintaxis
  {nombre}. En conflicto entre cualquier mock y esta espina, gana la espina. Proyecta un modelo de
  dominio (cuatro máquinas de estado + dos invariantes) cuyo hogar durable es el ADR de
  Identity/Invitation, no esta espina — ver § Encaje del modelo.
---

# Experiencia de acceso / identidad — EXPERIENCE

> **Qué es:** el contrato de experiencia de las superficies **públicas de acceso** de ERPify — el punto
> de entrada desde la landing, el login, la recuperación de contraseña, la aceptación de invitación y
> los **muros de estado de cuenta**. **Proyecta** un modelo de dominio de identidad (cuatro máquinas de
> estado ortogonales + dos invariantes de seguridad) sobre pantallas, estados, microcopy y journeys.
> **No es** la fuente de verdad de ese modelo: su hogar durable es el ADR de Identity/Invitation
> (ver § Encaje del modelo). Ninguna pantalla *es* un estado — cada pantalla es una **proyección** de
> las máquinas. **En conflicto entre cualquier mock y esta espina, gana la espina.**

## Foundation

- **Form-factor:** web PWA **responsive** (desktop + móvil). El **móvil-en-obra es de primera clase**,
  no un modo degradado: las personas reales (Marta, jefa de obra; David, subcontratista) abren un email
  y aceptan una invitación **con una sola mano, con 3G intermitente, a las 6:30**. Cada superficie se
  resuelve primero para ese contexto y escala hacia arriba, no al revés.
- **Sistema de UI:** ERPify PWA Design System ([`pwa/DESIGN.md`](../../../../pwa/DESIGN.md)) — Shadcn +
  Tailwind 4 + composites `@/components/erpify`. La identidad visual y los tokens viven ahí; el **delta
  de acceso** (nuevos componentes y variantes) en [`./DESIGN.md`](./DESIGN.md). Esta espina especifica
  **solo el delta de comportamiento**. Las superficies de auth heredan el shell `{AuthLayout}` actual
  (tarjeta centrada `min-h-dvh`, `{Logo}` al `HOME`, `noindex`).
- **Dos lenguajes visuales a propósito:** la **landing/marketing** (raw-palette, `app/_components/`) es
  la puerta; las **superficies de auth** (token-driven Shadcn) son la sala. El punto de entrada A1 vive
  en el lenguaje marketing; de B1 en adelante es lenguaje de app. No se cruzan.
- **La resiliencia de conectividad es una postura fundacional, no una feature** (§ Resiliencia de
  conectividad). No es offline-first: es *UX resiliente* — carga clara, anti doble-envío, sin pérdida
  de datos, reintento idempotente, error recuperable. Nace de dónde se usa el ERP: obras, sótanos,
  parkings, ascensores.
- **i18n = español primero, arquitectura multi-idioma preparada.** UI y emails en español; **ninguna
  cadena hardcodeada** — toda la microcopy de esta espina es contenido traducible, no literal de código.
- **Estado real del terreno:** login por credenciales (sesión httpOnly, sin JWT en cliente) **funciona**
  en backend; las cuatro pantallas `(auth)` existen pero son **mocks** (siembran identidad local, emiten
  toasts neutros). Esta espina define el **estado objetivo**; la integración real llega con el backend de
  Identity/Invitation.
- **Deuda de wiring (a corregir al cablear, no solo deprecar):** el mock `/register` (alta libre, siembra
  identidad sin invitación) y el copy del `ResetPasswordForm` («…inválido **o ha expirado**») **contradicen**
  los invariantes de esta espina (invitation-first; opacidad total del token — nunca «caducado»). No basta
  con deprecarlos en el contrato: la ruta de alta libre debe **retirarse** y el copy **colapsar** a «Este
  enlace ya no es válido» al integrar el backend.

## Information Architecture

**Inventario de superficies.** Cada necesidad del modelo aterriza en una superficie; cada
superficie tiene un camino que la alcanza.

| Código | Superficie | Ruta | Alcanzada desde | Propósito |
|---|---|---|---|---|
| **A1** | Entrada desde landing | `/` (lenguaje marketing) | Navbar público | Único CTA de acceso: «Iniciar sesión / Acceder al ERP». No hay «crear cuenta». |
| **B1** | Login | `/login` (`noindex`) | A1 · muros · `?next=` · sesión expirada | Credenciales → ERP. Enlaces a B2. |
| **B2** | Forgot password | `/forgot-password` (`noindex`) | B1 · muro de bloqueo | Solicitar enlace de restablecimiento (respuesta neutra única). |
| **B3** | Reset password | `/reset-password?token=` (`noindex`) | Email (`{SecurityEmail}`) | Fijar nueva contraseña vía token de un solo uso ({TokenActionScreen}). |
| **B4** | Accept invitation | `/accept-invitation?token=` (`noindex`) | Email (`{SecurityEmail}`) | Aceptar invitación + definir contraseña ({TokenActionScreen}) → `ACTIVE` → ERP. |
| **C1** | Access walls | render de `{AccessWall}` (sin ruta propia dedicada; estado o `?token=` inválido) | B3/B4 (token no elegible) · post-login (suspendido/bloqueo/desactivado) · sesión expirada | Muro de estado con acciones genéricas. |

- **Reemplazo `register` → `accept-invitation`.** El `/register` actual (mock de alta libre) **se retira**:
  el modelo es **invitation-first** — nadie se da de alta dentro de una empresa existente sin
  invitación. La superficie de alta pública de una **empresa nueva** (org self-signup, F1) es un
  **extension point diferido** (ver § Frontera del run), no la forma base de crear usuarios.
- **Superficies de auth `noindex`.** Heredan `robots: { index: false }` del `{AuthLayout}`. No son
  contenido de marketing; no se indexan.
- **Forward-compat de tenancy — restricción de diseño.** ERPify es hoy **single-tenant,
  invitation-first**. La UX inicial es single-tenant **sin superficie de tenant**: *nada* de selector de
  empresa, cambiar de organización, crear workspace ni elegir tenant. La **invitación es
  conceptualmente a una organización** (membership-aware) aunque la UI no muestre la org, y el modelo de
  identidad **no** asume «usuario global». Consecuencia de diseño: cuando llegue el ADR de tenancy,
  **solo deben aparecer caminos nuevos** (crear empresa, cambiar de organización) — el flujo
  `Landing → Login → Invitación → Entrar` permanece **casi idéntico**.

### Proyección estado → superficie pública (de aquí caen las pantallas)

| Disparador (máquina · estado) | Superficie pública |
|---|---|
| Invitation `SENT` + token válido · Identity `INVITED` | **B4 Accept invitation** (define contraseña) |
| Invitation `EXPIRED / REVOKED / ACCEPTED` (token no elegible) | **C1 Access wall** opaco + acción (invariante de token) |
| Identity `ACTIVE` · Auth `Unlocked` | **B1 Login** → ERP |
| Identity `ACTIVE` · Auth `LockedUntil(T)` | **C1 Access wall** «bloqueada temporalmente» (post-identidad) |
| Identity `SUSPENDED` | **C1 Access wall** «acceso suspendido · contacta admin» (post-identidad) |
| Identity `DEACTIVATED` | **C1 Access wall** genérico (opaco; ver invariante) |
| cualquiera | **B2 Forgot password** responde siempre el mismo mensaje neutro (pre-identidad) |
| Session `Revoked / Expired` | re-login (silencioso salvo acción del usuario), conservando `?next=` |

### Estados de B1 Login

| Estado | Superficie / componente | Comportamiento |
|---|---|---|
| **idle** | `{FormField}` (email + contraseña) | Reposo; `autofocus` en email; enlaces a B2. |
| **enviando** | `{ConnectivityButton}` | Deshabilitado en vuelo, etiqueta «Enviando…», anti doble-envío. |
| **credenciales inválidas** | mensaje neutro in-form | «Correo o contraseña incorrectos.» — un solo mensaje (pre-identidad); datos retenidos. |
| **`LockedUntil(T)`** | `{AccessWall}` post-identidad | Muro «Demasiados intentos» **tras** credenciales válidas (D-c); nunca pre-identidad. |
| **offline** | `{OfflineNotice}` | «Sin conexión. Reintenta cuando recuperes señal.»; formulario intacto, reintento idempotente. |
| **error técnico** | `{ProblemDisplay}` / `{MutationError}` | 500 / red dura: superficie persistente, `aria-live` assertive, formulario operable. |

**Dónde renderizan los muros post-identidad.** Un intento de login que devuelve identidad **no-`ACTIVE`**
(`SUSPENDED` / `DEACTIVATED` / `LockedUntil`) **redirige** (mecanismo cliente) a la superficie `{AccessWall}`
correspondiente, renderizada inline desde la respuesta del POST; **no crea sesión** (D-c) ni deja cookie o
token reanudable.

## Voice and Tone

Tono **sobrio, directo y tranquilizador sin dramatismo** — más comunicación bancaria que producto de
consumo. El sistema informa del hecho y del **siguiente paso**; nunca culpa, nunca alarma, nunca revela
más de lo que la seguridad permite. Español; **ninguna cadena hardcodeada** (i18n). Los tokens
(`?token=`), ids y correos escritos por el usuario nunca se «humanizan».

**Mensajes pre-identidad (neutros · indistinguibles · invariante):**

| Lugar | Texto |
|---|---|
| Login — fallo de credenciales | «Correo o contraseña incorrectos.» — **un solo mensaje** para email inexistente, contraseña errónea o identidad no elegible: nunca revela cuál. |
| Forgot password — respuesta única | «Si esa dirección corresponde a una cuenta, te enviaremos un enlace para restablecer tu contraseña.» — **idéntica** exista o no la cuenta. |
| Reset — token ausente / no elegible | «Este enlace ya no es válido.» — nunca «caducado», «usado» ni «revocado». |

**Muros de token opacos (C1 · `{AccessWall}` · invariante de token):**

| Situación | Título | Cuerpo | Acciones |
|---|---|---|---|
| Invitación no elegible (expirada / revocada / aceptada / eliminada) | «Este enlace ya no es válido» | «Solicita una nueva invitación a tu administrador para continuar.» | «Iniciar sesión» (D-a, siempre) · «Solicitar nueva invitación» |
| Reset no elegible | «Este enlace ya no es válido» | «Pide un nuevo enlace para restablecer tu contraseña.» | «Iniciar sesión» · «Solicitar un nuevo enlace» |

**Muros post-identidad (C1 · `{AccessWall}` · solo tras probar credenciales):**

| Estado | Título | Cuerpo | Acciones |
|---|---|---|---|
| Auth `LockedUntil(T)` | «Demasiados intentos» | «Prueba de nuevo en unos minutos o recupera tu acceso.» | «Recuperar mi acceso» → B2 · «Iniciar sesión» |
| Identity `SUSPENDED` | «Tu acceso está suspendido» | «Contacta con tu administrador para reactivarlo.» | «Iniciar sesión» |
| Identity `DEACTIVATED` | «No puedes acceder con esta cuenta» | «Contacta con tu administrador.» — **genérico**, no revela la desactivación. | «Iniciar sesión» |
| Session `Expired` | «Tu sesión ha expirado» | «Inicia sesión de nuevo para continuar.» | «Iniciar sesión» (conserva `?next=`) |

**Señales de seguridad (`{SecuritySignal}` · confirmación legible + siguiente paso):**

| Acción de seguridad | Texto |
|---|---|
| Invitación aceptada (cae dentro del ERP) | «Invitación aceptada. Ya puedes empezar a trabajar.» |
| Contraseña restablecida (J2 · **no dramática**) | «Tu contraseña se ha actualizado. Por seguridad, hemos cerrado las demás sesiones abiertas.» |
| Contraseña cambiada desde *Mi cuenta* (J6) | «Contraseña modificada correctamente. Hemos cerrado las demás sesiones; esta sigue activa.» |
| Cierre manual de sesiones | «Sesiones cerradas. Solo sigue activa la de este dispositivo.» |

**Reglas de microcopy.** (1) Nunca revelar el estado interno de un token. (2) Nunca insinuar *qué* campo
falló en el login. (3) Los muros específicos (`SUSPENDED`, `LockedUntil`) solo se muestran
**post-identidad**. (4) Toda confirmación de seguridad lleva el **siguiente paso**, no solo el hecho.

## Component Patterns (comportamiento)

Los specs visuales viven en [`./DESIGN.md`](./DESIGN.md); aquí solo el comportamiento.

### `{AccessWall}` — muro de estado

- **Un mensaje + acciones, sin formulario.** Renderiza dentro del `{AuthLayout}` (tarjeta centrada).
  Título breve, cuerpo de una línea, botones de acción.
- **Siempre ofrece «Iniciar sesión» (D-a).** Toda variante del muro incluye el CTA genérico «Iniciar
  sesión» **además** de su acción específica (*solicitar nueva invitación* / *contactar admin* /
  *recuperar acceso*). Rescata el caso de cobertura-perdida y la reapertura de un enlace ya aceptado
  **sin romper la opacidad**: la acción se ofrece *siempre*, no depende del estado, así que no revela nada.
- **Opacidad total en muros de token.** Un enlace no elegible **nunca** explica el motivo (usado /
  revocado / caducado / ya aceptado / eliminado = información interna). Solo *«Este enlace ya no es
  válido»* + acción.
- **Los muros específicos son post-identidad.** `SUSPENDED` / `LockedUntil` solo se muestran **después**
  de que el usuario haya probado credenciales válidas (§ Invariante de seguridad · regla de los tres
  momentos). `DEACTIVATED` se muestra genérico incluso post-identidad.
- **Se alcanza en frío o como estado:** un token inválido lo renderiza en lugar del `{TokenActionScreen}`;
  un login exitoso-pero-no-admitido navega a él; una sesión expirada lo usa como puente al re-login.

### `{TokenActionScreen}` — pantallas por token (B3 reset · B4 accept invitation)

- **Token de un solo uso + caducidad**, leído de `?token=` del enlace del email. Ausente o no elegible ⇒
  renderiza `{AccessWall}` opaco (nunca revela por qué).
- **Una sola mano, móvil-en-obra:** campos grandes; **un solo campo de contraseña con toggle** mostrar/ocultar
  (no doble campo de confirmación que obligue a re-teclear a ciegas en móvil); el teclado no tapa el botón de
  envío; `autofocus` en el primer campo al montar. Para el usuario de lector de pantalla que **llega desde el
  email**, **anunciar el contexto de página** (título + propósito) **antes** del autofocus al campo, de modo
  que sepa *dónde está* y *qué se le pide* sin tabular hacia atrás.
- **Campo único de contraseña, endurecido** (se mantiene el campo único, **no** se añade confirmación): (1)
  el toggle mostrar/ocultar arranca **por defecto REVELADO** en las pantallas de token — se *crea* la
  contraseña, no se protege un secreto existente, así que ver lo tecleado previene el «mistype → lockout»;
  (2) el toggle es **target táctil grande** y anuncia su estado con `aria-pressed`; (3) **salvavidas
  explícito:** tras aceptar, la identidad ya es `ACTIVE`, de modo que un error tipográfico **se recupera por
  forgot-password (B2), no por re-invitación** — aunque el token de B4 sea de un solo uso.
- **No pierde datos** ante caída de red (§ Resiliencia): los valores tecleados sobreviven a un submit
  fallido y a una pérdida de cobertura.
- **Envío vía `{ConnectivityButton}`** (anti doble-envío + reintento idempotente).
- **Al éxito** emite un `{SecuritySignal}` y navega a destino (ERP para B4; B1 para B3).

### `{ConnectivityButton}` — botón resiliente

- **Estados: reposo → enviando → deshabilitado → reintento.** Etiqueta de carga clara («Enviando…»);
  spinner heredado (`{Spinner}`).
- **Anti doble-envío:** se deshabilita mientras hay una petición en vuelo; una segunda pulsación no
  dispara un segundo envío. Nunca se queda «colgado» sin explicación.
- **Reintento idempotente** cuando la operación lo permite (aceptar invitación, reset): un reintento tras
  recuperar señal no crea efectos duplicados. Ante fallo de red muestra estado recuperable, con el
  formulario intacto.

### `{OfflineNotice}` — aviso de red caída, sin pérdida

- Aviso **in-form** (no un banner global) cuando el envío falla por red: *«Sin conexión. Reintenta cuando
  recuperes señal.»* El formulario **conserva** todo lo tecleado; el `{ConnectivityButton}` vuelve a
  reposo listo para reintentar. Recuperable, nunca destructivo.

### `{SecuritySignal}` — confirmación post-acción de seguridad

- No es auditoría técnica: es una **confirmación legible** tras cada acción de seguridad relevante,
  **siempre con el siguiente paso**. Se usa para: invitación aceptada, contraseña restablecida/cambiada,
  sesiones cerradas. Reduce la incertidumbre. Textos en § Voice and Tone.
- **Gestión de foco en la transición (SPA).** Al navegar a un `{SecuritySignal}` o a un `{AccessWall}` el
  foco **no se reubica solo**: hay que **moverlo al encabezado** (`<h1>`) de la nueva superficie y
  **anunciarlo**, para que el usuario de lector de pantalla no pierda el sitio ni se pierda la confirmación.
  Aplica a las tres transiciones: éxito de token, login→muro, sesión expirada→re-login.

### Formularios que no pierden datos

- Todos los formularios de auth usan `{FormField}` + validación de esquema (Zod) del sistema. Los valores
  **se retienen** ante un submit fallido, un error de validación 422 (mensajes espejo de la API) o una
  caída de conectividad. Los errores de mutación reales se muestran en la superficie persistente del
  sistema (`{MutationError}`), nunca como toast solitario ni dentro de un diálogo.

## State Patterns

**EL NÚCLEO.** El backbone de dominio se descompone en **cuatro máquinas de estado independientes y
ortogonales**. Regla de fondo: **no mezclar estado persistente de identidad con condición transitoria de
autenticación**. **Ninguna pantalla es un estado**; cada pantalla es una **proyección**. **Estados
híbridos prohibidos.**

**1 · Identity Lifecycle** — `INVITED → ACTIVE ↔ SUSPENDED ↔ DEACTIVATED`

- **Sin `PENDING`.** Aceptar invitación ⇒ `ACTIVE` directo. El admin ya decidió al invitar; no hay
  evento de negocio entre *aceptar* y *puede trabajar*. Un `PENDING` solo añadiría «esperar otro clic del
  admin».
- **Los roles se asignan ANTES de aceptar:** `Admin → invita → asigna rol → envía email → usuario acepta
  → ACTIVE`. **Nunca** un usuario activo que no pertenezca ya a la organización (membership-aware).
- **`SUSPENDED`** = pausa administrativa **reversible** (`ACTIVE ↔ SUSPENDED`).
- **`DEACTIVATED`** = *«esta persona ya no pertenece a la empresa»* (fin de empleo). Técnicamente
  reactivable; **no** es una suspensión prolongada, **no** implica borrado / GDPR / hard-delete.

**2 · Invitation Lifecycle** (entidad propia, distinta del `User`) — `CREATED → SENT → (ACCEPTED | REVOKED | EXPIRED)`

- Separarla del usuario habilita varias invitaciones, reenvíos, auditoría, métricas e histórico **sin**
  convertir al `User` en una máquina de estados gigante.
- Un **reenvío** emite un token nuevo que **invalida el anterior** (el token viejo → muro opaco).

**3 · Authentication State** (condición transitoria, **NO** ciclo de vida) — `Unlocked / LockedUntil(T)`
(+ futuras `PasswordExpired`, `MFARequired`)

- Ortogonal a Identity: `ACTIVE + Locked` y `ACTIVE + Unlocked` son válidos; `SUSPENDED + Locked` es
  **imposible** (un suspendido ni entra en el flujo de autenticación).
- El *lock* es sobre **intentos de contraseña**, no sobre la identidad — de ahí la regla D-b.

**4 · Session Lifecycle** (máquina independiente) — `Active → (Revoked | Expired)`

- Existe un **«dispositivo / sesión actual»** distinguible del resto (§ Modelo de sesión).
- **Cambiar contraseña ⇒ invalida las demás sesiones.** **Suspender / Desactivar ⇒ invalida sus
  sesiones.** El usuario puede cerrar sesiones (mínimo «cerrar todas las demás»).

### Reglas de proyección (comportamiento, no estados)

- **D-a — el muro de enlace no válido ofrece siempre «Iniciar sesión»** (además de *solicitar nueva /
  contactar admin*): rescata cobertura-perdida y reapertura de enlace aceptado **sin romper la opacidad**.
- **D-b — un reset con éxito limpia `LockedUntil`**: la recuperación **puentea** el bloqueo de
  autenticación (el lock es sobre intentos de contraseña, no sobre la identidad).
- **D-c — `Session.Active` se crea SOLO para `Identity=ACTIVE`.** `SUSPENDED` / `DEACTIVATED` **prueban
  identidad** (credenciales válidas) pero **no reciben sesión**: solo el muro post-identidad.

### Regla de los tres momentos (arquitectónica — frontera *autenticado ≠ admitido*)

Hay **tres momentos, no dos**. **Nunca** `credenciales válidas → sesión`:

```
credenciales válidas  →  identidad demostrada  →  evaluación de admisión  →  creación de sesión
```

Ese matiz separa una arquitectura limpia de un login lleno de excepciones: la admisión (consultar
Identity Lifecycle) ocurre **entre** demostrar quién eres y concederte una sesión.

## Interaction Primitives

- **Foco y teclado (crítico en flujos por token):** `autofocus` en el primer campo al montar cada
  `{TokenActionScreen}`; **orden de foco natural** (estas superficies son documentos, no diálogos: nada de
  focus-trap, `{Logo}` y `{ThemeToggle}` alcanzables por teclado); orden de tabulación = orden de lectura;
  **retorno del foco al campo en error** tras un submit fallido (no al inicio del formulario); compatibilidad
  con lectores de pantalla en cada control. `Esc` cierra popovers/toggles sin enviar el formulario.
- **Deep-link `?next=` (ya implementado).** `RequireAuth` guarda el destino bloqueado en `?next=`
  (`encodeURIComponent`), y B1 lo lee y lo **valida** con `safeInternalPath(next, Routes.BACKOFFICE)` +
  `safeHref` antes de navegar — rechaza cualquier destino off-origin, `//host`, `/\host`, o esquema
  peligroso (`javascript:` / `data:` / `file:`). Una sesión expirada **preserva** `?next=` para devolver
  al usuario exactamente donde estaba.
- **Token de un solo uso.** El `?token=` se consume al aceptar/restablecer; reabrir el mismo enlace ⇒
  muro opaco. Un token ausente se rechaza en el cliente; su elegibilidad (usado / caducado / revocado) la
  valida el backend, y el cliente **nunca** distingue el motivo en pantalla.
- **Anti doble-envío** en todo submit vía `{ConnectivityButton}`; reintentos idempotentes.

## Accessibility Floor

Hereda **WCAG 2.2 AA** del sistema ([`pwa/DESIGN.md`](../../../../pwa/DESIGN.md)). Específico de este eje:

- **Gestión de foco en flujos por token:** `autofocus` inicial, **focus-return al campo en error**, foco
  visible 2 px `{color.focus-ring}` en campos, toggles y botones de acción. `{TokenActionScreen}` /
  `{AccessWall}` son **documentos primarios, no diálogos** → **orden de foco natural** y `{Logo}` /
  `{ThemeToggle}` **alcanzables** por teclado (evita un keyboard-trap, WCAG 2.1.2 nivel A); el focus-trap se
  reserva a `{Dialog}` reales (confirmaciones destructivas), no a estas tarjetas.
- **Gestión de foco en transiciones de éxito / post-login (SPA):** al navegar a un `{SecuritySignal}` o a un
  `{AccessWall}`, **mover el foco al encabezado** (`<h1>`) de la nueva superficie y **anunciarlo** — el foco
  no se reubica solo, y si no, el usuario de lector de pantalla pierde el sitio y no oye la confirmación.
- **Semántica de encabezados:** el título del `{TokenActionScreen}` es un **`<h1>` elemento** (aunque use el
  token visual «Heading 2»); los encabezados del `{SecuritySignal}` **no saltan niveles**. Exactamente un
  `<h1>` por superficie de auth; separar «token de tamaño» de «nivel de elemento».
- **El color nunca es canal único:** el estado de un muro se comunica con **texto** (título + cuerpo), no
  solo con un icono o tinte. El toggle mostrar/ocultar contraseña lleva nombre accesible estático.
- **Nombres accesibles estáticos** en controles de acción (regla heredada): `aria-label` corto y estático
  («Iniciar sesión», «Mostrar contraseña»); el detalle dinámico va en `title`.
- **`prefers-reduced-motion`** respetado (heredado): transiciones discretas, sin loaders innecesarios; el
  estado de carga del `{ConnectivityButton}` no depende solo de animación.
- **Anuncios `aria-live` (nunca mueven el foco):** un **fallo real de submit** (500, red dura vía
  `{ProblemDisplay}` / `{MutationError}`) se anuncia **`assertive`**; el `{OfflineNotice}` ambiental,
  condición recuperable, **`polite`**. Ni `polite` ni `assertive` reubican el foco — «assertive» solo
  interrumpe la locución. Todo error de red/validación deja el formulario operable y el dato intacto
  (§ Resiliencia).
- **Modo oscuro cubierto** (heredado): abrir un email a las 6:30 desde el móvil en modo oscuro debe
  sentirse el mismo producto.

## Invariante de seguridad: indistinguibilidad pre-identidad

**Transversal, no solo UX.** *Antes* de establecer la identidad del usuario, **todas las respuestas
deben ser indistinguibles para un atacante**; *después* de autenticar, la app puede dar mensajes
específicos y orientados a resolver. No es una regla del login: aplica a **login, forgot-password, reset,
invitaciones, magic links, MFA y futuras APIs**.

- **Pre-identidad, todo neutro:** un fallo de login no distingue email inexistente de contraseña errónea;
  forgot-password responde lo mismo exista o no la cuenta; un token no elegible no revela por qué.
- **Post-identidad, específico y resolutivo:** los muros `SUSPENDED` / `LockedUntil` **solo** aparecen
  tras demostrar credenciales válidas (regla de los tres momentos). Un atacante no autenticado no ve
  ninguno de ellos — no puede enumerar cuentas por la respuesta.
- **Opacidad total del token:** un enlace no elegible (usado / revocado / caducado / aceptado /
  eliminado) muestra **siempre** el mismo muro — *«Este enlace ya no es válido»* + acción — porque el
  motivo es información interna. Tokens de un solo uso + caducidad.
- La anti-enumeración es **transversal**: J4 (cuenta suspendida) lo demuestra — la neutralidad no vive en
  el login, vive en el modelo.

### Lo que el invariante exige del backend (para el ADR)

El invariante es transversal: la UX iguala el *copy*, pero la indistinguibilidad solo es honesta si el
backend iguala también **timing, status HTTP y forma de respuesta**. Esta espina **no lo implementa** — lo
**enuncia** y lo **delega al ADR de Identity/Invitation**:

- **Indistinguibilidad multicanal.** El atacante no lee el texto: mide **latencia, código de estado y
  forma/tamaño de respuesta**. Exige un **camino de tiempo constante** (hashear **siempre** una contraseña,
  incluso cuando el email no existe) y **status/forma uniformes** entre los tres casos pre-identidad
  {email inexistente, contraseña errónea, identidad no elegible} — no solo el mensaje.
- **Higiene del token `?token=`:** un solo uso + caducidad corta; **nunca** logueado, ni propagado en
  `?next=`, ni filtrable por `Referer` (endurecer `Referrer-Policy` en las rutas con token). **Consumido en
  servidor y retirado de la URL** tras validar (`history.replaceState`), para evitar la fuga por `Referer`
  same-origin hacia `/api`, hacia el túnel `/monitoring`, y al historial del navegador y a los logs de
  acceso de Caddy. Se refuerza porque **D-b** (un reset con éxito limpia `LockedUntil`) **amplifica** el
  impacto de un token filtrado.
- **CSRF + regeneración de sesión** en los POST de **login** y de **aceptar-invitación** (salto de
  privilegio `INVITED→ACTIVE`): token CSRF en ambos y **regenerar el id de sesión** al acuñar la sesión
  `ACTIVE` (anti session-fixation).
- **Reset revoca TODAS las sesiones** — no solo «las demás»: quien resetea **no está en sesión de
  confianza** (arranca no autenticado), así que no hay sesión actual que preservar y cualquiera del atacante
  debe morir.
- **Rate-limit** en **login / forgot / reset / accept** (por IP y por cuenta), sin romper la neutralidad
  (mismo status/copy al saturar, no un «demasiadas solicitudes» que delate).
- **Muro `SUSPENDED` / `DEACTIVATED` entregado sin sesión parcial** (coherente con D-c): se renderiza inline
  desde la propia respuesta del POST de login, sin cookie, token reanudable ni estado de cuenta en la URL.

## Resiliencia de conectividad

**Prioridad alta, no offline completo.** ERPify se usa en obras, sótanos, parkings, ascensores, mala
cobertura. **UX resiliente, no offline-first.** Cinco principios de primera clase:

1. **Estado de carga claro** — todo botón de acción muestra «enviando» inequívoco; nunca se queda colgado
   sin explicación (`{ConnectivityButton}`).
2. **Evitar el doble envío** — el control se deshabilita en vuelo; una segunda pulsación no duplica.
3. **Formularios que no pierden datos** — un submit fallido o una caída de red **conservan** lo tecleado
   (`{OfflineNotice}`, `{TokenActionScreen}`).
4. **Reintentos idempotentes** cuando la operación lo permite — reintentar aceptar/reset tras recuperar
   señal no crea efectos duplicados.
5. **Mensajes de error recuperables** — el error deja el formulario operable y ofrece reintentar; nunca
   un callejón sin salida.

## Modelo de sesión

**Proyección al usuario** de la máquina 4 (`Active → Revoked | Expired`) — su definición y las reglas de
invalidación (cambio de contraseña, suspensión/desactivación) viven en **§ State Patterns**; aquí solo el
**modelo mental** que el usuario asume desde el día 1, aunque la pantalla de gestión sea mínima:

- **Existe un «dispositivo actual».** La sesión desde la que trabaja es distinguible del resto; «cerrar las
  demás sesiones» **nunca** se autoexpulsa. Los cierres se comunican con un `{SecuritySignal}` no dramático
  (J2, J6).
- **La expiración es comportamiento del día a día, sin pantalla propia.** `Session=Expired` → vuelta a B1
  **conservando `?next=`** → mensaje breve *«Tu sesión ha expirado. Inicia sesión de nuevo para continuar.»*
  Al re-autenticar, el usuario regresa exactamente donde estaba.
- **Abiertas (en el radar, no bloquean Fase 1):** ¿varias sesiones simultáneas?, ¿granularidad
  por-dispositivo? Se decide en el ADR; la UI mínima inicial es una lista simple con «cerrar las demás».

## Contrato de emails (`{SecurityEmail}`)

El lenguaje de los emails de seguridad (invitación, restablecimiento, aviso de cambio de contraseña):

- **Idioma:** el del usuario cuando exista; mientras tanto, español.
- **Remitente:** **evitar `no-reply`** (los usuarios responden por inercia) → `soporte@` / `hola@` /
  `seguridad@`, aunque desemboque en tickets.
- **Contenido:** extremadamente simple — **más comunicación bancaria que newsletter**. Sin marketing,
  imágenes enormes, banners ni colores llamativos.
- **Enlaces muy visibles**, especialmente en móvil (el enlace es la acción; se abre con una mano).
- **Pie legal mínimo** — no convertir el email en una página de texto.
- El email es el **origen del token** que abre B3/B4; su elegibilidad la resuelve el backend, y la
  pantalla mantiene la opacidad del token (§ Invariante de seguridad).

## Señales de seguridad visibles

Puntero: el **comportamiento** del `{SecuritySignal}` vive en § Component Patterns y los **textos exactos**
en § Voice and Tone. Cubren invitación aceptada, contraseña restablecida/cambiada, sesiones cerradas y
enlace no válido — cada una sobria y con el siguiente paso, nunca alarmante ni celebratoria.

## Key Flows

Los journeys son **tests narrativos**: pruebas de que el modelo resiste la realidad, no wireframes.
Los 6 + V1 **fluyen sin ningún estado nuevo** — el modelo es estable.

### J1 · «Entrar a trabajar por primera vez» (ancla) — Marta, jefa de obra, móvil, 3G intermitente

Domingo por la noche, en la caravana de la obra. Marta **no quiere «crear una cuenta»**: quiere empezar a
trabajar mañana. Abre el email (`{SecurityEmail}`), toca el enlace.

1. `Invitation=SENT` + token válido → **B4 Accept invitation** (`{TokenActionScreen}`). Su rol (**EDITOR**)
   ya está decidido; ni lo ve ni lo elige. **Una sola mano:** campos grandes, un campo de contraseña con
   toggle, teclado que no tapa el botón.
2. Define contraseña → submit. **Pierde cobertura justo al enviar.** El `{ConnectivityButton}` entra en
   *enviando* y no se cuelga; al caer la red, `{OfflineNotice}` *«Sin conexión. Reintenta cuando recuperes
   señal.»* con el formulario **intacto**.
3. Reintenta al recuperar señal. **Dos ramas, ambas fluyen:**
   - su submit anterior **sí llegó** → token `ACCEPTED` → reabrir enlace = **muro opaco**, pero ese muro
     ofrece **«Iniciar sesión»** (D-a) → entra con la contraseña que puso → trabaja.
   - su submit **no llegó** → token sigue `SENT` → ve B4 otra vez → define contraseña → entra.

**Clímax:** cae **dentro del ERP, ya operativa como EDITOR**, sin ningún paso administrativo intermedio.
**Descubrimiento D-a.**

### J2 · «Vuelta de vacaciones» — Rubén, administrativo, desktop, oficina

No recuerda la contraseña. Falla **3 intentos**.

1. `Auth: Unlocked → LockedUntil(T)` → muro **post-identidad** *«Demasiados intentos…»* con «Recuperar mi
   acceso».
2. **B2 Forgot password:** escribe su email → **siempre** el mismo mensaje neutro (pre-identidad). Recibe
   email.
3. **B3 Reset** (token de un solo uso) → nueva contraseña. Al entrar, `{SecuritySignal}` **no dramática:**
   *«Tu contraseña se ha actualizado. Por seguridad, hemos cerrado las demás sesiones abiertas.»*

**Clímax:** el muro de bloqueo temporal + la explicación de que se cerraron sus otras sesiones.
**Descubrimiento D-b:** el reset con éxito **limpia `LockedUntil`** (la recuperación puentea el bloqueo).

### J3 · «El enlace muerto» — David, subcontratista, móvil

Abre la invitación tres semanas tarde. `Invitation=EXPIRED` → **muro opaco**, idéntico a cualquier token
no elegible: *«Este enlace ya no es válido»* + *«Solicitar nueva invitación»*. **Nunca dice «caducado».**
**Clímax:** la opacidad en acción. Valida el invariante tal cual, sin cambios.

### J4 · «La cuenta en pausa» — Elena, gerente (el más importante en lo arquitectónico)

El admin la ha suspendido (`Identity=SUSPENDED`). Intenta entrar con credenciales **correctas**:

```
Login  →  respuesta NEUTRA (pre-identidad, indistinguible)
       →  credenciales válidas → identidad PROBADA
       →  consulta Identity Lifecycle → SUSPENDED
       →  NO se crea sesión (D-c) → Muro «acceso suspendido · contacta con tu administrador» (post-identidad)
```

**Clímax:** autentica con éxito y *entonces* recibe el muro específico — un atacante no autenticado no
distingue nada. Demuestra que la anti-enumeración es **transversal**, no una regla del login.
**Descubrimiento D-c** + regla de los tres momentos (*autenticado ≠ admitido*).

### J5 · «Sergio invita y revoca» (lado-contrato, sin UI de backoffice)

Contrato, no pantalla. La UI admin vive en su propio slice (lenguaje backoffice); aquí solo la costura
`Acción → Evento → Estado → Email → Superficie pública`:

| Acción admin | Evento | Cambio de estado | Email | Superficie pública que recibe |
|---|---|---|---|---|
| Invitar a Marta (rol EDITOR) | InvitationCreated/Sent | `Invitation: CREATED→SENT` | *«Te han invitado a ERPify»* | B4 Accept invitation |
| Reenviar | InvitationResent | `SENT` (token nuevo, invalida el anterior) | reenvío | B4 (token nuevo) / muro (token viejo) |
| Revocar invitación no aceptada | InvitationRevoked | `SENT→REVOKED` | — (aviso opcional) | Muro opaco |
| Suspender a Elena | UserSuspended | `Identity: ACTIVE→SUSPENDED` (invalida sesiones) | opcional | Muro «suspendido» post-identidad |

**Suficiente como contrato**; el backoffice se diseña aparte.

### J6 · «Cambiar contraseña desde Mi cuenta» (extension point — confluencia de 3 máquinas)

Rubén, ya dentro, cambia su contraseña desde *Mi cuenta > Seguridad*:

```
Session=Active (actual)  →  password changed
   →  Authentication: credencial actualizada
   →  Session: invalida las DEMÁS sesiones; la ACTUAL sobrevive
   →  Identity: sin cambios
   →  email de notificación «tu contraseña ha cambiado»
```

**Clímax:** confluyen Session + Authentication + Identity sin colisión; la sesión actual **no se
autoexpulsa**. Se **diseña el modelo mental ahora** (§ Modelo de sesión); la pantalla plena es un
**extension point**.

### V1 · Validación — reabrir invitación ya aceptada

`Invitation=ACCEPTED` + reabrir el mismo enlace → **exactamente** el muro de cualquier token no válido:
*«Este enlace ya no es válido»* (+ «Iniciar sesión», D-a). **Nunca** revela *ya usado / caducado /
revocado*. Prueba la opacidad de tokens.

**Veredicto global:** el modelo (cuatro máquinas + dos invariantes) **resiste los 6 journeys + V1 sin
introducir ningún estado nuevo ni excepción** — solo afloran D-a / D-b / D-c. Modelo de identidad
**estable**.

## Frontera del run y extension points

Lo que **se diseña por completo** aquí (pantalla + estados + microcopy) vs lo que queda como **costura
preparada** (mental model presente, sin implementar):

| Superficie / feature | En este run |
|---|---|
| B1 Login · B2 Forgot · B3 Reset · B4 Accept invitation · C1 Access walls | **Diseño completo** (Fase 1). |
| Backoffice de miembros (invitar / reenviar / revocar / activar / desactivar) | **Contrato** (J5), no UI — vive en el slice backoffice. |
| Gestión de sesiones | **Modelo mental completo** (§ Modelo de sesión); UI mínima; multi-sesión detallada = costura. |
| Magic link · Passwordless · MFA · SSO | **Solo costura** — el invariante y los flujos las contemplan, pero dependen de backend inexistente. |
| Org self-signup (crear empresa nueva) | **Extension point diferido** — pendiente del ADR de tenancy; solo AÑADE caminos. |

Las costuras se diseñan de modo que activarlas **añada** superficies sin rehacer el flujo de acceso.

## Encaje del modelo

Las cuatro máquinas + los dos invariantes **son modelo de dominio de Identity/Invitation, no UX**: su
hogar durable es un ADR (el subsistema auth/RBAC ya vive en
[`docs/adr/auth-rbac-subsystem.md`](../../../../docs/adr/auth-rbac-subsystem.md) — firewall de sesión,
identidad, roles — y el RBAC ortogonal en
[`docs/adr/rbac-authorization-model.md`](../../../../docs/adr/rbac-authorization-model.md)). El **agregado
`Invitation`**, el `status` de `User`, el *lockout* de autenticación y el store de sesiones descritos aquí
piden su propia extensión de ADR (`bmad-create-architecture`) antes de implementar backend. Esta espina
**proyecta** ese modelo (pantallas, estados visibles, microcopy, journeys); **no** es su fuente de verdad.

## Anti-patrones (no hacer)

- ❌ Revelar en el login *qué* campo falló, o si el email existe (rompe la indistinguibilidad).
- ❌ Explicar por qué un token no es válido (usado / caducado / revocado = información interna).
- ❌ Un muro `SUSPENDED` / `LockedUntil` **antes** de probar credenciales (rompe los tres momentos).
- ❌ Crear sesión para `SUSPENDED` / `DEACTIVATED` (viola D-c: *autenticado ≠ admitido*).
- ❌ Un estado `PENDING` de identidad entre aceptar y trabajar (añade un clic administrativo inútil).
- ❌ Selector de empresa / cambiar tenant / crear workspace en la UX inicial (rompe forward-compat).
- ❌ Alta libre dentro de una empresa existente sin invitación (rompe invitation-first).
- ❌ Botón de submit sin estado de carga, o que permita doble envío, o que pierda lo tecleado al fallar.
- ❌ `no-reply@` como remitente; emails tipo newsletter con imágenes/banners.
- ❌ `maxLength` en inputs (truncado silencioso); los límites van en el esquema Zod (`.max()`).
- ❌ Guardar el JWT / secretos / PII en `localStorage`/`sessionStorage` (la sesión es httpOnly).
