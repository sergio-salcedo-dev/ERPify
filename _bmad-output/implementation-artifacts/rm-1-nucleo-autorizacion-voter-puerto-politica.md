# Story RM-1 (PR-1): Núcleo de autorización — VO, puerto, política declarativa, voter y roles-tier (aditivo)

Status: ready-for-dev

Epic: `rbac-authorization-model` · Orden de merge: **1º** (desbloquea RM-2…RM-6) · Slice: backend puro, **aditivo, sin gatear ninguna ruta**.

## Story

Como plataforma de ERPify,
quiero un modelo de autorización `(resource, action)` decidido por un voter sobre un puerto declarativo,
para que cualquier recurso futuro se gobierne por permiso sin tocar el núcleo — introducido **sin cambiar todavía ningún comportamiento observable**.

## Acceptance Criteria

1. **Permission = valor.** `Permission` es un value object `final readonly` con el string canónico `"<resource>.<action>"`; una factory de string valida la forma (rechaza malformados con excepción de dominio), expone `resource()`, `action()`, `toString()` e igualdad por valor. **No** existe entidad `Permission { id }` ni tabla. *(FR1, SI-6, ADR D1)*
2. **Puerto `AuthorizationPolicy` neutral.** Interface que habla sólo de `Permission`, `list<string>` de **roles como tokens desnudos** y una **decisión** (`bool`); **jamás** referencia `User`, `Role` ni `SecurityUser`. *(FR2, ADR D6, NFR5)*
3. **`StaticAuthorizationPolicy` declarativa.** Implementa el puerto con **tres estructuras de sólo-datos** — `tierVerbs`, `explicitGrants`, `tierOptOut` — definidas como literales `const` (sin `if`/closure/expression/`match` **embebidos en los datos**). Resolución: concede **sii** `ADMIN ∈ roles`, **o** (`resource ∉ tierOptOut` **y** `action ∈` verbos de algún tier del sujeto), **o** (algún rol ∈ `explicitGrants[permiso]`). Mapa semilla del slice: `tierVerbs = {VIEWER→[read], EDITOR→[read,write], MANAGER→[read,write,delete], ADMIN→[*]}`. `explicitGrants`/`tierOptOut` **nacen vacíos o mínimos** — el slice banks/accounts/audit los rellena en RM-3/RM-4/RM-5. *(FR3.1–3.3, ADR D5, SI-8)*
4. **Test de arquitectura — tripwire barato "política = sólo datos".** Un test **falla** si `StaticAuthorizationPolicy` contiene código ejecutable en sus **datos de política**. `nikic/php-parser` **no** está en el autoload de la app → usar `token_get_all`/Reflection (ver Dev Notes). *(FR3, NFR2 mitad no-algoritmo, R3)*
5. **`PermissionVoter` (1er voter custom del repo).** `supports()` es `true` **sólo** para atributos con forma `<resource>.<action>` (**abstiene** sobre `ROLE_*` e `IS_AUTHENTICATED_*`); hace **strip del prefijo `ROLE_` en el borde**; construye el `Permission`; delega la decisión al puerto; **acepta pero NO lee** `subject:`. *(FR2, NFR3, SI-7/SI-9, ADR D4)*
6. **Composición del access-decision.** Con la estrategia **`affirmative` (default) sin tocar**, un `VIEWER` autenticado —que pasa `IS_AUTHENTICATED_FULLY`— es **denegado** `bank.write` (los voters nativos **abstienen** sobre `bank.write`; el `PermissionVoter` deniega → deny) y **concedido** `bank.read`. Verificado por un test de integración sobre el conjunto real de voters. **NO** cambiar a `unanimous`. *(FR2, NFR4, R6)*
7. **Enum de dominio `Role` extendido.** Añade `VIEWER/EDITOR/MANAGER/ADMIN` (con `AUDIT_READER` **retenido**); los `->value` viven **sin** prefijo `ROLE_`; **sin migración ni cambio de esquema** (columna `roles` es `JSON list<string>`); **ninguna** lógica de `Application`/`Domain` ramifica por rol ni por permiso. *(FR4, NFR4, SI-5→SI-7, ADR D3)*
8. **Placement + gates + sin marker nuevo.** Todo el core en `Backoffice/Identity/Infrastructure/Security`; `php.deptrac` verde **SIN** añadir capa/ruleset (el directorio `Infrastructure/` ya cubre `Infrastructure/Security/`); `php.lint.bounded-context` + `php.lint.error-contract` verdes; **ningún marker de error nuevo** (`AccessDeniedException` → 403 gratis); `ProblemDetailsFactory` **intacto** (lo pincha `ConstantTimeAuthBranchingContractTest`). *(NFR5, NFR6)*
9. **Aditivo — cero cambio de comportamiento.** Ninguna ruta lleva aún `#[IsGranted('resource.action')]`; las 2 rutas de audit con `#[IsGranted('ROLE_AUDIT_READER')]` **siguen funcionando** (el `PermissionVoter` abstiene sobre `ROLE_*`) y sus tests de auth existentes **siguen verdes**. Se **cierra la decisión abierta del rol de bootstrap**: el 1er usuario se crea con `ADMIN` (vía la opción `--role=ADMIN` ya existente; **sin** default nuevo en el comando); fixtures/backfill de tier a principals = **RM-3**. *(NFR7)*

## Tasks / Subtasks

- [ ] **T1 · Extender el enum `Role`** (AC: 7)
  - [ ] En `api/src/Backoffice/Identity/Domain/Enum/Role.php` añadir casos `VIEWER='VIEWER'`, `EDITOR='EDITOR'`, `MANAGER='MANAGER'`, `ADMIN='ADMIN'` (conservar `AUDIT_READER`). Mantener/actualizar el docblock que explica la dirección del prefijo (`->value` **sin** `ROLE_`).
  - [ ] Ampliar `api/tests/Unit/Backoffice/Identity/Domain/Enum/RoleTest.php` para cubrir los nuevos casos y la ausencia del prefijo `ROLE_`.
  - [ ] Verificar que **no** hace falta migración (la columna `identity_user.roles` es `JSON list<string>`; nuevos valores válidos = nuevos strings).
- [ ] **T2 · VO `Permission`** (AC: 1)
  - [ ] Crear `api/src/Backoffice/Identity/Infrastructure/Security/Permission.php` — `final readonly`, `fromString(string): self` (valida forma `<resource>.<action>`), `resource()`, `action()`, `toString()`, igualdad por valor. Excepción co-localizada (p. ej. `InvalidPermission`) al rechazar forma inválida.
  - [ ] Test unitario `api/tests/Unit/Backoffice/Identity/Infrastructure/Security/PermissionTest.php` (canónico, split, igualdad, rechazo de malformados: sin punto, partes vacías, múltiples puntos).
- [ ] **T3 · Puerto `AuthorizationPolicy` (neutral) + `StaticAuthorizationPolicy`** (AC: 2, 3)
  - [ ] Crear el puerto `api/src/Backoffice/Identity/Infrastructure/Security/AuthorizationPolicy.php` — `permits(Permission $permission, array $roles): bool` (`@param list<string> $roles` tokens desnudos). **Sin** imports de `User`/`Role`/`SecurityUser`.
  - [ ] Crear `api/src/Backoffice/Identity/Infrastructure/Security/StaticAuthorizationPolicy.php` con `tierVerbs`/`explicitGrants`/`tierOptOut` como `private const` (literales), resolución data-driven. Puede importar `Role` (Domain) para **construir** los mapas (`Role::VIEWER->value`), pero el puerto no.
  - [ ] Tests unitarios de la tabla de verdad (`StaticAuthorizationPolicyTest`): `ADMIN` concede todo; `VIEWER` concede `read`, deniega `write`/`delete`; roles vacíos = deny; `explicitGrants` concede a los roles listados; `tierOptOut` bloquea el auto-grant por tier.
- [ ] **T4 · Test de arquitectura "política = sólo datos"** (AC: 4)
  - [ ] `api/tests/Unit/Backoffice/Identity/Infrastructure/Security/StaticAuthorizationPolicyIsDataOnlyTest.php` — vía `token_get_all`/Reflection asserta que la definición de los mapas no contiene control-flow ejecutable. Ver **Dev Notes → tripwire** para el enfoque recomendado. (**Tarea de mayor incertidumbre** — considerar un spike de 15 min.)
- [ ] **T5 · `PermissionVoter`** (AC: 5, 6)
  - [ ] Crear `api/src/Backoffice/Identity/Infrastructure/Security/PermissionVoter.php` extendiendo `Symfony\Component\Security\Core\Authorization\Voter\Voter`: `supports()` sólo para forma `<resource>.<action>`; `voteOnAttribute()` hace strip `ROLE_` de `$token->getRoleNames()`, construye `Permission`, delega en el puerto; **no lee `$subject`**.
  - [ ] Test unitario `PermissionVoterTest`: `supports()` discrimina forma (abstiene en `ROLE_AUDIT_READER`, `IS_AUTHENTICATED_FULLY`); strip de `ROLE_`; delega en un fake del puerto; ignora `subject`.
  - [ ] Test de integración `api/tests/Functional/Backoffice/Identity/Infrastructure/Security/PermissionVoterAccessDecisionTest.php` (o Functional equiv.): sobre el `AuthorizationCheckerInterface`/`AccessDecisionManager` real con un token `VIEWER`, `isGranted('bank.write')===false` y `isGranted('bank.read')===true`. (Prueba R6 sin gatear rutas.)
- [ ] **T6 · Cableado DI** (AC: 6, 8)
  - [ ] Confirmar que `PermissionVoter` queda **autoconfigurado** como `security.voter` (subclase de `Voter`) y que `StaticAuthorizationPolicy` **autowirea** como única impl de `AuthorizationPolicy`. Añadir a `services.yaml` **sólo si** el autowiring no resuelve.
  - [ ] **No** tocar `security.yaml` (`access_decision_manager` sigue sin configurar = `affirmative`).
- [ ] **T7 · Cerrar decisión de rol de bootstrap** (AC: 9)
  - [ ] `CreateUserCommand` **ya** acepta `--role` validado contra `Role::cases()` → sin cambio de código. Documentar la convención (1er usuario = `ADMIN`) donde corresponda (ver Dev Notes; confirmar redacción con Sergio). **No** introducir un default `ADMIN` implícito en el comando.
- [ ] **T8 · Regresión + gates** (AC: 8, 9)
  - [ ] Correr los tests de auth de audit existentes (`features/backoffice/audit/access_control.feature`, `AuditEventDetailFunctionalTest`) → verdes (prueba que el voter abstiene sobre `ROLE_*`).
  - [ ] `make php.stan` en cada fichero PHP tocado; al final `make php.quality` (incluye `php.deptrac`, `php.lint.bounded-context`, `php.lint.error-contract`). Todo verde.

## Dev Notes

### Contrato de diseño (fuente de verdad)

- **ADR** `docs/adr/rbac-authorization-model.md` — decisiones **D1** (permiso=valor), **D4** (un voter sobre puerto; retiro de role-checks de negocio), **D5** (constantes por módulo + política tier declarativa), **D6** (placement en `Identity/Infra/Security`, contratos neutrales, strip `ROLE_` en el borde), **D7** (subject sólo vocabulario), **D8** (estático→configurable = swap del store), **D9** (`subject:` sin evaluar). Además el **criterio OCP verbatim + los 2 tripwires**.
- **Addendum** `_bmad-output/planning-artifacts/arch-addendum-rbac-authorization-model.md` — invariantes **SI-6** (permiso=valor), **SI-7** (autorización en el borde, extiende SI-5; ni App ni Domain ramifican por rol/permiso), **SI-8** (política = datos, no mecanismo), **SI-9** (recurso nuevo = additive-only; `subject:` sin evaluar). Localización PR-1 y DAG.
- **Hermano (en vigor)** `docs/adr/auth-rbac-subsystem.md` + `_bmad-output/planning-artifacts/arch-addendum-auth-rbac.md` — **SI-5** (roles = autorización externa, dirección Domain→Infra→Symfony, unidireccional) que esta historia **extiende**, y **SI-4** (errores por el contrato RFC 9457).
- **Épica** `_bmad-output/planning-artifacts/epics-rbac-authorization-model.md` — historia RM-1 y su tabla `FR Coverage Map`.

### Artefactos a crear/tocar (rutas exactas)

Todo el **core** vive en `api/src/Backoffice/Identity/Infrastructure/Security/` (ADR D6 — «packaging Infrastructure, hogar conceptual el modelo»):

| Fichero | Qué |
|---|---|
| `.../Security/Permission.php` | **NUEVO** — VO `final readonly` `(resource, action)`. |
| `.../Security/InvalidPermission.php` | **NUEVO** — excepción de forma inválida (co-localizada). |
| `.../Security/AuthorizationPolicy.php` | **NUEVO** — puerto **neutral** (`Permission` + `list<string>` + `bool`). |
| `.../Security/StaticAuthorizationPolicy.php` | **NUEVO** — impl declarativa (`tierVerbs`/`explicitGrants`/`tierOptOut` `const`). |
| `.../Security/PermissionVoter.php` | **NUEVO** — 1er voter custom; strip `ROLE_`; `subject:` no leído. |
| `Backoffice/Identity/Domain/Enum/Role.php` | **EDITAR** — añadir 4 tiers. |

**Estado actual del código (leído — no reinventar):**
- `Role` enum: `api/src/Backoffice/Identity/Domain/Enum/Role.php`, namespace `Erpify\Backoffice\Identity\Domain\Enum`, hoy **único caso** `AUDIT_READER='AUDIT_READER'`. El docblock ya documenta que `->value` no lleva `ROLE_`.
- `User` agregado: `api/src/Backoffice/Identity/Domain/Entity/User.php` — `register(id, email, HashedPassword, Role ...$roles)`, `roles(): list<Role>` (rehidrata de `JSON list<string>`, dedup en construcción), **sin mutador de roles** (por diseño). Columna `#[ORM\Column(type: Types::JSON)] private array $roles`.
- `SecurityUser`: `api/src/Backoffice/Identity/Infrastructure/Security/SecurityUser.php` — **único** sitio que añade `ROLE_`: `getRoles()` = `array_map(fn(Role $r) => 'ROLE_'.$r->value, $user->roles())`. El voter recibe esos strings vía `$token->getRoleNames()` y les hace strip.
- `CreateUser` (App): `api/src/Backoffice/Identity/Application/CreateUser.php` — `create(email, HashedPassword, Role ...$roles)`.
- `CreateUserCommand` (CLI): `api/src/Backoffice/Identity/Infrastructure/Cli/CreateUserCommand.php` — **ya** tiene `--role` (`VALUE_REQUIRED|VALUE_IS_ARRAY`, default `[]`, validado con `Role::tryFrom` contra `Role::cases()`). ⇒ Al añadir los tiers al enum, `identity:user:create --role=ADMIN` funciona sin tocar el comando.
- `security.yaml`: `api/config/packages/security.yaml` — firewall `main` (`json_login`, `login_throttling: 5`), `access_control` default-deny con allowlist pública + catch-all `^/api → IS_AUTHENTICATED_FULLY`. **`access_decision_manager` NO configurado** ⇒ default **`affirmative`** (symfony/security-core 8.0.13).

### Decisiones de diseño y gotchas críticos (previenen retrabajo)

1. **Estrategia access-decision = `affirmative` (NO cambiar a `unanimous`).** Bajo `affirmative` se concede si ≥1 voter concede. Sobre un atributo `bank.write` los voters nativos (`RoleVoter`, `AuthenticatedVoter`) **abstienen** (no es `ROLE_*` ni `IS_AUTHENTICATED_*`), así que el `PermissionVoter` es el único que vota: si deniega → deny. El firewall (`IS_AUTHENTICATED_FULLY`) y el `#[IsGranted('bank.write')]` son **dos decisiones independientes** — pasar el firewall no satisface el permiso. Cambiar a `unanimous` sería innecesario y arriesgaría regresiones en otros atributos. **R6 se prueba con el test de integración de T5, no con config.**
2. **deptrac: NO añadir capa/ruleset.** El collector `Backoffice.Identity.Infrastructure` es `src/Backoffice/Identity/Infrastructure/.*` y **ya matchea** `Infrastructure/Security/*` (precedente: `SecurityUser`, `UserProvider`, `UnauthenticatedAccessListener` ya viven ahí sin entrada propia). `Vendor.Symfony` (componente Security) ya está permitido en la capa Infrastructure. La nota del addendum sobre "mirror del bloque de Identity" aplica a **módulos nuevos**, no a este sub-directorio. Sólo mantener `make php.deptrac` verde.
3. **Contrato de error: sin marker nuevo.** Una denegación de `#[IsGranted]`/voter lanza `AccessDeniedException` → el pipeline lo mapea a `403 {type: forbidden}` en `Shared/ErrorContract/Application/ProblemDetailsFactory.php` (arm dedicado). Anónimo sobre `^/api` → `UnauthenticatedAccessListener` (prioridad 40) lo reescribe a 401. **No tocar `ProblemDetailsFactory`** — su branching 401/403 constant-time lo pincha `ConstantTimeAuthBranchingContractTest`. NFR26 (actualizar `docs/api-error-contract.md`) **no** se dispara: no se añade ni cambia marker.
4. **Neutralidad del puerto (habilita promoción a `Shared/Authorization`).** El puerto `AuthorizationPolicy` habla `Permission` + `list<string>` (tokens desnudos) + `bool`. **No** importa `Role`/`User`/`SecurityUser`. `StaticAuthorizationPolicy` (impl) **sí** puede usar `Role` para construir sus mapas. (La dependencia impl→`Backoffice/Identity/Domain/Role` se resolverá si algún día se promociona el core; no es problema de RM-1.)
5. **Tripwire "política = sólo datos" (T4) — enfoque recomendado.** Como `nikic/php-parser` **no** está en el autoload de PHPUnit, evitar AST de php-parser. Recomendado: (a) definir `tierVerbs`/`explicitGrants`/`tierOptOut` como `private const` array literales — PHP prohíbe closures/llamadas en un `const`, de modo que el **lenguaje ya garantiza "sólo datos"** en la definición del mapa; (b) un test con `token_get_all(file_get_contents((new \ReflectionClass(StaticAuthorizationPolicy::class))->getFileName()))` que asserta que la definición de esos const no incluye tokens de control-flow/closure. La **resolución** (`permits()`) es *mecanismo* y puede tener lógica; escribirla idealmente como una expresión booleana (`return $isAdmin || $tierGrant || $explicitGrant;`) con lookups `in_array`/`isset`, para que el test pueda ser estricto. La **mitad cara** del gate (invariancia del core-set + `subject:` en CI) es **RM-6**, no aquí.
6. **`subject:` aceptado, no leído (2º tripwire, SI-9/D9).** `voteOnAttribute($attribute, $subject, $token)` recibe `$subject` y **no lo usa**. RM-6 lo eleva a test de CI; RM-1 sólo debe no leerlo.
7. **Sin migración / sin cambio de esquema.** Añadir casos al enum `Role` no toca `identity_user.roles` (JSON `list<string>`). No `make db.diff`.
8. **Coexistencia con las rutas de audit.** En RM-1 las 2 rutas de audit siguen con `#[IsGranted('ROLE_AUDIT_READER')]` servido por el `RoleVoter` nativo; el `PermissionVoter` **abstiene** sobre `ROLE_AUDIT_READER` (sin punto → `supports()` false). La migración a `auditTrail.read` es **RM-5**, no aquí. Correr los tests de audit existentes como regresión.
9. **Decisión de rol de bootstrap (cerrada, confirmar redacción).** Recomendado: el 1er usuario/bootstrap se crea **explícitamente** `--role=ADMIN` (para no quedar fuera cuando RM-3 gatee); **sin** default `ADMIN` implícito en el comando (explícito = más seguro; evita que todo usuario CLI sea admin por accidente). El backfill de tier a principals existentes y el rol de la fixture Alice/Mallory son **RM-3**. *(Ver «Questions for Sergio» al final.)*
10. **`->value` sin `ROLE_`, dirección unidireccional (SI-5).** Los tiers entran al enum sin prefijo. El único traductor a `ROLE_*` es `SecurityUser::getRoles()`. El voter hace el camino inverso **sólo en el borde** (strip `ROLE_`) para hablar con el puerto en tokens desnudos; nada mapea un `ROLE_*` de Symfony de vuelta al enum.

### Fuera de alcance (NO hacer en RM-1)

- ❌ Gatear rutas de `Bank`/`BankAccount` o declarar `BankPermission`/`BankAccountPermission` → **RM-3/RM-4**.
- ❌ Migrar audit a `auditTrail.read` → **RM-5**.
- ❌ Fingerprint keyset #437 → **RM-2**.
- ❌ Fixture Alice/Mallory con tier + backfill de principals → **RM-3**.
- ❌ Gate OCP ejecutable (core-set-invariance + `subject:` en CI) → **RM-6**.
- ❌ Row-level / evaluar `subject:` / cualquier `if` en la política (sería ABAC = ADR nuevo).
- ❌ Materializar un tipo `AuthorizationSubject` (D7: sólo vocabulario).

### Testing (obligatorio; convenciones del repo)

- **Unit** (`api/tests/Unit/Backoffice/Identity/…`, espeja `src/`): `PermissionTest`, `StaticAuthorizationPolicyTest` (tabla de verdad), `StaticAuthorizationPolicyIsDataOnlyTest` (tripwire), `PermissionVoterTest` (fake del puerto, sin container/DB — regla de testing del repo). Ampliar `Domain/Enum/RoleTest`.
- **Integración/Functional** (`api/tests/Functional/Backoffice/Identity/…`): decisión de acceso real con `VIEWER` → `bank.write` deniega / `bank.read` concede. Patrón: `WebTestCase` + trait `AuthenticatesFunctionalRequests` (`api/tests/Functional/AuthenticatesFunctionalRequests.php`) que hace `loginUser` sin round-trip HTTP; para un `VIEWER` puntual, construir el `SecurityUser` con un `User::register(..., Role::VIEWER)` y `loginUser`. Alternativa mínima: resolver `AuthorizationCheckerInterface` del container con un token seteado.
- **Regresión audit**: `features/backoffice/audit/access_control.feature` (401/403 + fila `ACCESS_DENIED`) y `tests/Functional/Backoffice/Audit/.../AuditEventDetailFunctionalTest.php` deben seguir verdes.
- Fixtures de referencia: `api/tests/DataFixtures/Fixtures/User.yaml` (Alice `AUDIT_READER`, Mallory `[]`), factory `api/tests/DataFixtures/UserFixtureFactory.php` (`create(id, email, plainPassword, roleValues[])`). `SecurityContext` (`api/tests/Behat/Context/SecurityContext.php`) loguea Alice salvo `@anonymous`.
- Correr: `make php.unit c='--filter …'` iterando; `make php.behat` para las features; `make php.stan` por fichero; `make php.quality` al cerrar.

### Project Structure Notes

- Ubicación `Backoffice/Identity/Infrastructure/Security/` es **deptrac-legal sin cambios** (la capa Infrastructure ya la cubre y permite `Vendor.Symfony`). Namespace: `Erpify\Backoffice\Identity\Infrastructure\Security`.
- El VO `Permission` y el puerto `AuthorizationPolicy` residen en `Infrastructure/` **por decisión explícita del ADR D6** (packaging hoy; hogar conceptual el modelo de autorización) — no es una violación de capas, es la costura elegida para promover luego a `Shared/Authorization` sin rediseño. Documentarlo en un docblock breve del puerto/VO (el *por qué*).
- Sin YAML de rutas; sin serializer groups; el core no expone HTTP propio (es un voter + puerto).

### References

- `docs/adr/rbac-authorization-model.md` — D1, D4, D5, D6, D7, D8, D9 + §"Acceptance criterion (OCP) and the two tripwires".
- `_bmad-output/planning-artifacts/arch-addendum-rbac-authorization-model.md` — SI-6/SI-7/SI-8/SI-9; §"Localización de decisiones por PR" (PR-1); §"Slice de validación".
- `_bmad-output/planning-artifacts/epics-rbac-authorization-model.md` — §"Story RM-1 (PR-1)"; §"Riesgos" R3/R5/R6; §"FR Coverage Map".
- `docs/adr/auth-rbac-subsystem.md` — D3 (roles enum + dirección `ROLE_`), D4 (voter sobre pipeline 403), §"Decided inputs" (bootstrap = comando `identity:user:create`).
- `_bmad-output/planning-artifacts/arch-addendum-auth-rbac.md` — SI-4 (errores por contrato), SI-5 (roles = autorización externa, unidireccional).
- `docs/api-error-contract.md` — tabla de markers + "Symfony framework exception bridge" (`AccessDeniedException`→403, `AuthenticationException`→401); banner NFR26.
- `docs/project-context.md` — reglas PHP/Symfony/testing/quality del repo (cargar antes de codificar).
- `api/CLAUDE.md` — §"Layer rules", §"Deptrac architecture gate" (mirror sólo para módulos nuevos).
- Código en vigor: `api/src/Backoffice/Identity/Domain/Enum/Role.php`, `.../Domain/Entity/User.php`, `.../Infrastructure/Security/SecurityUser.php`, `.../Infrastructure/Cli/CreateUserCommand.php`, `api/config/packages/security.yaml`, `api/src/Shared/ErrorContract/Application/ProblemDetailsFactory.php`, `.../Backoffice/Identity/Infrastructure/Security/UnauthenticatedAccessListener.php`, `api/tools/deptrac/deptrac.yaml`.

### Previous Story Intelligence (subsistema que RM-1 extiende, ya en vigor)

- **Epic auth-foundation + Epic 3 (`done`).** Firewall de sesión (no JWT), `User` libre de framework en `Backoffice/Identity`, `SecurityUser` adapter, `UserProvider`, `SecurityActorContextFactory` (**actor de auditoría**, no autorización — no confundir con el `PermissionVoter`), `UnauthenticatedAccessListener` (401 vs 403), enum `Role` con único caso `AUDIT_READER`, y las 2 rutas de audit gateadas por `#[IsGranted('ROLE_AUDIT_READER')]` (RoleVoter nativo). **No existe ningún voter custom** — el `PermissionVoter` es el primero.
- **SI-5 congelado**: ninguna lógica de `Application`/`Domain` ramifica por rol; RM-1 lo **extiende** a permisos (SI-7). No introducir helpers `isAdmin()`/`can()` en dominio.
- **Pipeline de error maduro**: 401/403 ya fluyen; el reto de AF fue "gate de configuración, no de modelo" (una ruta que debería exigir auth quedando pública) — RM-1 no abre rutas, así que no aplica, pero mantener el default-deny intacto.

### Git Intelligence

- Rama actual: `docs/rbac-authorization-model-uwpj` (worktree). Commits recientes: `72832971` (épica RBAC), `50db9a80`/`7c6a413e` (ADR+addendum RBAC). El ADR RBAC también llegó a `main` vía `#454` — usar los artefactos de **esta rama** como contrato.
- Precedente de implementación de seguridad en Identity/Infra/Security (AF-1.x, Epic 3): seguir el estilo de `SecurityUser.php`/`UnauthenticatedAccessListener.php` (clases `final readonly` donde aplique, `#[Override]`, docblocks que explican el *por qué*).

### Project Context Reference

`docs/project-context.md` es de carga obligatoria antes de codificar: PHP 8.5 + `declare(strict_types=1)`, tipos en todo, enums sobre constantes, excepciones para error-flow (excepciones de dominio en `Domain/`, sin `HttpException` en `Domain/`), sin framework en `Domain/`, PHPStan `level: max` como única puerta de tipos, tests AAA con fakes in-tree sobre mocks. `make` desde la raíz del repo.

## Dev Agent Record

### Agent Model Used

_(a rellenar por el dev agent)_

### Debug Log References

### Completion Notes List

### File List
