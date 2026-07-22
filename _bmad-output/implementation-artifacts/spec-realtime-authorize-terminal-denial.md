---
title: 'Realtime authorize: tratar 401/403 como denegación terminal'
type: 'bugfix'
created: '2026-07-22'
status: 'done'
review_loop_iteration: 0
baseline_commit: 'e9476575'
context:
  - '{project-root}/pwa/CLAUDE.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Con un `EventSource` ya abierto, si la autorización deja de concederse —sesión expirada (401) o rol
revocado en caliente vía `PATCH /backoffice/users/{id}/status` o `/roles` (403)— la PWA reautoriza en bucle:
`EventSource` reconecta solo, `onerror` dispara `onError()` cada 30 s, y el `authorize` condenado muere en un
`telemetry.warn`. La pestaña machaca la API indefinidamente, cada intento genera una denegación auditada, el
usuario no ve nada, y el 401 nunca rebota a `/login` porque este `authorize` es el **único** HTTP de la PWA con
`fetch` global crudo en vez del puerto `HttpClient` (sin `problem.status`: el status solo se recupera parseando
el mensaje de error).

**Approach:** Enrutar el `authorize` por el puerto `HttpClient` y clasificar por `HttpError.problem.status`:
**401/403 son terminales** (ningún reintento las cambia) → cerrar la suscripción para que `EventSource` deje de
reconectar; el resto de fallos conserva el reintento best-effort. El contrato HTTP de la API no cambia.

## Boundaries & Constraints

**Always:**
- `authorize` va por `container.get<HttpClient>("HttpClient").get<void>(path)` — el puerto ya tolera cuerpo vacío
  en cualquier 2xx sin guard, así que un `204` no exige método nuevo.
- Clasificar sobre `error instanceof HttpError && error.problem.status` (constantes de `HttpStatus`), nunca texto.
- Terminal ⇒ `close()` de la suscripción + un único `telemetry.warn`. No-terminal ⇒ comportamiento actual intacto.
- El arreglo vive en `useMercureRealtime`: bank y bank-account lo heredan sin tocar sus adaptadores.
- El fallo sigue siendo silencioso para el usuario (contrato vigente del hook).

**Ask First:**
- Cambiar la firma del puerto `HttpClient` o de `MercureSubscriber`.
- Exponer estado de "realtime no disponible" a la UI (patrón nuevo: hoy `<Can>` gatea JSX, no capabilities de fondo).
- Tratar como terminal cualquier status distinto de 401/403.

**Never:**
- Tocar el gate de la API: `#[IsGranted]` en los dos `RealtimeAuthorizeController` se queda. El 403 está pinneado
  en Behat y lo audita `AccessDeniedAuditListener`; degradarlo a `204` borraría esa señal de seguridad.
- Cambiar `REAUTHORIZE_DEBOUNCE_MS` ni el auto-reconnect nativo de `EventSource`.
- Reintentar tras un terminal, ni con backoff.
- Tocar los otros 6 action items de la retro `users-admin`.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Autorizado | `authorize` → 204 | Se abre el stream; eventos llegan a `onEvent` | N/A |
| Denegado al montar | `authorize` → 403 | No se abre el stream (como hoy) | `telemetry.warn("subscription skipped")` |
| Rol revocado en caliente | Stream abierto → `onError` → 403 | Suscripción **cerrada**; ningún `authorize` posterior | Un solo `telemetry.warn` |
| Sesión expirada | Stream abierto → `onError` → 401 | Suscripción cerrada; rebote a `/login` | Un solo `telemetry.warn` |
| Blip transitorio | Stream abierto → `onError` → 503 / red | Suscripción **viva**; reintento debounced disponible | `telemetry.warn`, sin cerrar |
| Desmontaje en vuelo (montaje) | Desmonta durante el `await` inicial | No se abre stream (guarda `cancelled` actual) | Sin telemetría de terminal |
| Desmontaje en vuelo (terminal) | Desmonta mientras el refresco está en vuelo → 403 | El cleanup ya cerró; el camino terminal no reabre ni re-cierra sobre un handle ajeno | Sin efecto observable |
| `onError` concurrentes | 2+ señales antes de resolverse un refresco | La primera que resuelve terminal cierra; las demás no emiten `authorize` nuevo | Un solo `telemetry.warn` |

</frozen-after-approval>

## Code Map

- `pwa/src/context/shared/real-time/infrastructure/useMercureRealtime.ts` — `authorize()` con `fetch` crudo
  (l.29-42); `refreshAuthorization` traga el error (l.92-97); el efecto posee `subscription` (l.105-136).
- `pwa/src/context/shared/real-time/infrastructure/BrowserMercureSubscriber.ts` — `onerror` debounced a 30 s
  (l.72-80). **No se modifica**: el bucle se corta cerrando la suscripción.
- `pwa/src/context/shared/http-client/domain/HttpClient.ts` — puerto, `get<T>(url, validate?)`.
- `pwa/src/context/shared/http-client/domain/{HttpError,HttpStatus}.ts` — `problem: ProblemDetails`; `UNAUTHORIZED`/`FORBIDDEN`.
- `pwa/src/context/shared/http-client/infrastructure/FetchHttpClient.ts` — `request()` rebota el 401 (l.132-134);
  `parseBody` tolera cuerpo vacío sin guard (l.214-220); `toHttpError` (l.293).
- `pwa/src/context/shared/dependency-injection/infrastructure/Container.ts` — `container.get<T>("HttpClient")`.
- `pwa/tests/context/shared/real-time/infrastructure/useMercureRealtime.test.ts` — 6 tests; el primero mockea
  `fetch` global con 401 → hay que reapuntarlo.
- `api/features/backoffice/bank/access_control.feature` — sin escenarios de `realtime/authorize`; el gemelo
  `bank_account/access_control.feature:105-115` sí los tiene (401 anónimo, 403 sin permiso).

## Tasks & Acceptance

**Execution:**
- [x] `pwa/src/context/shared/real-time/infrastructure/useMercureRealtime.ts` — cambiar `authorize()` al puerto
      `HttpClient` inyectado: recupera Problem Details, `correlation-id`, `problem.status` y el rebote a `/login`,
      y devuelve el hook al DIP del que hoy es la única excepción de la PWA.
- [x] `pwa/src/context/shared/real-time/infrastructure/useMercureRealtime.ts` — clasificar 401/403 como terminal y
      cerrar la suscripción en el camino de reconexión — es lo que corta el bucle de 30 s.
- [x] `pwa/tests/context/shared/real-time/infrastructure/useMercureRealtime.test.ts` — reapuntar el mock al
      `HttpClient` y cubrir la matriz completa (403 al montar; 403/401 en reconexión → cierre y sin reintento;
      503 → vivo; `onError` concurrentes → un solo cierre; desmontaje con refresco en vuelo); hoy la cobertura del
      camino denegado es cero.
- [x] `api/features/backoffice/bank/access_control.feature` — añadir los dos escenarios de
      `/backoffice/banks/realtime/authorize` espejo de los de bank-account: pinnea el contrato de servidor que esta
      spec decide NO cambiar, para que un agente futuro no lo "arregle" a 204.
- [x] `pwa/tests/context/shared/real-time/infrastructure/BrowserMercureSubscriber.test.ts` — no previsto: su test
      de origen compartido asertaba el `fetch` global del authorize. Reapuntado al nuevo dueño de esa URL
      (`FetchHttpClient`) en vez de borrado — la invariante «EventSource y authorize resuelven el mismo origen con
      una base padded» sigue viva, ahora contra el par real.

**Acceptance Criteria:**
- Dado un feed abierto, cuando se revoca el rol y el hub tira el stream, entonces se emite como mucho un
  `authorize` más y ninguno después — el número de peticiones deja de crecer con el tiempo.
- Dado un feed abierto, cuando expira la sesión, entonces el usuario acaba en `/login` en vez de en una pestaña
  muda reautorizando.
- Dado un usuario `AUDIT_READER` (único rol sin `bank.read`), cuando llama a `/backoffice/banks/realtime/authorize`,
  entonces la API responde 403 `forbidden` — igual que antes de este cambio.
- Dado un blip de red con el stream abierto, cuando se recupera, entonces el feed sigue vivo y reconcilia por
  `onReconnect` — sin regresión.
- Dadas varias señales `onerror` llegando antes de completarse una reautorización, cuando la primera resuelve
  401/403, entonces la suscripción se cierra **exactamente una vez**, no se reabre, y no se emite ningún
  `authorize` posterior.

## Design Notes

**Por qué 401/403 y no todo fallo.** Un 5xx o un fallo de transporte son transitorios: el reintento debounced es
justo la recuperación para la que se escribió. Un 401/403 es una decisión de autorización estable — insistir solo
cuesta peticiones y ruido de auditoría.

**Por qué cerrar y no un flag.** `EventSource` reconecta solo; mientras el objeto viva, `onerror` seguirá
disparando. Un flag "no reautorices más" dejaría el socket reconectando contra un hub que nunca le entregará nada.
`close()` es la única parada real, y el `close()` del cleanup ya es idempotente. Forma orientativa:

```ts
onError: () => {
  void refreshAuthorization().then((shouldClose) => {
    if (shouldClose && !cancelled) { subscription?.close(); subscription = undefined; }
  });
},
```

**Por qué un booleano de acción y no un tipo de resultado.** Un `AuthorizationResult` de tres estados
(`authorized` / `terminal` / `retryable`) se descartó: `authorized` y `retryable` producen **la misma** acción en
el único consumidor —no cerrar—, así que el tipo modelaría un estado que nadie distingue (abstracción sin caller,
Regla de Tres). El nombre resuelve la ambigüedad al mismo coste: `shouldClose` declara la acción, no el estado.
Si un segundo consumidor llegara a necesitar separar «autorizado» de «reintentable», ese es el momento del tipo.

**La condición `!cancelled` es load-bearing.** El desmontaje durante un refresco en vuelo ya ejecutó el `close()`
del cleanup; sin la guarda, el camino terminal operaría sobre un handle que la limpieza ya dio por muerto. Hoy
sería benigno (`EventSource.close()` es idempotente), pero el contrato no debe apoyarse en esa benignidad.

**`credentials`.** El `fetch` crudo fuerza `include`; `FetchHttpClient` usa el default `same-origin`. No es
regresión: toda la PWA autenticada ya va por ese cliente y el hub es same-origin por diseño.

## Verification

**Commands:**
- `make pwa.test.unit c='tests/context/shared/real-time/infrastructure/useMercureRealtime.test.ts'` — verde con los casos nuevos.
- `make pwa.test.unit` — sin regresiones (los tests de página mockean `useBankRealtime`).
- `make pwa.quality` — ESLint + Prettier limpios.
- `make php.behat c='features/backoffice/bank/access_control.feature'` — verde con los dos escenarios nuevos.

**Manual checks:**
- Stack arriba, lista de bancos abierta: cambiar los roles del usuario a solo `AUDIT_READER` desde otra sesión
  ADMIN y comprobar en Network que `realtime/authorize` deja de repetirse cada 30 s.

## Suggested Review Order

**La decisión: qué es una denegación terminal**

- El corazón del cambio: 401/403 = decisión de autorización estable, nada más lo es.
  [`useMercureRealtime.ts:56`](../../pwa/src/context/shared/real-time/infrastructure/useMercureRealtime.ts#L56)

- Dónde se corta el bucle: cerrar es la única parada real; las tres guardas en orden.
  [`useMercureRealtime.ts:163`](../../pwa/src/context/shared/real-time/infrastructure/useMercureRealtime.ts#L163)

- Mensajes distintos por clase: telemetría coalesce por (level, scope, message).
  [`useMercureRealtime.ts:120`](../../pwa/src/context/shared/real-time/infrastructure/useMercureRealtime.ts#L120)

**El cambio de puerto (DIP) y su contrapartida**

- Del `fetch` global al puerto inyectado: de ahí salen `problem.status` y el rebote a `/login`.
  [`useMercureRealtime.ts:46`](../../pwa/src/context/shared/real-time/infrastructure/useMercureRealtime.ts#L46)

**El contrato de servidor que NO cambia**

- 403 pinneado, y la mitad que importa: un rechazo no mintea cookie.
  [`access_control.feature:73`](../../api/features/backoffice/bank/access_control.feature#L73)

- Cierra la asimetría con bank-account: el anónimo sigue siendo 401.
  [`access_control.feature:57`](../../api/features/backoffice/bank/access_control.feature#L57)

**Documentación del comportamiento nuevo**

- La descripción durable del hook (y su enlace roto `RealTime` → `real-time`).
  [`architecture-pwa.md:100`](../../docs/architecture-pwa.md#L100)

- Guía para agentes: la denegación terminal, junto al resto del contrato de telemetría.
  [`pwa/CLAUDE.md:124`](../../pwa/CLAUDE.md#L124)

**Tests**

- El caso con disparador vivo: rol revocado / sesión expirada abandonan el stream.
  [`useMercureRealtime.test.ts:155`](../../pwa/tests/context/shared/real-time/infrastructure/useMercureRealtime.test.ts#L155)

- El guard no puede quedar enganchado ni con un sink de telemetría que lanza.
  [`useMercureRealtime.test.ts:180`](../../pwa/tests/context/shared/real-time/infrastructure/useMercureRealtime.test.ts#L180)

- Desmontaje con refresco en vuelo: ni reabre, ni re-cierra, ni reautoriza.
  [`useMercureRealtime.test.ts:222`](../../pwa/tests/context/shared/real-time/infrastructure/useMercureRealtime.test.ts#L222)

- Errores concurrentes colapsan en un solo authorize.
  [`useMercureRealtime.test.ts:199`](../../pwa/tests/context/shared/real-time/infrastructure/useMercureRealtime.test.ts#L199)

- Paridad de origen, reconducida por el hook para que siga guardando lo que promete.
  [`BrowserMercureSubscriber.test.ts:152`](../../pwa/tests/context/shared/real-time/infrastructure/BrowserMercureSubscriber.test.ts#L152)
