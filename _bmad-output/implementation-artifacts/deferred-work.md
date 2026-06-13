# Deferred work

Collected during quick-dev. Not part of the current story's shippable scope.

> **2026-06-10 — items vivos migrados a GitHub issues.** El registro vivo ya no
> está en este fichero: cada obligación pendiente tiene su issue (lista abajo).
> Este fichero permanece como sink del workflow quick-dev — los nuevos diferidos
> se siguen apuntando aquí y se migran a issues periódicamente.

## Migrado a issues (2026-06-10)

| Issue                                                           | Item                                                                                                                                                                                            |
|-----------------------------------------------------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| [#194](https://github.com/sergio-salcedo-dev/ERPify/issues/194) | Gatear el endpoint público de autorización Mercure cuando llegue la auth de backoffice (incluye el item "privacy-theater" del review de PR #87)                                                 |
| [#196](https://github.com/sergio-salcedo-dev/ERPify/issues/196) | Subida de source-maps a Sentry (`SENTRY_AUTH_TOKEN` + `withSentryConfig`)                                                                                                                       |
| [#197](https://github.com/sergio-salcedo-dev/ERPify/issues/197) | Rate-limiting del túnel público `/monitoring` (fusiona el item 2026-06-09 y la tunnel-abuse note 2026-06-08)                                                                                    |
| [#198](https://github.com/sergio-salcedo-dev/ERPify/issues/198) | Gate `tsc --noEmit` en `pwa.quality`/CI                                                                                                                                                         |
| [#199](https://github.com/sergio-salcedo-dev/ERPify/issues/199) | Hardening del contrato `filters[]`: guards de `FieldMapping` (datetime+eq/in, flags mutuamente excluyentes) + round-trip en `parseStrict`                                                       |
| [#200](https://github.com/sergio-salcedo-dev/ERPify/issues/200) | Cobertura e2e de cursor keyset + cambio de `sort` — reasignado de la extinta Story 2.2 a **PR3 del ciclo keyset** (ojo: bajo el contrato del ADR es 422 `invalid-cursor`, no fallback a offset) |
| [#202](https://github.com/sergio-salcedo-dev/ERPify/issues/202) | Guard de precedencia default-type para excepciones dual-marker `InvalidInput` + `InvalidSearchCriteria`                                                                                         |
| [#203](https://github.com/sergio-salcedo-dev/ERPify/issues/203) | Asserts e2e de shortName normalizan con `.toLocaleUpperCase()` en vez de la regla del API                                                                                                       |
| [#204](https://github.com/sergio-salcedo-dev/ERPify/issues/204) | API Sentry: headers de auth custom en `RedactionDenylist` + scrub de breadcrumbs                                                                                                                |
| [#205](https://github.com/sergio-salcedo-dev/ERPify/issues/205) | PWA Sentry: denylist amplio, PII no-secreta, presupuesto de nodos en `scrubDeep`/`serializeCause`                                                                                               |
| [#206](https://github.com/sergio-salcedo-dev/ERPify/issues/206) | Guard de paridad del stub `sentryNextjs.ts` (fusiona los dos items duplicados 2026-06-08/09)                                                                                                    |

## Resueltos antes de la migración (histórico)

- **Coste de query sin límite más allá de los caps planos del contrato `filters[]`** —
  resuelto 2026-06-07 (story 1.5): gate NFR4 ejecutado, `EXPLAIN ANALYZE` sobre las 4 formas:
  `in`/`eq` sobre `name_normalized` → Index Scan (índice UNIQUE), `id` → Index Scan (PK);
  **`contains` → Seq Scan asumido conscientemente** (LIKE con comodín inicial no indexable por
  btree; los caps de mapping acotan el peor caso) — postura de perf vigente, no mera historia.
  Plan idéntico al legacy retirado → p95 sin regresión.
- **`InvalidSearchValue` no señala el índice posicional del valor ofensor** — resuelto
  2026-06-07 (story 1.5): `notAUuid(string $field, int $position)` — el context lleva campo +
  posición 0-based, **nunca el valor**. Los params legacy `names[]`/`ids[]` se retiraron del
  wire en la propia 1.5 (decisión de usuario 2026-06-07 — el código no estaba desplegado en
  producción; fase *contract* adelantada).
- Los dos items `patch` del review post-merge de PR #87 (`5753a4c`) quedaron registrados en la
  sección **Review Findings** de `spec-banks-mercure-realtime`.
- **Sentry sink PWA** — shipped 2026-06-08 (spec-pwa-sentry): `serializeCause()` + scrub
  recursivo, `createTelemetry()`, túnel same-origin `/monitoring`, severity map.

## Migrado a issues (2026-06-12)

Triaje del backlog sin-issue de los reviews de keyset (auditado contra `main`): 8 items ya
estaban resueltos en `main` por PR1–PR4 (codec arity guard, dedup `id` en `OrderByColumns`,
precisión `TIMESTAMP(0)`, `hasNext` de página `before` vacía, dirección de índice, `nullable`
sortable, frontera intra-empate del property test, y la migración inmutable de collation) y 2 se
arreglaron en PR #229 (`KeysetSqlSnapshotTest` cierra su conexión DBAL paralela; `SortFieldMapIndexContractTest`
deriva los campos del `SortFieldMap` de producción) — todos retirados. El resto se migró a issues:

| Issue | Item |
|-------|------|
| [#232](https://github.com/sergio-salcedo-dev/ERPify/issues/232) | keyset: `resolveLimit` no aplica `policy.defaultLimit` (default del wire inerte) |
| [#233](https://github.com/sergio-salcedo-dev/ERPify/issues/233) | keyset: `RowUniquenessGuard` falla-abierto fuera del caso addSelect (cartesiano / to-many no seleccionado) |
| [#234](https://github.com/sergio-salcedo-dev/ERPify/issues/234) | keyset: `qualify()` reescribe el DQL del predicado por regex (acoplado a `id` bare) |
| [#235](https://github.com/sergio-salcedo-dev/ERPify/issues/235) | keyset: `entityName()` colapsa al nombre corto → colisión de fingerprint multi-contexto |

## Diferidos del code review de PR #252 (2026-06-13)

Hallazgos reales del review adversarial (Blind Hunter / Edge Case Hunter / Acceptance Auditor)
sobre PR #252 que no entran en el alcance de la propia PR. Triados como `defer`.

- **Migración del índice único falla con duplicados `content_hash` pre-existentes** — el
  `CREATE UNIQUE INDEX` (squasheado en `Version20260405212338`) aborta el boot bajo
  `--all-or-nothing` si ya hay filas duplicadas; documentado como prereq manual ("dedupe rows
  first"), sin guarda ni auto-dedupe. Pre-prod no aplica; futura red de seguridad si se reactiva.
- **Replay manual de un mensaje fallido almacenado queda suprimido por `eventId` estable** —
  `BankChangedNotifyEmailHandler` + `DbalDomainEventHandlerDeduplicator`: un `messenger:failed:retry`
  de un evento que ya reclamó (y no liberó) no reenvía. Las actualizaciones reales distintas sí
  obtienen `eventId` distinto (verificado), así que solo afecta a replays operativos intencionados.
- **`Media::getRawBytes` cachea `''` si el stream está en EOF sin `rewind`** — `stream_get_contents`
  sobre un recurso ya consumido devuelve `''` (no `false`), que se cachea; además lanzar desde un
  getter puede aflorar durante serialización. Borde teórico sobre el flujo BLOB.
- **Dockerfile: el dir pre-creado queda sombreado por un volumen `object_storage_data` pre-existente** —
  en un upgrade desde un volumen creado root-owned, la propiedad `www-data` del layer de imagen no se
  aplica (Docker solo inicializa volúmenes vacíos); `-m 700` compartido entre `php` y `messenger_worker`
  es frágil. Falta un fallback `chown` en el entrypoint para el caso upgrade.
- **`HttpClient.malformedEnvelope`: `correlation-id` cae a un UUID de cliente cuando falta el header** —
  para las respuestas malformadas/error (justo donde más se necesita correlacionar) el id fabricado no
  casa con ningún log de servidor. Pre-existente; esta PR solo lo extrajo a un helper.
- **`MediaGetController:38` devuelve `new Response('Not Found', 404)` saltándose el pipeline RFC 9457** —
  pre-existente, adyacente al `getRawBytes` tocado; convertir a Problem Details en un follow-up.
- **`MediaRegistrar::concurrentWinner`: `findByContentHash` puede devolver null por timing** — bajo
  read-committed o si el tx ganador hace rollback tras bumpear el índice, el re-query falla y mapea a
  `ConcurrentMediaWinnerMissingException` (500) sin reintento; un único retry distinguiría
  "no-visible-aún" de "realmente ausente".
- **`HttpClient`: cuerpo 2xx vacío no-204 lanza `malformed-envelope`** — solo `204` se trata como vacío
  legítimo; un endpoint que devuelva `200` con cuerpo vacío rompería un `get<void>` guard-less. Revisar
  si algún consumidor espera 200-vacío.
