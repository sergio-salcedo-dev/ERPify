---
title: 'RR-2 — expulsar primero, rotar después en cada superficie que dice al usuario qué hacer'
type: 'feature'
created: '2026-10-22'
status: 'done'
baseline_revision: '8e44737412bd8247ae0594396793bcabae8e5e12'
review_loop_iteration: 0
followup_review_recommended: false
context:
  - '{project-root}/_bmad-output/specs/spec-recovery-readiness/rr-2-evict-first-guidance.md'
  - '{project-root}/pwa/CLAUDE.md'
warnings: [oversized]
deferred:
  - summary: >-
      Ningún test de navegador cubre el traspaso canje → dashboard que lleva el aviso de una sola vez.
    evidence: |-
      El aviso depende de que el módulo `recoveryRedeemedNotice.ts` sobreviva a `router.push` (navegación
      de cliente de Next). Los tests unitarios marcan y leen el flag en el mismo grafo de módulos, así que
      una regresión a navegación dura o a una segunda instancia del chunk seguiría en verde. Lo zanja un
      spec de Playwright que canjee un secreto en `/recovery` y vea `dashboard__recovery-next-step` en
      `/backoffice`. (Corregido en la code review de #1087: `pwa/tests/e2e` sí tiene recuperación —
      `fixtures/recovery-secret-api.ts` y `backoffice/recovery-readiness-real-api.spec.ts`, ambos sobre el
      admin compartido.) Plegado en la épica como la historia `rr-3`; no está en `deferred-work.md`.
    location: >-
      pwa/src/context/backoffice/user/application/recoveryRedeemedNotice.ts
    severity: medium (unverified)
---

<intent-contract>

## Intent

**Problem:** Solo el mail de cuenta bloqueada ordena «expulsa las otras sesiones, luego cambia la contraseña». El mail de contraseña cambiada, el formulario de cambio de contraseña y la pantalla tras canjear el secreto de recuperación no lo dicen, así que un usuario con un intruso dentro le entrega la contraseña nueva a una sesión aún abierta.

**Approach:** Solo copy (CAP-2): añadir la guía en los dos cuerpos del mail, una pista con enlace a *Active sessions* sobre los campos del formulario, un toast nuevo y un aviso de una sola vez en el destino del canje; y dejar §7 de `PRODUCTION_SECURITY_CHECKLIST.md` diciendo lo que ya existe.

## Boundaries & Constraints

**Always:**
- Copy en inglés y literal de la companion (AC1–AC3); `pwa/tests/ui-copy-language.test.ts` verde.
- El mail sigue sin enlaces, sin `href`, sin PII y sin afirmar que *este* cambio cerró sesiones ni que la contraseña anterior dejó de funcionar (las aserciones de `testTheBodyClaimsOnlyWhatTheCredentialChangeItselfGuarantees` siguen pasando: el copy nuevo no contiene `signed out`, `signed you out`, `previous password`, `no longer works`).
- Toda URL dinámica o de navegación pasa por `safeHref(...)`; ruta de sesiones = `` `${Routes.PROFILE}/sessions` `` (misma forma que `backofficeMenu.ts:301`).
- El aviso post-canje es un `<aside>` con `aria-label`, nunca `<output>`/`role="status"`/`aria-live` (gate `live-region-surfaces`).
- El copy post-canje nunca sugiere que la cuenta está segura: la contraseña no ha cambiado.

**Never:**
- Cambiar comportamiento de canje, reset o cambio de contraseña, ni endpoints, ni ADR D7.
- Transportar la señal «acabo de canjear» por query string ni `localStorage`/`sessionStorage`.
- Nuevas rutas, nuevos `ProblemDetails.type`.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Canje OK | `REDEEMED` y `login()` confirma | toast `Signed in with your recovery secret. We've signed out your other sessions.`; push a `/backoffice`; el dashboard muestra una vez el aviso «Next: set a new password…» | — |
| Recarga / navegación posterior | aviso ya consumido | `/backoffice` ya no lo muestra | — |
| Entrada normal al dashboard | sin canje previo (login normal, carga directa) | sin aviso | — |
| Canje sin sesión confirmada | `REDEEMED` y `login()` → null | rama `sessionUnconfirmed` intacta; no toast; no se marca el aviso | — |
| Canje rechazado | `INVALID` / `SUSPENDED` / `DEACTIVATED` | sin cambios; no se marca el aviso | — |

</intent-contract>

## Code Map

- `api/src/Iam/Identity/Infrastructure/Mail/SymfonyPasswordChangedEmailSender.php` -- `textBody()` L61 y `htmlBody()` L70: dos líneas hoy; insertar el párrafo nuevo entre ambas. Docblock L26-30 (no afirmar cierre de sesiones por este cambio) se mantiene; ampliar una frase explicando por qué *sí* puede afirmar que canje/reset expulsan.
- `api/tests/Unit/Iam/Identity/Infrastructure/Mail/SymfonyPasswordChangedEmailSenderTest.php` -- añadir test sobre texto y HTML del párrafo nuevo y su orden (antes del fallback `contact … immediately`); los tests existentes deben seguir verdes.
- `api/src/Iam/Identity/Infrastructure/Mail/SymfonyAccountLockedEmailSender.php` -- solo lectura: referencia del tono «evict first» ya existente.
- `pwa/src/app/backoffice/profile/_components/ChangePasswordForm.tsx` -- `<form>` L416: insertar la pista como primer hijo (antes de `<MutationError>`), con `next/link` + `safeHref`.
- `pwa/tests/app/backoffice/profile/changePasswordForm.test.tsx` -- añadir caso Vitest de la pista y del `href`.
- `pwa/src/app/(auth)/_components/RecoveryRedeemForm.tsx` -- L118 toast `"Signed in"` → copy nuevo; marcar el aviso de una vez justo antes de `router.push` (L121), solo en la rama confirmada.
- `pwa/tests/app/(auth)/recoveryRedeemForm.test.tsx` -- L131 espera `"Signed in"`: actualizar; añadir aserción de que el aviso queda marcado solo en éxito confirmado.
- `pwa/src/app/backoffice/page.tsx` -- server component del dashboard (sin `"use client"`); montar debajo del `<h1>` un componente cliente nuevo.
- `pwa/src/context/backoffice/user/application/` -- carpeta destino del almacén de un solo uso (ya importada por `RecoveryRedeemForm` vía `schemas/`; no rompe `.dependency-cruiser.cjs`).
- `pwa/src/components/erpify/NoticeBanner.tsx` -- solo lectura: patrón `<aside aria-label>` + punto de tono; puede reutilizarse (`tone="info"`, enlace a `Routes.PROFILE`) si encaja visualmente, si no un `<aside>` propio equivalente.
- `pwa/src/app/backoffice/BackOfficeLayoutClient.tsx` -- solo lectura: `RecoveryReadinessNotice` (RR-1) aparece solo tras el canje porque el secreto queda gastado; no tocar.
- `PRODUCTION_SECURITY_CHECKLIST.md` L1332-1338 -- la cláusula «whose first act has to be `revoke-others`» ya la reescribió #1001 («the redemption flow needs none, because it evicts on its own»); falta registrar que (2) existe ahora, igual que L1335-1338 registra la mitad de aviso de (1).

## Tasks & Acceptance

**Execution:**
- `api/src/Iam/Identity/Infrastructure/Mail/SymfonyPasswordChangedEmailSender.php` -- insertar en texto y HTML, entre la línea de cambio y el contacto: `If this was not you, use your recovery secret or reset your password from the sign-in page — either one signs every other device out. Then set a new password.`; convertir el contacto en fallback `If neither works, contact {from} immediately.` (HTML: `<strong>` en «either one signs every other device out», `{$safeFrom}` escapado); ampliar docblock -- AC1.
- `api/tests/Unit/Iam/Identity/Infrastructure/Mail/SymfonyPasswordChangedEmailSenderTest.php` -- test nuevo: ambos cuerpos contienen la guía y aparece antes de `contact … immediately` (`strpos`) -- AC5.
- `pwa/src/app/backoffice/profile/_components/ChangePasswordForm.tsx` -- pista `Think someone else is signed in? Sign out other devices first in <Link>Active sessions</Link>, then change your password here.` con `data-testid="change-password-form__evict-first-hint"` y enlace `change-password-form__sessions-link` -- AC2.
- `pwa/src/context/backoffice/user/application/recoveryRedeemedNotice.ts` -- almacén en memoria de módulo con `markRecoveryRedeemed()`, `peekRecoveryRedeemed()` y `clearRecoveryRedeemed()` (lectura pura + borrado separado para sobrevivir al doble montaje de StrictMode) -- soporte AC3.
- `pwa/src/app/(auth)/_components/RecoveryRedeemForm.tsx` -- toast nuevo y `markRecoveryRedeemed()` antes del push en la rama confirmada -- AC3.
- `pwa/src/app/backoffice/_components/RecoveryRedeemedNextStep.tsx` -- `"use client"`; `useState(peekRecoveryRedeemed)` + `useEffect(clearRecoveryRedeemed, [])`; si verdadero, `<aside aria-label="Next steps after recovery" data-testid="dashboard__recovery-next-step">` con `Next: set a new password — use Forgot password if you no longer know it — then create a new recovery secret. The one you used is spent.` y enlace a `Routes.PROFILE` -- AC3.
- `pwa/src/app/backoffice/page.tsx` -- montar `<RecoveryRedeemedNextStep />` bajo el `<h1>` -- AC3.
- `pwa/tests/...` -- Vitest: pista del form; toast + marca solo en éxito confirmado; componente del aviso visible una vez, ausente en el segundo montaje y sin marca -- AC5.
- `PRODUCTION_SECURITY_CHECKLIST.md` -- §7: registrar que la guía (2) existe (mail de contraseña cambiada, formulario de cambio con enlace a *Active sessions*) y que el canje dice la segunda mitad (contraseña nueva, secreto nuevo) sin afirmar seguridad -- AC4.

**Acceptance Criteria:**
- Given un cambio de contraseña, when se envía el mail, then texto y HTML dicen la guía de AC1 antes del contacto de fallback, sin enlaces y sin las afirmaciones prohibidas.
- Given la página de perfil, when se renderiza `ChangePasswordForm`, then sobre los campos aparece la pista de AC2 con enlace a `/backoffice/profile/sessions`.
- Given un canje confirmado, when el usuario llega a `/backoffice`, then ve el toast nuevo y una única vez el aviso «Next: …», y el aviso de RR-1 sigue apareciendo por su cuenta.
- Given el PR, when se lee §7, then no queda ninguna frase que exija `revoke-others` al canje y (2) consta como entregada.
- Given el árbol, when corren `make php.quality`, `make pwa.quality` y los tests, then todo verde, `ui-copy-language` incluido.

## Spec Change Log

## Review Triage Log

### 2026-10-22 — Review pass
- verdicts: 23 findings — high 0, medium 3, low 9, false 9, maybe-false 2
- findings:
  - `[low]` `[patch]` (blind) El docblock del mail decía que el reset «revoca todas» las sesiones; `CompletePasswordReset` lo hace best effort tras el commit (`RevokeSessionsBestEffort`) — docblock reescrito: best effort, respaldado por la des-autenticación nativa del cambio de credencial. El copy del mail no cambia (literal de AC1).
  - `[low]` `[reject]` (blind) «Then set a new password» sobra en la rama de reset — copy literal de AC1 de la companion; el intent fija la redacción.
  - `[medium]` `[patch]` (blind) El aviso post-canje dice «use Forgot password» y solo enlaza al perfil, donde cambiar la contraseña exige la actual — el aviso es ahora un `<aside>` propio (alternativa ya prevista en el Code Map) con «Forgot password» enlazado a `Routes.FORGOT_PASSWORD` (verificado: `(auth)/layout.tsx` no redirige con sesión) y el enlace al perfil.
  - `[low]` `[reject]` (blind) La marca sobrevive al logout si el dashboard no llega a montarse — se marca justo antes de `router.push` al propio dashboard; exige abandonar esa navegación y que otra persona entre en la misma pestaña sin recargar, y el arreglo cruzaría el `AuthProvider` compartido.
  - `[low]` `[reject]` (blind) La pista del formulario no está en `aria-describedby` — es el primer hijo del `<form>` y se lee en orden; enhebrarla por `FormField`/`PasswordInput` es fontanería añadida para un caso marginal.
  - `[low]` `[patch]` (blind) §7 no registraba lo que el aviso de una vez no cubre — añadida la frase: vive en la memoria de la pestaña, se pierde con una recarga o si monta otra página antes, y solo el banner de RR-1 repite parte.
  - `[maybe-false]` `[defer]` (blind) El traspaso por memoria de módulo no tiene test de navegador — si fuera cierto sería medium (el aviso nunca aparecería); lo zanja un Playwright canje → dashboard. Registrado en `deferred`.
  - `[low]` `[patch]` (blind) La separación peek/clear existe por el doble montaje de StrictMode y ningún test lo ejercía — añadido test bajo `<StrictMode>`: el aviso se ve y la marca se consume.
  - `[false]` `[reject]` (blind) Los dos banners (RR-1 y el nuevo) se solapan — AC3 exige que el de RR-1 aparezca por su cuenta tras el canje; la coexistencia es el contrato.
  - `[false]` `[reject]` (blind) La prueba «nunca dice safe» es estrecha y no cubre el toast — toast y aviso se aseveran por cadena exacta (`toHaveBeenCalledWith(SIGNED_IN_TOAST)`, `textContent` igual al copy), que no deja colar ninguna palabra.
  - `[medium]` `[patch]` (edge) El mail manda a «use your recovery secret … from the sign-in page» y el login solo enlazaba *Forgot password*; `/recovery` solo salía en el muro LOCKED aunque el canje no exige bloqueo (`RedeemRecoverySecret` «clears any lockout») — `LoginForm` enlaza ahora «Use a recovery secret» a `Routes.RECOVERY` (`login-form__recovery-link`), con test.
  - `[false]` `[reject]` (edge) El mail ofrece el secreto a quien nunca acuñó uno — la frase da la alternativa «or reset your password» y el fallback del buzón de seguridad.
  - `[medium]` `[patch]` (edge) Mismo hallazgo que el «Forgot password» de blind — mismo arreglo.
  - `[low]` `[reject]` (edge) La marca sobrevive si el dashboard nunca monta — misma raíz y misma razón que el hallazgo de blind sobre el logout.
  - `[low]` `[patch]` (edge) El test «renders nothing on the server» corría con la marca limpia y no fijaba nada — eliminado.
  - `[false]` `[reject]` (intent) La frase vecina de §7 «the redemption flow needs none» choca con «Redemption says the second half» — no chocan: el canje no necesita la guía de expulsar y sí dice la de rotar.
  - `[false]` `[reject]` (intent) «If neither works» condiciona el contacto — «keeping contact {from} as the fallback» describe justamente un fallback condicional.
  - `[maybe-false]` `[defer]` (intent) Falta un test extremo a extremo del traspaso — mismo grupo que el defer de blind.
  - `[false]` `[reject]` (intent) «RR-1's banner then appears on its own» no se prueba — lo cubre `pwa/tests/app/backoffice/backOfficeLayoutRecoveryNotice.test.tsx` de RR-1, sin tocar.
  - `[false]` `[reject]` (intent) No consta que `ui-copy-language` corriera — corrió y pasó (8 tests) en `make pwa.test.unit`.
  - `[low]` `[reject]` (intent) En la rama de reset expulsar y rotar son el mismo acto — copy literal de AC1, mismo grupo que el segundo hallazgo de blind.
  - `[false]` `[reject]` (intent) Extras no pedidos (enlace al perfil, módulo nuevo, docblock) — los fija la spec (Tasks) y no cambian comportamiento de servidor.
  - `[false]` `[reject]` (intent) El toast no tiene aserción negativa de «seguro» — se asevera por cadena exacta.

### 2026-10-22 — Review pass
- verdicts: 24 findings — high 0, medium 0, low 9, false 11, maybe-false 4
- findings:
  - `[low]` `[reject]` (blind) La ruta de *Active sessions* queda escrita en dos sitios (`ChangePasswordForm` y `backofficeMenu.ts:301`) — la spec fija esa misma forma; el test fija el `href` literal, así que un cambio de ruta se ve; unificarla añade superficie pública (una entrada nueva en `Routes`) por un caso raro.
  - `[low]` `[patch]` (blind) El comentario de `ACTIVE_SESSIONS_PATH` decía que la contraseña nueva «se entrega» a la sesión intrusa, cuando `ChangeMyPassword.php:128` ya revoca las otras sesiones — reescrito: el cambio solo pide, best effort, que las otras sesiones salgan, y una que sobreviva tendría la contraseña nueva.
  - `[false]` `[reject]` (blind) §7 dice que el formulario «abre con el mismo orden» y no es la misma garantía — la frase habla del orden (expulsar, luego rotar), que es idéntico; no afirma igual garantía.
  - `[low]` `[reject]` (blind) La marca de una vez sobrevive si el dashboard no monta y sale después, quizá a otra identidad — carried: misma raíz y razón que la fila de la pasada anterior (exige que la sesión caiga entre `login()` confirmado y el montaje, y una navegación SPA a otra identidad sin recarga; el logout es navegación dura). Lo que sí era falso, la descripción de §7, va en la fila de edge más abajo.
  - `[maybe-false]` `[defer]` (blind) Ningún test prueba el traspaso por memoria de módulo — carried: ya en `deferred` (Playwright canje → dashboard → recarga).
  - `[false]` `[reject]` (blind) El mail ofrece el secreto a quien no tiene — carried: la misma frase da «or reset your password» y el fallback del buzón.
  - `[false]` `[reject]` (blind) El mail no dice dónde está la página de login — el intent exige un mail sin enlaces; el origen es el mismo que el remitente de seguridad y el mail ya se recibe de ese sistema.
  - `[low]` `[patch]` (blind) El test del mail no comprueba que la frase antigua desapareció — añadido `assertSame(1, substr_count(..., 'If this was not you'))` en texto y HTML.
  - `[false]` `[reject]` (blind) El aviso manda a un perfil que exige la contraseña actual — carried: la pasada anterior añadió el enlace a *Forgot password* dentro del propio aviso.
  - `[false]` `[reject]` (blind) El docblock afirma sin prueba que *Forgot password* funciona con sesión — verificado en la pasada anterior: `(auth)/layout.tsx` no redirige con sesión; que el reset revoque y vuelva a firmar es comportamiento existente que el aviso no promete.
  - `[low]` `[patch]` (blind) §7 nombra «RR-1's banner», un id de historia que deja de resolver al cerrar la épica — sustituido por lo que es: el banner del back-office «no live recovery secret».
  - `[maybe-false]` `[reject]` (edge) La rama sin sesión confirmada no dice «pon una contraseña nueva» — si fuera cierto sería low/medium, pero el intent fija la rama `sessionUnconfirmed` intacta (matriz I/O: sin toast, sin marca); fuera de alcance por el propio intent.
  - `[low]` `[reject]` (edge) La marca no se limpia en login/logout — carried, misma fila que la de blind sobre la marca rancia.
  - `[low]` `[reject]` (edge) Identidad B ve el aviso de A — carried, misma raíz.
  - `[low]` `[patch]` (edge) §7 decía que el aviso se pierde si otra página monta antes; el código lo guarda hasta el siguiente dashboard — §7 corregido: una recarga lo pierde y, si la redirección acaba en otro sitio, espera y sale en la siguiente visita al dashboard de esa pestaña.
  - `[low]` `[patch]` (edge) El comentario del test de la rama sin confirmar decía que su copy «ya le dice qué hacer» — reescrito: la copy se deja como estaba y no dice nada de la contraseña.
  - `[low]` `[patch]` (edge) §7 atribuía la segunda mitad a todo canje — ahora dice «a redemption whose session is confirmed» y añade que la rama cuyo sondeo falla no recibe ni toast ni aviso.
  - `[low]` `[patch]` (vgap) §7 describe mal cómo se pierde el aviso — mismo grupo y mismo arreglo que la fila de edge.
  - `[low]` `[reject]` (vgap) Marca rancia sin test — carried, misma raíz que la de blind.
  - `[low]` `[reject]` (vgap) Ruta de sesiones duplicada — mismo grupo y razón que la fila de blind.
  - `[maybe-false]` `[defer]` (intent) «Una vez» y «se pierde con recarga» viven en la navegación real y solo se prueban por unidades — carried, ya en `deferred`.
  - `[low]` `[patch]` (intent) §7 no coincide con el código sobre cuándo se pierde el aviso — mismo grupo y mismo arreglo que la fila de edge.
  - `[false]` `[reject]` (intent) La guía del mail solo sirve a quien tiene secreto — carried, misma razón que la fila de blind.
  - `[maybe-false]` `[reject]` (intent) El `<aside>` lleva «Open your profile» además del literal, y el toast no se comprueba contra «safe» — carried: los extras los fija la spec y el toast se asevera por cadena exacta.

### 2026-10-22 — bmad-code-review on PR #1087 (Blind Hunter, Edge Case Hunter, Verification Gap, Acceptance Auditor)
- verdicts: 0 high, 0 medium; ACs 1–5 hold; every `patch` applied in the PR.
- `[low]` `[patch]` (blind) The `ACTIVE_SESSIONS_PATH` comment said a surviving intruder session would hold the new password — false: `SecurityUser::isEqualTo` compares the hash, so it is de-authenticated natively. Rewritten to the real reason (it keeps acting and drains the proof budget until the change commits).
- `[low]` `[patch]` (blind) The notice's Forgot password link: completing a reset revokes every session, this one included — said in the docblock. The copy is the contract's literal (AC3).
- `[low]` `[patch]` (blind) The "marked before leaving" test asserted inside the `push` mock — now records the value and asserts it after.
- `[low]` `[patch]` (blind+acceptance) Login links now go through `safeHref`, like the other new links; the test pins that *Forgot password?* survived.
- `[low]` `[patch]` (blind) The mail test also refuses "is now safe", "is now secure", "is secure".
- `[low]` `[patch]` (acceptance) Two change-relative test comments ("left as it was", "replaces the bare contact us line") rewritten.
- `[low]` `[patch]` (verification-gap) `AccessWall.tsx` and its test called the locked wall the only door to `/recovery`; the sign-in page now links it.
- `[low]` `[patch]` (edge) A session probe that fails while the dashboard shows the notice unmounts it, and the consumed mark does not come back. Rare and a code fix adds state; recorded in §7 instead of fixed. §7's paragraph reflowed (one line ran to 147 columns).
- `[low]` `[patch]` (blind+acceptance) The `deferred:` block's premise was false — `pwa/tests/e2e` has recovery fixtures; annotated, and pointed at `rr-3`. rr-3 gained the toast and negative case, and names seeding the dedicated identity as its first decision.
- `[false]` (blind) "DW-70 is nowhere and the diff does not touch `deferred-work.md`" — bmad-loop's commit added it and `8f2346e0` removed it; the net diff is zero by design.

## Design Notes

- **«Se muestra una vez»**: memoria de módulo, no almacenamiento. `router.push` es navegación de cliente y conserva el módulo; una recarga lo vacía, que es justo «una vez». El servidor nunca llama a `mark…` (solo el handler de submit del navegador), así que su copia es siempre `false`. Montado en la página y no en el layout: el layout persiste entre navegaciones y dejaría el aviso fijo hasta recargar.
- **Fallback del mail**: «keeping contact {from} as the fallback» → `If neither works, contact {from} immediately.`; conserva la subcadena `contact … immediately` que ya asierta el test.
- **AC4**: el texto obsoleto que cita la companion ya no existe (lo reescribió #1001, commit `e216a371`); el AC se cumple dejando §7 exacto tras este PR, no reescribiendo una frase ausente.

## Verification

**Commands:**
- `make php.unit c='--filter SymfonyPasswordChangedEmailSenderTest'` -- verde
- `make php.stan` y `make php.quality` -- sin hallazgos
- `make pwa.test.unit` -- verde (incluye `ui-copy-language`, `live-region-surfaces`, `data-testid-uniqueness`)
- `make pwa.quality` -- verde

## Auto Run Result

Status: done

**Resumen.** Pase de revisión de seguimiento sobre CAP-2 (RR-2), ya entregado en `58cdda14b83f2dd12046a2c19267d70d20963726`: la guía «expulsar primero, rotar después» en el mail de contraseña cambiada, el formulario de cambio de contraseña, el toast del canje y el aviso de una vez en el dashboard. Este pase no cambia comportamiento. Corrige prosa que no coincidía con el código y añade una aserción.

**Ficheros de este pase.**
- `PRODUCTION_SECURITY_CHECKLIST.md` — §7: la segunda mitad solo la dice un canje con sesión confirmada; el aviso espera en memoria hasta el siguiente dashboard en vez de «perderse»; el banner se nombra por lo que es, sin id de historia.
- `pwa/src/app/backoffice/profile/_components/ChangePasswordForm.tsx` — comentario de `ACTIVE_SESSIONS_PATH` alineado con la revocación best effort de `ChangeMyPassword`.
- `pwa/tests/app/(auth)/recoveryRedeemForm.test.tsx` — comentario de la rama sin confirmar corregido.
- `api/tests/Unit/Iam/Identity/Infrastructure/Mail/SymfonyPasswordChangedEmailSenderTest.php` — «If this was not you» aparece una sola vez en cada cuerpo.

**Revisión.** 24 hallazgos en 4 capas (blind 11, edge 6, verification-gap 0 huecos y 3 otros, intent 4).
- Patches aplicados (todos low): 8 filas en 5 grupos: la descripción del aviso en §7, el alcance «solo con sesión confirmada» en §7, el id de historia en §7, el comentario del formulario, el comentario del test y la aserción del mail.
- Defer: ninguno nuevo. Las dos filas carried apuntan al test de navegador que ya está en `deferred`.
- Rechazados, con su razón en el Review Triage Log:
  - la ruta de sesiones duplicada: la fija la spec, y unificarla añade superficie pública;
  - la marca rancia y la identidad B: carried, por una ventana de microsegundos y una navegación SPA;
  - la rama sin confirmar: el intent la deja intacta;
  - la URL de login en el mail: el intent lo prohíbe, sin enlaces;
  - la copy literal y los extras del aviso, carried;
  - cuatro `false` refutados contra el árbol.
- Recuento patcheado por verdict: high 0, medium 0, low 5 grupos.

**Recomendación de revisión de seguimiento: false.** Es un pase de seguimiento y no se patcheó nada `high`: el trabajo converge.

**Verificación (ejecuciones nuevas tras los patches).**
- `make php.unit c='--filter SymfonyPasswordChangedEmailSenderTest'`: OK, 5 tests y 44 aserciones.
- `make php.quality`: exit 0.
- `make pwa.test.unit`: exit 0, 278 ficheros y 2128 tests.
- `make pwa.quality`: exit 0.

**Riesgos residuales.**
- El traspaso canje → dashboard solo tiene cobertura unitaria; el test de navegador sigue en `deferred`.
- Una marca que no se consume espera en la pestaña hasta el siguiente dashboard. §7 ya lo dice así.
- La rama de canje sin sesión confirmada no recibe la segunda mitad de la guía, por decisión del intent.
