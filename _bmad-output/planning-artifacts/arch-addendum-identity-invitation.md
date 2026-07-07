# Arch addendum — ciclo de vida de identidad / invitación (scoped)

> **Estado:** `frozen-ready` · diseño · **extiende el subsistema auth/RBAC** ([`arch-addendum-auth-rbac.md`](arch-addendum-auth-rbac.md)) y el modelo RBAC ([`arch-addendum-rbac-authorization-model.md`](arch-addendum-rbac-authorization-model.md)) · **Alcance:** promoción a contextos top-level `Iam/{Identity,Invitation,Session}` + `Organization/{Organization,Membership}`; las cuatro máquinas de estado (identidad · invitación · autenticación/lockout · sesión) tras los dos invariantes transversales (indistinguibilidad pre-identidad · opacidad del token).
> **Decisiones (el *qué* y el *por qué*):** [`../../docs/adr/identity-invitation-lifecycle.md`](../../docs/adr/identity-invitation-lifecycle.md).
> **Jerarquía:** `epics.md` **>** este addendum. No contradice SI-1…SI-9 de los hermanos; **extiende SI-2** (framework confinado → `iamSessionId` es detalle de Infra) y es **ortogonal** al plano de autorización RBAC (identidad ≠ autorización ≠ tenancy).
> **methodology:** contract-first scoped sobre sistema maduro — invariantes globales mínimos + localización de decisiones por PR + DAG; se omite el march clásico de 8 pasos de `bmad-create-architecture` (modelo Q0–Q8 ya congelado en el run UX `ux-ERPify-2026-07-06` + su review de seguridad). Precedentes: [`arch-addendum-auth-rbac.md`](arch-addendum-auth-rbac.md), [`arch-addendum-rbac-authorization-model.md`](arch-addendum-rbac-authorization-model.md).

Método contract-first scoped: no repite el ADR ni describe estado actual; fija los **invariantes globales mínimos que el modelo añade al subsistema**, **localiza cada decisión en su PR** y da el **DAG de dependencias** para que la épica sea *dev-able*.

## System Invariants (globales — se cumplen en todo el subsistema de identidad)

Continúan la numeración de los hermanos (SI-1…SI-9); estos seis son los que introduce el modelo de identidad/invitación.

- **SI-10 · Tres momentos.** El `SessionId` se acuña **solo** tras superar la admisión: `credenciales válidas → identidad demostrada → admisión → Session(UUID) → sesión Symfony`. **Nunca** `credenciales → sesión` (ADR D4/D8).
- **SI-11 · Gate de sesión fail-closed.** Toda request autenticada atraviesa el **Session Admission Gate**; si no puede decidir (store caído), la petición **no continúa** — es parte del TCB de auth. La revocación **lógica** (marcar `Revoked`) basta **porque** el gate es la frontera de confianza; el borrado físico queda al GC del handler (ADR D8/D12).
- **SI-12 · Indistinguibilidad pre-identidad = timing + status + shape.** Antes de demostrar identidad, `{inexistente, password errónea, INVITED}` son indistinguibles en **tiempo, código de estado y forma** — no solo en copy (hash dummy siempre; forgot-password uniforme; throttle plegado al fallo) (ADR D10).
- **SI-13 · Opacidad + higiene del token.** Token opaco, single-use, TTL, hasheado en reposo; `Referrer-Policy: no-referrer` en pantallas por token + strip de la URL + redacción en logs; **una muerte de token = un único mensaje** (jamás el motivo) (ADR D11).
- **SI-14 · Especificidad graduada por confianza.** La especificidad de un error depende del **nivel de confianza alcanzado**, no del tipo de error: pre-identidad indistinguible; post-identidad, los estados de admisión son **observables por diseño** (no "enumerables" — el modelo protege contra enumeración anónima, no contra quien ya controla credenciales válidas). La frontera es la **demostración de identidad** (ADR D12).
- **SI-15 · Seam multi-tenant-ready.** Todo agregado carga `organizationId` (id, nunca relación tipada); `Membership(userId, organizationId, roles)` es el enlace autoritativo user↔org (un user nunca es "global"). Tenancy **operativa** (self-signup, tenant-switching, enforcement cross-tenant) queda diferida a su ADR — el `subject:` del voter RBAC sigue sin evaluar (ADR D2).

## Localización de decisiones por PR

*El corte fino de épica/historias es el paso BMAD siguiente (`bmad-create-epics-and-stories`); esta tabla es la localización propuesta para que sea dev-able.*

| PR / Story | Decisiones ADR | Costura / artefactos que toca |
|------------|----------------|-------------------------------|
| **PR-0 — promoción de contexto** | D1 | crear `Iam/` + `Organization/`; mover `Backoffice/Identity`→`Iam/Identity` (`User`, `SecurityUser`, provider, authenticator, `SecurityActorContextFactory`); actualizar `security.yaml`; re-registrar en `deptrac.yaml`. **Sin cambio de comportamiento** (move/rename). **Coordina con RBAC** (mueve su ubicación de core `…/Infrastructure/Security`) |
| **PR-1 — Organization + Membership + bootstrap** | D2 | agregados `Organization/Organization`, `Organization/Membership` (`userId`+`organizationId`+roles); CLI `ProvisionOrganization` + `CreateInitialAdministrator` (una org/instalación); nunca credenciales en migración |
| **PR-2 — Shared/Token** | D6 | `Shared/Token/Domain/SingleUseToken` (VO) + `Infrastructure/` (CSPRNG + hasher + verify constant-time). Prereq de PR-4 y PR-5 |
| **PR-3 — User.status + admisión** | D3, D4, D12 | enum `IdentityStatus` en `User` (`INVITED/ACTIVE/SUSPENDED/DEACTIVATED`) + `HashedPassword` nullable hasta `ACTIVE`; `UserChecker` (tres momentos: `checkPreAuth` INVITED uniforme, `checkPostAuth` muros post-identidad **sin sesión**); tipos de error `account-locked`/`account-suspended`/`DEACTIVATED`-genérico + operacional del gate → `docs/api-error-contract.md` |
| **PR-4 — Invitation + accept** | D5, D11 | agregado `Iam/Invitation` (`CREATED→SENT→ACCEPTED\|REVOKED\|EXPIRED`); POST accept **fuera del firewall** (CSRF + `Origin` check + **regeneración de sesión**) que voltea `INVITED→ACTIVE` y acuña `Session`; invite/resend/revoke; marker `invalid-token`; higiene token-en-URL (`no-referrer` + strip) |
| **PR-5 — reset de contraseña** | D9, D10 | forgot/reset **uniformes** (status+shape invariables); reset **revoca TODAS** las sesiones + **limpia `LockedUntil`** (D-b) + no-`ACTIVE`→muro (D-c). Depende de PR-2 (token), PR-3 (status), PR-7 (revoca-todas) |
| **PR-6 — lockout** | D7 | `failedAttempts`/`lockedUntil` persistido en la identidad, enforced en `checkPostAuth`, limpiado por reset/login/TTL; neutralidad del `login_throttling` (throttle plegado al fallo pre-identidad). Depende de PR-3 |
| **PR-7 — Session registry + gate** | D8 | agregado `Iam/Session` (source of truth del ciclo de vida) + **gate fail-closed** por-request (correlación `iamSessionId` en el bag Symfony, infra-only); revocación una/todas-menos-actual/todas; dos caminos de enforcement (`refreshUser` nativo + registro); "mis sesiones" |
| **PR-8 — hardening anti-enum + email** | D10, D11 | barrido constant-time (hash dummy en todos los caminos, incl. `INVITED`/forgot); `Referrer-Policy`/strip/log-redaction; `SecurityEmail` async (Messenger, HTTPS-only, escape de contenido dinámico, remitente no-`no-reply` + aviso "no compartas este enlace") |

## DAG de dependencias

```
PR-0 (promoción Iam/ + Organization/ · deptrac · coord. RBAC)
  ├─> PR-1 (Organization + Membership + bootstrap CLI)
  ├─> PR-2 (Shared/Token)
  ├─> PR-3 (User.status + UserChecker + tipos de error)
  │      ├─> PR-6 (lockout LockedUntil)
  │      ├─> PR-4 (Invitation + accept) ──┐   [+ PR-2]
  │      └─> PR-5 (reset) ────────────────┤   [+ PR-2, + PR-7]
  └─> PR-7 (Session registry + gate) ─────┘
         └─> PR-8 (hardening anti-enum + email)   [+ PR-4/PR-5 superficies]
```

**Orden safe-first (aditivo/estructural primero, comportamiento y superficie pública al final):** PR-0 (move sin comportamiento) → PR-1/PR-2/PR-3/PR-7 (fundación aditiva: agregados, token, status+admisión, registro de sesión) → PR-6 (lockout) → PR-4 (invitación, 1ª superficie pública nueva) → PR-5 (reset) → PR-8 (hardening transversal).

## Riesgo de secuenciación con RBAC

El addendum RBAC empaqueta su core (`PermissionVoter` + `AuthorizationPolicy`) en `Backoffice/Identity/Infrastructure/Security` y su épica **ya está cortada**. **PR-0 mueve ese destino** a `Iam/Identity/Infrastructure/Security`. Decisión de orden (a fijar al cortar épicas, no aquí): (a) PR-0 aterriza primero y RBAC apunta a la ubicación nueva, o (b) RBAC ship a `Backoffice/Identity` y PR-0 mueve ambos. `Iam/*` y `Organization/*` necesitan su registro en `deptrac.yaml` como cualquier módulo nuevo (mirror del bloque de Identity ya existente).

## Siguiente paso BMAD

Cortar la épica «Identity & Invitation lifecycle» (PR-0…PR-8) con `bmad-create-epics-and-stories`. Este addendum + el ADR son su contrato de entrada. La UI admin de gestión de miembros (invitar/reenviar/revocar/activar/desactivar) vive en su propio slice backoffice (lenguaje visual distinto), diseñada como **contrato** en el run UX — su corte es posterior a la superficie pública de acceso.
