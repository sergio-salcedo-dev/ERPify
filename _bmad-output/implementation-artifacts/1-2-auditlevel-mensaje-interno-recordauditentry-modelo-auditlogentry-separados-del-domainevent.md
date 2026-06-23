# Story 1.2: `AuditLevel` + mensaje interno `RecordAuditEntry` + modelo `AuditLogEntry`, separados del `DomainEvent`

Status: ready-for-dev

<!-- Epic 1 — Registro de auditoría end-to-end (backbone + primer actor auditado).
     Segunda historia del subsistema de auditoría operativa/de actor. Compone ActorContext (1.1).
     Ver ADR docs/adr/audit-activity-log.md (D1, D3, D6, D7). -->

## Story

Como desarrollador de un módulo,
quiero el enum `AuditLevel`, un mensaje interno `RecordAuditEntry` y un modelo persistible `AuditLogEntry` (y **ningún** tipo público `AuditEvent`),
para describir "qué hizo el actor" sin contaminar el stream de dominio ni el `event_store`, y sin exponer un tipo que invite a tratarlo como evento.

Esta historia define **el contrato de datos** del eje de auditoría: el nivel (`AuditLevel`), el registro inmutable (`AuditLogEntry`) y el mensaje de transporte hacia el worker (`RecordAuditEntry`). Es la pieza que cierra la fuga semántica central del ADR (D1): un `AuditEvent` "parecería un evento" (→ event catalog) y "viaja por un bus" (→ alguien lo escucha en otro contexto). Aquí no hay tal tipo; el único seam público —`AuditLogger->log(...)`— llega en 1.4. 1.2 es **dominio + Application puros**: no toca BD (1.3), ni Messenger/transporte (1.4), ni política de captura (2.1). Compone el `ActorContext` de 1.1.

## Acceptance Criteria

**AC1 — No existe un tipo público `AuditEvent`; el seam llega en 1.4.**
**Given** la superficie de `Shared/Audit`,
**When** se examina,
**Then** **no existe ningún tipo `AuditEvent`**: `RecordAuditEntry` y `AuditLogEntry` son piezas internas (`Shared\Audit\Application`), no se exportan a ningún otro bounded context y nadie fuera de `Shared/Audit` las construye; el único seam público de escritura —`AuditLogger->log(...)`— se introduce en la Story 1.4, no aquí (FR1, D1).

**AC2 — `RecordAuditEntry` NO es un `DomainEvent` y es estructuralmente invisible al backbone de eventos.**
**Given** `RecordAuditEntry`,
**When** se examina su tipo,
**Then** es un `final readonly class` **sin clase base** que **NO** extiende `Erpify\Shared\Event\Domain\DomainEvent`; no expone `aggregateId()`/`eventName()`/`aggregateType()`/`occurredOn()` de evento ni el contrato `toPrimitives()`/`fromPrimitives()` del evento;
**And** `is_a(RecordAuditEntry::class, DomainEvent::class, true) === false`, de modo que: `RegisterDomainEventsPass` **no lo descubre** (su único gate es `is_a($class, DomainEvent::class, true)`), `ReflectionDomainEventMapper` no lo registra, `PersistDomainEventMiddleware` (guard `instanceof DomainEvent`) lo deja pasar **sin** escribir en `event_store`, y `EventBus::publish(DomainEvent ...$events)` **ni siquiera lo acepta** (error de tipo);
**And** **no** requiere ninguna entrada en `api/.event-dispatch-allowlist` — la no-herencia es el mecanismo de exclusión **completo**, no hay opt-out que registrar (FR1, D1). Verificado por test unitario (sin contenedor).

**AC3 — Superficie de `AuditLogEntry` y de `RecordAuditEntry`.**
**Given** `AuditLogEntry`,
**When** se examina su superficie,
**Then** lleva: `id` (UUIDv7, `string`), `level` (`AuditLevel` ∈ {activity, security}), `action` (`string` no vacío), `actor` (`ActorContext`), `correlationId` (`string`, UUID), `occurredOn` (`DateTimeImmutable`), contexto de recurso opcional `resourceType`/`resourceId` (`?string`), y opcionales `metadata` (`array<string,mixed>`, default `[]`), `ip` (`?string`), `userAgent` (`?string`) — todos props públicos `readonly` (FR8, FR14);
**And** `RecordAuditEntry` **envuelve** un único `AuditLogEntry` (`public AuditLogEntry $entry`) — es el mensaje de transporte interno hacia el worker de auditoría y **no duplica** los campos del registro (mismo patrón que `BankCreatedDomainEvent` compone `BankSnapshot`);
**And** el `id` (UUIDv7) se acuña en `AuditLogEntry::create(...)` vía `Erpify\Shared\Uuid\Domain\Uuid::generate()`, **antes** de envolver/encolar (ancla de idempotencia de FR4).

**AC4 — `id` es el único ancla de idempotencia (sin `equals()` en PHP).**
**Given** `AuditLogEntry::create(...)`,
**When** se construye,
**Then** `id` es un UUIDv7 válido y es **el único** discriminante de idempotencia: reinsertar el mismo `id` será un no-op a nivel BD (`INSERT … ON CONFLICT (id) DO NOTHING`, Story 1.3); un `AuditLogEntry` regenerado con `create(...)` produce un `id` **nuevo** (fila nueva). No hay `equals()` ni deduplicación semántica en PHP — la identidad por `id` la **sella la PK** en 1.3, no un método del modelo (FR4, NFR7).

**AC5 — `action` no vacío (invariante del registro).**
**Given** `AuditLogEntry::create(...)` con `action` vacío (cadena vacía o solo espacios),
**When** se construye,
**Then** se rechaza con `InvalidAuditLogEntry` (excepción de dominio **sin marcador**: es un error de programación server-side —el `action` lo acuña el llamador como constante— que **fluye a Sentry** y **nunca** aflora como 4xx al usuario; mismo razonamiento que `InvalidActorContext` de 1.1, aislado además por la frontera best-effort de 1.4);
**And** con un `action` no vacío se acepta.

**AC6 — `metadata` admite JSON simple; la prohibición es el contenido (PII), no la forma.**
**Given** `metadata`,
**When** se tipa,
**Then** admite estructuras JSON simples (`array<string,mixed>`: escalares, arrays y objetos de **discriminantes** — p. ej. `{"filters":{"status":"active"}}`, `{"export_format":"xlsx"}`), con default `[]`;
**And** el veto a payloads de negocio y PII sensible (IBAN, cuerpos de entidad) es una regla de **contenido** que aplican los llamadores (validada end-to-end en 1.5), **no** una restricción de tipo en 1.2 (FR12).

**AC7 — `AuditLevel` tipado, backing en minúscula, case-only.**
**Given** `AuditLevel`,
**When** se examina,
**Then** es `enum AuditLevel: string` con exactamente `ACTIVITY = 'activity'` y `SECURITY = 'security'` (backing **minúscula** = contrato del enum Postgres que mapeará la Story 1.3), **case-only** sin métodos (el branching `activity` async / `security` write-before-send vive en `AuditLogger`, Story 1.4, vía `match`). Mirror de `ActorType` (1.1, Decisión D-1.1.c/d).

**AC8 — Ubicación y pureza.**
**Given** las piezas,
**When** se ubican,
**Then** `AuditLevel` y `InvalidAuditLogEntry` viven en `Shared/Audit/Domain` (solo PHP + el wrapper de dominio `Uuid` + la base `DomainException`); `AuditLogEntry` y `RecordAuditEntry` viven en `Shared/Audit/Application` como DTOs planos que **componen** las piezas de dominio (`ActorContext`, `AuditLevel`) **sin** imports de framework/ORM/HTTP/Messenger;
**And** `make php.deptrac` y `make php.lint.bounded-context` quedan verdes **sin** allowlist ni bloque deptrac nuevos.

**AC9 — Tests unitarios puros + gates verdes.**
**Given** tests unitarios de `AuditLevel`, `AuditLogEntry` (+ `InvalidAuditLogEntry`) y `RecordAuditEntry`,
**When** se ejecutan vía `make php.unit`,
**Then** pasan sin contenedor, sin BD y sin red (dominio/Application puros); el round-trip `serialize()`/`unserialize()` de `RecordAuditEntry` queda intacto (debe ser serializable para el transporte de 1.4); y `make php.stan` + `make php.quality` quedan verdes.

## Tasks / Subtasks

- [ ] **T1 — Crear el enum `AuditLevel`** (AC7, AC8) → `api/src/Shared/Audit/Domain/AuditLevel.php`
  - [ ] `enum AuditLevel: string` con `case ACTIVITY = 'activity';` y `case SECURITY = 'security';` (backing **minúscula**, exactamente los tokens del esquema del ADR — Decisión D-1.2.f).
  - [ ] Enum **case-only**, sin métodos: el match async/sync es responsabilidad de `AuditLogger` (1.4), no del enum.
  - [ ] `declare(strict_types=1);`; docblock breve solo si el nombre no basta. Mirror estructural de `api/src/Shared/Search/Domain/SortDirection.php` (enum plano en `Domain/`, no `Domain/Enum/`).
- [ ] **T2 — Crear la excepción de dominio `InvalidAuditLogEntry`** (AC5) → `api/src/Shared/Audit/Domain/Exception/InvalidAuditLogEntry.php`
  - [ ] `final class InvalidAuditLogEntry extends Erpify\Shared\ErrorContract\Domain\Exception\DomainException` — **sin** marcador (no `InvalidInput`/`InvariantViolation`): error server-side que fluye a Sentry, nunca 4xx (Decisión D-1.2.e; mismo razonamiento que `InvalidActorContext`, ver `1-1-...md` D-1.1.b).
  - [ ] Un constructor nombrado estático `actionMustNotBeEmpty(): self`. `type:` estable `'invalid-audit-log-entry'`; `title:` corto; `context:` `[]` (no hace falta — no hay valor ofensivo). Mirror de `api/src/Shared/Search/Domain/Exception/InvalidSearchValue.php`.
- [ ] **T3 — Crear el modelo `AuditLogEntry`** (AC3, AC4, AC5, AC6, AC8) → `api/src/Shared/Audit/Application/AuditLogEntry.php`
  - [ ] `final readonly class AuditLogEntry` con **constructor privado** `private function __construct(public string $id, public AuditLevel $level, public string $action, public ActorContext $actor, public string $correlationId, public DateTimeImmutable $occurredOn, public ?string $resourceType, public ?string $resourceId, public array $metadata, public ?string $ip, public ?string $userAgent)`.
  - [ ] Anotar `metadata` como `@param array<string, mixed>` / `@var array<string, mixed>` (AC6).
  - [ ] Una factoría estática `create(AuditLevel $level, string $action, ActorContext $actor, string $correlationId, DateTimeImmutable $occurredOn, ?string $resourceType = null, ?string $resourceId = null, array $metadata = [], ?string $ip = null, ?string $userAgent = null): self` que: (1) **guarda** `trim($action) === ''` → `throw InvalidAuditLogEntry::actionMustNotBeEmpty()`; (2) acuña `id = Uuid::generate()`; (3) `new self(...)` con el `id` minted (Decisión D-1.2.d).
  - [ ] **Sin** `equals()`, **sin** `toPrimitives()`/`fromPrimitives()`, **sin** factoría de reconstitución con `id` explícito (Decisión D-1.2.d / YAGNI — ver alcance).
  - [ ] `correlationId`/`resourceId` se aceptan como `string`/`?string` **sin** re-validar UUID (fuente fiable: `CorrelationIdListener` ya garantiza UUIDv7; los ids de recurso ya son ids de agregado validados — Decisión D-1.2.g).
- [ ] **T4 — Crear el mensaje `RecordAuditEntry`** (AC2, AC3, AC8) → `api/src/Shared/Audit/Application/RecordAuditEntry.php`
  - [ ] `final readonly class RecordAuditEntry` **sin clase base** con `public function __construct(public AuditLogEntry $entry) {}` — envuelve el registro, no duplica campos (Decisión D-1.2.a; patrón `BankCreatedDomainEvent`→`BankSnapshot`).
  - [ ] **No** importar `DomainEvent`, ni `MessageBusInterface`, ni nada de Messenger/Symfony. **No** definir `aggregateId()`/`eventName()`. El `#[AsMessageHandler]`, el transporte `audit` y el routing son de la Story 1.4 — aquí solo el tipo de mensaje.
- [ ] **T5 — Tests unitarios** (AC9) — mirror `api/tests/Unit/Shared/Audit/{Domain,Application}/`
  - [ ] `api/tests/Unit/Shared/Audit/Domain/AuditLevelTest.php` con `#[CoversClass(AuditLevel::class)]`: fija los 2 backing values (`'activity'|'security'`) y el conteo de casos (mirror de `SortDirectionTest`).
  - [ ] `api/tests/Unit/Shared/Audit/Application/AuditLogEntryTest.php` con `#[CoversClass(AuditLogEntry::class)]` **y** `#[CoversClass(InvalidAuditLogEntry::class)]`: `create(...)` mínimo acepta y expone todos los props; `id` minted es UUIDv7 válido (`Uuid::isValid($e->id)` + check v7 al estilo `UuidTest`); `metadata` default `[]`; recurso opcional (`null` por defecto, valores cuando se pasan); `action` vacío/espacios → `expectException(InvalidAuditLogEntry::class)`.
  - [ ] `api/tests/Unit/Shared/Audit/Application/RecordAuditEntryTest.php` con `#[CoversClass(RecordAuditEntry::class)]`: envuelve el `AuditLogEntry` (`assertSame($entry, $message->entry)`); **NO** es `DomainEvent` (`assertFalse(is_a(RecordAuditEntry::class, DomainEvent::class, true))` **y** `assertNotInstanceOf(DomainEvent::class, $message)`); round-trip `unserialize(serialize($message)) == $message` intacto (serializable para 1.4).
- [ ] **T6 — Gates** (AC8, AC9): `make php.stan` sobre los ficheros nuevos → `make php.unit` → `make php.quality` (incluye deptrac + bounded-context + phpmd + cs-fixer + rector). Verde antes de declarar done. Tras `php.quality`, re-correr `make php.stan` sobre los ficheros ya asentados (Rector puede reescribir asserts — ver Testing requirements).

## Dev Notes

### Contexto del subsistema (leer antes de tocar código)

- **Eje separado, contrato de datos.** Auditoría operativa/de actor (`AuditLogger → audit_log`) es un eje **distinto** del stream de dominio (`DomainEvent → event_store`). Esta historia define **solo el contrato de datos**: `AuditLevel` (nivel), `AuditLogEntry` (registro inmutable), `RecordAuditEntry` (mensaje de transporte). NO crea el puerto `AuditLogger`, ni el handler, ni el transporte, ni la tabla. Fuente: [`docs/adr/audit-activity-log.md`](../../docs/adr/audit-activity-log.md) D1, D3.
- **Prerequisito 1.1.** Compone `ActorContext` (`api/src/Shared/Audit/Domain/ActorContext.php`) y `ActorType` de la Story 1.1 ([`1-1-actorcontext-con-actortype-tipado.md`](./1-1-actorcontext-con-actortype-tipado.md), `ready-for-dev`). En la entrega de PR único los commits siguen la secuencia 1.1 → 1.2; **si se intenta 1.2 antes de que 1.1 exista en disco, está bloqueado**. Verifícalo: `api/src/Shared/Audit/Domain/ActorContext.php` debe existir con constructor privado + 4 factorías y props públicos `readonly` `type` (`ActorType`) y `actorId` (`?string`).
- **Esta historia alimenta a las siguientes.** 1.3 (tabla + escritor DBAL) **lee los props públicos** de `AuditLogEntry` para construir el `INSERT … ON CONFLICT (id) DO NOTHING` (igual que `DbalEventStore` lee `$event->eventId()`); 1.4 (`AuditLogger` + `ActorContextFactory` + handler + transporte) **construye** `AuditLogEntry::create(...)` estampando `correlationId`/`ActorContext`/`occurredOn`, lo envuelve en `RecordAuditEntry` y lo despacha o inserta según `level`. Mantenerlo mínimo y correcto.
- **El placeholder actual no se toca aquí.** `Backoffice/BankAccount/.../Audit/BankAccountsViewedAuditEvent.php` + `RecordAuditLogOnBankAccountsViewed.php` son el archetype provisional (mensaje audit que **ya** es deliberadamente no-`DomainEvent`) que `RecordAuditEntry`/`AuditLogEntry` **generalizan** y que la Story **1.5** retira. No se modifican en 1.2.

### Decisiones técnicas

**D-1.2.a — `RecordAuditEntry` ENVUELVE `AuditLogEntry` (no duplica campos). [decisión de diseño]**
*Principio:* DRY + SRP — el mensaje tiene una sola responsabilidad (transportar), el registro otra (ser el dato). *Objetivo:* una sola fuente de la forma del registro; el handler de 1.4 hace `writer->write($message->entry)` sin re-mapear 11 campos. *Coste / alternativa descartada:* dos clases planas con los 11 campos duplicados + un mapeo `RecordAuditEntry → AuditLogEntry` — más superficie, dos sitios que actualizar al añadir un campo, y un mapeo que probar. El repo ya tiene el patrón exacto: `BankCreatedDomainEvent` compone `BankSnapshot` (`public BankSnapshot $snapshot`, delega `toPrimitives()`) en vez de duplicar la foto del agregado en cada evento. Mirror de eso aquí: `RecordAuditEntry { public AuditLogEntry $entry; }`.

**D-1.2.b — Ubicación: `AuditLevel`/`InvalidAuditLogEntry` en `Domain`, `AuditLogEntry`/`RecordAuditEntry` en `Application`. [decisión de diseño]**
El AC de pureza del epic nombra como "piezas de dominio" **solo** `AuditLevel` y `ActorContext` — deliberadamente no `AuditLogEntry`/`RecordAuditEntry`. `AuditLogEntry` es el modelo persistido (cara a almacenamiento, compone `correlationId`/`ip`/`userAgent` que son contexto de transporte, no invariantes de negocio) y `RecordAuditEntry` es el mensaje de transporte: ambos son **Application**. Precedente directo: `api/src/Shared/Event/Application/StoredEvent.php` (la foto persistida de un evento) vive en `Application/`, no en `Domain/`. Application **puede** componer Domain (`ActorContext`, `AuditLevel`, `Uuid`, `InvalidAuditLogEntry`); ninguno importa framework, así que deptrac queda verde (los colectores `src/Shared/(.*/)?{Domain,Application}` autoenrollan `Shared/Audit/*` — ver `1-1-...md` y `api/CLAUDE.md` → "Deptrac"; **no** añadir bloque por módulo).

**D-1.2.c — `occurredOn` se INYECTA como parámetro, NO se acuña con `SystemClock::now()`. [mejora argumentada sobre el placeholder]**
*Principio:* pureza/testabilidad — el modelo no debe leer reloj ambiental. *Objetivo:* `AuditLogEntryTest` fija un `DateTimeImmutable` literal y asierta determinista, sin `SystemClock::set()`/`reset()`. *Coste / alternativa descartada:* el placeholder `BankAccountsViewedAuditEvent::record()` usa `SystemClock::now()` dentro de su factoría — cómodo, pero `SystemClock` es el accesor ambiental que el repo tolera **solo "para capas que la DI no alcanza" (agregados, domain events)**. `AuditLogEntry` lo construye el `AuditLogger` (1.4), que **sí** puede inyectar el puerto `Clock` (`api/src/Shared/Clock/Domain/Clock.php`). Por eso 1.2 recibe `occurredOn` por parámetro y 1.4 se lo pasa desde el `Clock` inyectado. (El `id` sí se acuña dentro de `create()` — es identidad app-minted, no tiempo de pared.)

**D-1.2.d — `id` minted en `create()`; SIN `equals()`, SIN reconstitución, SIN `toPrimitives()`. [YAGNI, mirror 1.1]**
El AC "dos entradas con el mismo `id` comparten identidad" describe que **`id` es el ancla de idempotencia**, y esa identidad la **sella la PK** de `audit_log` en 1.3 (`ON CONFLICT (id) DO NOTHING`), no un `equals()` en PHP. Un `equals()` sería un predicado **sin consumidor** en 1.2 (la misma lección que 1.1, D-1.1.d). Tampoco hace falta `toPrimitives()`/`fromPrimitives()`: el escritor DBAL de 1.3 lee los props públicos directamente (como `DbalEventStore` lee `$event->eventId()`/`$event->aggregateId()`), y la reconstitución desde la fila cruda —que sí necesitaría validación cruzada— es **trigger de revisita de 4.1** (read model), cuando exista un consumidor real. Por eso `create()` (acuña `id`) es la **única** factoría en 1.2.

**D-1.2.e — `action` es `string` con guard no-vacío + `InvalidAuditLogEntry` marker-less; NO un VO/enum. [YAGNI argumentado]**
Los `action` son constantes open-ended acuñadas **por módulo** (`BANK_ACCOUNTS_VIEWED`, futuros `CUSTOMERS_SEARCHED`, `ACCESS_DENIED`…; ADR "Alcance de captura — Fase 1") — **no** un conjunto cerrado, así que no es un `enum`. La única invariante es "no vacío"; un VO `AuditAction` para una sola invariante y un solo patrón de construcción es abstracción prematura (Regla de Tres). Se queda como `string` con guard en `create()`. La excepción es **sin marcador** porque un `action` vacío es un bug del llamador (server-side) que debe **fluir a Sentry**, no un 4xx (marcarla `InvalidInput`/`InvariantViolation` la suprimiría de Sentry y mentiría sobre la culpa — mismo análisis que `InvalidActorContext`, `1-1-...md` D-1.1.b). *Revisit trigger:* si `action` gana reglas de formato (convención `CONTEXT_ACTION`) o un registro central de acciones → entonces VO.

**D-1.2.f — `AuditLevel` backing en minúscula, case-only. [mirror `ActorType`]**
`'activity'|'security'` son los tokens **exactos** del esquema del ADR (`level enum activity | security`) y el contrato del enum Postgres que mapeará la Story 1.3 vía `EnumType`/`#[ORM\Column(enumType:)]`. Divergencia consciente del precedente MAYÚSCULA (`SortDirection`/`BankAccountStatus`), idéntica a la de `ActorType` (`1-1-...md` D-1.1.c). Case-only: el branching `activity` (async) / `security` (write-before-send) es un `match($level)` en `AuditLogger` (1.4), no un predicado del enum (D-1.1.d).

**D-1.2.g — `correlationId`/`resourceId` como `string` sin re-validar UUID. [trusted boundary]**
`correlationId` lo produce `api/src/Shared/Http/Infrastructure/CorrelationIdListener.php`, que **ya** garantiza un UUIDv7 RFC 4122 en minúscula (lo valida o lo genera). `resourceId` es un id de agregado ya validado aguas arriba. Re-validar en 1.2 sería redundante. Se aceptan como `string`/`?string`. *Si* en el futuro hace falta validar (p. ej. reconstitución desde fila cruda en 4.1), usar `Uuid::isValid()` + excepción **propia** marker-less — **nunca** `Uuid::ensure()`, que lanza `InvalidUuidException` (marcador `InvalidInput` → 400 `invalid-uuid`, semántica de error de cliente equivocada para un dato server-side; mismo razonamiento que `1-1-...md` D-1.1.a).

### YAGNI / alcance — qué NO hacer aquí

- **No** crear el puerto `AuditLogger` ni `ActorContextFactory` ni `RecordAuditEntryHandler` (todo es **Story 1.4**).
- **No** crear la tabla `audit_log`, su migración, el `postGenerateSchema` listener ni el escritor DBAL (**Story 1.3**).
- **No** registrar el transporte `audit` ni routing en `config/packages/messenger.yaml`, ni `#[AsMessageHandler]`, ni nada en `services.yaml` (**Story 1.4**).
- **No** crear `AuditPolicy` ni subscriber de captura (**Epic 2**).
- **No** añadir `equals()`, `toPrimitives()`/`fromPrimitives()`, ni una factoría de reconstitución con `id` explícito (D-1.2.d).
- **No** tocar `Backoffice/BankAccount/.../Audit/*` (placeholder de 1.5) ni `api/.event-dispatch-allowlist` (AC2: la no-herencia basta).
- **No** editar `tools/deptrac/deptrac.yaml` ni el allowlist externo (AC8).

### Architecture compliance (guardrails que muerden)

- **Hexagonal / pureza:** `AuditLevel` (Domain) no importa nada salvo PHP. `InvalidAuditLogEntry` (Domain) importa solo la base `DomainException`. `AuditLogEntry`/`RecordAuditEntry` (Application) importan `ActorContext`/`AuditLevel`/`Uuid`/`InvalidAuditLogEntry` (todo `Shared` inward) — **cero** imports de framework/ORM/HTTP/Messenger. `DateTimeImmutable` es PHP nativo (permitido).
- **`RecordAuditEntry` invisible al event backbone (AC2):** el gate de [`RegisterDomainEventsPass`](../../api/src/Shared/Event/Infrastructure/DependencyInjection/RegisterDomainEventsPass.php) es **únicamente** `is_a($class, DomainEvent::class, true)`; al no extender [`DomainEvent`](../../api/src/Shared/Event/Domain/DomainEvent.php), `RecordAuditEntry` no se descubre, no entra en `ReflectionDomainEventMapper`, [`PersistDomainEventMiddleware`](../../api/src/Shared/Event/Infrastructure/Messenger/PersistDomainEventMiddleware.php) (guard `instanceof DomainEvent`) lo ignora y [`EventBus::publish(DomainEvent ...)`](../../api/src/Shared/Event/Domain/EventBus.php) lo rechaza por tipo. **Sin** marcador, naming ni directorio que importen: la no-herencia es el mecanismo completo.
- **Error contract:** `InvalidAuditLogEntry` extiende `DomainException` **sin marcador** ⇒ no añade un `type` 4xx mapeado ⇒ **no** requiere editar [`docs/api-error-contract.md`](../../docs/api-error-contract.md) (NFR26 solo aplica al añadir/cambiar un marcador o su mapping). Igual que `InvalidActorContext` (1.1).
- **Event catalog:** `RecordAuditEntry` **no** entra en [`docs/architecture/event-catalog.md`](../../docs/architecture/event-catalog.md) (no es un `DomainEvent`). Esa doc solo se toca en la Story 1.5 (la línea *Non-domain signals* de `BANK_ACCOUNTS_VIEWED`).
- **Bounded-context isolation:** `Erpify\Shared\…` es siempre importable; ninguna pieza entra en el `Domain/` de un contexto de negocio. `make php.lint.bounded-context` verde sin allowlist nuevo.

### Librerías / framework

- PHP **8.5** (floor `^8.5`); idiomas 8.3 son forward-compatible — no inventar sintaxis 8.5 de memoria. Usar `final readonly class`, propiedades promovidas (válidas en constructor privado), enums backed, factorías estáticas. `match` se usará en 1.4, no aquí.
- `declare(strict_types=1);` en cada fichero (src y test). Tipos en todo parámetro/retorno/propiedad.
- UUID: **no** añadir dependencias; usar `Erpify\Shared\Uuid\Domain\Uuid::generate()` (acuña v7) e `Uuid::isValid()` (predicado, en los tests). El wrapper ya cubre `symfony/uid` bajo la excepción de layer.
- `ActorContext`/`ActorType`: de la Story 1.1 (prerequisito).
- Tests: **PHPUnit 13** con atributos (`#[CoversClass]`, `#[DataProvider]`, `#[Test]` si se usa), no doc-comments.

### File structure (todos NEW)

```
api/src/Shared/Audit/Domain/AuditLevel.php                         (NEW — enum backed string, case-only, backing minúscula)
api/src/Shared/Audit/Domain/Exception/InvalidAuditLogEntry.php     (NEW — DomainException sin marcador, 1 ctor nombrado)
api/src/Shared/Audit/Application/AuditLogEntry.php                 (NEW — final readonly record; ctor privado + create() acuña id)
api/src/Shared/Audit/Application/RecordAuditEntry.php              (NEW — final readonly message; envuelve AuditLogEntry; NO DomainEvent)
api/tests/Unit/Shared/Audit/Domain/AuditLevelTest.php             (NEW)
api/tests/Unit/Shared/Audit/Application/AuditLogEntryTest.php     (NEW — cubre AuditLogEntry + InvalidAuditLogEntry)
api/tests/Unit/Shared/Audit/Application/RecordAuditEntryTest.php  (NEW)
```

Patrón de carpeta: enum **plano** en `Domain/` (mirror de `Shared/Search/Domain/SortDirection.php`). Excepción en `Domain/Exception/` (mirror de `Shared/Search/Domain/Exception/`, junto al `InvalidActorContext` que crea 1.1). DTOs de Application junto a la primera capa `Application/` del módulo (1.1 solo creó `Domain/`). Tests espejan `src/`.

### Testing requirements

- **Puro:** `extends PHPUnit\Framework\TestCase` (no `KernelTestCase`), sin contenedor, sin BD. Mirror de [`api/tests/Unit/Shared/Search/Domain/SortDirectionTest.php`](../../api/tests/Unit/Shared/Search/Domain/SortDirectionTest.php) (enum) y [`api/tests/Unit/Shared/Uuid/Domain/UuidTest.php`](../../api/tests/Unit/Shared/Uuid/Domain/UuidTest.php) (UUID + excepción). Namespace `Erpify\Tests\Unit\Shared\Audit\…`, `final class …Test`, `/** @internal */`.
- **Cobertura por `#[CoversClass]`:** la cobertura solo se acredita al target del `#[CoversClass]` (gate SonarCloud `new_coverage`). Por eso `AuditLevelTest` lleva `#[CoversClass(AuditLevel::class)]`; `AuditLogEntryTest` declara `#[CoversClass(AuditLogEntry::class)]` **+** `#[CoversClass(InvalidAuditLogEntry::class)]`; `RecordAuditEntryTest` declara `#[CoversClass(RecordAuditEntry::class)]`. No confiar en cobertura "gratis" cruzada (lección de PR #364).
- **`AuditLogEntryTest`:** construir con `AuditLogEntry::create(AuditLevel::ACTIVITY, 'BANK_ACCOUNTS_VIEWED', ActorContext::anonymous(), Uuid::generate(), new DateTimeImmutable('2026-01-01T00:00:00+00:00'))` y asertar props con `assertSame`. `id`: `assertTrue(Uuid::isValid($e->id))` + check v7 al estilo `UuidTest` (`assertInstanceOf(UuidV7::class, ...)` o el patrón que use el repo). `metadata` default `[]`; recurso `null` por defecto + valores cuando se pasan. Rechazo: `$this->expectException(InvalidAuditLogEntry::class)` para `create(..., action: '')` y `action: '   '`.
- **`RecordAuditEntryTest`:** `assertSame($entry, (new RecordAuditEntry($entry))->entry)`; `assertFalse(is_a(RecordAuditEntry::class, DomainEvent::class, true))` **y** `assertNotInstanceOf(DomainEvent::class, $message)`; serialización: `assertEquals($message, unserialize(serialize($message)))` (intacto → serializable para el transporte de 1.4).
- **UUID en test:** generar con `Uuid::generate()`, **no** hardcodear literales.
- **Gotchas de tooling (de 1.1 y sesiones previas):**
  - Rector reescribe asserts en `php.quality` (p. ej. `assertEquals`→`assertSame` en escalares; `assertEmpty`→`assertSame([], …)`). Tras `php.stan` verde, correr `php.quality` y **re-correr `php.stan`** sobre los ficheros ya asentados; aceptar la forma que imponga Rector en vez de pelearla. Para el round-trip de objetos, `assertEquals` (no `assertSame`) es correcto y Rector no lo toca (compara objetos, no escalares).
  - PHPMD `TooManyPublicMethods` (límite 10) cuenta métodos de test: si `AuditLogEntryTest` se acerca al tope, fusionar casos con `#[DataProvider]` en un método (no añadir un provider extra si ya estás al tope — lección PR #364). Aquí hay holgura.
  - `php.quality` regenera `api/config/reference.php`: es auto-generado, **commitea** el diff regenerado, no hagas `git checkout` de él.

### Git intelligence (rama `feat/shared-audit-actor-context-5sz9`)

- Estado de la rama (base `df391a26`): commits de planning/docs (`c50214b7 sprint status`, `4a58aae0`/`bbda382b`/`e4023969`/`6067da9b`/`cc7caae9` docs del ADR + epics). El **primer commit de implementación** es el de la Story 1.1 (`ActorContext`); **1.2 es el segundo**, encadenado sobre él.
- Commit sugerido (Conventional Commits, scope `shared`): `feat(shared): add AuditLevel, AuditLogEntry record and RecordAuditEntry message`.
- **Barrer del diff** cualquier comentario con IDs de story/NFR/AC/`D-1.2.x` antes del commit final (regla de comentarios de `CLAUDE.md`): son andamiaje de desarrollo, no van a `main`.

### Project Structure Notes

- `api/src/Shared/Audit/` lo inicia la Story 1.1 (solo `Domain/`). 1.2 añade `Domain/AuditLevel.php`, `Domain/Exception/InvalidAuditLogEntry.php` y la **primera capa `Application/`** del módulo (`AuditLogEntry`, `RecordAuditEntry`). Coherente con la organización vertical-slice de `Shared/` ([`docs/adr/shared-module-organization.md`](../../docs/adr/shared-module-organization.md)).
- Sin conflictos de estructura. No tocar `Backoffice/BankAccount/.../Audit/*` (placeholder de 1.5).

### References

- [`docs/adr/audit-activity-log.md`](../../docs/adr/audit-activity-log.md) — D1 (eje separado, no `AuditEvent` público, `RecordAuditEntry`/`AuditLogEntry` internos, `StoredDomainEvent` no se renombra), D3 (split por `level`), D6 (correlación obligatoria), D7 (`ActorType`), esquema `audit_log`.
- [`_bmad-output/planning-artifacts/epics.md`](../planning-artifacts/epics.md) — Epic 1 / Story 1.2 (ACs originales), FR1/FR8/FR12/FR14, NFR7/NFR8.
- [`1-1-actorcontext-con-actortype-tipado.md`](./1-1-actorcontext-con-actortype-tipado.md) — API de `ActorContext`/`ActorType` (prerequisito) y decisiones D-1.1.a/b/c/d (excepción marker-less, backing minúscula, enum case-only).
- [`api/src/Shared/Event/Domain/DomainEvent.php`](../../api/src/Shared/Event/Domain/DomainEvent.php) — base que `RecordAuditEntry` **NO** extiende.
- [`api/src/Shared/Event/Infrastructure/DependencyInjection/RegisterDomainEventsPass.php`](../../api/src/Shared/Event/Infrastructure/DependencyInjection/RegisterDomainEventsPass.php) — gate `is_a(..., DomainEvent::class, true)` (lo que hace invisible a `RecordAuditEntry`).
- [`api/src/Shared/Event/Infrastructure/Messenger/PersistDomainEventMiddleware.php`](../../api/src/Shared/Event/Infrastructure/Messenger/PersistDomainEventMiddleware.php) — guard `instanceof DomainEvent` (no escribe `RecordAuditEntry` en `event_store`).
- [`api/src/Shared/Event/Domain/EventBus.php`](../../api/src/Shared/Event/Domain/EventBus.php) — `publish(DomainEvent ...$events)` (rechaza `RecordAuditEntry` por tipo).
- [`api/src/Backoffice/Bank/Domain/Event/BankSnapshot.php`](../../api/src/Backoffice/Bank/Domain/Event/BankSnapshot.php) + [`BankCreatedDomainEvent.php`](../../api/src/Backoffice/Bank/Domain/Event/BankCreatedDomainEvent.php) — patrón "mensaje/evento ENVUELVE un record VO" (D-1.2.a).
- [`api/src/Backoffice/BankAccount/Application/Audit/BankAccountsViewedAuditEvent.php`](../../api/src/Backoffice/BankAccount/Application/Audit/BankAccountsViewedAuditEvent.php) — archetype del mensaje audit no-`DomainEvent` que 1.2 generaliza y 1.5 retira.
- [`api/src/Shared/Event/Application/StoredEvent.php`](../../api/src/Shared/Event/Application/StoredEvent.php) — precedente de DTO persistido en `Application/` (D-1.2.b).
- [`api/src/Shared/Search/Domain/SortDirection.php`](../../api/src/Shared/Search/Domain/SortDirection.php) — patrón enum backed plano en `Domain/`.
- [`api/src/Shared/Uuid/Domain/Uuid.php`](../../api/src/Shared/Uuid/Domain/Uuid.php) — `generate()`/`isValid()`.
- [`api/src/Shared/Http/Infrastructure/CorrelationIdListener.php`](../../api/src/Shared/Http/Infrastructure/CorrelationIdListener.php) — `correlationId` = UUIDv7 `string` (D-1.2.g).
- [`api/src/Shared/Clock/Domain/Clock.php`](../../api/src/Shared/Clock/Domain/Clock.php) — puerto `Clock` que inyecta 1.4 (D-1.2.c).
- [`api/src/Shared/ErrorContract/Domain/Exception/DomainException.php`](../../api/src/Shared/ErrorContract/Domain/Exception/DomainException.php) + [`api/src/Shared/Search/Domain/Exception/InvalidSearchValue.php`](../../api/src/Shared/Search/Domain/Exception/InvalidSearchValue.php) — base + patrón de excepción de dominio (ctor nombrado, `context` sin valor ofensivo).

## Dev Agent Record

### Agent Model Used

### Debug Log References

### Completion Notes List

### File List
