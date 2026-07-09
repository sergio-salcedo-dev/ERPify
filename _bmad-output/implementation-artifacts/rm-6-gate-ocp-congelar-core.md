---
baseline_commit: ee094655a27a9bae918baf73c8f82caf7f5869c0
---

# Story RM-6 (PR-6): Gate OCP ejecutable — congelar el core de autorización (mitad cara, opcional)

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

Como **arquitecto de la plataforma**,
quiero un test de arquitectura que **congele el core de autorización**,
para que añadir un recurso nuevo no pueda modificar el `PermissionVoter` / VO `Permission` / puerto `AuthorizationPolicy`, ni convertir la política en un motor consultando el `subject:`.

Es la **última historia (opcional) de la épica RBAC**: el trabajo obligatorio (RM-1…RM-5) ya está en `main`. RM-6 sube a **fallo de CI** la *mitad cara* de los dos tripwires del ADR (§tripwires). La *mitad barata* (política = sólo datos) ya vive en RM-1 como `StaticAuthorizationPolicyIsDataOnlyTest` y **no se duplica**. No hay cambio de comportamiento en producción: RM-6 añade **sólo tests + una entrada de ADR** (rama `test/…`, sin migración, sin `security.yaml`).

## Acceptance Criteria

1. **Core-set-invariance / OCP (NFR1, criterio OCP del ADR).** Un test de arquitectura demuestra que un **recurso de prueba jamás visto por la política** (p. ej. `invoice`) queda gobernado por el core **sin modificar** el voter / VO / puerto: se resuelve **sólo por `tierVerbs`**, con **0 filas de política** (0 `explicitGrants`, 0 `tierOptOut`). Es el objetivo OCP en su forma más fuerte (un recurso sólo-CRUD futuro = 0 ediciones de core, 0 filas).
2. **Puerto cerrado a modificación (NFR1).** El mismo test asevera por reflexión que el contrato del puerto `AuthorizationPolicy` es el mínimo cerrado: **exactamente un método** `permits(Permission, array): bool`. Un segundo método (p. ej. `permitsWithScope(...)` para meter el `subject:` en el contrato) es precisamente la deriva ABAC que D9 prohíbe → el gate falla y fuerza un ADR consciente.
3. **`subject:` sin evaluar, gate estructural (NFR3, D9 — tripwire 2 elevado a CI).** Un test estructural sobre el fuente de `PermissionVoter` asevera que el parámetro `$subject` aparece **sólo como declaración de parámetro**, nunca leído en el cuerpo de ningún método. Es estrictamente **más fuerte** que el test de comportamiento existente (`PermissionVoterTest::testItDoesNotReadTheSubject`, que lo prueba para `null` y `stdClass`): vale para **cualquier** subject, y caza un `if ($subject instanceof …)` metido en `voteOnAttribute` aunque el test de comportamiento siguiera verde.
4. **No duplicar la mitad barata (frontera de alcance).** RM-6 cubre **sólo** la mitad cara (invariancia del core-set + `subject:`). El tripwire «política = sólo datos» sigue siendo de RM-1 (`StaticAuthorizationPolicyIsDataOnlyTest`); RM-6 no lo re-implementa.
5. **ADR §tripwires actualizado + status honesto.** En `docs/adr/rbac-authorization-model.md`, el *candidate follow-up* del §tripwires se convierte en la constatación de que el gate ejecutable **existe**, nombrando los tests que materializan cada tripwire (el de RM-1 para la mitad barata; los de RM-6 para la cara). Además el header `Status:` pasa de `accepted — design; not yet implemented` → `accepted — implemented`: el modelo ya está enteramente en `main` (RM-1…RM-5 + #464), así que «not yet implemented» estaba stale desde el merge de RM-4; RM-6 (cierre de la épica) lo corrige. Sin re-abrir decisiones D1–D9 ni la `Date`.
6. **Gates verdes.** `make php.stan` sobre cada fichero PHP nuevo, `make php.unit` (los tests nuevos + sin regresión), y `make php.quality` **exit 0** (deptrac/phpmd/cs-fixer/rector/ecs). Sin migración, sin cambio de `security.yaml`, sin marker de error nuevo.

## Tasks / Subtasks

- [x] **Task 1 — RED/GREEN: gate de core-set-invariance / OCP (AC: #1, #2)**
  - [x] Crear `api/tests/Unit/Iam/Identity/Infrastructure/Security/AuthorizationCoreIsClosedForModificationTest.php` (`final`, `@internal`, `#[CoversClass(PermissionVoter::class)]`).
  - [x] **Método OCP (AC#1):** instanciar el core de **producción** (`new PermissionVoter(new StaticAuthorizationPolicy())` — sin overrides de constructor, para vincular los mapas reales) y votar sobre un **recurso ausente de todo mapa** (nombre throwaway `invoice`; verificar en el fuente que no está en `EXPLICIT_GRANTS`/`TIER_OPT_OUT`). Aseverar la escalera por tier con un token stub `TokenInterface` (patrón de `PermissionVoterTest`):
    - `ROLE_VIEWER` → `invoice.read` GRANTED; `invoice.write`/`invoice.delete` DENIED.
    - `ROLE_EDITOR` → `invoice.write` GRANTED; `invoice.delete` DENIED.
    - `ROLE_MANAGER` → `invoice.delete` GRANTED.
    - `ROLE_ADMIN` → `invoice.read` **y** una domain-op inventada `invoice.approve` GRANTED (comodín).
    - `ROLE_MANAGER` → `invoice.approve` DENIED (no es verbo de tier, sin `explicitGrants`). → prueba «0 filas de política» para el recurso nuevo.
  - [x] **Método puerto-cerrado (AC#2):** por `ReflectionClass(AuthorizationPolicy::class)` aseverar `getMethods()` cuenta 1, nombre `permits`, 2 parámetros, tipo de retorno `bool`. Docblock que explique por qué el freeze del contrato del puerto ES el encoding directo de «el core no cambia al añadir un recurso».
  - [x] `make php.unit c='--filter AuthorizationCoreIsClosedForModificationTest'` → verde.
- [x] **Task 2 — RED/GREEN: gate estructural «`subject:` sin evaluar» (AC: #3)**
  - [x] Crear `api/tests/Unit/Iam/Identity/Infrastructure/Security/PermissionVoterDoesNotEvaluateSubjectTest.php` (`final`, `@internal`, `#[CoversClass(PermissionVoter::class)]`).
  - [x] Tokenizar el fuente de `PermissionVoter` con `token_get_all()` (patrón de `StaticAuthorizationPolicyIsDataOnlyTest`; `nikic/php-parser` no está en el autoload de la app). Recorrer y clasificar cada `T_VARIABLE` con contenido `$subject`: es **declaración** si está dentro del paréntesis de la lista de parámetros de una función; cualquier otra aparición es una **lectura** → fallo. Aseverar 0 lecturas.
  - [x] Añadir una segunda aserción de andamiaje: el fuente contiene ≥1 declaración `$subject` (si `supports`/`voteOnAttribute` dejaran de recibirlo, el gate perdería sentido silenciosamente — que falle en su lugar).
  - [x] `make php.unit c='--filter PermissionVoterDoesNotEvaluateSubjectTest'` → verde sobre el voter actual (0 lecturas).
- [x] **Task 3 — ADR §tripwires (AC: #5)**
  - [x] En `docs/adr/rbac-authorization-model.md` §«Acceptance criterion (OCP) and the two tripwires»: reescribir la línea *Candidate follow-up* (~L80) para constatar que el gate ejecutable existe, nombrando los tres tests (RM-1: `StaticAuthorizationPolicyIsDataOnlyTest` = mitad barata; RM-6: `AuthorizationCoreIsClosedForModificationTest` + `PermissionVoterDoesNotEvaluateSubjectTest` = mitad cara). Inglés (regla ADR), ≤ ~150 líneas, sin narrativa de proceso ni IDs de historia.
  - [x] Corregir el header `Status:` → `accepted — implemented` (stale desde #464; RM-6 cierra la épica). No tocar D1–D9 ni la `Date` (las decisiones no cambian; sólo se materializa el follow-up y se sincroniza el status).
- [x] **Task 4 — Gates de calidad + self-review de seguridad (AC: #6)**
  - [x] `make php.stan` sobre los 2 ficheros de test nuevos (si segfault exit 139: reintentar `PHP_SERVICE=messenger_worker`).
  - [x] `make php.quality` (sweep completo — única corrida de PHPMD/cs-fixer/rector/deptrac/ecs). Vigilar: acoplamiento PHPMD en los tests (imports ≤13), rector no reescriba las aserciones (ver Gotchas), `TooManyPublicMethods` (cap 10) por clase de test.
  - [x] Self-review de seguridad del diff (ver Dev Notes → *Security review*): el diff es **sólo tests + doc**; declarar explícito en el PR que ninguna clase de ataque aplica (no hay ruta, DTO, SQL, secreto, migración ni cambio de cabeceras).

## Dev Notes

### Contexto arquitectónico (lo que ya existe — NO reinventar)

El core RBAC completo está en `main` (RM-1…RM-5). RM-6 **sólo lo congela**; no crea módulos, ni directorios de `src/`, ni entradas en `deptrac.yaml`/`services.yaml`/`security.yaml`. Todo es un par de tests + una edición de ADR. El core vive en `Iam/Identity/Infrastructure/Security` (Identity se movió de `Backoffice` a `Iam` en II-0 `#458`, ya en el baseline):

- **VO `Permission`** — `api/src/Iam/Identity/Infrastructure/Security/Permission.php`. `final readonly`; `fromString('<r>.<a>')`, `isWellFormed()`, `resource()`, `action()`, `toString()`, `equals()`. Sin conocimiento por-recurso: parsea cualquier `<resource>.<action>` camelCase válido → el gate OCP lo ejercita con un recurso nunca visto (`invoice`) y pasa sin ediciones.
- **Puerto `AuthorizationPolicy`** — `api/src/Iam/Identity/Infrastructure/Security/AuthorizationPolicy.php`. **Un** método: `permits(Permission $permission, array $roles): bool`. Neutral (permisos + tokens de rol sin `ROLE_` + bool; jamás `User`/`Role`/`SecurityUser`). AC#2 congela exactamente esta forma.
- **`StaticAuthorizationPolicy`** — `.../StaticAuthorizationPolicy.php`. 3 mapas `const` (`TIER_VERBS` resource-agnostic, `EXPLICIT_GRANTS`, `TIER_OPT_OUT`), doblan como defaults del constructor. `permits()` concede sii ADMIN, o (`resource ∉ tierOptOut` **y** `action ∈` verbos del tier), o rol ∈ `explicitGrants[permiso]`. Los mapas de producción **no** contienen `invoice` → gobierno por tier puro (AC#1).
- **`PermissionVoter`** — `.../PermissionVoter.php`. `supports()` = `Permission::isWellFormed($attribute)` (soporte por forma; abstiene sobre `ROLE_*`/`IS_AUTHENTICATED_*`). `voteOnAttribute()` hace strip de `ROLE_` (`bareRoleTokens`) y delega en el puerto; **acepta `$subject` pero no lo lee** (docblock + `@SuppressWarnings(PHPMD.UnusedFormalParameter)`). AC#3 sube ese «no lo lee» de convención/comportamiento a gate estructural.
- **Enum `Role`** — `api/src/Iam/Identity/Domain/Enum/Role.php`: `VIEWER/EDITOR/MANAGER/ADMIN/AUDIT_READER`, valores sin prefijo. `ROLE_` se añade sólo en `SecurityUser::getRoles()` — por eso el token stub del gate usa `ROLE_VIEWER` etc. (el voter espera el prefijo Symfony y lo quita).

### Diseño de los dos gates (el *qué* y el *por qué*)

**Los dos tripwires del ADR tienen dos mitades. RM-1 fijó la barata; RM-6 fija la cara. No se solapan.**

| Tripwire (ADR §) | Mitad barata (RM-1, ya en `main`) | Mitad cara (RM-6, esta historia) |
|---|---|---|
| 1 · política = datos, no mecanismo | `StaticAuthorizationPolicyIsDataOnlyTest`: tokeniza los mapas `const` y rechaza tokens ejecutables (`if`/closure/`match`/`(`…). **NO duplicar.** | — (n/a; la política no se toca aquí) |
| — · OCP / core-set-invariance | — | `AuthorizationCoreIsClosedForModificationTest`: un recurso nuevo se gobierna con el core intacto + puerto = 1 método (AC#1/#2) |
| 2 · `subject:` sin evaluar | Comportamiento con 1-2 inputs (`PermissionVoterTest::testItDoesNotReadTheSubject`) | `PermissionVoterDoesNotEvaluateSubjectTest`: estructural sobre el fuente, vale ∀ subject (AC#3) |

**Por qué el gate OCP es un drive-through de comportamiento y no un «diff del fichero».** No se puede aseverar «el dev no editó el voter». Sí se puede aseverar la *consecuencia*: un recurso que la política nunca ha oído (`invoice`) queda íntegramente gobernado por el core **de producción, sin overrides**. Si gobernarlo exigiera tocar el voter/VO/puerto, este test sería imposible de escribir / fallaría. El test **es** la prueba de «añadir un recurso = 0 ediciones de core». Se conduce a través del **voter** (no sólo la política) para ejercitar el core-set completo (voter → puerto → VO) contra un recurso jamás visto.

**Por qué además congelar el puerto por reflexión (AC#2).** El drive-through prueba que el core *funciona* para un recurso nuevo; no prueba que el *contrato del puerto* no cambie. La forma más directa de encodar «el contrato del puerto no cambia al añadir un recurso» es «el puerto tiene exactamente el método con que nació». Si un futuro dev, para dar scope row-level a `invoice`, añade `permits(Permission, array, ?object $subject)` o un segundo método, el gate falla — que es exactamente la deriva ABAC de D9 que queremos que CI cace. Coste: puede marcar una adición benigna al puerto; pero por el criterio OCP tal cual, el contrato «no debe cambiar», así que fallar es *el tripwire funcionando* (fuerza un toque consciente de ADR). Una aserción, no un espejo del fichero.

**Por qué el gate del `subject:` es estructural y no otro test de comportamiento.** El handoff/ADR pide «architecture test / deptrac / php.lint.* style» y «elevar a CI». El test de comportamiento existente prueba dos inputs; el estructural prueba **todos** aseverando que el fuente no lee `$subject`. Es el que caza el `if ($subject->owner() === …)` metido en `voteOnAttribute` (que podría dejar verde al de comportamiento si concede para `stdClass`). Reutiliza el patrón `token_get_all()` ya establecido por `IsDataOnlyTest` — misma casa, mismo estilo.

**Clasificación de tokens (`$subject` declaración vs lectura).** Recorrer los tokens; mantener si estamos dentro del paréntesis de la lista de parámetros de una función (arma en `T_FUNCTION`, abre en el primer `(`, cierra al volver a profundidad 0). Un `T_VARIABLE`==`'$subject'` dentro de esa lista = **declaración** (OK); en cualquier otro sitio = **lectura** (fallo). El voter no tiene closures, así que el caso de closure-con-su-propio-`$subject` no aplica.

### Ubicación y convención de test

- Ambos tests: `api/tests/Unit/Iam/Identity/Infrastructure/Security/` (junto a `StaticAuthorizationPolicyIsDataOnlyTest`, misma familia de tripwires). Suite única PHPUnit (`api/tools/phpunit/phpunit.dist.xml` → `../../tests`); corren con `make php.unit`. No hay suite «Architecture» separada — el precedente es un `TestCase` plano con `token_get_all`/reflexión.
- `#[CoversClass(PermissionVoter::class)]` en ambos (mismo criterio que `IsDataOnlyTest`, que marca la clase que el tripwire protege aunque sólo lea el fuente). El drive-through OCP además ejecuta `StaticAuthorizationPolicy`+`Permission`, pero ya tienen cobertura; el objetivo aquí es la **invariancia**, no subir cobertura.
- Token stub `TokenInterface` con `->method('getRoleNames')->willReturn([...])` (patrón exacto de `PermissionVoterTest::tokenWithRoles`).

### Source tree — ficheros a tocar

| Fichero | Acción |
|---|---|
| `api/tests/Unit/Iam/Identity/Infrastructure/Security/AuthorizationCoreIsClosedForModificationTest.php` | **nuevo** — gate OCP (AC#1/#2) |
| `api/tests/Unit/Iam/Identity/Infrastructure/Security/PermissionVoterDoesNotEvaluateSubjectTest.php` | **nuevo** — gate `subject:` estructural (AC#3) |
| `docs/adr/rbac-authorization-model.md` | §tripwires: candidate follow-up → gate implementado (AC#5) |
| `_bmad-output/implementation-artifacts/sprint-status.yaml` | rm-3/rm-4 → done (reconciliación); rm-6 → in-progress→review |
| `_bmad-output/implementation-artifacts/rm-6-*.md` | esta historia (nueva) |

**Cero cambios en `api/src/`.** Si el diff toca `src/`, algo se salió de alcance.

### Gotchas (aprendidos de RM-1…RM-5 y de la base de calidad)

- **No duplicar `StaticAuthorizationPolicyIsDataOnlyTest`** (mitad barata de RM-1). RM-6 no toca la política.
- **`StaticAuthorizationPolicyIsDataOnlyTest` es el patrón de referencia** para el token-walk (constantes `FORBIDDEN_*`, `policySourceTokens()`, `describe()`). Reusar el estilo, no el fichero.
- **rector reescribe aserciones de test** (memoria): `assertEquals`→`assertSame`; sobre `?Object` impone `assertNotInstanceOf` en vez de `assertNull`; puede tocar round-trips. Escribir directamente `assertSame`/`assertCount`/`assertTrue`/`assertFalse` y correr `make php.quality` antes de commitear para no ensuciar el diff.
- **PHPMD `CouplingBetweenObjects` (≤13) aplica a tests**: mantener los imports por clase de test bajos (el OCP importa ~`PermissionVoter`, `StaticAuthorizationPolicy`, `Permission`, `AuthorizationPolicy`, `TokenInterface`, `VoterInterface`, `ReflectionClass`, `CoversClass`, `TestCase` ≈ 9). `TooManyPublicMethods` (cap 10) por clase — el OCP lleva 2 métodos, el subject 1-2.
- **PHP worker segfault (exit 139)** en `make php.stan`: reintentar `PHP_SERVICE=messenger_worker` (gotcha de FrankenPHP worker, no del código).
- **No dejar IDs de historia/AC en comentarios de código** (`RM-6`, `NFR1`, `AC#3`): andamiaje del spec; barrer el diff antes del commit final. El *porqué* va en el docblock en prosa, no citando el ID.
- **`api/config/reference.php`** puede mostrar churn no relacionado tras arrancar el stack; restaurarlo si aparece (fuera del diff).
- **cs-fixer/ecs/rector mutan ficheros**: correr `make php.quality` antes de commitear.

### Comandos

```bash
make php.unit c='--filter AuthorizationCoreIsClosedForModificationTest'
make php.unit c='--filter PermissionVoterDoesNotEvaluateSubjectTest'
make php.unit c='--filter StaticAuthorizationPolicyIsDataOnlyTest'   # verificar que sigue verde (no se toca)
make php.stan                       # por fichero nuevo (o PHP_SERVICE=messenger_worker si 139)
make php.quality                    # sweep final (PHPMD/cs-fixer/rector/deptrac/ecs) — exit 0
```

### Security review (para el PR)

- **Diff = sólo tests + doc.** No hay ruta, controller, DTO, SQL/DQL, secreto, migración, `security.yaml`, ni cambio de cabeceras/CSP. Ninguna clase de ataque del checklist aplica → declararlo explícito en el PR (regla «no silent skips»).
- **El cambio *refuerza* seguridad**: convierte en fallo de CI dos tripwires que impiden la deriva silenciosa del modelo de autorización a ABAC (política-motor / lectura de `subject:`). No abre superficie.
- **`PRODUCTION_SECURITY_CHECKLIST.md`**: no requiere edición (no introduce patrón de seguridad nuevo; los gates protegen un patrón ya documentado por RM-1…RM-5).

### Project Structure Notes

- El core RBAC vive en `Iam/Identity/Infrastructure/Security`; los gates viven junto a `StaticAuthorizationPolicyIsDataOnlyTest` en `tests/Unit/Iam/Identity/Infrastructure/Security`. Sin cambios de deptrac/bounded-context/error-contract.
- Baseline de dev: `main` con RM-1…RM-5 y RM-4 (`#464`) mergeadas — HEAD `ee094655` al crear esta historia. RM-6 sólo requiere RM-1; va tras el cierre del resto de la épica.
- **Reconciliación sprint-status**: `rm-3`/`rm-4` figuran `review` pero están MERGEADAS (`#463`/`#464` en `main`) → `done`. Se hace en esta rama junto al gate.
- **Rama**: `test/iam-rbac-ocp-gate-xjdl` (tipo `test/` — no hay cambio de comportamiento en producción). Worktree aislado off `main`.

### References

- [Source: `_bmad-output/planning-artifacts/epics-rbac-authorization-model.md#Story RM-6 (PR-6)`] — ACs y frontera (mitad cara, opcional).
- [Source: `_bmad-output/planning-artifacts/arch-addendum-rbac-authorization-model.md#Localización de decisiones por PR`] — fila PR-6; SI-9 (additive-only, `subject:` sin evaluar).
- [Source: `docs/adr/rbac-authorization-model.md#Acceptance criterion (OCP) and the two tripwires`] — criterio OCP, dos tripwires, *candidate follow-up* a materializar; D9 (puerta row-level abierta, sin construir).
- [Source: `api/src/Iam/Identity/Infrastructure/Security/PermissionVoter.php`] — `supports()`/`voteOnAttribute()`, `$subject` aceptado y no leído.
- [Source: `api/src/Iam/Identity/Infrastructure/Security/AuthorizationPolicy.php`] — puerto de 1 método a congelar.
- [Source: `api/src/Iam/Identity/Infrastructure/Security/StaticAuthorizationPolicy.php`] — mapas de producción (sin `invoice`) y algoritmo `permits()`.
- [Source: `api/tests/Unit/Iam/Identity/Infrastructure/Security/StaticAuthorizationPolicyIsDataOnlyTest.php`] — patrón token-walk (mitad barata, NO duplicar).
- [Source: `api/tests/Unit/Iam/Identity/Infrastructure/Security/PermissionVoterTest.php`] — `tokenWithRoles()` stub; `testItDoesNotReadTheSubject` (comportamiento, que AC#3 refuerza estructuralmente).

## Dev Agent Record

### Agent Model Used

claude-opus-4-8[1m] (Opus 4.8, 1M context)

### Debug Log References

- **GREEN inicial** — `AuthorizationCoreIsClosedForModificationTest` (11) + `PermissionVoterDoesNotEvaluateSubjectTest` (2) + `StaticAuthorizationPolicyIsDataOnlyTest` (3, sin tocar) = 16 tests verdes.
- **RED controlado (validación de que los gates cazan)** — (1) inyectar `$ignored = $subject;` en el cuerpo de `PermissionVoter::voteOnAttribute` → `PermissionVoterDoesNotEvaluateSubjectTest::testTheVoterNeverReadsTheSubjectToDecide` **falla** (read en índice de token 169); revertido. (2) añadir un 2º método `explain()` al puerto `AuthorizationPolicy` + impl stub → `testTheAuthorizationPolicyPortContractIsClosed` **falla** («actual size 2 matches expected size 1»); el drive-through OCP sigue verde (sólo usa `permits`); revertido. Ambos `git checkout --` limpios.
- **Regresión** — `make php.unit` completo: **1606 tests / 7292 asserts / 3 skipped** verde (+13 vs baseline 1593).
- **Gates** — `make php.stan` (829 ficheros, 0 errores, vía `PHP_SERVICE=messenger_worker` por el segfault del web worker); `make php.quality` **EXIT=0** (deptrac 0 violaciones, phpmd/cs-fixer/ecs/rector limpios). Requirió `make php.behat.install` en el worktree (PHPStan escanea el árbol Behat y su `vendor/` no estaba sembrado).

### Completion Notes List

- **Diff = sólo tests + doc + artefactos bmad. Cero cambios en `api/src/`** — confirma la frontera OCP (extender ≠ modificar): el gate se escribe *contra* el core intacto.
- **Gate OCP (`AuthorizationCoreIsClosedForModificationTest`)**: (a) drive-through de un recurso jamás visto (`invoice`) por el voter+policy de producción → gobernado sólo por `tierVerbs`, 0 filas de política (AC#1/#3); (b) reflexión que congela el puerto a su único método `permits(Permission, array): bool` (AC#2); (c) guard que fija que `invoice` sigue ausente de la policy (si alguien lo añade, el gate avisa de elegir otro sentinel).
- **Gate `subject:` (`PermissionVoterDoesNotEvaluateSubjectTest`)**: token-walk del fuente del voter; `$subject` sólo como declaración de parámetro, 0 lecturas en cuerpos (AC#3). Estrictamente más fuerte que el test de comportamiento existente (vale ∀ subject). Mismo patrón `token_get_all` que la mitad barata de RM-1.
- **Mitad barata NO duplicada**: `StaticAuthorizationPolicyIsDataOnlyTest` (RM-1) queda intacta; RM-6 cubre sólo la cara (AC#4).
- **ADR**: §tripwires materializado (candidate follow-up → gate implementado, nombrando los 3 tests); header `Status` `design; not yet implemented` → `implemented` (estaba stale desde #464; RM-6 cierra la épica). D1–D9 y `Date` intactos (AC#5).
- **Reconciliación sprint-status**: `rm-3`/`rm-4` ya quedaron `done` en `main` (commit `6f30eb50`, independiente), así que tras el rebase el diff de RM-6 en sprint-status es sólo `rm-6` `backlog` → `in-progress` → `review`.
- **Security review**: el diff no toca ninguna clase de ataque (sin ruta/DTO/SQL/secreto/migración/`security.yaml`/cabeceras); el cambio *refuerza* seguridad (dos tripwires anti-deriva-ABAC pasan a fallo de CI). `PRODUCTION_SECURITY_CHECKLIST.md` no requiere edición (sin patrón de seguridad nuevo).
- **Gotchas confirmados**: segfault del web worker en stan/unit (exit 137/139) → `PHP_SERVICE=messenger_worker`; cs-fixer separó las `const` con línea en blanco (formato esperado); PHPCS exigió ≤120 col (firma del test envuelta + constantes de voto aliasadas `self::GRANTED`/`self::DENIED`).

### File List

Added:

- `api/tests/Unit/Iam/Identity/Infrastructure/Security/AuthorizationCoreIsClosedForModificationTest.php`
- `api/tests/Unit/Iam/Identity/Infrastructure/Security/PermissionVoterDoesNotEvaluateSubjectTest.php`
- `_bmad-output/implementation-artifacts/rm-6-gate-ocp-congelar-core.md` (esta historia)

Modified:

- `docs/adr/rbac-authorization-model.md` (§tripwires → gate implementado; `Status` → `accepted — implemented`)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (rm-6 → review; rm-3/rm-4 ya `done` en main vía `6f30eb50`)

## Change Log

- 2026-07-08 — RM-6 creada (create-story + dev en un PR): gate OCP ejecutable (mitad cara de los dos tripwires) + reconciliación sprint-status. Status → ready-for-dev.
- 2026-07-08 — RM-6 implementada: 2 gates de arquitectura (`AuthorizationCoreIsClosedForModificationTest` = core-set-invariance + puerto cerrado; `PermissionVoterDoesNotEvaluateSubjectTest` = `subject:` sin evaluar, estructural); ADR §tripwires materializado + `Status` sincronizado a implemented; sprint-status rm-6 → review (rm-3/rm-4 ya `done` en main vía `6f30eb50`, rebase). Validado con RED controlado (revertido). `make php.quality` EXIT=0, `make php.unit` 1606 verde. Status → review.

## Review Findings

Code review — 2026-07-09 (PR #465; capas adversariales: Blind Hunter · Edge-Case Hunter · Acceptance Auditor). **6/6 ACs MET; ningún hallazgo bloqueante** (diff sólo tests + doc; refuerza seguridad; 0 cambios en `api/src/`). Hallazgos = robustez/completitud del gate.

- [x] [Review][Decision→Resuelto] Gate `subject:` acoplado al nombre literal `$subject` + guard file-global — `PermissionVoterDoesNotEvaluateSubjectTest` tokeniza buscando el identificador `$subject`. `Voter::voteOnAttribute` no fija el nombre del parámetro en un override, así que renombrar el subject de `voteOnAttribute` (p.ej. `$object`) y leerlo para decidir, dejando `supports()` con `$subject`, dejaba el gate VERDE (verificado empíricamente por Blind Hunter). Consultado con arquitecto (Winston) + dev (Amelia) → **defensa en profundidad**. RESUELTO: (1) `PermissionVoterTest::testItDoesNotReadTheSubject` → property test `testTheDecisionIsIndependentOfTheSubject` (política **denegante** + 3 subjects distintos; voto y llamadas a la policy invariantes ∀ subject; name-agnostic → inevadible por renombrado); (2) guard file-global → `testTheSubjectParameterKeepsItsCanonicalName` (name-pin por reflexión de `supports`+`voteOnAttribute` param pos-1 = `subject`, method-specific + anti-vacío); (3) `subjectOccurrences`/`partition` colapsados en `subjectReads` (filtro depth-0 vía `array_filter`, CC≤9); (4) docblock honesto (deja de prometer «strictly stronger»). RED controlado: el bypass exacto de D1 ahora falla en el name-pin Y en la property test (revertido). `make php.unit`/`php.stan`/`php.quality` verdes.
- [x] [Review][Patch→Resuelto] Freeze del puerto ignora nulabilidad — `typeName()` ahora prefija `?` cuando `allowsNull()`, así un `permits(...): ?bool` o `?array` rompe el freeze (el union-widening ya se cazaba vía `ReflectionUnionType`→null). RED controlado: widen a `?bool` → `testTheAuthorizationPolicyPortContractIsClosed` falla (`bool` vs `?bool`), revertido. [`AuthorizationCoreIsClosedForModificationTest.php` `typeName()`]
- [x] [Review][Patch→Resuelto] Higiene del sentinel OCP — single-sourced: el data provider pasa sólo la acción y el permiso se compone con `self::UNKNOWN_RESOURCE . '.' . $action` en el test, de modo que el drive-through y `testTheTestResourceStaysUnknownToThePolicy` no pueden derivar a sentinels distintos. (La amplitud del substring-guard se deja a propósito: dirección segura —sólo obliga a re-elegir sentinel, nunca enmascara una rotura OCP— y estrecharlo exigiría tokenizar los mapas = sobre-ingeniería, YAGNI.) [`AuthorizationCoreIsClosedForModificationTest.php` provider + `typeName()`]

Descartados (ruido/aceptable): closure `use($subject)` marcado como lectura (over-strict, no alcanzable hoy, sin closures en el voter); `fn`/`function` arma `atSignature` incondicional (exótico, acotado por el balanceo de `token_get_all`); referencias `D9` en comentarios = trazabilidad legítima a un ADR durable (no es ID de historia/NFR prohibido). Ningún `defer`.
