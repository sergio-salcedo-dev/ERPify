# Arch addendum — modelo RBAC de autorización transversal (scoped)

> **Estado:** `frozen-ready` · diseño · **extiende el subsistema auth/RBAC** ([`arch-addendum-auth-rbac.md`](arch-addendum-auth-rbac.md)) · **Alcance:** el modelo `Permission = (Resource, Action)` que toda entidad futura del ERP+CRM hereda; `Backoffice/Bank` + `Backoffice/BankAccount` = 1ª rebanada de validación; las 2 rutas de lectura de `Backoffice/Audit` migran a la misma gramática.
> **Decisiones (el *qué* y el *por qué*):** [`../../docs/adr/rbac-authorization-model.md`](../../docs/adr/rbac-authorization-model.md).
> **Jerarquía:** `epics.md` **>** este addendum. No contradice SI-1…SI-5 del hermano; **extiende SI-5** (rol → también permiso). Cortar la épica/historias es el paso BMAD siguiente (`bmad-create-epics-and-stories`), no esta fase.
> **methodology:** contract-first scoped sobre sistema maduro — invariantes globales mínimos + localización de decisiones por PR + DAG; se omite el march clásico de 8 pasos de `bmad-create-architecture` (no hay starter template; modelo Q0–Q8 ya congelado en el brainstorm). Precedente: [`arch-addendum-auth-rbac.md`](arch-addendum-auth-rbac.md).

Método contract-first scoped: no repite el ADR ni describe estado actual; fija los **invariantes globales mínimos que el ADR añade al subsistema**, **localiza cada decisión en su PR** y da el **DAG de dependencias** para que la épica sea *dev-able*.

## System Invariants (globales — se cumplen en todo el subsistema de autorización)

Continúan la numeración del hermano (SI-1…SI-5); estos cuatro son los que introduce el modelo de permisos.

- **SI-6 · Permiso = valor.** Un permiso es el valor derivado `(resource, action)` (`"bank.read"`) — nunca una entidad `Permission { id }` ni una tabla. Mantenerlo valor hace que estático→configurable sea un swap del store, no del modelo (ADR D1/D8).
- **SI-7 · Autorización en el borde (extiende SI-5).** La decide el `PermissionVoter` antes de entrar a la aplicación; **ninguna lógica de `Application`/`Domain` ramifica por rol NI por permiso**. El prefijo `ROLE_` y la traducción permiso→decisión viven sólo en Infra; el negocio no conoce ni roles ni permisos (ADR D4/D6).
- **SI-8 · Política declarativa, no mecanismo.** El mapa de política es **datos** (`tier → [verbos]`, `permiso → [roles]`, set de opt-out por recurso). El primer `if/closure/expression` lo convierte en motor de políticas → **ADR nuevo** (ABAC). Es uno de los dos tripwires que impiden derivar a ABAC (ADR §tripwires).
- **SI-9 · Recurso nuevo = additive-only (OCP).** Añadir un recurso sólo puede *añadir* (constantes de permiso en su borde + `#[IsGranted]` + —sólo para domain-ops/lecturas sensibles— filas en `explicitGrants`); **nunca modificar** el `PermissionVoter`, el valor `Permission` ni el contrato del puerto `PermissionPolicy`. La puerta row-level (`subject:` del voter) permanece **sin evaluar** (2º tripwire).

## Localización de decisiones por PR

*Core = `PermissionVoter` + valor `Permission` + puerto `PermissionPolicy` + `StaticPermissionPolicy` (tierVerbs + explicitGrants + tierOptOut).* Todo el core arranca en `Backoffice/Identity/Infrastructure/Security` con **interfaces neutrales** (hablan permisos/roles-como-token/decisiones, jamás `User`/`Role`/`SecurityUser`).

| PR / Story | Decisiones ADR | Costura / artefactos que toca |
|------------|----------------|-------------------------------|
| **PR-1 — authorization core** | D1, D4, D5, D6, D7, D8 | VO `Permission` + puerto `PermissionPolicy` (neutral) + `StaticPermissionPolicy` (mapas declarativos) + `PermissionVoter` (strip `ROLE_` en el borde, `subject:` aceptado y **no** evaluado) + extender enum `Role` con `VIEWER`/`EDITOR`/`MANAGER`/`ADMIN`. **Additive: ninguna ruta gateada aún** (sin cambio de comportamiento) |
| **PR-2 — keyset #437 (co-requisito)** | D9 | `Shared/Search`: discriminante base-query/route en `QueryExecutionTrace`/`FingerprintCanonicalizer` → un cursor acuñado en una ruta se rechaza (`422 invalid-cursor`) en otra con distinto `WHERE`/`JOIN`. **Cierra la puerta antes** de que exista el par de rutas con acceso divergente |
| **PR-3 — banks slice** | D2, D3, D9 | Constantes `BankPermission` (`bank.read/write/delete/close`) co-localizadas en el borde de `Backoffice/Bank` + `#[IsGranted]` en los controladores de Bank (retira su cobertura por el catch-all `IS_AUTHENTICATED_FULLY`) + `explicitGrants['bank.close']` + asignación de rol a la fixture Alice / bootstrap (si no, regresan acceso). **Tightening de comportamiento** |
| **PR-4 — bank-accounts slice** | D2, D3, D9 | Constantes `BankAccountPermission` (`bankAccount.read/write/delete/changeStatus`) + `#[IsGranted]` en los controladores de BankAccount, incl. la **ruta anidada** `GET /banks/{id}/accounts` + `explicitGrants['bankAccount.changeStatus']`. Depende de PR-2 (par nested-vs-colección) |
| **PR-5 — migración de audit** | D4 | Swap `#[IsGranted('ROLE_AUDIT_READER')]` → `#[IsGranted('auditTrail.read')]` en las 2 rutas de audit + `explicitGrants['auditTrail.read'] = [AUDIT_READER, ADMIN]` + `tierOptOut` incluye `auditTrail` (un VIEWER genérico NO lee el trail). Semánticamente equivalente (AUDIT_READER sigue concediendo); independiente del slice de negocio |
| **PR-6 — gate OCP (opcional)** | — | Test de arquitectura: el core-set no cambia al añadir un recurso + el mapa de política no contiene código ejecutable. Convierte los dos tripwires en fallo de CI. Candidato de ADR §tripwires |

## DAG de dependencias

```
PR-1 (core: Permission VO + PermissionPolicy + StaticPermissionPolicy + PermissionVoter + roles-tier)
  ├─> PR-2 (#437 keyset fingerprint) ──┐   [co-requisito: cierra la puerta row-level heredada]
  ├─> PR-3 (banks slice) ──────────────┤
  │                                     └─> PR-4 (bank-accounts slice: nested + colección)
  ├─> PR-5 (migración de audit → auditTrail.read)   [independiente]
  └─> PR-6 (gate OCP, opcional)
```

**Orden safe-first (aditivo primero, comportamiento al final):** PR-1 (core aditivo, sin gateo) → PR-2 (fix keyset, cierra la puerta) → PR-5 (audit, swap equivalente, ejercita la gramática sin abrir superficie nueva) → PR-3 (banks, 1er tightening real) → PR-4 (accounts, crea el par nested/colección — exige PR-2). PR-6 tras PR-1.

## Slice de validación (banks/accounts) — prueba del objetivo OCP

Vocabulario del slice, todo *additive* sobre el core:

- **Recursos:** `bank`, `bankAccount` (raíces de agregado); `auditTrail` (read model, en `tierOptOut`).
- **Permisos:** `bank.{read,write,delete,close}`, `bankAccount.{read,write,delete,changeStatus}`, `auditTrail.read`.
- **Roles:** tiers generales `VIEWER ⊂ EDITOR ⊂ MANAGER ⊂ ADMIN` (CRUD por verbo, resource-agnostic) + especializado `AUDIT_READER` (retenido de Epic 3, concede `auditTrail.read`).
- **Política:** `bank.read`/`bankAccount.read` = auto-cubiertos por el tier `read` (0 filas); `bank.close`/`bankAccount.changeStatus`/`auditTrail.read` = `explicitGrants` (domain-ops/sensibles). Añadir un recurso **sólo-CRUD** posterior (Invoice, Contact, …) = **0 filas de política** → objetivo-f en su forma más fuerte.

## Siguiente paso BMAD

Cortar la épica «RBAC authorization model» (PR-1…PR-4, +PR-5/PR-6) con `bmad-create-epics-and-stories`. Este addendum + el ADR son su contrato de entrada. `Backoffice/Identity/Infrastructure/Security` necesitará su registro en `deptrac.yaml` como cualquier módulo (mirror del bloque de Identity ya existente); el aislamiento de imports de Symfony Security queda anotado en **#438** (no bloquea).
