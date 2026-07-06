# Validation Report — ERPify (experiencia de acceso / identidad)

- **DESIGN.md:** `./DESIGN.md`
- **EXPERIENCE.md:** `./EXPERIENCE.md`
- **Run at:** 2026-07-06

## Overall verdict

Par de espinas **sólido y extraíble** como contrato aguas abajo: el rubric walker resuelve token-a-token y
fichero-a-fichero sin ninguna referencia rota, los seis componentes están doble-especificados (visual +
comportamiento) y los journeys están íntegros — **0 críticas, 0 high** en cobertura. Las lentes adversariales
sí desplazan el cuadro: **seguridad** encuentra que el invariante estrella (indistinguibilidad) se redujo a
*paridad de copy* y deja abiertos los canales que un atacante mide de verdad (timing / status / shape) más la
higiene del token-en-URL; **accesibilidad** encuentra dos fallos de gestión de foco a nivel de espec (uno es una
trampa de teclado WCAG-A). Todo es **cerrable con ediciones quirúrgicas** — el modelo de dominio (4 máquinas +
2 invariantes) aguanta; lo que falla es que los invariantes están *infra-especificados*, no equivocados.

## Category verdicts (rubric walker)

- Flow coverage — **strong**
- Token completeness — **strong**
- Component coverage — **strong**
- State coverage — **adequate**
- Visual reference coverage — **strong** (mocks aún no renderizados — paso Finalize pendiente)
- Bloat & overspecification — **adequate**
- Inheritance discipline — **strong**
- Shape fit — **strong**

## Findings by severity

### Critical (0)

_Ninguna._

### High (4)

**[Seguridad] Indistinguibilidad = solo paridad de copy** (`EXPERIENCE.md` § Invariante de seguridad)
Un email inexistente cortocircuita (no hashea) y responde rápido; un email real con contraseña errónea corre
argon2 y responde lento → **enumeración por timing** intacta pese al mensaje neutro, más divergencia de
status-code / response-shape (`401` vs `200`+redirect al muro `SUSPENDED`).
Fix: el invariante debe **declarar** que la indistinguibilidad incluye *timing, status y shape*, y entregar al
ADR el requisito de **camino de tiempo constante** + status/forma uniformes.

**[Seguridad] `?token=` en la URL sin contrato de higiene** (`EXPERIENCE.md` § Interaction Primitives)
El `Referrer-Policy: strict-origin-when-cross-origin` global **sigue filtrando el token completo** en
subpeticiones same-origin (`/api`, túnel `/monitoring` de Sentry), en el historial del navegador y en los logs
de acceso de Caddy → toma de cuenta, amplificado por D-b (reset limpia `LockedUntil`).
Fix: declarar el contrato del token — un solo uso + caducidad corta, **nunca** logueado ni en `?next=`/referrer,
consumido en servidor y **retirado de la URL** tras validarlo; `Referrer-Policy` reforzado en rutas con token.

**[Accesibilidad] "Trampa de foco dentro de la tarjeta" en superficies no-modales** (`EXPERIENCE.md` §
Accessibility Floor / Interaction Primitives)
`TokenActionScreen` / `AccessWall` son **documentos primarios, no diálogos**: un focus-trap literal es una
trampa de teclado **WCAG 2.1.2 (nivel A)** que deja al usuario de tecnología asistiva atrapado y le impide
alcanzar el `Logo` / `ThemeToggle`.
Fix: eliminar el "trap"; especificar orden de foco natural y que Logo/Theme sean alcanzables. El foco-trap solo
aplica a `Dialog` reales (confirmaciones destructivas).

**[Accesibilidad] Sin gestión de foco en las transiciones de éxito / post-login** (`EXPERIENCE.md` § Key Flows /
Component Patterns)
En una SPA, tras aceptar invitación / reset / login el foco no se reubica → el usuario de lector de pantalla
pierde el sitio y **se pierde la confirmación `SecuritySignal`**.
Fix: al navegar a éxito/muro, mover el foco al encabezado del `SecuritySignal` (o al landmark de la nueva
superficie) y anunciarlo.

### Medium (18 — las distintas)

- **[Rubric] B1 Login sin enumeración de estados por-superficie ni journey propio** (`EXPERIENCE.md` IA /
  State Patterns) — la superficie más usada obliga a ensamblar su máquina de estados desde ≥4 secciones.
  Fix: una tabla de estados de B1 (idle / enviando / credenciales-inválidas / lockout / offline / error técnico).
- **[Rubric] C1 `AccessWall` sin lugar de render para los muros post-identidad** (`EXPERIENCE.md` IA) — el caso
  token-inválido es claro; dónde se renderiza `SUSPENDED`/`LockedUntil`/`DEACTIVATED` no. Fix: nombrar la ruta o
  el mecanismo de render post-login.
- **[Accesibilidad] Enlace de email en dark mode falla AA** — `{color.accent}` inline ≈ `#2f5cd9` ≈ 2.5:1 sobre
  `surface-dark`. Fix: rama oscura del enlace (`~#6c9bff`), igual que se hizo con surface/text.
- **[Accesibilidad] `aria-live` polite vs assertive** — fallos reales de submit deben ser *assertive*; el
  `OfflineNotice` ambiental, *polite*. Ninguno mueve el foco. Fix: separar los dos registros.
- **[Accesibilidad] Semántica de encabezados sin fijar** — el título del `TokenActionScreen` debe ser `<h1>`
  elemento; `SecuritySignal` "Heading 3" puede saltar niveles. Fix: pinchar niveles.
- **[Accesibilidad] Autofocus al campo salta el contexto de página** para SR que llega desde el email; y área de
  toque del toggle mostrar/ocultar sin fijar (2.5.8). Fix: anunciar contexto antes de autofocus; toggle ≥ target.
- **[Seguridad] Contrato de sesión sin declarar** — CSRF + **regeneración de sesión** en los POST `INVITED→ACTIVE`
  y de login; **reset revoca TODAS las sesiones** (no solo "las demás"); rate-limit en login/forgot/reset/accept;
  muro `SUSPENDED` entregado **sin** sesión parcial. Fix: subsección "lo que el invariante exige del backend → ADR".
- **[Editorial] Códigos-ancla `E*` colgando** (`EXPERIENCE.md`, y 2 fugas de `E6.1` en DESIGN.md) — no resuelven
  a ningún destino. Fix: eliminar la serie `E*`; conservar `D-a/D-b/D-c` (definidos inline).
- **[Editorial] Microcopy de `OfflineNotice` divergente** (3 literales para una misma cadena). Fix: unificar a uno.
- **[Editorial] `§ Señales de seguridad visibles` casi duplica `§ {SecuritySignal}`; `§ Modelo de sesión`
  reafirma la máquina 4.** Fix: dejar una canónica + referencia cruzada.

### Low (22)

Repartidas: rubric 9 (matices de densidad/DRY), accesibilidad 4 (`{color.security}` como texto no-AA; contrato
a11y del email — texto de enlace descriptivo, `lang`, fallback de URL plana; referente abstracto "tu
administrador"; CTA de landing delegado a la superficie marketing), seguridad 4 (remitente no-`no-reply` + riesgo
de responder con datos; token en email restated; storage httpOnly confirmado), editorial 5 (D-a re-argumentado
3×; meta "proyección, no fuente de verdad" 3×; lista de remitentes `hola@` off-brand; refrán "6:30 mismo
producto" duplicado; literal `password-changed` desalineado con el split J2/J6). Detalle en los `review-*.md`.

## Decisiones que los revisores devuelven

- **Campo único de contraseña (triaje #1):** accesibilidad recomienda **mantener el campo único** (un confirm
  enmascarado a re-teclear a ciegas en móvil daña más a low-vision/motor/SR) y **endurecer** con: (1) toggle por
  defecto **revelado** en pantallas de token (se *crea* la contraseña, no se protege un secreto existente); (2)
  toggle grande con `aria-pressed`; (3) exponer el **salvavidas**: tras aceptar, la identidad ya es `ACTIVE`, así
  que un error tipográfico se recupera vía **forgot-password**, no re-invitación.
- **Códigos-ancla (editorial):** eliminar `E*`, conservar `D-a/D-b/D-c`.

## Reviewer files

- `review-rubric.md`
- `review-a11y.md`
- `review-security.md`
- `review-editorial.md`
