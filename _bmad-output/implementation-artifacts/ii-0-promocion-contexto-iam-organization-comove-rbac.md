---
baseline_commit: edd69e44
---

# Story II-0 (PR-0): Promoción de contexto `Iam/` + `Organization/` con parking del core RBAC (estructural, sin comportamiento)

Status: ready-for-dev

Epic `identity-invitation-lifecycle` · **primera historia en orden de merge safe-first** (`II-0 → II-1·II-2·II-3·II-7 → II-6 → II-4 → II-5 → II-8`). Slice: **movimiento estructural puro** (move/rename), **cero cambio de comportamiento**. Desbloquea todas las demás.

> **⚠️ Baseline = `main` (`edd69e44`), NO la rama del PR #455.** El core RBAC que esta historia co-mueve (`PermissionVoter`, `AuthorizationPolicy`, `StaticAuthorizationPolicy`, `Permission`) entró por **#456 a `main`** y **no existe** en el worktree `docs/iam-identity-invitation-bvdn` (branqueó antes). La **implementación** debe hacerse en un worktree/rama `feat/` **sobre `main` actual** (con autorización de branch de Sergio), no sobre #455. #455 lleva solo la planificación (ADR/épica/sprint/este story file).

## Story

Como plataforma de ERPify,
quiero promover el subsistema de identidad a contextos top-level `Iam/{Identity,Invitation,Session}` + `Organization/`, moviendo con él el core RBAC como **parking temporal** (consecuencia física del rename, no topología definitiva),
para que las capacidades IAM emergentes (invitación, reset, lockout, sesiones) tengan su hogar de dominio sin acoplarse a un área de negocio — **sin cambiar todavía ningún comportamiento observable**.

## Acceptance Criteria

1. **Identity promovido a `Iam/Identity`.** Los 23 ficheros de `api/src/Backoffice/Identity/` (Domain/Application/Infrastructure, incluido TODO `Infrastructure/Security`) viven bajo `api/src/Iam/Identity/` con namespace `Erpify\Iam\Identity\…`; `api/src/Backoffice/Identity/` **deja de existir**. *(FR1, ADR D1)*

2. **Core RBAC co-movido — *parking temporal* (mover ≠ terminar).** `Permission`, `InvalidPermission`, `AuthorizationPolicy`, `StaticAuthorizationPolicy` y `PermissionVoter` viven en `Iam/Identity/Infrastructure/Security` con su **comportamiento intacto**: `PermissionVoterAccessDecisionTest` (VIEWER → `bank.write`=deny, `bank.read`=grant) sigue verde y ninguna ruta cambia su decisión de autorización respecto de RM-1. **La ubicación es *parking*** —consecuencia física del `git mv` del módulo, no «RBAC pertenece a Identity» (es su plano ortogonal)— y queda registrado el **follow-up del ADR RBAC:** extraer el plano de autorización a su hogar propio (`Access/` / `Kernel/Authorization`) más adelante, sin decidirlo aquí. *(FR1, RM-1, secuenciación (b)-parking + follow-up)*

3. **Esqueletos hermanos + contexto `Organization/` creados, sin lógica.** Existen `Iam/Invitation`, `Iam/Session` y `Organization/` como esqueletos **deptrac-legales sin lógica** (habilitan II-1/II-4/II-7 sin adelantarlos). `Membership` **NO** se crea aquí — nace nuevo en `Organization/` en II-1 (ADR D1: el paraguas `Iam/` que absorbía Organization se descartó). *(ADR D1)*

4. **`security.yaml` reapuntado, firewall idéntico.** Las 4 referencias FQCN (`SecurityUser` en `password_hashers` y `when@test`, `UserProvider`, `ProblemDetailsAuthenticationFailureHandler`) apuntan a `Erpify\Iam\Identity\Infrastructure\Security\…`; el login autentica idéntico. *(no-regresión)*

5. **Ruta de login preservada exactamente.** Tras mover `LoginController` fuera de `src/Backoffice/`, la **URL `/api/v1/backoffice/login`** y el **nombre de ruta `identity_login`** (referenciado por `firewalls.main.json_login.check_path` y por `access_control`) se conservan **sin cambio**; `routes.yaml` descubre el controller reubicado. *(no-regresión — gotcha routes.yaml, §gotcha 1)*

6. **Doctrine: misma tabla, mapping nuevo, diff vacío.** `User` sigue mapeado a la tabla **`identity_user`** (nombre hardcodeado en `#[ORM\Table]`, sin cambio → **sin migración de datos**); se añade el mapping `Iam` (dir `src/Iam`, prefix `Erpify\Iam`) en `doctrine.yaml` (necesario porque `auto_mapping: false`). `make db.diff` produce un **diff vacío**. *(no-regresión — gotcha doctrine, §gotcha 2)*

7. **deptrac reregistrado.** El bloque de capas `Backoffice.Identity.*` (L81–87) y sus 3 reglas (L169, L202–208, L277–291) se reemplazan por `Iam.Identity.*` espejando el ruleset exacto; se añaden capas + reglas para `Iam.Invitation.*`, `Iam.Session.*`, `Organization.Organization.*`, `Organization.Membership.*` (cada una: 3 capas; Domain reusa el anchor `*domain`). `make php.deptrac` + `make php.lint.bounded-context` verdes. *(NFR7)*

8. **Tests movidos y externos reapuntados.** Los 21 ficheros bajo `tests/**/Backoffice/Identity/` se mueven al espejo `tests/**/Iam/Identity/` (namespace + `use` nuevos); los 4 ficheros de test externos (`AuthenticatesFunctionalRequests.php`, `Behat/Context/SecurityContext.php`, `DataFixtures/UserFixtureFactory.php`, `DataFixtures/Fixtures/User.yaml`) reapuntan a `Erpify\Iam\Identity\…`. *(no-regresión)*

9. **Invariante rector — NO-REGRESIÓN.** `make app.test` y `make app.quality` pasan **idénticos** pre y post promoción (mismo conjunto de tests verdes, mismos gates: deptrac, bounded-context, error-contract, stan, cs-fixer, phpmd, rector, psalm-taint). El comportamiento HTTP observable es idéntico: login `/api/v1/backoffice/login`, distinción 401-vs-403, CLI `identity:user:create`, y la decisión del `PermissionVoter`. Referencias cross-module por id, **sin** `#[ORM\ManyToOne]` a entidad de otro módulo. *(NFR8, invariante rector)*

## Tasks / Subtasks

- [ ] **T1 · Rama base sobre `main` actual** (AC: 1, 9)
  - [ ] Con autorización de Sergio: crear worktree/rama `feat/<iam>-promote-identity-context` con **BASE=main** (`edd69e44`) — NO sobre `docs/iam-identity-invitation-bvdn`. Confirmar que `api/src/Backoffice/Identity/Infrastructure/Security/PermissionVoter.php` existe en la rama (prueba de que #456 está presente).
  - [ ] `make app.dev` en el nuevo worktree; capturar el estado verde de baseline: `make app.test` + `make app.quality` (guardar salida para el diff de no-regresión de T9).

- [ ] **T2 · Mover `Backoffice/Identity` → `Iam/Identity`** (AC: 1, 2)
  - [ ] `git mv api/src/Backoffice/Identity api/src/Iam/Identity` (preserva historia; mueve los 23 ficheros incl. `Infrastructure/Security/*` = core RBAC de #456 en bloque).
  - [ ] Renombrar el namespace en los 23 ficheros: `Erpify\Backoffice\Identity\` → `Erpify\Iam\Identity\` (declaración `namespace` + `use` internos).
  - [ ] Verificar que NO queda ninguna referencia a `Erpify\Backoffice\Identity` dentro del módulo movido.

- [ ] **T3 · Esqueletos hermanos + `Organization/`** (AC: 3)
  - [ ] Crear directorios `api/src/Iam/Invitation/`, `api/src/Iam/Session/`, `api/src/Organization/` como esqueletos (sin clases de lógica; solo la estructura mínima que deptrac/PSR-4 aceptan). **No** crear `Membership` ni `Organization` entidades (van en II-1).

- [ ] **T4 · `security.yaml` — 4 FQCN** (AC: 4)
  - [ ] `api/config/packages/security.yaml`: L5 `password_hashers` (`SecurityUser`), L10 `providers.identity_user_provider.id` (`UserProvider`), L23 `json_login.failure_handler` (`ProblemDetailsAuthenticationFailureHandler`), L50 `when@test.password_hashers` (`SecurityUser`) → `Erpify\Iam\Identity\Infrastructure\Security\…`. **No** tocar `provider: identity_user_provider` (nombre lógico) ni `check_path: identity_login` (nombre de ruta, preservado en T5).

- [ ] **T5 · `routes.yaml` — preservar login** (AC: 5)
  - [ ] `api/config/routes.yaml`: registrar el descubrimiento de rutas del nuevo árbol `Iam/` de modo que `LoginController` reubicado siga sirviendo **URL `/api/v1/backoffice/login`** con **nombre `identity_login`**. Opciones (elegir la mínima que preserve exactamente URL+nombre): (a) `resource: ../src/Iam/` con `prefix: /api/v1/backoffice`; (b) ruta absoluta explícita en el `#[Route]` del controller. Verificar con `make sf c='debug:router identity_login'` → misma path/methods que en baseline.

- [ ] **T6 · `doctrine.yaml` — mapping `Iam`, diff vacío** (AC: 6)
  - [ ] `api/config/packages/doctrine.yaml`: añadir mapping `Iam` (`type: attribute`, `is_bundle: false`, `dir: '%kernel.project_dir%/src/Iam'`, `prefix: 'Erpify\Iam'`, `alias: Iam`) — espejo de los mappings `SharedMedia`/`SharedStorage`. (El mapping `Organization` se añade en II-1, cuando existan sus entidades.)
  - [ ] `make db.diff` → **diff vacío** (la tabla sigue `identity_user`). Si genera algo, investigar antes de continuar. **No** editar la migración histórica `Version20260702092603.php`.

- [ ] **T7 · `deptrac.yaml` — reregistro** (AC: 7)
  - [ ] `api/tools/deptrac/deptrac.yaml`: reemplazar el bloque `layers` `Backoffice.Identity.{Domain,Application,Infrastructure}` (L81–87) por `Iam.Identity.{…}` (colectores `src/Iam/Identity/<Layer>/.*`); actualizar las 3 reglas (L169 Domain=`*domain`; L202–208 Application; L277–291 Infrastructure) al prefijo `Iam.Identity.*`.
  - [ ] Añadir capas + reglas para `Iam.Invitation.*`, `Iam.Session.*`, `Organization.Organization.*`, `Organization.Membership.*` (mismo shape: Domain=`*domain`, Application y Infrastructure espejando el ruleset de Identity). `Shared.*` auto-folda, no requiere registro.
  - [ ] `make php.deptrac` + `make php.lint.bounded-context` verdes. **No** tocar `deptrac.baseline.yaml` (generado, 0 refs a Identity) ni `.bounded-context-allowlist` (0 refs a Identity).

- [ ] **T8 · Tests — mover 21 + reapuntar 4 externos** (AC: 8)
  - [ ] `git mv api/tests/Unit/Backoffice/Identity api/tests/Unit/Iam/Identity` y `git mv api/tests/Functional/Backoffice/Identity api/tests/Functional/Iam/Identity`; renombrar `namespace Erpify\Tests\…\Backoffice\Identity` → `…\Iam\Identity` + `use` en los 21 ficheros (incl. helpers `InMemoryUserRepository`, `UserMother`, `Fixtures/RecordingAuthorizationPolicy`).
  - [ ] Reapuntar los 4 externos: `api/tests/Functional/AuthenticatesFunctionalRequests.php` (4 use + `new SecurityUser`), `api/tests/Behat/Context/SecurityContext.php` (4 use), `api/tests/DataFixtures/UserFixtureFactory.php` (3 use), `api/tests/DataFixtures/Fixtures/User.yaml` (L1 clave FQCN del fixture Alice).

- [ ] **T9 · Verificación de no-regresión** (AC: 9)
  - [ ] `git grep -n 'Erpify\\Backoffice\\Identity'` y `git grep -n 'Backoffice/Identity'` en `api/src api/config api/tests` → **0 coincidencias** (excluyendo `var/cache`).
  - [ ] `make sf.cc`; `make app.test` + `make app.quality` → **idénticos** al baseline de T1 (mismo set verde). Diff explícito de la lista de tests/gates pre vs post.
  - [ ] Smoke HTTP: login `/api/v1/backoffice/login` (204 + cookie), ruta gateada anónima → 401, CLI `identity:user:create` funcional.

## Dev Notes

### Contrato de diseño (fuente de verdad)

- ADR `docs/adr/identity-invitation-lifecycle.md` — **D1** (promoción `Iam/`+`Organization/`; el trigger de promoción del auth-rbac-D2 disparó), **D2** (multi-tenant-ready; una org/instalación). El *Load-bearing challenge* «Promotion churn» de la §Implementation aplica directo aquí.
- Épica `_bmad-output/planning-artifacts/epics-identity-invitation-lifecycle.md` — historia **II-0** (comportamiento/consume/establece + AC), **Additional Requirements → secuenciación RBAC opción (b)**, riesgo **R4** (promotion churn + coordinación RBAC).
- Addendum `_bmad-output/planning-artifacts/arch-addendum-identity-invitation.md` — tabla PR-0 + **§Riesgo de secuenciación con RBAC**.

### Estado actual del código (leído sobre `main edd69e44` — no reinventar)

- **23 ficheros** en `api/src/Backoffice/Identity/`. **Pre-#456** (identidad/seguridad): `Domain/{Entity/User, Email, HashedPassword, Enum/Role, Repository/UserRepository, Exception/InvalidEmail, Exception/InvalidHashedPassword}`, `Application/CreateUser`, `Infrastructure/{Cli/CreateUserCommand, Http/LoginController, Http/LoginOriginListener, Persistence/Doctrine/DoctrineUserRepository, Security/{SecurityUser, UserProvider, PasswordHasher, ProblemDetailsAuthenticationFailureHandler, UnauthenticatedAccessListener, SecurityActorContextFactory}}`. **Core RBAC #456** (en `Infrastructure/Security/`): `Permission`, `InvalidPermission`, `AuthorizationPolicy` (puerto), `StaticAuthorizationPolicy` (impl declarativa; `const TIER_VERBS/EXPLICIT_GRANTS/TIER_OPT_OUT`), `PermissionVoter`.
- **Doble hogar de los tiers de Role**: el enum `Domain/Enum/Role.php` (vocabulario `VIEWER<EDITOR<MANAGER<ADMIN`, `AUDIT_READER`; `->value` sin `ROLE_`) **y** el mapa `TIER_VERBS` en `StaticAuthorizationPolicy` (la escala como datos). Ambos se mueven juntos (están en el módulo).
- **`SecurityUser` es el ÚNICO** sitio que añade el prefijo `ROLE_` (en `getRoles()`); el strip lo hace `PermissionVoter` en el borde. No alterar.
- **`SecurityActorContextFactory`** implementa un puerto de `Shared/Audit` (`ActorContextFactory`) — es el actor de **auditoría**, NO autorización; importa de `Shared/`, seguirá siendo deptrac-legal bajo `Iam.Identity.Infrastructure` (que permite `Shared.*`).
- **Tabla `identity_user`** con `email` UNIQUE — nombre hardcodeado en `#[ORM\Table]`, independiente del namespace.
- **services*.yaml**: 0 referencias a Identity (todo autowiring/autoconfig; `PermissionVoter` = `security.voter` autoconfigurado). **`.bounded-context-allowlist`**: 0 refs a Identity (Identity no publica seams). No tocar ninguno.

### Artefactos a crear/tocar (rutas exactas)

| Fichero | Qué |
|---|---|
| `api/src/Iam/Identity/**` (23) | **MOVER** desde `Backoffice/Identity/**` (git mv) + rename namespace |
| `api/src/Iam/Invitation/`, `api/src/Iam/Session/`, `api/src/Organization/` | **NUEVO** esqueleto vacío (sin lógica) |
| `api/config/packages/security.yaml` | **EDITAR** 4 FQCN (L5, L10, L23, L50) |
| `api/config/routes.yaml` | **EDITAR** descubrir `Iam/` preservando URL `/api/v1/backoffice/login` + nombre `identity_login` |
| `api/config/packages/doctrine.yaml` | **EDITAR** añadir mapping `Iam` (dir `src/Iam`, prefix `Erpify\Iam`) |
| `api/tools/deptrac/deptrac.yaml` | **EDITAR** `Backoffice.Identity.*` → `Iam.Identity.*` + capas/reglas de `Iam.Invitation/Session`, `Organization.Organization/Membership` |
| `api/tests/{Unit,Functional}/Iam/Identity/**` (21) | **MOVER** desde `…/Backoffice/Identity/**` + rename namespace/use |
| `api/tests/Functional/AuthenticatesFunctionalRequests.php`, `api/tests/Behat/Context/SecurityContext.php`, `api/tests/DataFixtures/UserFixtureFactory.php`, `api/tests/DataFixtures/Fixtures/User.yaml` | **EDITAR** FQCN externos (4/4/3/1) |

### Decisiones de diseño y gotchas críticos

1. **routes.yaml — el login desaparece si no se actúa.** Las rutas por atributo se descubren por `resource: ../src/Backoffice/` con `prefix: /api/v1/backoffice`. Al mover `LoginController` a `src/Iam/`, deja de descubrirse. **Invariante:** preservar URL `/api/v1/backoffice/login` + nombre `identity_login` exactos (los usan `check_path`, `access_control ^/api/v1/backoffice/login$` y el PWA). Elegir el mecanismo mínimo (nuevo `resource: ../src/Iam/` con prefijo, o ruta absoluta en el controller). Verificar con `debug:router`.
2. **doctrine.yaml — entidad huérfana si no se mapea.** `auto_mapping: false` + el mapping `Backoffice` (dir `src/Backoffice`, prefix `Erpify\Backoffice`) **no** cubre `src/Iam`. Sin un mapping `Iam` nuevo, `User` (ahora `Erpify\Iam\Identity\Domain\Entity\User`) no se mapea → 500/errores. Espejar `SharedMedia`/`SharedStorage`. La tabla NO cambia (`identity_user`) → **db.diff vacío**, sin migración.
3. **Baseline off `main`, no #455.** Repetido por su criticidad: #456 (RBAC core) está en `main` y no en bvdn. Branquear off `main`.
4. **Co-move es en bloque.** El core RBAC vive DENTRO de `Backoffice/Identity/Infrastructure/Security`, así que `git mv` del módulo lo arrastra — no hay un paso separado de «mover RBAC». El trabajo es rename de namespace, no reubicación fina.
5. **`Membership` NO se toca aquí** (ADR D1). `Organization/` se crea como esqueleto; sus agregados nacen en II-1. No añadir el mapping doctrine `Organization` hasta que existan entidades (un mapping a un dir sin entidades puede romper).
6. **Ninguna capa nueva para `Infrastructure/Security`.** El core RBAC no tiene capa deptrac propia (RM-1 AC8); queda cubierto por `Iam.Identity.Infrastructure`.
7. **Sin cambios de comportamiento = sin tocar lógica.** Prohibido «mejorar» de paso: es rename + rewire. Cualquier refactor va fuera de esta historia.

### Fuera de alcance (NO hacer)

- ❌ Crear los agregados `Organization`/`Membership` (II-1), `Invitation` (II-4), `Session` (II-7), `Shared/Token` (II-2).
- ❌ Cambiar el comportamiento de auth/RBAC (decisiones del voter, prefijo `ROLE_`, timing del provider).
- ❌ Cambiar la URL o el nombre de la ruta de login, o el nombre de la tabla `identity_user`.
- ❌ Editar `deptrac.baseline.yaml`, `.bounded-context-allowlist`, `services*.yaml`, o la migración histórica.
- ❌ Refactors de oportunidad («boy scout») en los ficheros movidos — esta historia es un move puro y auditable.

### Testing (obligatorio; convenciones del repo)

- **Regresión (el corazón de esta historia):** `make app.test` + `make app.quality` verdes **idénticos** pre/post (capturar baseline en T1). Los 21 tests de Identity movidos deben pasar sin cambios de aserción (solo namespace).
- **Gates de arquitectura:** `make php.deptrac`, `make php.lint.bounded-context`, `make php.lint.error-contract` verdes.
- **DB:** `make db.diff` = vacío.
- **Rutas:** `make sf c='debug:router identity_login'` = misma path/methods que baseline.
- **Grep de higiene:** `git grep -n 'Backoffice\\Identity' api/src api/config api/tests` = 0.
- Recordatorio PHPMD/cs-fixer solo los caza `make php.quality` (no hay baseline PHPMD); el worker FrankenPHP puede segfaultar en `php.stan` → usar `PHP_SERVICE=messenger_worker` si aparece exit 139.

### Project Structure Notes

- **Plan de rama (requiere OK de Sergio):** una rama `feat/iam-promote-identity-context` (scope = nuevo bounded context `Iam`) **off `main`**, worktree propio. La planificación (este story, épica, sprint) vive en `docs/iam-identity-invitation-bvdn` (#455); la implementación es una rama distinta — confirmar topología con Sergio antes de crear (regla dura de branch).
- Espejo de estructura: `api/src/Iam/Identity/{Domain,Application,Infrastructure}` y `api/tests/{Unit,Functional}/Iam/Identity/**` replican 1:1 la estructura de `Backoffice/Identity`.
- Convención deptrac de capa: `<Context>.<Module>.<Layer>` (`Iam.Identity.Domain`, …).

### References

- `docs/adr/identity-invitation-lifecycle.md` (D1, D2, §Load-bearing challenges «Promotion churn»).
- `_bmad-output/planning-artifacts/epics-identity-invitation-lifecycle.md` (II-0, secuenciación RBAC opción b, R4).
- `_bmad-output/planning-artifacts/arch-addendum-identity-invitation.md` (PR-0, §Riesgo de secuenciación con RBAC).
- `_bmad-output/planning-artifacts/rm-1-nucleo-autorizacion-voter-puerto-politica.md` (core RBAC movido, AC8 = sin capa deptrac propia para Security).
- Código en vigor (main): `api/src/Backoffice/Identity/**`, `api/config/packages/{security,doctrine}.yaml`, `api/config/routes.yaml`, `api/tools/deptrac/deptrac.yaml`, `api/migrations/2026/Version20260702092603.php`.

### Previous Story Intelligence

- **RM-1 (#456, merged)** implementó el core RBAC en `Backoffice/Identity/Infrastructure/Security` con contratos neutrales (puerto `AuthorizationPolicy` sin `User`/`Role`/`SecurityUser`) — pensado para promoción futura. II-0 ejecuta esa promoción. La neutralidad del puerto hace el co-move un rename, no un rediseño.
- La fundación auth (auth-foundation, Epic 3) fijó `SecurityUser`/provider/authenticator/`LoginOriginListener`/`UnauthenticatedAccessListener` — todos se mueven en bloque; su comportamiento (401-vs-403, Origin-check, timing) es parte del invariante de no-regresión.

### Git Intelligence

- Usar `git mv` (no delete+create) para preservar historia y facilitar el review del rename.
- Baseline `edd69e44` (`feat(backoffice): add RBAC permission voter, policy port and role tiers (#456)`).

### Project Context Reference

- `docs/project-context.md` — layout `Backoffice|Frontoffice|Shared` + reglas hexagonales; esta historia **añade** `Iam/` y `Organization/` como nuevos top-level; actualizar los docs de estructura (`docs/architecture-api.md`, `docs/source-tree-analysis.md`, `docs/claude-code-quickref.md`) es parte del cierre (o follow-up explícito) al ser directorios `src/` nuevos.

## Dev Agent Record

### Agent Model Used

### Debug Log References

### Completion Notes List

### Change Log

### File List
