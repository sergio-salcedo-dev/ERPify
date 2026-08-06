---
baseline_commit: 5431333272e797b834f73669459ad3198c5e88f0
---

# Story BR-2: Residuos del eje de referencias a persona

Status: review

> Épica: [`epics-backlog-resolution.md`](../planning-artifacts/epics-backlog-resolution.md) · Lote BR-2 · Issues #389 #562 #565 #564
> Rama: `fix/shared-person-reference-residuals-uxa2` · Worktree: `.claude/worktrees/shared-person-reference-residuals-uxa2` · Base: `main` @ `bca43bf1`

---

## Decisiones tomadas (2026-08-06)

**La medición contra el código refutó la disposición planificada en 3 de los 4 issues.** La épica los declaró
«los cuatro medidos y confirmados»; no lo estaban. Las tres decisiones que eso abrió están resueltas:

| # | Decisión | Resuelto |
|---|---|---|
| **D1** | **#389** — alcance de la redacción | **Ambas vías: documento + API.** El arreglo «más barato» de la épica no cierra el sumidero — la petición de API lleva el id bajo `filters[N][value]` (`buildSearchParams.ts:17-26`), clave que ningún `replace actorId` alcanza. Se acepta el coste: redactar `filters[N][value]` se lleva también nivel, acción y fechas, y el log de acceso deja de poder responder «qué filtro se aplicó». |
| **D2** | **#565** — cerrar o implementar | **Cerrar con evidencia + tripwire + mapear `40P01`, acotando el ADR.** El ABBA no es alcanzable y la sentencia `OR` es un arreglo peor que el defecto. El caso real del deadlock no es `audit_log` sino **`event_store`**, que el ADR no midió: su edición delimita el alcance de lo que decidió, no lo revierte. |
| **D3** | **#564** — 2 columnas o 4 + gate | **Sólo las 2 de `audit_log`, más el gate.** Revisada: `identity_user.status`/`failed_attempts` dropearon su default por un argumento **distinto y válido** (el agregado siempre fija el valor; un default enmascararía la escritura que lo olvide), y ponérselo sería un **fail-open** sobre la columna que lee la admisión de sesión. |

> **Estas dos filas son la versión revisada.** D2 y D3 se tomaron primero sobre una recomendación incompleta; un
> pase de validación en contexto fresco encontró que D3 habría causado daño real y que D2 chocaba con un ADR
> que nadie había leído. Queda escrito porque el fallo es reproducible: recomendé «4 columnas» por simetría del
> patrón sin comprobar que las razones para dropear el default fueran la misma en las dos tablas. No lo eran.

**#562 no tenía decisión: tenía un hecho.** El issue describe un N+1 vía `UserRepository::findById()` que **ya no
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
- `'User'` como `resource_type` aparece **una sola vez** como literal en `api/src`: `FulfilIdentityErasure.php:107`.
  **Ojo con la fuerza de esta prueba:** `RequestAuditResourceExtractor.php:27` construye `resource_type` en
  runtime desde `_audit_resource_type` (hoy sólo `Bank`/`BankAccount`), y `api/.audit-resource-types` declara
  esa vía como punto ciego del barrido textual. La conclusión vale hoy; el grep de literales no la agota.
- **El argumento que sostiene la conclusión no es el lock.** La fila que la transacción inserta en `:150-155`
  lleva `actor_id` = **el admin que actúa**, no el sujeto, así que sí participa en contención cruzada. Lo que
  hace inalcanzable el par recíproco es que **no puede coexistir**: `EraseIdentitySubject` hace hard-delete, de
  modo que quien fue borrado nunca vuelve a actuar. Usar «ya tiene lock exclusivo» en el comentario de cierre
  es refutable en review — usa éste.

**Por qué la sentencia `OR` del issue es un arreglo peor que el defecto:**

1. El issue no dice nada del `SET`. Un `SET` plano sobre `WHERE actor_id = :s OR (resource_type = :t AND resource_id = :s)` reescribiría `ip`/`user_agent`/`actor_erased` en filas donde el sujeto es *solo* recurso → **destruye la evidencia de un tercero**, lo que `DbalAuditResourceAnonymiser.php:22-28` prohíbe explícitamente. Exige `CASE WHEN` por columna (plantilla existente: `DbalEventStoreSubjectAnonymiser.php:61-75`).
2. **Regresión GDPR si se coloca en `:140`**: el invariante de `FulfilIdentityErasure.php:142-146` es que el INSERT de `GDPR_SUBJECT_ERASED` va *antes* del pase de recurso. Fusionar en la posición del pase de actor dejaría el id real del sujeto vivo en esa fila.
3. **Rompe los contadores de cumplimiento.** Hoy son dos (`affected_rows`, `anonymized_resource_rows`, `FulfilIdentityErasure.php:243-246`); la fila crosswalk cuenta dos veces. `FulfilIdentityErasureTest.php:70-71` existe **precisamente** para que un implementador que sume no pase desapercibido.
4. **Fuerza refactor de la superficie pública de `Shared/Audit`**: el pseudónimo se acuña dentro de `DbalAuditActorAnonymiser::anonymise()` (`:64`), y `EraseActorAuditTrailCommand.php:112` consume el anonimizador de actor **solo** (CLI, eje actor) y debe seguir funcionando.
5. **«Funde dos barridos en uno» no está medido.** Los dos predicados caen sobre índices distintos (`audit_log_actor_idx` y `audit_log_resource_idx`, `Version20260623164321.php:20,23`) → requiere `BitmapOr`, un plan que el planner abandona por Seq Scan al degradarse la selectividad. Afirmarlo sin `EXPLAIN` es una asunción.

**La tercera opción, que el issue propone y el descarte no puede omitir: `ORDER BY id` determinista.** El propio
#565 sugiere «tomar las filas en orden canónico de `id`», y **el repo ya lo practica exactamente para esto**:
`DoctrineActiveAdministratorDirectory.php:38-44` añade `ORDER BY id … FOR UPDATE` para fijar el orden de
bloqueo, y lo documenta. **Ninguno de los cinco motivos de arriba le aplica**: no funde ejes, no toca los
contadores, no altera la superficie pública de `Shared/Audit` y no reordena nada respecto al INSERT. Un
comentario de cierre que diga «se pesaron las opciones» sin nombrar ésta es refutable. Aceptarla o descartarla
**con argumento** es parte de AC2.

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

### AC1 — #389: ningún id de persona alcanza el log de acceso, por ninguna de las dos vías

- La redacción del Caddyfile cubre **ambas**: los nombres directos (`actorId`, `resourceId`, `correlationId`) y los posicionales `filters[0..8][value]`. `toAuditFilters` (`auditSearchCriteria.ts:22-55`) emite **9** filtros como máximo — level, actorType, actorId, resourceType, resourceId, correlationId, action, from, to → **índices 0..8**, nueve entradas, no ocho.
- `buildSearchParams.ts:22-23` emite además la forma `filters[N][value][]` para el operador `in`. Hoy ningún filtro de auditoría lo usa; **queda fuera de alcance y declarado**, porque un `in` futuro sobre `actorId` se des-redactaría en silencio.
- **Nada ata hoy el rango del Caddyfile al productor**: el gate es un test PHP textual sobre un fichero Caddy y el productor es TypeScript, así que un décimo eje de auditoría des-redacta sin que nada falle. Cerrarlo con margen deliberado o con un assert de longitud del lado PWA — decidir y dejarlo dicho.
- La pérdida de diagnóstico es **aceptada y declarada**: redactar `filters[N][value]` borra también nivel, tipo de actor, acción y fechas del log de acceso. Anotarlo donde vive la garantía, no solo en el PR.
- `CaddyfileAccessLogRedactionGateTest.php` asserta cada parámetro nuevo, y **sigue asertando `authorization` y `token`**.
- Los dos docblocks que hoy se autocontradicen quedan coherentes con el código: `auditUrlState.ts:45-52` y `auditFilter.ts:6-8`.
- El id de persona en el **path** (`ApiEndpoints.ts:38`) queda declarado como residual aceptado en `PRODUCTION_SECURITY_CHECKLIST.md`, con su razón (el filtro `query` de Caddy no lo alcanza).

### AC2 — #565: el defecto queda clasificado por su alcanzabilidad, no por su título

- El comentario de cierre (o de re-encuadre) cita la evidencia: `User.php:38-41`, `AdministratorErasureRequiresDemotion.php:15-18`, y que `'User'` aparece una sola vez en `api/src`.
- Existe un test que **se pone rojo el día que el ABBA se vuelve alcanzable** — es decir, cuando aparezca un escritor de `resource_type='User'` ajeno a la transacción. Precedente de forma: `DoctrineActiveAdministratorDirectoryTest.php:111-137` interroga `pg_locks` para `pg_backend_pid()` en vez de simular concurrencia.
- **`40P01` se mapea a un marcador reintentable** del contrato RFC 9457 (hoy sale 500), y `docs/api-error-contract.md` se actualiza — **obligatorio, NFR26**. La razón de fondo **no es el ABBA** sino `event_store`: `DbalEventStoreSubjectAnonymiser.php:61-69` corre en la **misma** transacción que los dos pases de auditoría y su `payload::text ILIKE` (`:68`) fuerza seq scan sobre filas que cualquier escritura de negocio está insertando por el outbox. Ahí el deadlock **sí** es plausible.
- **Qué marcador y dónde se traduce, decidido aquí y no en el teclado**: no existe ninguno «reintentable» en `api/src/Shared/ErrorContract/Domain/Exception/`, así que hay que crearlo o justificar reutilizar `Conflict`; y como `Doctrine\DBAL\Exception\DeadlockException` **no** es `DomainException`, el punto de traducción hay que nombrarlo explícitamente en el PR (candidato: `ProblemDetailsFactory`, que ya menciona `deadlock` en `:450`).
- **El ADR se ACOTA, no se ignora ni se revierte.** `docs/adr/audit-activity-log.md:177-181` decidió no añadir reintento síncrono, y su argumento —que las clases reintentables no contienden en el write de `audit_log`— **probablemente acierta para `audit_log`**. Lo que el ADR no cubre es `event_store`: `DbalEventStoreSubjectAnonymiser.php:61-69` corre en la misma transacción y su `ILIKE` fuerza seq scan sobre filas que el outbox está insertando. **La edición del ADR delimita su alcance a la tabla que midió**; dejar dos documentos del repo diciendo lo contrario es el patrón contrato↔realidad que este lote existe para cerrar.
- **NO se implementa la sentencia `OR`.** Los cinco motivos están arriba (§#565); el principal es que regresaría GDPR y destruiría evidencia de terceros.

### AC3 — #562: se cierra con evidencia y la deuda restante queda nombrada correctamente

- El comentario de cierre demuestra que el N+1 descrito no existe: el caso de uso no importa `UserRepository` y hace **una** llamada (`ReconcileErasedSubjectReferences.php:106,221`).
- Lo que queda se registra: (a) las cinco lecturas sin `LIMIT` — **nuevas**; (b) el troceo teórico **ya está** en `deferred-work.md:11` y registrarlo otra vez violaría la regla pending-only del registro. Lo que sí hay que hacer con esa bala es **actualizar su `Ref:`**, que apunta a `ReconcileErasedSubjectReferences.php:99` y hoy es `:106`.
- **`LiveIdentityDirectory.php:28-30` deja de afirmar que un llamador trocea**, porque ninguno lo hace. Es la corrección obligatoria aunque no se implemente el troceo. **`DoctrineLiveIdentityDirectory.php:27-30` NO necesita tocarse**: ya es honesto («chunking is the change to make when a measurement says it is close, not before»). Sólo el puerto tiene la frase normativa.

### AC4 — #564: la clase de defecto se cierra por la vía reachable

- Migración **nueva** (jamás editar `Version20260723151422.php` ni `Version20260626215406.php`, ambas mergeadas) con `ALTER COLUMN … SET DEFAULT FALSE` para **las dos columnas de `audit_log`**: `actor_erased` y `resource_erased`; `down()` reversible.
- **`identity_user.status` y `failed_attempts` quedan FUERA, y esto es deliberado.** Su `DROP DEFAULT` (`Version20260709230444.php:22-27`, `Version20260711171040.php:22-27`) **no** obedece a la premisa falsa del schema listener — obedece a un argumento válido y distinto: *el agregado siempre fija el valor explícitamente, y un default latente sólo enmascararía una escritura futura que lo olvide*. Ponerles default convertiría un fallo ruidoso en un **fail-open** sobre la columna que lee la admisión de sesión (`DoctrineActiveAdministratorDirectory.php:49` filtra por `status = :active`). Además la vía reachable no las alcanza: retroceder la imagen por debajo de #467/#475 no es un rollback plausible.
- `AuditLogSchemaListener.php:51-52` declara los defaults, y `:19-23` deja de afirmar lo que sus tres hermanos desmienten.
- **Generar la migración con `make db.diff` DESPUÉS de tocar el listener** — no a mano: el listener es la fuente de verdad de `audit_log`, que no tiene entidad ORM. Exige la BD **en head** antes de generar.
- `docs/deployment-guide.md:193-197` (Rollback) recoge la regla: una columna `NOT NULL` añadida sin `DEFAULT` rompe cada `INSERT` tras un rollback de imagen.
- **Gate** en `api/tests/Unit/Shared/Architecture/`: falla cuando una migración hace `ALTER COLUMN … DROP DEFAULT` sobre una columna que el `ADD COLUMN` de **esa misma migración** declara `NOT NULL` (es el único caso decidible con un barrido textual — no intentar inferirlo de otra forma). **Exención por lista cerrada de los cuatro ficheros históricos, y la lista no puede crecer**: eso es lo que convierte el gate en trinquete en vez de en decoración.
- **El gate se provoca en rojo antes de darlo por bueno**, con una migración sintética en fixture — no basta con que pase, porque tras la exención nace verde sobre conjunto vacío.

### AC5 — Gates y proceso

- `make php.quality`, `make pwa.quality` y los tests tocados, **cada uno de una corrida fresca y con su exit code impreso**.
- `make php.lint.person-reference` y `make php.lint.error-contract` verdes.
- **`make db.migrate` y `make db.validate`** con exit code impreso. Sin esto la migración de #564 no la verifica **nada**: `php.lint.doctrine` usa `--skip-sync` y no mira `audit_log` (no tiene entidad ORM), y `db.validate` no corre en CI. Además `make db.diff` exige la BD **en head** antes de generar, o el diff sale contaminado.
- **Pase adversarial recorded ANTES de abrir el PR** (GDPR/seguridad). El PR se abre en **draft**; el pase lo promueve. El cuerpo del PR dice dónde quedó registrado.
- Ningún issue se cierra sin **evidencia medida contra el código** en el comentario de cierre.

---

## Tasks / Subtasks

- [x] **T0 — D1/D2/D3 resueltas** (2026-08-06): ambas vías · cerrar+tripwire+`40P01` · 4 columnas + gate
- [x] **T1 — #389 redacción del log** (AC: 1)
  - [x] `api/frankenphp/Caddyfile` — `replace` para los 3 nombres directos **y** `filters[0..8][value]`
  - [x] Extender `CaddyfileAccessLogRedactionGateTest.php` — ata el rango al productor PWA; falsificado en 3 direcciones
  - [x] Corregir docblocks `auditUrlState.ts` y `auditFilter.ts`
  - [x] `PRODUCTION_SECURITY_CHECKLIST.md` — el residual del path + la redacción ampliada
  - [x] **Tercera vía, no prevista por la historia (decisión de Sergio, 2026-08-06): `request_uri` del log de aplicación.** `RequestUriRedaction` + los dos callsites de `ExceptionResponder` + `docs/api-error-contract.md` (NFR26)
- [x] **T2 — #565 clasificación + tripwire** (AC: 2)
  - [x] `PersonResourceErasureGateTest::noSecondFileWritesAPersonTypeIntoTheResourceAxis` usa `filesDerivingType()` (las 3 formas), no `sourceFilesCarrying()` (solo literal). Falsificado con un segundo escritor real en `api/src` y con `SecondAuditResourceFixtureWriter` en el árbol de fixtures. **No hizo falta acotar los sembrados de test**: el barrido lee `api/src` únicamente, así que `erase.feature` y `AuditActorAnonymiserFunctionalTest` son invisibles por construcción
  - [x] `DbalAuditResourceAnonymiser` — la separación de ejes pasa a ser una regla sobre lo que las columnas SIGNIFICAN, no una afirmación estadística sobre quién escribió las filas
  - [x] `40P01` → `TransientTransactionFailure` (503 `transient-transaction-failure`) traducido en `DoctrineTransactionManager`, el seam de transacción. Se captura el marcador `RetryableException` de DBAL, no una lista de SQLSTATEs. `docs/api-error-contract.md` actualizado (NFR26); deptrac 0 violaciones
  - [x] `docs/adr/audit-activity-log.md` ACOTADO: su argumento mide el `INSERT` de `activity`; se añade **traducción, no reintento**, así que la decisión no se revierte
  - [ ] Comentario de cierre en #565 (T5)

> **Nota para el PR:** el candidato que la historia sugería para el punto de traducción —`ProblemDetailsFactory:450`,
> «que ya menciona deadlock»— es un falso amigo: ese `deadlock` habla de un ciclo en la cadena de excepciones, no de
> uno de base de datos. Y traducir ahí habría metido un import de Doctrine en `Application`, que es exactamente lo
> que el trinquete de deptrac rechaza.
- [x] **T3 — #562 cierre con evidencia + contrato honesto** (AC: 3)
  - [x] Corregir `LiveIdentityDirectory.php` (sólo el puerto; `DoctrineLiveIdentityDirectory` ya era honesto)
  - [x] `deferred-work.md`: `Ref:` corregida a `:106`/`:221`, prosa que citaba el docblock viejo reescrita, y las 5 lecturas sin `LIMIT` registradas como bala nueva
  - [ ] Comentario de cierre en #562 (T5)
- [x] **T4 — #564 default + listener + doc** (AC: 4)
  - [x] `AuditLogSchemaListener` declara los dos defaults y su docblock deja de afirmar la premisa falsa; `make db.diff` con la BD en head → `Version20260806180031.php`. **Solo `audit_log`**; `identity_user` no produce deriva porque su entidad tampoco declara default
  - [x] `docs/deployment-guide.md` § Rollback recoge la regla
  - [x] `MigrationColumnDefaultGateTest` + `MigrationColumnDefaultRulesGateTest` + `Fixture/MigrationColumnDefault/` (8 fixtures). Provocado en rojo con `Version29990101000000.php` sintética en el árbol real; los fixtures cazaron un fallo real de la regla (`DEFAULT 'ACTIVE' NOT NULL` no se detectaba)
  - [x] Medido: `INSERT` que omite las dos columnas ahora escribe `f`/`f` (probe en transacción con `ROLLBACK`); `db.diff` posterior dice «No changes detected»
- [ ] **T5 — Gates, pase adversarial, PR draft** (AC: 5)
  - [x] Todos los gates verdes, cada uno de corrida fresca con exit code impreso (tabla abajo)
  - [x] `make db.migrate` / `make db.validate` exit 0; `db.diff` posterior sin deriva
  - [ ] **Pase adversarial PARCIAL** — 3 de 4 lectores murieron por límite de sesión; ver la sección dedicada
  - [ ] Comentarios de cierre en #389 #565 #562 #564
  - [ ] PR en draft

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
| `PRODUCTION_SECURITY_CHECKLIST.md` | #389 | residual del path — **y `:360-361` ya afirma que Caddy redacta «the `token` query parameter»; ampliar la redacción la deja rancia** |
| `api/src/Shared/Audit/Infrastructure/Persistence/DbalAuditResourceAnonymiser.php` | #565 | docblock `:23-25`, misma premisa falsa |
| `api/tests/Support/AuditResourceTypeRegistry.php` + `PersonResourceErasureGateTest` | #565 | extender para el tripwire |
| `_bmad-output/implementation-artifacts/deferred-work.md` | #562 | `:11`, actualizar la `Ref:` rancia |

**Rutas que no son adivinables** (varias no están donde el nombre sugiere): `api/src/Iam/Identity/Domain/Entity/User.php` ·
`api/src/Iam/Identity/Domain/Exception/AdministratorErasureRequiresDemotion.php` ·
`api/src/Shared/Audit/Infrastructure/Cli/EraseActorAuditTrailCommand.php` (**en `Shared/`, no en `Iam/`**) ·
`pwa/src/context/shared/http-client/infrastructure/ApiEndpoints.ts` ·
`api/tests/Functional/Iam/Identity/DoctrineLiveIdentityDirectoryTest.php` (**no** bajo `Infrastructure/Persistence/Doctrine/`) ·
`api/tests/Functional/Iam/Identity/Infrastructure/Persistence/Doctrine/DoctrineActiveAdministratorDirectoryTest.php`

### Patrones establecidos a REUTILIZAR (no reinventar)

- **Troceo por lotes en un adaptador DBAL** → `DbalAuditLogPruner.php:40,46,48-52,84,94`: constante privada + parámetro de constructor con default + validación `< 1`. Y su test inyecta un lote pequeño: `AuditLogPrunerFunctionalTest.php:39` (`SMALL_BATCH = 2`), `:57-61`.
- **Gate estático de fichero** → `api/tests/Unit/Shared/Architecture/` (20 clases hoy: `PersonReferenceRulesGateTest`, `ScheduleConsumptionRulesGateTest`, …). Es el hogar canónico.
- **⚠️ El tripwire de #565 NO se escribe de cero.** `api/tests/Support/AuditResourceTypeRegistry.php` + `PersonResourceErasureGateTest` **ya barren `api/src`** por todo tipo que llega a `AuditResource::of()` (literal *y* constante resuelta entre ficheros) y por cada `_audit_resource_type` de ruta, con reglas de derivación/wiring/witness/staleness. Es el 90 % de lo que pide AC2: **extender eso, no añadir un segundo sweep.**
- **Y hay que acotarlo**: `api/features/backoffice/users/erase.feature:113` siembra una fila `resource_type='User'` por SQL, y `AuditActorAnonymiserFunctionalTest.php:113` construye `AuditResource::of('User', …)`. Son test, no producción — pero el tripwire tropezará con ellos.
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

- **Concurrencia: sólo `40P01` sale vacío.** `deadlock` **sí** acierta, en tres sitios que hay que leer antes de tocar nada: `DoctrineActiveAdministratorDirectory.php:41,43`, `ProblemDetailsFactory.php:450` y sobre todo **`docs/adr/audit-activity-log.md:177-181`, que ya decidió NO añadir reintento síncrono** sobre esta tabla, argumentando que las clases reintentables «no contienden» en ese write. **Ese ADR hay que reconciliarlo o acotarlo explícitamente, no ignorarlo.**
- **No existe ningún marcador «reintentable»** en `api/src/Shared/ErrorContract/Domain/Exception/` (hay `ClientError, Conflict, Forbidden, InvalidInput, InvalidSearchCriteria, InvariantViolation, NotFound, RateLimitExceeded, RateLimited, ServiceUnavailable, Unauthenticated`). Y `Doctrine\DBAL\Exception\DeadlockException` **no** es `DomainException`, así que hay que decidir además **dónde se traduce**.
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

**Sondas contra el stack vivo** (no solo gates textuales):

- Log de acceso, petición de documento → `actorId=REDACTED&correlationId=REDACTED&level=security&resourceId=REDACTED`.
- Log de acceso, petición de API → `filters[0][value]=REDACTED`, `filters[8][value]=REDACTED`, y
  **`filters[9][value]` en claro** — el borde del rango es observable, no teórico. Es lo que motivó atar el
  rango al productor en vez de dejarlo escrito en un comentario.
- Log de aplicación → `"request_uri":"/api/audit?actorId=REDACTED&filters%5B0%5D%5Bfield%5D=actorId&filters%5B0%5D%5Bvalue%5D=REDACTED&token=REDACTED"`.
- `INSERT` que omite las dos columnas nuevas → escribe `f`/`f` (probe en transacción, `ROLLBACK`).

**Gates provocados en rojo antes de darlos por buenos** (restaurando por bytes, nunca `git checkout --`):

| Gate | Rojos provocados |
|---|---|
| `CaddyfileAccessLogRedactionGateTest` | hueco no contiguo en los índices · décimo `filters.push(` en el productor TypeScript · `replace token` retirado |
| `RequestUriRedactionTest` | `redact()` neutralizado a `return $requestUri;` → 10 de 16 rojos (exactamente las filas que deben redactar) |
| `MigrationColumnDefaultGateTest` | migración sintética `Version29990101000000.php` en el árbol real |
| `MigrationColumnDefaultRulesGateTest` | los fixtures cazaron un fallo REAL de la regla: `DEFAULT 'ACTIVE' NOT NULL` no se detectaba porque la cola se cortaba en la comilla del literal SQL |
| `PersonResourceErasureGateTest` (tripwire) | segundo escritor real de `AuditResource::of('User', …)` en `api/src` |

**Falso positivo descartado con medición, no con una muestra.** `AuditTimelineSearchCursorFunctionalTest::testTimelineAccessPathsAreIndexBacked`
falló de forma reproducible tras una corrida de Behat. No es regresión de este diff: el test siembra con
`TRUNCATE` + reinserción y no hace `ANALYZE`, así que el planner puede escoger sobre `reltuples` rancio. Un
`ANALYZE audit_log` (solo estadísticas) lo pone verde. Los `DEFAULT` de columna no entran en el modelo de
coste del planner. **Queda como hallazgo fuera de alcance**: es un gate que puede volverse rojo por la
ventana de autovacuum.

### Completion Notes List

- **AC1 cerrado por TRES vías, no dos.** La tercera —`request_uri` del log de aplicación— no estaba en la
  historia; se midió durante T1 y Sergio decidió cerrarla aquí. `RedactionDenylist` no podía protegerla
  porque casa NOMBRES de clave y nunca mira dentro de un valor.
- **Los ejes de identidad NO se metieron en `RedactionDenylist::KEYS`.** Esa lista se casa por subcadena
  también contra las claves de extensión del cuerpo, y `actorId`/`resourceId`/`correlationId` son nombres de
  propiedad de los Resource DTO: añadirlos ahí habría empezado a recortar campos de respuestas en silencio.
- **En PHP el rango no tiene acantilado.** El Caddyfile enumera 0..8 porque su gramática no tiene comodín;
  `RequestUriRedaction` usa un patrón, así que cubre cualquier índice y también la forma `[value][]` del
  operador `in` que el borde deja declarada fuera de alcance.
- **AC2 se resolvió reutilizando `ServiceUnavailable`, no creando un marcador.** 409 le diría al llamador que
  resuelva un conflicto que no existe; 503 es «reinténtalo». Se traduce en `DoctrineTransactionManager` —el
  seam de transacción— y no en `ProblemDetailsFactory`, que es `Application` y habría estrenado un import de
  Doctrine contra el trinquete de deptrac. Se captura el marcador `RetryableException` de DBAL, no una lista
  de SQLSTATEs.
- **El ADR se acotó sin revertirse**: se añade traducción, no reintento síncrono.
- **AC4: no hizo falta acotar los sembrados de test** que la historia anticipaba — el barrido del registro lee
  `api/src` únicamente, así que `erase.feature` y `AuditActorAnonymiserFunctionalTest` son invisibles por
  construcción.

**Fuera de alcance, no arreglado, declarado:**

- `testTimelineAccessPathsAreIndexBacked` puede ponerse rojo por estadísticas rancias (arriba).
- Dos `PHPUnit Notices` preexistentes en `DoctrineSessionRepositoryStoreUnavailableTest` (mocks sin
  expectativas). No es mi diff.

### File List

**Nuevos**

- `api/migrations/2026/Version20260806180031.php`
- `api/src/Shared/ErrorContract/Application/RequestUriRedaction.php`
- `api/src/Shared/Persistence/Domain/Exception/TransientTransactionFailure.php`
- `api/tests/Support/MigrationColumnDefaults.php`
- `api/tests/Unit/Shared/Architecture/MigrationColumnDefaultGateTest.php`
- `api/tests/Unit/Shared/Architecture/MigrationColumnDefaultRulesGateTest.php`
- `api/tests/Unit/Shared/Architecture/Fixture/MigrationColumnDefault/` (8 fixtures)
- `api/tests/Unit/Shared/Architecture/Fixture/PersonResource/src/SecondAuditResourceFixtureWriter.php`
- `api/tests/Unit/Shared/ErrorContract/Application/RequestUriRedactionTest.php`

**Modificados**

- `api/frankenphp/Caddyfile`
- `api/src/Iam/Identity/Domain/Repository/LiveIdentityDirectory.php`
- `api/src/Shared/Audit/Infrastructure/Persistence/AuditLogSchemaListener.php`
- `api/src/Shared/Audit/Infrastructure/Persistence/DbalAuditResourceAnonymiser.php`
- `api/src/Shared/ErrorContract/Infrastructure/Http/EventListener/ExceptionResponder.php`
- `api/src/Shared/Persistence/Infrastructure/DoctrineTransactionManager.php`
- `api/tests/Unit/Shared/Architecture/CaddyfileAccessLogRedactionGateTest.php`
- `api/tests/Unit/Shared/Architecture/PersonResourceErasureGateTest.php`
- `api/tests/Unit/Shared/Architecture/PersonResourceErasureRulesGateTest.php`
- `api/tests/Unit/Shared/ErrorContract/Infrastructure/Http/EventListener/ExceptionResponderTest.php`
- `api/tests/Unit/Shared/Persistence/DoctrineTransactionManagerTest.php`
- `docs/adr/audit-activity-log.md`
- `docs/api-error-contract.md`
- `docs/deployment-guide.md`
- `pwa/src/app/backoffice/audit/_lib/auditFilter.ts`
- `pwa/src/app/backoffice/audit/_lib/auditUrlState.ts`
- `PRODUCTION_SECURITY_CHECKLIST.md`
- `_bmad-output/implementation-artifacts/deferred-work.md`
- `_bmad-output/implementation-artifacts/sprint-status.yaml`

### Gates (corridas frescas, exit code impreso)

| Gate | Exit |
|---|---|
| `make php.quality` (incluye deptrac: 0 violaciones) | 0 |
| `make pwa.quality` | 0 |
| `make php.unit` (2345 tests) | 0 |
| `make php.behat` (399 escenarios) | 0 |
| `make pwa.test.unit` | 0 |
| `make php.lint.person-reference` | 0 |
| `make php.lint.error-contract` | 0 |
| `make php.lint.audit-resource` | 0 |
| `make php.lint.bounded-context` | 0 |
| `make php.lint.persistent-transport` | 0 |
| `make php.lint.schedule-consumption` | 0 |
| `make db.migrate` | 0 |
| `make db.validate` (schema in sync) | 0 |
| `make db.diff` posterior | «No changes detected» (sin deriva) |

### Pase adversarial (AC5) — PARCIAL, registrado aquí

**Estado: INCOMPLETO. El PR se abre en draft y NO debe promoverse hasta cerrarlo.**

Lanzados cuatro lectores hostiles read-only en contexto fresco (2026-08-06). **Tres murieron por límite de
sesión** (#389 sumideros, #565 tripwire+503, crítico de completitud). Sobrevivió uno, que enumeró
exhaustivamente los escritores de `audit_log.resource_type`.

**Lo que el pase confirmó, con evidencia:**

- `User` **no** implementa `AuditedEntity` (`User.php:38`), así que suspender o degradar no escribe fila.
- `EraseIdentitySubject.php:59` hace **hard delete** de la fila de `identity_user`.
- Ni el pase de actor (`DbalAuditActorAnonymiser.php:67-77`) ni el de recurso
  (`DbalAuditResourceAnonymiser.php:54-62`) llevan `ORDER BY`; los dos corren en la transacción abierta en
  `FulfilIdentityErasure.php:133`. El ABBA es estructuralmente posible y sólo lo bloquea la no-coexistencia.
- **Un solo `AuditResource::of` con `'User'` en `api/src`** (`FulfilIdentityErasure.php:147`, constante en
  `:107`). El tripwire no está verde por un fallo del barrido: el singleton es real.
- `ReconcileErasedSubjectReferences.php:132-134` lee `FulfilIdentityErasure::SUBJECT_RESOURCE_TYPE`, pero
  **fuera** de un `AuditResource::of(...)`, así que no entra en `filesDerivingType` — correcto.

**Hallazgo aplicado:** el tripwire no ve un tipo pasado como **variable** (`AuditResource::of($type, $id)`),
forma que no casa con ninguna de las tres derivaciones y que **no levanta excepción**. Queda declarado en el
docblock de `noSecondFileWritesAPersonTypeIntoTheResourceAxis` en vez de dejar que un verde se lea como más
de lo que prueba.

**Pendiente antes de sacar el PR de draft:** los tres ángulos que no se llegaron a ejecutar — las otras
superficies de log que pueden recibir un id de persona (#389), si `RetryableException` llega envuelta y la
traducción es código muerto (#565), y la revisión AC-por-AC del crítico de completitud.

### Change Log

| Fecha | Cambio |
|---|---|
| 2026-08-06 | Implementación completa de T1–T4; T5 con gates verdes y pase adversarial **parcial** |
