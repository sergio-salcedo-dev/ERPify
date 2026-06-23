# Story 1.3: Tabla `audit_log` append-only + escritor idempotente (raw DBAL + schema listener)

Status: ready-for-dev

<!-- Epic 1 — Registro de auditoría end-to-end (backbone + primer actor auditado).
     Tercera historia del subsistema de auditoría operativa/de actor. Persiste el AuditLogEntry de 1.2.
     Ver ADR docs/adr/audit-activity-log.md (D1, D3, D4, esquema audit_log). -->

## Story

Como plataforma de ERPify,
quiero la tabla `audit_log` **append-only** (raw DBAL, sin entidad ORM, mantenida schema-aware por un `postGenerateSchema` listener) con un **escritor idempotente** (`INSERT … ON CONFLICT (id) DO NOTHING`),
para registrar acciones de forma duradera, inmutable y sin duplicados ante reentrega del worker.

Esta historia construye **la capa de almacenamiento** del eje de auditoría: la tabla, su declaración de esquema (de la que `make db.diff` genera la migración), y el adaptador DBAL que persiste un `AuditLogEntry` (1.2) leyendo sus props públicos. Es **Infrastructure + Application puros de `Shared/Audit`**: NO crea el puerto público `AuditLogger`, ni el `ActorContextFactory`, ni el `RecordAuditEntryHandler`, ni el transporte `audit` (todo eso es **1.4**); NO toca captura (Epic 2), retención/GDPR (Epic 3) ni read model (Epic 4). El escritor recibe un `AuditLogEntry`, **no** un `RecordAuditEntry` (el desempaquetado del mensaje y el branching por `level` viven en 1.4). El patrón es un calco directo del subsistema `event_store` (`DbalEventStore` + `EventStoreSchemaListener`).

## Acceptance Criteria

**AC1 — La tabla `audit_log` existe con el esquema del ADR, generada vía schema listener + migración.**
**Given** el `AuditLogSchemaListener` (`postGenerateSchema`) como fuente de verdad y una migración generada con `make db.diff`,
**When** se aplica (`make db.migrate`),
**Then** existe `audit_log` con exactamente estas columnas y nulabilidad: `id` uuid **PK** (not null), `level` string (not null), `action` string (not null), `actor_type` string (not null), `actor_id` uuid **null**, `correlation_id` uuid (not null), `resource_type` string **null**, `resource_id` uuid **null**, `metadata` jsonb (not null), `ip` string **null**, `user_agent` string **null**, `occurred_on` timestamptz (not null);
**And** existen los cuatro índices del ADR: `(actor_id, occurred_on)`, `(correlation_id)`, `(level, occurred_on)`, `(resource_type, resource_id)` (FR3, NFR9).

**AC2 — Schema-aware: ni `DROP` ni `diff` espurios, sin entidad ORM.**
**Given** el `postGenerateSchema` listener registrado por atributo,
**When** se re-ejecuta `make db.diff` (o `make db.validate` / `doctrine:schema:validate`) con la tabla ya aplicada,
**Then** NO se propone `DROP TABLE audit_log` ni ningún diff sobre ella, y el esquema queda in-sync **sin** ninguna entidad ORM ni mapping en `doctrine.yaml` (NFR6) — igual que `event_store`/`bank_count`.

**AC3 — Puerto escritor (write-only) + adaptador DBAL aliaseado por atributo.**
**Given** la capa de almacenamiento,
**When** se examina,
**Then** existe el puerto `AuditLogWriter` en `Shared/Audit/Application` con un único método `write(AuditLogEntry $entry): void`, y un adaptador `DbalAuditLogWriter` (`final readonly`) en `Shared/Audit/Infrastructure/Persistence` que implementa el puerto, recibe el `Doctrine\DBAL\Connection` por constructor y se enlaza vía `#[AsAlias(AuditLogWriter::class)]` (cero YAML, autoconfigure);
**And** el puerto **no** declara método de lectura (`stream`/`find`) — la consulta llega en Epic 4 directamente sobre `audit_log` (FR19); SRP: este puerto solo escribe.

**AC4 — El `id` es app-minted (de `AuditLogEntry`), nunca generado por Postgres.**
**Given** un `AuditLogEntry` a persistir,
**When** el escritor inserta,
**Then** el `id` de la fila es exactamente `$entry->id` (el UUIDv7 acuñado en `AuditLogEntry::create()`, Story 1.2), **no** un valor generado por la BD; la columna `id` no tiene default ni identidad de servidor (la idempotencia de AC5 depende de ello) (FR4).

**AC5 — Inserción idempotente: `ON CONFLICT (id) DO NOTHING`.**
**Given** el escritor DBAL,
**When** persiste un `AuditLogEntry` y luego re-persiste **el mismo** `id`,
**Then** la segunda inserción afecta **0 filas**, sin lanzar excepción, y `audit_log` contiene exactamente **una** fila para ese `id` (FR4, NFR7) — **test de integración contra Postgres real**.

**AC6 — La idempotencia es solo por `id` (sin deduplicación semántica).**
**Given** dos `AuditLogEntry` creados por `create(...)` con los **mismos** datos de negocio (mismo `action`, `actor`, `correlationId`, etc.) pero **distinto** `id` (cada `create()` acuña uno nuevo),
**When** se persisten ambos,
**Then** resultan **dos** filas distintas: la tolerancia a reentrega cubre el **mismo** mensaje (mismo `id`), no un mensaje regenerado — no hay dedup semántica (FR4).

**AC7 — Append-only: el escritor solo inserta; no hay ruta de `UPDATE`/`DELETE`.**
**Given** la superficie de la capa de almacenamiento de 1.3,
**When** se audita el código,
**Then** `DbalAuditLogWriter` solo ejecuta el `INSERT … ON CONFLICT … DO NOTHING`; no existe ninguna ruta de `UPDATE`/`DELETE` sobre `audit_log` (la poda y la pseudonimización GDPR son **Epic 3**, fuera de alcance aquí) (FR9).

**AC8 — `level`/`actor_type` se persisten como el `value` de su enum backed; sin enum nativo Postgres ni reutilización Doctrine de `EnumType`.**
**Given** los enums `AuditLevel` (1.2) y `ActorType` (1.1),
**When** el escritor mapea `level`/`actor_type` a columnas,
**Then** escribe `$entry->level->value` (`'activity'|'security'`) y `$entry->actor->type->value` (`'anonymous'|'system'|'api_key'|'user'`) como **strings planos** en columnas string; la validez del valor la garantiza el **tipo enum en PHP** (un caso ilegal es inconstruible), no un tipo `ENUM` de Postgres ni un `CHECK` (no existe precedente de ninguno en el repo — `BankAccountStatus`→`TEXT`, `Currency`→`VARCHAR`). Ver **Decisión D-1.3.d** y la pregunta abierta sobre la redacción del epic ("reutilizan `EnumType`/`EnumTypeValidator`").

**AC9 — El mapeo de columnas hace round-trip contra Postgres real.**
**Given** un `AuditLogEntry` con todos los campos poblados (recurso, `metadata` no vacío, `ip`, `userAgent`, `actor` con `actorId`),
**When** se persiste y se lee la fila de vuelta (`fetchAssociative`),
**Then** cada columna coincide con el `AuditLogEntry` de origen: `level`/`action`/`actor_type` como strings; `actor_id`/`correlation_id`/`resource_id` como uuid; `metadata` decodificado como el array original; `occurred_on` como el instante original; `ip`/`user_agent` literales — los `CAST(... AS UUID|JSONB|TIMESTAMPTZ)` son correctos (FR3) — **test de integración**;
**And** un `AuditLogEntry` mínimo (`actor` anónimo → `actor_id` null, sin recurso, `metadata` `[]`) escribe `actor_id`/`resource_type`/`resource_id`/`ip`/`user_agent` como `NULL` y `metadata` como `[]`/`{}` JSONB.

**AC10 — Ubicación, aislamiento y gates verdes.**
**Given** las piezas,
**When** se ubican y se corren los gates,
**Then** `AuditLogWriter` vive en `Shared/Audit/Application` y `DbalAuditLogWriter`/`AuditLogSchemaListener` en `Shared/Audit/Infrastructure/Persistence`; `make php.deptrac` y `make php.lint.bounded-context` quedan verdes **sin** bloque deptrac ni allowlist nuevos (los colectores `src/Shared/(.*/)?{Application,Infrastructure}` autoenrollan `Shared/Audit/*`, como el backbone `Shared/Event`);
**And** `make php.stan`, `make php.unit` (incluye el test funcional de integración) y `make php.quality` quedan verdes;
**And** la migración tiene un `down()` reversible real (`DROP TABLE IF EXISTS audit_log`) — la app no está en producción (ADR), un down destructivo es aceptable y consistente con la baseline.

## Tasks / Subtasks

- [ ] **T0 — Verificar prerequisitos en disco (1.1 + 1.2)** — bloqueante.
  - [ ] Confirmar que existe `api/src/Shared/Audit/Domain/ActorContext.php` (props públicos `readonly` `type: ActorType`, `actorId: ?string`) y `ActorType`/`ActorContext` de la **Story 1.1**.
  - [ ] Confirmar que existe `api/src/Shared/Audit/Application/AuditLogEntry.php` (props públicos `readonly`: `id`, `level: AuditLevel`, `action`, `actor: ActorContext`, `correlationId`, `occurredOn: DateTimeImmutable`, `resourceType: ?string`, `resourceId: ?string`, `metadata: array<string,mixed>`, `ip: ?string`, `userAgent: ?string`) y el enum `AuditLevel` de la **Story 1.2**. En la entrega de PR único los commits siguen la secuencia 1.1 → 1.2 → 1.3; **si 1.2 no está en disco, 1.3 está bloqueada** (el escritor lee los props de `AuditLogEntry`).

- [ ] **T1 — `AuditLogSchemaListener`** (AC1, AC2, AC8, AC10) → `api/src/Shared/Audit/Infrastructure/Persistence/AuditLogSchemaListener.php`
  - [ ] `#[AsDoctrineListener(event: ToolEvents::postGenerateSchema)]` `final class AuditLogSchemaListener` con `private const string TABLE = 'audit_log';` y el guard `if ($schema->hasTable(self::TABLE)) { return; }`. Mirror exacto de `EventStoreSchemaListener`.
  - [ ] Columnas (usar constantes `Doctrine\DBAL\Types\Types`):
    - `id` → `Types::GUID`
    - `level` → `Types::STRING, ['length' => 16]`
    - `action` → `Types::STRING, ['length' => 100]`
    - `actor_type` → `Types::STRING, ['length' => 16]`
    - `actor_id` → `Types::GUID, ['notnull' => false]`
    - `correlation_id` → `Types::GUID`
    - `resource_type` → `Types::STRING, ['length' => 100, 'notnull' => false]`
    - `resource_id` → `Types::GUID, ['notnull' => false]`
    - `metadata` → `Types::JSONB`
    - `ip` → `Types::STRING, ['length' => 45, 'notnull' => false]`
    - `user_agent` → `Types::STRING, ['length' => 512, 'notnull' => false]`
    - `occurred_on` → `Types::DATETIMETZ_IMMUTABLE`
  - [ ] PK: `$table->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create());`
  - [ ] Índices (no únicos): `$table->addIndex(['actor_id', 'occurred_on'], 'audit_log_actor_idx');` · `addIndex(['correlation_id'], 'audit_log_correlation_idx');` · `addIndex(['level', 'occurred_on'], 'audit_log_level_idx');` · `addIndex(['resource_type', 'resource_id'], 'audit_log_resource_idx');`
  - [ ] Docblock breve de clase en la línea del de `EventStoreSchemaListener`: append-only, sin entidad ORM, este listener es la fuente de verdad y de él se genera la baseline; Doctrine no modela `DEFAULT now()`/`CHECK`/enum nativo → los valores los aporta el escritor. **Sin** IDs de story/AC/NFR en el comentario (regla de comentarios de `CLAUDE.md`).

- [ ] **T2 — Puerto `AuditLogWriter`** (AC3) → `api/src/Shared/Audit/Application/AuditLogWriter.php`
  - [ ] `interface AuditLogWriter { public function write(AuditLogEntry $entry): void; }`. Importa solo `Erpify\Shared\Audit\Application\AuditLogEntry` (misma capa). **Sin** método de lectura (write-only; las consultas son Epic 4 directo sobre la tabla). Mirror de `Shared/Event/Application/EventStore.php` (reducido a la escritura).
  - [ ] Docblock breve: puerto de salida de la bitácora append-only `audit_log`; lo invoca el `RecordAuditEntryHandler`/inserción síncrona de **1.4**.

- [ ] **T3 — Adaptador `DbalAuditLogWriter`** (AC3, AC4, AC5, AC8, AC9) → `api/src/Shared/Audit/Infrastructure/Persistence/DbalAuditLogWriter.php`
  - [ ] `#[AsAlias(AuditLogWriter::class)] final readonly class DbalAuditLogWriter implements AuditLogWriter` con `public function __construct(private Connection $connection) {}` (DBAL `Connection` por defecto, autowired). `#[Override]` en `write()`.
  - [ ] `INSERT INTO audit_log (...) VALUES (...) ON CONFLICT (id) DO NOTHING` vía `$this->connection->executeStatement(...)` con binding **nombrado** `:placeholder` (mirror del `VALUES … ON CONFLICT` de `DbalDomainEventHandlerDeduplicator`, no del `INSERT … SELECT` de `DbalEventStore` — `audit_log` no tiene columna calculada). Casts de tipo en SQL como en `DbalEventStore::append()`:
    ```php
    $this->connection->executeStatement(
        'INSERT INTO audit_log '
        . '(id, level, action, actor_type, actor_id, correlation_id, resource_type, resource_id, '
        . 'metadata, ip, user_agent, occurred_on) '
        . 'VALUES (CAST(:id AS UUID), :level, :action, :actor_type, CAST(:actor_id AS UUID), '
        . 'CAST(:correlation_id AS UUID), :resource_type, CAST(:resource_id AS UUID), '
        . 'CAST(:metadata AS JSONB), :ip, :user_agent, CAST(:occurred_on AS TIMESTAMPTZ)) '
        . 'ON CONFLICT (id) DO NOTHING',
        [
            'id'             => $entry->id,
            'level'          => $entry->level->value,
            'action'         => $entry->action,
            'actor_type'     => $entry->actor->type->value,
            'actor_id'       => $entry->actor->actorId,
            'correlation_id' => $entry->correlationId,
            'resource_type'  => $entry->resourceType,
            'resource_id'    => $entry->resourceId,
            'metadata'       => json_encode($entry->metadata, JSON_THROW_ON_ERROR),
            'ip'             => $entry->ip,
            'user_agent'     => $entry->userAgent,
            'occurred_on'    => $entry->occurredOn->format('Y-m-d H:i:s.uP'),
        ],
    );
    ```
  - [ ] `metadata` se serializa con `json_encode(..., JSON_THROW_ON_ERROR)` (mirror del `encode()` de `DbalEventStore`); `occurred_on` con `->format('Y-m-d H:i:s.uP')` (microsegundos + offset). Los `actor_id`/`resource_id` nulos pasan como `null`: `CAST(:actor_id AS UUID)` con valor `null` rinde `NULL` (correcto).
  - [ ] **No** capturar la excepción ni añadir lógica best-effort aquí — la frontera best-effort (que un fallo de auditoría nunca tumbe el caso de uso) es responsabilidad de **1.4**; el escritor falla ruidosamente si la BD falla. **No** abrir transacción propia (la transaccionalidad la decide el llamador de 1.4: `security` síncrono dentro del request, `activity` en el handler del worker).

- [ ] **T4 — Generar y aplicar la migración** (AC1, AC2, AC10)
  - [ ] Con el stack arriba (`make app.dev` o `make docker.up`), correr `make db.diff` → genera `api/migrations/2026/VersionYYYYMMDDHHMMSS.php` con el `CREATE TABLE audit_log` + los 4 `CREATE INDEX` derivados del listener T1.
  - [ ] Revisar el `up()`: `CREATE TABLE audit_log (...)` con `id UUID NOT NULL`, `level VARCHAR(16) NOT NULL`, `action VARCHAR(100) NOT NULL`, `actor_type VARCHAR(16) NOT NULL`, `actor_id UUID DEFAULT NULL`, `correlation_id UUID NOT NULL`, `resource_type VARCHAR(100) DEFAULT NULL`, `resource_id UUID DEFAULT NULL`, `metadata JSONB NOT NULL`, `ip VARCHAR(45) DEFAULT NULL`, `user_agent VARCHAR(512) DEFAULT NULL`, `occurred_on TIMESTAMP(0) WITH TIME ZONE NOT NULL`, `PRIMARY KEY (id)` + los 4 índices. Plain `CREATE INDEX` (transaccional; **no** `CONCURRENTLY` — rompería `--all-or-nothing`, ver `api/CLAUDE.md`).
  - [ ] Asegurar un `down()` real: `DROP TABLE IF EXISTS audit_log` (la baseline dropea explícitamente; usar `IF EXISTS` por resiliencia). Si `make db.diff` no emite el `down`, completarlo a mano (editar una migración de la rama actual está permitido).
  - [ ] `make db.migrate` para aplicarla en la BD de dev (necesario para que el test funcional de T6 encuentre la tabla).
  - [ ] Re-correr `make db.diff` → debe reportar **"No changes detected"** (cierra AC2). Si propone un diff sobre `audit_log`, alinear el listener T1 con lo que Doctrine espera (típicamente `length`/`notnull`) hasta que el round-trip sea estable.

- [ ] **T5 — Test unitario del schema listener** (AC1, AC2) → `api/tests/Unit/Shared/Audit/Infrastructure/Persistence/AuditLogSchemaListenerTest.php`
  - [ ] `extends PHPUnit\Framework\TestCase` (puro, sin kernel), `#[CoversClass(AuditLogSchemaListener::class)]`, `final`, `/** @internal */`. Mirror de `EventStoreSchemaListenerTest`.
  - [ ] `itInjectsTheAppendOnlyAuditLogTable`: `new Schema()`, `(new AuditLogSchemaListener())->postGenerateSchema(new GenerateSchemaEventArgs($this->createStub(EntityManagerInterface::class), $schema))`; aserta `$schema->hasTable('audit_log')`, las **12** columnas (`hasColumn`), los **4** índices (`hasIndex('audit_log_actor_idx')`, …), y la nulabilidad-clave para fijar el contrato: `$table->getColumn('actor_id')->getNotnull()` **false**, `$table->getColumn('correlation_id')->getNotnull()` **true**, `$table->getColumn('metadata')->getNotnull()` **true** (PHPStan: `getNotnull()` devuelve `bool`).
  - [ ] `itLeavesAnExistingTableUntouched`: llamar `postGenerateSchema` **dos veces** con el mismo `$args` → el guard `hasTable` lo hace idempotente; `assertTrue($schema->hasTable('audit_log'))`.

- [ ] **T6 — Test funcional de integración del escritor** (AC4, AC5, AC6, AC9) → `api/tests/Functional/Shared/Persistence/AuditLogWriterIdempotencyTest.php`
  - [ ] `extends Symfony\Bundle\FrameworkBundle\Test\KernelTestCase`, `#[CoversClass(DbalAuditLogWriter::class)]`, `final`, `/** @internal */`. Mirror de `DomainEventStoreIdempotencyTest` (misma carpeta `tests/Functional/Shared/Persistence/`, donde ya viven los tests funcionales de `Shared/Event`).
  - [ ] Resolver el escritor por el puerto: `$writer = self::getContainer()->get(AuditLogWriter::class);` `assertInstanceOf(AuditLogWriter::class, $writer)`. Conexión vía `self::getContainer()->get(EntityManagerInterface::class)->getConnection()`.
  - [ ] **Aislamiento**: `beginTransaction()` … todo el trabajo … `finally { if ($connection->isTransactionActive()) $connection->rollBack(); }` — la suite no tiene auto-rollback DAMA y comparte la conexión de la BD de dev; el test no debe dejar filas.
  - [ ] **Idempotencia (AC5)**: construir `$entry = AuditLogEntry::create(AuditLevel::ACTIVITY, 'BANK_ACCOUNTS_VIEWED', ActorContext::anonymous(), Uuid::generate(), new DateTimeImmutable('2026-01-01T00:00:00+00:00'))`; `$writer->write($entry)` → `assertSame(1, $this->countRowsForId($connection, $entry->id))`; `$writer->write($entry)` otra vez → `assertSame(1, …, 'second write must be a no-op')`.
  - [ ] **Id-scoped (AC6)**: crear un segundo `AuditLogEntry::create(...)` con los **mismos** datos de negocio (otra vez `Uuid::generate()` interno → `id` distinto), `write()`-arlo, y asertar que ahora hay **2** filas en total (p. ej. `SELECT COUNT(*) FROM audit_log WHERE correlation_id = :cid` con el mismo `correlationId` para ambos, o contar por los dos `id`).
  - [ ] **Round-trip de columnas (AC9)**: en un test (o caso) aparte, construir un `AuditLogEntry::create(AuditLevel::SECURITY, 'ACCESS_DENIED', ActorContext::forUser(Uuid::generate()), Uuid::generate(), new DateTimeImmutable('2026-03-02T10:11:12+00:00'), resourceType: 'Bank', resourceId: Uuid::generate(), metadata: ['filters' => ['status' => 'active']], ip: '203.0.113.7', userAgent: 'Mozilla/5.0')`, `write()`-arlo y `fetchAssociative('SELECT * FROM audit_log WHERE id = :id', …)`; asertar columna por columna (`level === 'security'`, `actor_type === 'user'`, `actor_id` = el uuid, `json_decode($row['metadata'], true) === ['filters' => ['status' => 'active']]`, `occurred_on` parseable al instante original). Probar también el `AuditLogEntry` mínimo (anónimo, sin recurso, `metadata` `[]`) → `actor_id`/`resource_type`/`resource_id`/`ip`/`user_agent` `NULL`.
  - [ ] Helper `countRowsForId(Connection $c, string $id): int` con `fetchOne('SELECT COUNT(*) FROM audit_log WHERE id = :id', ['id' => $id])` (`assertIsNumeric` + cast a int, mirror del helper de `DomainEventStoreIdempotencyTest`).
  - [ ] **UUID en test**: generar con `Erpify\Shared\Uuid\Domain\Uuid::generate()`, nunca literales hardcodeados.

- [ ] **T7 — Gates** (AC10): orden importa →
  1. `make php.stan` sobre los ficheros nuevos.
  2. Stack arriba + `make db.diff` + `make db.migrate` (crea `audit_log` en la BD de dev) + re-`make db.diff` ⇒ "No changes detected".
  3. `make php.unit` (corre Unit **y** Functional bajo la única "Project Test Suite" — el test de T6 necesita la tabla ya migrada).
  4. `make php.quality` (deptrac + bounded-context + phpmd + cs-fixer + rector).
  5. **Re-correr `make php.stan`** sobre los ficheros ya asentados (Rector puede reescribir asserts en `php.quality` — ver Testing). `api/config/reference.php` se regenera en `php.quality`: **commitea** el diff regenerado, no hagas `git checkout` de él.
  - [ ] **Barrer del diff** cualquier comentario con IDs de story/AC/FR/NFR/`D-1.3.x` antes del commit final (regla de comentarios de `CLAUDE.md`).

## Dev Notes

### Contexto del subsistema (leer antes de tocar código)

- **Esta historia es la capa de almacenamiento, calco de `event_store`.** El subsistema `Shared/Event` ya implementa exactamente este patrón: una tabla append-only raw-DBAL (`event_store`), sin entidad ORM, mantenida por un `postGenerateSchema` listener (`EventStoreSchemaListener`) del que se generó la baseline, y escrita por un adaptador DBAL (`DbalEventStore`) con `INSERT … ON CONFLICT (event_id) DO NOTHING`. **1.3 lo replica para `audit_log`/`AuditLogEntry`.** Fuente: [`docs/adr/audit-activity-log.md`](../../docs/adr/audit-activity-log.md) (D1, D4 + esquema), y los ficheros de `Shared/Event` listados en *References*.
- **Frontera con 1.2 y 1.4.** 1.2 define el dato (`AuditLogEntry` con props públicos `readonly` + `create()` que acuña el `id` UUIDv7). **1.3 lee esos props** para el `INSERT` (igual que `DbalEventStore` lee `$event->eventId()`/`$event->aggregateId()`). **1.4** construye el `AuditLogEntry`, lo envuelve en `RecordAuditEntry`, y según `level` lo despacha al transporte `audit` (handler → `AuditLogWriter::write`) o lo inserta síncrono write-before-send (`AuditLogWriter::write` directo). El escritor de 1.3 es **agnóstico al nivel**: solo inserta; el branching async/sync es de 1.4.
- **El escritor recibe `AuditLogEntry`, no `RecordAuditEntry`.** El epic redacta un AC como "Given un `RecordAuditEntry` a persistir…", pero el `RecordAuditEntry` es el **mensaje envoltorio** (`public AuditLogEntry $entry`) que solo aparece en 1.4 (transporte/handler). El input del escritor es el `AuditLogEntry` que envuelve — confirmado por las Dev Notes de 1.2 ("1.3 lee los props públicos de `AuditLogEntry`"). En 1.3 **no se importa** `RecordAuditEntry`.

### Decisiones técnicas

**D-1.3.a — Puerto `AuditLogWriter` write-only en `Application`; adaptador DBAL en `Infrastructure/Persistence`. [decisión de diseño, mirror `EventStore`]**
*Principio:* DIP + SRP — la Application define el puerto de salida; el detalle DBAL vive en Infrastructure. *Objetivo:* el `RecordAuditEntryHandler` y la inserción síncrona de 1.4 dependen del **puerto**, no del SQL; testeable con un doble del puerto. *Coste / alternativa descartada:* meter el SQL directamente en un handler de 1.4 — acopla orquestación a persistencia y duplica el INSERT entre la rama `activity` (handler) y la `security` (síncrona). Un solo adaptador detrás del puerto sirve a ambas. **Write-only**: no se añade `stream()`/`find()` (a diferencia de `EventStore`, que sí lee para replay) — la consulta de auditoría es Epic 4 **directo sobre la tabla** (FR19), sin pasar por este puerto. Un método de lectura aquí sería superficie sin consumidor (YAGNI).

**D-1.3.b — `INSERT … VALUES … ON CONFLICT (id) DO NOTHING`, no `INSERT … SELECT`. [mirror `DbalDomainEventHandlerDeduplicator`]**
*Principio:* mínimo código que resuelve el problema. *Objetivo:* una sola sentencia atómica idempotente. *Coste / alternativa descartada:* el `INSERT … SELECT … COALESCE(MAX(version)…)` de `DbalEventStore` existe **solo** porque `event_store` calcula `aggregate_version` por stream; `audit_log` no tiene columna calculada, así que la forma `VALUES` plana (la de `DbalDomainEventHandlerDeduplicator`) es la correcta. La idempotencia la da la **PK por `id`** + `ON CONFLICT (id) DO NOTHING`; un redelivery del **mismo** mensaje es un no-op (NFR7), sin `check-then-act`, sin cerrar el EntityManager (es DBAL crudo).

**D-1.3.c — `id` es la PK app-minted; sin columna `sequence` de servidor. [esquema del ADR]**
El ADR fija `id uuid v7 PK`. A diferencia de `event_store` (que tiene `sequence` BIGINT identity **además** de `event_id` uuid único), `audit_log` **no** lleva surrogate de servidor: `id` es la PK y el ancla de idempotencia (acuñado en `AuditLogEntry::create()`, 1.2). UUIDv7 es time-ordered, así que `(occurred_on, id)` es un keyset estable para la paginación de Epic 4 sin necesidad de un bigint monotónico. *Trigger de revisita:* si Epic 4 exige un orden total estricto más allá del que da UUIDv7, añadir entonces un `sequence` identity (hoy es YAGNI).

**D-1.3.d — `level`/`actor_type` como columnas string (enum `->value`); SIN enum nativo Postgres, SIN `EnumType` Doctrine. [desviación argumentada del AC del epic]**
*Principio en juego:* el AC del epic "Given los enums `level`/`actor_type`… Then **reutilizan `EnumType`/`EnumTypeValidator`**" descansa en un error de categoría. `EnumType` es una **constraint de Symfony Validator** (`Shared/Validation/Infrastructure`, valida propiedades de entidad/DTO en el pipeline de validación), **no** un Doctrine `Type`; y `EnumTypeValidator` es su validador runtime — ninguno mapea PHP↔Postgres. En todo el repo **no hay un solo enum nativo de Postgres** (`CREATE TYPE … AS ENUM`), ni custom Doctrine `Type`: `BankAccountStatus`→`TEXT`, `Currency`→`VARCHAR(3)`, ambos vía `#[ORM\Column(enumType: …)]` sobre una **entidad**. *Objetivo que compra la desviación:* `audit_log` es una tabla raw **sin entidad ORM ni DTO validado** en el camino de escritura, así que ni `#[ORM\Column(enumType:)]` ni `#[EnumType]` tienen dónde colgar. La garantía de valor la da el **tipo enum backed en PHP** (`AuditLevel`/`ActorType`, 1.1/1.2): un caso ilegal es inconstruible, y el escritor escribe su `->value` minúscula. Boring-over-clever + patrón de la casa. *Coste / alternativa descartada:* un `ENUM` nativo Postgres + `CHECK` (a) sería precedente nuevo, (b) el `postGenerateSchema` listener **no puede** emitirlo (limitación de la abstracción de esquema de Doctrine, documentada en los docblocks de los listeners existentes) → `make db.diff`/`schema:validate` verían drift salvo papelearlo a mano en la migración, (c) convertiría añadir un `action`/nivel en una migración. Se descarta. **Esta desviación se eleva como pregunta abierta** (ver *Preguntas* al final) por si el usuario prefiere un enum/CHECK nativo pese al coste.

**D-1.3.e — `ip` como `VARCHAR(45)`, no `inet`. [desviación argumentada del "esquema ideal" del ADR]**
*Principio:* el esbozo del ADR pone `ip inet`, pero `Doctrine\DBAL\Types\Types` **no tiene** `INET` (no existe la constante) y no hay columna `inet` en ningún sitio del repo. El docblock-patrón de `EventStoreSchemaListener` ya asume que el listener solo expresa el subconjunto de DDL que Doctrine modela. *Objetivo:* `VARCHAR(45)` (cabe IPv6 + zona) es lo que el listener puede emitir sin drift, y **ninguna** consulta necesita operadores de subred (`inet`): Epic 4 filtra por actor/fecha/recurso/nivel/acción (NFR9), y la GDPR de Epic 3 **redige** `ip` (hash/truncado irreversible), no la consulta por rango. *Coste / alternativa descartada:* `inet` nativo daría validación + operadores de subred, pero exigiría SQL a mano en la migración + un custom Doctrine type para que `schema:validate` no marque drift — coste alto para una capacidad que nadie pide hoy. *Trigger de revisita:* si aparece consulta por subred/CIDR. **También se eleva como pregunta** (más leve; recomendación: `VARCHAR(45)`).

**D-1.3.f — Sin defaults de servidor; el escritor aporta todos los valores (incluido `metadata`/`occurred_on`). [mirror `event_store`]**
Doctrine no modela `DEFAULT now()` ni `DEFAULT '[]'::jsonb`, y no hacen falta: el escritor siempre escribe los 12 valores. `metadata` JSONB es `NOT NULL` y el escritor escribe `json_encode($entry->metadata)` (vacío → `[]`); `occurred_on` lo aporta el llamador de 1.4 desde el puerto `Clock` inyectado (no `now()` de servidor) — coherente con D-1.2.c (el reloj se inyecta, no se lee del ambiente).

**D-1.3.g — `down()` reversible destructivo (`DROP TABLE IF EXISTS`). [contexto: pre-producción]**
La baseline `Version20260616201857` dropea explícitamente cada tabla en su `down()`. La app **no está en producción** (cabecera del ADR), así que un `down()` que dropea `audit_log` es aceptable y reversible (re-aplicar `up()` la recrea). `IF EXISTS` por resiliencia ante rollbacks parciales (`api/CLAUDE.md` → "Use `IF [NOT] EXISTS`").

### YAGNI / alcance — qué NO hacer aquí

- **No** crear el puerto público `AuditLogger`, `ActorContextFactory`, el `RecordAuditEntryHandler`, el transporte `audit` ni el routing en `messenger.yaml` (todo es **Story 1.4**).
- **No** importar `RecordAuditEntry` (el escritor toma `AuditLogEntry`; el mensaje envoltorio es de 1.4).
- **No** añadir un método de lectura (`stream`/`find`/`paginate`) al puerto — la consulta es **Epic 4** directo sobre la tabla (FR19).
- **No** crear `AuditLogPruner`, rutas de `UPDATE`/`DELETE`, ni nada de retención/pseudonimización GDPR (**Epic 3**).
- **No** crear una entidad ORM para `audit_log` ni añadir un mapping en `doctrine.yaml` — la tabla es raw-DBAL, el listener es su única declaración de esquema.
- **No** añadir un enum nativo Postgres / `CHECK` para `level`/`actor_type` (D-1.3.d) ni un custom Doctrine type para `ip` (D-1.3.e), salvo que el usuario lo decida tras las preguntas abiertas.
- **No** editar `tools/deptrac/deptrac.yaml`, el `deptrac.baseline.yaml` ni `api/.bounded-context-allowlist` (AC10 — `Shared/Audit/*` autoenrolla en `Shared.*`).
- **No** tocar `Backoffice/BankAccount/.../Audit/*` (placeholder de 1.5) ni `api/.event-dispatch-allowlist`.

### Architecture compliance (guardrails que muerden)

- **Hexagonal / deptrac:** `AuditLogWriter` (Application) importa solo `AuditLogEntry` (Application). `DbalAuditLogWriter`/`AuditLogSchemaListener` (Infrastructure) importan Doctrine DBAL/ORM-tools + atributos de DI — legítimo en Infrastructure. Los colectores `src/Shared/(.*/)?{Application,Infrastructure}` autoenrollan `Shared/Audit/*` en las capas `Shared.*`, igual que el backbone `Shared/Event` (ver `api/CLAUDE.md` → "Deptrac", "Nested `Shared/` modules are the exception"). **No** hace falta bloque deptrac por módulo ni allowlist.
- **Bounded-context isolation:** todo es `Erpify\Shared\…` (siempre importable); ninguna pieza entra en el `Domain/` de un contexto de negocio. `make php.lint.bounded-context` verde sin allowlist nuevo.
- **Migraciones (`api/CLAUDE.md` "Rules that bite"):** transaccionales (`--all-or-nothing`); `CREATE TABLE` + `CREATE INDEX` plano es correcto, **no** `CREATE INDEX CONCURRENTLY` (requeriría `isTransactional() => false` y rompe el pipeline). Editar la migración en la rama actual está permitido; tras merge a `main` es inmutable.
- **Seguridad / `audit_log` es PII:** esta historia **no** persiste PII todavía (no hay captura — eso es Epic 2/1.5), pero la tabla la contendrá (`actor_id`, `ip`, `user_agent`). La política de retención + pseudonimización y la actualización de `PRODUCTION_SECURITY_CHECKLIST.md`/`docs/rules/security.md` son **Epic 3** — no en 1.3. El escritor parametriza todo (`:placeholder`), sin interpolación de strings en SQL (checklist de inyección de `CLAUDE.md`).
- **Error contract:** 1.3 no añade marcadores ni respuestas HTTP; un fallo de BD en el escritor es una excepción de infraestructura que aflora por el pipeline RFC 9457 existente (y que 1.4 aislará tras su frontera best-effort para `activity`). **No** se edita `docs/api-error-contract.md`.
- **Docs:** registrar `audit_log` (nueva tabla raw-DBAL + transporte futuro) en `docs/architecture-api.md` (sección *Async & messaging* / tablas raw) — el ADR ya existe; basta una línea cuando el subsistema esté cableado. *Sugerencia:* diferir la actualización de `architecture-api.md` a **1.4/1.5** (cuando el flujo end-to-end exista) para no documentar una tabla aún sin escritor cableado; confirmar con el usuario si se prefiere documentar ya en 1.3.

### Librerías / framework

- PHP **8.5** (floor `^8.5`). `final readonly class`, `interface`, propiedades promovidas, `#[Override]`. Sin sintaxis 8.5 inventada de memoria.
- `declare(strict_types=1);` en cada fichero (src y test). Tipos en todo parámetro/retorno/propiedad.
- Doctrine DBAL: `Doctrine\DBAL\Connection` (`executeStatement`, binding nombrado); `Doctrine\DBAL\Types\Types` (constantes `GUID`/`STRING`/`JSONB`/`DATETIMETZ_IMMUTABLE`); `Doctrine\DBAL\Schema\PrimaryKeyConstraint`. ORM tools: `Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs`, `Doctrine\ORM\Tools\ToolEvents`. Atributos: `Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener`, `Symfony\Component\DependencyInjection\Attribute\AsAlias`. `json_encode(..., JSON_THROW_ON_ERROR)`.
- UUID en tests: `Erpify\Shared\Uuid\Domain\Uuid::generate()`. **No** añadir dependencias.
- `AuditLogEntry`/`AuditLevel` (1.2), `ActorContext`/`ActorType` (1.1): prerequisitos en disco (T0).
- Tests: **PHPUnit 13** con atributos (`#[CoversClass]`, `#[Test]`). `KernelTestCase` para los funcionales (necesitan stack + BD migrada).

### File structure

```
api/src/Shared/Audit/Application/AuditLogWriter.php                                  (NEW — puerto write-only: write(AuditLogEntry): void)
api/src/Shared/Audit/Infrastructure/Persistence/DbalAuditLogWriter.php               (NEW — #[AsAlias]; INSERT … ON CONFLICT (id) DO NOTHING)
api/src/Shared/Audit/Infrastructure/Persistence/AuditLogSchemaListener.php           (NEW — #[AsDoctrineListener postGenerateSchema])
api/migrations/2026/VersionYYYYMMDDHHMMSS.php                                        (NEW — generada por make db.diff; up()=CREATE TABLE+4 índices, down()=DROP TABLE IF EXISTS)
api/tests/Unit/Shared/Audit/Infrastructure/Persistence/AuditLogSchemaListenerTest.php (NEW — pure TestCase; mirror EventStoreSchemaListenerTest)
api/tests/Functional/Shared/Persistence/AuditLogWriterIdempotencyTest.php            (NEW — KernelTestCase; mirror DomainEventStoreIdempotencyTest)
```

Patrón de carpeta: el adaptador + listener en `Shared/Audit/Infrastructure/Persistence/` (mirror de `Shared/Event/Infrastructure/Persistence/`); el puerto en `Shared/Audit/Application/` (mirror de `Shared/Event/Application/EventStore.php`). 1.1 creó `Domain/`, 1.2 añadió `Application/` (`AuditLogEntry`, `RecordAuditEntry`); 1.3 añade la **primera capa `Infrastructure/`** del módulo. El test funcional se ubica en `tests/Functional/Shared/Persistence/` por consistencia con los tests funcionales de `Shared/Event` que ya viven ahí (no en `Functional/Shared/Audit/...`).

### Testing requirements

- **Listener (unit, puro):** `extends TestCase`, sin kernel ni BD. Mirror exacto de [`EventStoreSchemaListenerTest`](../../api/tests/Unit/Shared/Event/Infrastructure/Persistence/EventStoreSchemaListenerTest.php): construir `new Schema()`, invocar el listener, asertar tabla/columnas/índices + idempotencia del guard. Añadir las aserciones de nulabilidad (`getNotnull()`) para fijar el contrato `actor_id` null / `correlation_id` not-null / `metadata` not-null.
- **Escritor (functional, integración real):** `extends KernelTestCase`. Mirror de [`DomainEventStoreIdempotencyTest`](../../api/tests/Functional/Shared/Persistence/DomainEventStoreIdempotencyTest.php): `bootKernel()`, resolver el puerto del contenedor, `Connection` vía `EntityManagerInterface::getConnection()`, **`beginTransaction()`/`rollBack()` en `finally`** (la suite no tiene DAMA auto-rollback y comparte la conexión de dev). Cubrir: doble-write mismo `id` → 1 fila (AC5); write de `id` regenerado → fila nueva (AC6); round-trip de todas las columnas con casts (AC9) + caso mínimo con nulos.
- **Cobertura por `#[CoversClass]`:** SonarCloud `new_coverage` solo acredita al target del `#[CoversClass]`. `AuditLogSchemaListenerTest` → `#[CoversClass(AuditLogSchemaListener::class)]`; `AuditLogWriterIdempotencyTest` → `#[CoversClass(DbalAuditLogWriter::class)]`. El puerto `AuditLogWriter` es una interfaz (sin líneas ejecutables → no necesita cobertura). No confiar en cobertura cruzada "gratis" (lección PR #364).
- **Gotchas de tooling (de 1.1/1.2 y sesiones previas):**
  - **Rector reescribe asserts en `php.quality`** (`assertEquals`→`assertSame` en escalares; `assertEmpty`→`assertSame([], …)`; `AssertEmptyNullableObjectToAssertInstanceofRector` impone `assertNotInstanceOf`/`assertInstanceOf` sobre `assertNull`/`assertEmpty` para getters nullable de objeto). Para comparar `metadata` decodificado (array) o instantes, usa la forma que imponga Rector; tras `php.stan` verde corre `php.quality` y **re-corre `php.stan`** sobre los ficheros asentados. Para el array `metadata` decodificado, `assertSame(['filters' => ['status' => 'active']], json_decode($row['metadata'], true))` es comparación de arrays (Rector no la toca).
  - **PHPMD `TooManyPublicMethods` (límite 10)** cuenta métodos de test: si `AuditLogWriterIdempotencyTest` se acerca al tope, fusiona casos (foreach / un solo método por escenario), no añadas providers de más (lección PR #364). Aquí hay holgura (3-4 métodos).
  - **PHPMD `CouplingBetweenObjects` (≤13)** cuenta imports de cuerpo de clase, también en tests: el funcional importa `Connection`, `EntityManagerInterface`, `AuditLogWriter`, `DbalAuditLogWriter`, `AuditLogEntry`, `AuditLevel`, `ActorContext`, `Uuid`, `KernelTestCase`, `CoversClass`, `DateTimeImmutable` — vigila el conteo; si se pasa, no extraigas helpers a otra clase, recorta imports (p. ej. no instanciar tipos que no necesitas).
  - **`php.quality` regenera `api/config/reference.php`**: auto-generado, **commitea** el diff, no `git checkout`.
  - El test funcional **falla con "relation audit_log does not exist"** si se corre `make php.unit` antes de `make db.migrate` — respeta el orden de T7.

### Git intelligence (rama `feat/shared-audit-actor-context-5sz9`)

- Estado de la rama (base `df391a26`): hoy solo hay commits de **docs/planning** (`5467e658` spec 1.2, `51f20195` spec 1.1, `c50214b7` sprint status, + ADR/epics). **Aún no hay código del subsistema en disco** (1.1 y 1.2 son specs, no implementación). En la entrega de PR único los commits de implementación siguen 1.1 → 1.2 → 1.3; 1.3 es **el tercer commit de implementación**, encadenado sobre 1.2.
- Commit sugerido (Conventional Commits, scope `shared`): `feat(shared): add append-only audit_log table and idempotent DBAL writer`.
- **Barrer del diff** comentarios con IDs de story/AC/FR/NFR/`D-1.3.x` antes del commit final (regla de `CLAUDE.md`); son andamiaje de desarrollo, no van a `main`.

### Project Structure Notes

- `api/src/Shared/Audit/` lo inician 1.1 (`Domain/`) y 1.2 (`Application/`). 1.3 añade la **primera capa `Infrastructure/Persistence/`** del módulo. Coherente con la organización vertical-slice de `Shared/` ([`docs/adr/shared-module-organization.md`](../../docs/adr/shared-module-organization.md)) y con el espejo `Shared/Event/{Application,Infrastructure/Persistence}`.
- Sin conflictos de estructura. Sin nuevos mappings ORM en `doctrine.yaml` (tabla raw). Sin nuevos targets `make`.

### References

- [`docs/adr/audit-activity-log.md`](../../docs/adr/audit-activity-log.md) — D1 (eje separado, `AuditLogEntry` = modelo persistido append-only DBAL crudo sin entidad ORM), D4 (append-only, sin `UPDATE`/`DELETE` salvo retención), **"Esbozo de esquema (`audit_log`)"** (columnas + 4 índices), NFR de pre-producción (down destructivo aceptable).
- [`_bmad-output/planning-artifacts/epics.md`](../planning-artifacts/epics.md) — Epic 1 / Story 1.3 (ACs originales), FR3/FR4/FR9, NFR6/NFR7/NFR9.
- [`1-2-auditlevel-mensaje-interno-recordauditentry-modelo-auditlogentry-separados-del-domainevent.md`](./1-2-auditlevel-mensaje-interno-recordauditentry-modelo-auditlogentry-separados-del-domainevent.md) — superficie de `AuditLogEntry` (props públicos que lee el escritor) y `AuditLevel`; D-1.2.c (reloj inyectado), D-1.2.d (`id` minted en `create()`).
- [`1-1-actorcontext-con-actortype-tipado.md`](./1-1-actorcontext-con-actortype-tipado.md) — `ActorContext` (`type`, `actorId`) y `ActorType` que sella el escritor en `actor_type`/`actor_id`.
- [`api/src/Shared/Event/Infrastructure/Persistence/EventStoreSchemaListener.php`](../../api/src/Shared/Event/Infrastructure/Persistence/EventStoreSchemaListener.php) — **plantilla del schema listener** (atributo, guard `hasTable`, `createTable`/`addColumn` con `Types::*`, `PrimaryKeyConstraint::editor()`, `addIndex`, docblock sobre los límites de la abstracción de esquema).
- [`api/src/Shared/Event/Infrastructure/Messenger/DbalDomainEventHandlerDeduplicator.php`](../../api/src/Shared/Event/Infrastructure/Messenger/DbalDomainEventHandlerDeduplicator.php) — **plantilla del escritor** `INSERT … VALUES … ON CONFLICT … DO NOTHING` (forma plana, binding nombrado, señal de filas afectadas).
- [`api/src/Shared/Event/Infrastructure/Persistence/DbalEventStore.php`](../../api/src/Shared/Event/Infrastructure/Persistence/DbalEventStore.php) — referencia de **casts** `CAST(:x AS UUID|JSONB|TIMESTAMPTZ)`, `encode()` con `JSON_THROW_ON_ERROR`, `->format('Y-m-d H:i:s.uP')`, y el patrón `#[AsAlias]` + `Connection` por constructor.
- [`api/src/Shared/Event/Application/EventStore.php`](../../api/src/Shared/Event/Application/EventStore.php) — patrón del puerto de salida de almacenamiento (1.3 lo reduce a write-only).
- [`api/tests/Unit/Shared/Event/Infrastructure/Persistence/EventStoreSchemaListenerTest.php`](../../api/tests/Unit/Shared/Event/Infrastructure/Persistence/EventStoreSchemaListenerTest.php) — **plantilla del test unitario** del listener (Schema en memoria, idempotencia del guard).
- [`api/tests/Functional/Shared/Persistence/DomainEventStoreIdempotencyTest.php`](../../api/tests/Functional/Shared/Persistence/DomainEventStoreIdempotencyTest.php) — **plantilla del test de integración** (KernelTestCase, `beginTransaction`/`rollBack`, doble-write → 1 fila, helper `countRows…`).
- [`api/migrations/2026/Version20260616201857.php`](../../api/migrations/2026/Version20260616201857.php) — baseline: forma del `CREATE TABLE`/`CREATE INDEX` raw y `down()` con `DROP TABLE`; cómo render Doctrine los tipos (`UUID`, `JSONB`, `TIMESTAMP(0) WITH TIME ZONE`, `VARCHAR`).
- [`api/src/Shared/Validation/Infrastructure/EnumType.php`](../../api/src/Shared/Validation/Infrastructure/EnumType.php) — **NO** es un Doctrine type, es una constraint de Symfony Validator (sustenta D-1.3.d).
- [`api/config/services.yaml`](../../api/config/services.yaml) — `autoconfigure: true` + glob `Erpify\` ⇒ `#[AsDoctrineListener]`/`#[AsAlias]` se registran sin YAML.
- [`api/config/packages/doctrine_migrations.yaml`](../../api/config/packages/doctrine_migrations.yaml) — namespace `DoctrineMigrations`, `organize_migrations: BY_YEAR` (→ `migrations/2026/`).

## Preguntas para Sergio (resolver antes o durante la implementación)

1. **`level`/`actor_type` — string plano vs. enum nativo Postgres (D-1.3.d).** El AC del epic dice "reutilizan `EnumType`/`EnumTypeValidator`", pero `EnumType` es una *constraint de Symfony Validator*, no un mapeo Doctrine, y no aplica a una tabla raw sin entidad. La historia, por defecto, **persiste el `->value` del enum backed en columnas string** (patrón de la casa: cero enums nativos en el repo). ¿OK, o prefieres introducir `CREATE TYPE … AS ENUM` + `CHECK` como precedente nuevo (coste: SQL a mano en la migración + custom Doctrine type para que `schema:validate` no marque drift)? **Recomendación: string plano.**
2. **`ip` — `VARCHAR(45)` vs. `inet` (D-1.3.e).** `Types::INET` no existe en DBAL y no hay ninguna columna `inet` en el repo. Por defecto se modela como `VARCHAR(45)`. ¿OK, o quieres `inet` nativo (coste similar al de arriba) pese a que ninguna consulta de Epic 4 usa operadores de subred? **Recomendación: `VARCHAR(45)`.**
3. **Documentación de arquitectura.** ¿Documentamos `audit_log` en `docs/architecture-api.md` ya en 1.3, o lo diferimos a 1.4/1.5 cuando exista el flujo end-to-end (escritor cableado al transporte)? **Recomendación: diferir a 1.4/1.5.**

## Dev Agent Record

### Agent Model Used

### Debug Log References

### Completion Notes List

### File List
