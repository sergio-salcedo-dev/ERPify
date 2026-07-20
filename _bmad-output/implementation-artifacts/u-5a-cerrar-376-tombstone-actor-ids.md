---
baseline_commit: 01ce0fabab9c90ac5f6916f59a146ac38a209816
---

# Story 1.6 (U-5a): Cerrar #376 — eliminar la ventana de resurrección async del `actor_id`

Status: review

> **El título del épico dice «tombstone de `actor_id`s». Esta historia NO construye un tombstone.**
> La decisión se cerró tras consultar arquitecto, dev y un AI externo, y con medición en vivo: se elimina
> la cola de `activity` en vez de compensarla. El razonamiento completo está en *Decisión D1*. Si vienes del
> épico esperando una tabla nueva, lee D1 antes de nada.

## Story

Como **plataforma**,
quiero que un `actor_id` anonimizado no pueda ser re-insertado por una escritura de auditoría en vuelo,
para que el borrado GDPR sea completo y no se «resucite» un sujeto ya erasado.

## Contexto (leer antes de tocar código)

U-5a de la épica `users-admin` (orden safe-first `U-0 → U-1 → (U-2 · U-3) → U-4 · [U-5a → U-5b]`). U-0…U-4 están
**done/merged** (PRs #501–#509). U-5a no toca `Iam/Identity` ni la PWA: es cross-cutting en `Shared/Audit` y **bloquea
duro a U-5b** (borrado GDPR en consola), porque exponer el erase a admins eleva la frecuencia del disparo.

**El bug (issue #376).** Las entradas de auditoría nivel `activity` se escriben async: `SealedAuditEntryFactory` sella
`actor_id`/`ip`/`user_agent` en el ciclo de request, `SymfonyAuditLogger::dispatchActivity` las encola como
`RecordAuditEntry` en el transporte `audit`, y `RecordAuditEntryHandler` las drena después. El borrado GDPR
(`DbalAuditActorAnonymiser::anonymise()`) es un `UPDATE` puntual que solo reescribe filas **ya existentes**. Una entrada
encolada *antes* del borrado y consumida *después* re-inserta la PII original con `actor_erased = FALSE`.
`ON CONFLICT (id) DO NOTHING` no protege: solo cubre redelivery de la **misma** fila.

**Los tres niveles y su asimetría.** `security` es write-before-send **síncrono** (fallo propagado). `change` lo captura
`AuditWriteCaptureListener` en `onFlush`, **dentro de la transacción de negocio**. Solo `activity` encola. Es decir:
**dos de los tres niveles ya escriben en el request**, y solo el tercero —el único best-effort— paga una cola.

**Por qué esta historia borra código en vez de añadirlo.** El transporte es `doctrine://default`: la cola `audit` **es
una tabla Postgres** (`messenger_messages`) en la misma base y la misma `Connection` que `audit_log`. La captura ocurre
en `kernel.terminate`, **después de enviar la respuesta**. Y el transporte `failed` es global —`audit` no lo
sobreescribe—, así que un `RecordAuditEntry` que agote reintentos queda en `messenger_messages` con `ip`/`user_agent`
serializados **para siempre**, replayable por un operador semanas después, fuera de toda política de D4.

Consecuencia: hoy existen **dos copias durables de PII regulada** (`audit_log` y `messenger_messages`), y D4 gobierna
solo una. #376 es el síntoma; la duplicación es la enfermedad. Quitar la cola elimina las dos.

## Acceptance Criteria

**AC1 — `activity` se escribe en el request, sin cola.**
**Given** una interacción `/api/*` auditable,
**When** corre `AccessLogAuditListener` en `kernel.terminate`,
**Then** la entrada se escribe directamente vía `AuditLogWriter`, **sin** despachar ningún mensaje, y el transporte
`audit` queda sin tráfico.

**AC2 — El SLA de D3 sobrevive intacto.**
**Given** un fallo al escribir una entrada `activity`,
**Then** se **traga y se loguea** como `warning` sin filtrar contexto (mismo comportamiento que hoy) — y un fallo al
escribir una entrada `security` **sigue propagándose**. La asimetría de durabilidad por nivel es contrato, y no cambia.

**AC3 — No queda camino async que pueda resucitar PII.**
**Given** el subsistema tras el cambio,
**Then** `RecordAuditEntry`, `RecordAuditEntryHandler`, el transporte `audit` y su routing **ya no existen**; ninguna
entrada de auditoría viaja por Messenger ni puede aterrizar en `failed`.

**AC4 — El backlog existente se resuelve, no se hereda.**
**Given** mensajes `RecordAuditEntry` encolados o en `failed` en el momento del despliegue,
**When** se aplica el procedimiento de la tarea D,
**Then** ninguno queda a la espera de ser reinyectado. La política elegida (descartar) queda escrita, no asumida.

**AC5 — La ventana residual se documenta con honestidad.**
**Given** una petición que empieza antes del `UPDATE` de anonimización y termina después,
**Then** puede escribir el `actor_id` original — **exactamente la misma carrera de duración-de-request que `security` y
`change` ya tienen hoy** y que D4 tolera. El ADR lo dice explícitamente; no se afirma que la ventana sea cero.

**AC6 — Sin regresión.**
**Given** la suite existente,
**Then** siguen verdes la idempotencia del writer, el round-trip de columnas, la poda por retención, el reconciler de
crypto-shredding, y las features de auditoría en Behat (reescritas donde asertaban el paso por la cola).

**AC7 — Los ADR quedan actualizados.**
**Given** que #376 cierra,
**Then** `docs/adr/audit-activity-log.md` lleva una **enmienda explícita a D3** (mecanismo de entrega), **D4 y D4.1
quedan intactos**, y se registra el trigger de revisita. `PRODUCTION_SECURITY_CHECKLIST.md` refleja que la auditoría ya
no deja PII en el transporte.

**AC8 — Gates verdes.** `make php.quality` (incl. deptrac), `make php.unit`, `make php.behat`.

## Tasks / Subtasks

### A — Enmienda de ADR (AC5, AC7) · primero, porque fija el contrato que el código implementa

- [ ] A1. Enmendar **D3** en `docs/adr/audit-activity-log.md`, etiquetada como enmienda (precedente de forma:
      `regulatory-audit-trail.md` D10 enmienda D9, D11 enmienda D6). Contenido: la justificación original («libera el
      request path de IO de auditoría», «latencia p95») **no se sostiene** — la captura es post-respuesta y el `dispatch`
      es un `INSERT` en la misma base. **Incluir las cifras medidas** (§ *Medición* abajo), no adjetivos.
- [ ] A2. **D4 y D4.1 NO se tocan.** Si te ves editándolos, has tomado el camino equivocado — vuelve a D1.
- [ ] A3. Documentar la **ventana residual** de duración-de-request (AC5) y que es la que `security`/`change` ya tienen.
- [ ] A4. Registrar el **trigger de revisita**: si `MESSENGER_TRANSPORT_DSN` migra a un broker real (AMQP/Redis/SQS), el
      cálculo se invierte —el `dispatch` pasa a ser más barato que un write a BD— y la pregunta del tombstone vuelve.
- [ ] A5. `PRODUCTION_SECURITY_CHECKLIST.md`: la auditoría deja de escribir PII en `messenger_messages`.

### B — `activity` pasa a síncrona · `Shared/Audit/Infrastructure/SymfonyAuditLogger.php` (AC1, AC2)

- [ ] B1. `dispatchActivity()` → `writeActivity()`: **mismo `try`/`catch(Throwable)` y mismo `warning` verbatim**,
      cambiando `$this->messageBus->dispatch(new RecordAuditEntry($entry))` por `$this->writer->write($entry)`.
      El `catch` es el contrato de D3 (AC2) — **no lo simplifiques ni lo unifiques con `writeSecurity()`**, que
      deliberadamente **no** traga.
- [ ] B2. Retirar `MessageBusInterface` del constructor (queda sin uso en esta clase).
- [ ] B3. Verificar que el `match` sobre `AuditLevel` sigue rechazando `CHANGE` con `LogicException` — ese nivel lo
      captura el listener `onFlush`, no el logger.

### C — Borrar el camino async muerto (AC3)

- [ ] C1. Borrar `Shared/Audit/Application/RecordAuditEntry.php`.
- [ ] C2. Borrar `Shared/Audit/Infrastructure/Messenger/RecordAuditEntryHandler.php` (el directorio `Messenger/` conserva
      `Maintenance/`; **no lo borres entero**).
- [ ] C3. `api/config/packages/messenger.yaml`: quitar el transporte `audit`, su línea de `routing`, y su entrada en el
      bloque `when@test`. **Deja intactos** `async`, `failed` y `sync` — los usan los eventos de dominio.
- [ ] C4. `git grep -n "RecordAuditEntry\|'audit'\|\"audit\"" api/` y limpiar referencias residuales (config, tests,
      features, docs).
- [ ] C5. Comprobar que ningún otro consumidor dependía del mensaje: es interno, un solo handler, nunca un `DomainEvent`,
      nunca en el catálogo de eventos, nunca consumido por otro bounded context. **Verificado — reconfírmalo con grep.**

### D — Resolver el backlog existente (AC4)

- [ ] D1t. **Política: descartar, no migrar.** Amparo: D3 declara `activity` **best-effort y acepta pérdida de
      registros** — descartar un backlog en vuelo está dentro del contrato. Escríbelo en la enmienda (A1); no lo asumas.
- [ ] D2. Procedimiento de despliegue: parar workers → borrar de `messenger_messages` las filas con `queue_name = 'audit'`
      **y** las de `queue_name = 'failed'` cuyo header `type` sea `RecordAuditEntry` → desplegar. Documentarlo donde vive
      el runbook de despliegue.
- [ ] D3t. **Acotar el `DELETE` por tipo, no por cola**: `failed` es **compartido** con los eventos de dominio. Un
      `DELETE FROM messenger_messages WHERE queue_name = 'failed'` a secas destruiría eventos de negocio pendientes.
- [ ] D4t. Local: comprobar el estado antes y después (`SELECT queue_name, count(*) FROM messenger_messages GROUP BY 1`).

### E — Tests (AC1–AC6)

- [ ] E1. `SymfonyAuditLoggerTest`: **invierte** `testActivityRoutesTheSealedEntryToTheBusWithoutWriting` →
      «escribe sin despachar». **Debe seguir verde** `testAnActivityFailureIsSwallowedAndLoggedWithoutLeakingContext`
      (AC2) — es el test que protege el contrato de D3; si lo tocas, justifícalo.
      `testASecurityFailurePropagatesAndIsNotSwallowed` **no se toca**.
- [ ] E2. `SymfonyAuditLoggerBranchingTest` (functional): invierte
      `testActivityRoutesToTheAuditTransportAndWritesNoRowSynchronously` → escribe la fila en el acto, transporte vacío.
- [ ] E3. Borrar `RecordAuditEntryHandlerTest` y `RecordAuditEntryTest` con sus clases.
- [ ] E4. **Behat — la mayor superficie de cambio.** `api/features/shared/audit/access_log.feature`,
      `backoffice/audit/self_audit.feature` y `backoffice/bank_account/audit.feature` asertan hoy la coreografía
      *hold → consume → SQL*. Quitar los pasos `the "audit" transport should hold N message` y
      `I consume N message from the "audit" transport`; el `SELECT` sobre `audit_log` pasa a correr **directamente** tras
      la petición. Los asserts sobre el contenido de la fila **no cambian**.
- [ ] E5. **Presupuestos de query.** `DoctrineContext` cuenta sentencias con `assertEquals` exacto y hay ~140 asserts
      vivos. Cambio esperado: **neutro** (1 `INSERT` en `messenger_messages` → 1 `INSERT` en `audit_log`). **Mídelo, no
      lo asumas** — `I dump the number of executed queries`. Si el `dispatch` de Messenger emitía alguna sentencia extra,
      el delta aparecerá aquí.
- [ ] E6. **Test de regresión de #376:** con `activity` síncrona, escribe → anonimiza → escribe otra vez con el id
      original → asserta que la segunda fila no reintroduce PII de la primera anonimización. Contra Postgres real.

### Verificaciones (Working principle 4)

- [ ] `make php.stan` en cada fichero PHP tocado (`PHP_SERVICE=messenger_worker` si segfaultea con 139).
- [ ] `make php.quality` completo al final (PHPMD/cs-fixer/rector/deptrac **solo** salen aquí; CI corre
      `php.quality.dry-run`, que no arregla — repasa `git diff` tras el sweep por si los fixers te reescribieron algo).
- [ ] `make php.unit`, `make php.behat`.
- [ ] `make composer.check.all` — al borrar el único `dispatch` de `Shared/Audit`, comprobar que composer-unused no
      declare `symfony/messenger` huérfano (**no debería**: los eventos de dominio lo siguen usando).

## Dev Notes

### Decisión D1 (CERRADA por Sergio) — se elimina la cola, no se compensa

**Consultados:** Winston (arquitecto), Amelia (dev), un AI externo general, y una **medición en vivo**. Las cuatro
opciones descartadas, con el motivo real de cada una:

| Opción | Por qué NO |
|---|---|
| **A — tabla de mapeo `(huella → pseudónimo)`** | Una huella de `actor_id` **no es de un solo sentido**: los ids se sortean del conjunto **enumerable** de usuarios, así que con la tabla `users` o un backup se hashea cada candidato y se recupera el mapa. Es un **oráculo de re-identificación sin llave sobre la traza completa** — estrictamente más débil que el HMAC→UUID que D4 **ya examinó y descartó**. Y su TTL es ficticio: por el sumidero `failed`, tendría que vivir indefinidamente |
| **B — tombstone puro `(huella, erased_at)`** | Rompe un invariante **vigente y testeado**: `anonymise()` acuña **un solo** pseudónimo por sujeto y `AuditActorAnonymiserFunctionalTest` asserta que todas sus filas lo comparten («trail stays correlated»). Cada fila tardía recibiría uno distinto. Un marcador de procedencia repara el checker de integridad **pero no la regresión semántica**: pasarías a tener dos clases de fila erasada con garantías de correlación distintas — un modelo de privacidad nuevo, no metadata |
| **C — drenar antes de borrar** | **Incorrecta**, no solo débil: no puedes drenar lo que un operador reinyectará desde `failed` tres semanas después. Reintentos, worker parado y mensaje ya *fetched* sin commitear la defienden |
| **E — barrido periódico** | Convierte un **invariante** en **convergencia eventual**, y entonces la frecuencia de un cron define en silencio la semántica de privacidad. Si se quiere anonimización eventual, que sea un invariante explícito, no un efecto colateral operativo |

**Qué se enmienda y por qué.** La regla operativa: *se enmienda la decisión cuyo invariante declarado resultó falso, no
aquella cuyo invariante expuso el fallo.* D4 no ha afirmado nada falso — su modelo de privacidad sigue siendo el
correcto. D3 afirmó «libera el request path de IO de auditoría»; los hechos lo falsan. **Enmienda D3, D4 intacto.**
Enmendar D4 para acomodar una consecuencia de D3 sería tratar el síntoma en el órgano equivocado — y como D4 *ya*
descartó por escrito la pseudonimización con clave, cualquier variante de A exigiría **editar el texto para que dijera
lo contrario**: eso es erosión, no enmienda.

### Medición (ejecutada 2026-07-20, Postgres de dev, 2000 iteraciones interleaved, en transacción con `ROLLBACK`)

```
COSTE IN-REQUEST      ASYNC hoy  INSERT messenger_messages   p50 27.2 µs   mean 34.3 µs
                      SYNC con D INSERT audit_log            p50 43.7 µs   mean 59.3 µs
COSTE DE WORKER       SELECT … FOR UPDATE SKIP LOCKED        mean 56.5 µs
 (solo la vía async)  DELETE messenger_messages              mean 38.8 µs
TRABAJO TOTAL DE BD   async 189.0 µs   ·   sync 59.3 µs   →  async hace 3.19x
```

**Lee esto bien, porque es contraintuitivo:** el `INSERT` en `audit_log` cuesta **1,7x** el de `messenger_messages`
(6 índices, cuatro `CAST(… AS UUID)`, parse de `JSONB`, contra 2 índices y columnas `text`). **La cola sí ahorra
trabajo in-request** — unos 25 µs. Lo que pasa es que:

- esos 25 µs son **post-respuesta** (`kernel.terminate`), así que el cliente no los ve nunca; solo afectan a la
  ocupación del worker: **~0,35%** sobre un suelo medido de 7,22 ms por petición;
- son un **techo**, porque la serialización PHP del envelope —que la vía async paga **además**, in-request— no se midió;
- y a cambio la vía async hace **3,2x el trabajo total de BD**, sobre el recurso compartido.

**Corolario para quien argumente «pero con mucho tráfico la cola compensa»: es al revés.** A más tráfico, más pesa el
3,2x sobre la BD compartida frente a 25 µs de ocupación por worker. El tráfico alto argumenta **a favor** de D.

Si repites la medición: el denominador de 7,22 ms salió de respuestas **401** (el login falló en la BD de dev), así que
es un **suelo** — una petición autenticada real es más lenta y hace los 25 µs relativamente menores.

### Medición BAJO CARGA (pgbench 18, tablas scratch DDL-idéntico, transacciones reales con commit/fsync)

El microbenchmark de una conexión medía el coste de la sentencia; este mide el request path bajo clientes concurrentes,
que es lo que decide si `activity` síncrona degrada nada. `async` = `INSERT messenger_messages`; `sync` = `INSERT
audit_log`.

```
                        c=1        c=16 (3 reps)              c=32       c=64
async_dispatch tps      593        4702 / 5259 / 4266         8219       13305
sync_audit     tps      629        6469 / 4267 / 4120         7365       14084
latencia avg (ms)       ~1.6       ambas 2.5–3.9 (solapadas)  ~4.1       ~4.7
```

**Los dos caminos son indistinguibles.** A c=16, sync gana una repetición, async otra, empate la tercera — puro ruido
de la BD de dev compartida. Ambos sostienen **600–14.000 tps**, órdenes de magnitud sobre cualquier ritmo real de
auditoría (suelo de la app ~7 ms/petición).

**Y esto cierra la reserva:** el **1,7x** del microbenchmark **se desvanece bajo carga** — con transacciones completas
el coste de commit/fsync domina y es **idéntico** en ambos caminos, así que la diferencia por-fila de índice queda
enterrada. Conclusión doble: la justificación de rendimiento de D3 no se sostiene **ni** post-terminate (0,35%) **ni**
bajo carga (throughput/latencia indistinguibles). Enmendar D3 está respaldado por datos, no por lectura de código.

*Limitaciones: BD de dev compartida con los demás contenedores (de ahí la varianza), no un dataset de prod; scratch
tables, no las reales (idénticas en DDL/índices/JSONB). Repetible: `tmp/bench-376/` — o su reconstrucción, ya borrado.*

### Crux 1 — D no lleva la ventana a cero, y decir lo contrario te lo tumban en review

Con `activity` síncrona, una petición que **empieza antes** del `UPDATE` de anonimización y **termina después** aún
escribe el `actor_id` original. La ventana pasa de *ilimitada* (drenado de cola + `failed` + replay semanas después) a
*la duración de un request*.

Eso no debilita D: **`security` y `change` ya tienen exactamente esa misma carrera** (`writeSecurity` es síncrono en el
ciclo de request; si el `UPDATE` commitea a mitad, la fila posterior lleva el id original), y D4 convive con ella desde
el día uno. D **no elimina la carrera: iguala `activity` a los otros dos niveles y deja una ventana residual que el ADR
ya tolera.** Escríbelo así en A3.

### Crux 2 — el `catch` de `activity` es contrato, no descuido

`dispatchActivity()` traga cualquier `Throwable` y loguea un `warning` **sin contexto** (ni actor, ni recurso, ni
metadata: son datos tainted). `writeSecurity()` **no** traga. Esa asimetría **es** el SLA por nivel de D3 y es lo único
de D3 que la enmienda **no** toca. Al hacer `activity` síncrona la tentación de unificar ambos métodos es fuerte:
**resístela** — unificarlos convertiría un fallo de auditoría de acceso en un 500 para el usuario.

### Crux 3 — el borrado de código es el entregable, no un efecto colateral

Esta historia tiene **diff neto negativo**: una línea cambiada en `SymfonyAuditLogger`, dos clases borradas, tres
bloques de config fuera, y sus tests. Si te encuentras añadiendo tablas, puertos o adapters, te has salido de D1.

### Decisión D2 — el reconciler es guarda de regresión, no alcance nuevo

El AC del épico («el reconciler existente no encuentra discrepancias tras un erase») pasa **hoy, sin cambios**, porque
`SubjectErasureReconciler` mira **DEKs destruidas ⟺ `GDPR_SUBJECT_ERASED`** (crypto-shredding), y `audit:gdpr:erase`
(actor) no destruye ninguna DEK y emite `GDPR_ERASURE_EXECUTED`. `regulatory-audit-trail.md` **D15** fija que
erase-actor y erase-subject *«son operaciones legales distintas y nunca se fusionan»*. Se cumple **afirmando que el
cambio no lo perturba**; extenderlo al cross-check de D4.1 es historia propia (issue de seguimiento).

### Decisiones ya tomadas — no re-abrir

| # | Decisión | Argumento |
|---|---|---|
| D3a | Se enmienda **D3**; D4/D4.1 intactos | La decisión cuyo invariante declarado resultó falso es D3 |
| D4a | `activity` síncrona con **el mismo `catch`** | El SLA best-effort de D3 es ortogonal a sync/async y se preserva verbatim |
| D5a | Se **borran** `RecordAuditEntry` + handler + transporte | YAGNI; git lo preserva; el trigger de revisita queda en el ADR |
| D6a | Backlog existente: **descartar** | D3 declara `activity` best-effort y acepta pérdida de registros |
| D7a | `failed` se limpia **por tipo de mensaje**, nunca por cola | `failed` es compartido con los eventos de dominio |
| D8a | Ventana residual documentada, **no** declarada cero | Es la misma que `security`/`change` ya tienen |

### Ficheros a tocar (verificado)

| Fichero | Acción |
|---|---|
| `api/src/Shared/Audit/Infrastructure/SymfonyAuditLogger.php` | `dispatchActivity` → `writeActivity`; fuera `MessageBusInterface` |
| `api/src/Shared/Audit/Application/RecordAuditEntry.php` | **BORRAR** |
| `api/src/Shared/Audit/Infrastructure/Messenger/RecordAuditEntryHandler.php` | **BORRAR** (conservar `Messenger/Maintenance/`) |
| `api/config/packages/messenger.yaml` | fuera transporte `audit` + routing + entrada `when@test` |
| `api/tests/Unit/Shared/Audit/Infrastructure/SymfonyAuditLoggerTest.php` | invertir el test de `activity`; **conservar** los dos de fallo |
| `api/tests/Functional/Shared/Audit/SymfonyAuditLoggerBranchingTest.php` | invertir |
| `api/tests/Unit/Shared/Audit/Infrastructure/Messenger/RecordAuditEntryHandlerTest.php` | **BORRAR** |
| `api/tests/Unit/Shared/Audit/Application/RecordAuditEntryTest.php` | **BORRAR** |
| `api/features/shared/audit/access_log.feature` | quitar hold/consume; el `SELECT` corre directo |
| `api/features/backoffice/audit/self_audit.feature` | ídem |
| `api/features/backoffice/bank_account/audit.feature` | ídem |
| `docs/adr/audit-activity-log.md` | **enmienda a D3** (+ ventana residual + trigger de revisita) |
| `PRODUCTION_SECURITY_CHECKLIST.md` | la auditoría ya no deja PII en el transporte |
| runbook de despliegue | procedimiento de purga del backlog |

**Sin migraciones. Sin tablas nuevas. Sin cambios en la PWA. Sin endpoints nuevos.**

### Testing (patrones del repo)

- **Dobles existentes** en `api/tests/Unit/Shared/Audit/Infrastructure/Double/`: `InMemoryAuditLogWriter` (indexa por
  `$entry->id`, espeja `ON CONFLICT`), `FixedAuditEntryFactory` (sella `ActorContext`/`correlationId`/`occurredOn` sin
  RequestStack ni Clock), `RecordingAuditLogger`, `FailingAuditLogger` (para la rama de fallo tragado),
  `RecordingAuditActorAnonymiser`. **Con D bastan tal cual** — no hacen falta dobles nuevos.
- **Cobertura:** `#[CoversClass(...)]`, **nunca** `#[CoversNothing]`; Behat no alimenta clover, los functional sí.
- **Behat:** `SqlQueryContext` (`I execute the SQL query :query`, `the SQL result as JSON should be:`),
  `SecurityContext` (`I am logged in as a viewer`), `SymfonyCommandContext`, `LoggerContext`
  (`the logger logged a log entry as :level with message :message` — útil para AC2), `DoctrineContext` (presupuestos).

### Gotchas heredados (verificados)

- **`make php.behat` resetea la DB** → re-siembra el ADMIN e2e **después** si lo necesitas.
- **Presupuesto de queries Behat**: `assertEquals` exacto, ~140 asserts vivos. Mídelo en vivo, no lo adivines.
- **Inline SQL step de Behat: sin comillas dobles embebidas** (el matcher trunca); el `"""` se alinea con el keyword del
  step (4 espacios) o el sweep de gherkinlint aborta.
- **`php.stan` puede segfaultear** en el worker web (139) → `PHP_SERVICE=messenger_worker`.
- **Rector:** `/** @phpstan-var T */` (no `@var` sin nombre) sobre `return` en tests; importa el FQCN en closures de
  `array_map` (>120 chars rompe PHPCS). Cuidado con `CatchExceptionNameMatchingType`, que renombra `catch ($x)` y
  dispara `LongVariable` de PHPMD — usa `expectException` donde puedas (relevante en E1, que toca ramas de `catch`).
- **PHPMD no tiene baseline** y `CouplingBetweenObjects` (≤13) **también aplica a los tests**.
- **Cluster de flakes conocido** en tests de banks/realtime de la PWA: si algo rojo aparece ahí, **no** es de este diff
  (esta historia no toca la PWA). Nunca culpes a tu diff con una sola muestra.
- **`api/config/reference.php`** se regenera al ejecutar comandos de consola; es auto-generado, se commitea tal cual.

### Fuera de alcance (frontera explícita)

- **La superficie de consola del borrado GDPR** — es U-5b (`FulfilIdentityErasure`, `#[IsGranted('users.erase')]`,
  type-to-confirm, guard ≥1 ADMIN).
- **Encadenar `identity:gdpr:erase-subject` con `audit:gdpr:erase`** — hoy son dos comandos **independientes y no
  coordinados**; encadenarlos es el `FulfilIdentityErasure` de U-5b.
- **El sumidero `failed` como problema general** — esta historia saca de ahí la PII **de auditoría**. Que `failed` no
  tenga retención ni gobierno para **otros** tipos de mensaje es un gap vivo e independiente → **issue propio**.
- **Envolver `anonymise` + self-audit en un caso de uso transaccional** — D4 lo tiene como trigger de revisita y **U-5b
  lo dispara** (segundo disparador: endpoint HTTP). Va en la tarjeta de U-5b, no aquí.
- **Extender el reconciler al cross-check de D4.1** — D2; issue de seguimiento.
- **Migrar el transporte a un broker real** — invertiría el cálculo; queda como trigger de revisita en el ADR.

### Project Structure Notes

Todo cae en `Shared/Audit/Infrastructure/` + config. Ninguna capa nueva, ningún puerto nuevo, **ningún allowlist de
bounded-context** (`Shared/` es siempre importable). Deptrac no debería moverse: se **eliminan** dependencias hacia
`Symfony\Messenger` desde `Shared/Audit`, nunca se añaden.

### References

- [Source: `_bmad-output/planning-artifacts/epics-users-admin.md#Story 1.6 (U-5a)`] — AC del épico; FR9; NFR10.
  **Nota:** el épico pre-decidió «tombstone»; D1 lo revisa con datos y consulta. Jerarquía BMAD: `epics.md` manda, así
  que esta divergencia queda cerrada por Sergio y **documentada en el PR**.
- [Source: `_bmad-output/planning-artifacts/arch-addendum-users-admin.md`] — SI-19 (erase des-identifica identidad +
  rastro como unidad); fila U-5 (prerrequisito duro); DAG safe-first.
- [Source: `docs/adr/audit-activity-log.md#D3`] — SLA por nivel; justificación de la cola (la que se enmienda).
- [Source: `docs/adr/audit-activity-log.md#D4`] — `UPDATE` nunca `DELETE`; «sin valor original, sin tabla de mapeo, sin
  derivación determinista»; HMAC descartado; **intacto**.
- [Source: `docs/adr/audit-activity-log.md#D4.1`] — `actor_erased`; invariante protegido por test; cross-check con
  `GDPR_ERASURE_EXECUTED`; **intacto**.
- [Source: `docs/adr/regulatory-audit-trail.md#D15`] — erase-actor ≠ erase-subject; su línea 134 referencia #376.
- [Source: `docs/adr/regulatory-audit-trail.md#D10,#D11`] — **forma de una enmienda sana** a una decisión aceptada.
- [Source: `api/src/Shared/Audit/Infrastructure/SymfonyAuditLogger.php`] — `match` por nivel; asimetría de fallo.
- [Source: `api/src/Shared/Audit/Infrastructure/Http/EventListener/AccessLogAuditListener.php`] — `kernel.terminate`,
  «adds zero latency»; productor dominante de `activity`.
- [Source: `api/config/packages/messenger.yaml`] — `doctrine://default`; `failure_transport: failed` global; el comentario
  del routing **ya reconoce** que `failed` sería un sumidero de tokens en claro para los emails de seguridad.
- [Source: `api/src/Shared/Audit/Infrastructure/Persistence/DbalAuditActorAnonymiser.php`] — `UPDATE` + pseudónimo único
  por sujeto (el invariante que mata la opción B).
- [Source: `api/tests/Functional/Shared/Audit/AuditActorAnonymiserFunctionalTest.php`] — «trail stays correlated».

## Dev Agent Record

### Agent Model Used

claude-opus-4-8[1m]

### Debug Log References

- Medición bajo carga (pgbench 18, tablas scratch): sync ≈ async en el request path, indistinguibles bajo
  concurrencia — confirmó la enmienda de D3. Detalle en *Decisión D1 · Medición BAJO CARGA*.

### Completion Notes List

- **Desviación de E6 (test de resurrección bespoke): no aplica bajo la opción D, y se omite conscientemente.**
  E6 se redactó cuando el plan aún contemplaba tabla + ventana testeable. Con la cola retirada, no existe camino
  async que resucite PII; el cierre de #376 lo prueban `SymfonyAuditLoggerTest` (activity escribe síncrono, sin
  despachar) + `SymfonyAuditLoggerBranchingTest` (escribe la fila por el contenedor real, sin transporte) + la
  ausencia del transporte `audit`. Un test que asertara «una escritura post-erase lleva el id original» afirmaría
  la ventana residual —comportamiento aceptado, igual que `security`/`change`—, no un fix.
- **Presupuestos de query: NO fue neutro (E5 lo asumió mal).** En Behat el transporte era `in-memory` (0 queries),
  así que la escritura síncrona de `activity` en `kernel.terminate` **ahora se cuenta**: 50 escenarios de lectura
  subieron su presupuesto (+1 por GET auditado; +2/+3 en los de paginación con varios GET). No es coste nuevo en
  prod (allí el `dispatch` ya escribía en `messenger_messages` en terminate) — los presupuestos de Behat
  **infra-contaban** frente a prod y ahora casan con los round-trips reales de BD. Ajustados a los valores medidos.
- **Pollution cross-escenario:** `bank_account/audit.feature` filtraba `BANK_ACCOUNTS_VIEWED` sin `correlation_id`;
  como ahora toda lista de cuentas escribe esa fila, otros escenarios la contaminaban → se acotó por `correlation_id`.
- **Seguimiento abierto:** el transporte `failed` no tiene retención ni gobierno para el resto de tipos de mensaje
  (gap GDPR vivo, independiente de #376) — issue propio, fuera de alcance de U-5a.

### Verificación (ejecutada, con su resultado)

- `make php.stan` → OK, no errors (1106 ficheros).
- `make php.unit c='--filter Audit'` → OK (220 tests, 1376 assertions).
- `make php.behat` → **354 scenarios / 3244 steps, todos verdes**.
- `make php.quality` → EXIT=0 (rector, cs-fixer, phpcs, phpmd, deptrac 0 violaciones, error-contract mapping OK).

### File List

**Producción (código + config)**
- `api/src/Shared/Audit/Infrastructure/SymfonyAuditLogger.php` — `dispatchActivity`→`writeActivity` (síncrono);
  fuera `MessageBusInterface`; docblock actualizado.
- `api/src/Shared/Audit/Application/RecordAuditEntry.php` — **borrado**.
- `api/src/Shared/Audit/Infrastructure/Messenger/RecordAuditEntryHandler.php` — **borrado**.
- `api/config/packages/messenger.yaml` — fuera transporte `audit` + routing + entrada `when@test`.
- `compose.yaml`, `compose.prod.yaml` — `messenger:consume` deja de consumir el transporte `audit`.

**Tests**
- `api/tests/Unit/Shared/Audit/Infrastructure/SymfonyAuditLoggerTest.php` — activity escribe síncrono; ramas de
  fallo (swallow/propagate) conservadas.
- `api/tests/Functional/Shared/Audit/SymfonyAuditLoggerBranchingTest.php` — escritura síncrona por el contenedor real.
- `api/tests/Unit/Shared/Audit/Infrastructure/Messenger/RecordAuditEntryHandlerTest.php` — **borrado**.
- `api/tests/Unit/Shared/Audit/Application/RecordAuditEntryTest.php` — **borrado**.
- `api/tests/Unit/Shared/Audit/Infrastructure/Double/RecordingMessageBus.php` — **borrado** (sin uso).
- `api/tests/Behat/Context/OutboxContext.php` — `RESETTABLE_QUEUES` sin `audit`.
- `api/features/shared/audit/access_log.feature`, `api/features/backoffice/audit/self_audit.feature`,
  `api/features/backoffice/bank_account/audit.feature` — sin pasos hold/consume; asserts directos.
- `api/features/backoffice/bank/*.feature`, `bank_account/{search,search_collection}.feature`,
  `users/{get,search}.feature`, `identity/session.feature` — presupuestos de query ajustados (audit síncrono).

**Docs**
- `docs/adr/audit-activity-log.md` — enmienda **D3.1** (D4/D4.1 intactos), header actualizado.
- `docs/architecture-api.md`, `docs/architecture/event-catalog.md`, `docs/adr/event-driven-architecture.md`,
  `docs/rules/cqrs-naming.md` — vía async → escritura síncrona.
- `PRODUCTION_SECURITY_CHECKLIST.md` — #376 cerrado; sin copia de PII de auditoría en el transporte.
