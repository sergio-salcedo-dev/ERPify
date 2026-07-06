---
stepsCompleted: ['step-01-validate-prerequisites', 'step-02-design-epics', 'step-03-create-stories']
inputDocuments:
  - docs/adr/rbac-authorization-model.md
  - _bmad-output/planning-artifacts/arch-addendum-rbac-authorization-model.md
  - _bmad-output/planning-artifacts/arch-addendum-auth-rbac.md
  - docs/adr/auth-rbac-subsystem.md
  - _bmad-output/planning-artifacts/epics-auth-foundation.md
  - docs/adr/regulatory-audit-trail.md
  - docs/rules/security.md
  - docs/api-error-contract.md
scope: >-
  Modelo RBAC de autorización transversal (PR-1…PR-6 del DAG del addendum): el modelo
  `Permission = (resource, action)` que toda entidad futura del ERP+CRM hereda. Núcleo = `PermissionVoter` +
  VO `Permission` + puerto `AuthorizationPolicy` + `StaticAuthorizationPolicy`, empaquetado en
  `Backoffice/Identity/Infrastructure/Security` con interfaces neutrales. `Backoffice/Bank` + `Backoffice/BankAccount`
  = 1ª rebanada de validación; las 2 rutas de lectura de `Backoffice/Audit` migran a la misma gramática. EXTIENDE
  el subsistema auth/RBAC ya en vigor (SI-1…SI-5); NO revoca nada del hermano. NO diseña row-level scope / ABAC:
  los dos tripwires (política = datos; `subject:` sin evaluar) son la frontera.
---

# ERPify — Modelo RBAC de autorización transversal — Desglose de épica

## Overview

Desglosa la épica **«RBAC authorization model» (PR-1…PR-6)** definida en el DAG de
[`arch-addendum-rbac-authorization-model.md`](./arch-addendum-rbac-authorization-model.md), cuyas decisiones fija
[`docs/adr/rbac-authorization-model.md`](../../docs/adr/rbac-authorization-model.md) (D1–D9).

El hueco que cubre: hoy la autorización es un **puro role-check** — `#[IsGranted('ROLE_AUDIT_READER')]` en las 2 rutas
de audit (decidido por el `RoleVoter` nativo), y todo el resto de `/api` tras el catch-all
`IS_AUTHENTICATED_FULLY`: **cualquier usuario autenticado alcanza toda ruta de negocio**, incluido todo el CRUD de
`Bank`/`BankAccount`. **No hay granularidad por recurso/acción.** Esta épica introduce esa granularidad como **un
único modelo transversal** para todo el ERP+CRM (facturación, inventario, tesorería, contactos, oportunidades,
productos, almacenes, órdenes de compra…), con banks/accounts como primer slice de validación del objetivo OCP.

**Continuidad con el hermano (extiende, no revoca):** la fundación auth/RBAC ya shippeó (firewall de sesión, `User`
libre de framework en `Backoffice/Identity`, enum `Role` con un único caso `AUDIT_READER`) y **Epic 3 cerró**. Esta
épica **extiende SI-5** (rol → también permiso) y respeta SI-1…SI-5 de
[`arch-addendum-auth-rbac.md`](./arch-addendum-auth-rbac.md) /
[`docs/adr/auth-rbac-subsystem.md`](../../docs/adr/auth-rbac-subsystem.md). El precedente de corte por addendum es
[`epics-auth-foundation.md`](./epics-auth-foundation.md).

**Frontera explícita (RBAC, no ABAC):** esta épica diseña **autorización** (*¿puede el sujeto ejecutar esta acción?*)
y deja fuera, por diseño, la **visibilidad/row-level scope** (*¿sobre qué subconjunto de datos?*). Dos tripwires
objetivos marcan el límite; cruzar cualquiera es **un ADR nuevo, otra capacidad**: (1) la política contiene sólo datos,
nunca algoritmo; (2) el `subject:` que el voter recibe permanece **sin evaluar**. La puerta row-level se deja
**abierta, gratis y sin construir** (D9).

## Requirements Inventory

> **Derivado de un ADR ya aceptado.** Este inventario **no** es independiente del diseño: las FR/NFR destilan
> decisiones ya ratificadas en [`docs/adr/rbac-authorization-model.md`](../../docs/adr/rbac-authorization-model.md)
> (D1–D9) — por eso varias nombran artefactos concretos (`PermissionVoter`, `AuthorizationPolicy`,
> `StaticAuthorizationPolicy`, constantes por módulo). El objetivo aquí es **trazabilidad e implementabilidad**, no
> re-abrir el diseño. La cobertura decisión→requisito se verifica en «ADR Decision Coverage» al final del inventario.

### Functional Requirements

FR1: **Permiso = valor `(resource, action)`** — un permiso es el string canónico `"<resource>.<action>"`
(`bank.read`, `bankAccount.changeStatus`, `auditTrail.read`) modelado como VO `final readonly` (`Permission`);
**nunca** una entidad `Permission { id }` ni una tabla. Mantenerlo valor hace que estático→configurable sea un swap
del store, no del modelo (D1, SI-6).

FR2: **`PermissionVoter` sobre el puerto `AuthorizationPolicy`** — un único voter custom (el primero del codebase)
soporta atributos con forma `<resource>.<action>` y delega la decisión al puerto `AuthorizationPolicy`. Coexiste con
los voters nativos (que siguen sirviendo `IS_AUTHENTICATED_FULLY`): cada voter **abstiene** sobre la forma del atributo
del otro. Hace **strip del prefijo `ROLE_` en el borde** antes de consultar la política (dominio = fuente de verdad,
mapeo unidireccional), y **acepta `subject:` pero NO lo evalúa** (D4, D6, SI-7, SI-9).

FR3: **`StaticAuthorizationPolicy` declarativa** — implementa el puerto con **tres estructuras de datos
independientes** (sólo datos, **sin `if`/closure/expression**), separadas porque cada una evoluciona sin arrastrar a
las otras:

- **FR3.1 · `tierVerbs`** — `VIEWER→{read}`, `EDITOR→{read,write}`, `MANAGER→{read,write,delete}`, `ADMIN→{*}`;
  **resource-agnostic**, de modo que un recurso sólo-CRUD queda auto-cubierto con 0 ediciones.
- **FR3.2 · `explicitGrants`** — `permiso→{roles}` sólo para domain-ops y lecturas sensibles
  (`bank.close→{MANAGER,ADMIN}`, `auditTrail.read→{AUDIT_READER,ADMIN}`).
- **FR3.3 · `tierOptOut`** — recursos excluidos del auto-grant por tier (p. ej. `auditTrail`, para que un `VIEWER`
  genérico no lea el trail).

Resolución: split `(resource, action)`; concede **sii** `ADMIN`, o (`resource ∉ tierOptOut` y `action ∈` verbos del
tier del sujeto), o un rol ∈ `explicitGrants[permiso]` (D5, SI-8).

FR4: **Extender el enum de dominio `Role` con los tiers** — añadir `VIEWER`, `EDITOR`, `MANAGER`, `ADMIN` al enum
`Role` (hoy con único caso `AUDIT_READER`, que se **retiene** como rol especializado). Jerarquía conceptual
`VIEWER ⊂ EDITOR ⊂ MANAGER ⊂ ADMIN` expresada vía verbos, no vía herencia (D3, D5).

FR5: **Constantes de permiso co-localizadas por módulo** — cada módulo declara sus constantes en su borde
(`Backoffice/Bank/Infrastructure/.../BankPermission::READ = 'bank.read'`, análogo para `BankAccountPermission`),
referenciadas por el `#[IsGranted]` de ese módulo. Añadir un recurso es **aditivo**: constantes + `#[IsGranted]` +
(sólo domain-ops/lecturas sensibles) una línea de `explicitGrants` (D5, D2).

FR6: **Gateo de las rutas de `Bank`** — `#[IsGranted]` con `bank.{read,write,delete,close}` en los controladores de
`Bank`, **retirando** su cobertura por el catch-all `IS_AUTHENTICATED_FULLY`; `explicitGrants['bank.close'] =
{MANAGER, ADMIN}` + asignación de rol tier a la fixture Alice / bootstrap (si no, regresa el acceso). Primer
**tightening real de comportamiento** (PR-3; D2, D3, D9).

FR7: **Gateo de las rutas de `BankAccount` (incl. anidada)** — `#[IsGranted]` con
`bankAccount.{read,write,delete,changeStatus}` en los controladores de `BankAccount`, **incluida la ruta anidada**
`GET /banks/{id}/accounts` + `explicitGrants['bankAccount.changeStatus']`. Crea el par **nested-vs-colección** con
acceso potencialmente divergente sobre la misma raíz → **depende de FR9/PR-2** (PR-4; D2, D3, D9).

FR8: **Migración de `Audit` a la gramática de permisos** — swap `#[IsGranted('ROLE_AUDIT_READER')]` →
`#[IsGranted('auditTrail.read')]` en las 2 rutas de audit; `explicitGrants['auditTrail.read'] = {AUDIT_READER,
ADMIN}`; `tierOptOut` incluye `auditTrail` (un `VIEWER` genérico **no** lee el trail — acceso sensible = explícito).
Semánticamente **equivalente** (`AUDIT_READER` sigue concediendo), independiente del slice de negocio (PR-5; D4).

FR9: **Cierre de la puerta keyset heredada (#437, co-requisito)** — discriminante base-query/route en
`QueryExecutionTrace` / `FingerprintCanonicalizer` de `Shared/Search`: un cursor acuñado en una ruta se **rechaza**
(`422 invalid-cursor`) en otra con distinto `WHERE`/`JOIN`. Cierra el bypass de privilege-scope **antes** de que
exista el par de rutas con acceso divergente (FR7) (PR-2; D9).

### NonFunctional Requirements

NFR1 (Extensibility): **OCP — núcleo cerrado a modificación** — añadir un recurso o acción **sólo puede añadir**
(constantes de permiso + `#[IsGranted]` + —sólo domain-ops/lecturas sensibles— filas en `explicitGrants`); **nunca**
modificar el `PermissionVoter`, el VO `Permission`, ni el contrato del puerto `AuthorizationPolicy`. Un recurso
**sólo-CRUD** nuevo (Invoice, Contact, …) = **0 filas de política** (SI-9; criterio de aceptación OCP del ADR). Es el
NFR **rector** de la épica: mide **capacidad de evolución**, no rendimiento ni seguridad.

NFR2 (Architectural integrity): **Tripwire 1 — política = datos, no mecanismo** — la política (codificada o persistida) contiene sólo datos
(`tier→[verbos]`, `permiso→[roles]`, opt-out sets) y **ningún algoritmo**. El primer `if (…) then grant` / closure /
expression la convierte en **motor de políticas** → **ADR nuevo** (ABAC) (SI-8).

NFR3 (Architectural integrity): **Tripwire 2 — `subject:` sin evaluar** — el voter recibe y acepta `subject:` pero **no lo lee** para decidir;
leerlo = entrar en data-scoping/ABAC. La puerta row-level permanece abierta, gratis y sin construir (SI-9, D9).

NFR4 (Security boundary): **Autorización en el borde (extiende SI-5 → SI-7)** — la decide el `PermissionVoter` **antes** de entrar a la
aplicación; **ninguna lógica de `Application`/`Domain` ramifica por rol NI por permiso**. El prefijo `ROLE_` y la
traducción permiso→decisión viven sólo en Infra; el negocio no conoce ni roles ni permisos (SI-7, D4/D6).

NFR5 (Maintainability / isolation): **Contratos neutrales + placement deptrac-legal** — el núcleo arranca en
`Backoffice/Identity/Infrastructure/Security` con **interfaces neutrales** (hablan *permisos*, *roles-como-token*,
*decisiones* — jamás `User`/`Role`/`SecurityUser`); requiere su registro en `api/tools/deptrac/deptrac.yaml` (mirror
del bloque de Identity). La promoción futura a `Shared/Authorization` es **re-empaquetado y composición, no rediseño**
de modelo ni API (D6). `php.deptrac` + `php.lint.bounded-context` verdes.

NFR6 (Reliability): **Errores por el contrato** — 403/401 fluyen por el pipeline RFC 9457 existente, **sin nuevo marker** y sin
`JsonResponse` manual; `php.lint.error-contract` verde (D4, SI-4 heredado).

NFR7 (Safety): **PR-1 aditivo — cero cambio de comportamiento** — el core (voter + VO + puerto + política + roles-tier) se
introduce **sin gatear ninguna ruta**; el sistema se comporta idéntico hasta que PR-3/PR-4/PR-5 aplican el tightening.
Orden safe-first del addendum (aditivo primero, comportamiento al final).

NFR8 (Evolvability): **Estático hoy, configurable sin rediseño** — roles y mapas son código (un deploy los cambia);
porque el permiso es valor (FR1) y la resolución va por el puerto (FR2/FR3), mover los mapas a un store DB luego
swapea sólo el adapter (`StaticAuthorizationPolicy` → `DbAuthorizationPolicy`) — el modelo, el voter, los call-sites
`#[IsGranted]` y el contrato del puerto quedan intactos (D8).

NFR9 (Transport independence): **La decisión depende sólo de `(subject, permission)`** — la autorización nunca
depende del routing HTTP, del nombre de la ruta ni de detalles de implementación del controlador. Un recurso es un
objeto de negocio gobernable, **nunca** una ruta ni un controlador (recurso ≠ ruta, permiso ≠ controlador); las rutas
son **puntos de enforcement**, no lo gobernado. Corolario directo del slice: el mismo permiso `bankAccount.read`
protege por igual la ruta de colección y la anidada `GET /banks/{id}/accounts` (D2).

### Additional Requirements

- **Registro en deptrac:** `Backoffice/Identity/Infrastructure/Security` necesita su entrada en
  `api/tools/deptrac/deptrac.yaml` como cualquier módulo (mirror del bloque de Identity ya existente).
- **Aislamiento de imports de Symfony Security:** anotado en **#438** (no bloquea este corte).
- **Rol en fixtures/bootstrap:** la fixture Alice / el bootstrap del 1er usuario deben asignar el rol tier adecuado a
  los usuarios sembrados; sin ello, PR-3/PR-4 regresan acceso en dev/test.
- **Gate OCP ejecutable (PR-6, opcional):** test de arquitectura estilo `deptrac`/`php.lint.*` que asserta que el
  core-set (voter + VO `Permission` + puerto) no cambia al añadir un recurso y que el mapa de política no contiene
  código ejecutable — convierte los dos tripwires en **fallo de CI**. Candidato de ADR §tripwires.
- **Subject como vocabulario, sin tipo (restricción de diseño, D7):** se adopta «subject» (portador de autoridad
  atribuible: `User`/`Role` hoy; API key, service account, job programado mañana) como **vocabulario arquitectónico**,
  pero **no** se materializa un tipo `AuthorizationSubject` en este corte — bajo enforcement edge-only el subject *es*
  el token de Symfony, y un único tipo de subject no compra más que indirección (YAGNI; el `ActorType` existente ya
  reserva el futuro no-humano). No genera requisito funcional nuevo; se registra para que D7 no quede huérfano.

### UX Design Requirements

**N/A** — modelo de autorización **backend puro**. No hay documento UX ni superficie de UI en este corte: la
autorización se decide en el borde HTTP (`#[IsGranted]` + voter) antes del controlador; no introduce pantallas,
componentes ni tokens de diseño. (Un futuro editor de roles/permisos sería otra épica, gated por el trigger de D8.)

### ADR Decision Coverage

Verificación de que ninguna decisión del ADR queda huérfana. Esta tabla es **decisión→requisito**; la de
**requisito→historia** (`FR Coverage Map`) se rellena en el Paso 2.

| Decisión ADR | Requisito(s) |
|--------------|--------------|
| D1 — permiso = valor `(resource, action)` | FR1 |
| D2 — recurso ≠ ruta/contexto | FR5, FR6, FR7, NFR9 |
| D3 — acción = capacidad; CRUD semilla | FR4, FR6, FR7 |
| D4 — un voter sobre el puerto; retiro de role-checks de negocio | FR2, FR8, NFR4, NFR6 |
| D5 — constantes por módulo + política tier declarativa | FR3 (FR3.1–FR3.3), FR5 |
| D6 — placement en `Identity/Infra/Security`, contratos neutrales | NFR5 (+ strip `ROLE_` en el borde, FR2) |
| D7 — subject sólo como vocabulario, sin tipo | restricción de diseño (Additional Requirements); sin FR nuevo |
| D8 — estático hoy, configurable sin rediseño | NFR8 |
| D9 — puerta row-level abierta sin construir; co-requisito #437 | FR9, NFR3 |

Invariantes: **SI-6**→FR1 · **SI-7**→NFR4 · **SI-8**→NFR2 · **SI-9**→NFR1/NFR3. Sin decisiones ni invariantes
huérfanos.

### FR Coverage Map

Todas las FR pertenecen a la única épica `rbac-authorization-model`; el mapa desglosa a nivel de historia
(RM-N ⟷ PR-N):

- **FR1 → RM-1** — VO `Permission` (permiso = valor).
- **FR2 → RM-1** — `PermissionVoter` sobre el puerto `AuthorizationPolicy`.
- **FR3 (FR3.1/FR3.2/FR3.3) → RM-1** — `StaticAuthorizationPolicy`: `tierVerbs` / `explicitGrants` / `tierOptOut`.
- **FR4 → RM-1** — extender el enum `Role` con los tiers.
- **FR5 → RM-1 (convención) · RM-3 (`BankPermission`) · RM-4 (`BankAccountPermission`)** — constantes por módulo.
- **FR6 → RM-3** — gateo de las rutas de `Bank`.
- **FR7 → RM-4** — gateo de `BankAccount` incl. ruta anidada (depende de RM-2).
- **FR8 → RM-5** — migración de audit a `auditTrail.read`.
- **FR9 → RM-2** — cierre del fingerprint keyset (#437).

Cobertura NFR: **NFR1–NFR8** nacen con RM-1 y se verifican transversalmente en cada historia (gates + AC de
frontera); **NFR9** (transport independence) se ejercita en RM-3/RM-4 (mismo permiso para colección y ruta anidada);
**NFR1/NFR2/NFR3** se convierten en gate ejecutable en RM-6. Sin FR ni NFR huérfanos.

## Epic List

### Epic rbac-authorization-model: Autorización RBAC por recurso y acción

Introduce el modelo `Permission = (resource, action)` transversal a todo el ERP+CRM: tras la épica, cada rol
(`VIEWER ⊂ EDITOR ⊂ MANAGER ⊂ ADMIN` + `AUDIT_READER`) puede o no leer/escribir/borrar/operar sobre cada recurso,
decidido en el borde HTTP por un `PermissionVoter` sobre el puerto `AuthorizationPolicy`. `Backoffice/Bank` +
`Backoffice/BankAccount` quedan gobernados por recurso/acción (retirando el catch-all `IS_AUTHENTICATED_FULLY`), las
2 rutas de audit migran a la misma gramática, y **cualquier recurso sólo-CRUD futuro hereda el modelo con 0 filas de
política** (objetivo OCP). **FRs:** FR1–FR9. **NFRs:** NFR1–NFR9.

**Historias (RM-N ⟷ PR-N del DAG; detalle en el Paso 3):**

- **RM-1 (PR-1) — authorization core (aditivo):** VO `Permission` + puerto `AuthorizationPolicy` +
  `StaticAuthorizationPolicy` (`tierVerbs`/`explicitGrants`/`tierOptOut`) + `PermissionVoter` (strip `ROLE_` en el
  borde, `subject:` **no** evaluado) + extender el enum `Role` con `VIEWER/EDITOR/MANAGER/ADMIN`. **Ninguna ruta
  gateada** → cero cambio de comportamiento. **Fija dos cosas de raíz** (pre-mortem R6/R3): (a) la interacción
  voter↔estrategia de decisión — un `VIEWER` autenticado que pasa `IS_AUTHENTICATED_FULLY` es **denegado**
  `bank.write` (el catch-all autentica, `#[IsGranted]` autoriza, y la estrategia no deja que "autenticado" satisfaga
  un permiso); (b) el tripwire barato **política = sólo datos** (0 `if`/closure/expression en
  `StaticAuthorizationPolicy`), verificado **ya aquí**, no diferido a RM-6. — FR1, FR2, FR3 (FR3.1–FR3.3), FR4,
  FR5 (convención); NFR1–NFR8 (incl. mitad no-algoritmo de NFR2).
- **RM-2 (PR-2) — cierre keyset #437 (gate duro de RM-4):** discriminante base-query/route en
  `QueryExecutionTrace` / `FingerprintCanonicalizer` que cubre el **base-predicate `WHERE` y el `JOIN`/identidad de
  ruta** → un cursor acuñado en una ruta se rechaza (`422 invalid-cursor`) en otra distinta (test de replay
  colección→anidada). **RM-4 no puede mergearse sin RM-2 en `main`** (dependencia dura, no nota): sin ella el par
  nested/colección es un bypass de privilege-scope (pre-mortem R2). — FR9; NFR3.
- **RM-3 (PR-3) — banks slice (1er tightening real):** `BankPermission` +
  `#[IsGranted('bank.{read,write,delete,close}')]` en los controladores de `Bank` + `explicitGrants['bank.close']` +
  rol tier en la fixture Alice / bootstrap. **Incluye el backfill de rol por defecto a los principals existentes**
  (migración o runbook) **antes** del merge de gateo → ningún usuario autenticado pierde acceso que ya tenía salvo
  intención explícita (pre-mortem R1). Fija también anónimo→**401** / autenticado-sin-permiso→**403** por el pipeline
  RFC 9457 (R5). — FR6, FR5; NFR6, NFR9.
- **RM-4 (PR-4) — bank-accounts slice (nested + colección):** `BankAccountPermission` +
  `#[IsGranted('bankAccount.{read,write,delete,changeStatus}')]` incl. la ruta anidada `GET /banks/{id}/accounts` +
  `explicitGrants['bankAccount.changeStatus']`. **Depende de RM-2.** — FR7, FR5; NFR9.
- **RM-5 (PR-5) — migración de audit:** swap `#[IsGranted('ROLE_AUDIT_READER')]` → `#[IsGranted('auditTrail.read')]`
  en las 2 rutas + `explicitGrants['auditTrail.read']` + `tierOptOut ⊇ {auditTrail}`. Semánticamente **equivalente**,
  independiente del slice de negocio. — FR8.
- **RM-6 (PR-6) — gate OCP (mitad cara, opcional):** test de arquitectura que asserta que el **core-set** (voter +
  VO + puerto) no cambia al añadir un recurso, y sube a CI el tripwire `subject:` **sin evaluar**. La mitad barata
  (**política = sólo datos**) ya vive en RM-1, no aquí. — NFR1, NFR3 (verificados como test).

**Orden de ejecución/merge (safe-first — aditivo primero, comportamiento al final):**
`RM-1 → RM-2 → RM-5 → RM-3 → RM-4` · `RM-6` tras `RM-1`. La numeración RM-N sigue el PR-N del DAG; el **orden de
merge difiere y es intencional** (los slices de comportamiento van al final, tras cerrar la puerta keyset y ejercitar
la gramática con el swap equivalente de audit).

**Dependencias:** RM-1 desbloquea todo; **RM-4 exige RM-2 como gate duro** (RM-4 no mergea sin RM-2 en `main`; par de
acceso divergente sobre la misma raíz); RM-2/RM-3/RM-5 son independientes entre sí; RM-6 sólo requiere RM-1. Cada
historia cabe en el contexto de un único dev agent. Ninguna historia depende de una historia **posterior** en su
orden de merge.

---

## Epic rbac-authorization-model: Autorización RBAC por recurso y acción

Introduce el modelo `Permission = (resource, action)` transversal: un `PermissionVoter` sobre el puerto
`AuthorizationPolicy` decide en el borde HTTP, `Bank`/`BankAccount`/`Audit` quedan gobernados por recurso/acción, y
todo recurso futuro hereda el modelo con 0 filas de política. **Orden de merge safe-first**
(RM-1 → RM-2 → RM-5 → RM-3 → RM-4; RM-6 tras RM-1); la numeración RM-N sigue el PR-N del DAG. Ninguna historia depende
de una historia posterior en su orden de merge.

### Story RM-1 (PR-1): Núcleo de autorización — VO, puerto, política declarativa, voter y roles-tier (aditivo)

Como plataforma de ERPify,
quiero un modelo de autorización `(resource, action)` decidido por un voter sobre un puerto declarativo,
para que cualquier recurso futuro se gobierne por permiso sin tocar el núcleo — introducido sin cambiar todavía ningún
comportamiento.

**Acceptance Criteria:**

**Given** el núcleo empaquetado en `Backoffice/Identity/Infrastructure/Security`,
**When** se modela el permiso,
**Then** `Permission` es un value object `final readonly` con el string canónico `"<resource>.<action>"`; **no** existe
entidad `Permission { id }` ni tabla (FR1, SI-6).

**Given** la impl `StaticAuthorizationPolicy` del puerto `AuthorizationPolicy`,
**When** se define la política,
**Then** consta de tres estructuras de **sólo datos** — `tierVerbs`, `explicitGrants`, `tierOptOut` — sin ningún
`if`/closure/expression, y un test de arquitectura **falla** si la política contiene código ejecutable (FR3, NFR2
mitad no-algoritmo, R3).

**Given** la resolución de la política,
**When** se evalúa `(resource, action)` para un sujeto,
**Then** concede **sii** `ADMIN`, o (`resource ∉ tierOptOut` **y** `action ∈` verbos del tier del sujeto), o un rol
∈ `explicitGrants[permiso]` (FR3.1, FR3.2, FR3.3).

**Given** el `PermissionVoter` (primer voter custom del codebase),
**When** recibe un atributo con forma `<resource>.<action>`,
**Then** hace strip del prefijo `ROLE_` en el borde, delega la decisión al puerto, y **acepta pero no lee** `subject:`;
abstiene sobre atributos que no tienen esa forma (FR2, NFR3, SI-7/SI-9).

**Given** el catch-all `^/api → IS_AUTHENTICATED_FULLY` y un `#[IsGranted('bank.write')]`,
**When** un `VIEWER` autenticado —que sí pasa `IS_AUTHENTICATED_FULLY`— invoca la acción,
**Then** es **denegado**: la estrategia de decisión de acceso no deja que "autenticado" satisfaga un atributo de
permiso (FR2, NFR4, R6).

**Given** el enum de dominio `Role`,
**When** se extiende,
**Then** añade `VIEWER/EDITOR/MANAGER/ADMIN` (con `AUDIT_READER` retenido); los `->value` viven **sin** prefijo
`ROLE_` y **ninguna** lógica de `Application`/`Domain` ramifica por rol ni por permiso (FR4, NFR4, SI-5→SI-7).

**Given** el placement y los contratos neutrales,
**When** se corren los gates,
**Then** `php.deptrac` + `php.lint.bounded-context` + `php.lint.error-contract` verdes; el puerto habla
*permisos*/*roles-token*/*decisiones*, jamás `User`/`Role`/`SecurityUser`; `Backoffice/Identity/Infrastructure/Security`
queda registrado en `api/tools/deptrac/deptrac.yaml` (NFR5, NFR6).

**Given** que **ninguna** ruta lleva aún `#[IsGranted('resource.action')]`,
**When** se despliega RM-1,
**Then** el comportamiento observable del sistema es idéntico al previo (aditivo puro); se **cierra la decisión
abierta del rol por defecto del bootstrap** (candidato `ADMIN`) (NFR7).

### Story RM-2 (PR-2): Cierre del fingerprint keyset (#437) — gate duro de RM-4

Como responsable de seguridad,
quiero que un cursor keyset acuñado en una ruta sea rechazado en otra con distinto conjunto base,
para que, cuando dos rutas sobre la misma raíz tengan acceso divergente, un cursor no sirva para saltar el alcance de
privilegio.

**Acceptance Criteria:**

**Given** el fingerprint del cursor en `Shared/Search` (`QueryExecutionTrace` / `FingerprintCanonicalizer`),
**When** se acuña un cursor,
**Then** incorpora un discriminante que cubre el **base-predicate `WHERE`** y el **`JOIN`/identidad de ruta**, no sólo
las columnas de orden (FR9, D9).

**Given** un cursor acuñado en la ruta colección `GET /bank-accounts`,
**When** se presenta en la ruta anidada `GET /banks/{id}/accounts` (distinto `WHERE`),
**Then** se rechaza con `422 invalid-cursor` por el pipeline RFC 9457 (FR9, R2).

**Given** el mismo cursor re-presentado en su **propia** ruta,
**When** se pagina,
**Then** funciona sin regresión — la paginación keyset existente queda intacta (FR9).

**Given** la dependencia de orden,
**When** se planifica el merge,
**Then** **RM-4 no se mergea sin RM-2 en `main`**: un test/checklist falla si existe el par de rutas divergentes sin
el discriminante (gate duro, no nota) (R2).

### Story RM-5 (PR-5): Migración de las rutas de audit a `auditTrail.read`

Como auditor de cumplimiento,
quiero que las lecturas del trail se protejan con `auditTrail.read` en vez de `ROLE_AUDIT_READER`,
para que todo el sistema hable una sola gramática de autorización sin cambiar quién puede leer el trail.

**Acceptance Criteria:**

**Given** las 2 rutas de audit (`AuditTimelineSearchController`, `AuditEventDetailController`),
**When** se migran,
**Then** llevan `#[IsGranted('auditTrail.read')]` en lugar de `#[IsGranted('ROLE_AUDIT_READER')]` (FR8, D4).

**Given** la política,
**When** se configura,
**Then** `explicitGrants['auditTrail.read'] = {AUDIT_READER, ADMIN}` y `auditTrail ∈ tierOptOut` (FR8).

**Given** un `AUDIT_READER` o un `ADMIN`,
**When** accede a una ruta de audit,
**Then** **concede** — la equivalencia con el role-check previo se preserva (FR8, R4).

**Given** un `VIEWER`/`EDITOR`/`MANAGER` genérico (sin `AUDIT_READER`),
**When** accede a una ruta de audit,
**Then** **deniega** con 403: el trail no se auto-concede por tier (acceso sensible = explícito; exposición ISO
evitada) (FR8, R4).

**Given** los principals existentes antes del swap,
**When** se compara su acceso pre/post,
**Then** conservan **exactamente** su acceso previo al trail — sin regresión y sin sobre-concesión (R4).

### Story RM-3 (PR-3): Gateo de las rutas de `Bank` — primer tightening real

Como operador del backoffice,
quiero que las acciones sobre bancos exijan el permiso correspondiente por recurso/acción,
para que sólo los roles autorizados lean, editen, borren o cierren bancos, en vez de que cualquier autenticado alcance
toda operación.

**Acceptance Criteria:**

**Given** el borde de `Backoffice/Bank`,
**When** se declaran las constantes,
**Then** existe `BankPermission` co-localizada con `READ/WRITE/DELETE/CLOSE = 'bank.{read,write,delete,close}'`
(FR5, D2).

**Given** los controladores de `Bank`,
**When** se gatean,
**Then** cada uno lleva `#[IsGranted('bank.<action>')]` y se **retira** su cobertura por el catch-all
`IS_AUTHENTICATED_FULLY`; `explicitGrants['bank.close'] = {MANAGER, ADMIN}` (FR6, D3).

**Given** un `VIEWER`,
**When** invoca `bank.read` frente a `bank.write`,
**Then** el read concede y el write deniega (403), cubierto por `tierVerbs` sin fila de política (FR6, FR3.1).

**Given** los **principals existentes** (no sólo la fixture Alice),
**When** se despliega el gateo,
**Then** un backfill (migración/runbook) les asigna el rol tier por defecto **antes** del merge → ningún autenticado
pierde acceso que ya tenía salvo intención explícita, documentada (FR6, R1).

**Given** una request anónima a una ruta de bank gateada,
**When** el firewall/voter la rechaza,
**Then** responde **401** (no autenticado); un autenticado-sin-permiso responde **403**; ambos por RFC 9457 sin
`JsonResponse` manual (NFR6, R5).

**Given** la fixture Alice / el bootstrap,
**When** se siembra,
**Then** reciben el rol tier que les permite operar en dev/test, evitando regresión de acceso en las suites (FR6).

### Story RM-4 (PR-4): Gateo de `BankAccount` — colección + ruta anidada (depende de RM-2)

Como operador del backoffice,
quiero que las acciones sobre cuentas bancarias exijan permiso por recurso/acción en ambas rutas (colección y
anidada),
para que el acceso a cuentas sea coherente y no se pueda saltar por la ruta anidada.

**Acceptance Criteria:**

**Given** el borde de `Backoffice/BankAccount`,
**When** se declaran las constantes,
**Then** existe `BankAccountPermission` con `READ/WRITE/DELETE/CHANGE_STATUS =
'bankAccount.{read,write,delete,changeStatus}'` y `explicitGrants['bankAccount.changeStatus']` definido (FR5, FR7, D3).

**Given** los controladores de `BankAccount`, **incluida** la ruta anidada `GET /banks/{id}/accounts`,
**When** se gatean,
**Then** cada uno lleva `#[IsGranted('bankAccount.<action>')]`; **el mismo permiso protege por igual la colección y la
anidada** (recurso ≠ ruta) (FR7, NFR9).

**Given** RM-2 en `main`,
**When** se pagina la ruta anidada con un cursor acuñado en la colección,
**Then** se rechaza `422 invalid-cursor` — RM-4 no habría podido mergearse sin RM-2 (R2, dependencia dura).

**Given** un `VIEWER`,
**When** invoca `bankAccount.read` frente a `bankAccount.changeStatus`,
**Then** read concede y changeStatus deniega (403) salvo rol listado en `explicitGrants` (FR7).

**Given** los principals existentes,
**When** se despliega,
**Then** el backfill de RM-3 ya cubre el tier; ninguna cuenta accesible se pierde salvo intención (R1); denegaciones
por RFC 9457 (NFR6).

### Story RM-6 (PR-6): Gate OCP ejecutable — congelar el core (mitad cara, opcional)

Como arquitecto,
quiero un test de arquitectura que congele el core de autorización,
para que añadir un recurso nuevo no pueda modificar el voter/VO/puerto ni convertir la política en un motor.

**Acceptance Criteria:**

**Given** el core-set (voter + VO `Permission` + puerto `AuthorizationPolicy`),
**When** se añade un recurso de prueba,
**Then** un test de arquitectura asserta que **ninguno** de esos artefactos cambia (core-set-invariance) (NFR1,
criterio OCP).

**Given** el `subject:` del voter,
**When** corre el gate,
**Then** un test asserta que el voter **no lee** `subject:` para decidir (tripwire 2 elevado a CI) (NFR3, D9).

**Given** que la mitad "política = sólo datos" ya se verifica en RM-1,
**When** se define RM-6,
**Then** cubre **sólo** la mitad cara (invariancia del core-set + `subject:`); es **opcional** y candidato de ADR
§tripwires.

**Given** un recurso sólo-CRUD nuevo (p. ej. Invoice, Contact),
**When** se añade,
**Then** requiere **0 filas de política** (auto-cubierto por `tierVerbs`) — el objetivo OCP en su forma más fuerte
(NFR1).

## Riesgos / decisiones abiertas

Extraídos de un pre-mortem del rollout; cada uno se traduce en AC concretos en el Paso 3.

- **R1 · Lock-out de principals existentes.** El gateo default-deny por tier deja fuera a los usuarios reales sin rol
  asignado (sólo la fixture lo tenía). **Mitigación:** backfill de rol por defecto a los principals existentes
  (migración/runbook) **antes** del merge de RM-3/RM-4; AC «ningún autenticado pierde acceso previo salvo intención».
- **R2 · Bypass keyset como escalada de privilegios.** Un cursor replay-eado colección→anidada pagina datos no
  autorizados si RM-2 aterriza incompleto o RM-4 mergea antes. **Mitigación:** el fingerprint cubre `WHERE` +
  `JOIN`/ruta con test de replay `422`; **RM-2 = gate duro** de RM-4.
- **R3 · Deriva silenciosa a ABAC.** Un `if` en la política cruza el tripwire sin que CI lo cace si RM-6 se salta.
  **Mitigación:** la mitad barata (política = sólo datos) se verifica **en RM-1**; RM-6 (opcional) cubre sólo la mitad
  cara (core-set-invariance + `subject:` en CI).
- **R4 · Regresión/exposición en audit.** El swap «equivalente» puede quitar acceso a `ADMIN` o dárselo a un `VIEWER`.
  **Mitigación:** matriz bidireccional en RM-5 (`AUDIT_READER`/`ADMIN` conceden; tier genérico denegado por
  `tierOptOut`; principals pre-swap conservan su acceso exacto).
- **R5 · Regresión del error-contract 401 vs 403.** Una ruta gateada devuelve el estado equivocado o un `JsonResponse`
  manual en el deny. **Mitigación:** AC en RM-1/RM-3 — anónimo→401, autenticado-sin-permiso→403 por RFC 9457, sin
  marker nuevo; `php.lint.error-contract` verde.
- **R6 · Colisión de votos voter/nativo.** La estrategia de decisión deja que `IS_AUTHENTICATED_FULLY` satisfaga un
  permiso. **Mitigación:** RM-1 fija la interacción con test — `VIEWER` autenticado **denegado** `bank.write`.
- **Decisión abierta · Rol por defecto del bootstrap.** ¿Qué tier recibe el 1er usuario / los usuarios sembrados?
  (candidato: `ADMIN` para el bootstrap; tier explícito por fixture en dev/test). A cerrar al escribir RM-1/RM-3.
