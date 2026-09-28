---
title: 'RR-1: aviso a todo administrador sin secreto de recuperación vivo'
type: 'feature'
created: '2026-09-28'
status: 'draft'
route: 'dispatch'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/rr-1-recovery-secret-readiness-warning.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Un administrador sin secreto de recuperación vivo queda expuesto a la composición de #602, y nada fuera de `/backoffice/profile` se lo dice; además, «caducado cuenta como ninguno» solo vive en el cliente (`hasLapsed`).

**Approach:** El servidor expone `live: bool` en `GET /me/recovery-secret` (regla en `RecoverySecret::isLiveAt`), el panel lo consume, y el shell del back-office muestra una banda no descartable a quien tenga `ADMIN` y `live === false`. Criterios completos y copy: la story RR-1 (en `context`).

## Boundaries & Constraints

**Always:** mint sobre fila caducada sigue respondiendo 409 (decisión 2026-09-28); banda no descartable, `<aside aria-label="Account recovery">`, sin live region; copy en inglés; `NoticeBanner` presentacional en `components/erpify` (no importa `context/`).

**Never:** ruta nueva; relajar la re-prueba de contraseña de mint/revoke; decidir `ADMIN` por otra vía que `session.roles`.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Sin secreto | no hay fila | `exists:false, live:false`; banda variante «none» para ADMIN | N/A |
| Vivo | fila con `expires_at` futuro | `live:true`; sin banda | N/A |
| Caducado | fila con `expires_at` pasado | `exists:true, live:false`; banda variante «expired» | N/A |
| No admin | cualquier estado | sin banda | N/A |
| Lectura falla | GET error de red | sin banda (no bloquea el shell) | silencioso en el shell; el panel conserva su `MutationError` |

</frozen-after-approval>

## Open Questions

- **La banda en el e2e.** El admin e2e (`e2e@erpify.test`, sembrado en `make/pwa.mk:115-134`) no tiene secreto, así que la banda saldrá en todas las páginas de todos los specs. Opciones: dejarla (no bloquea; riesgo de romper aserciones de layout/foco) / sembrar un secreto al admin e2e en `pwa.mk` (el e2e deja de ver la banda salvo en un spec dedicado que la provoque revocando).

## Code Map

Rutas relativas al worktree.

- `api/src/Iam/Identity/Domain/Entity/RecoverySecret.php` -- `$expiresAt`, `verify($secret, $now)`; añadir `isLiveAt($now)` junto a él.
- `api/src/Iam/Identity/Infrastructure/Controller/GetMyRecoverySecretController.php` -- construye `RecoverySecretResource`; hoy sin reloj: inyectar `Erpify\Shared\Clock\Domain\Clock`.
- `api/src/Iam/Identity/Application/Resource/RecoverySecretResource.php` -- `(bool $exists, ?string $mintedAt, ?string $expiresAt)` → añadir `bool $live`.
- `api/tests/Unit/Iam/Identity/Infrastructure/Controller/GetMyRecoverySecretControllerTest.php:125` -- construye el controlador; usa `FixedClock`, `InMemoryRecoverySecretRepository`, `RecoverySecretMother::mintedFor`.
- No existe `RecoverySecretTest.php`: crearlo para `isLiveAt` (límite exacto en `expiresAt`).
- Behat: no hay feature del GET; crear `api/features/backoffice/identity/recovery_secret_read.feature` copiando `recovery_secret_redeem.feature` (siembra SQL `:30`, caducado `'2020-01-01'` `:130`; login `I am logged in as an administrator`; subselect por `admin@erpify.test`).
- `pwa/src/context/shared/access/domain/RecoverySecret.ts:7` -- unión `RecoverySecretStatus`; añadir `live`.
- `pwa/src/context/shared/access/infrastructure/ApiRecoverySecretRepository.ts` -- guarda `isStatusEnvelope`; exigir `typeof live === "boolean"`. Test: `pwa/tests/context/shared/access/ApiRecoverySecretRepository.test.ts:130`.
- `pwa/src/context/shared/access/application/useRecoverySecret.ts` -- fabrica estados en `revoke` y `applyMinted`: fijar `live`.
- `pwa/src/app/backoffice/profile/_components/RecoverySecretPanel.tsx` -- `hasLapsed` (:266, único uso :238) sale; `renderCurrent` (:82-95) pasa `live`. Test: `pwa/tests/app/backoffice/profile/recoverySecretPanel.test.tsx` (mock de Container).
- `pwa/src/app/backoffice/BackOfficeLayoutClient.tsx` -- `useSession()` :118, `<main>` :760; roles en `session.roles`, `Role.ADMIN`, `useCanRole`. Lectura de solo-lectura (no reutilizar el estado de mint/revoke del hook).
- Tests del layout (`backOfficeLayoutClient`, `…RouteFocus`, `…GroupSignOut`, `…SignOutIntent`, `backOfficeMenuPermissions`) no mockean Container: todos necesitarán el mock.
- `pwa/src/components/erpify/StatusBadge.tsx` -- variante `warning` para el punto; exportar `NoticeBanner` en `components/erpify/index.ts`; regla `erpify-not-bounded-context` (`pwa/.dependency-cruiser.cjs:82`).
- Docs: `docs/architecture-api.md:124`, `api/docs/postman/erpify-api.postman_collection.json:199`.

## Implementation Notes

## Spec Change Log

## Review Triage Log
