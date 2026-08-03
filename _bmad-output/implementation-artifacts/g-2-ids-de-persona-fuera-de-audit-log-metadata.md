---
baseline_commit: 9dce7355
---

# Story 1.4 (G-2): Ids de persona fuera de `audit_log.metadata`

Status: ready-for-dev

> **DA-1 ESTÁ TOMADA Y NO SE REABRE** (Sergio, 2026-08-03): gana **(A) el redactor** — un único statement en la
> pasada de erasure deja de conservar el id real, y el control de AC3 se resuelve como **testigo de aceptación
> falsable, no como gate de forma**. (B), el gate declarativo de claves de `metadata`, queda como **alternativa
> descartada, no como decisión abierta**. Con esto la precondición normativa de la épica
> ([`epics-gdpr-hardening.md`](../planning-artifacts/epics-gdpr-hardening.md) `:393-398`) queda satisfecha y la
> implementación puede empezar. El argumento completo y lo que (B) costaba están en *Decisión registrada*.

> **`sprint-status.yaml` afirma que ninguna historia de la épica tiene decisión abierta (`:157-159`) — es falso
> para G-2**, y su propia enumeración lo delata: lista G-1a, G-3a, G-3b y G-5, y **no** lista G-2. Épica y
> addendum mantienen el fork vivo. Corregir esa frase es tarea de esta historia.

> **A diferencia de G-1c, esta historia SÍ repara un dato vivo.** Medido contra la BD de desarrollo (solo
> `SELECT`): existe una fila `GDPR_SUBJECT_ERASED` cuyo `metadata->>'subject_user_id'` **no hace JOIN con
> ninguna fila de `identity_user`** — la persona ya fue borrada y su id real sigue ahí. No es prospectiva.

## Story

Como **responsable de cumplimiento**,
quiero que la prohibición de crosswalk deje de depender de un comentario,
para poder afirmar ante un regulador que ninguna fila de auditoría re-liga un pseudónimo con la persona.

**Eje que instala:** la prohibición D4 pasa de **prosa** a **mecanismo**.
**Invariantes que consume:** D4/NFR4, SI-21/NFR1. **Y SI-23**, que el corte no cita y que muerde en la opción
gate (`arch-addendum-gdpr-hardening.md` `:16`: *binds* todo registro, gate o control del repo).
**Dependencias:** ninguna en `main`. G-1a/G-1b ya shipped; el desbloqueo está declarado en `sprint-status.yaml`
`:154`. **Ver *Orden vs #634*** más abajo: hay una dependencia de orden que el corte no podía prever.

## Estado medido (`main` @ `9dce7355`)

> *Procedencia:* cuatro pases **read-only** en paralelo (artefactos · código · BD dev solo `SELECT` · patrón de
> los ejes ya shipped). Nada escrito, nada ejecutado que mute. Las coordenadas se dan para **re-verificarlas**.

**La fuga es exactamente UNA clave, y la escribe el propio borrado GDPR.**
[`EraseIdentitySubject`](../../api/src/Iam/Identity/Application/EraseIdentitySubject.php) `:60-63` escribe
`'subject_user_id' => $userId` en el `metadata` de la fila `GDPR_SUBJECT_ERASED`. `$userId` es el PK de
`identity_user`, que [`api/.person-reference-policy`](../../api/.person-reference-policy) clasifica
`User::$id => person`. Es un camino **VIVO**:
[`FulfilIdentityErasure`](../../api/src/Iam/Identity/Application/FulfilIdentityErasure.php) `:116` lo invoca
**dentro de la misma transacción** que después borra la fila de `identity_user` y anonimiza los dos ejes, y sus
llamadores son [`UserEraseController`](../../api/src/Iam/Identity/Infrastructure/Http/UserEraseController.php)
`:27` (`DELETE /backoffice/users/{id}`, `IsGranted('users.erase')`) y el CLI `identity:gdpr:erase-subject`.

**Los otros siete caminos de escritura NO meten ids de persona.** Medido uno a uno, no supuesto:

| Camino | Claves | Qué denota |
|---|---|---|
| `FulfilIdentityErasure.php:147-154` (`GDPR_ERASURE_EXECUTED`) | 6 conteos | ningún id — y `:143-146` **explica por escrito** por qué no lleva el pseudónimo |
| `EraseActorAuditTrailCommand.php:115-118` | `anonymized_actor_id`, `affected_rows` | pseudónimo **contractual** (D4.1), no id real |
| `EraseBankAccountSubject.php:64-66` | `encryption_scope_id` | recurso no-persona — **y es join key** de `DbalSubjectErasureReconciler` |
| `AuditTrailReadAuditListener.php:71-81` | `route`, `auditEventId` | id de una fila de `audit_log`, no de persona |
| `AccessDeniedAuditListener.php:64` | `route` | nombre de ruta |
| CDC `AuditWriteCaptureListener.php:47-104` | `changes` | solo `Bank` y `BankAccount` implementan `AuditedEntity` |
| `AccessLogAuditListener.php:86` y los dos searchers | *(ninguna)* | `[]` |

Lo que hace que la superficie sea de **una clave y no de muchas** es deliberado: `User` (`:37-40`), `Session`
(`:30-31`) e `Invitation` (`:39-40`) **no** implementan `AuditedEntity`, así que sus referencias a persona nunca
entran en `metadata.changes`. Ese cierre hay que preservarlo, no redescubrirlo.

**La escritura es un único INSERT y no hay entidad Doctrine.** La tabla se inyecta por
[`AuditLogSchemaListener`](../../api/src/Shared/Audit/Infrastructure/Persistence/AuditLogSchemaListener.php)
`:40-54` (`metadata` es `Types::JSONB` NOT NULL) y se escribe por DBAL crudo en
[`DbalAuditLogWriter`](../../api/src/Shared/Audit/Infrastructure/Persistence/DbalAuditLogWriter.php) `:36-60`.
**No hay ningún UPDATE de `metadata` en el árbol.**

**El borrado no toca `metadata`, y está declarado que no lo hace.**
[`DbalAuditActorAnonymiser`](../../api/src/Shared/Audit/Infrastructure/Persistence/DbalAuditActorAnonymiser.php)
`:66-76` reescribe `actor_id`, `ip`, `user_agent`, `actor_erased`;
[`DbalAuditResourceAnonymiser`](../../api/src/Shared/Audit/Infrastructure/Persistence/DbalAuditResourceAnonymiser.php)
`:48-57` reescribe `resource_id`, `resource_erased`. Ninguno nombra `metadata`. El docblock de
`DbalAuditActorAnonymiser` `:24-26` dice que se deja intacta *sobre la invariante ADR de que no lleva PII —
revisitar el día que una acción guarde datos personales ahí*. **Ese día ya llegó y nadie recogió el trigger:
G-2 no previene, paga deuda.**

**Evidencia empírica (BD dev, solo `SELECT`).** 1 fila con `metadata->>'subject_user_id'`; **0 coincidencias**
al hacer `LEFT JOIN identity_user` contra ese valor, 1 sin coincidencia. Es decir: la fila de la persona se fue
y su id sobrevivió dentro del JSON. Cero filas con email o nombre. Trátese como **evidencia de forma, no como
censo**: ambas BD son de desarrollo y este proyecto no tiene entorno de producción, así que la cifra «1» no
mide prevalencia — dimensiona por conjunto de claves posibles.

**Trampa de datos que muerde a cualquier SQL de esta historia:** `metadata` vacía se persiste como **`[]`**, no
`{}` (es `json_encode([])` en `DbalAuditLogWriter.php:54`) — 33 de 37 filas en una BD y 48 de 54 en la otra.
`jsonb_object_keys(metadata)` **aborta** con `cannot call jsonb_object_keys on an array`, y `metadata ? 'x'`
silencia esas filas en vez de fallar. Todo recorrido debe filtrar por `jsonb_typeof(metadata) = 'object'` o
normalizar antes.

**Ningún registro ni gate alcanza `metadata` hoy.** [`.person-reference-policy`](../../api/.person-reference-policy)
deriva su universo por reflexión sobre entidades Doctrine → estructuralmente ciego a esta tabla;
[`.audit-resource-types`](../../api/.audit-resource-types) cubre **solo** el eje `resource_type`;
[`.persistent-transport-policy`](../../api/.persistent-transport-policy) es otro eje. Los tres entran en
`php.quality` **y** en `php.quality.dry-run` ([`make/php-quality.mk`](../../make/php-quality.mk) `:187`, `:205`).

**G-2 ya está escrita como agujero declarado, y retirarla es parte del trabajo.**
`.person-reference-policy:51-57` nombra `audit_log.metadata` como *standing leak* con `NONE` de control, y
añade que *«la primera está escrita con el id real del sujeto por el mismísimo caso de uso que este fichero
nombra como propietario de `User::$id`»*. Cuando G-2 cierre, ese párrafo miente.

### Orden vs #634 (G-1c), que el corte no podía prever

PR **#634** (G-1c, abierta y verde, **no** en `main`) añade a `.person-reference-policy` un sub-bloque que fija
que el hueco para «columnas sin entidad Doctrine» tiene **exactamente una plaza**, ocupada por
`audit_log.resource_id`, y que *un segundo eje sin entidad no puede añadirse como fuente sin que el registro
crezca antes un verbo para columnas sin entidad*. Si G-2 elige la vía del gate declarativo, **choca con ese
texto** y necesita o el verbo nuevo o un registro propio. Si elige el redactor, no lo toca. **Mídelo contra el
árbol cuando arranques**: si #634 ya mergeó, esa restricción es normativa; si no, todavía es una PR.

### Superficie de rotura

- [`EraseIdentitySubjectTest`](../../api/tests/Unit/Iam/Identity/Application/EraseIdentitySubjectTest.php)
  `:42-45` afirma **igualdad exacta** del array de `metadata`. Es el único test que fija la clave objetivo.
- [`AuditActorAnonymiserFunctionalTest`](../../api/tests/Functional/Shared/Audit/AuditActorAnonymiserFunctionalTest.php)
  `:76`, `:196` afirma que `metadata` queda **byte a byte intacta** tras anonimizar. **Un redactor rompe este
  test por diseño, y romperlo es la señal de que G-2 hizo su trabajo** — no lo silencies, reescríbelo con su
  razón.
- `DbalSubjectErasureReconciler` hace JOIN por `metadata->>'encryption_scope_id'` contra `dek_keystore`: esa
  clave no se toca. Pinneada además en `EraseBankAccountSubjectFunctionalTest.php:171,192` y
  `SubjectErasureReconcilerFunctionalTest.php:65`.
- Features con literales JSON exactos de `metadata`: `backoffice/audit/access_control.feature:61,78`,
  `backoffice/audit/self_audit.feature:26,45`, `shared/audit/security_denial.feature:27`,
  `backoffice/bank_account/audit.feature:26,73`, `shared/audit/write_capture.feature:19`.
- [`features/backoffice/users/erase.feature`](../../api/features/backoffice/users/erase.feature) es el
  **testigo declarado** de `User => person` en `.audit-resource-types`: tocarlo pone rojo
  `make php.lint.audit-resource`. Hoy **no asierta nada sobre `metadata`** (solo cuenta filas por `action` +
  `actor_id`), así que desde aceptación nada impide que `subject_user_id` desaparezca — eso es un hueco a
  cerrar, y el sitio natural del testigo de AC1.

## Decisión registrada (NO reabrir)

**DA-1 — TOMADA (Sergio, 2026-08-03): (A) el redactor**, con el control de AC3 resuelto como testigo de
aceptación falsable y no como gate de forma. La recomendación se emitió con los tres argumentos de abajo y fue
**confirmada**, que es lo que la épica exige (`:393-398`). Los tres argumentos, con su coste y su alternativa
descartada:

**1. Solo el redactor satisface AC1, porque AC1 es una propiedad del DATO.** «Ninguna fila conserva su id real
en `metadata`» habla de filas existentes. Hay una fila así, medida. Un gate no reescribe nada: dejaría AC1 sin
satisfacer el día que se mergea y lo cumpliría solo por vacuidad en instalaciones nuevas.

**2. El hermano ya resolvió el mismo fork por ADR, y a favor del redactor — y ningún documento de G-2 lo cita.**
[`docs/adr/event-store-and-projections.md`](../../docs/adr/event-store-and-projections.md) D12 (`:294-315`) fija
para `event_store` *un único `UPDATE` parametrizado… dentro de la transacción que `FulfilIdentityErasure` ya
posee*, con un argumento **genérico** contra la vía preventiva: *«Cualquier mecanismo preventivo —que el id
nunca llegue a escribirse— depende de la memoria de quien añada el próximo evento, y para ser fiable
necesitaría su propio gate: más maquinaria para una garantía más débil»*. Es la decisión de G-5, ya registrada
(`sprint-status.yaml:285-289`). Elegir el gate aquí y el redactor allí necesitaría un argumento que separe los
dos logs; no lo veo, y el ADR dice explícitamente *«igual que su hermano `audit_log`»*.

**3. El gate no puede discriminar por forma sin romper un contrato o leerse verde por construcción.** La misma
tabla lleva `metadata.anonymized_actor_id`, que el ADR declara *parte del contrato de ese evento*
([`docs/adr/audit-activity-log.md`](../../docs/adr/audit-activity-log.md) `:330-333`) y sobre el que construye
un cross-check de integridad (`:334-336`). Un pseudónimo es **un UUID indistinguible por forma de un id real**
(`:298-300`). Luego un gate que prohíba «UUIDs en `metadata`» rompe D4.1, y uno que prohíba **por nombre de
clave** es una declaración que se comprueba a sí misma — SI-23 exactamente. El gate solo es honesto como
**registro declarativo de claves de `metadata`** (clave → clasificación, humano clasifica / automatización
verifica), que es maquinaria de la talla de G-1a para una superficie de **una** clave: falla la Regla de Tres.

**Coste del redactor, que hay que pagar y declarar:**

- **Añade un statement sobre `audit_log`, y el ADR ya fijó la regla que eso dispara**
  (`audit-activity-log.md:235-239`): enrutar por `TransactionManager`, **nunca** una transacción DBAL cruda
  anidada bajo `wrapInTransaction` — no hay `nest_transactions_with_savepoints`, así que anidar degradaría a
  rollback-only. Ninguno de los tres documentos de planificación cita esta regla.
- **Amplía un «conjunto cerrado» declarado en dos sitios con dos números distintos**: dos políticas de mutación
  en `audit-activity-log.md:198-200`, tres en [`docs/architecture-api.md`](../../docs/architecture-api.md)
  `:264`. Ampliarlo es enmienda de ADR, no una línea de código.
- **Exige enmendar el contrato de `metadata` de D4** (`audit-activity-log.md:271-273`), que hoy admite *«IDs y
  discriminantes»* como contenido legítimo y por eso justifica no redactar. Bajo su letra, FR6 lo contradice y
  nadie ha declarado la enmienda.

**Trampa a evitar en la forma del redactor:** **no escribas el pseudónimo en esa fila.** La fila
`GDPR_SUBJECT_ERASED` comparte `correlation_id` con `GDPR_ERASURE_EXECUTED`, y poner ahí el pseudónimo
reconstruye justo el crosswalk que `FulfilIdentityErasure.php:143-146` rechazó por escrito. La forma que este
repo ya bendijo dos veces es **conteos, nunca ids** (`sprint-status.yaml:202` y `:263-264`); eliminar la clave
también satisface AC2, porque la fila sigue teniendo `action`, `correlation_id`, `occurred_on` y el `actor_id`
del admin que ejecutó el borrado.

**Por qué se descartó (B), el gate declarativo de claves.** Habría traído dependencia de #634 (el hueco para
columnas sin entidad tiene una sola plaza, ocupada por `audit_log.resource_id`: necesitaría un verbo nuevo o un
registro propio), habría activado NFR11, y **aun así habría que reparar la fila viva** — B no sustituye a A, se
le suma. A eso se añade que es maquinaria de la talla de G-1a para **una** clave, lo que incumple la Regla de
Tres, y que ninguna de sus dos formas posibles es honesta: por forma rompe D4.1, por nombre de clave se lee
verde por construcción (SI-23). Esa asimetría es el argumento más fuerte contra presentar el fork como
simétrico, y es lo que decidió DA-1.

**Condición de reapertura** (escrita para que la decisión sea revisable y no dogma): si aparece un **segundo**
camino de escritura que meta un id de persona en `metadata` —hoy hay exactamente uno—, la Regla de Tres deja de
proteger a (B) y el registro declarativo pasa a valer lo que cuesta. El trigger es observable: cualquier PR que
añada una clave nueva a `metadata` con un id de persona dentro.

### Contradicciones de la planificación que la decisión debe resolver

1. **`sprint-status.yaml:157-159` niega el fork que épica y addendum declaran.** Corregir la frase.
2. **Los invariantes de G-2 no coinciden entre documentos**: FR6 dice «SI-21, D4»; la historia dice «D4/NFR4,
   SI-21/NFR1»; la fila del addendum (`:36`) dice **solo SI-21** — omitiendo D4 en la historia que existe para
   convertir D4 en mecanismo.
3. **Tres DAG distintos** para el mismo arco: `arch-addendum:48,57` (G-2 espera a G-1a), `epics:241,247-248`
   (G-2 y G-3 paralelizables), `epics:714` (la dependencia es condicional al fork).
4. **Qué impide hoy el crosswalk: dos mecanismos incompatibles.** La épica dice «un comentario»; el ADR
   (`audit-activity-log.md:247-249`) dice que `GDPR_SUBJECT_ERASED` se libra porque **el auto-borrado está
   prohibido** — y eso no es prosa, es una guarda con 409 `self-erasure-forbidden` (`architecture-api.md:264`).
   La premisa nuclear de FR6 («D4 está sostenido por prosa») es más débil de lo que el corte afirma. **La fuga
   sigue siendo real** — el id crudo está en `metadata` con independencia de quién sea el actor — pero el
   encuadre del PR tiene que ser el medido, no el heredado.
5. **`epics:301` ya habla del «redactor de `metadata`» como sustrato de la épica**, como si el fork estuviera
   resuelto.
6. **`epics:361-362` dice «los tres huecos que el gate destapa»**: el gate de G-1a aterriza en rojo por **dos**
   referencias, y el eje `audit_log` está estructuralmente fuera de su universo. El hueco de G-2 **no es
   visible** para el mecanismo de G-1a.

## Acceptance Criteria

Los tres del corte, verbatim (`epics-gdpr-hardening.md:727-741`), con lo que los hace falsables:

1. **Given** un sujeto borrado, **When** se inspecciona `audit_log`, **Then** ninguna fila conserva su id real
   en `metadata` (FR6, NFR4).
   → El testigo **siembra su propio dato y afirma que la escritura afectó a 1 fila antes de asertar el
   veredicto** (trampa F5 de G-1a: la BD de test se migra pero no se provisiona, así que un `INSERT … SELECT`
   sobre tabla vacía inserta cero y el test pasa en falso).
2. **Given** ese mismo borrado, **When** se consulta la fila `GDPR_SUBJECT_ERASED`, **Then** sigue siendo
   evidencia útil de que el borrado ocurrió — la garantía no se compra destruyendo la evidencia.
   → Afirma explícitamente qué **sí** sobrevive (`action`, `correlation_id`, `occurred_on`, `actor_id`), no
   solo qué desaparece.
3. **Given** una escritura futura que vuelva a poner un id de persona en `metadata`, **When** corre la suite,
   **Then** un control **falla**; y si ese control es un gate, está en `php.quality` y en `php.quality.dry-run`
   (NFR11).
   → **AC3 no exige un gate**: exige un control que falle. El control se verifica **por mutación** — se le
   quita la regla, se cuenta el rojo, se restaura copiando los bytes (**nunca `git checkout --`**, que se
   llevaría ediciones sin commitear). Toda rama de la regla necesita su propio caso rojo (trampa F1 de G-3a:
   una regla con un solo llamador y solo `assertNull` resultó infalsable).

## Tasks / Subtasks

- [x] **Tarea 0 — Cerrar DA-1 por escrito.** HECHA: (A) el redactor, confirmada por Sergio el 2026-08-03 y
      registrada arriba con su alternativa descartada y su condición de reapertura. Reproducirla en el PR.
- [x] **Tarea 1 — Re-medir el estado contra el árbol del día.** En especial: si #634 mergeó y si la fila
      huérfana sigue existiendo. **No heredar las cifras de este artefacto** — son de `9dce7355`.
- [x] **Tarea 2 (AC1, AC2)** — Que la pasada de erasure deje de conservar el id real: eliminar o sustituir
      `subject_user_id` **sin introducir pseudónimo en esa fila**. Un solo statement, dentro de la transacción
      que `FulfilIdentityErasure` ya posee, enrutado por `TransactionManager` — nunca una transacción DBAL
      cruda anidada bajo `wrapInTransaction`, que degradaría a rollback-only.
- [x] **Tarea 3 (AC1)** — Testigo de aceptación en
      [`features/backoffice/users/erase.feature`](../../api/features/backoffice/users/erase.feature) que siembre
      y demuestre **en el mismo escenario** (trampa F7 de G-3a: siembra y aserción en escenarios distintos
      pasaban en verde) y que **afirme el conteo de filas sembradas** antes del veredicto (trampa F5). Ojo: ese
      fichero es el testigo declarado de `.audit-resource-types` — verificar que `make php.lint.audit-resource`
      sigue verde.
- [ ] **Tarea 4 (AC3)** — Control falsable sobre el camino de escritura, **verificado por mutación**: quitar la
      regla, contar los rojos, anotar el número, y restaurar **copiando los bytes** (nunca `git checkout --`).
      Toda rama de la regla necesita su propio caso rojo.
- [ ] **Tarea 5** — Reescribir `AuditActorAnonymiserFunctionalTest:76,196` (hoy afirma que `metadata` queda
      byte a byte intacta) **con la razón del cambio**, y actualizar `EraseIdentitySubjectTest:43`.
- [ ] **Tarea 6 — Enmiendas documentales, que aquí no son cosmética**:
      [`docs/adr/audit-activity-log.md`](../../docs/adr/audit-activity-log.md) (contrato de `metadata` en D4
      `:271-273`, y el conjunto cerrado de mutaciones `:198-200`),
      [`docs/architecture-api.md`](../../docs/architecture-api.md) `:264` (dos vs tres políticas), y el párrafo
      de [`api/.person-reference-policy`](../../api/.person-reference-policy) `:51-57` que declara este agujero.
- [ ] **Tarea 7** — Corregir las seis contradicciones de planificación listadas arriba (o declarar por escrito
      cuál se deja abierta y por qué).
- [ ] **Tarea 8 — Pase adversarial por alguien distinto del autor, registrado, declarando dónde quedó**
      (definición de hecho de la épica; `CLAUDE.md` → *Security review on every change* → *Process*). Un pase
      que no encuentra nada también cuenta: se registra y se dice.
- [ ] **Tarea 9** — Puertas con **ejecución fresca y exit code impreso** en el artefacto: `make php.stan`,
      `make php.quality`, y la suite que ejerza el camino tocado.

## Dev Notes

### Anti-patrones concretos de esta historia

- **No metas el pseudónimo en la fila `GDPR_SUBJECT_ERASED`.** Comparte `correlation_id` con
  `GDPR_ERASURE_EXECUTED`; sería el crosswalk que `FulfilIdentityErasure.php:143-146` evitó a propósito.
- **No borres la fila.** AC2 lo prohíbe explícitamente, y el ADR sostiene que el log solo admite `UPDATE`.
- **No toques `metadata->>'encryption_scope_id'`**: es join key de un reconciliador vivo.
- **No amplíes el alcance a `event_store`**: es G-5, con su propia decisión ya registrada en D12.
- **No supongas que `metadata` es siempre un objeto** — ver la trampa del `[]`.

### Trampas del repo que muerden aquí

- **Siembra vacua** (F5): la BD de tests se migra pero no se provisiona. Afirma el conteo de filas afectadas.
- **`--filter` por prefijo** sale verde con un subconjunto estricto: una línea por clase, con nombre exacto, y
  verifica la selección con `--list-tests`.
- **Restaurar tras falsificar**: copia los bytes; `git checkout --` se lleva ediciones sin commitear.
- **PHPMD sin baseline** solo lo pilla `make php.quality`, y el límite de acoplamiento ≤13 aplica también a
  `tests/`.
- **`bmad.status.audit` da falsos positivos** casando claves contra asuntos de commit: no cierres nada por lo
  que diga, mide contra el código.

### Revisión de seguridad (declarar en el PR lo que no aplica)

Esta historia **es** superficie GDPR/auditoría, así que el pase adversarial es obligatorio (C2). Aplica además:
sin PII en logs ni en mensajes de error del control (un control GDPR que loguea ids de persona rompe SI-21 por
el propio control que lo hace cumplir); SQL parametrizado; y `metadata` se expone por API en el detalle
(`AuditEventDetailResource.php:33`), así que cualquier cambio de forma es cambio de contrato de lectura.

### Project Structure Notes

`Shared/Audit` **no aprende semántica ajena** (NFR7): posee la forma `(type, id)`, no el vocabulario. Quien sabe
que `subject_user_id` denota una persona es `Iam/Identity`, que es donde vive el escritor — lo cual favorece
que el redactor viva ahí y no en `Shared/Audit`.

### References

- [`_bmad-output/planning-artifacts/epics-gdpr-hardening.md`](../planning-artifacts/epics-gdpr-hardening.md) — FR6 `:127-132`, Story 1.4 `:706-741`, precondición `:393-398`, NFR11 `:233-235`, NFR7 `:215-218`
- [`_bmad-output/planning-artifacts/arch-addendum-gdpr-hardening.md`](../planning-artifacts/arch-addendum-gdpr-hardening.md) — SI-21 `:14`, SI-22 `:15`, SI-23 `:16`, fila G-2 `:36`
- [`docs/adr/audit-activity-log.md`](../../docs/adr/audit-activity-log.md) — D4 `:198-200`, `:235-249`, `:271-273`; D4.1 `:330-336`, `:362-364`
- [`docs/adr/event-store-and-projections.md`](../../docs/adr/event-store-and-projections.md) — D12 `:294-315`, `:326-327`
- [`docs/adr/regulatory-audit-trail.md`](../../docs/adr/regulatory-audit-trail.md) — D15 `:104-108`
- [`docs/architecture-api.md`](../../docs/architecture-api.md) — políticas de mutación y guarda de auto-borrado `:264`
- [`api/.person-reference-policy`](../../api/.person-reference-policy) — agujero declarado `:51-57`
- [`g-3a-segundo-testigo-registro-audit-resource-types.md`](g-3a-segundo-testigo-registro-audit-resource-types.md) — patrón del testigo y sus trampas
- [`g-1c-control-detective-referencias-cross-context.md`](g-1c-control-detective-referencias-cross-context.md) — patrón del control detective y la plaza única para columnas sin entidad

## Dev Agent Record

### Agent Model Used

claude-opus-5[1m]

### La forma de (A) cambió al medir: A2, el sujeto como RECURSO

**Decidido por Sergio (2026-08-03)** tras medir la cadena, y es distinto de lo que la Tarea 2 decía al escribirse.
`AuditLogger::log()` admite un **recurso** como tercer argumento, `EraseIdentitySubject` pasaba `null`, y su
**único llamador** es `FulfilIdentityErasure` (el CLI inyecta la cadena, no el caso de uso), que en `:120-123`
ya corre `DbalAuditResourceAnonymiser` con `AuditResource::of('User', $subjectId)` y el mismo pseudónimo. El
nivel `SECURITY` escribe **síncrono**, así que el INSERT es visible al UPDATE en la misma transacción.

Por tanto: la fila se escribe con el sujeto como recurso y **conteos** en `metadata`, y la maquinaria existente
la anonimiza sola. **Sin statement nuevo, sin política de mutación nueva, sin redactor que testear** — con lo
que el «conjunto cerrado» del ADR no se amplía y la regla del segundo statement (`TransactionManager`) no se
dispara. La Tarea 6 encoge a la retirada del bullet del registro y a la nota de revisita de D4.

### Dos predicciones de este artefacto que la medición REFUTÓ

1. **`AuditActorAnonymiserFunctionalTest` NO se rompe.** El artefacto decía que un redactor lo rompería «por
   diseño»; cierto del redactor que A2 no construye. A2 no toca el anonimizador, así que la aserción de
   `metadata` byte-a-byte intacta sigue siendo verdad y sigue verde.
2. **El tripwire de G-3a NO se disparó.** `PersonResourceErasureGateTest::theStalenessOfAPersonTypeIs…`
   promete ponerse rojo «el día que aparezca un escritor de producción del tipo». Ese día es hoy y siguió
   verde, porque el escritor referencia `FulfilIdentityErasure::SUBJECT_RESOURCE_TYPE` en vez del literal
   `'User'` — y el check cuenta ficheros que llevan el **literal**. Se eligió la constante por DRY (una sola
   fuente del vocabulario), no para esquivar el gate; **el comentario del tripwire queda falso en su premisa y
   hay que corregirlo** (pendiente, Tarea 5). Dejarlo pasar en silencio sería exactamente el modo de fallo que
   G-3a documentó.

### Falsificación ejecutada (evidencia de AC3, parcial)

- **M1 — devolver `subject_user_id` a `metadata`** (conservando el recurso): `make php.behat
  c='features/backoffice/users/erase.feature'` → **exit 2**, rojo. El testigo muerde.
- **M2 — pasar `null` como recurso** (conservando metadata de conteos): **PENDIENTE**.
- Restaurado copiando los bytes desde `tmp/g2-falsify/`, verificado por `md5sum` idéntico y `grep -c
  subject_user_id` = 0. Nunca `git checkout --`.

### Estado: PARCIAL — verde hasta donde llega

Tareas 2 y 3 hechas y verdes. **`main` se movió a `93befb7c` mientras se trabajaba** (#634/G-1c mergeó), así
que: (a) el `Estado medido` de este artefacto, fijado a `9dce7355`, hay que re-verificarlo; (b) la rama necesita
rebase; (c) el bullet de `.person-reference-policy` que G-2 debe retirar sigue en `main` (línea 61, «standing
leaks»), y G-3b edita **el mismo bloque de puntos ciegos** — esperar conflicto trivial ahí.

Puertas corridas (frescas, exit code impreso):

| Puerta | Resultado |
|---|---|
| `make php.unit c='--filter EraseIdentitySubjectTest'` | 4 tests, 14 aserciones, **exit 0** |
| `make php.unit` (suite completa, 2147 tests) | **exit 0** |
| `make php.behat c='features/backoffice/users/erase.feature'` | 12 escenarios, 99 pasos, **exit 0** |
| `make php.stan`, `make php.quality` | **PENDIENTES** |

### Completion Notes List

- Pendiente: M2, y las Tareas 4 a 9 (control falsable con todos sus rojos, corrección del comentario del
  tripwire, enmiendas documentales, contradicciones de planificación, pase adversarial, puertas finales).

### File List

- `api/src/Iam/Identity/Application/EraseIdentitySubject.php` (modificado)
- `api/tests/Unit/Iam/Identity/Application/EraseIdentitySubjectTest.php` (modificado)
- `api/features/backoffice/users/erase.feature` (modificado)
