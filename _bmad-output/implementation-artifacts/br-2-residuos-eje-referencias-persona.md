# Story BR-2: Residuos del eje de referencias a persona

Status: ready-for-dev

> Épica: [`epics-backlog-resolution.md`](../planning-artifacts/epics-backlog-resolution.md) · Lote BR-2 · Issues #389 #562 #565 #564
> Rama: `fix/shared-person-reference-residuals-uxa2` · Worktree: `.claude/worktrees/shared-person-reference-residuals-uxa2` · Base: `main` @ `bca43bf1`

---

## 🚦 BLOQUEO — tres decisiones antes de escribir código

**La medición contra el código refuta la disposición planificada en 3 de los 4 issues.** La épica los declaró
«los cuatro medidos y confirmados»; no lo están. Ningún dev agent debe elegir por su cuenta entre estas
opciones: cada una cambia qué se entrega y qué se cierra.

| # | Decisión | Por qué no se puede asumir |
|---|---|---|
| **D1** | **#389** — ¿alcance del arreglo: solo la vía del documento, o también la de la API? | El arreglo «más barato» de la épica (`replace actorId REDACTED` en Caddy) **no cierra el sumidero**: la petición de API lleva el id bajo `filters[N][value]` (`buildSearchParams.ts:17-26`), clave que ningún `replace actorId` alcanza. Cubrirla exige enumerar 9 índices posicionales y **redacta también ejes no-PII** (nivel, acción, fechas) → pérdida real de diagnóstico. Es un intercambio observabilidad↔privacidad, no un detalle técnico. |
| **D2** | **#565** — ¿cerrar con evidencia, o implementar la sentencia `OR`? | El ABBA **no es alcanzable**: suspender/degradar a un peer no escribe ninguna fila de auditoría (`User` no es `AuditedEntity`, `User.php:38-41`), así que las «dos filas recíprocas» no pueden existir. Y la sentencia `OR` tal como la describe el issue **regresaría GDPR** y destruiría evidencia de terceros (ver §#565). |
| **D3** | **#564** — ¿alcance: 2 columnas de `audit_log`, o las 4 del repo + un gate? | La «tensión» que el issue pide no re-litigar **descansa sobre un error de hecho** (el schema listener *sí* puede expresar defaults; tres listeners hermanos lo hacen). Caída la premisa, el arreglo es barato — lo que reabre si aplicarlo a las 4 columnas y blindarlo con un gate. |

**#562 no tiene decisión: tiene un hecho.** El issue describe un N+1 vía `UserRepository::findById()` que **ya no
existe** (entregado en G-1c/#634). Se cierra con evidencia; lo que queda es otra deuda, ya registrada en
`deferred-work.md:11`, y **no es #562**.

---

## Story

Como **responsable del cumplimiento GDPR de ERPify**,
quiero que **los cuatro residuos del eje de referencias a persona se resuelvan por su consecuencia medida y no por su título**,
para que **ningún id de persona sobreviva a su propio borrado en un sumidero sin dueño, y para que el backlog deje de afirmar defectos que el código ya desmiente**.

---

## Realidad medida, issue por issue

### #389 — ids de persona en el log de acceso de Caddy · **CONFIRMADO, alcance mayor del planificado**

**Confirmado:**

- `pwa/src/app/backoffice/audit/_lib/auditUrlState.ts:94-103` escribe los `FILTER_KEYS` (`:19-29`) como query params. Incluye `actorId`, `resourceId`, `correlationId`.
- `api/frankenphp/Caddyfile:24-29` redacta **solo** `authorization` y `token`. Un único Caddyfile sirve dev y prod (`api/frankenphp/` no tiene overlay).
- El sumidero no tiene dueño de borrado: ninguna `compose*.yaml` declara `logging:` → stderr → driver `json-file` por defecto, sin rotación declarada. `api/.person-reference-policy` no puede alcanzarlo (su universo son columnas `Types::GUID` de entidades).
- El docblock se autocontradice: `auditUrlState.ts:46-47` llama PII a `actor_id`/`resource_id` y `:94-103` los pone en la barra. **Duplicado** en `auditFilter.ts:6-8` — ningún artefacto lo menciona; hay que corregir **los dos**.

**Corregido respecto al issue y a la épica:**

| Afirmación | Veredicto |
|---|---|
| El eje `ip` existe | **FALSO**. `AuditFilter` (`auditFilter.ts:13-23`) no lo declara. `ip` existe pero en el read-model de detalle diferido (`AuditEntry.ts:13`). La épica ya recoge esta corrección. |
| `correlationId` es un id de persona | **FALSO**. Es el UUID v7 de correlación de petición; no está en `api/.person-reference-policy`. Redactarlo se justifica por otro argumento (reconstruir una sesión), no por este eje. |
| `resourceId` es PII incondicional | **MATIZADO**. Lo es sii `resourceType` denota persona (`api/.audit-resource-types`, `User => person`). |
| «tres líneas antes» | **FALSO**. Docblock en `:45-52`, escritura en `:94-103` → 49 líneas. La contradicción existe; la proximidad no. |
| El arreglo más barato cierra el sumidero | **FALSO — y es la corrección que cambia el alcance.** Cubre solo la petición del documento. La de API (que se dispara **en cada tecleo del filtro**) lleva el id en `filters[N][value]` (`buildSearchParams.ts:17-26`, `ApiAuditTimelineRepository.ts:120-123`). |

**Fuera de alcance pero mismo sumidero (declarar como residual, no arreglar aquí):** `ApiEndpoints.ts:38`
compone `/users/${encodeURIComponent(id)}` — id de persona en el **path**. El filtro `query` de Caddy es
estructuralmente incapaz de tocarlo.

### #565 — ABBA entre el pase de actor y el de recurso · **NO ALCANZABLE (latente)**

**Lo que sí se sostiene:**

- Los dos pases están en la misma transacción y en ese orden: `FulfilIdentityErasure.php:133` (`transactional`) → `:140` pase de actor (`DbalAuditActorAnonymiser.php:66-76`) → `:160-163` pase de recurso (`DbalAuditResourceAnonymiser.php:48-57`).
- Ninguno lleva `ORDER BY` → el orden de bloqueo no está fijado por contrato.
- **`40P01` no está mapeado a nada reintentable en todo el repo** (grep vacío en `api/src`, `api/config`, `docs/`) → saldría como 500 por la vía RFC 9457. Éste es el hallazgo con valor real del issue.

**Lo que la épica declara y el código desmiente:**

- «Dos administradores que se hayan suspendido mutuamente producen las dos filas recíprocas» → **FALSO**.
  `ChangeUserStatus.php` y `ChangeUserRoles.php` no contienen ninguna referencia a `Audit`; `User` no es
  `AuditedEntity` (`User.php:38-41`, deliberado — evita `password_hash` en el trail), así que
  `AuditWriteCaptureListener.php:78` descarta la entidad. **Suspender o degradar a un peer no escribe ninguna
  fila.** Lo confirma el docblock de `AdministratorErasureRequiresDemotion.php:15-18`.
- `'User'` como `resource_type` aparece **una sola vez** en `api/src`: `FulfilIdentityErasure.php:107`. La única
  fila de ese tipo en producción **la inserta la propia transacción** en `:150-155`, 10 líneas antes del pase de
  recurso → ya tiene lock exclusivo sobre ella. **No puede esperar a nadie.**

**Por qué la sentencia `OR` del issue es un arreglo peor que el defecto:**

1. El issue no dice nada del `SET`. Un `SET` plano sobre `WHERE actor_id = :s OR (resource_type = :t AND resource_id = :s)` reescribiría `ip`/`user_agent`/`actor_erased` en filas donde el sujeto es *solo* recurso → **destruye la evidencia de un tercero**, lo que `DbalAuditResourceAnonymiser.php:22-28` prohíbe explícitamente. Exige `CASE WHEN` por columna (plantilla existente: `DbalEventStoreSubjectAnonymiser.php:61-75`).
2. **Regresión GDPR si se coloca en `:140`**: el invariante de `FulfilIdentityErasure.php:142-146` es que el INSERT de `GDPR_SUBJECT_ERASED` va *antes* del pase de recurso. Fusionar en la posición del pase de actor dejaría el id real del sujeto vivo en esa fila.
3. **Rompe los contadores de cumplimiento.** Hoy son dos (`affected_rows`, `anonymized_resource_rows`, `FulfilIdentityErasure.php:243-246`); la fila crosswalk cuenta dos veces. `FulfilIdentityErasureTest.php:70-71` existe **precisamente** para que un implementador que sume no pase desapercibido.
4. **Fuerza refactor de la superficie pública de `Shared/Audit`**: el pseudónimo se acuña dentro de `DbalAuditActorAnonymiser::anonymise()` (`:64`), y `EraseActorAuditTrailCommand.php:112` consume el anonimizador de actor **solo** (CLI, eje actor) y debe seguir funcionando.
5. **«Funde dos barridos en uno» no está medido.** Los dos predicados caen sobre índices distintos (`audit_log_actor_idx` y `audit_log_resource_idx`, `Version20260623164321.php:20,23`) → requiere `BitmapOr`, un plan que el planner abandona por Seq Scan al degradarse la selectividad. Afirmarlo sin `EXPLAIN` es una asunción.

### #562 — troceo contra el techo de 65535 · **EL ISSUE YA ESTÁ RESUELTO; la deuda que queda es otra**

**El cuerpo de #562 no menciona `existingIdsAmong`, ni trocear, ni 65535.** Dice:

- «`ReconcileErasedSubjectReferences::unreconciledSubjectIds()` resolves one id at a time» → **método inexistente** (hoy `unreconciledReferences()`, `:103`).
- «the use case then calls `UserRepository::findById()` once per id» → **falso hoy**: el caso de uso no importa `UserRepository`; su colaborador es `LiveIdentityDirectory` y hace **una** llamada (`:106`/`:221`).
- «Suggested direction: a chunked `findExistingIds(list<string>)`» → **entregado en G-1c/#634**, y mejor de lo pedido (puerto propio en vez de `UserRepository`, por ISP).

**Lo que sí queda abierto de #562:** cinco lecturas sin `LIMIT` ni keyset —
`DbalPersonResourceReferences.php:36-41`, `DbalMembershipPersonReferences.php:41-42`,
`DbalSessionPersonReferences.php:46-47`, `DbalInvitationPersonReferences.php:40-41`,
`DbalPasswordResetTokenPersonReferences.php:47-48`.

**El troceo (lo que la épica describe) es real pero TEÓRICO y no es #562:**

- Medido en el expansor de DBAL: `ExpandArrayParameters.php:115-127` emite **un `?` y un parámetro ligado por id**. `ArrayParameterType` es azúcar del lado PHP. El techo de 65535 (Int16 del mensaje `Bind`) **aplica de verdad**, y falla en duro, no degradando.
- Alcanzarlo exige **≥65 536 personas distintas** referenciadas a la vez, con `api/CLAUDE.md` describiendo *one organization per installation*.
- No hay troceo aguas arriba: `grep array_chunk api/src api/tests` → **0**.
- Vive en `deferred-work.md:11`, **sin issue de GitHub asociado**.

**La deuda de más valor no es el techo, es el contrato que miente:** `LiveIdentityDirectory.php:28-30` dice «a
caller far past that scale must chunk». **Ningún llamador trocea.** Un lector concluye que alguien lo hace. Ese
desajuste contrato↔realidad es lo que hará que el próximo llamador asuma el problema resuelto.

### #564 — `NOT NULL` sin default · **ALCANZABLE POR OTRA VÍA; la «tensión» es un error de hecho**

**El título miente en su parte causal.** No hay despliegue rodante y **no puede haberlo**: `compose.prod.yaml:50-54`
declara solo `resources.limits` en `php`, y `compose.yaml:33-45` publica puertos de host fijos `80/443` →
`--scale php=2` falla por conflicto de puerto. Es una **imposibilidad estructural**, más fuerte que lo que
argumenta el comentario de cierre del propio issue.

**Pero la vía reachable existe y nadie la nombró: el rollback de imagen.** `docs/deployment-guide.md:195` dice
literalmente «redeploy the previous image tag». Volver a la imagen pre-#558 sin revertir la migración pone el
writer viejo (sin `resource_erased` en el INSERT, verificado en `git show 080faa16^`) contra el esquema nuevo
(`NOT NULL` sin default). `down()` **no se ejecuta** en un rollback de imagen. **Con una sola réplica.**

**Los tres tiers de consecuencia, verificados:**

| Tier | Fichero | Consecuencia |
|---|---|---|
| `activity` | `SymfonyAuditLogger.php:77-92` (`catch Throwable` → warning) | pérdida silenciosa del trail |
| `security` | `SymfonyAuditLogger.php:65-72` (sin `try`, deliberado) | la petición falla |
| `change` | `AuditWriteCaptureListener.php:57` (sin `catch`, dentro de `onFlush`) | **la escritura de negocio se cae** |

**La «tensión» que el issue pide no re-litigar descansa sobre una premisa FALSA.** `AuditLogSchemaListener.php:19-23`
afirma que la abstracción de esquema de Doctrine no expresa `DEFAULT` de columna. **Tres listeners hermanos lo
desmienten**: `ProjectionCheckpointSchemaListener.php:35`, `EventStoreSchemaListener.php:47`,
`BankCountSchemaListener.php:33-34` — y la migración generada los emite (`Version20260616201857.php:36-37,44`).
**Caída la premisa, no hay drift permanente en `make db.diff` y el arreglo cuesta 2 ficheros.**

**Alcance real del patrón: 4 columnas en 2 tablas**, no 2 — `audit_log.actor_erased`
(`Version20260626215406.php:25`), `audit_log.resource_erased` (`Version20260723151422.php:27`),
`identity_user.status` (`Version20260709230444.php:28`), `identity_user.failed_attempts`
(`Version20260711171040.php:28`).

**Las dos migraciones están MERGEADAS a `main`** (`080faa16` #558, `884815aa` #375, ambos ancestros de
`origin/main`) → **inmutables**. El arreglo crea una migración nueva.

---

## Acceptance Criteria

### AC1 — #389: ningún id de persona alcanza el log de acceso por la vía elegida en **D1**

- La redacción del Caddyfile cubre el conjunto de parámetros que **D1** determine.
- `CaddyfileAccessLogRedactionGateTest.php` asserta cada parámetro nuevo, y **sigue asertando `authorization` y `token`**.
- Los dos docblocks que hoy se autocontradicen quedan coherentes con el código: `auditUrlState.ts:45-52` y `auditFilter.ts:6-8`.
- El id de persona en el **path** (`ApiEndpoints.ts:38`) queda declarado como residual aceptado en `PRODUCTION_SECURITY_CHECKLIST.md`, con su razón (el filtro `query` de Caddy no lo alcanza).

### AC2 — #565: el defecto queda clasificado por su alcanzabilidad, no por su título

- El comentario de cierre (o de re-encuadre) cita la evidencia: `User.php:38-41`, `AdministratorErasureRequiresDemotion.php:15-18`, y que `'User'` aparece una sola vez en `api/src`.
- Existe un test que **se pone rojo el día que el ABBA se vuelve alcanzable** — es decir, cuando aparezca un escritor de `resource_type='User'` ajeno a la transacción. Precedente de forma: `DoctrineActiveAdministratorDirectoryTest.php:111-137` interroga `pg_locks` para `pg_backend_pid()` en vez de simular concurrencia.
- Si **D2** elige implementar: `40P01` se mapea a un marcador reintentable del contrato RFC 9457, y `docs/api-error-contract.md` se actualiza (NFR26, obligatorio).

### AC3 — #562: se cierra con evidencia y la deuda restante queda nombrada correctamente

- El comentario de cierre demuestra que el N+1 descrito no existe: el caso de uso no importa `UserRepository` y hace **una** llamada (`ReconcileErasedSubjectReferences.php:106,221`).
- Lo que queda se registra por separado: (a) las cinco lecturas sin `LIMIT`, (b) el troceo teórico.
- **`LiveIdentityDirectory.php:28-30` deja de afirmar que un llamador trocea**, porque ninguno lo hace. Ésta es la corrección obligatoria aunque no se implemente el troceo.

### AC4 — #564: la clase de defecto se cierra por la vía reachable

- Migración **nueva** (jamás editar `Version20260723151422.php` ni `Version20260626215406.php`) con `ALTER COLUMN … SET DEFAULT FALSE` para el conjunto que **D3** determine; `down()` reversible.
- `AuditLogSchemaListener.php:51-52` declara los defaults, y `:19-23` deja de afirmar lo que sus tres hermanos desmienten.
- `docs/deployment-guide.md:193-197` (Rollback) recoge la regla: una columna `NOT NULL` añadida sin `DEFAULT` rompe cada `INSERT` tras un rollback de imagen.
- Si **D3** elige el gate: un `*RulesGateTest` en `api/tests/Unit/Shared/Architecture/` que falle sobre `ALTER COLUMN \w+ DROP DEFAULT` en `api/migrations/**` cuando la columna es `NOT NULL`.

### AC5 — Gates y proceso

- `make php.quality`, `make pwa.quality` y los tests tocados, **cada uno de una corrida fresca y con su exit code impreso**.
- `make php.lint.person-reference` y `make php.lint.error-contract` verdes.
- **Pase adversarial recorded ANTES de abrir el PR** (GDPR/seguridad). El PR se abre en **draft**; el pase lo promueve. El cuerpo del PR dice dónde quedó registrado.
- Ningún issue se cierra sin **evidencia medida contra el código** en el comentario de cierre.

---

## Tasks / Subtasks

- [ ] **T0 — Resolver D1, D2, D3 con Sergio.** Bloquea todo lo demás. (AC: 1,2,4)
- [ ] **T1 — #389 redacción del log** (AC: 1)
  - [ ] `api/frankenphp/Caddyfile:24-29` — añadir los `replace` del alcance D1
  - [ ] Extender `api/tests/Unit/Shared/Architecture/CaddyfileAccessLogRedactionGateTest.php` — **ojo: el regex `[^}]*` (`:27-37`) no cruza `}`; las entradas nuevas deben ser hermanas dentro del mismo `request>uri query { … }`**
  - [ ] Corregir docblocks `auditUrlState.ts:45-52` y `auditFilter.ts:6-8`
  - [ ] `PRODUCTION_SECURITY_CHECKLIST.md` — el residual del path
- [ ] **T2 — #565 clasificación + tripwire** (AC: 2)
  - [ ] Test que se pone rojo cuando aparezca un escritor externo de `resource_type='User'`
  - [ ] Comentario de cierre/re-encuadre con la evidencia
  - [ ] (Si D2 = implementar) mapeo `40P01` + `docs/api-error-contract.md`
- [ ] **T3 — #562 cierre con evidencia + contrato honesto** (AC: 3)
  - [ ] Corregir `LiveIdentityDirectory.php:28-30` y `DoctrineLiveIdentityDirectory.php:27-30`
  - [ ] Comentario de cierre; registrar por separado las 5 lecturas sin `LIMIT` y el troceo
- [ ] **T4 — #564 default + listener + doc** (AC: 4)
  - [ ] `AuditLogSchemaListener.php:51-52` (+ `:19-23`), **luego** `make db.diff` para generar la migración desde el listener
  - [ ] `docs/deployment-guide.md:193-197`
  - [ ] (Si D3 = gate) `*RulesGateTest` sobre `api/migrations/**`
- [ ] **T5 — Gates, pase adversarial, PR draft** (AC: 5)

---

## Dev Notes

### Ficheros a tocar (rutas relativas al worktree)

| Fichero | Issue | Qué |
|---|---|---|
| `api/frankenphp/Caddyfile` | #389 | `replace` en `format filter` (`:24-29`). **Un solo Caddyfile para dev y prod.** |
| `api/tests/Unit/Shared/Architecture/CaddyfileAccessLogRedactionGateTest.php` | #389 | gate textual; corre en `make php.unit`, **no** en `php.quality` |
| `pwa/src/app/backoffice/audit/_lib/auditUrlState.ts` | #389 | docblock `:45-52` |
| `pwa/src/app/backoffice/audit/_lib/auditFilter.ts` | #389 | docblock `:6-8` |
| `api/src/Iam/Identity/Domain/Repository/LiveIdentityDirectory.php` | #562 | docblock `:28-30` |
| `api/src/Iam/Identity/Infrastructure/Persistence/Doctrine/DoctrineLiveIdentityDirectory.php` | #562 | docblock `:27-30` (+ troceo si se implementa) |
| `api/src/Shared/Audit/Infrastructure/Persistence/AuditLogSchemaListener.php` | #564 | `:51-52` defaults, `:19-23` premisa falsa |
| `api/migrations/2026/Version<nuevo>.php` | #564 | **nueva**; generar con `make db.diff` |
| `docs/deployment-guide.md` | #564 | `:193-197` Rollback |
| `PRODUCTION_SECURITY_CHECKLIST.md` | #389 | residual del path |

### Patrones establecidos a REUTILIZAR (no reinventar)

- **`SET` selectivo por columna en un `UPDATE` con `OR`** → `DbalEventStoreSubjectAnonymiser.php:61-75` ya lo hace con `CASE WHEN`. Es la plantilla si D2 = implementar.
- **Troceo por lotes en un adaptador DBAL** → `DbalAuditLogPruner.php:40,46,48-52,84,94`: constante privada + parámetro de constructor con default + validación `< 1`. Y su test inyecta un lote pequeño: `AuditLogPrunerFunctionalTest.php:39` (`SMALL_BATCH = 2`), `:57-61`.
- **Gate estático de fichero** → `api/tests/Unit/Shared/Architecture/` (21 clases hoy: `PersonReferenceRulesGateTest`, `ScheduleConsumptionRulesGateTest`, …). Es el hogar canónico.
- **Assert sobre ausencia de llamada** → `DoctrineLiveIdentityDirectoryTest.php:65-75` (`expects($this->never())`).
- **Interrogar `pg_locks` en vez de simular concurrencia** → `DoctrineActiveAdministratorDirectoryTest.php:111-137`.
- **Sacar un secreto de la URL tras leerlo** → `TokenActionScreen.tsx:31,39,44` (`history.replaceState`). **No aplica a auditoría**: allí la URL es la fuente de verdad viva del filtro (`auditUrlState.ts:57-90`).

### Regresiones que NO se pueden romper

1. **Deep link de la investigación** — razón de diseño declarada (`auditUrlState.ts:46`, `auditFilter.ts:7-8`). La Opción Caddy no lo toca; sacar `actorId` de la URL sí lo mata.
2. **Modo Jornada** — `auditFilter.ts:88-90` (`hasFixedActor`) exige `actorType` + `actorId` UUID. Pinneado por `auditInvestigationScreen.test.tsx:102`.
3. **Pivote «Ver esta correlación»** — `auditFilter.ts:22`: la URL es su **única** vía de entrada.
4. **Drawer de entrada** — `entry` (`auditUrlState.ts:101`), asertado en `auditInvestigationScreen.test.tsx:66-67,71`.
5. **Cursor keyset del timeline** — `auditUrlState.ts:49-51`: el valor del filtro resetea el cursor; una identidad que oscile machaca la paginación.
6. **Los dos contadores de cumplimiento distintos** — `FulfilIdentityErasureTest.php:70-72,86-87,121` existe para detectar una implementación que sume.
7. **La CLI de eje-actor** — `EraseActorAuditTrailCommand.php:112` consume el anonimizador de actor solo.
8. **`DbalAuditResourceAnonymiser.php:22-28`** — no tocar `ip`/`user_agent`/`actor_erased` en filas donde el sujeto es solo recurso: sería destruir la evidencia de un tercero.
9. **Observabilidad del log de acceso** — redactar `filters[N][value]` en bloque borra también nivel, acción y fechas. Regresión real, no funcional.

### Cobertura existente y huecos

- **Sin cobertura de concurrencia** en ningún sitio: `deadlock`/`40P01`/`DeadlockException` → grep vacío en `api/src`, `api/config`, `docs/`.
- **Sin cobertura de la ventana de migración**: ningún test aplica migraciones sobre BD con datos previos ni ejerce «código N-1 contra esquema N». Ningún gate rechaza `ADD COLUMN … NOT NULL` sin default.
- **Sin `auditUrlState.test.ts`** — ése es el hueco si se toca `FILTER_KEYS`.
- **Sin spec e2e de auditoría** (`pwa/tests/e2e/backoffice/`, 22 specs, ninguno `audit*`).
- **`make php.lint.doctrine` usa `--skip-sync`** (`make/php-quality.mk:71-72`) y no mira `audit_log` (no tiene entidad ORM). `make db.validate` sí, pero **no corre en CI**.

### Project Structure Notes

- `audit_log` **no tiene entidad ORM**: su forma la declara `AuditLogSchemaListener` vía `postGenerateSchema`. Por eso `db.diff` la genera desde el listener y por eso el listener es la fuente de verdad.
- El eje de referencias a persona es preventivo (registro + `#[PersonSubjectReference]`) **y** detectivesco (`PersonReferenceSource` etiquetado). El log de acceso de Caddy queda **fuera de ambos** por construcción — es un punto ciego declarado, no un fallo del gate.
- Los cuatro issues tocan `api/` y `pwa/` pero **no comparten fichero**: T1 y T4 son paralelizables; T2 y T3 tocan ambos `Iam/Identity` — no en paralelo entre sí.

### References

- [`_bmad-output/planning-artifacts/epics-backlog-resolution.md`](../planning-artifacts/epics-backlog-resolution.md) — lote BR-2, orden y criterios de cierre
- [`docs/adr/regulatory-audit-trail.md`](../../docs/adr/regulatory-audit-trail.md) — D15 separa `erase-actor` de `erase-subject`/DEK, **no** `actor_id` de `resource_id` (el issue #565 la cita mal)
- [`docs/adr/audit-activity-log.md`](../../docs/adr/audit-activity-log.md) — D4, `resource_type` es vocabulario del contexto propietario
- [`docs/api-error-contract.md`](../../docs/api-error-contract.md) — NFR26, obligatorio si se añade un marcador
- [`docs/deployment-guide.md`](../../docs/deployment-guide.md) — `:167-183` procedimiento, `:193-197` rollback
- [`PRODUCTION_SECURITY_CHECKLIST.md`](../../PRODUCTION_SECURITY_CHECKLIST.md) — §7 y la redacción del log
- `api/.person-reference-policy`, `api/.audit-resource-types` — registros y sus puntos ciegos declarados en cabecera

---

## Dev Agent Record

### Agent Model Used

claude-opus-5[1m]

### Debug Log References

Medición inicial: 4 subagentes read-only contra `bca43bf1` (2026-08-06). Los cuatro informes refutan la
disposición planificada en #565, #562 y #564.

### Completion Notes List

### File List
