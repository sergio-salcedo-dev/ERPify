---
baseline_commit: 1075319b3b17d0f061f43040a39845ef5a893b65
---

# Story RM-4 (PR-4): Gateo de `BankAccount` — colección + ruta anidada (depende de RM-2)

Status: done

Epic: `rbac-authorization-model` · Orden de merge: **5º y último de comportamiento** (RM-1 → RM-2 → RM-5 → RM-3 → **RM-4**) · Slice: `Backoffice/BankAccount` — **segundo y último tightening real** del epic. Extiende el modelo `Permission = (resource, action)` (ya en `main`) a las **8** rutas de `BankAccount`, incluida la **anidada** `GET /banks/{id}/accounts`, y añade la **primera fila de política de dominio de un slice de negocio** (`explicitGrants['bankAccount.changeStatus']`). **Depende de RM-2 como gate duro** (ya en `main` vía #457): sin el discriminante base-query del cursor keyset, el par colección↔anidada sería un bypass de privilege-scope. RM-3 (#463, ya en `main`) aporta los fixtures tier y el backfill que RM-4 **reutiliza sin añadir ninguno**.

## Story

Como operador del backoffice,
quiero que las acciones sobre cuentas bancarias exijan el permiso correspondiente por recurso/acción en **ambas** rutas (colección y anidada) y que el cambio de estado sea una operación explícitamente concedida,
para que el acceso a cuentas sea coherente, no se pueda saltar por la ruta anidada, y sólo un rol autorizado pueda cambiar el estado de una cuenta.

## Acceptance Criteria

1. **Constantes `BankAccountPermission` co-localizadas — con `CHANGE_STATUS`.** Existe `BankAccountPermission` en el borde de `Backoffice/BankAccount` (`Infrastructure/Security/`) con `READ/WRITE/DELETE/CHANGE_STATUS = 'bankAccount.{read,write,delete,changeStatus}'`. Es la **2ª clase de constantes de permiso** del repo (tras `BankPermission`) → cierra la **Regla de Tres por módulo** y ratifica la convención; el carve-out «clase de constantes, no enum» **ya vive en el ADR D5** (`docs/adr/rbac-authorization-model.md`, en `main`), que nombra explícitamente `BankAccountPermission` — **no** se re-edita el ADR. *(FR5, FR7, D2, D5)*
2. **Las 8 rutas de `BankAccount` gateadas — mismo permiso para colección y anidada.** Cada controlador lleva `#[IsGranted(BankAccountPermission::<X>)]`: `READ` → `BankAccountSearchCollectionController` (`GET /bank-accounts`), **`BankAccountSearchController` (`GET /banks/{id}/accounts` — anidada)**, `BankAccountGetController` (`GET /bank-accounts/{id}`), `BankAccountRealtimeAuthorizeController` (`GET /bank-accounts/realtime/authorize`); `WRITE` → `BankAccountPostController` (`POST`), `BankAccountPutController` (`PUT`); `DELETE` → `BankAccountDeleteController` (`DELETE`); `CHANGE_STATUS` → `BankAccountPatchStatusController` (`PATCH /bank-accounts/{id}/status`). **El mismo `bankAccount.read` protege por igual la colección y la anidada** (recurso ≠ ruta; la URL bajo `/banks/` **no** convierte la cuenta en recurso `Bank`). `security.yaml` **no se toca**. *(FR7, D2, NFR9)*
3. **Una sola fila de política — `changeStatus` es domain-op, no verbo de tier.** `StaticAuthorizationPolicy.EXPLICIT_GRANTS` gana **exactamente una** entrada: `'bankAccount.changeStatus' => [Role::MANAGER->value]` (ADMIN concede vía `grantedToAdmin`/wildcard → **no** se lista, misma convención que `auditTrail.read`). `changeStatus` **no** está en ningún `TIER_VERBS` (que sólo tiene `read/write/delete`) → no se auto-concede por tier. `read/write/delete` **sí** quedan auto-cubiertos por `TIER_VERBS` (como Bank). `bankAccount` **NO** entra en `TIER_OPT_OUT` (no es lectura sensible; sólo `changeStatus` es especial). El tripwire `StaticAuthorizationPolicyIsDataOnlyTest` queda **verde**: la fila nueva es literal puro (`Role::MANAGER->value`, ya probado data-only). *(FR7, FR3.2, D3, D5, NFR1, NFR2, SI-8, SI-9)*
4. **Tier ↔ verbo + grant explícito (concede/deniega por rol).** `VIEWER`: lee (200, colección + anidada) y **deniega** (403) `write`, `delete`, `changeStatus`. `EDITOR`: escribe pero **deniega** `delete` y `changeStatus`. `MANAGER` (Alice, sesión por defecto): `read/write/delete` por tier **y** `changeStatus` por el grant explícito. `ADMIN`: todo por wildcard. **Clave:** `changeStatus` deniega a `EDITOR` pese a tener `write` — fija que el cambio de estado exige el grant explícito, no el tier de escritura. *(FR7, FR3.1, FR3.2)*
5. **Gate duro RM-2 en `main` — el cursor no cruza rutas.** Un cursor keyset acuñado en `GET /bank-accounts` presentado en `GET /banks/{id}/accounts` (distinto `WHERE`) se rechaza **`422 invalid-cursor`** por el pipeline RFC 9457. RM-2 (#457) **ya está en `main`** y aporta el discriminante base-query + los escenarios replay cross-ruta en las features `bank_account`; RM-4 **verifica** que esa cobertura sigue verde (gate duro, no nota). **RM-4 no habría podido mergearse sin RM-2.** *(FR7 dep. FR9, R2, NFR3)*
6. **401 vs 403 por RFC 9457.** Anónimo a una ruta `BankAccount` gateada → **401** (firewall, `type: unauthenticated`); autenticado-sin-permiso → **403** (`PermissionVoter`, `type: forbidden`, `application/problem+json`); ambos **sin `JsonResponse` manual y sin marker nuevo** (`docs/api-error-contract.md` intacto → NFR26 no se dispara). *(NFR6, R5)*
7. **Backfill reutilizado (cero fixtures nuevos) + feature de access-control nueva.** El backfill tier de RM-3 (Alice=`MANAGER`, `mallory`=role-less, `user_victor`=`VIEWER`, `user_edith`=`EDITOR`, `user_trent`=`MANAGER`) y los steps `SecurityContext` (`viewer`/`editor`/`user without the audit-reader role`) **ya están en `main`** → RM-4 **no añade fixtures ni steps**. Nuevo `api/features/backoffice/bank_account/access_control.feature` (espejo de `bank/access_control.feature`) que **además** cubre la ruta **anidada** y el **binding de `changeStatus`** (MANAGER concede / EDITOR deniega). *(FR7, R1, NFR9)*
8. **Gates verdes, sin migración.** `make php.stan` por fichero + `make php.quality` (deptrac —el collector `Backoffice.BankAccount.Infrastructure` es prefijo de directorio → cubre `Infrastructure/Security` **sin cambio** en `deptrac.yaml`—, bounded-context, error-contract, phpmd, rector, cs-fixer, gherkinlint) EXIT=0; Behat `bank_account` + funcional RBAC verdes; features `bank_account` existentes siguen 200 con Alice=`MANAGER` (incl. `status.feature`). **Sin migración** (nada toca esquema). Sin edición de `docs/api-error-contract.md`. *(NFR5, NFR6)*

## Tasks / Subtasks

- [x] **T1 · Constantes `BankAccountPermission`** (AC: 1, 8)
  - [x] Crear `api/src/Backoffice/BankAccount/Infrastructure/Security/BankAccountPermission.php`: `final class BankAccountPermission` con `public const string READ = 'bankAccount.read'; WRITE = 'bankAccount.write'; DELETE = 'bankAccount.delete'; CHANGE_STATUS = 'bankAccount.changeStatus';`. Docblock breve con el *por qué* (typo-safety en 8 call-sites + FR5/D5 «el módulo declara su vocabulario»), espejo del de `BankPermission`. **No** enum (carve-out ADR D5 ya en `main`).
  - [x] Verificar deptrac: collector `Backoffice.BankAccount.Infrastructure` = `src/Backoffice/BankAccount/Infrastructure/.*` (prefijo) → cubre `Infrastructure/Security` **sin cambio** en `deptrac.yaml` (confirmado L70-71). `make php.deptrac` = 0 violations.
- [x] **T2 · Gatear los 8 controladores** (AC: 2, 4, 6)
  - [x] `use Symfony\Component\Security\Http\Attribute\IsGranted;` + `#[IsGranted(BankAccountPermission::READ)]` (nivel clase) a `BankAccountSearchCollectionController`, `BankAccountSearchController` (**anidada**), `BankAccountGetController`.
  - [x] `BankAccountRealtimeAuthorizeController`: el `#[Route]` está a **nivel método** (`__invoke`) → poner `#[IsGranted(BankAccountPermission::READ)]` a **nivel método** (espejo de `BankRealtimeAuthorizeController` en RM-3).
  - [x] `#[IsGranted(BankAccountPermission::WRITE)]` a `BankAccountPostController`, `BankAccountPutController`.
  - [x] `#[IsGranted(BankAccountPermission::DELETE)]` a `BankAccountDeleteController`.
  - [x] `#[IsGranted(BankAccountPermission::CHANGE_STATUS)]` a `BankAccountPatchStatusController`.
  - [x] **No** tocar `api/config/packages/security.yaml`.
- [x] **T3 · Política: +1 fila `explicitGrants`** (AC: 3, 4)
  - [x] En `api/src/Iam/Identity/Infrastructure/Security/StaticAuthorizationPolicy.php`, añadir a `EXPLICIT_GRANTS`: `'bankAccount.changeStatus' => [Role::MANAGER->value]` (una línea; ADMIN implícito por wildcard, **no** listar). **No** tocar `TIER_VERBS` ni `TIER_OPT_OUT` (bankAccount read/write/delete son tier; bankAccount NO opta fuera). Docblock de la clase: la afirmación «the audit trail is the only explicit grant» ya no es cierta si existe → ajustar la prosa a «sensitive/domain-op grants» (boy-scout, sin IDs de story).
  - [x] Confirmar tripwire `StaticAuthorizationPolicyIsDataOnlyTest` **verde sin tocarse** (la fila es literal puro).
- [x] **T4 · Unit de política — matriz `changeStatus` + guard `tierOptOut`** (AC: 3, 4)
  - [x] En `api/tests/Unit/Iam/Identity/Infrastructure/Security/StaticAuthorizationPolicyTest.php`: `bankAccount.changeStatus` **concede** a `MANAGER` (explícito) y `ADMIN` (wildcard); **deniega** a `VIEWER` y `EDITOR` (no es verbo de tier, no listado). RED real doble: EDITOR-con-write denegado + MANAGER concedido.
  - [x] **Aserción dedicada `bankAccount ∉ TIER_OPT_OUT`** (no una nota al margen): fija el contraste tier-in vs opt-out con dos aserciones pareadas — `assertTrue(permits(VIEWER, 'bankAccount.read'))` (un VIEWER entra por tier) **frente a** `assertFalse(permits(VIEWER, 'auditTrail.read'))` (audit opta fuera del tier → no entra). Deja **cristalino** que uno se concede por tier y el otro no; es exactamente la regresión que alguien podría introducir en 6 meses metiendo `bankAccount` en `TIER_OPT_OUT` (le quitaría el auto-grant de lectura sin que nada más lo cazara).
- [x] **T5 · Funcional wired — `changeStatus` (obligatorio, no opcional)** (AC: 4, 6)
  - [x] En `api/tests/Functional/Iam/Identity/Infrastructure/Security/PermissionVoterAccessDecisionTest.php`: caso `bankAccount.changeStatus` — MANAGER concede / EDITOR deniega (pese a `IS_AUTHENTICATED_FULLY` + tier write). El comportamiento **genuinamente nuevo** de RM-4 no es el HTTP sino la cadena `PermissionVoter → AuthorizationPolicy → explicitGrants`; el funcional del voter caza una regresión de **wiring** que Behat también detectaría pero con mucho más coste de diagnóstico. **Se mantiene** (decisión de Sergio): `read/write/delete` ya cubiertos por el patrón `bank.*`, `changeStatus` es el eslabón que sólo este test wired ejercita de forma directa.
- [x] **T6 · Behat `bank_account/access_control.feature` (new)** (AC: 5, 6, 7)
  - [x] Nuevo `api/features/backoffice/bank_account/access_control.feature`, espejo de `api/features/backoffice/bank/access_control.feature`, cubriendo **por verbo**:
    - `@anonymous` → **401** (`type: unauthenticated`) en: GET colección (`/backoffice/bank-accounts`), **GET anidada (`/backoffice/banks/{id}/accounts`)**, POST, PUT, DELETE, **PATCH status**.
    - role-less (`I am logged in as a user without the audit-reader role`) → **403** (`type: forbidden`) en cada verbo (bodies **válidos** en write/patch para que responda el gate, no un 422).
    - `viewer` → **200** GET colección + **200 GET anidada**; **403** en POST/PUT/DELETE/PATCH-status.
    - `editor` → **403** DELETE; **403 PATCH-status** (fija que `changeStatus` exige grant explícito, no basta `write`).
    - concedido (Alice=`MANAGER` por defecto, sin step) → **200** GET colección; el path 2xx de `changeStatus` concedido se cubre en `status.feature` (Alice=MANAGER).
  - [x] Verificar/usar los UUID-fixture de cuentas existentes (mirar `bank_account/status.feature` y `get.feature` para ids sembrados válidos, y un `bankId` válido para la ruta anidada).
- [x] **T7 · Runbook prod (auth-sensitive)** (AC: 7)
  - [x] `PRODUCTION_SECURITY_CHECKLIST.md` §Access-control: una línea extendiendo el gateo a las rutas `BankAccount` (colección + anidada) y anotando que `bankAccount.changeStatus` exige `MANAGER`/`ADMIN` explícito. El backfill tier ya lo documentó RM-3 (greenfield: sólo el `ADMIN` de bootstrap, wildcard → sin migración de datos).
- [x] **T8 · Gate duro RM-2 + regresión** (AC: 5, 8)
  - [x] Confirmar que los escenarios replay cross-ruta de RM-2 en `bank_account` (cursor colección→anidada → 422) **siguen verdes**.
  - [x] Verificar que las features `bank_account` existentes (`search`, `search_collection`, `get`, `create`, `update`, `delete`, `status`, `audit`) siguen **200/2xx** con Alice=`MANAGER` (incl. `status.feature`: MANAGER ahora tiene `changeStatus` por el grant explícito → sin regresión). Budgets de query intactos.
- [x] **T9 · Gates** (AC: 8)
  - [x] `make php.stan` por fichero PHP tocado; al cierre `make php.quality` (deptrac/bounded-context/error-contract/phpmd/rector/cs-fixer/gherkinlint). `make php.behat` de `bank_account` + `make php.unit c='--filter "StaticAuthorizationPolicy|PermissionVoter|BankAccount"'`. **Sin migración.** `make php.behat.install` primero (worktree fresco: el bootstrap de PHPStan referencia `api/tools/behat/vendor/autoload.php`).

## Dev Notes

### Contrato de diseño (fuente de verdad)

- **Épica** `_bmad-output/planning-artifacts/epics-rbac-authorization-model.md` — **FR5** (constantes por módulo → RM-4 `BankAccountPermission`), **FR7** (gateo de `BankAccount` incl. anidada + `explicitGrants['bankAccount.changeStatus']`), **FR3.2** (domain-ops = grant explícito), **NFR9** (transport independence — mismo permiso colección/anidada), **NFR1/SI-9** (OCP additive-only), **NFR6** (errores por contrato), pre-mortem **R1** (backfill, ya cubierto por RM-3) y **R2** (bypass keyset → gate duro RM-2), §"Story RM-4 (PR-4)" (5 AC BDD).
- **Addendum** `_bmad-output/planning-artifacts/arch-addendum-rbac-authorization-model.md` — §"Localización de decisiones por PR" (PR-4: `BankAccountPermission` + `#[IsGranted]` incl. anidada + `explicitGrants['bankAccount.changeStatus']`); DAG (**PR-4 exige PR-2 como gate duro**).
- **ADR** `docs/adr/rbac-authorization-model.md` — **D2** (recurso ≠ ruta/contexto — la anidada `/banks/{id}/accounts` es recurso `BankAccount`), **D3** (acción = capacidad; `changeStatus` es domain-op de 1ª clase, no CRUD), **D5** (constantes por módulo + política tier declarativa + **carve-out clase-de-constantes vs enum, que nombra `BankAccountPermission`**, L49), **D9** (puerta row-level abierta sin construir).

> **Corrección de rutas respecto al ADR/épica/addendum:** el núcleo RBAC se relocalizó de `Backoffice/Identity/Infrastructure/Security` → **`Iam/Identity/Infrastructure/Security`** (PR #458, promoción de Identity a `Iam` top-level, posterior a la redacción del ADR). Los documentos de diseño citan la ruta vieja; las rutas **vivas** son las de abajo. RM-4 **sí** edita el núcleo, pero **additive-only** (una fila de datos en `EXPLICIT_GRANTS`) — no toca voter/VO/puerto/algoritmo (SI-9 se respeta: «añadir un recurso sólo puede añadir constantes + `#[IsGranted]` + —sólo domain-ops— filas de `explicitGrants`»).

### Estado actual del código (leído — no reinventar)

**Núcleo RBAC (`api/src/Iam/Identity/Infrastructure/Security/`, en `main`):**

- `Permission.php` — VO `final readonly` `(resource, action)`. `fromString()`/`isWellFormed()`; **cada segmento** `/^[A-Za-z][A-Za-z0-9]*$/` → `bankAccount` (camelCase) y `changeStatus` **pasan**; `resource()`/`action()`/`toString()`. **No** entidad/tabla. **RM-4 no lo toca.**
- `PermissionVoter.php` — `supports()` sii `Permission::isWellFormed($attribute)`; strip `ROLE_` en el borde; delega en `AuthorizationPolicy::permits()`; **acepta `subject:` pero no lo lee**; abstiene sobre `ROLE_*`/`IS_AUTHENTICATED_*`. **RM-4 no lo toca.**
- `StaticAuthorizationPolicy.php` (impl `final readonly` del puerto) — política como **datos**, 3 `const`:
  - `TIER_VERBS = [VIEWER→['read'], EDITOR→['read','write'], MANAGER→['read','write','delete'], ADMIN→['*']]` (`WILDCARD='*'`). **`changeStatus` NO está aquí** → no auto-concedido por tier.
  - `EXPLICIT_GRANTS = ['auditTrail.read' => [Role::AUDIT_READER->value]]` → **RM-4 añade `'bankAccount.changeStatus' => [Role::MANAGER->value]`**.
  - `TIER_OPT_OUT = ['auditTrail']` → **RM-4 NO añade `bankAccount`** (read/write/delete siguen siendo tier).
  - `permits()`: (1) `grantedToAdmin` (wildcard incondicional; por eso ADMIN **no** se lista en `EXPLICIT_GRANTS`); (2) `grantedByTier` (si `resource ∉ tierOptOut` y `action ∈` verbos del rol); (3) `grantedExplicitly` (`array_intersect(EXPLICIT_GRANTS[permiso], roles)`).
  - Los tres `const` son también defaults del constructor (`final readonly` intacto) → los tests ejercen mapas custom sin datos fake.
- `Role.php` (`api/src/Iam/Identity/Domain/Enum/Role.php`) — enum `VIEWER/EDITOR/MANAGER/ADMIN/AUDIT_READER`, `->value` sin `ROLE_`. **RM-4 no añade rol** (MANAGER + wildcard ADMIN cubren `changeStatus`).
- Tripwire `api/tests/Unit/Iam/Identity/Infrastructure/Security/StaticAuthorizationPolicyIsDataOnlyTest.php` — tokeniza cada `const` de política y falla ante control-flow/closure/call. La fila nueva (`Role::MANAGER->value`) es literal → **verde sin tocarse**.

**Superficie HTTP de `BankAccount` (`api/src/Backoffice/BankAccount/Infrastructure/Controller/`, prefijo `/api/v1/backoffice`). Ninguno lleva `#[IsGranted]` hoy (grep = 0):**

| Controlador | Método + ruta | Permiso RM-4 |
|---|---|---|
| `BankAccountSearchCollectionController` | `GET /bank-accounts` (colección) | `bankAccount.read` |
| `BankAccountSearchController` | **`GET /banks/{id}/accounts` (anidada)** | `bankAccount.read` |
| `BankAccountGetController` | `GET /bank-accounts/{id}` | `bankAccount.read` |
| `BankAccountRealtimeAuthorizeController` | `GET /bank-accounts/realtime/authorize` (Route a nivel método) | `bankAccount.read` (nivel método) |
| `BankAccountPostController` | `POST /bank-accounts` | `bankAccount.write` |
| `BankAccountPutController` | `PUT /bank-accounts/{id}` | `bankAccount.write` |
| `BankAccountDeleteController` | `DELETE /bank-accounts/{id}` | `bankAccount.delete` |
| `BankAccountPatchStatusController` | `PATCH /bank-accounts/{id}/status` | `bankAccount.changeStatus` |

READ×4 · WRITE×2 · DELETE×1 · CHANGE_STATUS×1 = **8**. Su única puerta hoy es el catch-all `- { path: '^/api', roles: IS_AUTHENTICATED_FULLY }` (`security.yaml` L45).

**Precedente RM-3 (Bank, `main`) — el patrón exacto a espejar:**

- `api/src/Backoffice/Bank/Infrastructure/Security/BankPermission.php` — `final class` con `public const string READ/WRITE/DELETE` (docblock con el *por qué*). RM-4 lo replica añadiendo `CHANGE_STATUS`.
- Controladores Bank con `#[IsGranted(BankPermission::…)]` (import `Symfony\Component\Security\Http\Attribute\IsGranted`).
- `api/features/backoffice/bank/access_control.feature` — 13 escenarios (anon 401 · role-less 403 · viewer read-200/write-403 · editor delete-403 · manager 200), con el comentario-cabecera que explica 401-vs-403 y «write refusals send a valid body so the gate answers, not a 422». **Espejar su forma.**
- Fixtures `api/tests/DataFixtures/Fixtures/User.yaml`: `user_alice`=`['AUDIT_READER','MANAGER']` (default), `user_mallory`=`[]`, `user_trent`=`['MANAGER']`, `user_victor`=`['VIEWER']`, `user_edith`=`['EDITOR']`. **Todos en `main` — RM-4 no añade ninguno.**
- Steps `api/tests/Behat/Context/SecurityContext.php`: `I am logged in as a viewer` / `an editor` / `a user without the audit-reader role` / `a generic-tier user without the audit-reader role`. **En `main` — reutilizar.**

### Decisiones de diseño y gotchas (previenen retrabajo)

1. **RM-4 SÍ edita la política — y es la forma correcta del modelo, no una violación de OCP.** A diferencia de RM-3 (slice sólo-CRUD, 0 filas), `changeStatus` es un **domain-op** (`D3`): no es un verbo de tier, así que **debe** declararse en `explicitGrants` (`FR3.2`). Añadir **una fila de datos** es exactamente lo que SI-9 permite («añadir un recurso sólo puede añadir … —sólo domain-ops— filas de `explicitGrants`»); lo prohibido sería tocar el voter/VO/puerto o meter un `if`. RM-3 demostró el objetivo OCP en su forma más fuerte (0 filas); RM-4 lo demuestra en su forma **completa** (el recurso con un domain-op añade 1 fila declarativa, cero código).
2. **`changeStatus → [MANAGER]` (ADMIN implícito) — recomendación, a confirmar (ver Questions §1).** Análogo directo de `bank.close → {MANAGER, ADMIN}` de la épica (FR3.2): un cambio de estado de cuenta (activar/cerrar/suspender) es una operación de nivel gestor, por encima de `write`. ADMIN concede por wildcard (`grantedToAdmin`) → **no** se lista (convención confirmada en `auditTrail.read`, que sólo lista `AUDIT_READER`). EDITOR queda **fuera** deliberadamente: tener `write` no debe conceder `changeStatus` (es lo que el AC4 fija con el binding).
3. **La ruta anidada es un controlador `BankAccount`, no `Bank`.** `BankAccountSearchController` sirve `GET /banks/{id}/accounts` pero vive en `Backoffice/BankAccount/` → se gatea con `BankAccountPermission::READ`. Que la URL cuelgue de `/banks/` **no** la hace recurso `Bank` (D2/NFR9: recurso ≠ ruta). Gatearla con `bank.read` sería el error — rompería la coherencia (un VIEWER de cuentas no podría verlas por la anidada) y cruzaría el boundary `Bank ⊥ BankAccount` (deptrac). **Mismo `bankAccount.read` para colección y anidada** es el corolario literal de NFR9.
4. **`BankAccountRealtimeAuthorizeController` tiene el `#[Route]` a nivel método** (`__invoke`), no de clase → poner `#[IsGranted]` a **nivel método** (espejo de cómo RM-3 gateó `BankRealtimeAuthorizeController`). Los otros 7 tienen Route a nivel clase → `#[IsGranted]` a nivel clase.
5. **`security.yaml` intacto.** El catch-all `^/api → IS_AUTHENTICATED_FULLY` produce el **401 anónimo** antes del voter; `#[IsGranted]` capa el **403 autenticado-sin-permiso** encima. **No** añadir filas `access_control` por ruta (acopla ruta→permiso, viola NFR9/D2). Sin migración.
6. **Regresión `status.feature`:** hoy `PATCH /bank-accounts/{id}/status` no tiene gate → 200 con Alice. Tras RM-4 exige `changeStatus`; Alice=`MANAGER` lo obtiene **por el grant explícito nuevo** → `status.feature` **sigue verde**. (Si por error `changeStatus` no se concediera a MANAGER, `status.feature` rompería — señal útil.)
7. **422-antes-de-403 (heredado de RM-3, diferido).** En write (`POST`/`PUT`) y `PATCH status`, `MapRequestPayload`/`MapQueryString` resuelven **antes** que `#[IsGranted]` (orden de listeners Symfony) → un autenticado-sin-permiso con body inválido puede recibir 422 antes del 403. Es el mismo matiz que RM-3 registró en `deferred-work.md`; **no** se re-arregla aquí (fijarlo acoplaría ruta→permiso). Mitigación en el feature: enviar bodies **válidos** en los escenarios de denegación de escritura/status para que responda el gate. El anónimo cae en el firewall (401) antes de tocar el payload.
8. **PWA 403 en realtime (heredado de RM-3, diferido).** `bank-accounts/realtime/authorize` pasa de «cualquier autenticado» a exigir `bankAccount.read`; un autenticado sin tier de negocio recibiría 403 y el `EventSource` no se autorizaría — mismo follow-up cross-deployable que abrió RM-3 para el realtime de Bank. Enmascarado en greenfield (sólo el `ADMIN` wildcard existe). **Fuera de alcance** de RM-4 (backend puro); anotar en `deferred-work.md` como extensión del item de RM-3 si no está ya.
9. **camelCase en el permiso.** `bankAccount.changeStatus` → `resource='bankAccount'`, `action='changeStatus'`; ambos casan `/^[A-Za-z][A-Za-z0-9]*$/`. Confirmado por la CR de RM-3 (el charset por-segmento admite camelCase). No usar snake_case ni guiones.

### Fuera de alcance (NO hacer en RM-4)

- ❌ Añadir `bankAccount` a `TIER_OPT_OUT` (read/write/delete deben seguir siendo tier, como Bank — sólo audit opta fuera).
- ❌ Listar `ADMIN` en `explicitGrants['bankAccount.changeStatus']` (redundante; wildcard ya concede — romper la convención de `auditTrail.read`).
- ❌ Tocar `PermissionVoter` / VO `Permission` / puerto `AuthorizationPolicy` / `TIER_VERBS` / `Role` (SI-9: sólo la fila `explicitGrants` es additive-legal).
- ❌ Tocar `security.yaml`, añadir migración, o editar `docs/api-error-contract.md` (no hay marker nuevo).
- ❌ Re-editar el ADR D5 (el carve-out clase-de-constantes que nombra `BankAccountPermission` ya está en `main`).
- ❌ Añadir fixtures o steps Behat (RM-3 ya sembró VIEWER/EDITOR/MANAGER/role-less).
- ❌ Evaluar `subject:` / row-level scope (2º tripwire → capacidad futura) · Gate OCP ejecutable → RM-6.
- ❌ Arreglar el 422-antes-de-403 o el 403-realtime-PWA (diferidos, ver gotchas 7–8).

### Testing (obligatorio; convenciones del repo)

**Añadir (prueba del AC):**
- `StaticAuthorizationPolicyTest` — matriz `bankAccount.changeStatus` (MANAGER/ADMIN conceden, VIEWER/EDITOR deniegan) **+ aserción dedicada `bankAccount ∉ TIER_OPT_OUT`**: par `assertTrue(permits(VIEWER,'bankAccount.read'))` vs `assertFalse(permits(VIEWER,'auditTrail.read'))` (tier-in vs opt-out, guard anti-regresión). *(AC3/AC4)*
- `PermissionVoterAccessDecisionTest` (obligatorio) — `changeStatus` wired: MANAGER concede / EDITOR deniega. Cubre la cadena voter→policy→explicitGrants; no se omite. *(AC4/AC6)*
- `api/features/backoffice/bank_account/access_control.feature` — 401 anon / 403 role-less / viewer-read-200-incl-anidada / viewer-write-delete-status-403 / editor-delete-403 / **editor-status-403** (binding de changeStatus), por verbo, con la **ruta anidada** presente. *(AC5/AC6/AC7)*

**Mantener verde (regresión):**
- Features `bank_account`: `search`, `search_collection`, `get`, `create`, `update`, `delete`, `status`, `audit` (Alice=`MANAGER` → todas 2xx; `status` verde por el grant explícito).
- Escenarios replay cross-ruta de RM-2 en `bank_account` (cursor colección→anidada → 422). *(gate duro AC5)*
- Tripwire `StaticAuthorizationPolicyIsDataOnlyTest` (intacto).
- Suites Bank/audit (no tocadas).

Correr: `make php.unit c='--filter "StaticAuthorizationPolicy|PermissionVoter|BankAccount"'`, `make php.behat` de `bank_account`; `make php.stan` por fichero; `make php.quality` al cerrar. `make php.behat.install` primero en el worktree fresco.

### Project Structure Notes

- Ficheros **nuevos**: 2 → `api/src/Backoffice/BankAccount/Infrastructure/Security/BankAccountPermission.php` + `api/features/backoffice/bank_account/access_control.feature`. Namespace nuevo `Erpify\Backoffice\BankAccount\Infrastructure\Security` (dentro del boundary `BankAccount`; deptrac lo cubre por prefijo — sin cambio en `deptrac.yaml`).
- **Ediciones**: 8 controladores + `StaticAuthorizationPolicy.php` (+1 fila, ajuste docblock) + 2 tests (unit, funcional) + `PRODUCTION_SECURITY_CHECKLIST.md`.
- Sin nuevos directorios en `Iam`/`Shared`. Sin YAML de rutas nuevo. Sin serializer groups. Sin migración. Sin fixtures/steps nuevos.

### References

- `_bmad-output/planning-artifacts/epics-rbac-authorization-model.md` — FR5, FR7, FR3.2, NFR1/6/9, §"Story RM-4 (PR-4)", R1/R2, §"FR Coverage Map".
- `_bmad-output/planning-artifacts/arch-addendum-rbac-authorization-model.md` — §"Localización de decisiones por PR" (PR-4), DAG (gate duro PR-2), SI-9.
- `docs/adr/rbac-authorization-model.md` — D2, D3, D5 (incl. carve-out clase-de-constantes, L49), D9.
- `docs/api-error-contract.md` — 401/403 por RFC 9457 sin marker nuevo (NFR26 no se dispara).
- `docs/project-context.md` — PHP 8.5 `strict_types`, tipado total, `enum`/`final`/`readonly`, PHPStan `level: max`, AAA + fakes in-tree, `make` desde la raíz.
- Código en vigor: `api/src/Iam/Identity/Infrastructure/Security/{Permission,PermissionVoter,AuthorizationPolicy,StaticAuthorizationPolicy}.php`, `api/src/Iam/Identity/Domain/Enum/Role.php`; controladores `api/src/Backoffice/BankAccount/Infrastructure/Controller/BankAccount{SearchCollection,Search,Get,RealtimeAuthorize,Post,Put,Delete,PatchStatus}Controller.php`; `api/src/Backoffice/Bank/Infrastructure/Security/BankPermission.php` (precedente); `api/features/backoffice/bank/access_control.feature` (espejo); `api/tests/DataFixtures/Fixtures/User.yaml`; `api/tests/Behat/Context/SecurityContext.php`; `api/config/packages/security.yaml`.

### Previous Story Intelligence (RM-3 #463, RM-2 #457, RM-5 #459 — todas en `main`)

- **RM-3** es el precedente 1:1 de gateo de un slice de negocio (Bank): `BankPermission` (1ª clase de constantes) + `#[IsGranted]` en 7 controladores + `access_control.feature` (401/403/200) + backfill Alice/trait=`MANAGER`. RM-4 replica el patrón para BankAccount, con **dos diferencias**: (a) `BankAccount` tiene un **domain-op** (`changeStatus`) → +1 fila `explicitGrants` (RM-3 tuvo 0); (b) tiene una **ruta anidada** → NFR9 (mismo permiso colección/anidada) y gate duro RM-2. Los fixtures VIEWER/EDITOR que RM-4 reutiliza los **añadió la CR de RM-3** (patches `941f8e07`) precisamente para fijar el binding controlador→constante — RM-4 hereda esa red gratis.
- **Lección de la CR de RM-3 (aplicar de raíz):** el matrix de access-control **debe** fijar el binding con roles intermedios (VIEWER/EDITOR), no sólo MANAGER-todo vs role-less-nada. Sin ello una mala anotación a un permiso más débil (p.ej. `PatchStatus`→`WRITE` en vez de `CHANGE_STATUS`, o `Delete`→`READ`) pasaría CI en verde = escalada silenciosa. Por eso T6 incluye **editor→PATCH-status→403** y viewer→write/delete→403 desde el inicio.
- **RM-2** aporta el discriminante base-query del cursor keyset + los escenarios replay cross-ruta en `bank_account` → RM-4 los **hereda como gate duro** (no los reescribe; los verifica verdes).
- **`BankPermission` = clase de constantes (no enum)** quedó ratificado por Winston+Amelia en la CR de RM-3 y documentado como carve-out en ADR D5 → `BankAccountPermission` sigue la convención **sin re-abrir el debate**.
- Convención: `make php.quality` corre rector/cs-fixer/phpmd/deptrac/gherkinlint — correrlo antes de declarar hecho; `make php.behat.install` en el worktree fresco.

### Git Intelligence

- Rama: `feat/backoffice-bankaccount-rbac-gate-ypiw` (worktree), base `main` en `1075319b` (incluye RM-1 #456, RM-2 #457, RM-5 #459, relocación Iam #458, RM-3 #463). Orden de merge del epic: RM-4 es el **5º y último de comportamiento**; RM-6 (opcional) sólo requiere RM-1.
- Estilo a seguir: los 7 controladores Bank gateados en #463 (`#[IsGranted(BankPermission::…)]` + import Symfony Security), `BankPermission` `final class … public const string`, y la fila `auditTrail.read` de `EXPLICIT_GRANTS` como plantilla de la de `changeStatus`.

### Project Context Reference

`docs/project-context.md` obligatorio antes de codificar: `declare(strict_types=1)`, tipado total, `enum`/`final`/`readonly`, excepciones para error-flow, **sin framework en `Domain/`** (el cambio es todo `Infrastructure/` + política/tests, OK), PHPStan `level: max` única puerta de tipos, AAA + fakes in-tree, `make` desde la raíz. Reglas de comentarios: sólo el *por qué* no obvio; **barrer IDs de story/FR/NFR y comentarios change-relative del diff de código antes del commit final** (la trazabilidad vive en el PR/spec, no en el código).

### Questions for Sergio (resolver durante dev; recomendación entre corchetes)

1. **Roles de `bankAccount.changeStatus`** — [`explicitGrants['bankAccount.changeStatus'] = [MANAGER]` → concede a `MANAGER` (+ `ADMIN` por wildcard); `EDITOR` **no**]. Análogo de `bank.close = {MANAGER, ADMIN}`. ¿Confirmas que `EDITOR` queda fuera y no hay un tier intermedio de «operador de tesorería» aún? (Si más adelante existe, es otra fila, additive.)
2. **Capa funcional wired de `changeStatus`** (T5) — **RESUELTA (Sergio): se mantiene, no opcional.** El comportamiento nuevo de RM-4 es la cadena `PermissionVoter → AuthorizationPolicy → explicitGrants`; el funcional del voter caza una regresión de wiring con mucho menos coste de diagnóstico que el Behat. Además, la unit lleva la **aserción dedicada `bankAccount ∉ TIER_OPT_OUT`** (par VIEWER tier-in / audit opt-out) como guard anti-regresión de 6 meses.
3. **Ubicación del gate duro RM-2 (AC5)** — [verificar que los escenarios replay de RM-2 en `bank_account` siguen verdes = el gate; **no** duplicar el test]. ¿Suficiente, o quieres un escenario replay explícito adicional que también compruebe el 403 de autorización sobre la anidada (defensa en profundidad: 403 antes incluso de evaluar el cursor)?

## Dev Agent Record

### Agent Model Used

claude-opus-4-8[1m] (Claude Opus 4.8, 1M context) — dev-story workflow.

### Debug Log References

- `make php.behat.install` — tooling Behat aislada ausente en el worktree fresco (el bootstrap de PHPStan referencia `api/tools/behat/vendor/autoload.php`).
- `make php.stan` — **OK, 0 errores** (827 ficheros).
- `make php.quality` — 1ª pasada falló en **PHPMD `TooManyPublicMethods`**: `StaticAuthorizationPolicyTest` subió a 12 métodos (cap = 10). Resuelto sin perder cobertura (ver Completion Notes §5). 2ª pasada **EXIT=0**.
- `make php.behat` — **246/246 escenarios (2287 steps)**.
- `make php.unit` — **1593 tests / 7270 aserciones, 3 skipped pre-existentes**, EXIT=0.

### Completion Notes List

- **`BankAccountPermission` = 2ª clase de constantes de permiso** (`READ/WRITE/DELETE/CHANGE_STATUS`), cierra la Regla de Tres por módulo. Sigue el carve-out ADR D5 (ya en `main`); el docblock referencia el *por qué* (typo-safety + string end-to-end) → **ADR no re-editado**.
- **8 controladores gateados.** READ×4 (colección, **anidada `GET /banks/{id}/accounts`**, get, realtime), WRITE×2 (post, put), DELETE×1, CHANGE_STATUS×1 (patch-status). `RealtimeAuthorize` lleva el `#[IsGranted]` a **nivel método** (su `#[Route]` es de método). **Mismo `bankAccount.read` en colección y anidada** (NFR9). `security.yaml` intacto; deptrac cubre `Infrastructure/Security` por prefijo sin cambio.
- **Boy-scout:** corregidos 3 docblocks stale «Public … the repo has no auth yet» (`BankAccountSearchController`, `BankAccountSearchCollectionController`, `BankAccountPostController`) — ya falsos: RM-4 añade la autorización. Reescritos al estado actual (gateados por `bankAccount.read`/`write`), conservando la nota PII-IBAN y la explicación `_audit_canonical`.
- **Una fila de política** (`EXPLICIT_GRANTS['bankAccount.changeStatus'] = [Role::MANAGER->value]`) — forma **completa** del OCP (recurso con domain-op = 1 fila declarativa, 0 código). ADMIN concede por wildcard → **no** se lista (convención de `auditTrail.read`). `TIER_VERBS`/`TIER_OPT_OUT` intactos (`bankAccount` **no** opta fuera: read/write/delete siguen tier). Tripwire `StaticAuthorizationPolicyIsDataOnlyTest` verde sin tocarse. Docblock de la clase generalizado (ya no «audit es el único grant explícito»).
- **Rol de `changeStatus` = `[MANAGER]`** (+ ADMIN implícito), la recomendación documentada (análogo de `bank.close={MANAGER,ADMIN}`); EDITOR fuera. Es un valor de política — **confirmar en review** si se quiere un tier intermedio (Questions §1).
- **Reestructura del unit por PHPMD (§5):** `StaticAuthorizationPolicyTest` no puede pasar de 10 métodos públicos. Para no perder cobertura ni los dos refuerzos pedidos: (1) `changeStatus` quedó como **método dedicado** (matriz MANAGER/ADMIN conceden, VIEWER/EDITOR deniegan); (2) el **par-guard dedicado `bankAccount.read` (tier-in) vs `auditTrail.read` (opt-out)** aterrizó en el método que **ya** prueba la especificidad del opt-out (`testAuditTrailReadIsGrantedOnlyToAuditReaderAndAdmin`) — su sitio natural, cambiando la aserción VIEWER de `bank.read`→`bankAccount.read`; (3) boy-scout: fusionados dos denials triviales duplicados (`testNoRolesIsDenied` + `testAnUntieredRoleIsDeniedWhenNothingGrantsIt`) en uno. Neto = 10 métodos. Ninguno de los dos asks de Sergio se perdió.
- **T5 funcional wired (obligatorio, decisión de Sergio):** `PermissionVoterAccessDecisionTest` prueba la cadena voter→policy→explicitGrants para `changeStatus` (MANAGER concede / EDITOR deniega pese a write).
- **Behat `bank_account/access_control.feature` (21 escenarios):** anon→401 (×6 incl. anidada y patch-status), role-less→403 (×6), VIEWER (read 200 incl. **anidada**, write/delete/status 403), EDITOR (delete 403, **status 403** = binding de `changeStatus`), MANAGER 200. Bodies **válidos** en denegaciones de escritura/status para que responda el gate (403), no un 422 (mismo matiz que RM-3, diferido).
- **Gate duro RM-2 (AC5):** el escenario replay cross-ruta de RM-2 en `bank_account/search.feature` (cursor colección→anidada → 422 `invalid-cursor`) **sigue verde** — es el gate; RM-4 **no lo duplica**. RM-2 está en `main` (#457).
- **Regresión:** todas las features `bank_account` (search/search_collection/get/create/update/delete/**status**/audit) verdes con Alice=`MANAGER` — `status.feature` verde porque MANAGER ahora concede `changeStatus` por el grant explícito.
- **Self-review de seguridad:** RM-4 **es** el endurecimiento (retira el catch-all como única puerta de BankAccount). Sin SQL nuevo, sin migración, sin secretos, sin cambio de serializer/setters, sin marker de error nuevo (401/403 por RFC 9457). Matiz 422-antes-de-403 en escritura/status: heredado de RM-3 (diferido, `deferred-work.md`); anónimo cae en firewall 401 pre-payload; BankAccount expone IBAN (PII) → el gate lo protege ahora. **Follow-up cross-deployable (PWA):** `bank-accounts/realtime/authorize` pasa a exigir `bankAccount.read` → un autenticado sin tier de negocio recibe 403 y el `EventSource` no se autoriza (mismo item que abrió RM-3 para el realtime de Bank; enmascarado en greenfield).

### File List

- `api/src/Backoffice/BankAccount/Infrastructure/Security/BankAccountPermission.php` (new — `READ/WRITE/DELETE/CHANGE_STATUS`)
- `api/src/Backoffice/BankAccount/Infrastructure/Controller/BankAccountSearchCollectionController.php` (modified — `bankAccount.read` + docblock stale)
- `api/src/Backoffice/BankAccount/Infrastructure/Controller/BankAccountSearchController.php` (modified — `bankAccount.read` anidada + docblock stale)
- `api/src/Backoffice/BankAccount/Infrastructure/Controller/BankAccountGetController.php` (modified — `bankAccount.read`)
- `api/src/Backoffice/BankAccount/Infrastructure/Controller/BankAccountRealtimeAuthorizeController.php` (modified — `bankAccount.read` a nivel método)
- `api/src/Backoffice/BankAccount/Infrastructure/Controller/BankAccountPostController.php` (modified — `bankAccount.write` + docblock stale)
- `api/src/Backoffice/BankAccount/Infrastructure/Controller/BankAccountPutController.php` (modified — `bankAccount.write`)
- `api/src/Backoffice/BankAccount/Infrastructure/Controller/BankAccountDeleteController.php` (modified — `bankAccount.delete`)
- `api/src/Backoffice/BankAccount/Infrastructure/Controller/BankAccountPatchStatusController.php` (modified — `bankAccount.changeStatus`)
- `api/src/Iam/Identity/Infrastructure/Security/StaticAuthorizationPolicy.php` (modified — +1 fila `explicitGrants` + docblock)
- `api/tests/Unit/Iam/Identity/Infrastructure/Security/StaticAuthorizationPolicyTest.php` (modified — matriz `changeStatus` + par-guard + fusión de denials)
- `api/tests/Functional/Iam/Identity/Infrastructure/Security/PermissionVoterAccessDecisionTest.php` (modified — `changeStatus` wired)
- `api/features/backoffice/bank_account/access_control.feature` (new — 21 escenarios 401/403/200 incl. anidada + binding changeStatus)
- `PRODUCTION_SECURITY_CHECKLIST.md` (modified — §Access-control: rutas BankAccount + changeStatus)
- `_bmad-output/implementation-artifacts/rm-4-gateo-bankaccount-coleccion-anidada.md` (new — story)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (modified — RM-4 → in-progress → review)

### Change Log

- `BankAccountPermission` (new): vocabulario `bankAccount.{read,write,delete,changeStatus}` co-localizado en el borde de `Backoffice/BankAccount`.
- 8 controladores de `BankAccount` gateados con `#[IsGranted(BankAccountPermission::…)]` (incl. la ruta anidada); 3 docblocks stale corregidos (boy-scout).
- `StaticAuthorizationPolicy`: +1 fila `explicitGrants['bankAccount.changeStatus'] = [MANAGER]` (domain-op, no verbo de tier); docblock generalizado. Sin cambio en `TIER_VERBS`/`TIER_OPT_OUT`/voter/VO/puerto.
- Tests: matriz unit `changeStatus` + par-guard `tierOptOut`, funcional wired `changeStatus`, `bank_account/access_control.feature` (nuevo). Fusión boy-scout de dos denials duplicados por el cap PHPMD.
- `PRODUCTION_SECURITY_CHECKLIST.md`: gateo de rutas BankAccount + nota `changeStatus` = MANAGER/ADMIN.
- Sin migración, sin `security.yaml`, sin `deptrac.yaml`, sin re-edición del ADR (carve-out D5 ya en `main`).

## Review Findings (code review 2026-07-08)

Veredicto: **verde — 8/8 AC cumplidos, 0 bloqueantes.** Código productivo (guards, colocación, fila de política, algoritmo `permits()`) correcto y completo. Los 4 hallazgos son de red de tests y espejo del precedente `bank/access_control.feature` (ya en `main`).

- [x] [Review][Patch] Rutas READ `GET /bank-accounts/{id}` (IBAN/PII) y `GET /bank-accounts/realtime/authorize` gateadas pero sin escenario 401/403 [api/features/backoffice/bank_account/access_control.feature] — **APLICADO**: +4 escenarios (anon→401, role-less→403 por ruta); feature 21→25, behat 25/25 verde. Cierra el hueco «guard caído pasa CI verde» que el precedente Bank aún tiene.
- [x] [Review][Defer] POST/PUT sin positivo EDITOR (`write→2xx`) [api/features/backoffice/bank_account/access_control.feature] — diferido, pre-existente (mismo patrón que `bank/access_control.feature`; la dirección crítica de seguridad viewer→write→403 ya está cubierta). Registrado en `deferred-work.md`.
- Dismissed (ruido, espejo de convención ya mergeada): escenario «granted manager» con sesión implícita/casi-tautológico (MANAGER changeStatus→2xx ya vive en `status.feature`); `BankAccountPermission` instanciable sin ctor privado (espejo de `BankPermission` + forma ratificada en ADR D5).
