---
baseline_commit: a7d39f06dcc72fef6edf2aa7c3a00c69b40ad02b
---

# Story RM-3 (PR-3): Gateo de las rutas de `Bank` — primer tightening real

Status: review

Epic: `rbac-authorization-model` · Orden de merge: **4º** (RM-1 → RM-2 → RM-5 → **RM-3** → RM-4) · Slice: `Backoffice/Bank` — **primer tightening real de comportamiento**. Retira el catch-all `IS_AUTHENTICATED_FULLY` como única puerta de las rutas de bank y añade autorización por recurso/acción. Independiente de RM-2/RM-5 (ya en `main`); no bloquea a RM-4 (RM-4 depende de RM-2, no de RM-3).

## Story

Como operador del backoffice,
quiero que las acciones sobre bancos exijan el permiso correspondiente por recurso/acción,
para que sólo los roles autorizados lean, editen o borren bancos, en vez de que cualquier usuario autenticado alcance toda operación.

## Acceptance Criteria

1. **Constantes `BankPermission` co-localizadas — sin `close`.** Existe `BankPermission` en el borde de `Backoffice/Bank` (`Infrastructure/Security/`) con `READ/WRITE/DELETE = 'bank.{read,write,delete}'`. **No** hay `CLOSE`: un banco **no se cierra** — su operación terminal es el hard-delete (decisión de Sergio; el agregado `Bank` no tiene campo de estado — «close» vive sólo en `BankAccount` vía `PATCH /bank-accounts/{id}/status`). *(FR5, D2, D5)*
2. **Los 7 controladores de `Bank` gateados.** Cada controlador lleva `#[IsGranted(BankPermission::<X>)]`: `READ` → `BankSearchController`, `BankGetController`, `BankCountController`, `BankRealtimeAuthorizeController`; `WRITE` → `BankPostController`, `BankPutController`; `DELETE` → `BankDeleteController`. El catch-all `^/api → IS_AUTHENTICATED_FULLY` deja de ser su **única** puerta (el `#[IsGranted]` capa autorización sobre la autenticación). **`security.yaml` no se toca** — el catch-all sigue siendo el default-deny del resto de `/api`. *(FR6, D3, NFR9)*
3. **Cero edición de política — la forma más fuerte del objetivo OCP.** `bank.read/write/delete` quedan **auto-cubiertos por `TIER_VERBS`** (`VIEWER→read`, `EDITOR→+write`, `MANAGER→+delete`, `ADMIN→*`), con `bank ∉ TIER_OPT_OUT` y **ninguna** fila en `explicitGrants`. `StaticAuthorizationPolicy` **no se modifica**: RM-3 es un slice sólo-CRUD gateado con **0 filas de política** (objetivo-f en su forma más fuerte). El tripwire `StaticAuthorizationPolicyIsDataOnlyTest` queda **verde sin tocarse**. *(NFR1, SI-9)*
4. **Tier ↔ verbo (concede/deniega por tier, sin fila de política).** Un `VIEWER` concede `bank.read` y **deniega** `bank.write` (403); un `EDITOR` escribe pero un `VIEWER`/`EDITOR` **deniega** `bank.delete` (sólo `MANAGER`/`ADMIN` borran); todo cubierto por `tierVerbs`. *(FR6, FR3.1)*
5. **401 vs 403 por RFC 9457.** Una request **anónima** a una ruta de bank gateada → **401** (no autenticado, del firewall); una **autenticada-sin-permiso** → **403** (`PermissionVoter`); ambas por el pipeline RFC 9457 (`application/problem+json`, `type: unauthenticated` / `type: forbidden`), **sin `JsonResponse` manual y sin marker nuevo**. *(NFR6, R5)*
6. **Backfill — ningún acceso se pierde salvo intención.** En prod (greenfield: sólo el `ADMIN` de bootstrap, con wildcard → no afectado) el backfill es un **runbook** (no migración de datos Doctrine): asignar un rol tier por defecto a cualquier principal **no-ADMIN** preexistente **antes** del merge. En dev/test, la fixture Alice y el usuario del trait funcional reciben un tier (`MANAGER`) → ninguna suite regresa acceso. *(FR6, R1)*
7. **Fixtures/bootstrap siembran el tier.** Alice (sesión Behat por defecto) y `functional@erpify.test` (trait funcional) — hoy sólo `AUDIT_READER`, que **no** concede ningún verbo bank → 403 tras el gateo — reciben `MANAGER`. Un nuevo `api/features/backoffice/bank/access_control.feature` prueba la matriz 401/403/200 por verbo (mirror de `audit/access_control.feature`). *(FR6)*
8. **Gates verdes, sin migración.** `make php.stan` por fichero y `make php.quality` (incl. `php.deptrac` — con el ancla de la nueva sub-namespace `Backoffice/Bank/Infrastructure/Security` si el bloque no la cubre —, `php.lint.bounded-context`, `php.lint.error-contract`) verdes; suites bank Behat + funcional verdes. **Sin migración** (nada toca esquema). Sin edición de `docs/api-error-contract.md` (no se añade/cambia marker → NFR26 no se dispara). *(NFR5, NFR6)*

## Tasks / Subtasks

- [x] **T1 · Constantes `BankPermission` (sin `CLOSE`)** (AC: 1, 8)
  - [x] Crear `api/src/Backoffice/Bank/Infrastructure/Security/BankPermission.php`: `final class BankPermission` con `public const string READ = 'bank.read'; WRITE = 'bank.write'; DELETE = 'bank.delete';`. **Sin `CLOSE`.** Docblock: el *por qué* de constantes vs literal inline (typo-safety en 7 call-sites — `Permission` es case-sensitive y un literal mal escrito falla sólo en request-time; + FR5/D5 «el módulo declara su vocabulario en su borde»). Divergencia **consciente** del precedente de audit (literales) — ver Dev Notes §Decisión-1.
  - [x] Deptrac verificado: el collector `Backoffice.Bank.Infrastructure` es `src/Backoffice/Bank/Infrastructure/.*` (prefijo de directorio) → cubre `Infrastructure/Security` **sin cambio** en `deptrac.yaml`; `make php.deptrac` = 0 violations.
- [x] **T2 · Gatear los 7 controladores** (AC: 2, 4, 5)
  - [x] Añadir `use Symfony\Component\Security\Http\Attribute\IsGranted;` + `#[IsGranted(BankPermission::READ)]` a `BankSearchController`, `BankGetController`, `BankCountController`, `BankRealtimeAuthorizeController`.
  - [x] `#[IsGranted(BankPermission::WRITE)]` a `BankPostController`, `BankPutController`.
  - [x] `#[IsGranted(BankPermission::DELETE)]` a `BankDeleteController`.
  - [x] Boy-scout: corregir el docblock stale de `BankCountController` que dice «consciously unauthenticated» (ya no cierto — ahora exige `bank.read`; y de hecho hoy ya está tras el catch-all, el docblock miente).
  - [x] **No** tocar `api/config/packages/security.yaml`.
- [x] **T3 · Backfill fixtures/trait (dev/test)** (AC: 6, 7)
  - [x] `api/tests/DataFixtures/Fixtures/User.yaml`: `user_alice` roles `['AUDIT_READER']` → `['AUDIT_READER', 'MANAGER']` (MANAGER concede read+write+delete; no hace falta ADMIN sin `close`).
  - [x] `api/tests/Functional/AuthenticatesFunctionalRequests.php` (~L46/L57): añadir `MANAGER` al usuario `functional@erpify.test` y **extender la reconciliación de roles** (si sólo compara `AUDIT_READER`, un usuario sembrado sin el tier daría 403 fantasma). Actualizar el docblock que afirma que `AUDIT_READER` «es inerte para bank tests» (deja de ser cierto).
- [x] **T4 · Runbook backfill prod (no migración)** (AC: 6)
  - [x] Documentar en el cuerpo del PR + una línea en `PRODUCTION_SECURITY_CHECKLIST.md` (cambio auth-sensitive): greenfield → sólo el `ADMIN` de bootstrap (wildcard, no afectado); asignar un rol tier a cualquier no-ADMIN preexistente **antes** del merge. **Sin migración de datos Doctrine** (roles se asignan a usuarios directamente; ninguno huérfano en el greenfield).
- [x] **T5 · Tests unit de política** (AC: 3, 4)
  - [x] Verificado: `StaticAuthorizationPolicyTest` (RM-1) **ya** cubre `bank.read/write/delete` por tier (VIEWER read-only, EDITOR +write, MANAGER +delete, ADMIN todo) y la no-concesión por rol ajeno (`AUDIT_READER`/`[]` → `bank.read` denegado = ninguna fila `explicitGrants` para `bank`). Por *minimum-code* RM-3 **no** duplica.
- [x] **T6 · Test funcional wired (decisión real)** (AC: 4, 5)
  - [x] Verificado: `PermissionVoterAccessDecisionTest` (RM-1) **ya** prueba la cadena wired `bank.read` (concede VIEWER) / `bank.write` (deniega VIEWER pese a pasar `IS_AUTHENTICATED_FULLY`). El gate de `delete` — mismo mecanismo que `write` — queda cubierto end-to-end por Behat (mallory DELETE → 403; `delete.feature` Alice-MANAGER → 204). Sin duplicación.
- [x] **T7 · Behat access_control + no-regresión** (AC: 5, 7)
  - [x] Nuevo `api/features/backoffice/bank/access_control.feature` (mirror de `api/features/backoffice/audit/access_control.feature`): `@anonymous` → 401 (`type: unauthenticated`); `mallory` (role-less) → 403 (`type: forbidden`, `application/problem+json`) en un GET, un POST/PUT y el DELETE; un tier concedido → 200/2xx por verbo.
  - [x] Verificar que las features bank existentes (`search/get/update/delete/create/count`, más los `dispatch_event`) **siguen 200** con Alice=`MANAGER` (sin cambiarlas salvo que el gateo lo exija).
- [x] **T8 · Gates + regresión** (AC: 8)
  - [x] `make php.stan` en cada fichero PHP tocado; al final `make php.quality` (deptrac, bounded-context, error-contract, phpmd, rector, cs-fixer). `make php.behat` de las features bank + `make php.unit`/functional del árbol RBAC (`--filter 'StaticAuthorizationPolicy|PermissionVoter|Bank'`). Sin migración.

## Dev Notes

### Contrato de diseño (fuente de verdad)

- **Épica** `_bmad-output/planning-artifacts/epics-rbac-authorization-model.md` — **FR5** (constantes por módulo → RM-3 `BankPermission`), **FR6** (gateo de rutas de Bank), **NFR1/SI-9** (OCP additive-only), **NFR6** (errores por contrato), **NFR9** (transport independence), §"Story RM-3 (PR-3)" (6 AC BDD), pre-mortem **R1** (backfill) y **R5** (401/403).
- **Addendum** `_bmad-output/planning-artifacts/arch-addendum-rbac-authorization-model.md` — §"Localización de decisiones por PR" (PR-3: constantes `BankPermission` + `#[IsGranted]` retirando el catch-all + backfill de rol a Alice/bootstrap); **SI-9** (recurso nuevo = additive-only); DAG (PR-3 sólo depende de PR-1/core, ya en `main`).
- **ADR** `docs/adr/rbac-authorization-model.md` — **D2** (recurso = objeto de negocio gobernable, nunca ruta/contexto), **D3** (acción = capacidad; CRUD semilla; domain-ops como `bank.close` son ejemplos del *modelo*, no obligan a que todo recurso los tenga), **D5** (constantes por módulo + política tier declarativa), **D9** (puerta row-level abierta sin construir).

> **Corrección de rutas respecto al ADR/épica/addendum:** el núcleo RBAC se **relocalizó** de `Backoffice/Identity/Infrastructure/Security` → **`Iam/Identity/Infrastructure/Security`** en el PR #458 (promoción de Identity a `Iam` top-level), **posterior** a la redacción del ADR. Los documentos de diseño citan la ruta vieja; las rutas **vivas** son las de abajo. RM-3 **no** edita el núcleo (es additive-only): sólo lo referencia.

### Estado actual del código (leído — no reinventar)

**Núcleo RBAC (todo en `api/src/Iam/Identity/Infrastructure/Security/`, ya en `main` vía RM-1 #456 + relocación #458):**

- `Permission.php` — VO `final readonly` `(resource, action)` renderizado `"<resource>.<action>"`. `fromString()` / `isWellFormed()`; segmentos `/^[A-Za-z][A-Za-z0-9]*$/`. `bank.read/write/delete` pasan. **No hay entidad/tabla.**
- `PermissionVoter.php` — `extends Voter`; `supports()` sii `Permission::isWellFormed($attribute)`; `bareRoleTokens()` hace strip de `ROLE_`; delega en `AuthorizationPolicy::permits()`; **acepta `subject:` pero no lo lee**. Abstiene sobre `ROLE_*`/`IS_AUTHENTICATED_*`/`PUBLIC_ACCESS` (van a los voters nativos). **RM-3 no lo toca.**
- `AuthorizationPolicy.php` (puerto) + `StaticAuthorizationPolicy.php` (impl `final readonly`) — la política como **datos**:
  - `TIER_VERBS = [VIEWER→['read'], EDITOR→['read','write'], MANAGER→['read','write','delete'], ADMIN→['*']]` (`WILDCARD='*'`).
  - `EXPLICIT_GRANTS = ['auditTrail.read' → [Role::AUDIT_READER->value]]` (sólo domain-ops/lecturas sensibles).
  - `TIER_OPT_OUT = ['auditTrail']`.
  - Resolución `permits()`: (1) ADMIN wildcard → true; (2) tier si `resource ∉ TIER_OPT_OUT` y `action ∈` verbos del rol; (3) `array_intersect` con `EXPLICIT_GRANTS[permiso]`. **RM-3 no la edita** (read/write/delete son tier; sin `close` no hay explicitGrants).
- `Role.php` (`api/src/Iam/Identity/Domain/Enum/Role.php`) — enum `VIEWER/EDITOR/MANAGER/ADMIN/AUDIT_READER`, `->value` sin prefijo `ROLE_`. **RM-3 no añade rol** (MANAGER cubre read+write+delete).
- `SecurityUser.php` — `getRoles()` antepone `ROLE_`. Wiring: `services.yaml` autowire/autoconfigure (el voter se auto-taggea `security.voter`; el puerto se auto-bindea a la impl única). **RM-3 no toca wiring.**
- Tripwire `api/tests/Unit/Iam/Identity/Infrastructure/Security/StaticAuthorizationPolicyIsDataOnlyTest.php` — tokeniza cada `const` de la política y falla ante control-flow/closure/call. Como RM-3 **no** edita la política, queda intacto.

**Superficie HTTP de `Bank` (todo en `api/src/Backoffice/Bank/Infrastructure/Controller/`, prefijo `/api/v1/backoffice`):**

| Controlador | Método + ruta | Permiso RM-3 |
|---|---|---|
| `BankSearchController` | `GET /banks` | `bank.read` |
| `BankGetController` | `GET /banks/{id}` | `bank.read` |
| `BankCountController` | `GET /banks/count` (priority 10) | `bank.read` (corrige docblock stale) |
| `BankRealtimeAuthorizeController` | `GET /banks/realtime/authorize` | `bank.read` |
| `BankPostController` | `POST /banks` | `bank.write` |
| `BankPutController` | `PUT /banks/{id}` | `bank.write` |
| `BankDeleteController` | `DELETE /banks/{id}` | `bank.delete` |

**Ninguno lleva `#[IsGranted]` hoy** (grep confirmado: 0 matches en `Backoffice/Bank`). Su única puerta es el catch-all `- { path: '^/api', roles: IS_AUTHENTICATED_FULLY }` (`security.yaml` L45). No hay acción `close`/status en `Bank`.

**Precedente RM-5 (audit, `main`) — el patrón exacto a espejar:**

- `api/src/Backoffice/Audit/Infrastructure/Controller/{AuditTimelineSearchController,AuditEventDetailController}.php` L29 → `#[IsGranted('auditTrail.read')]` (import `Symfony\Component\Security\Http\Attribute\IsGranted`). **Audit usó literal inline, sin clase de constantes** (RM-3 diverge conscientemente — ver Decisión-1).
- Fixtures: `api/tests/DataFixtures/Fixtures/User.yaml` (`user_alice`=`['AUDIT_READER']`, `user_mallory`=`[]`, `user_trent`=`['MANAGER']`), factory `UserFixtureFactory::create(id,email,pass,roles)`.
- Behat: `api/features/backoffice/audit/access_control.feature` (401 anon / 403 role-less / 403 tier-genérico) + `api/tests/Behat/Context/SecurityContext.php` (`authenticateDefaultUser` loguea Alice salvo `@anonymous`; steps mallory/trent).
- Funcional wired: `api/tests/Functional/Iam/Identity/Infrastructure/Security/PermissionVoterAccessDecisionTest.php` (**ya referencia `bank.read`/`bank.write`** — plantilla lista).
- Unit: `api/tests/Unit/Iam/Identity/Infrastructure/Security/StaticAuthorizationPolicyTest.php`.

### Decisiones de diseño y gotchas (previenen retrabajo)

1. **`BankPermission` como clase de constantes (vs literal inline de audit) — divergencia argumentada.** *Principio (FR5/D5):* el módulo declara su vocabulario de permisos en su borde. *Objetivo:* **typo-safety** — `Permission` es case-sensitive y un literal mal escrito (`'bank.raed'`) no falla en compilación ni en PHPStan; falla sólo en request-time como una denegación silenciosa. Con `#[IsGranted(BankPermission::READ)]` (una constante **sí** es argumento válido de atributo) el call-site queda verificado y hay una única fuente del string. *Coste / alternativa descartada:* el literal inline (lo que hizo audit) es 0-código pero reparte el string por 7 controladores sin red. La Regla de Tres se cumple pronto: RM-4 añade `BankAccountPermission` → 2 clases → es la **convención de módulo**, no una abstracción especulativa. **La AC lo exige** (FR5 asigna `BankPermission` a RM-3); esta nota documenta que la divergencia del precedente audit es deliberada.
2. **RM-3 no toca la política — y eso es la prueba OCP.** Al caer `bank.close` (Sergio: un banco no se cierra), los tres verbos restantes son tier puro → **0 filas** en `StaticAuthorizationPolicy`. Es exactamente el objetivo del addendum §"Slice de validación": «añadir un recurso sólo-CRUD = 0 filas de política». El primer tightening real lo demuestra en su forma más fuerte. **No** añadir `bank` a `TIER_OPT_OUT` (eso le quitaría el auto-grant por tier — es lo que audit necesita por ser lectura sensible, Bank **no**).
3. **`security.yaml` no se toca.** El catch-all `^/api → IS_AUTHENTICATED_FULLY` **no** se borra: es el default-deny del resto de `/api` y produce el **401 anónimo** antes de que corra el voter. El `#[IsGranted]` capa el **403 autenticado-sin-permiso** encima. No añadir filas `access_control` bank-específicas (recurso ≠ ruta, NFR9). El único matiz: `BankCountController` tenía un docblock «consciously unauthenticated» que ya era **falso** (no hay regla `PUBLIC_ACCESS` para `/banks/count`); RM-3 lo gatea `bank.read` y corrige el docblock.
4. **Backfill = runbook, no migración (greenfield).** RM-5 no necesitó migración de datos; los roles se asignan a usuarios directamente. En prod greenfield el único principal es el `ADMIN` de bootstrap (wildcard → cubre todo). El riesgo R1 (un autenticado sin tier pierde acceso) se materializa sólo si existieran no-ADMIN preexistentes → el runbook les asigna un tier antes del merge. En dev/test el «backfill» es dar `MANAGER` a Alice y al user del trait funcional. **No** crear una migración Doctrine (nada toca esquema — AC8).
5. **401 vs 403 — dos capas, dos orígenes.** El firewall (autenticación) da 401 vía `UnauthenticatedAccessListener`; el `PermissionVoter` (autorización) da 403. Ambos por RFC 9457, **sin marker nuevo** (`php.lint.error-contract` verde, `docs/api-error-contract.md` intacto → NFR26 no se dispara). No introducir `JsonResponse` manual.
6. **El engine se prueba directo; el gate end-to-end es Behat.** La prueba primaria del tier↔verbo es el unit de política (`StaticAuthorizationPolicyTest`) + el funcional wired (`PermissionVoterAccessDecisionTest`); el `access_control.feature` es el gate HTTP 401/403/200 (mirror de audit). Convención del repo: no probar reglas de política sólo vía HTTP.

### Fuera de alcance (NO hacer en RM-3)

- ❌ `bank.close` / cualquier acción de estado en `Bank` — el agregado no tiene estado; «close» es de `BankAccount` (decisión de Sergio).
- ❌ Gatear `BankAccount` (colección + anidada `GET /banks/{id}/accounts`) ni `BankAccountPermission` → **RM-4** (depende de RM-2).
- ❌ Editar `StaticAuthorizationPolicy` / `PermissionVoter` / VO `Permission` / puerto (SI-9: additive-only; tocarlos rompería el OCP).
- ❌ Tocar `security.yaml` (el catch-all se conserva) ni añadir migración.
- ❌ Evaluar `subject:` / row-level scope → RM-6 / capacidad futura (2º tripwire).
- ❌ Gate OCP ejecutable (test de arquitectura que congela el core-set) → RM-6.

### Testing (obligatorio; convenciones del repo)

**Añadir (prueba del AC):**
- `StaticAuthorizationPolicyTest` — `bank.read/write/delete` por tier + aserción «0 filas explicitGrants para bank». *(AC3/AC4)*
- `PermissionVoterAccessDecisionTest` — extender con el tier de `bank.delete` (ya cubre read/write). *(AC4/AC5)*
- `api/features/backoffice/bank/access_control.feature` — 401 anon / 403 role-less (mallory) / 2xx tier concedido, por verbo. *(AC5/AC7)*

**Actualizar (rotura garantizada por el gateo):**
- `api/tests/DataFixtures/Fixtures/User.yaml` — Alice gana `MANAGER`.
- `api/tests/Functional/AuthenticatesFunctionalRequests.php` — user del trait gana `MANAGER` + reconciliación; docblock corregido.

**Mantener verde (regresión — deben seguir 200 con Alice=MANAGER):**
- Behat bank: `search/get/update/delete/create/count/dispatch_event` (`api/features/backoffice/bank/`).
- Funcional bank HTTP (usan el trait): `BankSearchCursorFunctionalTest`, `BankDetailResponseGoldenFunctionalTest`, `BankLogoMultipartFunctionalTest`, `BankStoredObjectMultipartFunctionalTest`.
- Tripwire `StaticAuthorizationPolicyIsDataOnlyTest` (intacto — no se edita política).

Correr: `make php.unit c='--filter "StaticAuthorizationPolicy|PermissionVoter"'`, funcional bank, `make php.behat` de bank; `make php.stan` por fichero; `make php.quality` al cerrar.

### Project Structure Notes

- Ficheros nuevos: **1** (`api/src/Backoffice/Bank/Infrastructure/Security/BankPermission.php`) + **1** feature (`bank/access_control.feature`). Resto son ediciones puntuales (7 controladores, 2 fixtures/trait, 2-3 tests, `PRODUCTION_SECURITY_CHECKLIST.md`).
- Namespace nuevo: `Erpify\Backoffice\Bank\Infrastructure\Security` — dentro del boundary `Backoffice/Bank` (deptrac aísla `Bank ⊥ BankAccount`). **Verificar** si el bloque `Backoffice/Bank` de `deptrac.yaml` cubre `Infrastructure/*` o necesita el ancla `Infrastructure/Security` (mirror del patrón por-módulo). No colocar en `Iam`/`Shared`.
- Sin nuevos directorios en `Iam`. Sin YAML de rutas nuevo. Sin serializer groups. Sin migración.

### References

- `_bmad-output/planning-artifacts/epics-rbac-authorization-model.md` — FR5, FR6, NFR1/6/9, §"Story RM-3 (PR-3)", R1/R5, §"FR Coverage Map".
- `_bmad-output/planning-artifacts/arch-addendum-rbac-authorization-model.md` — §"Localización de decisiones por PR" (PR-3), SI-9, §"Slice de validación" (0 filas de política), DAG.
- `docs/adr/rbac-authorization-model.md` — D2, D3, D5, D9, §"Acceptance criterion (OCP) and the two tripwires".
- `docs/api-error-contract.md` — 401/403 por RFC 9457 sin marker nuevo (NFR26 no se dispara).
- `docs/project-context.md` — PHP 8.5 `strict_types`, PHPStan `level: max`, AAA + fakes in-tree, `make` desde la raíz.
- Código en vigor: `api/src/Iam/Identity/Infrastructure/Security/{Permission,PermissionVoter,AuthorizationPolicy,StaticAuthorizationPolicy,SecurityUser}.php`, `api/src/Iam/Identity/Domain/Enum/Role.php`; controladores `api/src/Backoffice/Bank/Infrastructure/Controller/Bank{Search,Get,Count,RealtimeAuthorize,Post,Put,Delete}Controller.php`; `api/config/packages/security.yaml`; precedente `api/src/Backoffice/Audit/Infrastructure/Controller/*` + `api/features/backoffice/audit/access_control.feature`.

### Previous Story Intelligence (RM-1 #456, RM-5 #459 — ambas en `main`)

- **RM-1** aterrizó el núcleo (VO `Permission`, puerto + `StaticAuthorizationPolicy`, `PermissionVoter`, `Role` +tiers) **additive, sin gatear rutas**. RM-3 es el primer consumidor real del core que **cambia comportamiento**.
- **RM-5** es el precedente 1:1 de gateo (audit): swap a `#[IsGranted('auditTrail.read')]` + 4 capas de test (Behat access_control, funcional wired, unit policy, y sin tocar el tripwire) + backfill sólo por fixtures (`trent`). RM-3 replica esas 4 capas para bank. **Diferencia clave:** audit *swapeó* un atributo existente (`ROLE_AUDIT_READER`); Bank **no tenía ninguno** → RM-3 **añade** la capa 403 sobre el 401 del firewall.
- Convención confirmada: `make php.quality` corre rector/cs-fixer/phpmd/deptrac — correrlo antes de declarar hecho.

### Git Intelligence

- Rama: `feat/backoffice-bank-rbac-gate-xnh0` (worktree), base `main` en `a7d39f06` (incluye RM-1 #456, RM-2 #457, RM-5 #459, relocación Iam #458). Orden de merge del epic: RM-3 es el 4º; no depende de RM-4.
- Estilo a seguir: los controladores audit gateados (`#[IsGranted(...)]` + import Symfony Security) y el `final readonly` con docblock-porqué del core RBAC.

### Project Context Reference

`docs/project-context.md` obligatorio antes de codificar: `declare(strict_types=1)`, tipado total, `enum`/`final`/`readonly`, excepciones para error-flow, **sin framework en `Domain/`** (el cambio es todo `Infrastructure/` + fixtures/tests, OK), PHPStan `level: max` única puerta de tipos, AAA + fakes in-tree, `make` desde la raíz. Reglas de comentarios: sólo el *por qué* no obvio; **barrer IDs de story/FR/NFR y comentarios change-relative del diff de código antes del commit final** (la trazabilidad vive en el PR/spec, no en el código).

### Questions for Sergio (resueltas / por confirmar durante dev; recomendación entre corchetes)

1. **`bank.close`** — RESUELTA: **fuera** (un banco no se cierra, se elimina). Sin `CLOSE`, sin `explicitGrants` → RM-3 = 0 filas de política.
2. **`banks/count` y `banks/realtime/authorize`** — RESUELTA: **ambas `bank.read`** (+ corregir el docblock stale de count).
3. **`BankPermission` clase vs literal** — [**clase de constantes**, por FR5 + typo-safety; divergencia consciente del literal de audit]. ¿OK? (Ver Decisión-1.)
4. **Ubicación del runbook de backfill** — [cuerpo del PR + 1 línea en `PRODUCTION_SECURITY_CHECKLIST.md`, por ser auth-sensitive]. ¿Preferencia?

## Dev Agent Record

### Agent Model Used

claude-opus-4-8[1m] (Claude Opus 4.8, 1M context) — dev-story workflow (create-story + implementación en 1 PR).

### Debug Log References

- `make php.behat.install` — la tooling Behat aislada faltaba en el worktree; el bootstrap de PHPStan la requiere (`api/tools/behat/vendor/autoload.php`).
- `make php.stan` — **OK, 0 errores** (803 ficheros).
- `make php.quality` — **EXIT=0, 0 violations** (deptrac 0 violations / 0 uncovered / no new layer; phpmd; cs-fixer normalizó `BankPermission`; rector; gherkinlint 38 features; error-contract; bounded-context; event-bus). Sin cambio en `docs/api-error-contract.md` (no se añadió/cambió marker → NFR26 no se dispara).
- PHPUnit completo — **1571 tests / 7196 assertions verde** (3 skipped pre-existentes). Subset RBAC+Bank: 214.
- Behat completo — **219 escenarios / 2141 steps verde**. Bank: **101** (incl. los 7 del nuevo `access_control.feature`; budgets de create/update/delete intactos). Audit: 20 (Alice con `AUDIT_READER`+`MANAGER` sigue leyendo el trail; el opt-out se prueba con `trent`).

### Completion Notes List

- **Corrección de rutas:** el núcleo RBAC vive en `Iam/Identity/Infrastructure/Security` (no `Backoffice/Identity` como dice el ADR/épica — relocación PR #458 posterior). RM-3 no toca el núcleo (additive-only, SI-9): sólo lo referencia.
- **`bank.close` fuera (decisión de Sergio: «un banco no se cierra, se elimina»).** El agregado `Bank` no tiene estado; «close» es de `BankAccount`. Consecuencia: sin `CLOSE` ni `explicitGrants['bank.close']`, RM-3 es un slice **sólo-CRUD con 0 filas de política** — la forma más fuerte del objetivo OCP (addendum §"Slice de validación"), lograda por el primer tightening real. `read/write/delete` se resuelven por `TIER_VERBS`; `StaticAuthorizationPolicy` **no se editó** y el tripwire `StaticAuthorizationPolicyIsDataOnlyTest` quedó verde sin tocarse.
- **`banks/count` y `banks/realtime/authorize` → `bank.read`** (decisión de Sergio). Boy-scout: corregido el docblock stale «consciously unauthenticated» de `BankCountController` (ya era falso — estaba tras el catch-all).
- **`BankPermission` = primera clase de constantes de permiso del codebase.** Audit (RM-5) usó literales inline; RM-3 crea la clase por FR5/D5 (el módulo declara su vocabulario) + typo-safety (`#[IsGranted(BankPermission::READ)]` es compiler-checked; un literal case-sensitive mal escrito falla sólo en request-time). Divergencia consciente; RM-4 continúa el patrón con `BankAccountPermission` (Regla de Tres por módulo).
- **`security.yaml` intacto:** el catch-all `^/api → IS_AUTHENTICATED_FULLY` se conserva (default-deny del resto de `/api` y origen del 401 anónimo); el `#[IsGranted]` capa el 403 encima. Sin filas `access_control` bank-específicas (recurso ≠ ruta, NFR9). Sin migración.
- **Backfill:** dev/test = `MANAGER` a Alice (sesión Behat por defecto) y al usuario del trait funcional; reconciliación del trait ampliada a ambos roles. Prod = runbook en `PRODUCTION_SECURITY_CHECKLIST.md` (greenfield: sólo el `ADMIN` de bootstrap, wildcard → sin migración de datos).
- **Tests (minimum-code):** la conducta tier de `bank.read/write/delete` ya está probada por `StaticAuthorizationPolicyTest` + `PermissionVoterAccessDecisionTest` (RM-1, con `bank` como recurso ilustrativo); RM-3 **no duplica** y aporta el test nuevo genuino — `bank/access_control.feature` (matriz HTTP 401/403/200).
- **Self-review de seguridad:** RM-3 **es** el endurecimiento (retira el catch-all como única puerta de bank). Sin SQL nuevo, sin migración, sin secretos, sin cambio de serializer/setters, sin marker de error nuevo (401/403 por el pipeline RFC 9457). **Matiz de orden anotado (candidato a follow-up):** `#[IsGranted]` es un gate a nivel controlador/argumentos; por el orden de resolución de Symfony, `MapRequestPayload` corre **antes**, así que en rutas de escritura un autenticado-sin-permiso puede recibir un 422 (validación / prueba de unicidad de nombre) antes del 403 — baja severidad (revela sólo veredictos de validación / existencia de nombre de banco, ningún dato de banco ni PII; `Bank` es catálogo). El anónimo se detiene en el firewall (401) antes de tocar el payload. Cerrarlo requeriría gatear en `access_control` por ruta (acopla ruta→permiso, viola NFR9/D2), por lo que se deja como nota, no se fuerza.

### File List

- `api/src/Backoffice/Bank/Infrastructure/Security/BankPermission.php` (new — constantes `READ/WRITE/DELETE`, sin `CLOSE`)
- `api/src/Backoffice/Bank/Infrastructure/Controller/BankSearchController.php` (modified — `#[IsGranted(BankPermission::READ)]`)
- `api/src/Backoffice/Bank/Infrastructure/Controller/BankGetController.php` (modified — `bank.read`)
- `api/src/Backoffice/Bank/Infrastructure/Controller/BankCountController.php` (modified — `bank.read` + docblock stale corregido)
- `api/src/Backoffice/Bank/Infrastructure/Controller/BankRealtimeAuthorizeController.php` (modified — `bank.read` a nivel método)
- `api/src/Backoffice/Bank/Infrastructure/Controller/BankPostController.php` (modified — `bank.write`)
- `api/src/Backoffice/Bank/Infrastructure/Controller/BankPutController.php` (modified — `bank.write`)
- `api/src/Backoffice/Bank/Infrastructure/Controller/BankDeleteController.php` (modified — `bank.delete`)
- `api/tests/DataFixtures/Fixtures/User.yaml` (modified — Alice gana `MANAGER`)
- `api/tests/Functional/AuthenticatesFunctionalRequests.php` (modified — trait gana `MANAGER` + reconciliación de ambos roles + docblock)
- `api/features/backoffice/bank/access_control.feature` (new — matriz 401/403/200 por verbo)
- `PRODUCTION_SECURITY_CHECKLIST.md` (modified — §Access-control: gateo de rutas Bank + runbook de backfill)
- `_bmad-output/implementation-artifacts/rm-3-gateo-rutas-bank-tightening.md` (new — story)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (modified — RM-3 → in-progress → review)

### Change Log

- `BankPermission` (new): vocabulario `bank.{read,write,delete}` co-localizado en el borde de `Backoffice/Bank`.
- 7 controladores de `Bank` gateados con `#[IsGranted(BankPermission::…)]`; docblock stale de `BankCountController` corregido.
- Fixtures/trait: Alice y el usuario funcional reciben `MANAGER` (backfill dev/test); reconciliación del trait ampliada.
- `PRODUCTION_SECURITY_CHECKLIST.md`: gateo de rutas Bank + runbook de backfill prod (sin migración).
- Test nuevo: `bank/access_control.feature` (401/403/200). Sin duplicar la cobertura tier de RM-1.
- Sin cambios en `StaticAuthorizationPolicy` / `PermissionVoter` / `Permission` / `security.yaml` / esquema (0 filas de política, additive-only).

### Review Findings

_Code review (bmad-code-review) del PR #463 — 2026-07-08. 3 capas adversariales (Blind Hunter, Edge Case Hunter, Acceptance Auditor); sin capas fallidas. El Acceptance Auditor verificó las **8 AC como MET** y la lista «Fuera de alcance» limpia (sin `bank.close`, sin gatear BankAccount, sin tocar política/voter/VO/`security.yaml`). Los matices siguientes no rompen ninguna AC._

- [x] [Review][Defer] 422 puede preceder al 403 en rutas de escritura — `#[IsGranted]` resuelve DESPUÉS de `#[MapRequestPayload]`/`#[MapQueryString]` (ambos en `kernel.controller_arguments`, el resolver primero), así que un autenticado-sin-permiso con body inválido recibe 422 (contrato de validación) antes del 403. Auto-disclosed en Completion Notes; el feature evita el path enviando body válido. Bajo impacto (Bank es catálogo, sin PII; la 409 de unicidad de nombre vive post-gate, no filtra). Fijarlo «bien» acoplaría ruta→permiso (viola NFR9/D2). El anónimo se detiene en el firewall (401) antes de tocar el payload. (blind+edge+auditor) [api/src/Backoffice/Bank/Infrastructure/Controller/BankPostController.php, BankPutController.php]
- [x] [Review][Patch] `BankPermission` clase de constantes vs `enum` backed — `docs/project-context.md` prefiere «enums (backed o puros) sobre constantes string para sets cerrados». La Decisión-1 de la spec sólo argumenta contra el literal inline (audit), no contra el enum: un `enum BankPermission: string` da la misma typo-safety y sigue siendo usable en el atributo vía `#[IsGranted(BankPermission::READ->value)]`. Defensible (el VO `Permission` y `#[IsGranted]` consumen strings; sin precedente de permission-enum en el repo), pero infra-argumentado frente a la regla que nombra el enum. (auditor) [api/src/Backoffice/Bank/Infrastructure/Security/BankPermission.php:21]
- [x] [Review][Patch] El nuevo `access_control.feature` no fija el binding controlador→constante — los únicos principales tier ejercitados contra rutas bank son Alice=`MANAGER` (todos los verbos) y mallory=role-less (ninguno); no hay `VIEWER`/`EDITOR`. Una mala anotación a un permiso más débil (p.ej. `BankDeleteController`→`READ`) pasaría CI en verde = escalada de privilegios silenciosa (un VIEWER podría borrar). El path positivo sólo asegura READ→200; no hay 2xx concedido de WRITE/DELETE en este feature, y `bank.delete` no tiene aserción funcional wired (sólo su hermano `write`; delete cubierto e2e por Behat). RM-1 fija la escalera tier↔verbo a nivel engine, pero nadie fija el binding. Decidir: endurecer con fixture VIEWER/EDITOR + escenarios por verbo, o aceptar la cobertura minimum-code. (blind+edge+auditor) [api/features/backoffice/bank/access_control.feature]
- [ ] [Review][Patch] PUT sin cobertura de access-control en el feature nuevo — GET/POST/DELETE tienen escenarios anónimo→401 y role-less→403, pero PUT no tiene ninguno; el único PUT ejercitado es `update.feature` como Alice=MANAGER (→200 con o sin gate), así que quitar `#[IsGranted(BankPermission::WRITE)]` de `BankPutController` no rompería la suite. Añadir PUT anónimo→401 y role-less→403 (espejo de POST, body válido). (edge+blind) [api/features/backoffice/bank/access_control.feature]
- [x] [Review][Defer] Verificar que la PWA maneja un 403 de `/banks/realtime/authorize` — la ruta pasó de «cualquier autenticado» a exigir `bank.read`; un autenticado sin tier de negocio (p.ej. sólo `AUDIT_READER`) ahora recibe 403 en vez de 204+cookie, y el `EventSource` nunca se autoriza. Fuera del diff (PWA no verificada); enmascarado en greenfield (sólo el `ADMIN` wildcard existe hoy). (edge) [api/src/Backoffice/Bank/Infrastructure/Controller/BankRealtimeAuthorizeController.php:29] — deferred, follow-up cross-deployable (PWA)

**Resolución del triaje (2026-07-08, Sergio):**

- **D1 (422 antes de 403)** → **diferido** a follow-up (registrado en `deferred-work.md`). Motivo: mitigación posible sin acoplar ruta→permiso; revisar en RM-6 o al endurecer las rutas de escritura. Bank es catálogo sin PII, la 409 de unicidad vive post-gate, el anónimo cae en el firewall (401) — riesgo bajo.
- **D2 (`BankPermission` clase vs enum)** → **mantener la clase de constantes** (consenso unánime de Winston/arquitecto y Amelia/dev: el enum es un constant-expression válido —confirmado en `StaticAuthorizationPolicy.php` con `Role::VIEWER->value`— pero no aporta tipo consumido; el VO `Permission` ya cubre el value-type, y el enum añade `->value`×7 + un footgun cazado por PHPStan). Acción: `patch` de docs — añadir un carve-out de 1 línea al ADR D5 (`docs/adr/rbac-authorization-model.md`) documentando que un vocabulario de permisos consumido por atributo-string es la excepción a la regla «enum sobre constantes».
- **D3 (binding no fijado por test HTTP)** → **endurecer ahora**: fixture VIEWER/EDITOR + escenarios granted-write / denied-delete por verbo, para que una mala anotación a un permiso más débil rompa CI. Se convierte en `patch`.
- **P1 (PUT sin cobertura)** → `patch` (independiente; espejo de POST).

**Patches aplicados (2026-07-08, sin commitear en el worktree):**

- **P1** — `access_control.feature`: PUT anónimo → 401 y PUT role-less → 403 (espejo de POST, body válido).
- **D3** — fixtures `user_victor` (VIEWER) + `user_edith` (EDITOR) en `User.yaml`; steps Behat «I am logged in as a viewer / an editor» en `SecurityContext.php`; escenarios que fijan el binding: VIEWER lee (200), VIEWER crea/edita → 403 (write ≠ read), EDITOR borra → 403 (delete ≠ write). Una mala anotación a un permiso más débil ahora rompe CI.
- **D2** — carve-out de 1 párrafo en ADR D5 (`docs/adr/rbac-authorization-model.md`): el vocabulario de permisos por módulo es clase de constantes por diseño (excepción argumentada a la regla enum). `BankPermission` **sin cambio de código**.
- **Gates:** `make php.stan` 0 errores (803 ficheros) · `make php.behat` **225/225** escenarios (2169 steps) · `make php.quality` **EXIT=0** (deptrac 0 violations; cs-fixer/rector sin mutaciones).
