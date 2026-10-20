---
title: 'DW-22 — un 503 de /me lleva a /maintenance, no a /login'
type: 'feature'
created: '2026-09-29'
status: 'done'
baseline_revision: '1305a5780fdf5158246d7f0e7d8440937e0e093f'
review_loop_iteration: 1
followup_review_recommended: false
context:
  - '{project-root}/pwa/CLAUDE.md'
  - '{project-root}/docs/rules/frontend.md'
warnings: ['oversized']
deferred:
  - summary: >-
      La sonda en frío de /me que se resuelve después de un login() puede pisar la sesión recién obtenida.
    evidence: |-
      AuthProvider aplica el resultado de la sonda inicial sin secuenciar contra login(); la forma es previa a DW-22 (antes pisaba con null, ahora también con unavailable). Solo ocurre si la sonda en frío tarda más que un login completo.
    location: >-
      pwa/src/context/shared/access/infrastructure/ui/AuthProvider.tsx
    severity: low
  - summary: >-
      Un re-sondeo de /me disparado por navegación puede pisar el resultado de un login() o logout() posterior.
    evidence: |-
      Misma causa raíz que la entrada anterior: AuthProvider no secuencia sus sondas (el efecto de ruta cancela solo la suya). Con UNAVAILABLE/HYDRATING RequireAuth no monta los controles de logout; el caso requiere un login() que resuelva antes que el re-sondeo lanzado al llegar a /login.
    location: >-
      pwa/src/context/shared/access/infrastructure/ui/AuthProvider.tsx
    severity: low
---

<intent-contract>

## Intent

**Problem:** `AuthProvider.resolveSession` captura cualquier fallo no-401 de `GET /me` y lo convierte en `null` → `UNAUTHENTICATED`, así que un corte del store de sesiones (el backend responde 503 `service-unavailable`) se presenta como «sesión requerida» y `RequireAuth` rebota a `/login`, que también da 503. Se pierde la distinción 401/503 que el backend construyó a propósito.

**Approach:** El adaptador `ApiIdentityRepository.me()` traduce un `HttpError` con estado 503 a un error de dominio propio (`IdentityUnavailableError`); `AuthProvider` lo distingue y publica un estado nuevo `AuthStatus.UNAVAILABLE`; `RequireAuth` redirige ese estado a `/maintenance` (nueva constante `Routes.MAINTENANCE`). Red y cuerpo malformado siguen en el camino actual (`UNAUTHENTICATED` → `/login`).

## Boundaries & Constraints

**Always:**
- La discriminación es por `problem.status === HttpStatus.SERVICE_UNAVAILABLE` (503), cualquiera que sea el `type` — un 503 de pasarela sin cuerpo RFC 9457 llega también como `HttpError` con estado 503 y merece la misma pantalla.
- `domain/` no importa infraestructura: el error nuevo es una clase `Error` pura en `access/domain/`.
- El contrato de `login()` no cambia: sigue resolviendo `Session | null`, `null` también en el caso 503 (los formularios de auth ya muestran un error reintentable con `null`).
- `RequireAuth` conserva el guard de `departing` también para la redirección a mantenimiento, y usa `router.replace` (no `push`).
- Toda cadena visible nueva en inglés; comentarios sin IDs de historia/regla.

**Never:**
- No tocar `FetchHttpClient`, el `SessionExpiryCurtain` ni el flujo 401.
- No reintentos automáticos ni polling de `/me` en esta historia.
- No cambiar el contenido de la página `/maintenance`.
- No editar `deferred-work.md` (lo cierra el orquestador).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| 200 | `/me` 200 válido | `AUTHENTICATED` | — |
| 401 | `/me` 401 | adaptador → `null`; `UNAUTHENTICATED`; `RequireAuth` → `/login?next=…` | — |
| 503 | `/me` 503 (`service-unavailable` o `about:blank`) | adaptador lanza `IdentityUnavailableError`; `UNAVAILABLE`; `RequireAuth` → `replace("/maintenance")`; sesión `null` | no se rebota a `/login` |
| red | `HttpError` status 0 / `Error` genérico | se propaga igual que hoy; `UNAUTHENTICATED` | camino actual |
| cuerpo malformado | `HttpError` `malformed-response-envelope` con status 200 | se propaga igual que hoy; `UNAUTHENTICATED` | camino actual |
| otro 5xx | `HttpError` 500/502 | se propaga igual que hoy; `UNAUTHENTICATED` | camino actual |
| login() con 503 | re-sondeo tras sign-in devuelve 503 | `login()` resuelve `null`; estado `UNAVAILABLE` | el formulario muestra su error reintentable |
| recuperación | 503 y luego `login()` con 200 | `AUTHENTICATED` (el flag de indisponible se limpia) | — |
| departing | estado `UNAVAILABLE` con una salida de documento reclamada | no redirige | — |

</intent-contract>

## Code Map

- `pwa/src/context/shared/access/infrastructure/ApiIdentityRepository.ts:76-92` -- `me()`: hoy mapea 401→`null` y relanza el resto; añadir la rama 503 → `IdentityUnavailableError` (con `cause` = el `HttpError`). Actualizar el docblock de la clase (líneas 54-71).
- `pwa/src/context/shared/access/domain/IdentityRepository.ts:17` -- documentar en el puerto que `me()` rechaza con `IdentityUnavailableError` cuando el servidor dice que no puede decidir (503).
- `pwa/src/context/shared/access/domain/` -- nuevo `IdentityUnavailableError.ts` (clase `Error` pura, `name` fijo). Precedente de forma: `http-client/domain/HttpError.ts`.
- `pwa/src/context/shared/access/infrastructure/ui/AuthProvider.tsx:18-30,95-104,118-123,161-166` -- `AuthStatus` gana `UNAVAILABLE`; `resolveSession` debe devolver un resultado que distinga «no hay sesión» de «servidor no disponible»; el veredicto de indisponibilidad se guarda **ligado a la ruta en la que se observó** (ver Design Notes) y no como un booleano; `useEffect` y `login()` lo fijan; `status` lo deriva en render. Docblock de `AuthStatus` actualizado. El provider se monta UNA vez en `pwa/src/app/layout.tsx:74` y su sonda en frío solo corre al montar: por eso un veredicto booleano quedaría pegado a toda navegación cliente posterior.
- `pwa/src/context/shared/error/infrastructure/ui/ErrorActions.tsx:46-90` -- las acciones de `/maintenance` («Return home» `Link`, «Go back» `router.back()`) son navegaciones CLIENTE: no remontan el provider (solo lectura).
- `pwa/src/context/shared/access/infrastructure/ui/RequireAuth.tsx:24-44` -- efecto: si `UNAVAILABLE` (y no `departing`) → `router.replace(Routes.MAINTENANCE)`; el render ya devuelve `null` para todo lo que no sea `AUTHENTICATED`. Docblock actualizado.
- `pwa/src/context/shared/routing/domain/Routes.ts` -- añadir `MAINTENANCE: "/maintenance"` con docblock.
- `pwa/src/app/(errors)/maintenance/page.tsx` -- la página destino (existente, solo lectura).
- `pwa/tests/context/shared/access/ApiIdentityRepository.test.ts` -- patrón `problem(status, type)` + `httpClientGetting` para los casos nuevos.
- `pwa/tests/context/shared/access/AuthProvider.test.tsx:99-106` -- patrón de rechazo de `me`; añadir casos 503 y recuperación.
- `pwa/tests/app/backoffice/backOfficeLayoutClient.test.tsx:27-50` -- patrón de mocks de `useSession`/`next/navigation` para un test nuevo de `RequireAuth`.
- `pwa/src/app/(auth)/_components/LoginForm.tsx:53-60` -- consumidor de `login()`; solo lectura, su contrato no cambia.
- `docs/api-error-contract.md:84` -- ya describe 401 → «the PWA route to sign-in preserving `?next=`» y el 503 del store como resultado distinto; añadir en esa frase que el PWA envía ese 503 a `/maintenance`.
- `docs/architecture-pwa.md:64` -- el registro durable del mapeo de `/me` (Decisión F/AC9): hoy describe un `AuthProvider` que siembra ADMIN en `localStorage`, ya falso; reescribir la frase del `AuthProvider`/`RequireAuth` con el mapeo real 200/401/503/resto.

## Tasks & Acceptance

**Execution:**
- `pwa/src/context/shared/access/domain/IdentityUnavailableError.ts` -- crear la clase de error de dominio -- que el puerto exprese «no se pudo decidir» sin filtrar HTTP.
- `pwa/src/context/shared/access/domain/IdentityRepository.ts` -- documentar el rechazo 503 -- el contrato del puerto es lo que lee `AuthProvider`.
- `pwa/src/context/shared/access/infrastructure/ApiIdentityRepository.ts` -- rama 503 → `IdentityUnavailableError`; docblock -- el adaptador es dueño del mapeo de estados.
- `pwa/src/context/shared/routing/domain/Routes.ts` -- `MAINTENANCE` -- sin literal suelto en el guard.
- `pwa/src/context/shared/access/infrastructure/ui/AuthProvider.tsx` -- estado `UNAVAILABLE` ligado a la ruta y re-sondeo al cambiar de ruta -- distinguir el 503 del «sin sesión» sin dejar el veredicto pegado tras el fin del corte.
- `pwa/src/context/shared/access/infrastructure/ui/RequireAuth.tsx` -- redirección a mantenimiento -- la superficie que ve el usuario.
- `pwa/tests/context/shared/access/ApiIdentityRepository.test.ts` -- 503 (`service-unavailable` y `about:blank`) → rechaza con `IdentityUnavailableError`; 500 y malformado siguen relanzando el `HttpError` original -- matriz del adaptador.
- `pwa/tests/context/shared/access/AuthProvider.test.tsx` -- 503 → `UNAVAILABLE` y sesión `null`; `login()` con 503 → `null` + `UNAVAILABLE`; 503 seguido de `login()` 200 → `AUTHENTICATED`; `UNAVAILABLE` seguido de `login()` 401 → `UNAUTHENTICATED`; `logout()` limpia el veredicto; el caso de red existente sigue `UNAUTHENTICATED`; **tras un 503, un cambio de `usePathname()` pone el estado en `HYDRATING` en el mismo render (antes de cualquier efecto) y re-sondea: 200 → `AUTHENTICATED`, 503 → `UNAVAILABLE` otra vez** -- matriz del provider y recuperación.
- `pwa/tests/context/shared/access/RequireAuth.test.tsx` -- `UNAVAILABLE` → `replace("/maintenance")` y nunca `/login`; `UNAUTHENTICATED` → `/login?next=…`; `UNAVAILABLE` con salida reclamada → sin redirección, y al liberarse la salida → `replace("/maintenance")`; `AUTHENTICATED` que pasa a `UNAVAILABLE` con el guard montado → `/maintenance`; el estado del mock se reinicia en `beforeEach` -- matriz del guard.
- `docs/api-error-contract.md` -- una cláusula en la frase de la línea 84 -- el contrato de error dice cómo reacciona el PWA a cada rama.
- `docs/architecture-pwa.md` -- reescribir la descripción del `AuthProvider`/`RequireAuth` con el mapeo real de `/me` -- registro durable de la Decisión F/AC9 refinada.

**Acceptance Criteria:**
- Given el store de sesiones caído y `/me` respondiendo 503, when un usuario abre una ruta bajo `RequireAuth`, then la app navega a `/maintenance` con `replace` y nunca a `/login`.
- Given un 503 ya observado y la API recuperada, when el usuario navega en cliente (sin recargar) a una ruta bajo `RequireAuth`, then el guard no redirige con el veredicto viejo: espera a una sonda nueva y muestra la ruta autenticada.
- Given `/me` falla por red o cuerpo malformado, when hidrata el provider, then el estado es `unauthenticated` y `RequireAuth` redirige a `/login?next=…` como hoy.
- Given `make pwa.quality` y `make pwa.test.unit`, when se ejecutan, then ambos terminan con exit 0.

## Spec Change Log

### 2026-09-29 — bucle 1 (bad_spec)
- **Hallazgo:** el veredicto `unavailable` era un booleano en un provider montado una sola vez en el layout raíz; tras un 503, cualquier navegación cliente a `/backoffice` (p. ej. «Return home»/«Go back» desde `/maintenance`) rebotaba a `/maintenance` aunque la API ya estuviera sana, hasta recargar (Blind Hunter + Edge Case Hunter; verificado en `pwa/src/app/layout.tsx:74` y `ErrorActions.tsx`).
- **Enmienda:** Code Map, Tasks, AC y Design Notes piden el veredicto ligado a la ruta (`unavailableAt`), `HYDRATING` derivado en render al cambiar de ruta y re-sondeo en efecto; tests de recuperación, de transición en el guard y de liberación de la salida; reinicio del mock en `beforeEach`; una cláusula en `docs/api-error-contract.md:84`.
- **Estado malo evitado:** usuario atrapado en `/maintenance` tras el fin del corte sin otra salida que recargar o volver a iniciar sesión.
- **KEEP:** `IdentityUnavailableError` (clase `Error` pura con `ErrorOptions`/`cause`, mensaje y docblock); rama 503 del adaptador por estado, cualquier `type`, con sus docblocks; `Routes.MAINTENANCE` con su docblock; el tipo `Probe` y el `catch` con `instanceof`; `login()` sigue devolviendo `Session | null`; la rama de `RequireAuth` tras el guard de `departing` con `router.replace(Routes.MAINTENANCE)`; la reescritura de `docs/architecture-pwa.md:64`; los tests del adaptador (`it.each` de `service-unavailable`/`about:blank` comprobando `cause`, 500/502 relanzados, malformado no convertido) y los del provider y del guard ya escritos.

## Review Triage Log

### 2026-09-29 — Review pass
- verdicts: 19 findings — high 0, medium 2, low 15, false 2, maybe-false 0
- findings:
  - `[medium]` `[bad_spec]` (Blind) `UNAVAILABLE` queda pegado hasta recargar; la navegación cliente desde `/maintenance` rebota — verificado: `AuthProvider` montado una vez en `pwa/src/app/layout.tsx:74`, sonda solo al montar, `ErrorActions` navega en cliente. Enmienda: veredicto ligado a la ruta (Spec Change Log, bucle 1).
  - `[low]` `[reject]` (Blind) se pierde el deep link en la ruta a mantenimiento — ruta de corte infrecuente; arreglarlo exige que `/maintenance` lea y valide un `next`, más que una corrección directa.
  - `[low]` `[reject]` (Blind) la copia «Scheduled maintenance» no describe un corte imprevisto — la intención nombra explícitamente «the existing /maintenance page».
  - `[false]` `[reject]` (Blind) `logout()` durante el corte manda a `/login` — con `UNAVAILABLE`, `RequireAuth` devuelve `null` y los controles de salida viven dentro de él (`BackOfficeLayoutClient.tsx:374`), así que no hay logout alcanzable en ese estado; y el logout reclama una salida a `Routes.HOME`.
  - `[low]` `[reject]` (Blind) 502/504 no se tratan como indisponible — la intención acota el cambio a «distinguish a 503»; el resto conserva el camino actual por decisión.
  - `[low]` `[reject]` (Blind) offline/red sigue yendo a `/login` aunque exista `/offline` — la intención lo excluye: «Network and malformed-body failures keep the current unauthenticated path».
  - `[low]` `[reject]` (Blind) la sonda fallida no deja telemetría — el `catch` mudo es previo; el 503 ya llega a Sentry en el servidor (`ServiceUnavailable` no es `ClientError`, `SessionStoreUnavailable.php:11-15`); añadirla es superficie nueva.
  - `[low]` `[reject]` (Blind) los formularios de sign-in no reaccionan a `UNAVAILABLE` — el usuario ya está en el formulario; en un corte del store el propio `POST /login` da 503 → `REQUEST_FAILED` (`ApiLoginRepository.ts:65`), y la ventana entre el POST y el re-sondeo es estrecha; el error reintentable es honesto.
  - `[low]` `[bad_spec]` (Blind) transiciones sin test (`UNAVAILABLE` → 401, liberación de la salida, `AUTHENTICATED` → `UNAVAILABLE` montado) — incorporadas a Tasks en el bucle 1.
  - `[low]` `[reject]` (Blind) sin E2E del 503 — la intención pide cobertura Vitest.
  - `[low]` `[bad_spec]` (Blind) `auth.status` del mock no se reinicia en `beforeEach` — incorporado a Tasks en el bucle 1.
  - `[medium]` `[bad_spec]` (Edge) sin re-sondeo tras el fin del corte — misma causa raíz que la primera fila; misma enmienda.
  - `[low]` `[defer]` (Edge) la sonda inicial que se resuelve tarde pisa una sesión recién obtenida por `login()` — forma de carrera previa (antes pisaba con `null`); requiere que la sonda en frío tarde más que un login completo.
  - `[false]` `[reject]` (Edge) un 503 cuyo cuerpo RFC 9457 trae otro `status` — `ProblemDetailsFactory` fija siempre el `status` del cuerpo al del response; no hay productor que lo contradiga.
  - `[low]` `[bad_spec]` (Edge) falta el test de liberación de la salida con `UNAVAILABLE` — incorporado a Tasks en el bucle 1.
  - `[low]` `[reject]` (Intent) se discrimina por estado 503 y no solo por el store — un 503 de pasarela `about:blank` rebotando a `/login` es exactamente el defecto; la decisión usa «store-unavailable» para describir el 503.
  - `[low]` `[reject]` (Intent) las páginas `(auth)` no redirigen a `/maintenance` — misma evidencia que la fila de los formularios de sign-in.
  - `[low]` `[bad_spec]` (Intent) el registro de la decisión no toca `docs/api-error-contract.md:84`, que describe la reacción del PWA al 401/503 — incorporado a Tasks en el bucle 1.
  - `[low]` `[reject]` (Intent) los tests viven en tres costuras unitarias y no en la app compuesta — la intención pide Vitest; cada costura fija su contrato y comparten la clase y el literal.

### 2026-09-29 — Review pass (bucle 1)
- verdicts: 31 findings — high 0, medium 0, low 26, false 5, maybe-false 0
- findings:
  - `[low]` `[reject]` (Blind) la copia «Scheduled maintenance» no describe un corte imprevisto — carried: la intención nombra «the existing /maintenance page».
  - `[low]` `[reject]` (Blind) se pierde el deep link al ir a `/maintenance` — carried: ruta infrecuente; exigiría que `/maintenance` lea y valide un `next`.
  - `[low]` `[reject]` (Blind) en `/maintenance`, un re-sondeo 200 no devuelve al usuario solo — la página es estática; la siguiente navegación del usuario ya funciona (arreglo del bucle 1); redirigir desde la página añade superficie.
  - `[low]` `[reject]` (Blind) una llamada `/me` extra al llegar a `/maintenance` — coste aceptado y documentado en Design Notes; una sola petición por navegación durante un corte.
  - `[low]` `[reject]` (Blind) 502/504/red siguen yendo a `/login` — carried: la intención acota a 503 y mantiene red en el camino actual.
  - `[low]` `[reject]` (Blind) sin telemetría en la sonda — carried: el 503 ya llega a Sentry en el servidor.
  - `[low]` `[patch]` (Blind) nada fija que el veredicto se selle con la ruta al RESPONDER — test añadido en `AuthProvider.test.tsx` (sonda pendiente, cambio de ruta, rechazo 503 → `UNAVAILABLE` en la ruta nueva, `me` una vez); contraprueba: sellar con la ruta de inicio lo pone rojo.
  - `[low]` `[reject]` (Blind) sin E2E — carried: la intención pide Vitest.
  - `[false]` `[reject]` (Blind) el adaptador lee el `status` del cuerpo — carried: `ProblemDetailsFactory` iguala siempre el `status` del cuerpo al del response.
  - `[low]` `[reject]` (Blind) `"/maintenance"` sigue literal en la galería de errores — la galería enumera como datos todas las rutas de error en literal; cambiar una sola rompe su homogeneidad sin daño evitado.
  - `[low]` `[patch]` (Blind) `docs/architecture-pwa.md` seguía diciendo «mocked IAM core» — corregido a «IAM core».
  - `[low]` `[patch]` (Blind) `pwa/docs/error-pages-testing.md` daba `/maintenance` como alcanzable solo por navegación directa — la celda nombra también el 503 de `/me` bajo una ruta guardada; `pwa/CLAUDE.md` («navigable `/maintenance`») sigue siendo cierto y no se toca.
  - `[false]` `[reject]` (Blind) la entrada del ledger no está cerrada — la tarea prohíbe editar el ledger; lo cierra el orquestador.
  - `[false]` `[reject]` (Edge) 503 con cuerpo de otro `status` — carried.
  - `[low]` `[reject]` (Edge) volver a la ruta sellada antes de que responda el re-sondeo intermedio reutiliza el veredicto — ventana de la latencia de una sonda; en cuanto responde la sonda de `/maintenance` el sello se mueve o se limpia.
  - `[low]` `[reject]` (Edge) `routeRef` se actualiza en efecto pasivo — ventana de microsegundos; la consecuencia es solo una sonda extra que acaba en el estado correcto.
  - `[low]` `[defer]` (Edge) un re-sondeo por navegación en vuelo puede pisar el resultado de `login()`/`logout()` — misma causa raíz que la carrera diferida en el primer pase (sin secuenciación de sondas); con `UNAVAILABLE`/`HYDRATING` los controles de logout del layout no están montados.
  - `[low]` `[reject]` (Edge) `?next=` perdido en la redirección a mantenimiento — carried.
  - `[low]` `[reject]` (Edge) `login()` devuelve `null` también con 503 — carried: contrato fijado; el formulario muestra error reintentable.
  - `[low]` `[reject]` (Edge) la AC de recuperación no vale si se vuelve a la ruta sellada dentro de la ventana — misma evidencia que la fila de la ruta sellada.
  - `[false]` `[reject]` (Edge) un 503 de pasarela con cuerpo discordante iría a `/login` — carried: no hay productor.
  - `[low]` `[patch]` (VerifGap) sellado por ruta de respuesta sin test — agrupado con la fila de Blind; mismo test.
  - `[low]` `[reject]` (VerifGap, other) timeout/504 siguen yendo a `/login` — carried: fuera de la intención.
  - `[low]` `[reject]` (Intent) la redirección vive en `RequireAuth`, que la intención no nombra — es el único componente que navega; la intención pide el resultado, no el sitio.
  - `[low]` `[reject]` (Intent) tests por costuras, sin composición — carried.
  - `[low]` `[reject]` (Intent) 503 por estado — carried.
  - `[low]` `[reject]` (Intent) 500/502 fijados a `/login` — carried.
  - `[low]` `[reject]` (Intent) el re-sondeo por navegación va más allá de la intención — necesario: sin él el usuario queda atrapado (medium del primer pase).
  - `[low]` `[reject]` (Intent) los docs no nombran «Decision F/AC9» — `docs/` registra decisiones, no el proceso; la resolución del ledger por el orquestador enlaza DW-22 con este cambio.
  - `[false]` `[reject]` (Intent) no consta `make pwa.quality` — corrió con exit 0 (ver Auto Run Result).
  - `[low]` `[reject]` (Intent) copia de la página de mantenimiento — carried.

## Design Notes

`resolveSession` pasa a devolver un resultado discriminado en vez de `Session | null`, para que el `catch` no vuelva a colapsar dos causas en un valor:

```ts
type Probe = { session: Session | null; unavailable: boolean };
// catch (error) { return { session: null, unavailable: error instanceof IdentityUnavailableError }; }
```

`status`: `!hydrated` → `HYDRATING`; `unavailable` → `UNAVAILABLE`; si no, ACTIVE → `AUTHENTICATED`, resto → `UNAUTHENTICATED`. `logout()` limpia el veredicto. Se descarta devolver un union desde el puerto (`Identity | null | "unavailable"`): obligaría a tocar cada doble de `me()` en los tests y mezclaría un fallo con un valor.

**Veredicto ligado a la ruta.** El provider vive en el layout raíz y su sonda solo corre al montar; `/maintenance` sale por navegación cliente, así que un booleano `unavailable` sobreviviría al corte y el guard rebotaría a `/maintenance` en cada vuelta hasta recargar. En su lugar se guarda `unavailableAt: string | null` (el `usePathname()` vigente cuando la sonda dijo 503). En render: `unavailableAt !== null && unavailableAt !== pathname` → `HYDRATING` (el guard no renderiza ni redirige); un efecto sobre `pathname` re-sondea en ese caso y fija `unavailableAt` a la ruta actual o lo limpia. Se deriva en render y no en un efecto porque los efectos del hijo (`RequireAuth`) corren antes que los del padre: un `setHydrated(false)` en efecto llegaría tarde y el guard ya habría redirigido con el veredicto viejo. No es polling ni reintento automático: re-sondea solo cuando el usuario navega, igual que haría una carga en frío. Coste medido por diseño: una llamada a `/me` extra al llegar a `/maintenance` durante el corte. `usePathname()` fuera del App Router (tests que montan el provider real sin mock de `next/navigation`) debe seguir funcionando: si devuelve `null`, trátalo como una ruta más.

## Verification

**Commands:**
- `make pwa.test.unit` -- expected: exit 0, incluidos los tests nuevos.
- `make pwa.quality` -- expected: exit 0 (ESLint, dependency-cruiser, Prettier, tsc).

## Auto Run Result

Status: done

**Resumen.** Un 503 de `GET /me` (cualquier `type`, incluido el 503 desnudo de una pasarela) ya no se presenta como «sin sesión»: el adaptador lo traduce a `IdentityUnavailableError`, `AuthProvider` publica `AuthStatus.UNAVAILABLE` y `RequireAuth` hace `router.replace("/maintenance")` en vez de rebotar a `/login`. Red, cuerpo malformado y otros 5xx siguen en `unauthenticated` → `/login?next=…`. El veredicto queda ligado a la ruta en la que se observó, así que tras el fin del corte la siguiente navegación cliente vuelve a sondear en lugar de rebotar a `/maintenance` (el provider vive en el layout raíz).

**Ficheros.**
- `pwa/src/context/shared/access/domain/IdentityUnavailableError.ts` — error de dominio nuevo (con `cause`).
- `pwa/src/context/shared/access/domain/IdentityRepository.ts` — contrato del puerto: 503 → `IdentityUnavailableError`.
- `pwa/src/context/shared/access/infrastructure/ApiIdentityRepository.ts` — rama 503.
- `pwa/src/context/shared/access/infrastructure/ui/AuthProvider.tsx` — estado `UNAVAILABLE`, `Probe`, veredicto `unavailableAt` derivado en render y re-sondeo por cambio de ruta.
- `pwa/src/context/shared/access/infrastructure/ui/RequireAuth.tsx` — redirección a `/maintenance` tras el guard de salida.
- `pwa/src/context/shared/routing/domain/Routes.ts` — `Routes.MAINTENANCE`.
- `docs/architecture-pwa.md`, `docs/api-error-contract.md`, `pwa/docs/error-pages-testing.md` — registro durable del mapeo refinado de `/me` (Decisión F/AC9), y corrección de una descripción falsa (sesión ADMIN sembrada en `localStorage`).
- Tests: `ApiIdentityRepository.test.ts`, `AuthProvider.test.tsx`, `RequireAuth.test.tsx` (nuevo); cinco tests de `app/backoffice/users/` ganan `usePathname` en su mock de `next/navigation`.

**Revisión.** Pase 1: 19 hallazgos → un `bad_spec` (medium: veredicto pegado hasta recargar) que forzó el bucle 1 con re-derivación; 1 diferido; el resto rechazado con motivo en el log. Pase 2: 31 hallazgos → 3 parches (low: test del sellado por ruta de respuesta, «mocked» en la doc, celda de la tabla de páginas de error), 1 diferido (misma causa raíz de carrera de sondas), 22 rechazados (motivos en el Review Triage Log), 5 `false`. Parches por veredicto en el pase final: high 0, medium 0, low 3.

**Seguimiento recomendado:** `false` — el pase final solo parcheó hallazgos `low`.

**Verificación.** `make pwa.quality` → exit 0; `make pwa.test.unit` → exit 0 (265 ficheros, 1964 tests). Contrapruebas del implementador: quitar la rama 503 / la redirección / el sellado por ruta de respuesta pone rojos los tests correspondientes.

**Riesgos residuales.** Sin comprobación en navegador ni E2E del 503 compuesto (la intención pide Vitest). La carrera de sondas sin secuenciar queda en `deferred`. La página `/maintenance` sigue diciendo «Scheduled maintenance» para un corte imprevisto, por decisión de reutilizarla tal cual.
