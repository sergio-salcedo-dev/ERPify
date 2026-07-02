# Story AF-1.1: Agregado `User` en `Backoffice/Identity` + persistencia

Status: ready-for-dev

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

Como **plataforma de ERPify**,
quiero **un agregado `User` de dominio con identidad, credencial y roles, y su persistencia**,
para **tener un modelo de identidad propio y libre de framework sobre el que AF-1.2 montará la autenticación**.

> **Origen:** Épica `auth-foundation` (`_bmad-output/planning-artifacts/epics-auth-foundation.md#Story AF-1.1`, FR3/FR5, NFR1/NFR4/NFR5), ADR [`auth-rbac-subsystem.md`](../../docs/adr/auth-rbac-subsystem.md) **D2** (`User` puro + adapter en Infra) y **D3** (enum `Role`), addendum [`arch-addendum-auth-rbac.md`](../planning-artifacts/arch-addendum-auth-rbac.md) (SI-2). Es **PR-0 del DAG**: sin dependencias hacia atrás; **desbloquea** AF-1.2 (firewall + `SecurityUser`) y, aguas abajo, E3.

### Scope — sólo el modelo de identidad + su persistencia

Esta story entrega, en un **contexto nuevo `Backoffice/Identity`**: (1) el agregado de dominio `User` (id UUID v7, `email` identificador, VO `HashedPassword`, roles como enum `Role`), **100 % libre de framework**; (2) su **puerto de repositorio** en `Domain` + **adapter Doctrine** en `Infrastructure` + **migración** reversible; (3) el registro del contexto en el gate `deptrac`. Es una épica de fundación técnica (sin superficie PWA): backend puro, verificable por tests.

### Frontera explícita — qué NO entra aquí (evitar scope creep)

- **NADA de Symfony Security.** Ni `security.yaml`, ni `symfony/security-bundle`, ni `UserInterface`, ni `SecurityUser`, ni `UserProvider`, ni authenticator, ni `PasswordHasherInterface`, ni CSRF → **todo AF-1.2**.
- **NADA de hashing.** El `HashedPassword` VO recibe un hash **ya calculado**; el dominio no conoce bcrypt/argon2id ni el hasher (D2, NFR5). Quién hashea (Infra) llega en AF-1.2.
- **NADA de `access_control`/401** → AF-1.3.
- **NADA del eje de auditoría.** No se toca `ActorContextFactory`, ni esquema/bus/storage del trail (NFR5, SI-1).
- **NADA de PWA** — épica 100 % `api/`.
- **Sin mapeo `Role → ROLE_*`** (eso lo emite el adapter `getRoles()` en AF-1.2); aquí sólo existe el enum de dominio.

### Decisiones de diseño (argumentadas — confirmar las marcadas ⚠️ con architect/Sergio)

1. **`id` = `string` RFC 4122, no un VO `UserId`.** Se reutiliza el trait `Identifiable` (`#[ORM\Id] #[ORM\Column(type: GUID)]`, **sin** `#[ORM\GeneratedValue]`, id asignado por la app vía `Uuid::generate()` v7). Es el patrón exacto de `Bank`/`BankAccount`; **no existe** `BankId`/precedente de VO de id → introducir `UserId` sería abstraer para un solo caller (YAGNI). El `string` UUID satisface directamente `ActorContext::forUser($id)` (validación de formato en `withValidatedId`) sin tocar el VO de auditoría.
2. **`User extends AggregateRoot`** (traits `Identifiable` + `Timestamped` + recolección de eventos). Da id + `created_at`/`updated_at` gratis y alinea con `Bank`. **No emite eventos de dominio en AF-1.1** (YAGNI: la auditoría de gestión de usuarios, si llega, es E3+). *Alternativa descartada:* entidad plana sin base → duplicaría el plumbing de id/timestamps que ya vive en los traits.
3. **⚠️ `HashedPassword` persistido como columna escalar `string`, no `#[ORM\Embedded]` ni tipo Doctrine custom.** Precedente `NormalizedText`: el VO se usa en el borde/constructor y la persistencia guarda un escalar. `User` acepta/expone `HashedPassword` en su API (`register(HashedPassword $pw)`, `passwordHash(): HashedPassword`) pero mapea una columna `password_hash VARCHAR`. *Descartado:* Embeddable de un único campo (sobre-ingeniería) / tipo DBAL custom (YAGNI). **Confirmar** que no se prefiere un Embeddable por consistencia futura.
4. **⚠️ `email` como identificador: almacenado canónico (minúsculas) + índice `UNIQUE`.** AF-1.2 cargará al usuario por identificador (`loadUserByIdentifier`) y **debe** ser case-insensitive; fijarlo AQUÍ (normalización a la creación + columna única) evita un login inconsistente después. `#[Assert\Email]` + `#[Assert\NotBlank]`. **Confirmar** si se guarda además la forma display (probablemente innecesario — YAGNI, como `shortName` en `Bank` que sólo guarda la forma canónica).
5. **⚠️ `roles` = columna `JSON` con los `->value` del enum `Role`.** El agregado mantiene `list<Role>`, mapea a/desde `string[]` en el column mapping. **Confirmar** política: ¿un `User` exige ≥1 rol, o `[]` es válido (usuario sin permisos)? Recomendado: permitir `[]` (un usuario recién creado sin rol es legítimo; el default-deny de AF-1.3 lo cubre).
6. **`User` NO implementa `AuditedEntity` — guardia de seguridad load-bearing.** El CDC `onFlush` de Epic 1 captura el changeset **sólo** de las entidades que optan por el marker (`Bank implements AuditedEntity`). Si `User` lo implementara, el **hash de contraseña entraría en el diff de auditoría** (fuga de credencial). Por tanto `User` **no** opta in. Si algún día se quiere auditar gestión de usuarios (E3+), `password_hash` debe excluirse/clasificarse `#[PersonalData]` **antes**. Ver *Must-preserve*.
7. **`HashedPassword` invariante mínima: no vacío.** Un hash nunca es cadena vacía. **No** se valida el formato/algoritmo (el dominio no lo conoce).
8. **`Role` enum backed string**, espejo de `BankAccountStatus`. Casos mínimos: **`AUDIT_READER`** (único que E3 exige: el adapter lo emitirá como `ROLE_AUDIT_READER`). `ADMIN` opcional si el bootstrap del primer usuario lo necesita — decidir al implementar el seed (ver riesgos). **Dirección del prefijo (fuente de verdad = dominio):** los `->value` viven sin `ROLE_`; Symfony es un *consumidor* del vocabulario de dominio, no su fuente — el prefijo se añade sólo en el borde de Infra (adapter), jamás al revés.
9. **Los roles son política de autorización EXTERNA, no una decisión de negocio.** El `User` los guarda como *dato* (fuente de verdad de qué roles tiene, que el adapter emite a Symfony), pero **ninguna lógica de `Application`/`Domain` ramifica por rol** para decidir comportamiento. Security decide *acceso* (allow/deny) **antes** de entrar; `Application` no conoce roles. Prohibido `if ($user->isAdmin())` / `if (in_array(Role::ADMIN, $user->roles()))` como gate de negocio. Esto evita que Security se filtre a lógica de negocio con el tiempo. (`User` **no** expone helpers `isAdmin()`/`isX()`; sólo `roles(): list<Role>` como dato para el adapter.)

## Acceptance Criteria

**AC1 — Agregado `User` libre de framework (FR3, FR5, NFR1, D2).**
Given el nuevo contexto `Backoffice/Identity`,
When se modela el agregado en `Domain/Entity/User.php`,
Then lleva **id UUID v7** (vía trait `Identifiable`, asignado por la app), **`email`** como identificador, un VO **`HashedPassword`** y roles como enum de dominio **`Role`**; **no importa ninguna clase de framework** (ni `Symfony\...\UserInterface`, ni hasher, ni Doctrine `EntityManager`); `make php.deptrac` verde para `Backoffice.Identity.Domain`.

**AC2 — Enum de dominio `Role` (FR5, D3).**
Given el modelo de roles,
When se define `Domain/Enum/Role.php`,
Then es un `enum Role: string` (espejo de `BankAccountStatus`) con al menos el caso **`AUDIT_READER`**; los `->value` **NO llevan el prefijo `ROLE_`** (`AUDIT_READER`, nunca `ROLE_AUDIT_READER`). El mapeo es **unidireccional Domain → Infra → Symfony**: el adapter de AF-1.2 antepone `ROLE_` al emitir `getRoles()`; **el dominio nunca conoce el prefijo `ROLE_`** y **nada** mapea un `ROLE_*` de Symfony de vuelta al enum. **Esta story no crea el adapter ni el mapeo.**

**AC3 — VO `HashedPassword` sin conocimiento del hasher (FR3, D2, NFR5).**
Given la credencial,
When se construye `HashedPassword` (`fromHash(string)`),
Then representa un hash **ya calculado**, rechaza la cadena vacía, y **el dominio nunca referencia** bcrypt/argon2id ni ningún `PasswordHasher*`; el hashing ocurre en Infrastructure en AF-1.2.

**AC4 — Persistencia: puerto + adapter + migración (FR3, NFR4).**
Given el agregado,
When se persiste,
Then existe un **puerto `UserRepository`** en `Domain/Repository` (`save`, `remove`, `findById(string): ?User`, `findByEmail(string): ?User`), un **adapter `DoctrineUserRepository`** en `Infrastructure/Persistence/Doctrine` (`#[AsAlias(UserRepository::class)]`, `EntityManagerInterface`, `persist`+`flush()` sin args — Doctrine ORM 3), y una **migración generada por `make db.diff`** que crea la tabla (id `GUID` PK, `email` `UNIQUE`, `password_hash`, `roles` `JSON`, `created_at`/`updated_at`), **reversible** (`down()` con `DROP TABLE IF EXISTS`), **sin sembrar PII ni secretos**.

**AC5 — Aislamiento de capas/contextos (NFR1, SI-2).**
Given el gate de arquitectura,
When se corre `make php.deptrac` + `make php.lint.bounded-context`,
Then pasan: se ha registrado `Backoffice.Identity.{Domain,Application,Infrastructure}` en `api/tools/deptrac/deptrac.yaml` (espejo de `Backoffice.Bank.*`); `Backoffice/Identity/Domain` **no alcanza framework** y **no importa** el `Domain` de otro contexto de negocio (sólo `Erpify\Shared\…`).

**AC6 — Cero retrabajo del eje de auditoría + no-fuga de credencial (NFR5, SI-1, seguridad).**
Given el agregado y la guardia de seguridad,
When se revisa el diseño,
Then `User` **no** implementa `AuditedEntity` (queda fuera del CDC `onFlush` de Epic 1 → el `password_hash` **nunca** entra en el diff de auditoría); y **no** se modifica `ActorContextFactory`, ni el esquema/bus/storage del trail, ni CORS/CSRF/Mercure.

**AC7 — Listo para la costura de actor (frontera con E3).**
Given un `User` persistido,
When E3 (Story 3.1) atribuya la identidad,
Then `User.id()` es un UUID RFC 4122 válido tal que **`ActorContext::forUser($user->id())` es satisfacible** — verificado por un test unitario (no se implementa el swap de `ActorContextFactory` aquí).

## Tasks / Subtasks

> Convenciones: DDD+Hexagonal (deps hacia `Domain/`). PHP 8.5, `declare(strict_types=1)`, PSR-12, tipos en todo. Espejo directo del vertical slice de `Backoffice/Bank`. Barrido de comentarios con ID de story/AC/FR antes del commit final (viven en este spec, **no** en el código).

### A. Contexto `Backoffice/Identity` — modelo de dominio (AC1, AC2, AC3)

- [ ] **A1.** `api/src/Backoffice/Identity/Domain/Entity/User.php`
  - [ ] `final class User extends AggregateRoot` (`Erpify\Shared\Kernel\Domain\Aggregate\AggregateRoot` → traits `Identifiable`+`Timestamped`). Constructor **privado** + factory estático `register(string $id, string $email, HashedPassword $password, Role ...$roles): self` (o `array $roles`), espejo de `Bank::create()`. Asignar `$this->id = $id` tras `parent::__construct()`.
  - [ ] `email`: normalizar a minúsculas en el factory (decisión ⚠️4); `#[ORM\Column(unique: true)]`, `#[Assert\Email]`, `#[Assert\NotBlank]`. Considerar `#[UniqueEntity(fields: ['email'])]` (como `Bank`) — la unicidad dura la impone el índice de la migración; `UniqueEntity` aporta el 422 limpio cuando exista un flujo de alta (AF-1.2).
  - [ ] `password_hash`: columna escalar `#[ORM\Column(name: 'password_hash')]` (decisión ⚠️3); API tipada (`passwordHash(): HashedPassword`, almacena `$password->toString()`).
  - [ ] `roles`: **columna `JSON`** con los `->value` de `Role`; accessor `roles(): list<Role>` que mapea de vuelta a enum (decisión ⚠️5). **No** usar Doctrine `enumType:` aquí — ese modo mapea **un** enum escalar (así hace `BankAccount.status`: `#[ORM\Column(type: TEXT, enumType: BankAccountStatus::class)]`), no una `list<Role>`.
  - [ ] **No** implementar `AuditedEntity`, **no** importar framework (sólo `Doctrine\ORM\Mapping`/`Assert` como metadata pasiva permitida, `symfony/uid` vía `Shared/Uuid`).
- [ ] **A2.** `api/src/Backoffice/Identity/Domain/HashedPassword.php` — VO: constructor privado + `fromHash(string $hash): self` (rechaza vacío → excepción de dominio, p. ej. `InvalidHashedPassword` en `Domain/Exception/`), `toString(): string`, igualdad por valor. Sin conocimiento del algoritmo (AC3).
- [ ] **A3.** `api/src/Backoffice/Identity/Domain/Enum/Role.php` — `enum Role: string` (espejo `BankAccountStatus`), caso mínimo `AUDIT_READER = 'AUDIT_READER'` (+ `ADMIN` si el seed lo requiere).
- [ ] **A4.** `api/src/Backoffice/Identity/Domain/Repository/UserRepository.php` — puerto: `save(User): void`, `remove(User): void`, `findById(string $id): ?User`, `findByEmail(string $email): ?User` (`findByEmail` es lo que consumirá el `UserProvider` de AF-1.2; se define ya para no reabrir el puerto).

### B. Persistencia — adapter Doctrine + migración (AC4)

- [ ] **B1.** `api/src/Backoffice/Identity/Infrastructure/Persistence/Doctrine/DoctrineUserRepository.php` — `final readonly class ... implements UserRepository`, `#[AsAlias(UserRepository::class)]`, inyecta `EntityManagerInterface`. `save` = `persist`+`flush()` (**sin args**, ORM 3), `remove` = `remove`+`flush()`, `findById` = `->find(User::class, $id)`, `findByEmail` = query builder parametrizado (`WHERE u.email = :email`, con `$email` ya en minúsculas). Espejo de `DoctrineBankRepository` (líneas 45–79). Sin herencia de `ServiceEntityRepository`.
- [ ] **B2.** Migración: `make db.diff` → genera `api/migrations/2026/VersionYYYYMMDDHHMMSS.php` (`extends AbstractMigration`, `$this->addSql(...)`). Revisar el SQL (tabla p. ej. `identity_user`; el `Types::GUID` del trait renderiza como **PG `UUID` PK sin `GeneratedValue`**, `email` `UNIQUE`, `password_hash VARCHAR`, `roles JSON`, `created_at`/`updated_at` `TIMESTAMP(0) WITHOUT TIME ZONE` — house style verificado en `Version20260616201857`). Verificar `down()` reversible (`DROP TABLE`). **Sin `INSERT` de datos.** Migración transaccional por defecto (no `CONCURRENTLY`). Aplicar con `make db.migrate` y verificar up+down en scratch DB.
- [ ] **B3.** Verificar **auto-wiring sin editar config**: `src/Backoffice` ya está attribute-mapped por prefijo en `api/config/packages/doctrine.yaml` (`dir: src/Backoffice`, `prefix: Erpify\Backoffice`) → `User` se mapea solo; `Erpify\: resource '../src/'` en `services.yaml` registra el adapter y el `#[AsAlias]` liga el puerto. **No** tocar `doctrine.yaml`/`services.yaml`. Confirmar que `make db.diff` detecta la entidad (si no, es señal de mapeo mal ubicado).

### C. Gate de arquitectura (AC5)

- [ ] **C1.** `api/tools/deptrac/deptrac.yaml`: añadir en `layers` los 3 layers `Backoffice.Identity.{Domain,Application,Infrastructure}` (collectors `directory: src/Backoffice/Identity/<Layer>/.*`) y en `ruleset` los bloques espejo de `Backoffice.Bank.*`. **Sólo `Backoffice.Identity.Domain: *domain` reutiliza el anchor** (`&domain` = `[Shared.Domain, Vendor.Psr, Vendor.SymfonyUid, Vendor.PassiveMetadata]`; idéntico en todos los módulos). **`Application` e `Infrastructure` se escriben explícitos** (copiar el bloque de `Backoffice.BankAccount.*` y renombrar) — **no** reutilizar el anchor `*infra`, porque apunta a los layers de *Bank* (`Backoffice.Bank.Domain/Application`), no a los de Identity. Infrastructure incluye `Vendor.Doctrine`. `make php.deptrac`.
  - [ ] **Nota AF-1.2:** ese `Infrastructure` ruleset ganará `Vendor.Symfony` cuando llegue el `SecurityUser` adapter — **no** añadirlo aquí (aún no hay import de Security).
- [ ] **C2.** `make php.lint.bounded-context` — `Identity` sólo importa `Erpify\Shared\…` y a sí mismo; ningún import a `Bank`/`BankAccount`/`Audit` `Domain`/`Infrastructure`.

### D. Tests (AC1–AC7)

- [ ] **D1.** Unit `api/tests/Unit/Backoffice/Identity/Domain/Entity/UserTest.php` (`final`, `/** @internal */`, `extends TestCase`, `#[CoversClass(User::class)]`, métodos **`testXxx` descriptivos** — es el default del repo, no `#[Test]`; AAA): `register` con id/email/hash/roles → accessors correctos; **email normalizado a minúsculas**; `id()` es UUID válido y **`ActorContext::forUser($user->id())` no lanza** (AC7); roles round-trip enum↔value. Añadir un **Object Mother** `Mother/UserMother.php` (`DEFAULT_ID` = literal UUID v7, `create()`), espejo de `BankMother`.
- [ ] **D2.** Unit `HashedPasswordTest` (rechaza vacío; igualdad por valor vía `equals()`; `toString`) y `RoleTest` (valores esperados; `AUDIT_READER` presente).
- [ ] **D3.** Functional `api/tests/Functional/Backoffice/Identity/DoctrineUserRepositoryTest.php` (`extends KernelTestCase`, `self::bootKernel()`, transacción siempre rolled-back — espejo de `DoctrineBankAccountCollectionSearchRepositoryTest`): `save`→`findById`→`findByEmail` (case-insensitive) round-trip; unicidad de `email` (segundo save con mismo email → violación); `remove` hard-borra (satisface NFR4/GDPR).
- [ ] **D4.** Test-guardia AC6: assert que `User` **NO** implementa `AuditedEntity` (patrón "X no es Y" → `assertFalse((new ReflectionClass(User::class))->implementsInterface(AuditedEntity::class))`; ver gotcha de tests de negación). Fija el invariante de no-fuga de credencial.
- [ ] **D5.** (recomendado, lo consumirá AF-1.2) `api/tests/Unit/Backoffice/Identity/Application/InMemoryUserRepository.php` — fake del puerto `UserRepository` (`#[Override]` por método, registra `saved`/`removed`, `findByEmail` case-insensitive), espejo de `InMemoryBankRepository`. Si no aporta a D1–D4, diferir a AF-1.2 (YAGNI).

### E. Docs (nuevo contexto + seguridad)

- [ ] **E1.** `docs/architecture-api.md`: nuevo contexto `Backoffice/Identity` (agregado `User` + puerto/adapter). Nuevo dir `src/` → actualizar también `docs/claude-code-quickref.md` y `docs/source-tree-analysis.md` (regla "keeping docs up to date").
- [ ] **E2.** `PRODUCTION_SECURITY_CHECKLIST.md` + `docs/rules/security.md`: se introduce el modelo de identidad — `password_hash` **nunca** se loguea/retorna/audita (AC6); `email` es PII, el hard-delete de `User` mantiene satisfacible el borrado GDPR (NFR4). Cambio security-sensitive → obligatorio.
- [ ] **E3.** Registrar el follow-up (issue o nota) de **bootstrap del primer usuario** y de la **pantalla de login PWA** (advertencia UX del readiness report) — no bloquean; encadenados tras AF-1.2.

### F. Barrido final

- [ ] **F1.** Sin comentarios con ID (story/AC/FR/NFR/D) en el código. `make php.stan` por archivo (**`PHP_SERVICE=messenger_worker`** por el segfault del web worker), luego `make php.quality` (deptrac + bounded-context + phpmd + cs-fixer + rector), `make php.psalm.taint`. Migración verificada up+down. Sin `// NOSONAR`.

## Dev Notes

### Estado actual (verificado en la rama)

- **`Backoffice/`** tiene hoy `Audit`, `Bank`, `BankAccount`, `Health`. `Identity` se añade como un 5º contexto **con la misma estructura** `Domain/Application/Infrastructure` (aquí `Application/` puede no ser necesaria en AF-1.1 — no forzarla; llega con el caso de uso de alta en AF-1.2).
- **`symfony/security-core ^8.0.13`** está en `api/composer.json` (sólo como lib, por `AccessDeniedException`). **`symfony/security-bundle` NO está** — lo instala AF-1.2. **No** requerir nada de Composer en AF-1.1.
- **`AggregateRoot`** (`Shared/Kernel/Domain/Aggregate`) = traits `Identifiable` (id `#[ORM\Column(type: GUID)]`, **sin** `GeneratedValue`, `#[Assert\Uuid(strict: true)]`, id asignado por la app) + `Timestamped` (`created_at`/`updated_at` `DATETIME_IMMUTABLE`) + `record()`/`pullDomainEvents()`. `Bank`/`BankAccount` lo extienden.
- **`Uuid`** (`Shared/Uuid/Domain`, abstracto): `generate()` = `SymfonyUuid::v7()->toRfc4122()`; `isValid`/`ensure`. Los ids son `string` RFC 4122; **no hay VOs de id**.
- **`ActorContext::forUser($id)`** (`Shared/Audit/Domain`) ya modela la atribución a usuario: valida `Uuid::isValid($id)`. El `User.id` UUID v7 lo satisface **sin cambiar el VO** (frontera E3, AC7).
- **Mapeo Doctrine**: `doctrine.yaml` mapea `src/Backoffice` por prefijo (`Erpify\Backoffice`, `type: attribute`) — la nueva entidad se auto-mapea. `services.yaml` `Erpify\: '../src/'` auto-registra servicios. **Ningún cambio de config necesario** salvo `deptrac`.
- **deptrac NO auto-descubre módulos** (`api/CLAUDE.md`): un contexto nuevo **debe** registrarse en `deptrac.yaml` (`layers`+`ruleset`). El hermano `php.lint.bounded-context` sí auto-descubre, así que la aislación cross-context queda cubierta mientras tanto.

### Decisión de arquitectura (resumen argumentado)

- **Principio:** DIP + aislamiento hexagonal (SI-2/D2). El `Domain` de identidad no puede conocer Symfony Security — lo impone `deptrac`. La credencial es un VO de dominio (`HashedPassword`) opaco al algoritmo; el hashing es un detalle de Infra que se inyecta después.
- **Objetivo:** un modelo de identidad **testeable sin contenedor ni DB** (dominio puro) y **sustituible** en su mecanismo de auth; desbloquea AF-1.2 con un puerto ya definido.
- **Coste / descartado:** `User implements UserInterface` (más simple, pero mete framework en `Domain/`, rompe `deptrac`, exigiría baseline permanente); `UserId` VO (abstracción sin segundo caller); Embeddable/tipo Doctrine para un hash escalar (YAGNI frente al precedente `NormalizedText`).

### Source tree — archivos a tocar

**API NEW:** `Backoffice/Identity/Domain/Entity/User.php`, `Domain/HashedPassword.php`, `Domain/Exception/InvalidHashedPassword.php`, `Domain/Enum/Role.php`, `Domain/Repository/UserRepository.php`, `Infrastructure/Persistence/Doctrine/DoctrineUserRepository.php`; migración `api/migrations/VersionXXXX.php`; tests Unit (`UserTest`, `HashedPasswordTest`, `RoleTest`) + Functional (`DoctrineUserRepositoryTest`).
**API UPDATE:** `api/tools/deptrac/deptrac.yaml` (registrar `Backoffice.Identity.*`). Docs: `docs/architecture-api.md`, `docs/claude-code-quickref.md`, `docs/source-tree-analysis.md`, `PRODUCTION_SECURITY_CHECKLIST.md`, `docs/rules/security.md`.
**NO TOCAR:** `ActorContextFactory` / `Shared/Audit` (NFR5, SI-1); `security.yaml` (no existe aún — AF-1.2); `doctrine.yaml`/`services.yaml` (auto-wiring cubre); `deptrac.baseline.yaml` (nunca a mano). No añadir deps Composer.

### Previous-story intelligence (patrones a espejar)

- **Vertical slice `Backoffice/Bank`** = plantilla directa: `Domain/Entity/Bank.php` (private ctor + `create()`, ORM/Assert inline, `extends AggregateRoot`), `Domain/Repository/BankRepository.php` (puerto `save`/`remove`/`findById`), `Infrastructure/Persistence/Doctrine/DoctrineBankRepository.php` (`#[AsAlias]`, EM persist+flush). **Ignorar** de `Bank` lo que AF-1.1 no necesita: search/keyset engine, Resource DTOs, controllers, Mercure, Media/StoredObject, projections.
- **`BankAccountStatus`** (`Backoffice/BankAccount/Domain/Enum`) = forma exacta del enum `Role`.
- **`BankAccount` PII/audit** (`Domain/Entity/BankAccountPersonalDataTest`, `BankAccountAuditTest`) = referencia de por qué la clasificación PII y el opt-in a auditoría son deliberados — aquí el opt-in **no** se hace (AC6).

### Testing standards

PHPUnit 13, `declare(strict_types=1)`. **Estilo del repo (verificado):** clases `final` + `/** @internal */` + `#[CoversClass]`; **nombres de método `testXxx` descriptivos** (el repo NO usa `#[Test]` en tests de dominio); Object Mother (`*Mother`) para datos de test e in-memory fake del puerto (`InMemory<Port>`). Dominio unit-test **sin contenedor ni DB** (`extends TestCase`). Repositorio con **Postgres real** (`extends KernelTestCase`, transacción rolled-back, no SQLite). Tests mirror del árbol `src/` bajo `api/tests/Unit|Functional/Backoffice/Identity/`.

### Quality gates + gotchas relevantes (memoria del repo)

- **deptrac**: registrar el módulo nuevo (C1) o `php.quality` falla; **no** silenciar vía baseline.
- `make php.stan` con **`PHP_SERVICE=messenger_worker`** (web worker segfault, exit 139).
- **PHPMD** no tiene baseline → sólo `make php.quality` lo caza; `CouplingBetweenObjects ≤13` **aplica a tests** (mantener los tests magros; fakes a un trait si hace falta); `#[CoversClass]` acredita sólo la clase target.
- **Rector** `CatchExceptionNameMatchingType` renombra `catch ($x)` al tipo → dispara `LongVariable`: no capturar la excepción si no se usa (`expectException`). Rector impone `assertNotInstanceOf`/`assertSame`; para tests de negación de tipo usar `ReflectionClass::implementsInterface` (D4).
- **Psalm/PHPStan `assertCount` tug-of-war**: si un test hace `assertCount(N,$arr)` y luego indexa, `array_unique`/reestructurar; correr **ambos**.
- Sin `// NOSONAR`, sin comentarios que narren un lint rule, sin comentarios con ID de story.

### Must-preserve / regresión

- **Eje de auditoría intacto (NFR5, SI-1):** no se toca `ActorContextFactory`, ni esquema/bus/storage del trail. `User` **no** es `AuditedEntity` → su `password_hash` **nunca** aparece en el diff `onFlush` (fuga de credencial evitada). Si en el futuro se audita `User`, excluir/clasificar `password_hash` **antes**.
- **Superficie sin ensanchar (NFR3):** cero cambios en CORS/CSRF/nelmio/Mercure.
- **`Domain/` puro:** ningún import de Symfony Security/Doctrine-behavioral/HTTP en `Identity/Domain` (deptrac lo verifica).
- **Roles = autorización externa (decisión 9):** ninguna ramificación por `Role`/`ROLE_*` en `Application`/`Domain`; el prefijo `ROLE_` sólo existe en el borde de Infra. `User` no lleva helpers `isAdmin()`.
- **Migración reversible + sin secretos (NFR4):** `down()` limpio; nunca sembrar credenciales en migraciones; hard-delete de `User` mantiene GDPR satisfacible.

### Project Structure Notes

`Backoffice/Identity` por **Regla de Tres** (único consumidor hoy = acceso backoffice al trail): se promociona a `Identity`/`IAM` top-level sólo con un **2º consumidor real** (Frontoffice/cliente/OAuth) **o cuando aparezcan capacidades propias de IAM** (MFA, password reset, login attempts, sessions, API keys, OAuth, SSO, impersonation) — **no antes** (ADR D2). Explícito para el futuro: **el ADR NO obliga a mantener `Identity` bajo `Backoffice` para siempre**; hoy es un BC auxiliar, y esas capacidades lo convertirán en un subdominio transversal cuando lleguen. `Application/` no se fuerza en AF-1.1; nace en AF-1.2 con el caso de uso de alta. El enum `Role` vive en `Domain/Enum` (no en `Shared`): es vocabulario de este contexto hasta que otro contexto lo consuma.

### Riesgos / decisiones abiertas (cerrar al implementar)

- **⚠️ Persistencia de `HashedPassword`** (escalar vs Embeddable) — recomendado escalar; confirmar (decisión 3).
- **⚠️ Normalización de `email`** (case-insensitive) — fijarla aquí para no romper el login de AF-1.2 (decisión 4).
- **⚠️ Cardinalidad de `roles`** (`[]` permitido) — recomendado permitir vacío (decisión 5).
- **Lifecycle del `User` — CONGELADO (review Sergio, 2026-07-02):** **sin auto-registro público** (identidad backoffice-only); alta de usuarios = admin autenticado vía una story posterior (no AF-1.1/1.2); **bootstrap del 1er usuario = comando de consola `identity:user:create`** (idempotente, hashea en Infra) en **AF-1.2** (cuando exista el hasher), espejo de los CLI de audit. **Nunca** credenciales en migraciones (NFR4); dev/test = fixture Alice con hash precomputado. En AF-1.1 basta `HashedPassword::fromHash('<precomputado>')` en tests. *(Propagado a ADR «Decided inputs» + épica «Riesgos».)*
- **CSRF — CONGELADO para AF-1.2 (review Sergio, 2026-07-02):** `SameSite=Lax` + verificación de `Origin` en no-seguros + **token CSRF stateless double-submit** (Symfony `csrf_protection: stateless`). Descartado Synchronizer Token (stateful, form-oriented). Fuera del alcance de AF-1.1; se implementa con el firewall en AF-1.2. *(Propagado a ADR D1 + épica «Riesgos».)*
- **Nombre de la tabla** (`identity_user` vs `users`) — `users` puede chocar con reservado/futuro; preferir prefijo de contexto `identity_user` (consistente con `bank`/`bank_account`). Confirmar el naming al revisar el `db.diff`.

### References

- [Source: `_bmad-output/planning-artifacts/epics-auth-foundation.md#Story AF-1.1`] — user story, ACs base, FR3/FR5, NFR1/NFR4/NFR5, frontera con E3.
- [Source: `docs/adr/auth-rbac-subsystem.md`] — D2 (`User` puro + `SecurityUser` adapter en Infra, hashing en Infra), D3 (enum `Role`→`ROLE_*`), D5/D6 (`actor_id` nullable, `ActorContextFactory` costura — contexto de frontera).
- [Source: `_bmad-output/planning-artifacts/arch-addendum-auth-rbac.md`] — SI-1/SI-2 (costura única de identidad; framework confinado), DAG (PR-0 → 3.1 → 3.2 → 3.3), tabla de localización por PR.
- [Source: `api/src/Backoffice/Bank/Domain/Entity/Bank.php` + `Domain/Repository/BankRepository.php` + `Infrastructure/Persistence/Doctrine/DoctrineBankRepository.php`] — vertical slice a espejar (agregado + puerto + adapter).
- [Source: `api/src/Shared/Kernel/Domain/Aggregate/AggregateRoot.php` + `Domain/Entity/{Identifiable,Timestamped}.php`] — base + traits (id `GUID` asignado, timestamps).
- [Source: `api/src/Shared/Uuid/Domain/Uuid.php`] — `generate()` v7, `ensure`/`isValid`.
- [Source: `api/src/Shared/Audit/Domain/ActorContext.php`] — `forUser($id)` (frontera AC7); `ActorType`.
- [Source: `api/src/Backoffice/BankAccount/Domain/Enum/BankAccountStatus.php`] — forma del enum `Role`.
- [Source: `api/config/packages/doctrine.yaml` + `api/config/services.yaml`] — auto-mapping por prefijo `Backoffice` + service glob (sin cambios).
- [Source: `api/tools/deptrac/deptrac.yaml`] — registrar `Backoffice.Identity.*` (espejo `Backoffice.Bank.*`).
- [Source: `api/CLAUDE.md` §"Deptrac architecture gate", "Layer rules"] — un módulo nuevo debe registrarse en deptrac; excepción de metadata pasiva `#[ORM]`/`#[Assert]` + `symfony/uid` en `Domain/`.

## Dev Agent Record

### Agent Model Used

### Debug Log References

### Completion Notes List

### File List
