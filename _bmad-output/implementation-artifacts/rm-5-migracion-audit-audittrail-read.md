---
baseline_commit: 70c52f8963ed25d897413eea5bb5a0f0134daccd
---

# Story RM-5 (PR-5): Migración de las rutas de audit a `auditTrail.read`

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

Como **auditor de cumplimiento**,
quiero que las lecturas del trail se protejan con el permiso `auditTrail.read` en vez del rol `ROLE_AUDIT_READER`,
para que todo el sistema hable una sola gramática de autorización `(resource, action)` **sin cambiar quién puede leer el trail**.

Migración semántica pura sobre el core RBAC ya en `main` (RM-1 `#456`): las 2 rutas de lectura de audit pasan del `RoleVoter` nativo al `PermissionVoter`/`StaticAuthorizationPolicy`. Es la **primera ruta de negocio que cruza el `PermissionVoter` en producción** (aditiva: no abre superficie nueva; `AUDIT_READER` sigue concediendo, un tier genérico sigue sin poder leer el trail).

## Acceptance Criteria

1. **Swap del atributo (FR8, D4).** Los 2 controllers de audit (`AuditTimelineSearchController`, `AuditEventDetailController`) llevan `#[IsGranted('auditTrail.read')]` en lugar de `#[IsGranted('ROLE_AUDIT_READER')]`.
2. **Política configurada (FR8, D5).** En `StaticAuthorizationPolicy`: `explicitGrants['auditTrail.read'] = [AUDIT_READER, ADMIN]` **y** `auditTrail ∈ tierOptOut`. Ambas entradas quedan como **literales puros** (pasa `StaticAuthorizationPolicyIsDataOnlyTest`).
3. **Equivalencia preservada (FR8, R4).** Un `AUDIT_READER` o un `ADMIN` que accede a una ruta de audit → **concede** (200). Alice (fixture, `AUDIT_READER`) sigue leyendo.
4. **Sin sobre-concesión (FR8, R4).** Un tier genérico `VIEWER`/`EDITOR`/`MANAGER` **sin** `AUDIT_READER` que accede a una ruta de audit → **deniega 403** (`type: forbidden`). El trail no se auto-concede por tier: acceso sensible = explícito (evita la exposición ISO 27001 A.5.18). Esto es lo que garantiza `tierOptOut`.
5. **Sin regresión (R4).** Comparando el acceso pre/post swap, los principals existentes conservan **exactamente** su acceso previo al trail: anónimo → 401; autenticado sin rol (mallory) → 403; `AUDIT_READER` → 200. Ni una regresión, ni una sobre-concesión.

## Tasks / Subtasks

- [x] **Task 1 — RED: test unitario del matrix de política sobre los mapas de producción (AC: #2, #3, #4)**
  - [x] Añadir un método a `api/tests/Unit/Backoffice/Identity/Infrastructure/Security/StaticAuthorizationPolicyTest.php` que instancie la política **de producción** (`new StaticAuthorizationPolicy()` — sin argumentos, para vincular los literales reales, no un doble de test) y asevere el matrix de `auditTrail.read`:
    - `AUDIT_READER` → `assertTrue`; `ADMIN` → `assertTrue`.
    - `VIEWER`, `EDITOR`, `MANAGER` → `assertFalse` (cada uno); `[]` (sin roles) → `assertFalse`.
  - [x] Ejecutar `make php.unit c='--filter StaticAuthorizationPolicyTest'` y **confirmar que falla** con los mapas actuales vacíos (hoy `AUDIT_READER`→false por no tener tier ni grant; `VIEWER`→**true** porque `auditTrail` aún no está en `tierOptOut` — exactamente la sobre-concesión que la historia cierra). Esto valida el test.
- [x] **Task 2 — GREEN: poblar `StaticAuthorizationPolicy` (AC: #2)**
  - [x] En `api/src/Backoffice/Identity/Infrastructure/Security/StaticAuthorizationPolicy.php`, poblar `EXPLICIT_GRANTS` (línea 47) y `TIER_OPT_OUT` (línea 54) con los literales exactos (ver Dev Notes → *Cambios de código exactos*).
  - [x] Reejecutar el test de Task 1 → **verde**. Ejecutar también `make php.unit c='--filter StaticAuthorizationPolicyIsDataOnlyTest'` → verde (los literales `Role::X->value` son data-only, ya probado por `TIER_VERBS`).
- [x] **Task 3 — Swap del atributo en los 2 controllers (AC: #1)**
  - [x] `api/src/Backoffice/Audit/Infrastructure/Controller/AuditTimelineSearchController.php` línea 29: `#[IsGranted('ROLE_AUDIT_READER')]` → `#[IsGranted('auditTrail.read')]`.
  - [x] `api/src/Backoffice/Audit/Infrastructure/Controller/AuditEventDetailController.php` línea 29: idéntico swap.
  - [x] **No** tocar imports: `use Symfony\Component\Security\Http\Attribute\IsGranted;` ya es el correcto para el `PermissionVoter` (soporta `resource.action` por forma; ver Dev Notes → *Cómo funciona el voter*).
- [x] **Task 4 — Comportamiento end-to-end en Behat (AC: #3, #4, #5)**
  - [x] Añadir fixture de tier genérico en `api/tests/DataFixtures/Fixtures/User.yaml`: `user_trent`, email `trent@erpify.test`, roles `['MANAGER']` (un manager plenamente tierado que **aun así** no puede leer el trail — prueba de `tierOptOut`). UUID v7 nuevo, distinto de alice/mallory.
  - [x] En `api/tests/Behat/Context/SecurityContext.php`: añadir `private const string GENERIC_TIER_USER_EMAIL = 'trent@erpify.test';` y un step `#[Given('I am logged in as a generic-tier user without the audit-reader role')]` que llame a `logInAs(self::GENERIC_TIER_USER_EMAIL)`.
  - [x] En `api/features/backoffice/audit/access_control.feature`: **añadir** un escenario «un tier genérico (manager) es denegado el timeline con 403» usando el nuevo step (status 403 + `type: forbidden`). Actualizar el título (línea 1) y el comentario de intención (líneas 7-10) para reflejar la puerta `#[IsGranted('auditTrail.read')]` y que un tier genérico se deniega porque `auditTrail` opta fuera del auto-grant por tier.
  - [x] Verificar que los escenarios existentes **siguen verdes sin tocarlos**: anónimo→401, mallory(sin rol)→403 (+fila `ACCESS_DENIED`), alice(`AUDIT_READER`)→200. Ejecutar `make php.behat` (feature de audit; ver Dev Notes → *Comandos*).
- [x] **Task 5 — (SHOULD) Test de decisión funcional container-wired (AC: #3, #4)**
  - [x] Extender `api/tests/Functional/Backoffice/Identity/Infrastructure/Security/PermissionVoterAccessDecisionTest.php` con 2 aserciones sobre el `AuthorizationCheckerInterface` real: un `VIEWER` es **denegado** `auditTrail.read`; un `AUDIT_READER` es **concedido** `auditTrail.read`. Prueba que la `StaticAuthorizationPolicy` de producción es la cableada (no un doble). Si el coste (login de un 2º usuario) no compensa frente a Behat+unit, **omitir y anotarlo** en Completion Notes.
- [x] **Task 6 — Boy-scout: docblocks que quedan obsoletos (calidad)**
  - [x] `StaticAuthorizationPolicy.php` docblock (líneas ~22-24): la frase «`explicitGrants`/`tierOptOut` seed empty … populate them later» deja de ser cierta. Reescribir a **estado actual** sin narrativa de cambio (ver Dev Notes → *Cambios de código exactos*).
  - [x] `PermissionVoter.php` docblock (~línea 18): la cláusula que afirma que «the existing `#[IsGranted('ROLE_AUDIT_READER')]` audit routes are untouched» queda **falsa**. Leer el bloque real y reescribir para que explique el *porqué* del abstain sobre tokens `ROLE_*`/`IS_AUTHENTICATED_*` **sin** afirmar que las rutas de audit usan el rol.
  - [x] **NO tocar** el docblock de `Permission.php` (~línea 43): usa `ROLE_AUDIT_READER` como ejemplo de string «que NO es un permiso» (sin separador) — sigue siendo correcto (es un token de rol, no un permiso).
- [x] **Task 7 — Docs (cambio sensible a seguridad)**
  - [x] `git grep -n "ROLE_AUDIT_READER"` sobre `docs/` y `PRODUCTION_SECURITY_CHECKLIST.md`. Actualizar **solo** las entradas que describen la *puerta viva* de las rutas de audit → `auditTrail.read` (candidatos: `PRODUCTION_SECURITY_CHECKLIST.md`, `docs/rules/security.md`, `docs/architecture-api.md`).
  - [x] `PRODUCTION_SECURITY_CHECKLIST.md` es autoritativo y el cambio es sensible a seguridad → actualizarlo (regla de seguridad de `api/CLAUDE.md`).
  - [x] **NO** modificar el ADR `docs/adr/rbac-authorization-model.md`: su §Context describe el estado *previo* como contexto histórico de la decisión, y su status `accepted — design; not yet implemented` sigue vigente hasta cerrar RM-3/RM-4/RM-6. El rol `AUDIT_READER` **sobrevive** (enum, adapter `SecurityUser`, fixtures) — no borrarlo en ningún sitio.
- [x] **Task 8 — Gates de calidad + self-review de seguridad**
  - [x] `make php.stan` sobre cada fichero PHP cambiado (controllers, política, tests).
  - [x] `make php.quality` (sweep completo — única corrida que ejecuta PHPMD/cs-fixer; ver Dev Notes → *Gotchas* sobre `TooManyPublicMethods`).
  - [x] Self-review de seguridad del diff (ver Dev Notes → *Security review*). El cambio ES una restricción de autorización (voter/`IsGranted` presente); anotarlo en el PR.

## Dev Notes

### Contexto arquitectónico (lo que ya existe — NO reinventar)

El core RBAC ya está en `main` (RM-1 `#456`, commit `edd69e44`). RM-5 **solo consume** ese core; no crea módulos, ni directorios, ni entradas en `deptrac.yaml`/`services.yaml`/`security.yaml`. Todo es autowired/autoconfigured.

- **`Permission` = valor `(resource, action)`** — `api/src/Backoffice/Identity/Infrastructure/Security/Permission.php`. `Permission::fromString('auditTrail.read')` → `resource()='auditTrail'`, `action()='read'`. `isWellFormed('auditTrail.read')===true` (camelCase válido). [Source: `api/src/Backoffice/Identity/Infrastructure/Security/Permission.php`]
- **Puerto `AuthorizationPolicy`** — `permits(Permission $permission, array $roles): bool`. Neutral: habla permisos + tokens de rol **sin** prefijo `ROLE_` + bool. Jamás `User`/`Role`/`SecurityUser`. [Source: `api/src/Backoffice/Identity/Infrastructure/Security/AuthorizationPolicy.php`]
- **`StaticAuthorizationPolicy`** — 3 mapas `const` declarativos. Lógica de `permits()` (orden, corto-circuito OR):
  1. `grantedToAdmin` — `ADMIN` incondicional (nunca bloqueado por opt-out).
  2. `grantedByTier` — **si `resource ∈ tierOptOut` → `return false`** (aquí entra el opt-out); si no, concede si la acción está en algún tier del sujeto.
  3. `grantedExplicitly` — concede si algún rol del sujeto está en `explicitGrants[permission]`.
  → Un opt-out bloquea la vía tier **pero no** la explícita; `ADMIN` esquiva ambas. [Source: `api/src/Backoffice/Identity/Infrastructure/Security/StaticAuthorizationPolicy.php`]
- **Cómo funciona el voter** — `PermissionVoter::supports()` = `Permission::isWellFormed($attribute)`: soporta **por forma del string**. Por eso abstiene sobre `ROLE_*`/`IS_AUTHENTICATED_*`/`PUBLIC_ACCESS` (sin `.`), que resuelven los voters nativos. `voteOnAttribute()` hace `bareRoleTokens($token->getRoleNames())` (strip `ROLE_`, descarta tokens sin prefijo) y delega en la política. `#[IsGranted('auditTrail.read')]` → `IsGrantedAttributeListener` → `AccessDecisionManager` (estrategia por defecto `affirmative`) → solo el `PermissionVoter` vota (los nativos abstienen). [Source: `api/src/Backoffice/Identity/Infrastructure/Security/PermissionVoter.php`]
- **`Role` enum** — `api/src/Backoffice/Identity/Domain/Enum/Role.php`: `VIEWER/EDITOR/MANAGER/ADMIN/AUDIT_READER`, valores **sin** prefijo. `AUDIT_READER` es ortogonal a la escalera (no tiene fila en `tierVerbs`). El prefijo `ROLE_` se añade **solo** en `SecurityUser::getRoles()`. [Source: `api/src/Backoffice/Identity/Domain/Enum/Role.php`, `.../Security/SecurityUser.php`]
- **401 vs 403 son decisiones independientes del voter.** Anónimo sobre `^/api` → firewall `IS_AUTHENTICATED_FULLY` → 401 (`UnauthenticatedAccessListener`), **antes** de tocar el voter. Denegación del voter → `AccessDeniedException` → 403 `type: forbidden` (pipeline RFC 9457, sin marker nuevo). [Source: `api/config/packages/security.yaml`]

### Cambios de código exactos

**Controllers (Task 3)** — en cada fichero, línea 29 (a nivel de clase, bajo el `#[Route]`):
```php
#[IsGranted('auditTrail.read')]
```

**`StaticAuthorizationPolicy.php` (Task 2)** — reemplazar los dos `const` vacíos:
```php
    /**
     * Permission string -> the role tokens explicitly granted it, independent of any tier.
     *
     * @var array<string, list<string>>
     */
    private const array EXPLICIT_GRANTS = [
        'auditTrail.read' => [Role::AUDIT_READER->value, Role::ADMIN->value],
    ];

    /**
     * Resources that opt OUT of tier auto-grant: reachable only by an explicit grant (or by ADMIN).
     *
     * @var list<string>
     */
    private const array TIER_OPT_OUT = ['auditTrail'];
```

**`StaticAuthorizationPolicy.php` docblock (Task 6)** — la última frase del bloque de clase (~línea 23-24) describe el estado; reescribir a estado actual, p.ej.:
> `tierVerbs` carries the resource-agnostic ladder; `explicitGrants` and `tierOptOut` hold the exceptions for sensitive resources (the audit trail: readable only via an explicit grant, opted out of tiering).

(Sin «previously/now/seed empty» — describe el código actual, regla anti change-relative de `CLAUDE.md`.)

**Unit test (Task 1)** — método nuevo en `StaticAuthorizationPolicyTest`, patrón `new StaticAuthorizationPolicy()` (mapas de producción), estilo `testXxx` con asserts directos (como el resto de la clase). Nombre sugerido: `testAuditTrailReadIsGrantedOnlyToAuditReaderAndAdmin`.

### Estrategia de test (red-green-refactor)

- **Unit (Task 1, MUST)** — es la RED primaria. Vincula los **literales de producción** (no un doble). Falla hoy y por partida doble: `AUDIT_READER` denegado (debería conceder) y `VIEWER` concedido (debería denegar — la sobre-concesión). Verde tras Task 2.
- **Behat (Task 4, MUST)** — prueba end-to-end la puerta HTTP. El escenario **nuevo** (manager tierado → 403) es la cobertura de AC#4/#5: sin `tierOptOut`, un `MANAGER` (tier con `read`) leería el trail. Es el guard de regresión de más valor. Los 3 escenarios existentes (401/403-mallory/200-alice) quedan **verdes sin editarse** porque la migración es semánticamente equivalente para ellos.
- **Functional (Task 5, SHOULD)** — refuerzo container-wired; omitible si no compensa.
- **`StaticAuthorizationPolicyIsDataOnlyTest`** — no se añade test; solo se verifica que sigue verde tras poblar los literales.

### Source tree — ficheros a tocar

| Fichero | Acción |
|---|---|
| `api/src/Backoffice/Audit/Infrastructure/Controller/AuditTimelineSearchController.php` | swap atributo (L29) |
| `api/src/Backoffice/Audit/Infrastructure/Controller/AuditEventDetailController.php` | swap atributo (L29) |
| `api/src/Backoffice/Identity/Infrastructure/Security/StaticAuthorizationPolicy.php` | poblar 2 `const` + reword docblock |
| `api/src/Backoffice/Identity/Infrastructure/Security/PermissionVoter.php` | reword docblock (boy-scout) |
| `api/tests/Unit/Backoffice/Identity/Infrastructure/Security/StaticAuthorizationPolicyTest.php` | +1 test method |
| `api/tests/Functional/Backoffice/Identity/Infrastructure/Security/PermissionVoterAccessDecisionTest.php` | +2 asserts (SHOULD) |
| `api/tests/DataFixtures/Fixtures/User.yaml` | +`user_trent` (MANAGER) |
| `api/tests/Behat/Context/SecurityContext.php` | +const +step |
| `api/features/backoffice/audit/access_control.feature` | +escenario, título/prosa |
| `PRODUCTION_SECURITY_CHECKLIST.md`, `docs/rules/security.md`, `docs/architecture-api.md` | actualizar puerta ROLE→permiso |

### Gotchas (aprendidos de RM-1 y de la base de calidad)

- **PHPMD `TooManyPublicMethods` (cap 10).** `StaticAuthorizationPolicyTest` ya tiene 9 métodos públicos; el nuevo lo lleva a 10. PHPMD solo se ejecuta en `make php.quality` (no hay baseline). Si dispara, opciones: consolidar los asserts de audit en el único método (ya es 1 método) o convertir varios tests a un `#[DataProvider]` (ojo: el provider estático **también** cuenta como método público). Mantener **un** método para `auditTrail.read` deja el total en 10 — verificar en el sweep.
- **`StaticAuthorizationPolicyIsDataOnlyTest`** refleja el fuente y rechaza tokens ejecutables. `Role::AUDIT_READER->value` es data-only aceptable: `TIER_VERBS` ya usa `Role::VIEWER->value` y pasa. No introducir `??`, closures, llamadas ni condicionales en los mapas (convertiría la política en *motor* → viola SI-8, nuevo ADR).
- **cs-fixer/rector mutan ficheros**: correr `make php.quality` antes de commitear para no ensuciar el diff.
- **PHP worker segfault (exit 139)** en `make php.stan`: si pasa, reintentar con `PHP_SERVICE=messenger_worker` (gotcha conocido, no es fallo del código).
- **No dejar IDs de historia/AC en comentarios de código** (`RM-5`, `FR8`, `AC#4`): son andamiaje del spec; barrer el diff antes del commit final (regla `CLAUDE.md`). El texto de negocio va en el escenario Behat / mensajes de test.

### Comandos

```bash
make php.unit c='--filter StaticAuthorizationPolicyTest'
make php.unit c='--filter StaticAuthorizationPolicyIsDataOnlyTest'
make php.behat                      # o filtrar la feature de audit
make php.stan                       # por fichero cambiado
make php.quality                    # sweep final (PHPMD/cs-fixer/rector/deptrac)
```

### Security review (para el PR)

- **Authorization** — el cambio ES la restricción: `#[IsGranted('auditTrail.read')]` presente en ambas rutas; ninguna ruta pública nueva. Cobertura de denegación (403) y concesión (200) probada. Sobre-concesión cerrada por `tierOptOut` (AC#4).
- **Injection / input validation / mass-assignment / secrets / CORS / migrations** — N/A (sin SQL nuevo, sin DTO nuevo, sin migración, sin secreto, sin cambio de transporte). Declararlo explícito en el PR (regla «no silent skips»).
- **Regresión** — matriz bidireccional pre/post (AC#5) cubierta por Behat (401/403/200) + unit (política).

### Project Structure Notes

- Alineado con la estructura: el core RBAC vive en `Backoffice/Identity/Infrastructure/Security`; las rutas migradas en `Backoffice/Audit/Infrastructure/Controller`. La autorización se queda en Identity; en Audit **solo** cambia el string del atributo. Sin cambios de deptrac/bounded-context/error-contract.
- Baseline de dev: `main` con RM-1 (`#456`) y RM-2 (`#457`) mergeadas — HEAD `70c52f89` al crear esta historia. RM-5 es independiente del slice de negocio (banks/accounts) y va **antes** de RM-3/RM-4 en el orden de merge safe-first.
- **Worktree**: al arrancar dev, esta historia se implementa en un worktree aislado off `main` (regla dura de worktree + confirmación de topología de rama con el usuario antes de crearla). Rama sugerida: `feat/backoffice-audit-permission-gate` (confirmar con el usuario).

### References

- [Source: `_bmad-output/planning-artifacts/epics-rbac-authorization-model.md#Story RM-5 (PR-5)`] — ACs y alcance.
- [Source: `_bmad-output/planning-artifacts/arch-addendum-rbac-authorization-model.md#Localización de decisiones por PR`] — fila PR-5; SI-6…SI-9 (permiso=valor, borde, política≠mecanismo, additive-only).
- [Source: `docs/adr/rbac-authorization-model.md#D4`] — retiro de role-checks de rutas de negocio; `AUDIT_READER` sobrevive concediendo el permiso. [#D5] — `explicitGrants`/`tierOptOut`.
- [Source: `api/src/Backoffice/Identity/Infrastructure/Security/StaticAuthorizationPolicy.php`] — mapas y algoritmo `permits()`.
- [Source: `api/features/backoffice/audit/access_control.feature`] — escenarios 401/403/200 a preservar + convención.
- [Source: `api/tests/Behat/Context/SecurityContext.php`] — `logInAs()`, step de rol-less, firewall `main`.
- [Source: `api/tests/DataFixtures/Fixtures/User.yaml`] — alice (`AUDIT_READER`), mallory (`[]`).

## Dev Agent Record

### Agent Model Used

claude-opus-4-8[1m] (Opus 4.8, 1M context)

### Debug Log References

- **RED** — `StaticAuthorizationPolicyTest::testAuditTrailReadIsGrantedOnlyToAuditReaderAndAdmin` falló sobre los mapas vacíos (AUDIT_READER denegado; VIEWER sobre-concedido por tier). Confirma el test.
- **GREEN** — 13 tests unitarios de política verdes, incl. `StaticAuthorizationPolicyIsDataOnlyTest` (literales `Role::X->value` siguen data-only).
- **Funcional** — `PermissionVoterAccessDecisionTest` 2 tests / 7 asserts (VIEWER denegado + AUDIT_READER concedido `auditTrail.read` sobre el contenedor real).
- **Behat** — `features/backoffice/audit/` 19 escenarios / 143 pasos verdes: escenario nuevo manager-genérico→403 + existentes 401/403(mallory)/200(alice)/self-audit/timeline sin tocar.
- **Gates** — `make php.stan` (802 ficheros, sin errores); `make php.quality` exit 0 (PHPMD/cs-fixer/rector/deptrac: 0 violaciones).

### Completion Notes List

- Migración semánticamente equivalente: AUDIT_READER/ADMIN conceden; mallory (sin rol) y trent (MANAGER genérico) → 403; anónimo → 401. Sin regresión y sin sobre-concesión (AC#3/#4/#5).
- Política: `explicitGrants['auditTrail.read'] = [AUDIT_READER, ADMIN]` + `tierOptOut = ['auditTrail']` como literales puros; tripwire data-only verde (AC#2).
- `tierOptOut` es lo que cierra la sobre-concesión: sin él, un VIEWER/MANAGER (tier `read`) leería el trail. Probado en unit (mapas de producción) y Behat (manager→403 end-to-end).
- Boy-scout: docblocks obsoletos reescritos a estado actual en `StaticAuthorizationPolicy` y `PermissionVoter`; comentario con ID de requisito `R6` retirado del test funcional. `Permission.php` NO tocado (su ejemplo `ROLE_AUDIT_READER` sigue siendo un token-no-permiso correcto). ADR `rbac-authorization-model.md` NO tocado (su §Context es histórico; el status del modelo sigue vigente hasta RM-3/RM-4/RM-6).
- PHPMD `TooManyPublicMethods`: el test de política queda en 10 métodos, sin disparo.
- `api/config/reference.php` mostró churn no relacionado (dump de esquema, `translator.enabled`) — restaurado, fuera del diff.
- Security review: el cambio ES el gate `#[IsGranted('auditTrail.read')]`; sin SQL/DTO/migración/secreto/CORS nuevos. Docs de seguridad actualizadas (checklist, `security.md`, `architecture-api.md`).

### File List

Modified:

- `api/src/Backoffice/Audit/Infrastructure/Controller/AuditTimelineSearchController.php`
- `api/src/Backoffice/Audit/Infrastructure/Controller/AuditEventDetailController.php`
- `api/src/Backoffice/Identity/Infrastructure/Security/StaticAuthorizationPolicy.php`
- `api/src/Backoffice/Identity/Infrastructure/Security/PermissionVoter.php`
- `api/tests/Unit/Backoffice/Identity/Infrastructure/Security/StaticAuthorizationPolicyTest.php`
- `api/tests/Functional/Backoffice/Identity/Infrastructure/Security/PermissionVoterAccessDecisionTest.php`
- `api/tests/Behat/Context/SecurityContext.php`
- `api/tests/DataFixtures/Fixtures/User.yaml`
- `api/features/backoffice/audit/access_control.feature`
- `PRODUCTION_SECURITY_CHECKLIST.md`
- `docs/rules/security.md`
- `docs/architecture-api.md`
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (rm-5 → review)

Added:

- `_bmad-output/implementation-artifacts/rm-5-migracion-audit-audittrail-read.md` (esta historia)

## Change Log

- 2026-07-07 — RM-5 implementada: rutas de audit migradas a `#[IsGranted('auditTrail.read')]`; `StaticAuthorizationPolicy` poblada (`explicitGrants` + `tierOptOut`); unit/funcional/Behat + docs de seguridad actualizados. Status → review.
