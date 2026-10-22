---
title: 'RR-1: aviso a todo administrador sin secreto de recuperación vivo'
type: 'feature'
created: '2026-09-29'
status: 'ready-for-dev'
route: 'dispatch'
review_loop_iteration: 0 # revisión externa del spec incorporada 2026-09-29 (Spec Change Log)
story_key: 'rr-1-recovery-secret-readiness-warning' # tablero: sprint-status-recovery-readiness.yaml
context:
  - '{project-root}/_bmad-output/implementation-artifacts/rr-1-recovery-secret-readiness-warning.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Un administrador sin secreto de recuperación vivo queda expuesto a la composición de #602, y nada fuera de `/backoffice/profile` se lo dice; además, «caducado cuenta como ninguno» solo vive en el cliente (`hasLapsed`).

**Approach:** El servidor expone `live: bool` en `GET /me/recovery-secret` (regla en `RecoverySecret::isLiveAt`), el panel lo consume, y el shell del back-office muestra una banda no descartable a quien tenga `ADMIN` y `live === false`. Criterios completos y copy: la story RR-1 (en `context`).

## Boundaries & Constraints

**Always:** mint sobre fila caducada sigue respondiendo 409 (decisión 2026-09-28); banda no descartable, `<aside aria-label="Account recovery">`, sin live region; copy en inglés; `NoticeBanner` presentacional en `components/erpify` (no importa `context/`).

**Decisiones (2026-09-29):** e2e — antes de la suite, el admin e2e queda con `live === true`: se lee `GET /me/recovery-secret`; si ya está vivo no se toca; si existe caducado se revoca; si no está vivo se acuña; se termina verificando `live === true` (un 409 de mint NO es éxito: una fila caducada también lo produce). El spec RR-1 revoca por API, comprueba la banda en dos rutas, acuña por UI y deja la cuenta con secreto vivo. Spec entero (~1800 tokens), sin partir. `live` es la foto del servidor en el instante de la lectura: sin polling ni timers en RR-1. Lectura pendiente o fallida = desconocido, nunca `false`. El bootstrap e2e es un `globalSetup` de Playwright en TypeScript (no `make/pwa.mk`): no escribe `storageState`. En el burn-in (`--repeat-each`), el spec RR-1 se salta las repeticiones con `testInfo.repeatEachIndex > 0` y dice por qué (revoke+mint gastan 2 de las 10 pruebas de contraseña por 15 min); el límite no se relaja para e2e.

**Never:** ruta nueva; relajar la re-prueba de contraseña de mint/revoke; decidir `ADMIN` por otra vía que `session.roles`; recalcular la caducidad en el cliente desde `expiresAt`; un GET extra tras mint/revoke para sincronizar el shell.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Sin secreto | no hay fila | `exists:false, live:false`; banda variante «none» para ADMIN | N/A |
| Vivo | fila con `expires_at` futuro | `live:true`; sin banda | N/A |
| Caducado | fila con `expires_at` pasado, o `== now` (`now >= expiresAt` ⇒ caducado) | `exists:true, live:false`; banda variante «expired» | N/A |
| No admin | cualquier estado | sin banda | N/A |
| Lectura pendiente o falla | GET en vuelo o error de red | sin banda (no bloquea el shell) | desconocido ≠ `false`; silencioso en el shell; el panel conserva su `MutationError` |
| Caduca con la página abierta | `live:true` leído antes de `expires_at` | la banda no aparece hasta la siguiente lectura | deliberado: sin polling |

</frozen-after-approval>

## Code Map

Rutas relativas al worktree.

- `api/src/Iam/Identity/Domain/Entity/RecoverySecret.php` -- `$expiresAt`, `verify()` delega en `SingleUseToken`; añadir `isLiveAt($now)` como `!SingleUseToken::fromHash(...)->isExpired($now)` (`api/src/Shared/Token/Domain/SingleUseToken.php:81`, `$now >= expiresAt` ⇒ caducado): la regla de vida es la misma que la de canje, no una segunda.
- `api/src/Iam/Identity/Infrastructure/Controller/GetMyRecoverySecretController.php` -- hoy sin reloj; inyectar `Erpify\Shared\Clock\Domain\Clock`.
- `api/src/Iam/Identity/Application/Resource/RecoverySecretResource.php` -- añadir `bool $live` (tras `exists`) y documentarlo.
- `api/tests/Unit/Iam/Identity/Infrastructure/Controller/GetMyRecoverySecretControllerTest.php` -- `FixedClock`, `InMemoryRecoverySecretRepository`, `RecoverySecretMother::mintedFor`.
- `api/features/backoffice/identity/recovery_secret_redeem.feature` -- modelo para el nuevo feature del GET (siembra SQL, caducado `'2020-01-01'`, `I am logged in as an administrator`).
- `pwa/src/context/shared/access/domain/RecoverySecret.ts` -- unión `RecoverySecretStatus`: `live: false` en la rama `exists:false`, `live: boolean` en la otra.
- `pwa/src/context/shared/access/infrastructure/ApiRecoverySecretRepository.ts` -- guarda `isStatusEnvelope`: exigir `typeof live === "boolean"` y `live === false` si `!exists`.
- `pwa/src/context/shared/access/application/useRecoverySecret.ts` -- fabrica estados en `revoke`/`applyMinted` (fijar `live`: revoke ⇒ `false`, mint ⇒ `true`, sin fecha) y los publica al estado compartido (abajo), si hay proveedor.
- `pwa/src/context/shared/access/infrastructure/ui/AuthProvider.tsx` + `application/useSession.ts` -- precedente de estado compartido: el proveedor `.tsx` vive en `infrastructure/ui/` y el hook de `application/` lee su contexto. `application/` aloja hooks de React (`useRecoverySecret`, `useSession`), pero ningún `.tsx`.
- `pwa/src/app/backoffice/profile/_components/RecoverySecretPanel.tsx` -- `hasLapsed` (:266) sale; Active/Expired desde `live`; copy de AC4.
- `pwa/src/app/backoffice/BackOfficeLayoutClient.tsx` -- `useSession()` :118 (roles en `session.roles`), `<main>` :760; `useCanRole` en `context/shared/access/application/useCan.ts:16`.
- `pwa/src/components/erpify/StatusBadge.tsx` -- punto `warning`; `NoticeBanner` nuevo junto a él, exportado en `components/erpify/index.ts`; no importa `context/` (`pwa/.dependency-cruiser.cjs:82`).
- Tests de layout (`backOfficeLayoutClient`, `…RouteFocus`, `…GroupSignOut`, `…SignOutIntent`, `backOfficeMenuPermissions`) mockean `useSession` con `vi.mock`: el hook de preparación se mockea igual.
- `.github/workflows/ci.yml` job `pwa-burn-in` (semanal) -- `make pwa.test.e2e c='--repeat-each=10'`.
- Límite `password_change_per_identity`: 10 / 15 min, ventana deslizante (`api/.env:103`), compartido por mint y revoke. `RevokeRecoverySecret` revoca también una fila caducada.
- Docs: `docs/architecture-api.md:124`, `api/docs/postman/erpify-api.postman_collection.json:199`.

## Tasks & Acceptance

**Execution:**
- [ ] `api/src/Iam/Identity/Domain/Entity/RecoverySecret.php` -- `isLiveAt()` -- una sola regla, en el agregado.
- [ ] `api/tests/Unit/Iam/Identity/Domain/Entity/RecoverySecretTest.php` (nuevo) -- vivo antes, caducado en `expiresAt` exacto y después (falsa un `>` en lugar de `>=`).
- [ ] `RecoverySecretResource.php` + `GetMyRecoverySecretController.php` + su test -- `live` desde `Clock`.
- [ ] `api/features/backoffice/identity/recovery_secret_read.feature` (nuevo) -- none / live / expired.
- [ ] `RecoverySecret.ts`, `ApiRecoverySecretRepository.ts` + test, `useRecoverySecret.ts` -- `live` en el contrato del cliente.
- [ ] `pwa/src/context/shared/access/infrastructure/ui/RecoveryReadinessProvider.tsx` + `application/useRecoveryReadiness.ts` (nuevos, calcados de `AuthProvider`/`useSession`) -- el proveedor hace la lectura del shell y guarda `live | unknown`; `useRecoverySecret` publica ahí el resultado de mint/revoke (no-op sin proveedor).
- [ ] `pwa/src/components/erpify/NoticeBanner.tsx` (nuevo) + test -- tono, texto, enlace; sin rol de región viva.
- [ ] `BackOfficeLayoutClient.tsx` -- monta el proveedor; la banda, primer hijo de `<main>`, solo si `ADMIN` y `live === false` (nunca con `unknown`); el foco de navegación sigue en `<main>`; los cinco tests de layout reciben el mock.
- [ ] `RecoverySecretPanel.tsx` + test -- `live`, copy de AC4, `hasLapsed` fuera.
- [ ] `pwa/tests/e2e/global-setup.ts` (nuevo) + `globalSetup` en `pwa/playwright.config.ts` -- deja al admin en `live === true` según la decisión (GET → revoca si caducado → acuña → GET verifica); falla la suite si no lo consigue.
- [ ] `pwa/tests/e2e/backoffice/recovery-readiness-real-api.spec.ts` (nuevo) -- revoca por API, banda en dos rutas con el foco en `<main>`, acuña por UI, banda fuera sin navegar; termina vivo; `test.skip` si `repeatEachIndex > 0`.
- [ ] Docs + Postman -- `live` en el contrato.

**Acceptance Criteria:**
- Given un admin sin secreto en `/backoffice/profile`, when acuña, then la banda desaparece sin navegar ni recargar; y when revoca, then reaparece (variante «none»).
- Given un admin sin secreto, when navega entre dos rutas del back-office, then la banda está en ambas y el foco sigue yendo a `<main>`.
- Given un GET del shell en vuelo o fallido, then no hay banda; y given mint/revoke, then no sale un segundo `GET /me/recovery-secret`.
- Given cualquier código del PWA, then ninguno compara `expiresAt` con el reloj para decidir si el secreto vive (`hasLapsed` borrado).
- Given la banda montada, then no hay `role="status"`/`alert`, `<output>` ni `aria-live` en ella, y `live-region-surfaces`, `ui-copy-language`, `backoffice-route-titles` siguen verdes sin tocarlos.
- Given `api/.route-manifest.json` y `api/.credential-proof-policy`, then no cambian.

## Design Notes

El layout del App Router no se remonta al navegar, así que una lectura hecha una vez en el shell quedaría rancia tras acuñar en el perfil. Por eso la lectura del shell vive en un proveedor del contexto `access` y el hook del panel lo actualiza con lo que el 201/204 ya dice: ni segundo GET ni un GET por navegación. Estado del shell: `unknown | live | not-live`; solo `not-live` pinta la banda.

La banda va como primer hijo de `<main>`, no como hermano anterior: visualmente es lo mismo (encima del contenido), pero la navegación mueve el foco a `<main>`, así que fuera de él un lector de pantalla empieza a leer después del aviso y no lo oye nunca; dentro, es lo primero que lee. Sigue siendo un landmark (`<aside>` con nombre), no una región viva.

## Verification

**Commands:**
- `make php.stan` y `make php.quality` -- exit 0.
- `make php.unit c='--filter RecoverySecret'` y `make php.behat c='features/backoffice/identity/recovery_secret_read.feature'` -- verde.
- `make pwa.quality` y `make pwa.test.unit` -- exit 0.
- `make pwa.test.e2e c='recovery-readiness'` -- verde contra el stack del worktree.

## Implementation Notes

## Spec Change Log

- 2026-09-29 — revisión externa del spec (pegada por el usuario). Aceptado: el 409 de mint no prueba un secreto vivo (el bootstrap lee, revoca si caducó, acuña y verifica); límite `== now` pinado; `unknown` ≠ `false`; `live` como foto sin polling; ningún GET extra tras mutar; ningún cálculo de caducidad en el cliente; la banda dentro de `<main>`. Rechazado por medición: «`application/` es framework-free» (aloja `useRecoverySecret`, `useSession`, `useAuditTimeline`…; lo que no aloja es `.tsx`, y por eso el proveedor va a `infrastructure/ui/` como `AuthProvider`); «sin mocks en los tests de layout» (ya mockean `useSession` con `vi.mock`). KEEP: el bloque congelado inicial y el Code Map verificado.

## Review Triage Log
