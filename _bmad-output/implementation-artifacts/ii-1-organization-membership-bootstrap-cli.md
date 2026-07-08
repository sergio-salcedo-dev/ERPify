---
baseline_commit: 4ccb2f92
---

# Story II-1 (PR-1): `Organization` + `Membership` + bootstrap CLI de la primera organización

Status: done

Epic `identity-invitation-lifecycle` · **segunda historia en orden de merge safe-first** (`II-0 → II-1·II-2·II-3·II-7 → II-6 → II-4 → II-5 → II-8`). Slice: **fundación aditiva** — nace el contexto `Organization/` (agregados + bootstrap CLI) sobre la promoción estructural de II-0, **sin tocar el hot-path de auth ni la decisión del voter**. No depende de ninguna historia posterior.

> **⚠️ Baseline = `origin/main` (`4ccb2f92`, PR #458 ya mergeado con II-0).** El contexto `Organization/` hoy es un **esqueleto `.gitkeep`** (`api/src/Organization/.gitkeep`); las capas deptrac `Organization.Organization.*` y `Organization.Membership.*` **ya están registradas** por II-0 (reservadas, sin clases). La implementación se hace en el worktree/rama **`feat/iam-organization-membership-bootstrap-ljd3`** (creado sobre `origin/main` con autorización de Sergio). El `main` local del checkout primario estaba 1 commit por detrás — **no branquear sobre él**.

## Story

Como administrador de la instalación,
quiero provisionar la organización y el primer administrador por CLI, con la pertenencia usuario↔organización como enlace autoritativo,
para que exista una organización propietaria de identidades y roles desde el arranque, sin alta pública ni credenciales en migración.

**Comportamiento que introduce:** comandos CLI de bootstrap (`ProvisionOrganization`, `CreateInitialAdministrator`); el modelo `Organization` + `Membership(userId, organizationId, roles)`.

**Invariantes que consume:** la ubicación `Organization/` (esqueleto + capas deptrac de II-0); el `User` en `Iam/Identity` (II-0) referenciado **por id**.
**Invariantes que establece:**

- **SI-15** — el enlace autoritativo user↔org es `Membership(userId, organizationId, roles)`; **ningún user es «global»**; los roles son **org-scoped** y existen **antes** de que el user sea `ACTIVE`; **una** organización por instalación; **ninguna credencial/PII en migración**.
- **Roles autoritativos en `Membership`** (ADR D3) — *por modelo*. La fuente **operativa** de la autenticación/autorización (`SecurityUser::getRoles()`, `PermissionVoter`) **sigue siendo `User.roles` sin cambio** (decisión aditiva; ver §Decisiones).
- **Invariante de titularidad** — la organización mantiene **siempre ≥1 `Membership` con rol `ADMIN` cuyo `User` está activo**. Es una **regla de dominio**, **no** un tier `OWNER` nuevo (la titularidad billing/legal/transferencia es concepto futuro de tenancy, SRP).

## Decisiones (contexto para el dev — no re-abrir sin motivo)

1. **Roles: aditivo/escalonado (decisión de Sergio).** `Membership.roles` es autoritativo **por modelo** (ADR D3), pero **no se re-cablea el auth path en II-1**: `SecurityUser::getRoles()` y `PermissionVoter` **siguen leyendo `User.roles`** (columna JSON de II-0) tal cual. El bootstrap fija **el mismo set de roles en `User.roles` y en `Membership.roles`** (duplicación transitoria consciente). Retirar `User.roles` + resolver roles desde `Membership` (con el seam publicado que haga falta) es **follow-up nombrado** — candidato: **II-3**, donde ya se toca el auth path. Esto cumple safe-first (**NFR8**: PR-1 aditiva, cero cambio de superficie pública ni de TCB).

2. **Persistencia: state-oriented** (ADR D2). `Organization` y `Membership` son **datos de referencia/config** (una org por instalación, bootstrap CLI, snapshot actual = verdad de negocio) — **no** un ledger. Sin event-sourcing (misma familia que `Bank`). Extienden `AggregateRoot` (id UUID v7 + timestamps), pero **no** se emiten eventos de dominio en II-1: no hay consumidor/reactor (R2 «wire-on-consumer»), YAGNI.

3. **`organizationId` NO se añade a `User` en II-1** (recomendado — confirmar, §Preguntas). El enlace user↔org es `Membership` (autoritativo). Duplicar `organizationId` en `User` reproduciría exactamente el smell que la decisión (1) evita para los roles (en mono-org `User.organizationId == Membership.organizationId` siempre). La lectura literal de **SI-15** («todo agregado carga `organizationId`») es el **seam de scoping por fila** para el *enforcement* cross-tenant — **diferido** (NFR6: seam modelado, operación diferida); se realiza cuando se construya ese enforcement, no aquí. II-1 introduce el seam **modelando** `Organization` + `Membership(organizationId)`.

4. **Vocabulario `Role` cross-context** (recomendado — confirmar, §Preguntas). `Membership.roles` necesita el enum `Role`, que vive en `Iam\Identity\Domain\Enum\Role`. Que `Organization/Membership/Domain` lo importe es **cross-context**. Recomendado: **publicar `Role` como seam** en `.bounded-context-allowlist` (`* => Erpify\Iam\Identity\Domain\Enum\Role`), tratándolo como **vocabulario de autorización publicado** — transitorio y coherente con el *parking* del core RBAC de II-0. Su **hogar propio** (mover `Role` al futuro plano de autorización `Access/`/`Kernel/Authorization`, o a `Shared/`) se pliega al **follow-up de extracción RBAC** ya registrado; **no se decide aquí**. *(Alternativa descartada por ahora: `Membership` guarda `list<string>` de valores crudos — pierde tipado y duplica la validación del vocabulario.)*

5. **Reconciliación de `identity:user:create` + fixtures** (para honrar AC «ningún User sin Membership»). El `identity:user:create` actual crea un `User` **sin** `Membership`, y las fixtures `user_alice`/`user_mallory` también. II-1 debe cerrarlo. Recomendado: el **CLI de bootstrap orquesta ambos contextos en Infra** (crea `User` vía `Iam` + `Membership` vía `Organization`), y **`identity:user:create` se retira o evoluciona** para no dejar users sin membership. Ver §Decisiones de diseño (gotcha 3) para la topología del seam.

## Acceptance Criteria

1. **`Organization` + `Membership` modelados como agregados que referencian por id.** Existen `Erpify\Organization\Organization\Domain\Entity\Organization` (state-oriented: `id` UUID v7, `name`) y `Erpify\Organization\Membership\Domain\Entity\Membership` (`id`, `userId`, `organizationId`, `roles`). Toda referencia cross-module es **por id** (`private string $userId`, `private string $organizationId`) — **nunca** `#[ORM\ManyToOne]` a la entidad de otro módulo. Cada uno tiene su puerto de repositorio en `Domain/Repository` + impl Doctrine por **composición** (`EntityManagerInterface` inyectado, `#[AsAlias]`, sin `ServiceEntityRepository`), espejo de `DoctrineUserRepository`. *(D1, D2, SI-15, per-aggregate isolation)*

2. **Roles autoritativos en `Membership`; auth intacto.** `Membership` porta `roles` (enum `Role`, distinct, org-scoped). `SecurityUser::getRoles()` y `PermissionVoter` **no se tocan** y siguen leyendo `User.roles`. El bootstrap escribe el **mismo** set en `User.roles` y `Membership.roles`. *(D3, decisión aditiva)*

3. **CLI `ProvisionOrganization`.** Crea **la** organización de la instalación. Una **segunda** invocación se **rechaza** con un error de dominio claro (invariante *una org/instalación*) — o es idempotente si el dev lo justifica; recomendado **rechazar** (provisionar es un bootstrap único). No siembra credenciales ni PII. *(FR2, R5)*

4. **CLI `CreateInitialAdministrator`.** Crea el `User` administrador (password fijada **por CLI**, hasheada en Infra, nunca impresa/logueada) **y** su `Membership` con rol **`ADMIN`** — tier máximo, **sin** `OWNER`/`SUPER_ADMIN`/`ROOT` (un rol superior sería evolución explícita del modelo, YAGNI). La operación es atómica (no deja un `User` sin `Membership` ni viceversa ante fallo). *(FR2, ≥1 ADMIN, security)*

5. **Ningún `User` sin `Membership`.** Toda vía válida de creación de usuario deja al `User` con **exactamente un** `Membership` (su `organizationId` + roles). `identity:user:create` se **reconcilia** (retirar, o evolucionar para adjuntar `Membership`) y las **fixtures** `User.yaml` (`user_alice`, `user_mallory`) reciben su `Membership` companion en la org de test. Un test lo verifica sobre el flujo de bootstrap. *(AC4, SI-15)*

6. **Invariante ≥1 `ADMIN` activo — establecido.** Tras el bootstrap, la organización tiene **al menos un `Membership` con rol `ADMIN` cuyo `User` está en estado activo/operativo**; documentado como **invariante de dominio** (no tier RBAC). Un test lo asserta sobre el resultado del bootstrap. La **preservación** bajo `suspend`/`deactivate` se verifica en **II-3**; bajo `demote`/`remove` (baja de rol / de `Membership`) en el **slice diferido de gestión de miembros** — **no** en II-1. *(R5, YAGNI)*

7. **Migración limpia, sin credenciales.** `make db.diff` genera las tablas `organization` + `membership`. FK física **solo intra-contexto** `membership.organization_id → organization.id` (indexada, **schema-aware por el listener `postGenerateSchema`** — no `ManyToOne` en el mapping); `membership.user_id` cruza a `Iam` y por aislamiento cross-context **NO** lleva FK física (solo UNIQUE index) — *excepción confirmada en review 2026-07-07, patrón bank↔bankaccount*. `down()` reversible; **hard-delete** por defecto. La migración **no** contiene credenciales ni PII. Se añade el mapping Doctrine **`Organization`** en `doctrine.yaml` (espejo del bloque `Iam`, `dir: src/Organization`, `prefix: Erpify\Organization`). *(AC3, FR2, security, database rules)*

8. **Gates de arquitectura verdes.** `Organization.Organization.*` y `Organization.Membership.*` (capas ya registradas por II-0) alojan las clases; el/los **seam(s) publicado(s)** entre `Iam` y `Organization` que exija el bootstrap (y el vocabulario `Role`, §Decisiones 4) van en `api/.bounded-context-allowlist` (patrón Bank↔BankAccount). `make php.deptrac` + `make php.lint.bounded-context` + `make php.lint.error-contract` verdes. *(NFR7)*

9. **Fixtures Alice para dev/test.** Existen `Organization.yaml` + `Membership.yaml` (con factory si el agregado toma VOs, espejo de `UserFixtureFactory`); dev/test se siembra **solo** por fixtures, **nunca** en migración. *(AC5)*

10. **No-regresión.** `make app.test` + `make app.quality` verdes. El comportamiento HTTP observable de auth (login `/api/v1/backoffice/login`, distinción 401-vs-403, decisión del `PermissionVoter`) es **idéntico** al baseline — la ruta `User.roles` no se altera. *(NFR8)*

## Tasks / Subtasks

- [x] **T1 · Baseline verde sobre el worktree** (AC: 10)
  - [x] `make app.dev` en `feat/iam-organization-membership-bootstrap-ljd3`; capturar baseline verde `make app.test` + `make app.quality` (para el diff de no-regresión de T9). Confirmar que `api/src/Organization/.gitkeep` existe y `api/src/Iam/Identity/…` está presente (prueba de que #458 está en la base).

- [x] **T2 · Agregado `Organization`** (AC: 1, 7)
  - [x] `Organization/Organization/Domain/Entity/Organization` extends `AggregateRoot` (`id`, `name`; factory `provision()` que funnelea invariantes; `#[ORM\Entity]` + `#[ORM\Table(name: 'organization')]`; `#[Assert]` en `name`). VO de nombre solo si aporta invariante real (YAGNI si no).
  - [x] Puerto `Organization/Organization/Domain/Repository/OrganizationRepository` (`save`, `findById`, y un `exists()`/`findTheOne()` para el invariante *una org/instalación*) + `Infrastructure/Persistence/Doctrine/DoctrineOrganizationRepository` (composición, `#[AsAlias]`).

- [x] **T3 · Agregado `Membership`** (AC: 1, 2, 6)
  - [x] `Organization/Membership/Domain/Entity/Membership` extends `AggregateRoot` (`id`, `userId`, `organizationId`, `roles: list<string>` mapeado JSON; refs cross-module por id, `Uuid::ensure()` en el borde). Roles distinct (espejo de `User::distinctRoleValues`). Tipar `roles()` como `list<Role>` usando el enum publicado (§Decisiones 4).
  - [x] Puerto `MembershipRepository` (`save`, `remove`, `findByUserId`, y lo que exija el invariante ≥1 ADMIN — p.ej. `findAdminsOf(organizationId)`) + impl Doctrine por composición.
  - [x] Documentar el invariante **≥1 ADMIN activo** como concepto de dominio; **no** sobre-modelar enforcement de removal (diferido) — basta que el bootstrap lo satisfaga + test (T8).

- [x] **T4 · Casos de uso Application** (AC: 3, 4, 5)
  - [x] `Organization/…/Application/ProvisionOrganization` (crea la org; rechaza si ya existe). `Organization/Membership/Application/GrantMembership` (crea `Membership(userId, organizationId, roles)`; valida vía `Validator::ensure`). Espejo de `Iam\Identity\Application\CreateUser` (server mint UUID v7, `Validator::ensure`, `repo->save`).
  - [x] Definir la **orquestación de bootstrap** que compone `Iam\Identity\Application\CreateUser` (crea `User` + `User.roles`) con `GrantMembership` (crea `Membership` + `Membership.roles`), en el mismo set de roles. Ver gotcha 3 para el placement + seam.

- [x] **T5 · CLI de bootstrap** (AC: 3, 4)
  - [x] `ProvisionOrganization` (`#[AsCommand('organization:provision')]` o nombre acordado) y `CreateInitialAdministrator` (`#[AsCommand]`) — password por **prompt oculto** (nunca argumento visible en `ps`/history), hasheada vía `PasswordHasher` (Infra), espejo de `CreateUserCommand`. Salidas `SymfonyStyle` claras; `Command::INVALID`/`FAILURE`/`SUCCESS`.
  - [x] Placement del comando + seam cross-context: ver gotcha 3. Añadir la(s) entrada(s) en `.bounded-context-allowlist`.

- [x] **T6 · `identity:user:create` + fixtures reconciliados** (AC: 5, 10)
  - [x] Reconciliar `identity:user:create`: **retirar** (recomendado — bajo impacto en tests) **o** evolucionar para adjuntar `Membership`. **Consumidores verificados:** el único test es `api/tests/Functional/Iam/Identity/CreateUserCommandTest.php` (behat y el harness `AuthenticatesFunctionalRequests`/`SecurityContext` **no** usan el CLI — siembran vía fixtures). Si se retira: borrar ese test + su comando/`CreateUser` si quedan sin uso, y **barrer los docs/ADRs que nombran `identity:user:create` como bootstrap** → apuntarlos a `CreateInitialAdministrator`: `docs/adr/auth-rbac-subsystem.md` (D3/§Lifecycle), `docs/adr/identity-invitation-lifecycle.md` (§7), `_bmad-output/planning-artifacts/epics-auth-foundation.md`, `epic-3-context.md`, `PRODUCTION_SECURITY_CHECKLIST.md` L205.
  - [x] `User.yaml`: añadir `Membership` companion para `user_alice` (roles `[AUDIT_READER]`) y `user_mallory` (roles `[]`), ligados a la org de test (fixture `Organization.yaml`). Actualizar `AuthenticatesFunctionalRequests`/`SecurityContext` si asumen creación de user sin membership.

- [x] **T7 · Doctrine + deptrac + migración** (AC: 7, 8)
  - [x] `doctrine.yaml`: añadir mapping `Organization` (`type: attribute`, `is_bundle: false`, `dir: '%kernel.project_dir%/src/Organization'`, `prefix: 'Erpify\Organization'`, `alias: Organization`), espejo del bloque `Iam`.
  - [x] `.bounded-context-allowlist`: seam(s) `Iam`↔`Organization` del bootstrap + vocabulario `Role` (§Decisiones 4), con comentario justificativo (patrón Bank↔BankAccount).
  - [x] `make db.diff` → migración `organization` + `membership` en `api/migrations/2026/`; revisar FKs/índices, `down()` reversible, **sin PII/credenciales**. `make php.deptrac` + `make php.lint.bounded-context` verdes.

- [x] **T8 · Tests** (AC: 1, 2, 3, 4, 5, 6)
  - [x] **Unit (dominio, sin DB):** invariantes de `Organization` (provisión) y `Membership` (roles distinct, refs por id, ≥1 ADMIN sobre el resultado del bootstrap); *timing/entropía* no aplica aquí.
  - [x] **Functional/integración:** los dos comandos CLI (org + admin creados con `Membership` ADMIN; **segunda** `ProvisionOrganization` rechazada); repos Doctrine; «ningún `User` sin `Membership`» tras bootstrap.
  - [x] **Migración:** un check de que `make db.diff` post-migración = vacío y que la migración no contiene literales de credencial/PII.

- [x] **T9 · No-regresión + cierre** (AC: 10)
  - [x] `make sf.cc`; `make app.test` + `make app.quality` verdes; diff vs baseline de T1 (mismos gates: deptrac, bounded-context, error-contract, stan, cs-fixer, phpmd, rector, psalm-taint). Smoke: login `204`, ruta gateada anónima `401`, decisión del voter intacta.
  - [x] Actualizar docs de estructura por el **nuevo árbol `src/Organization/**` con clases**: `docs/architecture-api.md`, `docs/source-tree-analysis.md`, `docs/claude-code-quickref.md`, y `api/CLAUDE.md` si procede (o follow-up explícito). Nuevo comando CLI → `docs/development-guide-api.md` + quickref.

## Dev Notes

### Contrato de diseño (fuente de verdad)

- ADR `docs/adr/identity-invitation-lifecycle.md` — **D2** (dominio multi-tenant-ready; `Membership(userId, organizationId, roles)` autoritativo; **una org/instalación**; bootstrap CLI; nunca credenciales en migración) y **D3** (roles viven en `Membership`; `User`+`Membership` existen en `INVITED` antes de aceptar; `HashedPassword` nullable-hasta-`ACTIVE` — esto último **es II-3**, no II-1).
- Épica `_bmad-output/planning-artifacts/epics-identity-invitation-lifecycle.md` — historia **II-1** (comportamiento/consume/establece + 6 AC; invariante ≥1 ADMIN activo), **FR2**, **NFR6**, y §Método de las historias (AC como invariantes verificables).
- Addendum `_bmad-output/planning-artifacts/arch-addendum-identity-invitation.md` — tabla **PR-1** + **SI-15** + DAG (II-1 ⟸ II-0, no bloquea a nadie posterior).
- Reglas: `docs/adr/bank-bankaccount-modeling.md` (per-aggregate isolation, refs por id, FK schema-aware vía `postGenerateSchema`, `.bounded-context-allowlist`), `docs/rules/database.md` (hard-delete default, isolation Level 1/2), `docs/rules/security.md`.

### Estado actual del código (leído sobre `origin/main 4ccb2f92` — no reinventar)

- **`Organization/`** = solo `api/src/Organization/.gitkeep`. Capas deptrac `Organization.Organization.{Domain,Application,Infrastructure}` y `Organization.Membership.{…}` **ya registradas** por II-0 (`deptrac.yaml` L108–122, reglas L207–208/262–270/389+; Domain reusa el anchor `*domain`). **No** hace falta crear capas nuevas; sí **poblarlas** con clases.
- **`Iam/Identity`** (patrones a espejar):
  - `Domain/Entity/User` extends `AggregateRoot`; `#[ORM\Table(name: 'identity_user')]`, `email` UNIQUE, `roles: list<string>` JSON, factory `register()` privada-constructor, `distinctRoleValues()`. **`User` NO se modifica en II-1** (decisión aditiva).
  - `Domain/Enum/Role` = `VIEWER<EDITOR<MANAGER<ADMIN` + `AUDIT_READER` (`->value` sin `ROLE_`). Es el vocabulario que `Membership` referencia (§Decisiones 4). El enum es **pura vocabulario** (sin ranking/mapeo — eso vive en `StaticAuthorizationPolicy`).
  - `Domain/{Email,HashedPassword}` = VOs `final readonly` con factory + `equals`. `Domain/Repository/UserRepository` = puerto (`save/remove/findById/findByEmail`).
  - `Application/CreateUser` = server mint `Uuid::generate()`, `Validator::ensure($user)`, `repo->save`. `Infrastructure/Cli/CreateUserCommand` = `#[AsCommand('identity:user:create')]`, prompt oculto, `PasswordHasher`, `SensitiveParameter`.
  - `Infrastructure/Persistence/Doctrine/DoctrineUserRepository` = **composición** (`EntityManagerInterface`), `#[AsAlias(UserRepository::class)]`, `#[Override]`.
  - `Infrastructure/Security/SecurityUser::getRoles()` = **único** sitio que añade `ROLE_`; lee `$user->roles()`. **No tocar** (la retirada de `User.roles` es follow-up II-3).
- **`AggregateRoot`** (`Shared/Kernel/Domain/Aggregate`) = `Identifiable` (id nullable, asignado pre-persist) + `Timestamped` + `record()/pullDomainEvents()`. `id()` protegido lanza si null.
- **`doctrine.yaml`**: `auto_mapping: false`; mappings `Backoffice`, `Iam`, `SharedMedia`, `SharedStorage`. **Falta `Organization`** — añadirlo (T7). Sin él, las entidades `Organization/**` **no se mapean** → 500.
- **`.bounded-context-allowlist`**: 3 formas de entrada (fichero completo · `path => Fqcn` · `* => Fqcn` global). Precedente: `Bank/Application/BankDeleter.php => …\BankAccount\Domain\Repository\BankAccountRepository` y `BankAccountCountEnricher => …\BankAccount\Domain\Repository\BankAccountCounter`.
- **Fixtures**: `api/tests/DataFixtures/Fixtures/User.yaml` (`user_alice` AUDIT_READER, `user_mallory` role-less) vía `UserFixtureFactory::create` (hashea plaintext bcrypt cost 4 porque el `$` del hash colisiona con la sintaxis Alice). Ambos users **sin Membership** hoy → reconciliar (T6).
- **Migración**: última histórica `api/migrations/2026/Version20260702092603.php` — **inmutable** (mergeada). II-1 crea una **nueva** vía `db.diff`.

### Artefactos a crear/tocar (rutas exactas)

| Fichero | Qué |
|---|---|
| `api/src/Organization/Organization/{Domain,Application,Infrastructure}/**` | **NUEVO** agregado `Organization` (entity, repo port + Doctrine, `ProvisionOrganization`) |
| `api/src/Organization/Membership/{Domain,Application,Infrastructure}/**` | **NUEVO** agregado `Membership` (entity, repo port + Doctrine, `GrantMembership`) |
| CLI bootstrap (`ProvisionOrganization` + `CreateInitialAdministrator`) | **NUEVO** — placement + seam según gotcha 3 |
| `api/config/packages/doctrine.yaml` | **EDITAR** añadir mapping `Organization` |
| `api/tools/deptrac/deptrac.yaml` | **NO editar capas** (ya están); solo si el seam requiere `skip_violations` |
| `api/.bounded-context-allowlist` | **EDITAR** seam(s) `Iam`↔`Organization` + vocabulario `Role` |
| `api/migrations/2026/VersionYYYY…php` | **NUEVO** vía `make db.diff` (`organization` + `membership`) |
| `api/src/Iam/Identity/Infrastructure/Cli/CreateUserCommand.php` (+ `Application/CreateUser`) | **RECONCILIAR** `identity:user:create` (retirar/evolucionar) |
| `api/tests/DataFixtures/Fixtures/{Organization,Membership}.yaml` (+ factory si aplica) | **NUEVO** |
| `api/tests/DataFixtures/Fixtures/User.yaml` | **EDITAR** Membership companion para alice/mallory |
| `api/tests/{Unit,Functional}/Organization/**` | **NUEVO** tests |
| `docs/architecture-api.md`, `docs/source-tree-analysis.md`, `docs/claude-code-quickref.md`, `docs/development-guide-api.md`, `api/CLAUDE.md` | **EDITAR** nuevo contexto `Organization/` + comando CLI (o follow-up) |

### Decisiones de diseño y gotchas críticos

1. **`doctrine.yaml` — entidad huérfana si no se mapea.** `auto_mapping: false`; sin un mapping `Organization` nuevo, `Organization`/`Membership` no se mapean → error. Espejar el bloque `Iam`. Solo tras crear las entidades (un mapping a un dir sin entidades puede romper — mismo motivo por el que II-0 difirió este mapping a II-1).
2. **FK cross-module sin `ManyToOne`.** `Membership.userId`/`organizationId` son `string` (ids), **no** relaciones tipadas. La integridad referencial física la mantiene el listener `postGenerateSchema` schema-aware (ADR bank-bankaccount). No introducir un object graph que cruce el módulo.
3. **Seam cross-context del bootstrap — el punto arquitectónico de II-1.** Crear `User` (`Iam`) + `Membership` (`Organization`) en una operación cruza dos contextos. El gate bounded-context (Level 1) **falla** ante un import `Domain/Application/Infrastructure` de otro contexto salvo allowlist. Recomendado: **el comando CLI (Infra) orquesta** `Iam\Identity\Application\CreateUser` (seam **publicado**, allowlisted) + `Organization\Membership\Application\GrantMembership` (local), alojando el comando en **`Organization/…/Infrastructure/Cli`** (el bootstrap es org-céntrico) — mirror del precedente `BankDeleter → BankAccountRepository`. Entrada allowlist con comentario justificativo. **Confirmar el placement** (org-infra vs iam-infra) en review.
4. **Vocabulario `Role`** — §Decisiones 4: publicar `Role` vía `* => Erpify\Iam\Identity\Domain\Enum\Role`; hogar propio = follow-up RBAC.
5. **`identity:user:create` invalida el invariante AC4** si sigue creando users sin `Membership`. Retirar (preferido — invitation-first lo sustituye en II-4; los users de dev/test van por fixtures; único consumidor = su propio `CreateUserCommandTest`) o evolucionar. **Coste de retirar:** actualizar los docs/ADRs que lo nombran como bootstrap (ver T6). El harness de tests funcionales/behat **no** depende del CLI (usa fixtures), así que retirarlo no toca la suite salvo `CreateUserCommandTest`.
6. **Password nunca en migración/log/fixture-migración.** El admin fija su password por CLI (prompt oculto, `SensitiveParameter`); dev/test vía `UserFixtureFactory` (plaintext legible → bcrypt en el factory), **jamás** en el schema.
7. **`HashedPassword` nullable / `IdentityStatus` = II-3, NO aquí.** II-1 no introduce estados de identidad ni nullabilidad del password; el admin bootstrapeado nace con password fijada. No adelantar II-3.
8. **Sin eventos de dominio en II-1** (R2: sin consumidor). Los agregados extienden `AggregateRoot` pero no `record()` — se cablearán cuando haya reactor/audit consumer (eventos de seguridad son II-4…II-8, NFR10).

### Fuera de alcance (NO hacer)

- ❌ Tocar `User`/`SecurityUser`/`PermissionVoter`/`UserProvider` o cambiar la resolución de roles del auth path (retirada de `User.roles` = follow-up II-3).
- ❌ `IdentityStatus`, `HashedPassword` nullable, `UserChecker`, muros de admisión (II-3).
- ❌ `Invitation` (II-4), `Session` (II-7), `Shared/Token` (II-2), lockout (II-6).
- ❌ Enforcement de removal del último ADMIN / `demote` / gestión de miembros (slice diferido); tenant-switching, self-signup, `organizationId` en `Bank`/`BankAccount`/audit (tenancy operativa diferida, NFR6).
- ❌ Un tier `OWNER`/`SUPER_ADMIN`/`ROOT` (YAGNI; ≥1 ADMIN es regla de dominio).
- ❌ Mercure/realtime (sin consumidor). Refactors de oportunidad fuera de los ficheros que tocas.

### Testing (obligatorio; convenciones del repo)

- **Unit de dominio sin DB** (AAA, un comportamiento/test, nombres por comportamiento): invariantes de `Organization`/`Membership`, roles distinct, refs por id, ≥1 ADMIN sobre el bootstrap. Preferir **in-memory repos** (fakes de los puertos) sobre mocks.
- **Functional/integración** (Postgres real, transacción/fixtures): los dos comandos CLI, repos Doctrine, «ningún User sin Membership», segunda `ProvisionOrganization` rechazada.
- **Gates:** `make php.deptrac`, `make php.lint.bounded-context`, `make php.lint.error-contract`, `make db.diff`=vacío tras migrar, `make app.test` + `make app.quality` verdes idénticos a baseline.
- **Recordatorios repo:** PHPMD/cs-fixer solo los caza `make php.quality` (sin baseline PHPMD; puede OOM/exit 137). Worker FrankenPHP puede segfaultar en `php.stan` → `PHP_SERVICE=messenger_worker` si aparece exit 139. En worktree fresco, `make php.behat.install` antes de behat/quality.

### Project Structure Notes

- Nuevo top-level `Organization/` con **dos módulos** (`Organization`, `Membership`), cada uno `{Domain,Application,Infrastructure}` — espejo estructural de `Iam/Identity`. Convención deptrac de capa: `<Context>.<Module>.<Layer>`.
- El worktree `feat/iam-organization-membership-bootstrap-ljd3` (scope `iam` por continuidad con II-0, aunque el churn principal es `Organization/`) está creado sobre `origin/main` con autorización de Sergio; el story + implementación viven en la **misma** rama → un solo PR.

### References

- `docs/adr/identity-invitation-lifecycle.md` (D2, D3; §Implementation «bootstrap CLI-only, credenciales nunca en migración»).
- `_bmad-output/planning-artifacts/epics-identity-invitation-lifecycle.md` (II-1, FR2, NFR6, invariante ≥1 ADMIN).
- `_bmad-output/planning-artifacts/arch-addendum-identity-invitation.md` (PR-1, SI-15, DAG).
- `docs/adr/bank-bankaccount-modeling.md` (refs por id, FK schema-aware, `.bounded-context-allowlist`).
- Código en vigor (`origin/main 4ccb2f92`): `api/src/Iam/Identity/**` (patrones), `api/config/packages/doctrine.yaml`, `api/.bounded-context-allowlist`, `api/tools/deptrac/deptrac.yaml`, `api/tests/DataFixtures/**`.

### Previous Story Intelligence (II-0, #458 merged)

- II-0 promovió `Backoffice/Identity → Iam/Identity` (move puro) + esqueletos `Iam/Invitation`, `Iam/Session`, `Organization/` (`.gitkeep`) + **capas deptrac de `Iam.*`/`Organization.*` ya registradas** (reglas espejo de Identity). II-1 **puebla** `Organization/*`; no re-registra capas.
- II-0 dejó explícito: «`Membership` **NO** se crea en II-0 — nace nuevo en `Organization/` en II-1» y «el mapping doctrine `Organization` se añade en II-1, cuando existan sus entidades». II-1 ejecuta ambos.
- El core RBAC (`PermissionVoter`, `StaticAuthorizationPolicy` con `TIER_VERBS`) quedó **parqueado** en `Iam/Identity/Infrastructure/Security` (ubicación temporal; hogar propio = follow-up). El vocabulario `Role` que II-1 necesita para `Membership` vive ahí — de ahí la decisión del seam publicado (§Decisiones 4).
- II-0 usó `git mv` para preservar historia; II-1 es creación nueva. Baseline II-0 fue `main`, no la rama de planificación #455 — II-1 lo es sobre `origin/main` (#458).

### Git Intelligence

- Baseline `4ccb2f92` (`feat(iam): identity & invitation foundation — ADR + II-0 promote Identity to top-level Iam (#458)`).
- Commits recientes relevantes: `edd69e44` (#456 RBAC voter/policy/role tiers — origen del enum `Role`), `70c52f89` (#457 keyset fingerprint, RM-2).

### Project Context Reference

- `docs/project-context.md` — layout `Backoffice|Frontoffice|Shared` + hexagonal; esta historia **añade** el top-level `Organization/` con clases (II-0 solo reservó el namespace). Doctrine ORM 3 / DBAL 4 gotchas (sin `flush($entity)`, `fetchAllAssociative`, `executeQuery`). Migraciones vía `make db.diff`; nunca modificar la DB directamente ni sembrar PII.

## Dev Agent Record

### Agent Model Used

claude-opus-4-8[1m] (Claude Opus 4.8, 1M context)

### Debug Log References

- `make php.deptrac` → 0 violations / 75 skipped (incl. the 4 new Organization↔Iam seams) / 0 warnings / 0 errors.
- `make php.lint.bounded-context` → OK (8 tests); `DeptracSeamSyncGateTest` OK (allowlist ↔ deptrac in sync).
- `make php.stan` → 0 errors (821 files). Fresh worktree needed `make php.behat.install` first ([[behat-tooling-isolated-install]]).
- `make php.quality` → EXIT 0 (cs-fixer, rector, phpmd, psalm-taint, error-contract, deptrac, stan). Two fixes during the sweep: PHPMD `CouplingBetweenObjects` on `BootstrapCommandsTest` (14→trimmed by deriving the org id from the membership, not fetching the `Organization` entity/repo); PHPCS 120-char on three test lines (extracted `TRUNCATE_SQL` const + a `saveMembershipWith` helper).
- `make db.diff` after migrate → "No changes detected" (schema listener holds the `fk_membership_organization` FK — stable).
- `make db.load.fixtures` → loaded; `organization`=1, `membership`=2 (alice AUDIT_READER, mallory role-less); FK ordering resolved via Alice `@`-refs.
- `make php.test` → PHPUnit 1582 tests / 7208 assertions (3 skipped) + Behat 210 scenarios / 2099 steps — all green (no regression). PWA untouched (backend-only story), so `pwa.*` not run.

### Completion Notes List

- **`Organization/` context built from the II-0 `.gitkeep` skeleton:** `Organization` + `Membership(userId, organizationId, roles)` aggregates (state-oriented, extend `AggregateRoot`, cross-module refs by id, `Uuid::ensure()` at the edge), each with a repository port + composition-based Doctrine adapter, and the `ProvisionOrganization` / `GrantMembership` use cases (`GrantMembership` resolves the single org itself). Deptrac layers were already registered by II-0.
- **Roles = aditivo/escalonado (Sergio's decision):** `Membership.roles` is authoritative-by-model; the bootstrap writes the same set to `User.roles`; `SecurityUser::getRoles()` / `PermissionVoter` are untouched (auth path unchanged — no-regression). Retiring `User.roles` + re-pointing auth at `Membership` remains the named follow-up (candidate II-3).
- **Bootstrap CLI — placement deviates from the story recommendation (flag for review):** `ProvisionOrganizationCommand` lives in `Organization/…/Cli` (local); `CreateInitialAdministratorCommand` lives in **`Iam/Identity/…/Cli`**, not `Organization/`. Rationale (argued improvement): the admin bootstrap needs to hash the password via Iam's *Infrastructure* `PasswordHasher` — placing the command in Iam keeps hashing + `CreateUser` local and reduces the cross-context surface to a single published seam (`GrantMembership`), avoiding an Organization→Iam *Infrastructure* import. Identity + ADMIN membership are created atomically (`wrapInTransaction`) so a failed grant leaves no orphan user. Command names: `organization:provision`, `organization:administrator:create`.
- **`membership.user_id` cross-context FK deliberately omitted** (deviates from AC7's "FKs → identity_user/organization"): only the intra-context `membership.organization_id → organization.id` FK is physical (schema-listener, mirrors bank↔bankaccount). A physical FK from Organization into Iam's `identity_user` is a Level-2 cross-context coupling the isolation rule discourages — `user_id` is an indexed id-only reference.
- **`organizationId` NOT added to `User`** (story open-question 1 recommendation, taken): the user↔org link is `Membership`; duplicating it on `User` is the same smell the roles decision avoids. SI-15's row-scoping seam for other aggregates stays deferred (NFR6).
- **`Role` vocabulary + seams:** `Membership` types roles with the Iam `Role` enum via per-file allowlist seams (no global `* =>` — the sync gate forbids a form deptrac can't mirror). 4 seams added to both `.bounded-context-allowlist` and deptrac `skip_violations`. Proper home for `Role` = the deferred RBAC-extraction follow-up.
- **`identity:user:create` retired** (invitation-first; only consumer was its own test): command renamed to `CreateInitialAdministratorCommand`, `CreateUserCommandTest` deleted. `CreateUser` app service kept (used by the bootstrap). Swept the durable docs that named it as bootstrap (`auth-rbac-subsystem.md`, `PRODUCTION_SECURITY_CHECKLIST.md`).
- **Fixtures:** seeded users `alice`/`mallory` now get a `Membership` in a seeded organization (no seeded user without a membership); `MembershipFixtureFactory` + `Organization.yaml` + `Membership.yaml`.
- **Boy-scout:** `docs/architecture-api.md` and `docs/source-tree-analysis.md` still showed `Backoffice/Identity` (II-0 moved it to `Iam/` but left the trees stale) — fixed the trees to `Iam/{Identity,Invitation,Session}` + added `Organization/{Organization,Membership}`.
- **Security review:** CLI-only surface, no new HTTP route; password hashed in Infra, never printed/logged (`SensitiveParameter`, hidden prompt); no credentials/PII in the migration; migration `down()` reversible + hard-delete default. No `pwa/` files touched.

### Change Log

- 2026-07-07 — II-1 implemented: `Organization` + `Membership` aggregates + bootstrap CLI (`organization:provision`, `organization:administrator:create`), `identity:user:create` retired, fixtures + docs updated. All gates green (php.quality EXIT 0; PHPUnit 1582 + Behat 210). Status → review.

### File List

**New — `Organization/` source (15):**
- `api/src/Organization/Organization/Domain/Entity/Organization.php`
- `api/src/Organization/Organization/Domain/Repository/OrganizationRepository.php`
- `api/src/Organization/Organization/Domain/Exception/OrganizationAlreadyProvisioned.php`
- `api/src/Organization/Organization/Application/ProvisionOrganization.php`
- `api/src/Organization/Organization/Infrastructure/Persistence/Doctrine/DoctrineOrganizationRepository.php`
- `api/src/Organization/Organization/Infrastructure/Cli/ProvisionOrganizationCommand.php`
- `api/src/Organization/Membership/Domain/Entity/Membership.php`
- `api/src/Organization/Membership/Domain/Repository/MembershipRepository.php`
- `api/src/Organization/Membership/Domain/Exception/OrganizationNotProvisioned.php`
- `api/src/Organization/Membership/Domain/Exception/UserAlreadyMember.php`
- `api/src/Organization/Membership/Application/GrantMembership.php`
- `api/src/Organization/Membership/Infrastructure/Persistence/Doctrine/DoctrineMembershipRepository.php`
- `api/src/Organization/Membership/Infrastructure/Persistence/Doctrine/MembershipOrganizationForeignKeySchemaListener.php`
- `api/migrations/2026/Version20260707141602.php`

**Renamed (retired `identity:user:create`):**
- `api/src/Iam/Identity/Infrastructure/Cli/CreateUserCommand.php` → `api/src/Iam/Identity/Infrastructure/Cli/CreateInitialAdministratorCommand.php`

**Deleted:**
- `api/tests/Functional/Iam/Identity/CreateUserCommandTest.php`

**New — tests + fixtures (12):**
- `api/tests/Unit/Organization/Organization/Domain/Entity/OrganizationTest.php`
- `api/tests/Unit/Organization/Organization/Application/{ProvisionOrganizationTest,InMemoryOrganizationRepository}.php`
- `api/tests/Unit/Organization/Membership/Domain/Entity/MembershipTest.php`
- `api/tests/Unit/Organization/Membership/Application/{GrantMembershipTest,InMemoryMembershipRepository}.php`
- `api/tests/Functional/Organization/Organization/DoctrineOrganizationRepositoryTest.php`
- `api/tests/Functional/Organization/Membership/DoctrineMembershipRepositoryTest.php`
- `api/tests/Functional/Organization/BootstrapCommandsTest.php`
- `api/tests/DataFixtures/MembershipFixtureFactory.php`
- `api/tests/DataFixtures/Fixtures/{Organization,Membership}.yaml`

**Modified — config:**
- `api/config/packages/doctrine.yaml` (Organization mapping), `api/.bounded-context-allowlist` (4 seams), `api/tools/deptrac/deptrac.yaml` (3 skip_violations), `api/config/reference.php` (auto-regenerated).

**Modified — docs:**
- `api/CLAUDE.md`, `docs/architecture-api.md`, `docs/source-tree-analysis.md`, `docs/adr/auth-rbac-subsystem.md`, `PRODUCTION_SECURITY_CHECKLIST.md`.

## Preguntas abiertas / decisiones a confirmar antes de (o al inicio de) dev

1. **`organizationId` en `User`** (§Decisiones 3). Recomendado **NO** añadirlo en II-1 (org vía `Membership`, evita duplicar el enlace — coherente con la decisión aditiva de roles). Tensión: SI-15 literal («todo agregado carga `organizationId`»). Si Sergio quiere el seam de scoping por fila también en `User` ahora, es una columna + backfill trivial (greenfield) — una edición acotada de esta historia.
2. **Hogar/seam del vocabulario `Role`** (§Decisiones 4). Recomendado **publicar `Role` como seam allowlisted** (transitorio, coherente con el parking RBAC). Alternativa: `Membership` guarda `list<string>` crudos. El hogar definitivo se pliega al follow-up de extracción del plano de autorización.
3. **Placement del CLI de bootstrap + retiro de `identity:user:create`** (gotcha 3, 5). Recomendado: comandos en `Organization/…/Infrastructure/Cli` orquestando el `CreateUser` publicado de `Iam`; **retirar** `identity:user:create`. Confirmar (org-infra vs iam-infra; retirar vs evolucionar) — verificar consumidores en behat/tests primero.
4. **Nombres de comando CLI.** `organization:provision` + `organization:create-admin` (o `identity:*`)? Elegir el namespace de comando coherente con `identity:user:create`.

## Review Findings (code review — 2026-07-07)

_Review adversarial de 3 capas (Blind Hunter · Edge Case Hunter · Acceptance Auditor). Sin BLOCKER/HIGH. **D=3** decision-needed · **P=1** patch · **W=1** defer · **R=3** dismissed. Diff: 39 ficheros, +1714/−231._

### decision-needed

- [x] [Review][Decision] Singleton «una org por instalación» = check-then-act no atómico sin respaldo en BD — `ProvisionOrganization::provision` hace `findTheOne()`-then-`save()` sin constraint de unicidad/single-row en `organization` (la migración solo crea `PRIMARY KEY (id)`). Dos `organization:provision` concurrentes (o cualquier futuro caller no-CLI) podrían insertar dos orgs; peor: `findTheOne()` = `findOneBy([])` sin `ORDER BY`, así que con >1 fila `GrantMembership` liga miembros a una org arbitraria y SI-15 se rompe en silencio. El *no-schema-constraint* es decisión documentada («el invariante se relaja sin migración cuando se abra tenancy»); lo no cubierto es el race. Riesgo práctico ≈ 0 (writer = CLI run-once); contraste: `membership.user_id` sí lleva UNIQUE en BD. Fuentes: blind+edge. [`api/src/Organization/Organization/Application/ProvisionOrganization.php:31`, `DoctrineOrganizationRepository.php:37`] — **RESUELTO 2026-07-07: aceptado as-is** (Sergio; CLI run-once, riesgo ≈0, no-constraint documentado). Sin cambio de código.
- [x] [Review][Decision] FK física `membership.user_id → identity_user` omitida vs. literal de AC7 («FKs indexadas hacia `identity_user`/`organization`») — solo `fk_membership_organization` es físico; `user_id` lleva UNIQUE index pero sin FK (aislamiento cross-context por id, patrón bank↔bankaccount). El dev lo marcó para sign-off. Coste residual: sin integridad referencial hacia `identity_user` (un `User` hard-deleted deja membership colgante; removal de miembros está fuera de alcance). Fuente: auditor. [`api/src/Organization/Membership/Infrastructure/Persistence/Doctrine/MembershipOrganizationForeignKeySchemaListener.php:29`] — **RESUELTO 2026-07-07: mantener sin FK** (Sergio; aislamiento by-id confirmado). AC7 aclarado en su bullet.
- [x] [Review][Decision] `CreateInitialAdministratorCommand` colocado en `Iam/…/Cli` en vez de `Organization/…/Cli` (recomendación del spec, gotcha 3 — pide «confirmar el placement en review»). Argumento del dev: en Iam mantiene hashing (`PasswordHasher`, Infra Iam) + `CreateUser` locales y reduce la superficie cross-context a un único seam Application publicado (`GrantMembership`), evitando un import Organization→Iam *Infrastructure*. Fuente: auditor. [`api/src/Iam/Identity/Infrastructure/Cli/CreateInitialAdministratorCommand.php`] — **RESUELTO 2026-07-07: mantener en `Iam/`** (Sergio; seam Application único confirmado).

### patch

- [x] [Review][Patch] `GrantMembership::grant()` valida el `userId` DESPUÉS del lookup en BD — `findByUserId($userId)` corre antes de que `Uuid::ensure()` (en `Membership::__construct`) dispare, así que un `userId` malformado/vacío llega a Postgres como error DBAL crudo en vez del `InvalidUuidException` (400 `invalid-uuid`) limpio que exige la convención de edge-guards del repo. Fix: `Uuid::ensure($userId)` al inicio de `grant()`, antes del lookup. Latente hoy (único caller pasa un UUID v7 recién acuñado), muerde cuando la ruta de invitación (II-4) reuse el seam. Fuentes: blind+edge. [`api/src/Organization/Membership/Application/GrantMembership.php:34`] — **APLICADO 2026-07-07**: `Uuid::ensure($userId)` al ingress + `@throws InvalidUuidException` en docblock + test `testRejectsAMalformedUserIdBeforeTouchingTheRepositories`. php.stan 0, php.quality EXIT 0, unit 4/4.

### defer

- [x] [Review][Defer] «Ningún User sin Membership» (AC5) se sostiene por convención (un único caller `CreateInitialAdministratorCommand`, atómico), no por construcción — `CreateUser` sigue siendo servicio Application autowired e invocable; nada estructural impide un futuro caller que cree un `User` sin `Membership`. Correcto para este slice (fixtures siembran memberships de alice/mallory). Fuente: auditor. [`api/src/Iam/Identity/Application/CreateUser.php`] — deferred to II-4 (la ruta de onboarding por invitación debe funnelear por `GrantMembership`)

### dismissed (considerados, sin acción)

- **`Membership::roles()` `Role::from` lanza `\ValueError` ante un valor persistido fuera del enum** — fail-fast defendible; todos los write-paths son enum-tipados, ningún valor inválido puede persistirse; tolerancia = especulativo (YAGNI). (blind)
- **`GrantMembership` no verifica existencia del `userId` (ref colgante)** — aislamiento cross-context by-design (refs-by-id, sin FK); confirmado intencional por edge+auditor. (blind+edge)
- **La org de fixtures no tiene membership ADMIN** — AC6 acota el invariante ≥1-ADMIN al flujo de bootstrap (testeado en `BootstrapCommandsTest`); las fixtures alice/mallory siembran deliberadamente los casos del auth-gate (mallory role-less = 403-vs-401). Intencional. (auditor)

## Review Findings — pasada independiente (2026-07-07 · segundo review)

_Review adversarial de 3 capas fresco (subagentes sin sesgo del dev), post-resolución del primer review. Diff: 40 ficheros, +1758/−231. **D=1** decision-needed · **P=4** patch · **W=1** defer · **R=4** dismissed. Cazó un consumidor del comando retirado que el primer review no vio._

### decision-needed

- [x] [Review][Decision] **AC10 roto/no verificado — la retirada de `identity:user:create` rompió el seeding del usuario E2E** — `make/pwa.mk:109` sigue sembrando el usuario E2E con `sf c='identity:user:create <email> <pass> --role AUDIT_READER'`. Este diff renombró el comando a `organization:administrator:create` **y** eliminó `--role`, así que la invocación apunta a un comando+opción inexistentes; el fallo se traga (`>/dev/null 2>&1 || echo`) y muerde downstream: `auth.setup.ts` hace login con ese usuario contra `/api` default-deny → en BD fresca (CI) el usuario no existe → login ≠204 → falla el proyecto `setup` de Playwright → toda la suite E2E cae. `make app.test` (→`ci.test`→`pwa.test`→`pwa.test.e2e`) lo cazaría, pero el dev solo corrió `php.test` (AC10 «`app.test` verde» quedó sin verificar Y regresado). El comando nuevo hardcodea `Role::ADMIN` y exige la org ya provisionada → **no es un rename drop-in**. Verificado: único consumidor en código/make; ningún spec E2E depende del rol AUDIT_READER (el test `403` es la página estática `/unauthorized`; solo las rutas Audit llevan `#[IsGranted('ROLE_AUDIT_READER')]` y ningún spec las toca). Fuente: auditor (blind lo tocó de refilón). [`make/pwa.mk:109`, `api/src/Iam/Identity/Infrastructure/Cli/CreateInitialAdministratorCommand.php:36`] — **RESUELTO 2026-07-07 (Opción 1, decisión de Sergio): usuario E2E → ADMIN vía comandos shipped.** `make/pwa.mk` siembra ahora `organization:provision $(E2E_ORG_NAME)` (nombre sin espacios, evita quoting frágil) + `organization:administrator:create $(E2E_USER_EMAIL) $(E2E_USER_PASSWORD)`; comentario y `E2E_ORG_NAME` añadidos. **Verificado E2E end-to-end sobre BD fresca:** provision `[OK]` + admin-create `[OK] ... with an ADMIN membership` + login `POST /api/v1/backoffice/login` → **HTTP 204** (auth.setup pasaría).

### patch

- [x] [Review][Patch] **ADR durable stale — barrido T6 incompleto** — `docs/adr/identity-invitation-lifecycle.md:7` sigue nombrando `identity:user:create` como estado-actual («CLI-only user creation (`identity:user:create`)»); T6 lo listó explícitamente para el sweep. Fix: apuntarlo al comando nuevo / notar la retirada (espejo de `auth-rbac-subsystem.md:65`, ya actualizado). [`docs/adr/identity-invitation-lifecycle.md:7`]
- [x] [Review][Patch] **Docs de estructura/CLI no landeadas (T9 marcado hecho, incompleto)** — `docs/claude-code-quickref.md` y `docs/development-guide-api.md` no mencionan el contexto `Organization/` ni los comandos `organization:provision` / `organization:administrator:create`; la regla «Keeping docs up to date» los exige para dir/comando nuevos. Fix: añadido mínimo. [`docs/claude-code-quickref.md`, `docs/development-guide-api.md`]
- [x] [Review][Patch] **Invariante «nombre con contenido» vive en el adapter CLI, no en el agregado** — `#[Assert\NotBlank]` de `Organization::$name` no trimea (sin normalizer), así que `provision("   ")` (whitespace) pasa y `provision("  ACME  ")` persiste con padding; el único guard real es el `trim` en `ProvisionOrganizationCommand`, que además pasa el nombre **sin** trimear al servicio. Un futuro caller no-CLI del servicio autowired lo salta. Fix (mejora argumentada, invariante-en-dominio): normalizar/trim en `Organization::provision()`. [`api/src/Organization/Organization/Domain/Entity/Organization.php:81`, `ProvisionOrganizationCommand.php:52`]

### defer

- [x] [Review][Defer] **Grant duplicado concurrente → `UniqueConstraintViolationException` crudo en vez de `UserAlreadyMember`** [`api/src/Organization/Membership/Application/GrantMembership.php:42`] — `findByUserId()`-then-`save()` no atómico; el UNIQUE index `membership(user_id)` **sí** sostiene el invariante (sin corrupción), solo degrada el contrato de error. Latente hoy (único caller CLI run-once con UUID recién acuñado); muerde cuando la ruta de invitación (II-4) reuse el seam bajo concurrencia. Misma clase TOCTOU que el race del singleton ya aceptado. Fuentes: blind+edge — **diferido a II-4** (onboarding por invitación endurece el seam).

### dismissed (segundo pase — considerados, sin acción)

- **Singleton `findTheOne()` check-then-act sin constraint en BD** (blind MEDIUM + auditor F5) — **ya ratificado «aceptado as-is» por Sergio** en el primer review (CLI run-once, riesgo ≈0, no-constraint documentado). Sin cambio.
- **Ventana de bootstrap en dos transacciones** (org provisionada, `administrator:create` falla → org con 0 ADMIN) (blind) — trade-off de ergonomía de un bootstrap de dos comandos, recuperable re-ejecutando; «≥1 ADMIN desde el arranque» (AC6) se satisface cuando ambos comandos tienen éxito. Aceptado.
- **«Ningún User sin Membership» por convención, no construcción** (auditor F4) — ya diferido a II-4 en el primer review.
- **`reference.php:415` translator `enabled` `true→false`** (blind) — ruido de regeneración auto-generado, **idéntico en `main` y worktree**, ajeno a Organization; benigno ([[reference-php-commit-ok-when-autoregenerated]]).
- **`InMemoryMembershipRepository::$removeCalled` escrito sin assert** (auditor F6, inicialmente clasificado patch) — **reclasificado dismiss al aplicar**: es la **convención establecida** de todos los in-memory repos hermanos (`InMemoryBankRepository`/`InMemoryBankAccountRepository`/`InMemoryUserRepository` exponen `removeCalled` para que su deleter-test lo assertee); `MembershipRepository::remove()` lo pide T3 (simetría de ciclo de vida) y el flag espera el use-case de removal de miembros (slice diferido). Mantener el patrón (consistencia > "corrección" del fake).

## Review Findings — pasada independiente #3 (2026-07-08 · tercer review)

_Review adversarial de 3 capas fresco (Blind Hunter · Edge Case Hunter · Acceptance Auditor), foco en el delta post-`c4f99620` (cobertura de tests + fix de seeding E2E `make/pwa.mk`) — la zona con menos escrutinio de los dos reviews previos. **CI de la PR verde**, incl. `PWA (E2E) [shard 1/1]` SUCCESS → AC10 no-regresión evidenciada por el gate autoritativo (cierra el gap de evidencia del 2º review). **D=1** decision-needed · **P=4** patch · **W=3** defer (2 ya en `deferred-work.md`) · **R=8** dismissed. Sin BLOCKER/HIGH nuevo no-adjudicado._

### decision-needed

- [x] [Review][Decision] **Usuario E2E escalado a ADMIN; ya no queda path de seeding no-admin.** Retirar `identity:user:create --role` dejó `organization:administrator:create` (que hardcodea `Role::ADMIN`) como único CLI shipped que crea usuarios. El usuario E2E compartido pasó de `AUDIT_READER` → `ADMIN` (`make/pwa.mk:113`). Funciona hoy (CI E2E verde; ningún spec depende del rol menor — el `403` es la página estática `/unauthorized` y ningún spec toca rutas `#[IsGranted('ROLE_AUDIT_READER')]`), pero cualquier E2E futuro de autorización-negativa (una ruta que deba dar 403 a un no-admin) no se puede sembrar y pasaría en silencio bajo ADMIN. Fuentes: blind+auditor. [`make/pwa.mk:113`, `CreateInitialAdministratorCommand.php:36`] — **RESUELTO 2026-07-08 (Sergio): aceptado as-is.** El reset limpio de E2E es el escenario B (truncar el grafo org+membership+user juntas / recargar fixtures) — no borrar solo el user (dejaría membership huérfana, W2). Borrado/re-rol/granularidad de miembros = **slice de gestión de miembros (J5)**, ahora rastreado en **issue #462**.

### patch

- [x] [Review][Patch] **`make/pwa.mk` enmascara un fallo real de `organization:provision`.** Si `provision` falla (tragado por `|| true`, L112), `administrator:create` falla luego con `OrganizationNotProvisioned` y el `|| echo` lo reporta como «user likely already exists (fine)». Un fallo genuino se anuncia como benigno → traza de debug engañosa. Fuente: blind. [`make/pwa.mk:112-115`] — **APLICADO 2026-07-08**: mensaje reescrito para no afirmar «fine» y apuntar el debug al fallo de login downstream como señal real.
- [x] [Review][Patch] **`resolvePassword` deja escapar `MissingInputException` en EOF interactivo.** `execute()` llama `resolvePassword()` **fuera** del `try/catch` (que solo envuelve `createAndReport`); `askHidden` ante Ctrl+D lanza `MissingInputException` (RuntimeException) → traza cruda en vez del `Command::INVALID` limpio previsto para «sin password». Fuente: edge. [`CreateInitialAdministratorCommand.php:76,95`] — **APLICADO 2026-07-08**: `try/catch (RuntimeException)` en `resolvePassword` → `''` → `INVALID`. Se captura `RuntimeException` (ya importada), no `MissingInputException`, para no subir el `CouplingBetweenObjects` de PHPMD (12→13); `MissingInputException` deriva de `\RuntimeException`, así que se cubre igual (+ el error «unable to hide»).
- [x] [Review][Patch] **`docs/claude-code-quickref.md` no lista los comandos CLI nuevos.** La regla «Keeping docs up to date» exige quickref para comandos nuevos; `development-guide-api.md` sí los tiene, quickref no menciona ninguno (el parche del 2º review afirmó tocarlo pero quickref **no está en el diff**). Fuente: auditor. [`docs/claude-code-quickref.md`] — **APLICADO 2026-07-08**: añadidos `organization:provision` / `organization:administrator:create` al catálogo `### API / PHP` (vía `make sf c='…'`).
- [x] [Review][Patch] **`BootstrapCommandsTest` — `RESTART IDENTITY` no-op + `CASCADE` innecesariamente amplio.** El patrón commit-then-truncate es **correcto y necesario** aquí (el comando comitea vía `wrapInTransaction`, así que no cabe transacción-con-rollback; **no** es regresión vs el test no-committing borrado). Pero `RESTART IDENTITY` es no-op sobre PKs UUID, y `CASCADE` excede las tres tablas ya listadas explícitamente. Fuente: blind. [`BootstrapCommandsTest.php:37`] — **APLICADO 2026-07-08**: `TRUNCATE membership, organization, identity_user` (verificado: la única FK a esas tablas es `fk_membership_organization` y ambas están en la lista → sin `CASCADE`; test 5/5 verde).

### defer

- [x] [Review][Defer] **Grant duplicado concurrente → `UniqueConstraintViolationException` crudo** [`GrantMembership.php:42`] — **ya registrado** en `deferred-work.md` (II-4). TOCTOU no atómico; el UNIQUE `membership(user_id)` sostiene el invariante, solo degrada el contrato de error. Fuentes: blind+edge.
- [x] [Review][Defer] **`membership.user_id` sin FK → orphan al hard-delete del `User` rompe silenciosamente ≥1 ADMIN** [`Version20260707141602.php:22`] — nuevo; añadido a `deferred-work.md`. Aislamiento cross-context by-id (aceptado); la integridad inversa (membership-sin-user) pertenece al slice diferido de member-lifecycle. Fuentes: blind+edge.
- [x] [Review][Defer] **«Ningún User sin Membership» por convención, no construcción** [`CreateUser.php`] — **ya registrado** en `deferred-work.md` (II-4). Fuente: auditor.

### dismissed (tercer pase — considerados, sin acción)

- **A3 `PermissionVoter.php` "editado" (docblock-only)** — **FALSO POSITIVO**: no está en el diff de la PR (`a952b5d5...d8b91cd1`). El auditor comparó con `git diff main` contra el `main` actual (`70c52f89`) en vez del merge-base → capturó un delta main-vs-rama ajeno a la PR. (auditor)
- **`Membership::roles()` `Role::from` → `\ValueError` con valor fuera de enum** — ya dismisseado en el 1er review (fail-fast defendible; todos los write-paths son enum-tipados; tolerancia = YAGNI). (edge)
- **`GrantMembership` sin `Validator::ensure` (contradice T4 del spec)** — no-op hoy (`Membership` no lleva `#[Assert]`); la asimetría con `ProvisionOrganization` refleja que solo `Organization` tiene constraints. Añadirlo ahora = especulativo (YAGNI). (blind)
- **`assertActiveAdminExists` asserta rol, nunca "activo"** — `IdentityStatus` no existe hasta II-3; el test refleja correctamente el slice (AC6 acota «activo» al arranque, documentado). (blind)
- **Mensaje de éxito imprime `$email` crudo, no el canónico persistido** — cosmético (posible drift de casing en la salida del operador). (blind)
- **Args de seed E2E rompen con espacios/comillas en override de `E2E_ORG_NAME`/`E2E_USER_PASSWORD`** — defaults seguros (`E2E-Test-Organization`, sin espacios); override es responsabilidad del operador. (edge)
- **AC10 «`make app.test` (suite E2E) sin evidenciar verde»** — **RESUELTO**: el job `PWA (E2E)` de CI está en SUCCESS; CI es el gate autoritativo. (auditor)
- **Singleton `findTheOne()` check-then-act + `findOneBy([])` no determinista** (edge lo elevó a HIGH) — el race **ya está ratificado «aceptado as-is» por Sergio** (1er review; writer = CLI run-once, riesgo ≈0, no-constraint documentado en la entidad). La no-determinación del `findOneBy([])` solo aplica **tras** haberse roto ya el invariante de una-org → un `ORDER BY` sería especulativo bajo el invariante aceptado (YAGNI). (blind+edge)
