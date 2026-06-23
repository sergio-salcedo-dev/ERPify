# Story 1.4: Puerto `AuditLogger` + persistencia por nivel (activity async / security write-before-send) + `ActorContextFactory`

Status: ready-for-dev

<!-- Epic 1 — Registro de auditoría end-to-end (backbone + primer actor auditado).
     Cuarta historia del subsistema de auditoría operativa/de actor. Cierra el flujo de escritura:
     compone ActorContext (1.1), AuditLevel/AuditLogEntry/RecordAuditEntry (1.2) y AuditLogWriter (1.3).
     Ver ADR docs/adr/audit-activity-log.md (D2, D3, D6, D7 + "Secuencia frente a auth"). -->

## Story

Como desarrollador de un módulo,
quiero el puerto público `AuditLogger` (`->log(...)`) que sella actor + correlación y persiste según el nivel, más un `ActorContextFactory` que resuelve el actor actual,
para registrar una acción con **una sola llamada** —sin IO de persistencia en el camino de request para `activity` y con durabilidad write-before-send para `security`— y sin que un fallo de auditoría tumbe nunca el caso de uso principal.

Esta historia construye **el seam público de escritura** y la **frontera best-effort** del eje de auditoría: el puerto `AuditLogger` (el único que toca Application; D1/D2), su adaptador que ramifica por `level` (D3), el `ActorContextFactory` (la **única** pieza que cambia cuando entre auth real; D7/FR16), el `RecordAuditEntryHandler` que drena el transporte `audit` invocando el escritor de 1.3 (FR4), y el cableado del transporte `audit` dedicado en `messenger.yaml` + el worker. Compone **todo** lo anterior: `ActorContext` (1.1), `AuditLevel`/`AuditLogEntry`/`RecordAuditEntry` (1.2), `AuditLogWriter` (1.3) y los puertos `Clock` (1.2 D-1.2.c) y `CorrelationIdListener` (vía `RequestStack`). NO crea política de captura (`AuditPolicy`, subscriber `kernel.terminate`, listener de `AccessDeniedException` — todo eso es **Epic 2**); NO migra `BankAccountsViewed` al seam (eso es **1.5**, el primer consumidor real); NO toca retención/GDPR (**Epic 3**) ni read model (**Epic 4**). Tras 1.4 el backbone está completo y es invocable, pero todavía **nadie lo llama** (1.5 es el primer `log(...)` real).

## Acceptance Criteria

**AC1 — Puerto `AuditLogger` con `log(...)` como único seam público de escritura.**
**Given** la superficie de `Shared/Audit/Application`,
**When** se examina,
**Then** existe la interfaz `AuditLogger` con un único método `log(...)` (la firma exacta la fija AC2); es el **único** seam de escritura que toca cualquier Application (D1/D2/FR2), y los módulos NO conocen `AuditLogEntry`, `RecordAuditEntry`, el transporte ni el escritor — solo `AuditLogger->log(...)` y los enums/VOs que pasan como argumento (`AuditLevel`, el carrier de recurso).

**AC2 — Firma de `log(...)`: acción + nivel + recurso opcional + metadata opcional.**
**Given** el puerto `AuditLogger`,
**When** se examina la firma,
**Then** es `log(string $action, AuditLevel $level, ?AuditResource $resource = null, array $metadata = []): void` (orden del epic `log(action, level, resource?, metadata?)`), donde `metadata` está anotado `@param array<string, mixed>`;
**And** el contexto de recurso viaja como **un único value object opcional** `AuditResource` (`Shared/Audit/Domain`, props públicos `readonly` `type: string`, `id: string`) en vez de dos parámetros `?string $resourceType, ?string $resourceId` sueltos — ata el par tipo+id que `AuditLogEntry` separa en dos columnas, hace `log('X', activity, AuditResource::of('Bank', $bankId))` legible en el callsite de 1.5 y elimina el estado ilegal "tipo sin id / id sin tipo" (D-1.4.a);
**And** `correlationId`, `ActorContext`, `id` (UUIDv7) y `occurredOn` **NO** son parámetros de `log(...)` — los sella el adaptador (AC3), no el llamador (un módulo no debe poder falsear el actor ni la correlación).

**AC3 — El adaptador sella `id`/`correlationId`/`actor`/`occurredOn` y construye el `AuditLogEntry`.**
**Given** el adaptador concreto de `AuditLogger` (`Shared/Audit/Infrastructure`),
**When** se invoca `log(...)`,
**Then** construye el registro vía `AuditLogEntry::create($level, $action, $actor, $correlationId, $occurredOn, $resource?->type, $resource?->id, $metadata)` (1.2) donde: el `id` UUIDv7 lo acuña `create()` (1.2 D-1.2.d); `$actor` lo provee `ActorContextFactory::current()` (AC5); `$correlationId` lo lee del request actual (AC6); `$occurredOn` es `$clock->now()` con el puerto `Clock` **inyectado** (1.2 D-1.2.c, **no** `SystemClock::now()`); `$resource?->type`/`$resource?->id` se desempaquetan del `AuditResource` opcional (null → ambos null) (FR2, FR14, FR16).

**AC4 — Ramificación por nivel: `activity` async (encolar), `security` síncrono write-before-send.**
**Given** el adaptador de `AuditLogger`,
**When** ramifica por `$level` (`match`),
**Then** para `AuditLevel::ACTIVITY` despacha un `RecordAuditEntry($entry)` al transporte Messenger **dedicado** `audit` (vía el `MessageBusInterface` / `EventBus`-equivalente; ver D-1.4.e) y **no** ejecuta ninguna escritura síncrona en `audit_log`; para `AuditLevel::SECURITY` invoca **directamente** `AuditLogWriter::write($entry)` (1.3) en el mismo ciclo de request (**write-before-send**), sin encolar (FR2, D3);
**And** el branching es un `match($level)` exhaustivo (los dos casos del enum) en el adaptador — no un predicado del enum (1.2 D-1.2.f).

**AC5 — `ActorContextFactory` resuelve el actor actual: `/api/*` sin auth → `anonymous`; CLI/scheduler → `system`.**
**Given** el puerto `ActorContextFactory` (`Shared/Audit/Application`) con `current(): ActorContext` y su adaptador (`Shared/Audit/Infrastructure`),
**When** lo resuelve **antes de que exista auth real**,
**Then** si hay una request HTTP en curso (`RequestStack::getCurrentRequest()` no null) devuelve `ActorContext::anonymous()` (no hay identidad autenticada todavía); si **no** hay request (CLI / Symfony Scheduler / worker) devuelve `ActorContext::system()` (FR16, NFR10);
**And** es la **única** pieza del subsistema que cambiará cuando entre User/RBAC (el día que exista auth, `forUser($id)`/`forApiKey($id)` reemplazan la rama `anonymous` aquí — schema, bus, storage, escritor, transporte y handler **no se tocan**, ADR "Secuencia frente a auth"). Verificado por test unitario con un `RequestStack` poblado vs. vacío.

**AC6 — `correlationId` se lee del request actual vía `RequestStack` + `CorrelationIdListener::ATTRIBUTE_KEY`; el path CLI/worker se resuelve sin null.**
**Given** el adaptador de `AuditLogger`,
**When** necesita el `correlationId` para el `AuditLogEntry` (que lo requiere **no-null**, 1.2),
**Then** en una request lo lee de `RequestStack::getCurrentRequest()->attributes->get(CorrelationIdListener::ATTRIBUTE_KEY)` (el UUIDv7 minteado en `kernel.request`, ver `CorrelationIdListener`); **no existe** un servicio holder — se diseña contra `RequestStack` (Additional Requirements del epic: "reutilizar `CorrelationIdListener` como fuente de `correlation_id`");
**And** fuera de request (CLI/worker, donde `getCurrentRequest()` es null) o si el atributo está ausente/no es un UUIDv7 canónico, el adaptador acuña un `correlationId` de respaldo (`Uuid::generate()`) para que `AuditLogEntry::create()` nunca reciba null — la correlación de un `log()` de sistema es la de ese acto aislado, no la de ninguna request (D-1.4.d; **esto se eleva como pregunta abierta** — ver *Preguntas*).

**AC7 — Frontera best-effort: un fallo del despacho/escritura de auditoría NUNCA tumba el caso de uso.**
**Given** la `frontera best-effort` que 1.4 mueve **dentro** de `AuditLogger`,
**When** `log(...)` falla por cualquier causa (transporte mal configurado, serialización, servicio inexistente, `ActorContextFactory`/`Clock` que lanzan, o la BD en el caso `security`),
**Then** `log(...)` **nunca** propaga la excepción al llamador — el caso de uso principal completa con éxito (la auditoría es observabilidad, no parte del contrato de negocio; ADR D3/FR4/NFR2), de modo que el callsite de 1.5 llama `auditLogger->log(...)` **sin** `try/catch` (1.4 absorbe la frontera que hoy vive en `BankAccountSearcher::recordAccess`);
**And** el fallo **nunca es silencioso**: se registra mediante observabilidad técnica (una línea de log a nivel `warning`/`error` por `Psr\Log\LoggerInterface`, que el bridge Monolog→Sentry recoge), **sin** el valor ofensivo en claro y **sin** PII; la pérdida pre-encolado es aceptable, la pérdida **silenciosa** no (FR4, NFR2);
**And** NO se manda "tragar toda excepción a ciegas": el captura-y-registra es la política de la **frontera del seam** (`AuditLogger`), elegida conscientemente, no un `catch (\Throwable)` esparcido por los handlers (D-1.4.c deja la latitud de implementación que pide el epic).

**AC8 — `RecordAuditEntryHandler` sobre el transporte `audit` → escritor idempotente de 1.3.**
**Given** el handler `RecordAuditEntryHandler` (`Shared/Audit/Infrastructure/Messenger`),
**When** el `messenger_worker` consume un `RecordAuditEntry` del transporte `audit`,
**Then** es un `#[AsMessageHandler] final readonly class` con `__invoke(RecordAuditEntry $message): void` que hace **exactamente** `$this->writer->write($message->entry)` (inyecta el puerto `AuditLogWriter` de 1.3, no el adaptador) — sin lógica adicional, mirror de `SendEmailOnBankChanged`/`RunProjectionsOnDomainEvent`;
**And** la idempotencia ante reentrega at-least-once la da la PK + `ON CONFLICT (id) DO NOTHING` del escritor de 1.3 (NFR7) — el handler **no** añade dedup propia; una reentrega es un no-op por `id`.

**AC9 — `messenger.yaml`: transporte `audit` dedicado + routing de `RecordAuditEntry` + override `when@test` + worker que lo consume.**
**Given** `config/packages/messenger.yaml`,
**When** se cablea el eje async de auditoría,
**Then** existe un transporte **dedicado** `audit` (Doctrine, `queue_name: audit`, `auto_setup: false`) **separado** del `async` de los `DomainEvent` (D3: no comparte cola — aísla head-of-line coupling, saturación del `failed` de dominio y escalado); existe el routing `Erpify\Shared\Audit\Application\RecordAuditEntry: audit`; y el bloque `when@test` añade `audit: 'in-memory://?serialize=true'` (igual que `async`/`failed`, para cazar payloads no serializables);
**And** el `messenger_worker` consume el transporte `audit` — la línea `messenger:consume` de `compose.yaml` (dev) pasa de `async scheduler_maintenance` a incluir `audit`, y la de `compose.prod.yaml` (pool escalable) lo añade a su consumo de `async` (D3: "en dev se pliega en `messenger_worker`; en prod puede tener su propio worker" — Fase 1 lo pliega en el pool, un worker dedicado es trigger de revisita por volumen). **Sin** este cambio de comando nada drena la cola `activity` (ver Decisión D-1.4.f).

**AC10 — `activity` no hace IO de persistencia síncrono; `security` es la única excepción consciente a NFR2.**
**Given** el camino de request,
**When** se observa `AuditLogger->log(...)` con `level=activity`,
**Then** no ejecuta ninguna escritura síncrona en `audit_log` (solo `dispatch()` al transporte `audit`, que con el outbox Doctrine es un `INSERT` en `messenger_messages`, no en `audit_log` — el path de request queda libre de IO de **la tabla de auditoría**, NFR2/D3);
**And** con `level=security` ejecuta el `INSERT` síncrono en `audit_log` (write-before-send) — raro y fuera del path caliente, la **única excepción consciente a NFR2** (NFR2, D3). Verificado por test (functional/integración) que distingue las dos ramas.

**AC11 — Ubicación, aislamiento y gates verdes (sin bloque deptrac ni allowlist nuevos).**
**Given** las piezas,
**When** se ubican y se corren los gates,
**Then** las **interfaces** `AuditLogger` y `ActorContextFactory` viven en `Shared/Audit/Application`; el VO `AuditResource` en `Shared/Audit/Domain`; los **adaptadores** (`SymfonyAuditLogger`, `RequestStackActorContextFactory`, `RecordAuditEntryHandler`) en `Shared/Audit/Infrastructure`; `make php.deptrac` y `make php.lint.bounded-context` quedan verdes **sin** bloque deptrac por módulo ni allowlist nuevos (los colectores `src/Shared/(.*/)?{Application,Infrastructure}` autoenrollan `Shared/Audit/*` en `Shared.*`, como el backbone `Shared/Event`; mirror de 1.3 AC10);
**And** `make php.stan`, `make php.unit` (unit + el functional de las dos ramas) y `make php.quality` quedan verdes;
**And** `make php.lint.event-bus` queda verde: `SymfonyAuditLogger` importa `MessageBusInterface` desde **Infrastructure** (no Application), así que no entra en el gate del event-dispatch-allowlist (D-1.4.e) — **no** se añade entrada a `api/.event-dispatch-allowlist`.

## Tasks / Subtasks

- [ ] **T0 — Verificar prerequisitos en disco (1.1 + 1.2 + 1.3)** — bloqueante.
  - [ ] Confirmar `api/src/Shared/Audit/Domain/ActorContext.php` + `ActorType.php` (1.1) con factorías `anonymous()`/`system()`/`forUser()`/`forApiKey()`.
  - [ ] Confirmar `api/src/Shared/Audit/Application/AuditLogEntry.php` (1.2) con `create(AuditLevel $level, string $action, ActorContext $actor, string $correlationId, DateTimeImmutable $occurredOn, ?string $resourceType = null, ?string $resourceId = null, array $metadata = [], ?string $ip = null, ?string $userAgent = null): self`, `RecordAuditEntry.php` (`public AuditLogEntry $entry`) y el enum `AuditLevel` (`ACTIVITY`/`SECURITY`).
  - [ ] Confirmar `api/src/Shared/Audit/Application/AuditLogWriter.php` (1.3, puerto `write(AuditLogEntry): void`) y `Infrastructure/Persistence/DbalAuditLogWriter.php`. En la entrega de PR único los commits siguen 1.1 → 1.2 → 1.3 → 1.4; **si 1.3 no está en disco, 1.4 está bloqueada** (el handler y la rama `security` invocan el escritor).

- [ ] **T1 — VO `AuditResource`** (AC2) → `api/src/Shared/Audit/Domain/AuditResource.php`
  - [ ] `final readonly class AuditResource` con **constructor privado** `private function __construct(public string $type, public string $id)` y una factoría `public static function of(string $type, string $id): self`. Mirror estructural de los VOs de `Shared/…/Domain` con constructor privado + factoría (`ActorContext`, `NormalizedText`).
  - [ ] **Sin** re-validar el `id` como UUID (igual que 1.2 D-1.2.g: el `resourceId` es un id de agregado ya validado aguas arriba; `AuditLogEntry` lo acepta como `string` sin re-validar). **Sin** `equals()`/serialización (YAGNI; nadie los consume).
  - [ ] Docblock breve: ata el par `(resourceType, resourceId)` que `audit_log` separa en dos columnas; existe para que `AuditLogger->log(...)` reciba un recurso atómico en vez de dos parámetros sueltos.

- [ ] **T2 — Puerto `AuditLogger`** (AC1, AC2) → `api/src/Shared/Audit/Application/AuditLogger.php`
  - [ ] `interface AuditLogger { /** @param array<string, mixed> $metadata */ public function log(string $action, AuditLevel $level, ?AuditResource $resource = null, array $metadata = []): void; }`. Importa `AuditLevel` (1.2 Domain) y `AuditResource` (T1, Domain). **Sin** `correlationId`/`actor`/`id`/`occurredOn` en la firma (los sella el adaptador).
  - [ ] Docblock breve: único seam público de escritura del eje de auditoría; ramifica por `level` (async/sync) en su adaptador; best-effort (nunca propaga, AC7).

- [ ] **T3 — Puerto `ActorContextFactory`** (AC5) → `api/src/Shared/Audit/Application/ActorContextFactory.php`
  - [ ] `interface ActorContextFactory { public function current(): ActorContext; }`. Importa `ActorContext` (1.1 Domain).
  - [ ] Docblock breve: produce el `ActorContext` actual; **la única pieza que cambia cuando entre auth real** (ADR "Secuencia frente a auth").

- [ ] **T4 — Adaptador `RequestStackActorContextFactory`** (AC5, AC11) → `api/src/Shared/Audit/Infrastructure/RequestStackActorContextFactory.php`
  - [ ] `#[AsAlias(ActorContextFactory::class)] final readonly class RequestStackActorContextFactory implements ActorContextFactory` con `public function __construct(private RequestStack $requestStack) {}`. `#[Override]` en `current()`. Patrón `#[AsAlias]` + `RequestStack` por constructor mirror de `ContentHashUrlGenerator` y `SymfonyClock`.
  - [ ] `current()`: `return $this->requestStack->getCurrentRequest() instanceof Request ? ActorContext::anonymous() : ActorContext::system();` — request en curso → anónimo (sin auth aún); fuera de request → sistema (CLI/scheduler/worker).
  - [ ] Docblock breve sobre la regla request→anonymous / CLI→system y que el día de auth solo cambia esta clase. **Sin** IDs de story/AC/FR en el comentario.

- [ ] **T5 — Adaptador `SymfonyAuditLogger`** (AC3, AC4, AC6, AC7, AC10) → `api/src/Shared/Audit/Infrastructure/SymfonyAuditLogger.php`
  - [ ] `#[AsAlias(AuditLogger::class)] final readonly class SymfonyAuditLogger implements AuditLogger` con constructor inyectando: `MessageBusInterface $messageBus`, `AuditLogWriter $writer` (puerto de 1.3), `ActorContextFactory $actorContextFactory`, `Clock $clock` (puerto de 1.2), `RequestStack $requestStack`, `LoggerInterface $logger`. `#[Override]` en `log()`.
  - [ ] `log(...)`: envolver **todo** el cuerpo en la frontera best-effort (try/catch en el **seam**, AC7). Esqueleto:
    ```php
    #[Override]
    public function log(string $action, AuditLevel $level, ?AuditResource $resource = null, array $metadata = []): void
    {
        try {
            $entry = AuditLogEntry::create(
                $level,
                $action,
                $this->actorContextFactory->current(),
                $this->resolveCorrelationId(),
                $this->clock->now(),
                $resource?->type,
                $resource?->id,
                $metadata,
            );

            match ($level) {
                AuditLevel::ACTIVITY => $this->messageBus->dispatch(new RecordAuditEntry($entry)),
                AuditLevel::SECURITY => $this->writer->write($entry),
            };
        } catch (Throwable $throwable) {
            // why: auditing is observability, not part of the use case's contract — a dispatch or
            // write hiccup must never turn a successful operation into a 5xx. The gap stays visible
            // (Monolog → Sentry) instead of silent; no offending value or PII in the context.
            $this->logger->warning('Failed to record an audit entry.', [
                'action' => $action,
                'level' => $level->value,
                'exception' => $throwable,
            ]);
        }
    }
    ```
  - [ ] `private function resolveCorrelationId(): string`: leer `RequestStack::getCurrentRequest()?->attributes->get(CorrelationIdListener::ATTRIBUTE_KEY)`; si es un `string` que casa el patrón UUIDv7 canónico, usarlo; si no (CLI/worker, atributo ausente, forma inválida) → `Uuid::generate()` (AC6, D-1.4.d). Reutilizar la constante `CorrelationIdListener::ATTRIBUTE_KEY` (no el literal `'_correlation_id'`).
  - [ ] **No** capturar/dispatch dos veces ni reintentar: `match` exhaustivo sobre los dos casos del enum. **No** abrir transacción para la rama `security` (un solo `INSERT … ON CONFLICT`, atómico de por sí; la transaccionalidad de 1.3 la decide el llamador y aquí es una inserción aislada).
  - [ ] Imports: `MessageBusInterface`, `Throwable`, `Override`, `AsAlias` (Infrastructure-legítimos). **Verificar el conteo de `use` por PHPMD `CouplingBetweenObjects` (≤13)** — esta clase compone muchas piezas; si roza el tope, recortar (no extraer helper a otra clase).

- [ ] **T6 — Handler `RecordAuditEntryHandler`** (AC8) → `api/src/Shared/Audit/Infrastructure/Messenger/RecordAuditEntryHandler.php`
  - [ ] `#[AsMessageHandler] final readonly class RecordAuditEntryHandler` con `public function __construct(private AuditLogWriter $writer) {}` y `public function __invoke(RecordAuditEntry $message): void { $this->writer->write($message->entry); }`. Mirror exacto de `SendEmailOnBankChanged`/`RunProjectionsOnDomainEvent` (adapter dumb; la idempotencia vive en el escritor de 1.3).
  - [ ] Docblock breve: drena el transporte `audit`; el `INSERT … ON CONFLICT (id) DO NOTHING` de 1.3 hace la reentrega un no-op (sin dedup propia). **Sin** IDs de story/AC en el comentario.

- [ ] **T7 — Cablear `messenger.yaml`** (AC9) → `api/config/packages/messenger.yaml`
  - [ ] Añadir el transporte dedicado bajo `transports:`:
    ```yaml
    audit:
        dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
        options:
            queue_name: audit
            auto_setup: false
    ```
  - [ ] Añadir el routing: `Erpify\Shared\Audit\Application\RecordAuditEntry: audit`.
  - [ ] En `when@test.framework.messenger.transports`, añadir `audit: 'in-memory://?serialize=true'` (paridad con `async`/`failed`).
  - [ ] **No** tocar el bus/`PersistDomainEventMiddleware` (auditoría no pasa por el middleware de dominio; `RecordAuditEntry` no es `DomainEvent`, 1.2 AC2). **No** reusar el `async`.

- [ ] **T8 — Que el `messenger_worker` consuma `audit`** (AC9, D-1.4.f) → `compose.yaml`, `compose.prod.yaml`
  - [ ] `compose.yaml` (dev): cambiar el `command` del `messenger_worker` de `["php","bin/console","messenger:consume","async","scheduler_maintenance","--time-limit=3600"]` a incluir `audit` (`… "messenger:consume","async","audit","scheduler_maintenance","--time-limit=3600"`).
  - [ ] `compose.prod.yaml` (pool escalable): añadir `audit` al `messenger:consume` del `messenger_worker` (junto a `async`). **No** tocar `scheduler_worker` (su `scheduler_maintenance` es independiente; la poda de auditoría es Epic 3).
  - [ ] Verificar tras `make app.dev` que el worker arranca consumiendo `audit` (`make docker.logs` del `messenger_worker`, o `make sf c='messenger:stats'` muestra la cola `audit`). Esto es lo que cierra el end-to-end async de `activity` — sin ello, los `RecordAuditEntry` se acumulan en `messenger_messages` sin drenar.

- [ ] **T9 — Tests unitarios** (AC2, AC5, AC7) — mirror `api/tests/Unit/Shared/Audit/{Domain,Application,Infrastructure}/`
  - [ ] `api/tests/Unit/Shared/Audit/Domain/AuditResourceTest.php` con `#[CoversClass(AuditResource::class)]`: `of('Bank', $id)` expone `type`/`id`; props públicos `readonly`.
  - [ ] `api/tests/Unit/Shared/Audit/Infrastructure/RequestStackActorContextFactoryTest.php` con `#[CoversClass(RequestStackActorContextFactory::class)]` (puro, `TestCase`): con un `RequestStack` que tiene una `Request` empujada (`push(Request::create('/api/banks'))`) → `current()` devuelve `ActorContext::anonymous()` (`assertSame(ActorType::ANONYMOUS, ...->type)`); con un `RequestStack` **vacío** → `ActorContext::system()`.
  - [ ] `api/tests/Unit/Shared/Audit/Infrastructure/SymfonyAuditLoggerTest.php` con `#[CoversClass(SymfonyAuditLogger::class)]` (puro, `TestCase`, fakes/stubs de los puertos): cubrir las **tres** conductas clave —
    - **AC4 activity**: con `level=activity`, un `MessageBusInterface` espía recibe un `dispatch(RecordAuditEntry)` y el `AuditLogWriter` (fake in-memory) **no** se llama; el `RecordAuditEntry` despachado envuelve un `AuditLogEntry` cuyo `actor`/`correlationId`/`occurredOn` son los sellados (actor del factory stub, correlación del `RequestStack`, instante del `Clock` fijo).
    - **AC4 security**: con `level=security`, el `AuditLogWriter` fake recibe `write($entry)` y el bus **no** recibe dispatch.
    - **AC7 best-effort**: un `AuditLogWriter` fake que lanza (o un bus que lanza) → `log()` **no** propaga (`$this->expectNotToPerformAssertions()` no — en su lugar: invoca `log()` sin envolver en try, y asierta que el `LoggerInterface` espía recibió un `warning`). Usar `Symfony\Component\Clock\MockClock` o un `Clock` fake para `occurredOn` determinista; `RequestStack` con/ sin request para la correlación.
  - [ ] **Fakes sobre mocks** (regla de testing del repo): preferir un `InMemoryAuditLogWriter` (implementa el puerto, registra los `write`) y un `RecordingMessageBus`/spy. Para el `LoggerInterface` un spy simple. **OJO PHPMD `CouplingBetweenObjects`** en este test (compone muchos colaboradores) — si roza ≤13, mover los fakes a un `trait` reutilizable (lección de 1.3 `coversclass-restricts-clover-and-phpmd-coupling`), no a otra clase con imports.

- [ ] **T10 — Test funcional de las dos ramas** (AC4, AC8, AC10) → `api/tests/Functional/Shared/Audit/SymfonyAuditLoggerBranchingTest.php`
  - [ ] `extends KernelTestCase`, `#[CoversClass(SymfonyAuditLogger::class)]`, `final`, `/** @internal */`. Resolver `AuditLogger::class` del contenedor (`assertInstanceOf`). En `when@test` el transporte `audit` es `in-memory://?serialize=true`.
  - [ ] **activity (AC10)**: `log('BANK_ACCOUNTS_VIEWED', AuditLevel::ACTIVITY, AuditResource::of('Bank', Uuid::generate()))`; asertar que el transporte in-memory `audit` tiene **1** mensaje encolado (`get()` del `InMemoryTransport` resuelto del contenedor, p. ej. `self::getContainer()->get('messenger.transport.audit')`), que **no** se ha escrito fila en `audit_log` síncronamente (contar filas antes/después dentro de transacción con rollback, mirror del aislamiento de 1.3 `DomainEventStoreIdempotencyTest`), y que el `RecordAuditEntry` round-trippea por el serializer (lo garantiza `serialize=true`).
  - [ ] **security (AC4/AC8)**: `log('ACCESS_DENIED', AuditLevel::SECURITY, AuditResource::of('Bank', Uuid::generate()))`; asertar que **1** fila aparece en `audit_log` síncronamente (write-before-send) y que la cola `audit` está **vacía** (no se encoló). Envolver en `beginTransaction()`/`rollBack()` en `finally` (la suite no tiene DAMA, comparte la conexión de dev — mirror de 1.3).
  - [ ] **handler (AC8)** (opcional, si no se cubre vía consume): construir un `RecordAuditEntry` y `RecordAuditEntryHandler` resuelto del contenedor, `__invoke`-arlo, y asertar 1 fila + reentrega = no-op (1 fila). Si se prefiere consumir el transporte de verdad, usar un `Worker` real contra la instancia exacta del transporte (NO `messenger:consume` vía `new Application($kernel)`, que resetea el `InMemoryTransport` — gotcha de sesiones Behat previas).

- [ ] **T11 — Gates** (AC11): orden importa →
  1. `make php.stan` sobre los ficheros nuevos.
  2. Stack arriba (`make app.dev`); el transporte `audit` necesita la BD migrada de 1.3 (`audit_log` existe) para el functional de la rama `security`.
  3. `make php.unit` (Unit + Functional bajo la única "Project Test Suite").
  4. `make php.quality` (deptrac + bounded-context + event-bus + phpmd + cs-fixer + rector). `api/config/reference.php` se regenera: **commitea** el diff, no `git checkout`.
  5. **Re-correr `make php.stan`** sobre los ficheros asentados (Rector reescribe asserts en `php.quality`).
  - [ ] **Barrer del diff** comentarios con IDs de story/AC/FR/NFR/`D-1.4.x` antes del commit final (regla de comentarios de `CLAUDE.md`).

## Dev Notes

### Contexto del subsistema (leer antes de tocar código)

- **Esta historia cierra el flujo de escritura del eje de auditoría.** 1.1 dio el actor (`ActorContext`), 1.2 el dato (`AuditLogEntry`/`RecordAuditEntry`) y el nivel (`AuditLevel`), 1.3 el almacenamiento (`AuditLogWriter` + `DbalAuditLogWriter` + tabla `audit_log`). **1.4 los compone** detrás del seam público `AuditLogger->log(...)`: sella actor + correlación + instante, ramifica por `level` (D3), y mueve la frontera best-effort **dentro** del logger para que 1.5 (el primer consumidor) llame sin `try/catch`. Fuente: [`docs/adr/audit-activity-log.md`](../../docs/adr/audit-activity-log.md) (D2, D3, D6, D7).
- **El branching por nivel es el corazón de D3.** `activity` (alto volumen, observabilidad) → encolar al transporte `audit` y olvidarse (request path libre de IO de `audit_log`, acepta perder un registro si el proceso muere antes de encolar). `security` (raro, fuera del path caliente: denegaciones, API keys, elevaciones) → `INSERT` síncrono write-before-send (una denegación no se pierde si la request llegó a ejecutarse). El sistema es **observabilidad con pérdida parcial tolerada en `activity`**, no logging forense uniforme.
- **Frontera con 1.5 y Epic 2.** 1.4 deja el seam **invocable pero no invocado**: nadie llama `log(...)` todavía. **1.5** es el primer `log('BANK_ACCOUNTS_VIEWED', activity, AuditResource::of('Bank', $bankId))` real (migra `BankAccountSearcher`, borra el placeholder, retira la entrada del `event-dispatch-allowlist`). **Epic 2** añade la captura automática (`AuditPolicy` + subscriber `kernel.terminate` + listener de `AccessDeniedException` → `security`). 1.4 **no** crea ninguna de esas piezas.
- **El placeholder actual (`BankAccountSearcher::recordAccess`) muestra la frontera que 1.4 absorbe.** Hoy `BankAccountSearcher` envuelve el `dispatch` en un `try/catch (ExceptionInterface)` + `logger->warning` para que un hiccup de auditoría no 5xxe la lectura ([`api/src/Backoffice/BankAccount/Application/BankAccountSearcher.php`](../../api/src/Backoffice/BankAccount/Application/BankAccountSearcher.php)). 1.4 **mueve esa frontera al seam** (AC7): tras 1.4, el llamador no necesita `try/catch` porque `AuditLogger->log` nunca propaga. 1.5 retira el `try/catch` de `BankAccountSearcher` al migrar.

### Decisiones técnicas

**D-1.4.a — Carrier de recurso: un VO opcional `AuditResource`, NO dos parámetros `?string $resourceType, ?string $resourceId`. [decisión de diseño / "make illegal states unrepresentable"]**
*Principio en juego:* el epic redacta `log(action, level, resource?, metadata?)` — un **único** parámetro `resource?`, no dos. Dos `?string` sueltos abren el estado ilegal "tipo sin id / id sin tipo" y obligan a cada callsite a pasar `null, null` o a recordar el orden de dos parámetros homogéneos (smell de "data clump"). *Objetivo que compra:* `log('X', activity, AuditResource::of('Bank', $bankId))` es legible y el par tipo+id viaja atado; el adaptador lo desempaqueta a las dos columnas de `AuditLogEntry` (`$resource?->type`, `$resource?->id`) con un solo nullsafe. Es el mismo patrón "envuelve el record" de 1.2 D-1.2.a (`RecordAuditEntry` envuelve `AuditLogEntry`). *Coste / alternativa descartada:* un VO más (≈10 líneas) frente a dos parámetros; pero el VO es la forma que el epic ya nombra (`resource?`) y elimina la clase de bug. Se descartan también: (b) `resourceType`/`resourceId` como params sueltos (data clump, orden frágil), (c) reutilizar `AuditLogEntry` como input de `log()` (rompe el seam: expondría el modelo persistido a los módulos, justo lo que 1.2 AC1 evita).

**D-1.4.b — `log(...)` NO recibe `correlationId`/`actor`/`id`/`occurredOn`; los sella el adaptador. [seguridad + DIP]**
*Principio:* un módulo no debe poder **falsear** el actor ni la correlación de una entrada de auditoría — eso degradaría justo la consulta forense que el subsistema existe para servir. *Objetivo:* el seam acepta solo lo que el llamador legítimamente conoce (`action`, `level`, `resource`, `metadata`); el adaptador sella el resto desde fuentes de confianza (`ActorContextFactory`, `RequestStack`+`CorrelationIdListener`, `Clock`, `AuditLogEntry::create()` que acuña el `id`). *Coste / alternativa descartada:* aceptar `correlationId`/`actor` por parámetro sería más "flexible" pero permite suplantación y obliga a cada callsite a obtenerlos — más superficie, peor seguridad. El sellado en el adaptador es la elección de D2 (el hook/llamador "solo captura contexto", la identidad la pone el subsistema).

**D-1.4.c — La frontera best-effort vive DENTRO de `AuditLogger`, en el seam, no en cada llamador. [SRP + DRY]**
*Principio:* SRP — "no romper el caso de uso por un fallo de auditoría" es responsabilidad **del eje de auditoría**, no de cada Application que lo usa. Hoy esa política está duplicada en `BankAccountSearcher::recordAccess` (try/catch + warning); si cada futuro consumidor la repite, es N copias de la misma decisión. *Objetivo:* una sola frontera (el `try/catch` del adaptador, AC7) → 1.5 y todo consumidor posterior llaman `log(...)` desnudo; la política de "qué se traga y cómo se registra" se cambia en un sitio. *Coste / alternativa descartada:* el epic deja **latitud** ("no se manda tragarse toda excepción a ciegas, queda libertad de implementación") — un `catch (\Throwable)` en el seam es amplio, pero es **consciente y localizado** (un seam, no esparcido), y registra siempre (nunca silencioso). Alternativa descartada: capturar solo `Messenger\Exception\ExceptionInterface` (como el placeholder) — dejaría escapar errores de programación (`ActorContextFactory` mal cableado, `Clock` que lanza, serialización) que el epic **explícitamente** quiere que la frontera absorba ("incluidos errores de programación: configuración rota, serialización, servicio inexistente"). Por eso `Throwable` en el seam, con log a `warning` para que Sentry lo vea.

**D-1.4.d — `correlationId` se lee de `RequestStack`+`CorrelationIdListener`; fuera de request se acuña uno de respaldo. [trusted boundary + gap CLI]**
*Principio:* `AuditLogEntry` exige `correlationId` **no-null** (1.2), y la fuente canónica es el `_correlation_id` del request (`CorrelationIdListener::ATTRIBUTE_KEY`, UUIDv7 minteado en `kernel.request`). No hay servicio holder — el patrón del repo es leer `RequestStack::getCurrentRequest()` (mirror de [`ContentHashUrlGenerator`](../../api/src/Shared/Http/Infrastructure/ContentHashUrlGenerator.php)). *Objetivo:* el camino HTTP reutiliza la correlación end-to-end (la misma que sale en `X-Correlation-Id` y en cada línea PSR-3). *Coste / alternativa descartada — el gap CLI/worker:* fuera de request `getCurrentRequest()` es null y no hay correlación que heredar. Opciones: (a) **acuñar `Uuid::generate()` de respaldo** (cada `log()` de sistema es su propio acto correlacionado consigo mismo) — simple, nunca null, pierde correlación entre varios `log()` del mismo proceso CLI; (b) un `CorrelationContext` holder que el CLI/worker setea por proceso/mensaje — más correcto para reconstruir una "jornada de sistema", pero es infraestructura nueva sin consumidor en Fase 1 (Epic 2 captura `/api/*`, no CLI). **Recomendación: (a)** para 1.4 (YAGNI), **elevada como pregunta** porque toca la semántica forense de los actos de sistema. *Trigger de revisita:* cuando Epic 2/futuro audite trabajo de scheduler/worker y haga falta correlar varios `log()` del mismo tick.

**D-1.4.e — `SymfonyAuditLogger` despacha por `MessageBusInterface` desde Infrastructure; NO por el `EventBus` ni desde Application. [event-bus gate + D1]**
*Principio:* `RecordAuditEntry` **no** es un `DomainEvent` (1.2 AC2) y **no** debe viajar por el `EventBus` transaccional ni por `PersistDomainEventMiddleware` (contaminaría `event_store`/outbox de dominio — la fuga que D1 evita). El gate `make php.lint.event-bus` prohíbe importar `MessageBusInterface` desde `*/Application/`, forzando a los publicadores de **dominio** a usar el puerto `EventBus`. *Objetivo:* el adaptador de auditoría (`SymfonyAuditLogger`) vive en `Infrastructure`, así que **puede** inyectar `MessageBusInterface` directamente sin entrar en el gate ni en el `event-dispatch-allowlist` — y el eje de auditoría queda estructuralmente separado del bus de dominio. *Coste / alternativa descartada:* meter el `dispatch` en una clase de `Application` exigiría una entrada en `api/.event-dispatch-allowlist` (como el placeholder de `BankAccountSearcher`, que 1.5 retira) — innecesario aquí porque el seam ya separa Application (puerto `AuditLogger`) de Infrastructure (adaptador con el bus). Alternativa descartada: un `AuditBus` puerto propio en `Shared/Audit/Application` envolviendo Messenger — abstracción para un solo consumidor (YAGNI); `MessageBusInterface` en el adaptador basta.

**D-1.4.f — El `messenger_worker` debe consumir `audit` explícitamente; añadir el transporte a `messenger.yaml` NO basta. [guardrail operativo]**
*Hecho verificado:* el `messenger_worker` de dev consume hoy `["…","messenger:consume","async","scheduler_maintenance","…"]` ([`compose.yaml`](../../compose.yaml) línea ~129) y el de prod consume `async` ([`compose.prod.yaml`](../../compose.prod.yaml) línea ~169). `messenger:consume` drena **solo** los transportes nombrados en su línea de comando. *Consecuencia:* añadir el transporte `audit` + routing en `messenger.yaml` encola los `RecordAuditEntry` en `messenger_messages` (cola `audit`) pero **nada los drena** salvo que `audit` aparezca en el `messenger:consume` del worker. T8 lo añade en ambos compose. *Coste / alternativa descartada:* un `audit_worker` dedicado (como `scheduler_worker`) — el ADR lo deja como opción de prod por volumen ("en prod puede tener su propio worker"), pero en Fase 1 plegarlo en el `messenger_worker` (dev y prod) es lo mínimo que cierra el end-to-end; un worker dedicado es trigger de revisita por throughput (ADR triggers (b)).

**D-1.4.g — `occurredOn` desde el puerto `Clock` inyectado, NO `SystemClock::now()`. [confirma 1.2 D-1.2.c]**
`AuditLogEntry::create()` recibe `occurredOn` por parámetro (1.2 D-1.2.c). El `AuditLogger` es Infrastructure y la DI **sí** lo alcanza, así que inyecta el puerto `Clock` ([`api/src/Shared/Clock/Domain/Clock.php`](../../api/src/Shared/Clock/Domain/Clock.php), adaptador prod `SymfonyClock`) y pasa `$clock->now()`. **No** usar el accesor ambiental `SystemClock::now()` (reservado para capas que la DI no alcanza — agregados/domain events; lo usa el placeholder `BankAccountsViewedAuditEvent::record()` que 1.5 borra). Esto hace `SymfonyAuditLoggerTest` determinista con un `MockClock`/`Clock` fake, sin `SystemClock::set()/reset()`.

### YAGNI / alcance — qué NO hacer aquí

- **No** crear `AuditPolicy`, el subscriber `kernel.terminate`, ni el listener de `AccessDeniedException` (todo es **Epic 2**).
- **No** llamar `AuditLogger->log(...)` desde ningún consumidor real ni tocar `BankAccountSearcher`/el placeholder/`api/.event-dispatch-allowlist` (eso es **1.5**).
- **No** crear `AuditLogPruner`, retención, ni pseudonimización GDPR (**Epic 3**); **no** tocar `scheduler_maintenance`/`scheduler_worker`.
- **No** crear read model ni nada en `Backoffice/Audit` (**Epic 4**).
- **No** añadir un puerto `AuditBus` ni un `CorrelationContext` holder (D-1.4.e / D-1.4.d — abstracción sin consumidor en Fase 1).
- **No** validar el `id` del `AuditResource` como UUID (1.2 D-1.2.g: es un id de agregado ya validado).
- **No** reusar el transporte `async` ni el `PersistDomainEventMiddleware` para `RecordAuditEntry` (D3 / 1.2 AC2).
- **No** editar `tools/deptrac/deptrac.yaml`, el `deptrac.baseline.yaml` ni `api/.bounded-context-allowlist` (AC11 — `Shared/Audit/*` autoenrolla).
- **No** añadir un `audit_worker` dedicado en compose (D-1.4.f — Fase 1 lo pliega en `messenger_worker`).
- **No** añadir un `retry_strategy` específico ni un `failure_transport` propio para `audit`: hereda el `failed` global; un fallo del handler en el worker es retriable/visible como cualquier otro (no se difiere a Epic 3, que solo hace poda).

### Architecture compliance (guardrails que muerden)

- **Hexagonal / deptrac:** interfaces `AuditLogger`/`ActorContextFactory` en `Application` (importan solo `Shared/Audit/Domain`: `AuditLevel`, `AuditResource`, `ActorContext`). Adaptadores en `Infrastructure` importan Messenger/`RequestStack`/`Clock`/`LoggerInterface`/atributos DI — legítimo. Los colectores `src/Shared/(.*/)?{Application,Infrastructure}` autoenrollan `Shared/Audit/*` en `Shared.*` (verificado en `tools/deptrac/deptrac.yaml` líneas 88-93), como el backbone `Shared/Event`. **No** hace falta bloque deptrac por módulo ni allowlist (mirror 1.3 AC10).
- **Event-bus gate (`make php.lint.event-bus`):** `SymfonyAuditLogger` importa `MessageBusInterface` desde **Infrastructure** → fuera del gate (que solo mira `*/Application/`). **No** se añade entrada a `api/.event-dispatch-allowlist` (esa entrada es la del placeholder de 1.5, que 1.5 retira). D-1.4.e.
- **Bounded-context isolation:** todo es `Erpify\Shared\…` (siempre importable); ninguna pieza entra en el `Domain/` de un contexto de negocio. `make php.lint.bounded-context` verde sin allowlist nuevo.
- **Error contract:** 1.4 no añade marcadores ni respuestas HTTP. La frontera best-effort (AC7) **traga** las excepciones de auditoría y las registra por `LoggerInterface` → **no** llegan al pipeline RFC 9457, **no** hay 5xx ni `type` nuevo. **No** se edita `docs/api-error-contract.md`. (Una `AccessDeniedException` que *dispara* un `log(security)` es Epic 2; aquí no.)
- **Seguridad / `audit_log` es PII:** 1.4 es el primer punto que **escribe** PII en `audit_log` por la rama `security` (vía 1.3), pero la captura real (con `ip`/`user_agent`) es Epic 2 y la política de retención/pseudonimización es Epic 3 — 1.4 **no** edita `PRODUCTION_SECURITY_CHECKLIST.md`/`docs/rules/security.md` (lo hará Epic 3 al introducir la PII de captura y su minimización). El log de la frontera best-effort (AC7) **no** vuelca PII ni el valor ofensivo (solo `action`/`level`/exception). El dispatch/insert va parametrizado (1.3) — sin interpolación en SQL.
- **Migraciones:** 1.4 **no** crea ni edita migraciones (la tabla `audit_log` es de 1.3; el transporte `audit` reusa la tabla `messenger_messages` del outbox Doctrine, `auto_setup: false`, ya existente).
- **Docs de arquitectura:** con 1.4 el flujo de escritura existe end-to-end (seam → transporte → handler → tabla). Procede actualizar [`docs/architecture-api.md`](../../docs/architecture-api.md) (*Async & messaging* — nuevo eje/transporte `audit`) y la sección *Non-domain signals* de [`docs/architecture/event-catalog.md`](../../docs/architecture/event-catalog.md). 1.3 dejó esa actualización **diferida a 1.4/1.5**. *Sugerencia:* documentar el **transporte/eje** (`audit`, branching por nivel) en 1.4 (cuando el seam existe), y dejar la línea concreta de `BANK_ACCOUNTS_VIEWED` en *Non-domain signals* para **1.5** (cuando hay un consumidor real que persiste). **Confirmar con el usuario** (ver *Preguntas*).

### Librerías / framework

- PHP **8.5** (floor `^8.5`). `final readonly class`, `interface`, propiedades promovidas, `#[Override]`, `match` exhaustivo. Sin sintaxis 8.5 inventada de memoria.
- `declare(strict_types=1);` en cada fichero (src y test). Tipos en todo parámetro/retorno/propiedad.
- Symfony Messenger: `Symfony\Component\Messenger\MessageBusInterface` (`dispatch`); `Symfony\Component\Messenger\Attribute\AsMessageHandler`. HTTP: `Symfony\Component\HttpFoundation\{RequestStack,Request}`. DI: `Symfony\Component\DependencyInjection\Attribute\AsAlias`. Logging: `Psr\Log\LoggerInterface`. `Throwable`/`Override` nativos.
- Puertos compuestos: `Clock` (1.2 D-1.2.c), `AuditLogWriter` (1.3), `CorrelationIdListener::ATTRIBUTE_KEY` (constante reutilizada, no literal). UUID de respaldo: `Erpify\Shared\Uuid\Domain\Uuid::generate()`.
- Tests: **PHPUnit 13** con atributos. `TestCase` para unit (puro, fakes de puertos + `MockClock`/`RequestStack`); `KernelTestCase` para el functional (transporte in-memory `audit` + `audit_log` migrada). Resolver el transporte in-memory por id de servicio (`messenger.transport.audit`).

### File structure

```
api/src/Shared/Audit/Domain/AuditResource.php                                      (NEW — VO recurso: of(type,id), constructor privado)
api/src/Shared/Audit/Application/AuditLogger.php                                    (NEW — puerto seam: log(action, level, resource?, metadata?))
api/src/Shared/Audit/Application/ActorContextFactory.php                            (NEW — puerto: current(): ActorContext)
api/src/Shared/Audit/Infrastructure/SymfonyAuditLogger.php                          (NEW — #[AsAlias]; sella + match(level) async/sync; frontera best-effort)
api/src/Shared/Audit/Infrastructure/RequestStackActorContextFactory.php             (NEW — #[AsAlias]; request→anonymous / CLI→system)
api/src/Shared/Audit/Infrastructure/Messenger/RecordAuditEntryHandler.php           (NEW — #[AsMessageHandler]; writer->write($message->entry))
api/config/packages/messenger.yaml                                                  (EDIT — transporte audit + routing RecordAuditEntry + when@test)
compose.yaml                                                                        (EDIT — messenger_worker consume: añadir "audit")
compose.prod.yaml                                                                   (EDIT — messenger_worker consume: añadir "audit")
api/tests/Unit/Shared/Audit/Domain/AuditResourceTest.php                            (NEW)
api/tests/Unit/Shared/Audit/Infrastructure/RequestStackActorContextFactoryTest.php (NEW — request vs sin-request)
api/tests/Unit/Shared/Audit/Infrastructure/SymfonyAuditLoggerTest.php               (NEW — activity/security/best-effort)
api/tests/Functional/Shared/Audit/SymfonyAuditLoggerBranchingTest.php               (NEW — KernelTestCase; in-memory audit + audit_log)
```

Patrón de carpeta: puertos en `Application/` (mirror de `Shared/Event/Application/EventStore.php`); adaptadores HTTP/Messenger/DI en `Infrastructure/` (mirror de `Shared/Event/Infrastructure/Messenger/*` y `Shared/Clock/Infrastructure/SymfonyClock.php`); VO en `Domain/` (mirror de `ActorContext`). 1.1 creó `Domain/`, 1.2 `Application/`, 1.3 `Infrastructure/Persistence/`; 1.4 añade `Infrastructure/` raíz + `Infrastructure/Messenger/` del módulo. El functional vive en `tests/Functional/Shared/Audit/` (no `Persistence/`, porque prueba el branching del logger, no el escritor de 1.3).

### Testing requirements

- **Unit (puro, `TestCase`):** sin kernel ni BD para `AuditResourceTest`, `RequestStackActorContextFactoryTest` y `SymfonyAuditLoggerTest`. Para el logger, **fakes in-memory de los puertos** sobre mocks (regla de testing del repo): `InMemoryAuditLogWriter` (registra los `write`), un `RecordingMessageBus` (registra los `dispatch`; o un mock de `MessageBusInterface` que devuelva un `Envelope`), un spy de `LoggerInterface`, un `Clock` fake o `Symfony\Component\Clock\MockClock` para `occurredOn` determinista, y un `RequestStack` poblado/vacío para la correlación y el actor. Cubrir: activity→dispatch (no write), security→write (no dispatch), best-effort→writer/bus que lanza ⇒ no propaga + `warning` registrado.
- **Functional (integración real, `KernelTestCase`):** resolver `AuditLogger::class` del contenedor; el transporte `audit` es `in-memory://?serialize=true` en `when@test`. Distinguir las dos ramas: activity → 1 mensaje en `messenger.transport.audit` + 0 filas síncronas en `audit_log`; security → 1 fila en `audit_log` + 0 mensajes encolados. Aislamiento `beginTransaction()`/`rollBack()` en `finally` (sin DAMA, conexión de dev compartida — mirror [`DomainEventStoreIdempotencyTest`](../../api/tests/Functional/Shared/Persistence/DomainEventStoreIdempotencyTest.php)). Requiere `audit_log` migrada (1.3) → respetar el orden de T11.
- **Cobertura por `#[CoversClass]`:** SonarCloud `new_coverage` acredita solo al target. `SymfonyAuditLoggerTest`/`…BranchingTest` → `#[CoversClass(SymfonyAuditLogger::class)]`; `RequestStackActorContextFactoryTest` → `#[CoversClass(RequestStackActorContextFactory::class)]`; `AuditResourceTest` → `#[CoversClass(AuditResource::class)]`. El `RecordAuditEntryHandler` necesita su propio cubridor (un `#[CoversClass(RecordAuditEntryHandler::class)]`, sea unit con fake writer o el caso opcional de T10). Los puertos (`AuditLogger`/`ActorContextFactory`) son interfaces (sin líneas ejecutables → sin cobertura). No confiar en cobertura cruzada "gratis" (lección PR #364).
- **Gotchas de tooling (de 1.1/1.2/1.3 y sesiones previas):**
  - **PHPMD `CouplingBetweenObjects` (≤13)** cuenta imports de cuerpo de clase, también en tests. `SymfonyAuditLogger` y su test componen muchos colaboradores (bus, writer, factory, clock, requeststack, logger, entry, message, level, resource, uuid, correlationlistener) → vigila el conteo; si se pasa, en el **test** mueve los fakes a un `trait` (no a otra clase con imports), y en **src** no instancies tipos que no necesitas. Lección 1.3 `coversclass-restricts-clover-and-phpmd-coupling`.
  - **PHPMD `TooManyPublicMethods` (≤10)** cuenta métodos de test: si `SymfonyAuditLoggerTest` se acerca al tope, fusiona casos (foreach / un método por rama), no añadas providers de más (lección PR #364). Aquí hay holgura (3-4 métodos).
  - **Rector reescribe asserts en `php.quality`** (`assertEquals`→`assertSame` en escalares; `AssertEmptyNullableObjectToAssertInstanceofRector` sobre getters nullable de objeto). Tras `php.stan` verde corre `php.quality` y **re-corre `php.stan`** sobre los ficheros asentados. Para round-trip de objetos (`RecordAuditEntry` despachado) usa `assertEquals` (Rector no lo toca).
  - **Consume del transporte in-memory:** si el functional consume el transporte de verdad (rama opcional de T10), construye un `Worker` real contra la instancia exacta del transporte — **no** `messenger:consume` vía `new Application($kernel)`, que resetea el `InMemoryTransport` y descarta el mensaje (gotcha `behat-consume-inmemory-worker-not-command`).
  - **`php.quality` regenera `api/config/reference.php`**: commitea el diff, no `git checkout`.

### Git intelligence (rama `feat/shared-audit-actor-context-5sz9`)

- Estado de la rama (base `df391a26`): specs de planning/docs de 1.1/1.2/1.3 + ADR/epics; **el código del subsistema aún no está en disco** salvo lo que aporten los commits de 1.1→1.2→1.3. En la entrega de PR único los commits de implementación siguen 1.1 → 1.2 → 1.3 → **1.4** (cuarto commit de implementación), encadenado sobre 1.3.
- Commit sugerido (Conventional Commits, scope `shared`): `feat(shared): add AuditLogger seam with per-level persistence and ActorContextFactory`.
- **Barrer del diff** comentarios con IDs de story/AC/FR/NFR/`D-1.4.x` antes del commit final (regla de `CLAUDE.md`); son andamiaje de desarrollo, no van a `main`.
- Recuerda que T8 toca `compose.yaml`/`compose.prod.yaml` (raíz del monorepo) — no son ficheros de `Shared/Audit`, pero son **load-bearing** para el end-to-end async (D-1.4.f). Inclúyelos en el mismo commit.

### Project Structure Notes

- `api/src/Shared/Audit/` lo inician 1.1 (`Domain/`), 1.2 (`Application/`) y 1.3 (`Infrastructure/Persistence/`). 1.4 añade `Infrastructure/` (raíz, los dos adaptadores `#[AsAlias]`) e `Infrastructure/Messenger/` (el handler) — completando la organización vertical-slice del módulo (mirror del espejo `Shared/Event/Infrastructure/{Messenger,Persistence}`). Coherente con [`docs/adr/shared-module-organization.md`](../../docs/adr/shared-module-organization.md).
- Sin conflictos de estructura. `messenger.yaml` es un punto de edición compartido — esta historia lo toca sola (no paralelizar con otra que edite transports/routing). `compose.yaml`/`compose.prod.yaml` igual. Sin nuevos targets `make`.

### References

- [`docs/adr/audit-activity-log.md`](../../docs/adr/audit-activity-log.md) — D2 (la política decide; el seam es `AuditLogger->log(action, level, resource?, metadata?)`), **D3** (persistencia por nivel: `activity` async al transporte `audit`, `security` write-before-send; transporte dedicado; "en dev se pliega en `messenger_worker`"), D6 (correlación obligatoria), D7 + "Secuencia frente a auth" (`ActorContextFactory` = única pieza que cambia con auth; `anonymous`/`system` hasta entonces).
- [`_bmad-output/planning-artifacts/epics.md`](../planning-artifacts/epics.md) — Epic 1 / Story 1.4 (ACs originales), FR2/FR4/FR14/FR16, NFR2/NFR7/NFR10; *Additional Requirements* (transporte `audit` dedicado + routing + worker, reuso de `CorrelationIdListener`, puerto `Clock`).
- [`1-1-actorcontext-con-actortype-tipado.md`](./1-1-actorcontext-con-actortype-tipado.md) — factorías de `ActorContext` (`anonymous()`/`system()`/`forUser()`/`forApiKey()`) que `ActorContextFactory` invoca.
- [`1-2-auditlevel-mensaje-interno-recordauditentry-modelo-auditlogentry-separados-del-domainevent.md`](./1-2-auditlevel-mensaje-interno-recordauditentry-modelo-auditlogentry-separados-del-domainevent.md) — firma de `AuditLogEntry::create()` que el adaptador invoca, `RecordAuditEntry` (envuelve `entry`), `AuditLevel`; D-1.2.c (reloj inyectado), D-1.2.f (branching en `AuditLogger`), D-1.2.g (`correlationId`/`resourceId` sin re-validar).
- [`1-3-tabla-audit-log-append-only-escritor-idempotente-raw-dbal-schema-listener.md`](./1-3-tabla-audit-log-append-only-escritor-idempotente-raw-dbal-schema-listener.md) — puerto `AuditLogWriter` (`write(AuditLogEntry): void`) que invocan el handler y la rama `security`; idempotencia por PK (`ON CONFLICT (id) DO NOTHING`).
- [`api/src/Backoffice/Bank/Infrastructure/Messenger/SendEmailOnBankChanged.php`](../../api/src/Backoffice/Bank/Infrastructure/Messenger/SendEmailOnBankChanged.php) + [`api/src/Shared/Event/Infrastructure/Messenger/RunProjectionsOnDomainEvent.php`](../../api/src/Shared/Event/Infrastructure/Messenger/RunProjectionsOnDomainEvent.php) — forma del `#[AsMessageHandler] final readonly` adapter que calca `RecordAuditEntryHandler`.
- [`api/src/Shared/Http/Infrastructure/CorrelationIdListener.php`](../../api/src/Shared/Http/Infrastructure/CorrelationIdListener.php) — `ATTRIBUTE_KEY = '_correlation_id'`, UUIDv7 minteado en `kernel.request`; fuente del `correlationId` vía `RequestStack` (AC6, D-1.4.d).
- [`api/src/Shared/Http/Infrastructure/ContentHashUrlGenerator.php`](../../api/src/Shared/Http/Infrastructure/ContentHashUrlGenerator.php) — patrón idiomático `RequestStack::getCurrentRequest() instanceof Request` para distinguir request vs off-request (AC5/AC6).
- [`api/src/Shared/Clock/Domain/Clock.php`](../../api/src/Shared/Clock/Domain/Clock.php) + [`api/src/Shared/Clock/Infrastructure/SymfonyClock.php`](../../api/src/Shared/Clock/Infrastructure/SymfonyClock.php) — puerto `Clock` inyectado + adaptador `#[AsAlias]` (D-1.4.g); patrón `#[AsAlias]` + `#[Override]` que calcan los adaptadores de 1.4.
- [`api/src/Backoffice/BankAccount/Application/BankAccountSearcher.php`](../../api/src/Backoffice/BankAccount/Application/BankAccountSearcher.php) — la frontera best-effort actual (`try/catch (ExceptionInterface)` + `logger->warning`) que 1.4 mueve al seam (AC7, D-1.4.c) y 1.5 retira.
- [`api/config/packages/messenger.yaml`](../../api/config/packages/messenger.yaml) — transports/routing/`when@test` a editar (T7); patrón del transporte `async` + override in-memory.
- [`compose.yaml`](../../compose.yaml) (línea ~129) + [`compose.prod.yaml`](../../compose.prod.yaml) (línea ~169) — `messenger:consume` del `messenger_worker` a editar para drenar `audit` (T8, D-1.4.f).
- [`api/tests/Functional/Shared/Persistence/DomainEventStoreIdempotencyTest.php`](../../api/tests/Functional/Shared/Persistence/DomainEventStoreIdempotencyTest.php) — plantilla del functional (`KernelTestCase`, `beginTransaction`/`rollBack`, resolver puerto del contenedor).
- [`api/.event-dispatch-allowlist`](../../api/.event-dispatch-allowlist) — la entrada del placeholder (1.5 la retira); 1.4 **no** la toca (D-1.4.e: el adaptador despacha desde Infrastructure).

## Preguntas para Sergio (resolver antes o durante la implementación)

1. **`correlationId` fuera de request (CLI/worker) — `Uuid::generate()` de respaldo vs. holder de correlación (D-1.4.d).** `AuditLogEntry` exige `correlationId` no-null; en HTTP se lee del `_correlation_id` del request, pero en CLI/scheduler/worker no hay request. Por defecto 1.4 **acuña un UUIDv7 de respaldo** por cada `log()` de sistema (nunca null; pierde correlación entre varios `log()` del mismo proceso CLI). Alternativa: un `CorrelationContext` holder seteable por proceso/mensaje (más correcto para una "jornada de sistema", pero infraestructura nueva sin consumidor en Fase 1 — Epic 2 audita `/api/*`). **Recomendación: respaldo `Uuid::generate()`** (YAGNI); revisita cuando se audite trabajo de scheduler/worker.

2. **Worker de auditoría: plegado en `messenger_worker` vs. `audit_worker` dedicado (D-1.4.f / D3).** El ADR deja abierto que prod tenga su propio worker de auditoría (como `scheduler_worker`). Por defecto 1.4 **pliega el consumo de `audit` en el `messenger_worker`** existente (dev y prod), que es lo mínimo para cerrar el end-to-end. ¿OK, o quieres ya un `audit_worker` aislado en `compose.prod.yaml` (más aislamiento de throughput, más infra)? **Recomendación: plegar en `messenger_worker`** (Fase 1); aislar cuando el volumen lo pida (ADR trigger (b)).

3. **Carrier de recurso: VO `AuditResource` vs. dos parámetros `?string` (D-1.4.a).** El epic escribe `log(action, level, resource?, metadata?)` — un único `resource?`. 1.4 lo modela como un VO opcional `AuditResource::of(type, id)` (ata el par, elimina el estado ilegal tipo-sin-id). ¿OK, o prefieres `?string $resourceType, ?string $resourceId` sueltos? **Recomendación: VO `AuditResource`.**

4. **Actualización de docs de arquitectura: ¿1.4 o 1.5? (Architecture compliance).** 1.3 difirió la actualización de `docs/architecture-api.md` / `event-catalog.md`. Propuesta: 1.4 documenta el **transporte/eje `audit`** y el branching por nivel en `architecture-api.md` (*Async & messaging*) cuando el seam ya existe; 1.5 actualiza la línea de `BANK_ACCOUNTS_VIEWED` en *Non-domain signals* (cuando hay un consumidor real que persiste). ¿OK, o concentramos toda la doc en 1.5? **Recomendación: dividir como arriba.**

5. **Nivel de log de la frontera best-effort: `warning` vs `error` (AC7).** Un fallo de auditoría es accionable (config rota / serialización) pero no rompe el negocio. El placeholder usa `warning`. ¿`warning` o `error` para que destaque más en Sentry? **Recomendación: `warning`** (consistente con el placeholder y con `SendEmailOnBankChanged`; el bridge Monolog→Sentry lo recoge igual).

## Dev Agent Record

### Agent Model Used

### Debug Log References

### Completion Notes List

### File List
