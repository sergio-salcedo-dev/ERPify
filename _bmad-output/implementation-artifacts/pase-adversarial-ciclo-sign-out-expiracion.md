---
title: Pase adversarial — ciclo de sign-out y expiración de sesión (PWA)
status: done
issues: [795, 798, 786, 772, 784, 796, 797]
pr: 819
date: 2026-08-21
---

# Pase adversarial — ciclo de sign-out y expiración de sesión

Registro del pase hostil exigido por `CLAUDE.md` § «Security review on every change → Process»
sobre el cambio de la rama `claude/close-multiple-github-issues-tjkq6i`.

## Honestidad sobre el orden

**La regla exige que este registro exista ANTES de `gh pr create`, y ese orden no se cumplió.**
El PR [#819](https://github.com/sergio-salcedo-dev/ERPify/pull/819) se abrió desde la UI de Claude
Code, fuera de la sesión que hacía el trabajo, mientras los tres lectores hostiles seguían
corriendo. No es el patrón de #616/#620 (el pase llegó en un PR posterior) ni el de #770 (el
registro llegó nueve minutos tarde en el mismo PR): aquí el pase estaba _en vuelo_ y el PR se abrió
por un camino que la sesión no controla. Es exactamente el hueco que
[#815](https://github.com/sergio-salcedo-dev/ERPify/issues/815) describe — el gate no ve un PR
abierto fuera de la sesión — y es la cuarta ocurrencia que este párrafo anticipaba. Se registra
como incumplimiento, no como cumplimiento.

Lo que sí se sostiene: el pase se ejecutó **antes de que ningún hallazgo se convirtiera en código**,
y los arreglos de abajo llegan en el mismo PR, no en uno de seguimiento.

## Cómo se ejecutó

Tres lectores hostiles independientes, **en contexto fresco y sin haber escrito el código**, cada
uno con una lente distinta y sin ver los informes de los otros:

| Lente                     | Alcance                                                                                          |
| ------------------------- | ------------------------------------------------------------------------------------------------ |
| Seguridad y privacidad    | open redirect, sesión/auth, CSRF, contaminación de cabeceras, abort/DoS, fuga de información     |
| Corrección y casos límite | carreras, latches, temporizadores, StrictMode, SSR/hidratación, **vacuidad de los tests nuevos** |
| Accesibilidad y contrato  | anuncios reales de lector de pantalla, foco, y radio de impacto del cambio de puerto             |

Dos de ellos midieron sus hallazgos contra el código (un test scratch borrado después, y un
`checkout` de `HEAD~1` para comprobar vacuidad); el tercero midió el open redirect contra el
parser de URL. Devolvieron **4 GRAVE, 8 MODERATE y 9 MINOR**. El árbol quedó limpio.

---

## GRAVE

### G1 — Open redirect vivo post-login en `safeInternalPath` _(preexistente)_ — **ARREGLADO**

`String.trim()` quita TAB/LF/CR sólo en los **extremos**; el parser WHATWG los quita en
**cualquier posición**. Medido con `new URL(v, "https://app.example/x")`:

| entrada           | guard    | resuelve a          |
| ----------------- | -------- | ------------------- |
| `/<TAB>/evil.com` | **pasa** | `https://evil.com/` |
| `/<LF>/evil.com`  | **pasa** | `https://evil.com/` |
| `/<CR>/evil.com`  | **pasa** | `https://evil.com/` |

Vector: `…/login?next=/%09/evil.com`. `URLSearchParams.get()` decodifica `%09` a un TAB crudo antes
de que el guard lo vea, `safeHref` sólo bloquea esquemas con script, y el router de Next sale del
origen. **Dispara después de un login correcto**, que es cuando una página de phishing
«tu sesión expiró, vuelve a entrar» es más creíble.

Preexistente, pero entra en este PR porque el docblock de `hardNavigate` apoyaba **toda** su
seguridad en ese guard. Arreglado resolviendo contra un origen centinela y comparando —el control
autoritativo que `ApiUserSearchNavigator` ya usaba— en vez de enumerar formas con una regex. Tres
casos fijados; la regex antigua los pone rojos. Registrado en `PRODUCTION_SECURITY_CHECKLIST.md` §7.

### G2 — El presupuesto de petición no cubría el cuerpo — **ARREGLADO**

`clearTimeout` corría en el `finally` del `await fetch(...)`, y `fetch` resuelve con las
**cabeceras**. `res.text()` venía después, con el controlador ya sin referencias. Una respuesta
cuyas cabeceras llegan y cuyo cuerpo se queda colgado dejaba la promesa pendiente para siempre.

Cadena hasta el atasco: `logout(3000)` → `revokeCurrent` → cabeceras llegan → cuerpo se cuelga →
`logout()` nunca settlea → `hardNavigate` nunca se llama → `signOut` fijado en `LEAVING` de por vida
del documento. **Es la regresión estricta**: el `Promise.race` borrado sí acotaba la operación
entera. Reproducido con un test scratch (`ReadableStream` que nunca cierra).

Peor: mi propio test `"stops the clock once the response lands"` **fijaba el defecto** — asertaba
cero temporizadores en el instante en que llegan las cabeceras, o sea prohibía el arreglo.

Arreglado leyendo el cuerpo dentro del presupuesto (`request()` devuelve `{ res, raw }`), y el test
ahora asserta que el reloj para cuando llega el **cuerpo**. Añadido el caso del cuerpo colgado.

### G3 — La recuperación `refused`/`stalled` era inalcanzable en producción — **ARREGLADO**

`logout()` limpia la sesión en su `finally`; `RequireAuth` devuelve `null`; el `<output>` vive
dentro de `RequireAuth`. Al quitar el race, la navegación sólo ocurría **después** de que `logout()`
settleara, así que cuando `hardNavigate` invocaba el callback el árbol que debía anunciar ya no se
renderizaba. Los dos tests que «lo probaban» pasaban sólo porque el mock de `logout` nunca tocaba
`auth.status` — el diff ya contenía la refutación en un test preexistente.

Arreglado **restaurando el `Promise.race`**. No es una vuelta atrás: el bound del transporte cubre
una _petición_, el race cubre la _operación_, y es el race el que mantiene la sesión viva y el
subárbol montado — que es literalmente el escenario que #795 describe («logout() sigue pendiente»).
Los tests se reescribieron para la ordenación de producción (el presupuesto gana la carrera).

### G4 — `pagehide` de cualquier causa desarmaba el bound para siempre — **ARREGLADO**

`pagehide` afirma dos hechos distintos: `persisted: false` (documento descartado → la navegación
confirmó) y `persisted: true` (bfcache o _freeze_ — iOS Safari lo emite al mandar la app a segundo
plano), del que el documento puede volver. Tratar ambos como «confirmó» desarmaba el único bound del
caller en una interacción de móvil corriente: la reclamación de expiración no se soltaba nunca y la
aplicación quedaba **en blanco de forma permanente, sin salida** — justo lo que el argumento de
seguridad de la cortina dice que es imposible. Arreglado leyendo `persisted`; ambos sentidos fijados.

---

## MODERATE arreglados

- **A11y: `polite` para un error de acción y una alerta de sistema.** `DESIGN.md:484` exige
  `assertive`. El fallo de sign-out es ahora `assertive`; la cortina usa `role="alert"`.
- **A11y: el usuario vidente no recibía ninguna afirmación.** En `refused`/`stalled` la única salida
  era la región `sr-only`; en tres de tres superficies el menú se cierra sobre la entrada, y en la
  sidebar expandida una etiqueta que revierte no distingue «falló» de «nunca empezó» — la
  desestimación silenciosa que `pwa/CLAUDE.md` prohíbe. La región ahora es **visible** al fallar.
- **A11y: la cortina probablemente no anunciaba nada.** Una live region que se monta _ya con_ su
  contenido es el caso clásico de no-anuncio. Ahora es `role="alert"`, con `<h1>`, `<main>`, foco
  movido al contenedor y **un enlace a `/login`** — antes era un callejón sin salida de teclado
  durante 10 s, sin un solo elemento focalizable.
- **La cortina no suprimía los toasts, y mi comentario afirmaba que sí.** `<SonnerToaster/>` era
  _hermano_ de `<AuthProvider>` y la cola de Sonner es estado de módulo, así que un 401 durante un
  borrado de usuario pintaba «Couldn't erase user — see error details» **encima** de la cortina,
  apuntando a detalles recién desmontados. Movido dentro del boundary.
- **`MockHttpClient` era una implementación estale del puerto que seguía compilando** (aridad menor).
  Es el cliente que usa _todo_ test que pasa por el contenedor. Ahora declara `RequestOptions`.
- **El eslabón central del presupuesto no tenía test.** `AuthProvider.logout → revokeCurrent` era el
  único de cuatro saltos sin pin: borrar el argumento dejaba sign-out heredando 30 s en silencio,
  con vitest, eslint, dependency-cruiser y tsc verdes. Fijado en ambos sentidos.
- **El arreglo de #796 era asimétrico.** La sidebar de escritorio pasaba el modelo crudo a
  `SidebarItem` para los grupos, así que el mismo medio-soporte seguía vivo una superficie más allá.
  Una sola derivación (`withEntryState`) para todos los padres; cubierto por test.

## MINOR arreglados

- Valor de retorno muerto en `redirectToLoginOnSessionExpiry`, con un comentario que describía un
  diseño sustituido por la cortina → `void`.
- `signOut` nunca volvía a `IDLE`: el mensaje de fallo se quedaba en el árbol de accesibilidad el
  resto de la vida del documento → se limpia en la siguiente navegación.
- `hardNavigate` era un sumidero de navegación sin validar, contra la propia regla XSS de
  `pwa/CLAUDE.md` → ahora **rechaza** (no reescribe) un destino que no sea ruta interna.
- La justificación de seguridad `replace` vs `assign` (bfcache en máquina compartida) se había
  perdido al centralizar → restaurada en `hardNavigate`.
- Claves derivadas de la etiqueta en `accountLinks.map`, e iconos decorativos sin `aria-hidden`, en
  los bloques que este diff ya tocaba → arreglados (regla del boy scout).
- El test de la cortina dejaba estado global vivo (reclamación, presupuesto de 10 s y listener) →
  se libera dentro del test.
- Fixture `mockResolvedValue(makeResponse(...))` reutilizaba **una** `Response` entre llamadas; un
  cuerpo sólo se lee una vez, y el error lo tapaba un `.catch(() => null)` → una `Response` por
  llamada.

## Vacuidad de tests (medida contra `HEAD~1`)

- `"says nothing about a navigation that committed"` era **vacuo**: pasaba contra el código
  anterior. Sólo asertaba que la región seguía diciendo «Signing out…» tras `pagehide`, cosa que el
  código viejo también hacía por no tener presupuesto alguno. Reescrito con `persisted: false`, y
  añadido el caso que faltaba: presupuesto agotado **primero**, `pagehide` después.
- Los dos tests de recuperación no eran vacuos contra `HEAD~1` pero sí **respecto a producción**
  (G3). Reescritos.
- `"stops the clock once the response lands"` no era vacuo pero **fijaba G2**. Reescrito.

## Aceptado como residual, con su argumento

- **La cortina puede parpadear.** Tras soltar la reclamación por `not-committed`, el siguiente 401
  la vuelve a levantar: en un navigable que ignora navegaciones esto cicla. La alternativa es la
  deglución permanente que #786 registra. Sólo alcanzable donde la app ya está rota, y
  `frame-ancestors 'none'` hace ese entorno inalcanzable en el despliegue.
- **Un 401 concurrente puede correr contra la navegación de sign-out.** Preexistente: ambas rutas
  llamaban a `location.replace` de forma independiente antes de este cambio. → seguimiento.
- **El transporte importa `access/application`.** La dirección está invertida (un adaptador de
  infraestructura escribiendo en la capa de aplicación de otra capacidad). Propuesto, no resuelto:
  la forma natural es un puerto en `http-client/domain` al que `access` se suscriba. → seguimiento.
- **El namespace de `ProblemDetails.type` no tiene dueño.** Nada impide que un marcador futuro de la
  API reclame `request-timeout`. → seguimiento.
- **`ProblemDisplay` rotula `status: 0` como «No response»**, que contradice lo que
  `request-timeout` existe para decir. → seguimiento.
- **Breadcrumbs de Sentry no pasan por el scrub de query.** Preexistente y de otra clase. → seguimiento.
