# Story 1.1: `ActorContext` con `ActorType` tipado

Status: ready-for-dev

<!-- Epic 1 — Registro de auditoría end-to-end (backbone + primer actor auditado).
     Primera historia del subsistema de auditoría operativa/de actor. Ver ADR docs/adr/audit-activity-log.md (D6, D7). -->

## Story

Como plataforma de ERPify,
quiero un value object `ActorContext` con un `ActorType` tipado,
para que toda entrada de auditoría identifique sin ambigüedad **quién** actuó y la consulta forense no dependa de heurísticas frágiles (`actor_id IS NULL` + ruta).

Esta es la **primera pieza del backbone de auditoría** y es **dominio puro**: no toca BD, ni Messenger, ni HTTP. Es el discriminante de actor que el resto del épico (factory en 1.4, escritor en 1.3, captura en E2) sella en cada fila de `audit_log`. Su única razón de existir es cerrar la fuga semántica de D7: un `actor_id = null` es ambiguo (¿anónimo? ¿sistema? ¿api key?) y eso degrada justo la investigación que el subsistema existe para servir.

## Acceptance Criteria

**AC1 — `actor_type` obligatorio (nunca null).**
**Given** el enum `ActorType` (`anonymous|system|api_key|user`),
**When** se construye un `ActorContext`,
**Then** `type` es obligatorio y nunca null (garantizado por el tipo: cada factoría pasa un `ActorType` concreto, nunca `?ActorType`).

**AC2 — `anonymous`/`system` ⇒ `actor_id` siempre null (irrepresentable de otro modo).**
**Given** las factorías `ActorContext::anonymous()` / `::system()`,
**When** se construye un actor de esos tipos,
**Then** `actorId` es `null` y **no existe ninguna vía pública** para emparejar `anonymous`/`system` con un id — el constructor es privado, el estado ilegal es **irrepresentable** (no se valida en runtime porque no se puede expresar).

**AC3 — `api_key`/`user` ⇒ `actor_id` es un UUID válido.**
**Given** las factorías `ActorContext::forApiKey(string $id)` / `::forUser(string $id)`,
**When** `$id` no representa un UUID RFC 4122 válido,
**Then** se rechaza con `InvalidActorContext` (excepción de dominio, marker-less);
**And** con un `$id` que sí es un UUID válido se acepta. El caso `null` es **estructuralmente imposible** (el parámetro es `string`, no `?string`). La validez de UUID se delega en `Shared\Uuid\Domain\Uuid::isValid()` — no se reimplementa con regex (Decisión D-1.1.a).

**AC4 — Ubicación y pureza de dominio.**
**Given** el VO, el enum y la excepción,
**When** se ubican,
**Then** viven en `Shared/Audit/Domain` **sin** imports de framework/ORM/HTTP (solo PHP, el enum de dominio, el wrapper de dominio `Shared\Uuid\Domain\Uuid` y la base `Shared\ErrorContract\Domain\Exception\DomainException`). Lo verifican `make php.deptrac` y `make php.lint.bounded-context`.

**AC5 — Tests unitarios puros (sin contenedor ni BD).**
**Given** un test unitario que cubre los cuatro `ActorType` y la invariante de UUID,
**When** se ejecuta vía `make php.unit`,
**Then** pasa sin contenedor, sin BD y sin red (dominio puro), y `make php.stan` + `make php.quality` quedan verdes.

## Tasks / Subtasks

- [ ] **T1 — Crear el enum `ActorType`** (AC1) → `api/src/Shared/Audit/Domain/ActorType.php`
  - [ ] `enum ActorType: string` con casos `ANONYMOUS = 'anonymous'`, `SYSTEM = 'system'`, `API_KEY = 'api_key'`, `USER = 'user'` (backing **minúscula**, exactamente los tokens del esquema del ADR — Decisión D-1.1.c).
  - [ ] Enum **case-only**, sin métodos: con las factorías nombradas de `ActorContext`, la regla "qué tipo lleva id" vive en cada factoría, no en un predicado del enum (Decisión D-1.1.d).
  - [ ] `declare(strict_types=1);` y docblock breve sólo si el nombre no basta.
- [ ] **T2 — Crear la excepción de dominio `InvalidActorContext`** (AC3) → `api/src/Shared/Audit/Domain/Exception/InvalidActorContext.php`
  - [ ] `final class InvalidActorContext extends Erpify\Shared\ErrorContract\Domain\Exception\DomainException` — **sin** marcador (`InvalidInput`/`InvariantViolation`): es un error de programación server-side, no de cliente, y **nunca aflora como 500 al usuario** (lo aísla la frontera best-effort de 1.4 — Decisión D-1.1.b).
  - [ ] **Un** constructor nombrado estático: `actorIdMustBeUuid(ActorType $type): self`. `type:` estable `'invalid-actor-context'`; `title:` corto; `context:` `['actorType' => $type->value]` (nunca el `actorId` ofensivo en claro). El emparejamiento tipo↔id ya no necesita excepción (es irrepresentable).
- [ ] **T3 — Crear el value object `ActorContext`** (AC1, AC2, AC3, AC4) → `api/src/Shared/Audit/Domain/ActorContext.php`
  - [ ] `final readonly class ActorContext` con **constructor privado** `private function __construct(public ActorType $type, public ?string $actorId)` — props públicas readonly para los consumidores (1.2/1.3); la construcción es solo por factorías.
  - [ ] Cuatro factorías estáticas (estado ilegal irrepresentable): `anonymous(): self` → `new self(ActorType::ANONYMOUS, null)`; `system(): self` → idem con `SYSTEM`; `forUser(string $id): self` y `forApiKey(string $id): self` → `Uuid::isValid($id)` o `throw InvalidActorContext::actorIdMustBeUuid(ActorType::USER | API_KEY)`, luego `new self(...)`.
  - [ ] **Sin** validación cruzada en el constructor (no hace falta: cada factoría conoce su forma). Reusar `Shared\Uuid\Domain\Uuid::isValid()` (predicado), nunca `ensure()` (Decisión D-1.1.a).
- [ ] **T4 — Tests unitarios** (AC5) — mirror `api/tests/Unit/Shared/Audit/Domain/`
  - [ ] `ActorContextTest.php` con `#[CoversClass(ActorContext::class)]` **y** `#[CoversClass(InvalidActorContext::class)]`: `anonymous()`/`system()` → `type` correcto y `actorId === null`; `forUser(Uuid::generate())` / `forApiKey(Uuid::generate())` → aceptan y exponen el id; `forUser('not-a-uuid')` / `forApiKey('not-a-uuid')` → `expectException(InvalidActorContext::class)`.
  - [ ] `ActorTypeTest.php` con `#[CoversClass(ActorType::class)]`: fija los 4 backing values (`'anonymous'|'system'|'api_key'|'user'`) y el conteo de casos (mirror de `api/tests/Unit/Shared/Search/Domain/SortDirectionTest.php`, que testea un enum case-only).
- [ ] **T5 — Gates** (AC4, AC5): `make php.stan` sobre los ficheros nuevos → `make php.unit` → `make php.quality` (incluye deptrac + bounded-context + phpmd + cs-fixer + rector). Verde antes de declarar done.

## Dev Notes

### Contexto del subsistema (leer antes de tocar código)

- **Eje separado.** Auditoría operativa/de actor (`AuditLogger → audit_log`) es un eje **distinto** del stream de dominio (`DomainEvent → event_store`). Esta historia NO crea ningún `DomainEvent`, ningún mensaje de bus, ninguna tabla. Solo el VO + enum + su excepción. Fuente: [`docs/adr/audit-activity-log.md`](../../docs/adr/audit-activity-log.md) D1, D6, D7.
- **Greenfield verificado.** `api/src/Shared/Audit/` **no existe** todavía — esta historia crea el árbol `Shared/Audit/Domain/`. Las únicas referencias "Audit" actuales (`Backoffice/BankAccount/.../Audit/BankAccountsViewedAuditEvent.php`, `RecordAuditLogOnBankAccountsViewed.php`) son el **placeholder provisional** que la Story 1.5 retirará; **no se tocan aquí**.
- **Esta historia es la base de las demás.** 1.2 (modelo `RecordAuditEntry`/`AuditLogEntry`) compone `ActorContext`; 1.3 (tabla + escritor) mapea `actor_type`/`actor_id`; 1.4 (`AuditLogger` + `ActorContextFactory`) **construye** el VO vía sus factorías. Mantenerlo mínimo y correcto.

### Decisiones técnicas

**D-1.1.a — Validez de UUID: `Uuid::isValid()` (predicado), NO `Uuid::ensure()`. [CONFIRMADO]**
El AC original mencionaba `Uuid::ensure()`; se interpreta como *"delega la validez de UUID en la utilidad estándar del proyecto, no la reimplementes"* y se usa el **predicado** `Erpify\Shared\Uuid\Domain\Uuid::isValid(string): bool`, lanzando la excepción **local** `InvalidActorContext`. Razón: `Uuid::ensure()` lanza `InvalidUuidException`, que lleva el marcador `InvalidInput` → HTTP 400 `invalid-uuid` y es un `ClientError` **suprimido de Sentry** — semántica equivocada para un VO acuñado server-side. Precedente del repo: `Shared\Search\Domain\Exception\InvalidSearchValue::notAUuid()` valida con el predicado y lanza su **propia** excepción tipada. El docblock de `Uuid` lo dice literalmente: *"callers that branch and raise their own error (e.g. search filters) use [`isValid`]"*.

**D-1.1.b — Estado ilegal irrepresentable + excepción marker-less (NUNCA un 500 al usuario). [CONFIRMADO — revisión arquitectónica con el usuario]**
El emparejamiento tipo↔id **no** se valida en runtime: las factorías nombradas (`anonymous`/`system` fijan id null; `forUser`/`forApiKey` exigen un `string`) hacen el estado ilegal **imposible de construir** ("make illegal states unrepresentable"). Constructor **privado**. Esto supera conscientemente el snippet de constructor público del ADR D7: el snippet ilustra la forma, no obliga a una construcción cross-field validada en runtime.

Queda **una** invariante en runtime — el UUID de `forUser`/`forApiKey` — con excepción `InvalidActorContext` **sin marcador**.

Aclaración clave (corrige una imprecisión del primer borrador): un `ActorContext` inválido **no produce un 500 al usuario**. El VO lo acuña el `ActorContextFactory` en el path de auditoría, que por diseño es **best-effort y aislado** (D3 / FR4 / NFR2; AC de 1.4: *"un fallo del despacho de auditoría NUNCA impide completar el caso de uso principal… queda registrado mediante observabilidad técnica"*). Si una factoría lanzara, 1.4 lo **traga y registra** — no llega al pipeline RFC 9457, no hay respuesta 500. Que la excepción sea **marker-less** solo decide cómo se ve **en Sentry**: un `DomainException` sin marcador **no** es `ClientError`, así que **fluye a Sentry** como fallo accionable (una factoría mal cableada). Marcarla `InvariantViolation`/422 la **suprimiría de Sentry** y mentiría sobre la culpa (diría "error de cliente") — por eso se descarta.

Superficie de la excepción: mirror de [`InvalidSearchValue`](../../api/src/Shared/Search/Domain/Exception/InvalidSearchValue.php) (constructor nombrado, `context` sin el valor ofensivo). Base [`DomainException`](../../api/src/Shared/ErrorContract/Domain/Exception/DomainException.php) — `__construct(string $type, string $title, array $context = [], ?Throwable $previous = null)`.

**D-1.1.c — Backing de `ActorType` en minúscula.**
`enum ActorType: string` con backing `'anonymous'|'system'|'api_key'|'user'` — los tokens **exactos** del esquema del ADR (`actor_type enum anonymous|system|api_key|user`). Es una **divergencia consciente** del precedente `BankAccountStatus`/`SortDirection` (backing en MAYÚSCULA): aquí los valores son el contrato del enum Postgres que mapeará la Story 1.3 (`EnumType`/`EnumTypeValidator`), y el ADR los fija en minúscula. No cambiar a mayúscula.

**D-1.1.d — `ActorType` es case-only; la regla "qué tipo lleva id" vive en las factorías.**
Con las factorías nombradas, cada una **ya** encarna la regla (`anonymous()` fija null; `forUser()` exige un id) — añadir un `ActorType::requiresActorId()` sería un predicado **sin consumidor** en 1.1 (YAGNI: el VO no lo necesita y nadie más lo invoca aún). Se deja el enum case-only. Si 1.3 (mapeo de storage) o 4.1 (read model) necesitan **reconstruir** desde `(actor_type, actor_id)` crudos, esa historia añadirá el seam que pida — no se especula aquí (Regla de Tres).

### YAGNI / alcance — qué NO hacer aquí

- Las factorías nombradas **sí** entran (son corrección-por-construcción, no ergonomía — decisión arquitectónica confirmada, D-1.1.b). Constructor **privado**.
- **No** añadir `equals()`, serialización, ni un `fromPrimitives(string $type, ?string $id)` de reconstitución. Nadie los consume en 1.1. La reconstitución desde la fila cruda de `audit_log` —que sí necesitaría validación cruzada, y al leer de **nuestra** tabla append-only un desajuste sería corrupción de datos (caso marker-less/Sentry genuino)— es **trigger de revisita** de 1.3/4.1, no de aquí.
- **No** crear `ActorContextFactory` (es 1.4), ni `AuditLevel`/`RecordAuditEntry` (es 1.2), ni tabla/migración (es 1.3).
- **No** registrar nada en `messenger.yaml`, `services.yaml`, ni `deptrac.yaml` (ver Architecture compliance).

### Architecture compliance (guardrails que muerden)

- **Hexagonal / pureza de dominio:** `Domain/` no importa framework/ORM/HTTP/DI. Aquí solo se importan: el propio `ActorType` (mismo layer), `Shared\Uuid\Domain\Uuid` (clase de dominio del repo que envuelve `symfony/uid` bajo la excepción documentada) y `Shared\ErrorContract\Domain\Exception\DomainException` (mismo Shared/Domain). Todo dentro del allowlist de deptrac → **sin** cambios en `tools/deptrac/deptrac.yaml` ni en el allowlist externo.
- **Deptrac no necesita registro de módulo.** Los módulos anidados de `Shared/` son la excepción: los colectores `src/Shared/(.*/)?Domain` autoenrollan `Shared/Audit/Domain` en las capas `Shared.*` (ver [`api/CLAUDE.md`](../../api/CLAUDE.md) → "Deptrac"). No añadir un bloque por módulo.
- **Bounded-context isolation:** `Erpify\Shared\…` es siempre importable; este VO no entra en el `Domain/` de ningún contexto de negocio. `make php.lint.bounded-context` debe quedar verde sin allowlist nuevo.
- **Exception → error contract:** `InvalidActorContext` extiende `DomainException` pero **sin marcador** ⇒ no cambia el contrato de errores publicado (no es un nuevo `type` 4xx mapeado). Por tanto **no** requiere editar [`docs/api-error-contract.md`](../../docs/api-error-contract.md) (NFR26 sólo aplica al añadir/cambiar un marcador o su mapping).

### Librerías / framework

- PHP **8.5** (floor `^8.5`); idiomas 8.3 son forward-compatible — no inventar sintaxis 8.5 de memoria. Usar `final readonly class`, propiedades promovidas (válidas en constructor privado), `match`, enums backed, factorías estáticas.
- `declare(strict_types=1);` en cada fichero (src y test). Tipos en todo parámetro/retorno/propiedad.
- UUID: **no** añadir dependencias; usar `Erpify\Shared\Uuid\Domain\Uuid` (`isValid()`/`generate()`). El wrapper ya cubre `symfony/uid` bajo la excepción de layer.
- Tests: **PHPUnit 13** con atributos (`#[CoversClass]`, `#[DataProvider]`, `#[Test]` si se usa), no doc-comments.

### File structure (todos NEW)

```
api/src/Shared/Audit/Domain/ActorType.php                         (NEW — enum backed string, case-only)
api/src/Shared/Audit/Domain/ActorContext.php                      (NEW — final readonly VO, constructor privado + 4 factorías)
api/src/Shared/Audit/Domain/Exception/InvalidActorContext.php     (NEW — DomainException sin marcador, 1 constructor nombrado)
api/tests/Unit/Shared/Audit/Domain/ActorContextTest.php           (NEW)
api/tests/Unit/Shared/Audit/Domain/ActorTypeTest.php              (NEW)
```

Patrón de carpeta: enum **plano** en `Domain/` (mirror de `Shared/Search/Domain/SortDirection.php`, no `Domain/Enum/`). Excepción en `Domain/Exception/` (mirror de `Shared/Search/Domain/Exception/`). Tests espejan `src/`.

### Testing requirements

- **Dominio puro:** `extends PHPUnit\Framework\TestCase` (no `KernelTestCase`), sin contenedor, sin BD. Mirror de [`api/tests/Unit/Shared/Kernel/Domain/ValueObject/NormalizedTextTest.php`](../../api/tests/Unit/Shared/Kernel/Domain/ValueObject/NormalizedTextTest.php) y [`api/tests/Unit/Shared/Uuid/Domain/UuidTest.php`](../../api/tests/Unit/Shared/Uuid/Domain/UuidTest.php).
- **Cobertura por `#[CoversClass]`:** la cobertura sólo se acredita al target del `#[CoversClass]` del test (gate SonarCloud new_coverage). Por eso `ActorTypeTest` lleva su propio `#[CoversClass(ActorType::class)]` y `ActorContextTest` declara `#[CoversClass(ActorContext::class)]` **+** `#[CoversClass(InvalidActorContext::class)]`. No confiar en cobertura "gratis" cruzada.
- **Rechazos:** `$this->expectException(InvalidActorContext::class)` para `forUser('not-a-uuid')` / `forApiKey('not-a-uuid')`. Para los aceptados, assert sobre los props (`assertSame(ActorType::USER, $ctx->type)`, `assertSame($id, $ctx->actorId)`) — patrón de `UuidTest`/`NormalizedTextTest`.
- **UUID válido en test:** generar con `Uuid::generate()` (no hardcodear un literal). UUID inválido: `'not-a-uuid'` (mirror de `UuidTest::testEnsureRejectsAMalformedValue`).
- **Gotchas de tooling (de sesiones previas, aplican a tests de dominio):**
  - Rector puede reescribir asserts en `php.quality` (p. ej. `assertEquals`→`assertSame` en escalares). Tras `php.stan` verde, correr `php.quality` y re-correr `php.stan` sobre los ficheros ya asentados; aceptar la forma que imponga Rector en vez de pelearla.
  - PHPMD `TooManyPublicMethods` (límite 10) cuenta métodos de test: si un test se acerca al tope, fusionar casos con `#[DataProvider]` en un método. (Aquí hay holgura — no debería morder.)

### Git intelligence (rama `feat/shared-audit-actor-context-5sz9`)

- Commits de la rama hasta ahora: solo **planning/docs** (`c50214b7 sprint status`, `4a58aae0 docs(shared): add implementation-readiness report…`, `bbda382b docs(shared): close audit_log gdpr erasure policy…`). **No hay código del backbone todavía** — esta historia es el **primer commit de implementación**.
- Commit sugerido (Conventional Commits, scope `shared`): `feat(shared): add typed ActorContext value object for audit actor identity`.
- Barrer del diff cualquier comentario con IDs de story/NFR/AC antes del commit final (regla de comentarios de `CLAUDE.md`): los `FRxx`/`Story 1.1`/`AC2`/`D-1.1.x` son andamiaje de desarrollo, no van a `main`.

### Project Structure Notes

- Sin conflictos de estructura: `Shared/Audit/` es un nuevo módulo vertical-slice bajo `Shared/`, que carga sólo las capas que necesita (aquí, solo `Domain/`). Coherente con la organización de `Shared/` descrita en [`api/CLAUDE.md`](../../api/CLAUDE.md) y [`docs/adr/shared-module-organization.md`](../../docs/adr/shared-module-organization.md).
- No tocar `Backoffice/BankAccount/.../Audit/*` (placeholder de 1.5).

### References

- [`docs/adr/audit-activity-log.md`](../../docs/adr/audit-activity-log.md) — D6 (correlación + `actor_id` nullable hasta auth), D7 (`ActorType` tipado, tabla `actor_type`/`actor_id`, snippet de `ActorContext`), esquema `audit_log`.
- [`_bmad-output/planning-artifacts/epics.md`](../planning-artifacts/epics.md) — Epic 1 / Story 1.1 (ACs originales), FR14/FR15/FR16, NFR8/NFR10.
- [`docs/api-error-contract.md`](../../docs/api-error-contract.md) — tabla marcador→status (`InvalidInput` 400, `InvariantViolation` 422 `ClientError`, marker-less → 500/Sentry).
- [`api/src/Shared/Uuid/Domain/Uuid.php`](../../api/src/Shared/Uuid/Domain/Uuid.php) — `isValid()`/`ensure()`/`generate()`.
- [`api/src/Shared/Search/Domain/Exception/InvalidSearchValue.php`](../../api/src/Shared/Search/Domain/Exception/InvalidSearchValue.php) — patrón excepción de dominio (constructores nombrados, `context` sin valor ofensivo, predicado + excepción propia).
- [`api/src/Shared/ErrorContract/Domain/Exception/DomainException.php`](../../api/src/Shared/ErrorContract/Domain/Exception/DomainException.php) — base de excepción.
- [`api/src/Shared/Search/Domain/SortDirection.php`](../../api/src/Shared/Search/Domain/SortDirection.php) — patrón enum backed plano en `Domain/`.
- [`api/src/Shared/Kernel/Domain/ValueObject/NormalizedText.php`](../../api/src/Shared/Kernel/Domain/ValueObject/NormalizedText.php) — patrón `final readonly` VO con factorías estáticas + constructor privado.

## Dev Agent Record

### Agent Model Used

### Debug Log References

### Completion Notes List

### File List
