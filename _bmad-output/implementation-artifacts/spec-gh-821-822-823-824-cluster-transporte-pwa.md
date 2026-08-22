---
title: 'Cluster del transporte PWA: URLs en Sentry, un solo dueño de la salida, y quién acuña un `type` — #821, #822, #823, #824'
type: 'bugfix'
created: '2026-08-21'
baseline_commit: '3f8145c876aabb29125dbd99933ec6b388e0737d'
status: 'in-review'
review_loop_iteration: 1
context: []
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** los cuatro issues salen del pase adversarial de #819 y viven en el mismo sitio — el transporte, el pipeline de eventos de Sentry y la superficie de problem-details. **#821**: los breadcrumbs llevaban la URL completa con query a un sink sin ruta de borrado. **#822**: un 401 concurrente durante el sign-out lanzaba una segunda navegación contradictoria, y la curtain podía parpadear indefinidamente. **#823**: el transporte escribía en la capa de aplicación de otra capacidad. **#824**: nadie decidía quién puede acuñar un `ProblemDetails.type`, y la píldora de status 0 afirmaba lo contrario de lo que `request-timeout` existe para decir.

**Approach:** un solo claim de "salida en vuelo" con motivo, en `navigation/application`, que da dueño único a #822.1 y a #823 a la vez; el pase URL-aware sobre **todas** las superficies estructuradas del evento, no sobre una lista de las que se creía que llevan URLs; y para #824 documentación más un gate de colisión que deriva los nombres de las constantes.

## Boundaries & Constraints

**Always:** el contrato HTTP no cambia — un 401 sigue lanzando el mismo `HttpError`. Toda afirmación nueva se sostiene en una mutación del código que enrojece exactamente su fila. Las decisiones de arquitectura las decide el humano, no el revisor.

**Never:** ampliar el alcance a las otras familias de issues abiertos. Mergear a `main`.

**Ask First:** colapsar estado que vive en una PR ajena recién mergeada.

## Decisiones tomadas (humano)

1. **#821 — scrub URL-aware, no desactivar los breadcrumbs de fetch.** Descartadas: `breadcrumbsIntegration({ fetch: false })` (pierde el rastro de peticiones previas, que es cómo se triangula un error tragado por un boundary) y el strip total del query como en #803 (incoherente con el trato que `event.request.url` recibe en el mismo fichero).
2. **#822.1 + #823 — un claim único en `navigation`.** Descartadas: una bandera hermana en `access` (deja dos hechos donde el propio comentario del módulo dice que debe haber uno) y dejar #823 abierto.
3. **#822.2 — tope de 2 reclamaciones por documento.** Descartada: aceptarlo como residual apoyándose en que `frame-ancestors 'none'` hace el escenario inalcanzable.
4. **#824 — documentar y gatear la colisión.** Descartadas: prefijo `client:` (se lee como un esquema URI que no existe y aparece en la UI) y un registro dedicado en la raíz de `api` (la maquinaria de `.audit-resource-types` para un espacio con tres miembros de un lado).
5. **Colapsar `isSigningOut` en el claim.** `#826` aterrizó en `main` a mitad del trabajo con un booleano en `AuthContextValue` puesto y limpiado en las mismas dos líneas que el claim. Descartadas: convivir y documentarlo (dos hechos que sincronizar a mano), y colapsar al revés (`FetchHttpClient` es una función de módulo sin acceso a contexto React, así que exigiría sacar el estado de React igualmente).

</frozen-after-approval>

## Code Map

| Fichero | Qué hace |
|---|---|
| `pwa/src/context/shared/navigation/application/departure.ts` | El claim único, con motivo. Un hecho, tres lectores. |
| `pwa/src/context/shared/navigation/application/useDeparture.ts` | El lector React, vía `useSyncExternalStore`. |
| `pwa/src/context/shared/observability/infrastructure/scrubSentryEvent.ts` | Pase de denylist + pase URL sobre toda superficie estructurada. |
| `pwa/src/context/shared/http-client/infrastructure/FetchHttpClient.ts` | Reclama el motivo `session-expired`, tope de reclamaciones, y conserva el claim en el último intento. |
| `pwa/src/app/backoffice/BackOfficeLayoutClient.tsx` | Reclama `sign-out` antes de revocar. |
| `pwa/src/context/shared/access/infrastructure/ui/RequireAuth.tsx` | Lee el claim en vez de un booleano de contexto. |
| `pwa/src/components/erpify/ProblemDisplay.tsx` | La píldora de status 0 consulta el tipo. |
| `pwa/tests/client-minted-problem-types.test.ts` | El gate de colisión de tipos. |
| `docs/api-error-contract.md`, `PRODUCTION_SECURITY_CHECKLIST.md` §7, `CLAUDE.md` | Reglas y residuales. |

## Tasks & Acceptance

- Dado un evento con un breadcrumb de `fetch`, un span de `http.client` o `contexts.trace.data`, cuando lleva un identificador en el query, entonces sale con el valor en `REDACTED` y la ruta intacta.
- Dado un sign-out en vuelo, cuando otra petición devuelve 401, entonces no se programa una segunda navegación y la curtain no se levanta.
- Dadas dos navegaciones que no confirman, entonces el transporte deja de reclamar **y conserva el claim**, para que la curtain y su `Link` sigan siendo el camino de salida.
- Dado un `type:` en `api/src` que colisione con un nombre acuñado en cliente, entonces el gate falla nombrando fichero y valor.
- Dado `status: 0` con tipo `request-timeout`, entonces la píldora dice `Timed out`.

## Adversarial pass

**Contexto fresco, sólo-lectura, antes de que la PR existiera.** No participó en escribir el código. Leyó los ficheros tocados y además **las fuentes del SDK instalado** (`@sentry/core@10.70.0`, `@sentry/browser`, `@sentry/nextjs`) para establecer qué lleva realmente un evento, y ejecutó el `scrubSentryEvent` real contra 14 eventos construidos. **Un GRAVE, tres MODERATE y cinco MINOR**, todos con reproducción medida. No confirmó el cambio: lo amplió.

### F1 — GRAVE — la fuga que #821 existe para cerrar seguía abierta en `event.spans`

El pase URL apuntaba a `breadcrumbs` y a nada más. Pero `sentryInitOptions` cablea la misma función a `beforeSendTransaction`, `browserTracingIntegration` es integración **por defecto** con `traceFetch: true`, y `getFetchSpanAttributes` escribe la URL cruda en `url`, `http.url` y `url.full`, más el query pelado en `http.query` — cuatro atributos, en el 20 % de los fetch trazados en producción. El **nombre** del span sí lo sanea el SDK (`getSanitizedUrlStringFromUrlObject` vacía el `search`), y eso es exactamente lo que lo hacía fácil de pasar por alto; los atributos no. Medido antes del arreglo: un evento cuyo span era el que el SDK construye para la búsqueda de auditoría salía **idéntico**, con la dirección de correo en claro tres veces, mientras el mismo valor dentro de un breadcrumb del mismo evento salía `REDACTED`. `contexts.trace.data` tiene la misma forma y sólo tenía scrub por clave.

Agravante documental: §7 registraba el ítem como **cerrado** y nombraba `contexts` entre lo cubierto. Una fuga enumerable, demostrada y muestreada al 20 % no es un residual.

**Arreglado**, y no puntualmente: el pase corre ahora sobre **toda** superficie estructurada (`extra`, `contexts`, `user`, `breadcrumbs`, `spans`) en vez de sobre una lista de las que se creía que llevan URLs — nombrar las superficies es lo que produjo el defecto. `http.query` necesitó su propio brazo en la regla de forma, porque un query pelado no empieza ni por esquema ni por `/`. Tres filas nuevas, falsificadas por mutación.

### F2 — MODERATE — §7 describía un alcance que el código no tenía

Decía que `data.arguments` de un breadcrumb de consola *no* se reescribe y que reescribirlo se **descartó** porque destrozaría prosa con un `?`. Medido: `data.arguments` **sí** se recorre (es un array bajo `data`), y el destrozo que el documento citaba como razón para no hacerlo ocurría justo ahí — `"/help? what now"` → `"/help?+what+now="`. El documento describía un alcance ficticio y su argumento lo refutaba el propio código. **Reescrito** contra lo que el código hace.

### F3 — MODERATE — la garantía en el código sobre la curtain era falsa en dos caminos

El comentario del transporte afirma que lo que impide que la UI de error pinte durante la ventana de descarga es la curtain, «que lee el mismo claim». Pero la curtain ahora lee el **motivo**: durante un sign-out está abajo a propósito, así que un 401 concurrente sí pinta el error de su llamador; y pasado el tope no se reclama nada nuevo. **Arreglado el comentario**, nombrando las dos excepciones. Suprimir además la UI de error durante el sign-out se **difiere con issue**: blanquear la app destruiría la región de estado que anuncia la salida, y hacerlo bien significa tocar cada superficie de error — alcance que el usuario no pidió.

### F4 — MODERATE — el tope se gastaba la única salida del usuario

Rendirse **soltando** el claim era la grafía obvia y era la equivocada. La curtain, y el `Link` cliente a `/login` que lleva dentro, son lo único que le queda al usuario en un navegable que descarta navegaciones — y la entrada de Sign out sale por el mismo mecanismo descartado, así que también se atasca. Soltarlo se lleva la salida justo en el escenario para el que el tope existe. **Arreglado**: el último intento **conserva** el claim; la curtain se queda, su `Link` sigue enrutando en cliente, y no se intenta ningún rebote más — que era el objetivo, sin gastar la afordancia. Fila nueva, falsificada contra la grafía que el pase rechazó.

### F5 a F9 — MINOR, los cinco cerrados

- **F5** — la justificación de leer `latin1` en el gate era **falsa**: medidos los 598 `.php` con un decodificador estricto, ninguno es UTF-8 inválido (el veredicto `data` de `file(1)` me engañó). Y `latin1` falla en silencio hacia el falso negativo. Ahora lee `utf8` y **afirma** que los nombres son ASCII en vez de asumirlo.
- **F6** — la fila de colisión no tenía suelo: un `api/src` que exista pero no contenga `.php` la dejaba verde sin haber leído nada. Ahora cuenta los ficheros recorridos. La dependencia con el árbol hermano queda declarada en el docblock.
- **F7** — la regla que el gate impone es más fuerte que la documentada, y en una dirección que presiona al autor a mentir (marcar una constante que no es un tipo la mete en el check de colisión y en el de galería). **Declarada explícitamente** en el docblock, con la salida correcta: mover la constante, no marcarla.
- **F8** — dos grafías que **son** formas y escapan (URL relativa sin `/` inicial; query dentro del fragmento). Ninguna la produce esta aplicación hoy — `browserApiBase()` devuelve `""` — pero un script de terceros sí. **Registradas** como residual tres.
- **F9** — `scrubUrl` corrompía cadenas con forma de URL que no lo son (`%20` → `+`, `?` final descartado, prosa convertida en parámetro). **Arreglado**: sólo se reescriben los bytes del llamador si algo se redactó de verdad, que es la misma regla que `scrubNestedUri` ya aplicaba un nivel más abajo. Cuatro filas nuevas.

### Lo que revisó y encontró limpio

Que ningún camino deja el claim tomado y sin soltar, ni suelto por quien no lo tomó (construyó el solape transporte-gana/sign-out-pierde y no es un atasco); que el contador cuenta abandonos y no rebotes; que el aislamiento entre tests es real — la fila del 401 concurrente importa `departure` **después** del reset de módulos, así que comparte de verdad la instancia del cliente, y una instancia equivocada ahí fallaría, no pasaría; que `components/erpify` puede importar `@/context/shared` (permitido explícitamente por `eslint.config.mjs` y por `pwa/CLAUDE.md`, y el fichero ya lo hacía); que `MINTED_TYPE_CONST` no es catastróficamente backtrackable; que las cuentas que la documentación afirma son ciertas — exactamente cuatro `switch` sobre `problem.type` bajo `src/app/backoffice/**` y tres constantes acuñadas en cliente; que el truncado por `MAX_NODES` no rompe el pase (100 breadcrumbs cuestan ~901 nodos y los 100 sobreviven); y que no hay regresión en el anuncio del sign-out — de hecho mejora, porque antes un 401 concurrente levantaba la curtain y desmontaba ese `<output>` a mitad de anuncio.

### Coste conocido

El pase comprueba lo que puede leer y ejecutar. No observa un SDK real en vuelo, así que un campo fuera de las cinco superficies y de `request` sigue intocado por construcción, y eso es residual cuatro y no una garantía.

## Verification

```
npx vitest run          → 245 ficheros, 1481 tests verdes
npx tsc --noEmit        → 0
npx eslint .            → 0
npx depcruise src       → 0 violaciones (507 módulos, 1974 dependencias)
npx prettier --check .  → limpio
```

**No ejecutado, y no es lo mismo que verde:** `make php.quality`, `make php.stan` y los tests de PHP — este contenedor no tiene daemon de Docker y `composer install` muere con 403 contra GitHub a través del proxy (185 paquetes). El diff no toca `api/src`. Tampoco `pwa.test.e2e`, que necesita el stack levantado. Node es 22 aquí, no el 24/26 que pide `package.json`.

## Review Findings

Revisión adversarial en tres capas (Blind Hunter, Edge Case Hunter, Acceptance Auditor) sobre el diff de la PR #829, más verificación manual contra el código antes de puntuar. Ningún hallazgo del propio pase adversarial de la sección anterior (F1–F9) fue refutado; esto es una segunda pasada, posterior a la apertura de la PR.

- [x] **[Review][Decision] La rama partía de un `main` desactualizado y chocaba con `hardNavigate`'s single-flight de #830 — RESUELTO.** `baseline_commit` era `3f8145c8`; `main` real ya estaba en `b7dac57a`, dos commits por delante (`d4439b2d` #830 "make hardNavigate() single-flight...", `b7dac57a` #828). #830 añadió una rama `if (failure === "superseded")` a `BackOfficeLayoutClient.tsx` que llamaba a `isSessionExpiring()`/`setIsSigningOut(false)`, ambos eliminados por esta PR — `git apply --check` fallaba en 3 ficheros. **Decisión (Sergio): rebasar y reconciliar.** Reconciliado a mano contra `origin/main` (`b7dac57a`): la rama `superseded` ahora lee `currentDeparture() !== DepartureReason.SESSION_EXPIRED` (en vez de `!isSessionExpiring()`) para decidir si omite el toast, y ambas ramas (`superseded` y `refused`/`stalled`) liberan el claim con `if (claimed) releaseDeparture();` — solo quien ganó el claim de `departure` lo suelta; `hardNavigate`'s propio `claimedBy` se mantiene como mecanismo de defensa en profundidad para un tercer llamador hipotético, ya no puede ser disputado por los dos llamadores conocidos porque `departure` los serializa antes de que ninguno alcance `hardNavigate`. Se reconciliaron también los 2 ficheros de test con el mismo choque (`backOfficeLayoutClient.test.tsx`, `SessionExpiryCurtain.test.tsx`), incluida una migración de las pruebas de `#830` que aún usaban `beginSessionExpiry`/`endSessionExpiry`/`auth.isSigningOut` (módulo y campo ya eliminados) a `claimDeparture`/`releaseDeparture`/`currentDeparture`. Verificado tras la reconciliación: `npx tsc --noEmit` → 0, `npx eslint .` → 0, `npx depcruise src` → 0 violaciones, `npx prettier --check .` → limpio, `npx vitest run` → 245 ficheros, 1491 tests verdes (+10 sobre el recuento original: las pruebas de `#830` reconciliadas más una nueva — "stays down while the departure is a sign-out").

- [x] **[Review][Patch] `scrubRequest()` deja `request.data`/`headers`/`cookies` solo con el pase de denylist, nunca con el pase de forma-URL — APLICADO** [`pwa/src/context/shared/observability/infrastructure/scrubSentryEvent.ts`]. `scrubRequest` y `tryScrubJson` ahora llaman a `scrubStructured` (denylist + forma-URL) en vez de `scrubDeep` puro sobre `data`/`headers`/`cookies`; docblock y `PRODUCTION_SECURITY_CHECKLIST.md` §7 actualizados para nombrar la superficie. Dos filas nuevas en `scrubSentryEvent.test.ts`, falsificadas por mutación.

- [x] **[Review][Patch] `event.tags` no se scrubea en ningún pase — APLICADO** [`pwa/src/context/shared/observability/infrastructure/scrubSentryEvent.ts`]. `scrubSentryEvent` ahora aplica `scrubStructured` también a `event.tags`; docblock y checklist actualizados. Fila nueva en `scrubSentryEvent.test.ts`.

- [x] **[Review][Patch] 3 ficheros de test hermanos seguían mockeando un `setIsSigningOut` que ya no existe — APLICADO** [`pwa/tests/app/backoffice/backOfficeLayoutGroupSignOut.test.tsx`, `backOfficeLayoutRouteFocus.test.tsx`, `backOfficeLayoutSignOutIntent.test.tsx`]. Propiedad retirada de los tres mocks de `useSession`.

- [x] **[Review][Patch] `backOfficeLayoutRouteFocus.test.tsx` dejaba un claim de `departure` sin liberar en un camino real — APLICADO** [`pwa/tests/app/backoffice/backOfficeLayoutRouteFocus.test.tsx`]. `beforeEach`/`afterEach` ahora liberan el claim real (mismo patrón que `backOfficeLayoutClient.test.tsx`).

- [x] **[Review][Patch] La curtain de sesión expirada no distinguía "sigo reintentando" de "ya renuncié" — APLICADO** [`pwa/src/context/shared/access/infrastructure/ui/SessionExpiryCurtain.tsx`]. El copy pasa a afirmar un hecho ("Sign in again to continue.") en vez de una acción automática en curso, verdadero tanto durante el reintento como tras agotar `MAX_EXPIRY_BOUNCES` — sin plumbing nuevo. El estado terminal (el claim se mantiene, y con él el redirect ordinario de `RequireAuth` queda suprimido para el resto de la vida del documento) queda nombrado en un comentario junto a `MAX_EXPIRY_BOUNCES` en `FetchHttpClient.ts`.

Descartados como ruido o ya mitigados (sin acción): el escaneo de subcadenas del gate de colisión de `client-minted-problem-types.test.ts` (tradeoff ya reconocido en su propio comentario); el hueco de estilo de comillas en `EXPORTED_STRING_CONST`/`MINTED_TYPE_CONST` (inalcanzable — Prettier fuerza `singleQuote: false` en todo el repo vía `make pwa.quality`); la restricción de esquema de `scrubUrlLikeValue` (misma clase que el residual tres ya aceptado); el export de test `resetExpiryBounceBudget()` (nit de estilo); y la justificación del comentario sobre `useMercureRealtime` en `MAX_EXPIRY_BOUNCES` (especulativa, no afecta la corrección del tope).

**Verificación final tras F1–F6:** `npx tsc --noEmit` → 0, `npx eslint .` → 0, `npx depcruise src` → 0 violaciones (507 módulos, 1974 dependencias), `npx prettier --check .` → limpio, `npx vitest run` → 245 ficheros, 1494 tests verdes.
