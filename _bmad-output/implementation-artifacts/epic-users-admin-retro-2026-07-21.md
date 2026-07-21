# Retrospectiva — Épica *Administración de usuarios (back-office / Iam)*

- **Fecha:** 2026-07-21
- **Alcance:** 1 épica, 7 historias (U-0…U-5b), ventana 2026-07-16 → 2026-07-21
- **Estado:** épica `done` · 7/7 stories `done`
- **Facilitación:** solo-dev + IA, estilo de las retros de E1 y del arco auth (lentes analíticas reales, no party-mode ficticio).
- **Principio rector de esta retro (decisión de Sergio):** el objetivo no es acumular issues sino **reducir el pile de defers y resolverlos ahora**. Los action items de abajo están triados en *resoluble ya* vs *decisión de diseño propia*, no en "abrir un issue y olvidar".

## Resumen de entrega

| Historia | PR(s) | Qué entregó |
|---|---|---|
| U-0 read-side + auth-data + wiring | #501 (API) · #502 (PWA) | list+detalle keyset (`UserRow` single-context, sin JOIN), `users`→`TIER_OPT_OUT`+grants, swap mock→real sin cambios de consumidor |
| U-1 `/me` deriva permisos + `<Can>` | #503 | `PermissionCatalog`+`PermissionResolver` (12 perms ADMIN, set derivado no persistido), tripwire de completitud, enum PWA alineado byte-a-byte |
| U-2 invitar | #504 (+ issue #505) | `POST /invitations`, form gateado por `users.invite`; `Role` reubicado a `Shared/Access/Domain/Role` (52 ficheros) |
| U-3 cambio de estado | #506 (+ #507) | `PATCH .../status`, 1er adaptador productivo de `ActiveAdministratorDirectory`, revoke de sesiones post-commit |
| U-4 edición de roles | #508 (+ #509) | `PATCH .../roles` (semántica set), `User::changeRoles()`+`UserRolesChanged`, guard condicional, editor checkbox |
| U-5a cerrar #376 | #524 (cierra #376) | borra la cola `activity` (escritura síncrona; diff net-negativo); enmienda ADR D3 |
| U-5b borrado GDPR | #529 | `FulfilIdentityErasure` atómico (identidad + rastro + sesiones en una tx), `DELETE /users/{id}`, type-to-confirm, 409 self-erase |

Adyacente en la ventana: #519/#520 (rechazar miembros no declarados en todo body), #527/#528 (contrato test-id vía ESLint), #521 (retros en lote de las 5 épicas previas), #522 (auditoría de marcadores sprint-status + hook `SessionStart`).

| Métrica | Baseline (fin II) | Fin épica | Δ |
|---|---|---|---|
| PHPUnit | ~1850 | 2058 | ~+200 |
| Behat | 286 | 362 | +76 |
| PWA unit | 1049 | 1111 | +62 |
| Reverts | — | 0 | — |
| Migraciones de esquema | — | 0 | (todas las acciones montan sobre agregados de la Épica II) |
| Incidentes en producción | — | 0 | (el incidente de `/me`, #488, fue en la costura II→U y ya estaba cerrado) |

## Qué fue bien

- **El orden safe-first aguantó una cuarta vez consecutiva, con 0 reverts.** `U-0 (lectura aditiva) → U-1 (habilitador) → (U-2·U-3) → U-4 → U-5a→U-5b`; ninguna historia dependió de una posterior en su orden de merge, y U-0 no cambió comportamiento existente.
- **El puerto RBAC congelado (RM-6, "opcional" en el plan) volvió a pagar renta.** U-1 necesitaba que `/me` *enumerase* permisos — presión directa sobre un puerto que por diseño es una pregunta cerrada. El puerto forzó la salida correcta: **composición, no modificación** (`PermissionCatalog` de literales puros + `PermissionResolver`), con la propuesta externa de tagged-iterator retirada al chocar con `BoundedContextGateTest`. Es la lección cabecera del arco previo, **vindicada por segunda vez**.
- **El review adversarial cazó los dos bugs reales de la épica, ambos de clase "drenar la org a 0 admins, irrecuperable".** U-3: **TOCTOU** — `keepsAnActiveAdminWithout` corría antes y fuera de la transacción, así que dos PATCH concurrentes suspendiendo distintos admins pasaban ambos el guard → 0 admins. Fix: guard dentro de la tx con `SELECT id … FOR UPDATE` sobre el set completo de admins activos (mismas filas, mismo orden → sin deadlock; el perdedor re-lee por EvalPlanQual → 409 limpio). U-4: **lost-update** — el guard *condicional* lee el agregado con `findById` sin lock; fix = `findByIdForUpdate` (`PESSIMISTIC_WRITE`, ya usado por reset/accept).
- **Disciplina "verifica por sabotaje".** El tripwire de U-1 se validó quitando `users.read` del catálogo (build rojo por diseño) y forzando `permissions:[]` en el cliente (e2e rojo), luego restaurando. U-4 hizo que `InMemoryActiveAdministratorDirectory` **registrara los ids consultados** para asertar que el directorio *no* se invoca en add/same-set/non-admin. Un control que no se ha visto fallar no es un control.
- **Pago arquitectónico: `Role` reubicado a `Shared/Access/Domain/Role`** (U-2, 52 ficheros, escalado a Sergio) — borró de golpe las **6 exenciones** de `.bounded-context-allowlist`/deptrac que se estaban acumulando por la arista `Iam/Invitation → Iam/Identity`. Vocabulario RBAC compartido en un kernel, no en un contexto dueño con exenciones per-file.
- **U-5a cerró #376 BORRANDO la cola, no con un tombstone.** Diff net-negativo, y diagnosticó la enfermedad más honda: **dos copias durables de PII regulada** (`audit_log` **y** `messenger_messages`), con la política de retención D4 gobernando solo una. Defendido con **dos pasadas de medición** (el `INSERT` en `audit_log` cuesta 1,7x el de `messenger_messages`, pero es post-response en `kernel.terminate` y el async hace 3,2x trabajo total de BD; y un segundo bench pgbench mostró que el 1,7x se desvanece bajo carga porque domina el commit/fsync). La medición *fue* el entregable, y la enmienda tocó D3 (cuyo invariante declarado se falsó), dejando D4 intacto.
- **U-5b logró atomicidad cross-módulo por transacciones anidadas sobre una `Connection` compartida** — el erase "una operación" (guard + `EraseIdentitySubject` + anonimización de rastro + purga de sesiones + self-audit) con *menos* código que el fallback plan (orden + reconciliación). Y razonó limpio el borde self-erase (409 `self-erasure-forbidden`: sujeto y actor son responsabilidades incompatibles; el sujeto debe dejar de existir mientras aún necesita existir como actor para atribuir su propia evidencia legal).
- **Se cableó un action item de la retro previa:** #522 materializó el candidato-a-hook "post-merge mueve el marcador" (`bmad-status-audit.sh` + hook `SessionStart` + `make bmad.status.audit`).

## Qué costó

- **Los specs afirmaron repetidamente "hechos" falsos, descubiertos solo verificando en vivo.** U-1: 3 notas contradichas (`/me` ya devuelve `{data}`; nunca devolvió `permissions:[]` — el `[]` estaba hardcodeado en `ApiIdentityRepository.ts:60`; el rename del enum PWA no ocurrió en U-0). U-3: 3 divergencias (`identity_user.roles` es `json`, no `jsonb`; `UserSuspended`/`UserDeactivated` **no** se rutean a `async`; `User` deliberadamente **no** implementa `AuditedEntity`). U-4: "espeja U-3" era falso — 5 divergencias. U-2: "email encolado" (NFR9) es un misnomer — es síncrono best-effort post-commit (un token en claro no puede serializarse al transporte). U-5a: el presupuesto de queries E5 estaba mal. **Es la misma cabecera del arco previo ("verifica el framework, no razones sobre él"), recurriendo a nivel de planificación de historia — las notas del plan se pudren entre historias.**
- **La prosa de las AC describe un mecanismo que el sistema real implementa distinto:** "outbox" es en realidad el `event_store`; "CDC audita el cambio" es falso (`User` no es `AuditedEntity`, por diseño anti-fuga de credenciales); "invalida sesiones" era false-green (hizo falta cablear `RevokeSessionsBestEffort`, porque un flip `ACTIVE→SUSPENDED` no toca password ni roles y el `UserChecker` es login-only); "email encolado" es síncrono. Divergencias honestas y bien documentadas, pero el spec engaña a quien lo lea después.
- **Un hueco sistémico de la PWA diferido tres veces sin issue:** `FetchHttpClient.request()` no tiene try/catch → un fallo de red (no-`HttpError`) escapa como unhandled rejection / no-op silencioso en **todos** los forms del backoffice (lo marcan U-2, U-3, U-4; preexiste en `BankForm.tsx:141`). Fix correcto y central en `FetchHttpClient.ts:111`. Exactamente el "la deuda solo se rastrea si tiene número".
- **El mismo flake repo-wide mordió cuatro historias:** "Premature end of PHP process" (multipart/segfault del worker web) en U-1, U-3, U-4, U-5b — no determinista, sensible a la result-cache de PHPUnit, ajeno a los diffs. Mitigaciones conocidas (limpiar result-cache / `PHP_SERVICE=messenger_worker` / batch-run), pero es un impuesto de fiabilidad de CI sin issue dueño.
- **Drift de estado de specs:** U-5a sigue `Status: review` en su cabecera pese a estar mergeada (#524) y `done` en sprint-status; U-5b (`done`) sin podar. Mismo patrón que ii-4/ii-5 en la retro previa.
- **Semántica de CLI cambiada (U-5b D3, ratificada):** `identity:gdpr:erase-subject` ahora **también** anonimiza el rastro + purga sesiones + exige ≥1-admin — contradice el "la CLI se mantiene additiva" (FR8/NFR4). Idem D5: un fallo del self-audit SECURITY dentro de la tx atómica ahora hace **rollback del erase entero** (all-or-nothing, más correcto pero cambio de comportamiento). **Ambos deben ir en release notes.**
- **Decisiones reabiertas en dev, otra vez.** U-5a anuló el título "tombstone" del épico; U-5b anuló la ubicación post-commit de la sesión que fijaron Winston+Amelia (la movió dentro de la tx, porque post-commit dejaría PII de sesión tras borrar la identidad — la misma clase de bug que el `actor_id` huérfano). Ambas salieron **mejores** (medidas/razonadas) y fueron ratificadas — y aquí se hizo bien: ratificación + documentación en la propia historia. Es la mejora sobre el arco previo, donde algunas reaperturas no dejaron rastro en ADR.

## Insights

1. **Un puerto congelado sigue cobrando renta.** RM-6 vindicado por segunda vez; "opcional" fue el peor juicio de dos planificaciones seguidas.
2. **Las notas de planificación se pudren más rápido que el código.** Cada historia re-derivó "hechos" que el épico afirmaba. El spec es hipótesis; el sistema corriendo es la verdad. Presupuesta una pasada de verificación al inicio de cada historia (y corrige la AC in situ, no solo el código).
3. **El review adversarial es load-bearing en ESTE dominio** precisamente porque el modo de fallo es "0 admins, irrecuperable": dos bugs de esa clase (U-3, U-4) en una sola épica, ninguno detectable sin una lectura hostil.
4. **La deuda sin número es invisible** — y peor: la deuda *con* número también se olvida. El objetivo (decisión de Sergio) es **resolver, no acumular**.
5. **"Mide, no asumas" ganó una decisión de arquitectura** (U-5a): la intuición (la cola ahorra tiempo in-request) era cierta-pero-irrelevante (post-response) y se invertía bajo carga.

## Continuidad con la retro previa (arco auth/RBAC/identidad)

- **#376** (arrastraba desde la retro de **E1**, dos retros atrás) → **CERRADO** por U-5a.
- Hook "post-merge mueve el marcador" → **enviado** (#522).
- "Ninguna story de superficie de seguridad `done` sin pasada adversarial registrada" → se aplicó en U-0…U-4… **pero U-5a y U-5b — las dos historias GDPR/audit, las más sensibles — no tienen sección de review adversarial registrada.** El contraejemplo II-8 **recurrió**, y encima en el borrado GDPR.
- **Riesgo latente del 403 en `realtime/authorize` al primer no-ADMIN** (deferred-work.md, RM-3/RM-4): **sigue abierto**, y esta épica lo **acercó** (U-2 ya permite invitar no-ADMINs). No ha disparado porque la consola aún no pone a un no-ADMIN en una sesión de realtime de bancos.

## Readiness

- **Funcional:** la consola está enteramente cableada al backend real (list/detalle/invite/status/roles/erase-GDPR, todo gateado por `users.*` ADMIN-only por opt-out). 0 incidentes, 0 reverts, 0 migraciones.
- **Seguridad:** sólida donde se revisó (dos bugs de drenaje concurrente cazados + `FOR UPDATE`; self-erase rechazado; erase GDPR des-identifica identidad+rastro+sesiones atómicamente). **Débil donde NO se revisó:** U-5b (el borrado GDPR, la historia más sensible del arco) sin pasada adversarial registrada — autocertificada, como II-8.
- **Riesgos latentes con trigger conocido:** (1) 403 de `realtime/authorize` al primer no-ADMIN; (2) política de rol del invitador ausente (#505) — cualquier ADMIN acuña ADMINs sin límite; (3) fallo silencioso de `FetchHttpClient` ante errores de red en todos los forms.
- **Veredicto:** épica técnicamente limpia; el safe-first aguantó por cuarta vez y las dos decisiones de dominio más finas (borrar la cola vs tombstone; atomicidad del erase) se cerraron con medición y ratificación. Los huecos son de **gobernanza** (deuda sin resolver, drift de estado de specs, un cambio de comportamiento de CLI que necesita release notes) y **la pasada adversarial que falta en las dos historias GDPR**.

## Épica siguiente

No hay ninguna definida — users-admin es la cola del arco Iam/acceso. Los pasos naturales son decisiones ya diferidas: la **autoridad de tenancy** (`User.roles` vs `Membership.roles`, SI-15; U-4 escribió solo `User.roles`), la **extracción del core RBAC** del parking de `Iam/Identity/Infrastructure/Security` (diferida desde II-0, es una épica en sí), y saldar la deuda trackeada de abajo.

## Action items — resolver, no acumular

> **Corrección durante la ejecución de la retro (y su mejor evidencia).** Al ir a *resolver* los tres primeros
> "resoluble ya" se descubrió que **ya estaban en `main`**, cerrados por PRs posteriores a los specs de origen y
> nunca reconciliados en el tracking. Eran **deuda fantasma** — justo el tema "los artefactos se pudren y nadie los
> barre", esta vez inflando la propia lista de defers. Lección reforzada: **verifica el estado real contra `main`
> antes de tratar un defer como abierto** (los specs de historia son fotos del momento, no el estado vivo).

### Ya resuelto — solo reconciliar el tracking (verificado en `main`)

- `FetchHttpClient.request()` try/catch central → **#520** (`catch (cause) { throw this.transportError() }` = `HttpError` `NETWORK_ERROR`, status 0; los forms lo pintan por `ProblemDisplay`).
- 500 array-con-claves en el endpoint de invitación → **#509** (`InviteUserRequest` con `#[Assert\Type('list')]` + `#[Assert\Count(max: MAX_ROLES)]` + `roleValues()`; 422 antes del dominio, con filas Behat que lo cubren).
- `InvitedIdentityUnavailable` → **#507** (markerless-500 **honesto por diseño**: ambos throw-sites son faltas de servidor, no input corregible del cliente; su hermano client-triggerable `UserAlreadyMember` sí lleva `Conflict`→409).

### Resoluble ya (deuda REAL abierta, mecánica)

1. **a11y `status: 0` anunciado `polite`.** `ProblemDisplay.tsx:198`: un error de transporte "No response" es visualmente destructivo, pero `isUrgent = status >= 500` lo deja `aria-live="polite"` → no se anuncia asertivamente. Aflorado *al añadir* el productor de transporte (#520). Fix = plegar `status === 0` en el test de urgencia, igual que ya hacen tono/icono. — *PWA*
2. **Cinco páginas de detalle colapsan cualquier error a "puede que se haya borrado".** `banks/[id]`, `bank-accounts/[id]`, sus `edit` y `banks/[id]/accounts/[accountId]/edit` mandan todo `ViewStatus.ERROR` al copy de 404; un 500 o blip de red tras una mutación correcta sugiere un borrado inexistente. Fix mecánico idéntico al **ya aplicado en el detalle de usuario** (comparar `problem?.status` con 404). — *PWA*
3. **Aserciones de test que faltan (blindaje, no comportamiento):** en `status.feature`, `status` ausente/null/vacío/lowercase → 422; admin `INVITED` no rescata al último ADMIN; `SUSPENDED→DEACTIVATED` → 409. Correctos por construcción hoy, sin asertar. — *API*

### Decisión propia (corte/épica — no "issue y olvidar", pero no cabe en un PR corto)

4. **403 de `realtime/authorize` al primer no-ADMIN** (deferred-work.md, RM-3/RM-4; sigue abierto — `5ffa0c13` endureció la *selección*, no la degradación del 403). Esta épica acercó el trigger (U-2 invita no-ADMINs). Decidir: ¿`204` sin cookie para quien no tiene `bank.read`, o degradación PWA del `EventSource`?
5. **Política de rol del invitador (#505):** cualquier ADMIN acuña ADMINs sin límite (`SendInvitation::invite(Role ...)` sin filtrar; el form itera `ALL_ROLES` incl. ADMIN). Superficie de propagación de privilegio — decidir el modelo y cerrarlo.
6. **Lockout por email = DoS contra admins** (review de U-4): revisar antes de producción (throttling adaptativo por origen / MFA + umbrales para privilegiadas / recuperación documentada). **Descartado explícitamente** filtrar `locked_until` en el directorio de admins — no re-proponer.
7. **Gobernanza del transporte `failed`:** gap GDPR vivo, independiente de #376 — otros tipos de mensaje pueden dejar PII sin TTL en `messenger_messages`/`failed`. Heredado de U-5a→U-5b; necesita política de retención/purga.
8. **Pasada adversarial retro-ajustada a U-5b** (borrado GDPR, autocertificada) + fijar la regla: ninguna story de seguridad/GDPR/audit `done` sin review adversarial registrada.
9. **Fetch-antes-del-gate** en la consola (los hooks corren antes de `<Can>` → petición 403 desperdiciada): flag `enabled` en los hooks de `Shared/resource` (cross-cutting).
10. **Autoridad de tenancy `User.roles` vs `Membership.roles` (SI-15)** y **extracción del core RBAC del parking** (desde II-0): las dos decisiones de arquitectura que el arco lleva difiriendo; corte natural de la próxima épica.

### Higiene de artefactos (este PR)

- Poda de specs `done` (u-0…u-5b) — sin enlaces entrantes (las referencias de `deferred-work.md` son encabezados de texto, no links).
- `arch-addendum-users-admin.md` + `epics-users-admin.md` marcados como implementados/históricos.
- Release notes del cambio de semántica de la CLI de erase (U-5b): `identity:gdpr:erase-subject` deja de ser aditiva (anonimiza rastro + purga sesiones + exige ≥1-admin) y el self-audit SECURITY dentro de la tx hace rollback del erase.
