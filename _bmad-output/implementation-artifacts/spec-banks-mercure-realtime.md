---
title: 'Sincronización en tiempo real de bancos vía Mercure'
type: 'feature'
created: '2026-05-31'
status: 'done'
baseline_commit: 'a07c8a0'
context:
  - '{project-root}/docs/project-context.md'
  - '{project-root}/docs/integration-architecture.md'
  - '{project-root}/docs-info/domain-events-and-messenger.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Cuando un usuario crea, edita o elimina un banco, los demás usuarios que tienen abierta la lista (`/backoffice/banks`) o el detalle (`/backoffice/banks/{id}`) no ven el cambio hasta recargar. Falta propagación en tiempo real entre clientes.

**Approach:** Publicar los domain events de banco (`created`/`updated`/`deleted`) al hub Mercure desde un nuevo handler **async de Messenger**, en topics **privados** por colección y por banco. El PWA se suscribe vía `EventSource` (con cookie de autorización Mercure) y actualiza el estado de lista/detalle sin recargar.

## Boundaries & Constraints

**Always:**
- Reusar el patrón event-driven existente: el aggregate `Bank` graba el evento, el use case hace `pullDomainEvents()` + `dispatch()`, y un nuevo `#[AsMessageHandler]` publica a Mercure. No publicar Mercure desde controladores ni use cases.
- Topics **privados** (`Update(..., private: true)`). El navegador obtiene la cookie de autorización Mercure desde un endpoint dedicado **antes** de abrir el `EventSource`.
- Payload Mercure = forma `BankProps` `{id,name,shortName,createdAt,updatedAt}` para created/updated; `{id}` para deleted; envuelto con discriminador `type` (`bank.created|bank.updated|bank.deleted`).
- `Domain/` puro (API y PWA): sin Symfony/Mercure/Doctrine/Next/HTTP. IRIs de topic estables (`urn:…`), no atados al hostname.
- Handler idempotente (Messenger es at-least-once). Reusar `API_BASE_URL`, `toastNotifier`, `bankRoutes` existentes.

**Ask First:**
- Si hace falta ampliar CORS, política de cookies, o `connect-src` de la CSP en `next.config.ts` más allá de `'self'` para que el `EventSource` same-origin funcione.
- Si los topics deben restringirse a un usuario/rol concreto (hoy `subscribe: '*'` en `mercure_subscribe.yaml`).
- Si la latencia del `messenger_worker` (poll) resulta insuficiente y se requiere publicación síncrona para este handler.

**Never:**
- No publicar entidades Doctrine crudas ni binarios de logo/stored-object por Mercure; no exponer campos de auditoría internos ni secretos.
- No tocar migraciones (sin cambios de esquema; `domain_event` ya existe y el middleware persiste también el nuevo evento).
- No introducir librerías nuevas de realtime/estado (sin SWR/react-query/socket.io).
- No alterar el flujo de email existente (`BankChangedNotifyEmailHandler` queda intacto).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Borrado visto en lista | B elimina banco X; A tiene la lista abierta | La fila X desaparece de la lista de A sin recargar | Si cae el `EventSource`, A conserva datos cacheados y reintenta reconexión |
| Edición vista en lista | B edita banco X; A tiene la lista abierta | La fila X muestra el nuevo name/shortName | idem |
| Alta vista en lista | B crea banco; A tiene la lista abierta | El nuevo banco aparece (prepend) en la lista de A | idem |
| Edición vista en detalle | B edita X; A tiene el detalle de X abierto | El detalle de A muestra name/shortName nuevos | idem |
| Borrado visto en detalle | B elimina X; A tiene el detalle de X abierto | A es redirigido a `/backoffice/banks` + toast «Este banco fue eliminado» | idem |
| Suscriptor sin cookie | `EventSource` a topic privado sin cookie válida | El hub no entrega updates (no hay filtrado de datos) | Sin crash; log/retry en cliente |

</frozen-after-approval>

## Code Map

**API (`api/`)**
- `src/Backoffice/Bank/Domain/Event/BankDeletedDomainEvent.php` — NUEVO. Modelar sobre `BankUpdatedDomainEvent`; `eventName()='erpify.backoffice.bank.deleted'`; `toPrimitives()={bankId}`.
- `src/Backoffice/Bank/Domain/Entity/Bank.php` — añadir `delete(string $deleteEventId): void` que hace `record(new BankDeletedDomainEvent(...))`.
- `src/Backoffice/Bank/Application/BankDeleter.php` — inyectar `MessageBusInterface`; `$bank->delete(SymfonyUuidGenerator::generate())`, `pullDomainEvents()` antes de `remove()`, luego `dispatch()`.
- `src/Backoffice/Bank/Domain/MercureBankTopic.php` — NUEVO. `COLLECTION='urn:erpify:backoffice:banks'` + `forBank(string $id): string`.
- `src/Backoffice/Bank/Infrastructure/Messenger/BankRealtimePublisherHandler.php` — NUEVO. 3 métodos `#[AsMessageHandler]` (Created/Updated/Deleted); `HubInterface->publish(new Update([topics], json, private: true))`.
- `src/Backoffice/Bank/Infrastructure/Controller/BankRealtimeAuthorizeController.php` — NUEVO. `GET /api/v1/backoffice/banks/realtime/authorize` → 204 + cookie de suscriptor (`Symfony\Component\Mercure\Authorization::setCookie`).
- `config/packages/messenger.yaml` — enrutar `BankDeletedDomainEvent` a `async` (created/updated ya enrutados).
- _Referencias_: `Frontoffice/Mercure/.../MercurePublishDemoController.php` (patrón publish), `BankChangedNotifyEmailHandler.php` (patrón handler), `config/packages/mercure_subscribe.yaml`.

**PWA (`pwa/`)**
- `src/context/shared/infrastructure/RealTime/MercureSubscriber.ts` (+ port en `domain/`) — NUEVO. Adapter `EventSource` (`withCredentials`), parseo JSON, callbacks tipados; cierre limpio.
- `src/context/backoffice/bank/infrastructure/useBankRealtime.ts` — NUEVO hook. Llama `authorize`, construye topics, mapea eventos → callbacks de estado.
- `src/context/shared/infrastructure/api/ApiEndpoints.ts` — añadir `BANKS.REALTIME_AUTHORIZE`.
- `src/app/backoffice/banks/page.tsx` — efecto de suscripción al topic colección: created→prepend, updated→map, deleted→filter (reusar `setBanks`/handlers existentes).
- `src/app/backoffice/banks/[id]/page.tsx` — suscripción al topic `bank:{id}`: updated→`setBank`, deleted→toast + `router.push(bankRoutes.list)`.
- _Referencias_: `HttpClient.API_BASE_URL`, `toastNotifier`, `bankRoutes`.

## Tasks & Acceptance

**Execution:**
- [x] `api/src/Backoffice/Bank/Domain/Event/BankDeletedDomainEvent.php` — crear el evento (payload mínimo `bankId`) — habilita el borrado en tiempo real (hoy delete no emite evento).
- [x] `api/src/Backoffice/Bank/Domain/Entity/Bank.php` — `delete()` que graba el evento — mantiene el origen del evento en el aggregate.
- [x] `api/src/Backoffice/Bank/Application/BankDeleter.php` — grabar+pull+dispatch — alinea delete con create/update.
- [x] `api/src/Backoffice/Bank/Domain/MercureBankTopic.php` — IRIs de topic — fuente única de los topics.
- [x] `api/src/Backoffice/Bank/Infrastructure/Messenger/BankRealtimePublisherHandler.php` — publicar a Mercure (private) — núcleo backend de la feature.
- [x] `api/src/Backoffice/Bank/Infrastructure/Controller/BankRealtimeAuthorizeController.php` — cookie de autorización — requisito de topics privados.
- [x] `api/config/packages/messenger.yaml` — routing del evento delete — sin esto el handler no se invoca.
- [x] `api/tests/Unit/Backoffice/Bank/...` — tests: `Bank::delete` graba el evento (`BankDeleteEventTest`) + handler publica topic/payload/`private=true` para created/updated/deleted (`BankRealtimePublisherHandlerTest`). `BankDeleter` despacha → DIFERIDO (ver Spec Change Log: `BankFinder` es `final readonly`, no mockeable; cubierto por Behat `delete.feature`).
- [x] `pwa/src/context/shared/infrastructure/RealTime/MercureSubscriber.ts` (+ port) — adapter EventSource — base de suscripción reutilizable.
- [x] `pwa/src/context/shared/infrastructure/api/ApiEndpoints.ts` — `REALTIME_AUTHORIZE` — endpoint de cookie.
- [x] `pwa/src/context/backoffice/bank/infrastructure/bankRealtime.ts` — hook `useBankRealtime` + topics + `parseBankRealtimeEvent` (nombre de fichero final: `bankRealtime.ts`, no `useBankRealtime.ts`).
- [x] `pwa/src/app/backoffice/banks/page.tsx` — suscripción de lista — created/updated/deleted en vivo.
- [x] `pwa/src/app/backoffice/banks/[id]/page.tsx` — suscripción de detalle + redirect-on-delete.
- [x] `pwa/tests/...` — vitest de `parseBankRealtimeEvent` (created/updated/deleted + payloads malformados/tipos desconocidos). Merge de estado de lista/detalle y ciclo de vida del `EventSource` → DIFERIDO a E2E Playwright (2 contextos), fuera de alcance unit por el spec.

**Acceptance Criteria:**
- Given dos sesiones en la lista, when una crea/edita/elimina un banco, then la otra refleja el cambio en < 2 s sin recargar.
- Given el detalle de X abierto en dos sesiones, when una lo edita, then la otra ve name/shortName actualizados.
- Given el detalle de X abierto, when otra sesión lo elimina, then la primera es redirigida a la lista con toast.
- Given un suscriptor sin cookie válida, when intenta el topic privado, then el hub no entrega updates (sin fuga de datos de banco).
- `make php.quality`, `make pwa.quality`, `make php.unit`, `make pwa.test.unit` en verde.

## Design Notes

- **Envelope de payload** (ejemplo): `{"type":"bank.updated","bank":{"id":"…","name":"…","shortName":"…","createdAt":"…","updatedAt":"…"}}`; delete: `{"type":"bank.deleted","id":"…"}`. El cliente hace `Bank.fromPrimitives(bank)`. Los datos salen de `event->toPrimitives()` (mapear `bankId`→`id`), no de `ResourceNormalizer`.
- **Topics**: colección `urn:erpify:backoffice:banks` (lista escucha created/updated/deleted) + `urn:erpify:backoffice:bank:{id}` (detalle escucha updated/deleted). El handler publica updated/deleted a **ambos** topics; created sólo al de colección.
- **Cookie de autorización**: `Authorization::setCookie($request, $subscribeTopics)` en el controlador; el `EventSource` same-origin (vía `API_BASE_URL`) envía la cookie. `mercure_subscribe.yaml` ya concede `subscribe: '*'`.
- **Latencia**: depende del poll del `messenger_worker` (sub-segundo típico). Si insuficiente → publicación síncrona (ver Ask First).

## Verification

**Commands:**
- `make php.stan` — esperado: 0 errores en los ficheros tocados.
- `make php.quality` — esperado: verde.
- `make php.unit c='--filter Bank'` — esperado: tests de banco en verde.
- `make pwa.quality` — esperado: verde.
- `make pwa.test.unit c='src/context/shared/infrastructure/RealTime'` — esperado: verde.

**Manual checks:**
- Dos pestañas en `https://localhost/backoffice/banks`; crear/editar/eliminar en una y verificar reflejo inmediato en la otra. En DevTools → Network, confirmar el `EventSource` a `/.well-known/mercure` con estado 200 y la llegada de eventos.

## Suggested Review Order

**Publicación backend (núcleo de la feature)**

- Punto de entrada: cómo cada evento de banco se traduce a un `Update` privado de Mercure por topic.
  [`BankRealtimePublisherHandler.php:36`](../../api/src/Backoffice/Bank/Infrastructure/Messenger/BankRealtimePublisherHandler.php#L36)

- IRIs de topic estables (colección + por banco), fuente única compartida con el PWA.
  [`MercureBankTopic.php:14`](../../api/src/Backoffice/Bank/Domain/MercureBankTopic.php#L14)

**Origen del evento de borrado (lo que faltaba)**

- El aggregate graba el nuevo evento de borrado.
  [`Bank.php:165`](../../api/src/Backoffice/Bank/Domain/Entity/Bank.php#L165)

- El use case captura los eventos ANTES de `remove()` y luego despacha (alinea delete con create/update).
  [`BankDeleter.php:24`](../../api/src/Backoffice/Bank/Application/BankDeleter.php#L24)

- Payload mínimo (`bankId`) — sin fuga de logo/stored-object.
  [`BankDeletedDomainEvent.php:33`](../../api/src/Backoffice/Bank/Domain/Event/BankDeletedDomainEvent.php#L33)

- Enrutado del evento al transporte async.
  [`messenger.yaml:20`](../../api/config/packages/messenger.yaml#L20)

**Autorización de suscripción (topics privados)**

- Cookie de suscriptor scoped a los topics de banco. ⚠️ Ruta pública consciente (ver Spec Change Log).
  [`BankRealtimeAuthorizeController.php:27`](../../api/src/Backoffice/Bank/Infrastructure/Controller/BankRealtimeAuthorizeController.php#L27)

**Suscripción y estado en el PWA**

- Hook de wiring: `useEffectEvent` para ver siempre los handlers más recientes sin reabrir el stream; dep única `topicsKey`.
  [`bankRealtime.ts:77`](../../pwa/src/context/backoffice/bank/infrastructure/bankRealtime.ts#L77)

- Adapter `EventSource` (`withCredentials`) same-origin contra `/.well-known/mercure`.
  [`BrowserMercureSubscriber.ts:24`](../../pwa/src/context/shared/infrastructure/RealTime/BrowserMercureSubscriber.ts#L24)

- Port de dominio (sin imports de infraestructura).
  [`MercureSubscriber.ts:11`](../../pwa/src/context/shared/domain/RealTime/MercureSubscriber.ts#L11)

- Lista: merges id-keyed (created→prepend, updated→map, deleted→filter), sin toast duplicado.
  [`page.tsx:135`](../../pwa/src/app/backoffice/banks/page.tsx#L135)

- Detalle: update en vivo y redirect+toast en borrado remoto.
  [`[id]/page.tsx:66`](../../pwa/src/app/backoffice/banks/%5Bid%5D/page.tsx#L66)

- Endpoint nuevo en el registro de rutas API.
  [`ApiEndpoints.ts:38`](../../pwa/src/context/shared/infrastructure/api/ApiEndpoints.ts#L38)

**Tests**

- Handler: topics + payload + `private=true` + ausencia de campos sensibles.
  [`BankRealtimePublisherHandlerTest.php:22`](../../api/tests/Unit/Backoffice/Bank/Infrastructure/Messenger/BankRealtimePublisherHandlerTest.php#L22)

- `Bank::delete` graba el evento correcto.
  [`BankDeleteEventTest.php:21`](../../api/tests/Unit/Backoffice/Bank/Domain/Entity/BankDeleteEventTest.php#L21)

- Parser de payloads Mercure (happy + malformados).
  [`bankRealtime.test.ts:13`](../../pwa/tests/context/backoffice/bank/infrastructure/bankRealtime.test.ts#L13)
