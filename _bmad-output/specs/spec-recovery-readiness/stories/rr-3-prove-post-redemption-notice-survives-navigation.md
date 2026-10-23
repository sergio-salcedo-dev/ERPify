---
title: 'RR-3 — probar en navegador que el aviso post-canje sobrevive a la navegación al dashboard'
type: 'chore'
created: '2026-10-23'
status: 'done'
baseline_revision: '5d39996f8389938878fab1387e117f04d5e3d706'
review_loop_iteration: 0
followup_review_recommended: false
context:
  - '{project-root}/pwa/CLAUDE.md'
warnings: [oversized]
deferred: []
---

<intent-contract>

## Intent

**Problem:** El aviso de una sola vez tras canjear un secreto de recuperación (`dashboard__recovery-next-step`) viaja en memoria de módulo (`recoveryRedeemedNotice.ts`) a través de la navegación de cliente `/recovery` → `/backoffice`. Los tests unitarios de RR-2 marcan y leen el flag en el mismo grafo de módulos, así que una regresión a navegación dura o a una segunda instancia del chunk seguiría en verde.

**Approach:** Un spec de Playwright contra la API real con una identidad dedicada sembrada por el CLI de producto: acuña un secreto por API (la respuesta 201 es el único sitio donde su texto plano es legible), lo canjea en `/recovery`, ve el toast y el aviso en el mismo documento, y deja de verlo tras recargar; y un inicio de sesión normal de la misma identidad no muestra aviso.

## Boundaries & Constraints

**Always:**
- Identidad dedicada (`e2e-recovery@erpify.test`), nunca el admin compartido `e2e@erpify.test`: un canje expulsa todas las otras sesiones de la cuenta y rompería la sesión por worker de `authenticatedTest`.
- Sembrada en `make pwa.test.e2e` con `organization:administrator:create` (camino real: hash, identidad ADMIN y membresía en una transacción), idempotente como la siembra del admin compartido; valores en sincronía con `pwa/tests/e2e/constants.ts`.
- El secreto se acuña por API dentro del spec con la contraseña de la identidad dedicada; un secreto previo (de una ejecución que falló antes de canjear) se revoca antes. El texto plano nunca se registra ni se adjunta al informe.
- El spec usa un contexto sin `storageState` (sin la sesión del admin compartido).
- Prueba que la llegada es navegación de cliente: un marcador en `globalThis` puesto antes del submit sigue presente al ver el aviso.
- Presupuesto de prueba de contraseña (10 / 15 min por identidad, compartido por acuñar y revocar): ≤2 por intento; el test se salta en `repeatEachIndex > 0` (burn-in `--repeat-each=10`).

**Never:**
- Cambiar código de producción del PWA o de la API, endpoints o límites de tasa.
- Relajar el limitador para E2E, añadir rutas `/api/test/*` o sembrar el hash por SQL.
- Usar el admin compartido para canjear.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Canje confirmado | secreto vivo recién acuñado, pegado en `/recovery` | toast `Signed in with your recovery secret. We've signed out your other sessions.`; URL `/backoffice`; `dashboard__recovery-next-step` visible con «Next: set a new password»; marcador del documento intacto | — |
| Recarga | tras el caso anterior, `page.reload()` | heading `Dashboard` visible y aviso ausente (`toHaveCount(0)`) | — |
| Inicio de sesión normal | contexto nuevo, `/login` con email+contraseña de la identidad dedicada | llega a `/backoffice`, heading visible, aviso ausente | — |
| Secreto previo sin canjear | `GET /me/recovery-secret` → `exists: true` | se revoca y se acuña uno nuevo | error explícito si revocar ≠ 204 o acuñar ≠ 201 |
| Identidad sin sembrar | login API ≠ 204 | el test falla con un mensaje que nombra la identidad y la siembra de `make pwa.test.e2e` | — |

</intent-contract>

## Code Map

- `make/pwa.mk` L107-145 -- receta `pwa.test.e2e`: siembra el admin compartido con `organization:administrator:create` (L134) y el miembro suspendible por SQL (L138). Añadir variables `E2E_RECOVERY_EMAIL`/`E2E_RECOVERY_PASSWORD` y una línea de siembra idempotente gemela a la del admin, con su comentario (por qué una identidad aparte).
- `api/src/Iam/Identity/Infrastructure/Cli/CreateInitialAdministratorCommand.php` -- solo lectura: crea identidad ADMIN + membresía; no impide un segundo administrador; contraseña 8–128 code points (`PasswordPolicy`). Email duplicado → FAILURE (tragado por la receta).
- `pwa/tests/e2e/constants.ts` -- añadir `E2E_RECOVERY_USER_EMAIL` / `E2E_RECOVERY_USER_PASSWORD` con docblock (por qué dedicada, sincronía con `make/pwa.mk`).
- `pwa/tests/e2e/fixtures/recovery-secret-api.ts` -- hoy `PROOF` fija `E2E_USER_PASSWORD`. Parametrizar la prueba de contraseña (por defecto la del admin compartido, sin cambiar a los llamantes existentes: `global-setup.ts`, `recovery-readiness-real-api.spec.ts`) y añadir `mintFreshRecoverySecret(api, currentPassword): Promise<string>`: lee, revoca si `exists`, acuña (201) y devuelve `data.secret` (`MintedRecoverySecretResource`: `secret`, `mintedAt`, `expiresAt`). Actualizar el docblock de `ensureLiveRecoverySecret` («nothing in the suite redeems it» deja de ser cierto en general).
- `pwa/tests/e2e/global-setup.ts` -- solo lectura: patrón de login por API (`POST /api/v1/backoffice/login`, cabecera `Origin: apiBaseURL()`, 204) y cierre con `POST /api/v1/sessions/revoke-current`.
- `pwa/tests/e2e/backoffice/recovery-readiness-real-api.spec.ts` -- solo lectura: patrón de marcador de documento, `COLD_ROUTE_TIMEOUT_MS`, skip por repetición.
- `pwa/tests/e2e/backoffice/logout.spec.ts` -- solo lectura: `test.use({ storageState: { cookies: [], origins: [] } })` y login por UI (`login-form__email`/`__password`/`__submit`).
- `pwa/src/app/(auth)/_components/RecoveryRedeemForm.tsx` -- solo lectura: `recovery-redeem-form` con `data-hydrated="true"` tras hidratar (el botón está deshabilitado antes), campo `recovery-redeem-form__secret`, botón `recovery-redeem-form__submit`; toast exacto; `markRecoveryRedeemed()` y `router.push(Routes.BACKOFFICE)`.
- `pwa/src/app/backoffice/_components/RecoveryRedeemedNextStep.tsx` + `pwa/src/app/backoffice/page.tsx` -- solo lectura: aviso `<aside aria-label="Next steps after recovery" data-testid="dashboard__recovery-next-step">` bajo el `<h1>Dashboard`.
- `pwa/src/context/shared/routing/domain/Routes.ts` L28 -- `RECOVERY: "/recovery"`.
- `api/src/Iam/Identity/Application/RedeemRecoverySecret.php` -- solo lectura: el canje consume (elimina) la fila, así que una ejecución sana solo gasta 1 prueba (acuñar).

## Tasks & Acceptance

**Execution:**
- `make/pwa.mk` -- variables y siembra idempotente de la identidad dedicada antes de lanzar Playwright -- la API no tiene otro camino a una identidad con contraseña.
- `pwa/tests/e2e/constants.ts` -- constantes de la identidad dedicada -- una sola fuente para el spec.
- `pwa/tests/e2e/fixtures/recovery-secret-api.ts` -- prueba de contraseña parametrizada + `mintFreshRecoverySecret` -- el spec necesita el texto plano y otra contraseña.
- `pwa/tests/e2e/backoffice/recovery-redeemed-notice-real-api.spec.ts` -- nuevo spec con dos tests (canje → aviso → recarga; login normal sin aviso) siguiendo la matriz -- CAP-3.

**Acceptance Criteria:**
- Given la identidad dedicada sembrada y un secreto recién acuñado, when se canjea en `/recovery`, then el toast aparece, la URL es `/backoffice`, `dashboard__recovery-next-step` es visible en el mismo documento y, tras `page.reload()`, ya no está.
- Given la identidad dedicada, when inicia sesión por `/login`, then el dashboard carga sin `dashboard__recovery-next-step`.
- Given el spec y el resto de la suite, when corre `make pwa.test.e2e`, then todo verde y el admin compartido conserva su sesión por worker y su secreto vivo.
- Given el árbol, when corre `make pwa.quality`, then verde.

## Spec Change Log

## Review Triage Log

### 2026-10-23 — Review pass
- verdicts: 23 findings — high 0, medium 0, low 15, false 8, maybe-false 0
- findings:
  - `[low]` `[patch]` (blind) El docblock de `proof()` afirma un valor por defecto que la función no tiene — reescrito: solo describe el cuerpo con el que acuñar y revocar re-prueban la contraseña.
  - `[low]` `[patch]` (blind) «Una vez» solo se prueba con `page.reload()`, que vacía la memoria de módulo y pasaría aunque `clearRecoveryRedeemed()` no se llamase — añadido: salir por `dashboard__recovery-next-step-link` a `/backoffice/profile`, `page.goBack()`, heading visible, aviso con count 0 y marcador intacto; la recarga se mantiene. Falsado: con la llamada a `clearRecoveryRedeemed()` comentada el test sale rojo en `toHaveCount`.
  - `[false]` `[reject]` (blind) Las aserciones de ausencia son instantáneas y un aviso tardío escaparía — `RecoveryRedeemedNextStep` lee la marca en el inicializador de `useState`, síncrono, y se monta en el mismo commit que el `<h1>` de `page.tsx`; no hay render diferido que esperar.
  - `[low]` `[reject]` (blind) La siembra no repara una identidad ya existente bloqueada, suspendida o con otra contraseña — ningún spec falla logins, suspende ni cambia la contraseña de esta identidad; reparar exige SQL o lógica extra en la receta para un estado que la suite no produce.
  - `[low]` `[reject]` (blind) Un 429 del presupuesto de pruebas no dice por qué — el error ya imprime el estado (429) y el docblock del fixture y del spec explican el presupuesto; una rama nueva no compensa.
  - `[low]` `[reject]` (blind) `data.secret` se castea sin comprobar — el contrato de `MintedRecoverySecretResource` es fijo; el fallo sería visible en el spec y la guarda añade rama para un caso improbable.
  - `[low]` `[patch]` (blind) `global-setup.ts` dice que el usuario E2E es «the installation's administrator», que ahora suena a único — cambiado a «an installation administrator».
  - `[low]` `[reject]` (blind) Las credenciales de `make/pwa.mk` y `constants.ts` solo se sincronizan por comentario — mismo patrón que el par `E2E_USER_*` existente; exportarlas a Playwright añade fontanería a ambos lados para un override que nadie usa.
  - `[false]` `[reject]` (blind) La razón del skip por repetición cita una expulsión imposible — las repeticiones de `--repeat-each` son instancias distintas que Playwright puede repartir entre workers, así que el canje de una puede expulsar la sesión del login de otra en paralelo; el skip del test de login también es necesario.
  - `[false]` `[reject]` (blind) El artefacto no registra revisión — se registra en este paso, que es donde el flujo la escribe.
  - `[low]` `[reject]` (edge) Siembra sin reparación — mismo grupo y razón que la fila de blind.
  - `[low]` `[reject]` (edge) Override de variables de make diverge del spec — mismo grupo y razón que la fila de blind.
  - `[low]` `[reject]` (edge) Secreto sin validar — mismo grupo y razón que la fila de blind.
  - `[low]` `[reject]` (edge) 429 sin pista — mismo grupo y razón que la fila de blind.
  - `[low]` `[patch]` (edge) `ensureLiveRecoverySecret` aceptaba otra contraseña pero su error nombra al admin E2E — eliminado el parámetro, que ningún llamante pasaba; la función vuelve a ser solo del admin compartido.
  - `[low]` `[patch]` (edge) Docblock de `proof()` — mismo grupo y arreglo que la fila de blind.
  - `[low]` `[patch]` (vgap) Docblock de `proof()` — mismo grupo y arreglo.
  - `[low]` `[patch]` (vgap) Mensaje de `ensureLiveRecoverySecret` con otra identidad — mismo grupo y arreglo que la fila de edge.
  - `[low]` `[patch]` (intent) El consumo «una vez» dentro del documento no se probaba — mismo grupo y arreglo que la segunda fila de blind.
  - `[false]` `[reject]` (intent) El login normal solo se prueba en contexto nuevo — la intención pide que un inicio de sesión normal no muestre aviso; el contexto nuevo es justo ese caso.
  - `[false]` `[reject]` (intent) La siembra vive en `make pwa.test.e2e`, no en Playwright — es la decisión de la historia; CI corre por ese target (`ci.yml` L420 y L566) y un stack sin sembrar falla con un mensaje que lo nombra (medido).
  - `[false]` `[reject]` (intent) El burn-in solo corre la repetición 0 — decisión registrada por el presupuesto de 10 pruebas / 15 min, ya en Boundaries.
  - `[false]` `[reject]` (intent) Nada asevera que el admin compartido conserve sesión y secreto — se cumple por construcción (identidad aparte, contraseña por defecto intacta) y la suite de RR-1 sobre ese admin pasó junto a este spec.

## Design Notes

- **Por qué el CLI y no SQL o invitación.** El CLI es el camino de producto que hashea con el hasher configurado y crea la membresía; SQL exigiría un hash precalculado acoplado a la configuración del hasher, y la invitación necesita leer el token de un mail. Coste: la organización E2E tiene un segundo ADMIN; ningún spec depende de que haya uno solo (las guardas `self-*` son por identidad).
- **Por qué acuñar dentro del spec.** El texto plano solo existe en la respuesta 201; acuñarlo en `global-setup` obligaría a pasarlo por fichero. Dentro del spec vive en una variable.
- **Presupuesto.** Una ejecución sana gasta 1 prueba (acuñar; el canje elimina la fila), una tras fallo previo 2. Con 2 reintentos de CI el peor caso es 6 ≤ 10; diez repeticiones no caben, de ahí el skip por `repeatEachIndex`.

## Verification

**Commands:**
- `make pwa.test.e2e c='backoffice/recovery-redeemed-notice-real-api.spec.ts'` -- verde (con el stack del worktree arriba)
- `make pwa.test.e2e c='backoffice/recovery-'` -- verde junto al spec de RR-1 (comparten el fixture)
- `make pwa.quality` -- exit 0

## Auto Run Result

Status: done

**Resumen.** CAP-3: un spec de Playwright contra la API real prueba que el aviso de una sola vez tras canjear un secreto de recuperación sobrevive a la navegación de cliente `/recovery` → `/backoffice`, se consume al volver al dashboard en el mismo documento y no reaparece tras recargar; un inicio de sesión con contraseña no lo muestra. Corre con una identidad dedicada (`e2e-recovery@erpify.test`) sembrada por `organization:administrator:create`, nunca con el admin compartido. Sin cambios de código de producción.

**Ficheros.**
- `make/pwa.mk` — variables `E2E_RECOVERY_*` y siembra idempotente de la identidad dedicada en `pwa.test.e2e`.
- `pwa/tests/e2e/constants.ts` — `E2E_RECOVERY_USER_EMAIL` / `E2E_RECOVERY_USER_PASSWORD`.
- `pwa/tests/e2e/fixtures/recovery-secret-api.ts` — prueba de contraseña parametrizada (`proof()`, `revokeRecoverySecret` con contraseña opcional) y `mintFreshRecoverySecret`, que devuelve el texto plano del 201.
- `pwa/tests/e2e/global-setup.ts` — docblock: el usuario E2E es *un* administrador de la instalación.
- `pwa/tests/e2e/backoffice/recovery-redeemed-notice-real-api.spec.ts` — nuevo: canje → toast → aviso en el mismo documento → ida y vuelta por cliente sin aviso → recarga sin aviso; y login normal sin aviso. El secreto nunca llega a un título de paso, a un trace ni al contexto de error (valor puesto con el setter nativo, `trace: "off"`, campo vaciado tras el submit).

**Revisión.** 23 hallazgos en 4 capas (blind 10, edge 6, verification-gap 0 huecos + 2 otros, intent 5).
- Patches (todos low), 4 grupos: docblock de `proof()`; parámetro sin uso en `ensureLiveRecoverySecret`; comentario de `global-setup.ts`; consumo «una vez» dentro del documento.
- Defer: ninguno.
- Rechazados, con razón en el Review Triage Log: reparación de la identidad sembrada, pista del 429, validación de `data.secret` y sincronía make↔constants (low improbables cuyo arreglo añade ramas o fontanería); ausencias instantáneas, razón del skip, artefacto sin revisión y cuatro observaciones descriptivas de intent (`false`).
- Recuento patcheado por verdict: high 0, medium 0, low 4 grupos.

**Recomendación de revisión de seguimiento: false.** Primer pase sin `high` ni `medium` parcheados.

**Verificación (ejecuciones nuevas).**
- `make pwa.quality`: exit 0 (tras los patches).
- `make pwa.test.e2e c='backoffice/recovery-'` (con `PLAYWRIGHT_BASE_URL`/`PLAYWRIGHT_API_BASE_URL=https://localhost:34021`, necesarios desde un worktree): 3 passed (RR-1 + los 2 de RR-3), tras los patches.
- Falsación: navegación dura en lugar de `router.push` → rojo (implementador); `clearRecoveryRedeemed()` comentado → rojo en `toHaveCount` (este paso); ambos restaurados por copia de bytes.
- Matriz: filas 1–3 cubiertas por los dos tests; fila 4 (secreto previo sin canjear) medida acuñando por API sin canjear y relanzando el spec → 2 passed; fila 5 (identidad sin sembrar) medida con un email no sembrado → el error nombra la identidad y la siembra de `make pwa.test.e2e`.

**Riesgos residuales.**
- La suite completa no se ha demostrado verde en este stack: el implementador vio 5 fallos de 183 en specs que este cambio no toca (`banks.spec.ts` mockeado, `banks-real-api` con 25 bancos sobrantes de la BD local, `error-pages`, `frontoffice/theme`), distintos en cada repetición y no comparados con la base. CI lo zanja.
- La organización E2E tiene un segundo ADMIN; ningún spec visto depende de que haya uno solo (`users-real-api.spec.ts` filtra por el email del admin conocido).
- El burn-in (`--repeat-each=10`) solo ejecuta la repetición 0 de este spec, por el presupuesto de 10 pruebas de contraseña / 15 min por identidad.
