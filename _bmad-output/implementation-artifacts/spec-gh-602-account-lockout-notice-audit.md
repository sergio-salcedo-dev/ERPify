---
title: 'La entrega del aviso de bloqueo de cuenta no dejaba traza de seguridad — #602'
type: 'security'
created: '2026-08-26'
status: 'in-review'
review_loop_iteration: 0
context: ['#602']
baseline_commit: '0d80cac879ac6fcdc0ece7a5258b67030f7b5890'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**El #602 original describía el grafo de recuperación de un bloqueo administrativo con dos aristas**: la
propia sesión de auditoría (#683, ya cerrada — proyecta el DISPARO del bloqueo como fila `security` vía
`RecordLockoutAuditBestEffort`, acción `USER_LOCKED`) y la palanca de desbloqueo administrativo (rama hermana,
otro worktree, fuera de alcance aquí). Al preparar esta tarea se detectó que el encargo original describía la
ausencia de traza como si nada la cubriese — pero #683 ya la cubre **para el disparo**. Lo que #683 NO
proyecta es la **entrega**: `NotifyLockedIdentities` (el barrido de mantenimiento que avisa al dueño por
correo, como mucho una vez al día) envía el aviso y sella la ventana de supresión, y ninguna fila registra que
eso ocurrió. Un operador investigando un bloqueo en curso puede ver `USER_LOCKED` y no tiene forma de saber si
el dueño llegó a ser avisado — un fallo del transporte de correo o una ventana de supresión ya gastada quedan
indistinguibles del silencio.

**Alcance de esta PR — mínimo y aditivo.** Una única fila `security` nueva (`ACCOUNT_LOCKOUT_NOTIFIED`),
escrita solo cuando el envío reportó éxito, emparejada con el sello `markLockoutNotified()` que la clase ya
escribía. Sin tocar el correo, el scheduler, ni la palanca de desbloqueo administrativo (rama hermana). El
evento `UserLocked` (`aggregateType() === 'Iam.Identity'`) sigue clasificado `person` en
`api/.persistent-transport-policy` y sigue sin enrutarse — no se añade transporte Messenger para él.

</frozen-after-approval>

## Lo entregado

| Pieza | Mecanismo |
|---|---|
| Fila de auditoría | `RecordLockoutNoticeAuditBestEffort::record()` — `AuditLogger::log('ACCOUNT_LOCKOUT_NOTIFIED', AuditLevel::SECURITY, AuditResource::of('User', $userId), ['lockedUntil' => …])`, envuelto en `catch (Throwable)` |
| Punto de llamada | `NotifyLockedIdentities::notifyOwner()` — tras `save()` (que ya es durable, `flush()` por fila), nunca antes |
| Getter nuevo | `User::lockedUntil(): ?DateTimeImmutable` — solo lectura, ningún setter nuevo |
| Registro | `api/.audit-evidence-actions`: `ACCOUNT_LOCKOUT_NOTIFIED => ordinary` (no es evidencia de un borrado GDPR) |
| `resource_type` | `User`, ya clasificado `person :: FulfilIdentityErasure.php` en `api/.audit-resource-types` — sin línea nueva |

**La clase se extrajo como un colaborador propio (`RecordLockoutNoticeAuditBestEffort`) en vez de inyectar
`AuditLogger` directamente en `NotifyLockedIdentities`, y no fue la primera forma que se escribió.** La forma
inicial —inyección directa, calcada del método `recordDrift()` de
`InspectStoredIdentityIntegrityCommand`— hizo subir `NotifyLockedIdentities` a un coupling-between-objects de
14 (PHPMD, umbral 13) y a `NotifyLockedIdentitiesTest` a 13 métodos públicos (umbral 10). El repo ya tiene el
mismo patrón resuelto igual una vez: `RecordLockoutAuditBestEffort` está separado de `LoginAttemptRegistrar`
por el mismo motivo, documentado en el propio test
(`LoginAttemptRegistrarAuditTest`: «holding both in one class pushed it past the public-method and
object-coupling thresholds»). Se siguió ese precedente en vez de suprimir el aviso de PHPMD.

## Adversarial pass

**Ejecutado el 2026-08-26, un subagente fresco, sin haber escrito una línea de este código, en solo
lectura.** Se le entregó el diff completo (`git diff --cached`), los ficheros nuevos/modificados en su
totalidad (no solo los hunks) y los ficheros hermanos sin tocar que este código imita o con los que colinda
(`RecordLockoutAuditBestEffort`, `LoginAttemptRegistrar`, `SymfonyAuditLogger`, `AuditLevel`, `AuditResource`,
`DoctrineUserRepository`, `.audit-resource-types`). Se le pidieron doce ángulos explícitos de ataque —doble
disparo de la fila, fuga de excepción fuera del `catch`, durabilidad del sello frente al fallo de auditoría,
si `AuditLevel::SECURITY` era el caso correcto, nulabilidad de `lockedUntil()`, contenido del metadata,
clasificación en ambos registros, si la extracción por PHPMD era correcta o solo esquivaba el linter, si los
tests eran falsables por mutación, y el cableado en `services.yaml`— y se le indicó explícitamente que no se
limitara a confirmarlos.

**Veredicto: sin hallazgos GRAVE ni MODERADO.** Cada ángulo se verificó contra el código, no se dio por
bueno:

- **Doble disparo — descartado.** `findLockedAt()` es un `SELECT id … ORDER BY id` sin join (sin duplicados
  posibles en una corrida), y `record()` solo se alcanza tras `send() → true` **y** `save()`. Una corrida
  repetida vuelve a leer `lockoutNotifiedAt` y `awaitsLockoutNoticeAt()` corta antes de llegar al envío.
- **Fuga de excepción — descartado, verificado línea a línea.** `AuditResource::of(...)` se evalúa como parte
  de la lista de argumentos de `log(...)`, dentro del `try`; `SymfonyAuditLogger::writeSecurity()` no tiene
  `catch` propio, así que cualquier throw de `AuditEntryFactory::create()` o del writer llega íntegro al
  `catch (Throwable)` de `RecordLockoutNoticeAuditBestEffort`. Falsificado borrando ese `catch`: **3 tests se
  ponen rojos** de inmediato.
- **Durabilidad del sello — confirmada leyendo `DoctrineUserRepository::save()` directamente**: `persist()` +
  `flush()` síncronos, sin transacción envolvente en `NotifyLockedIdentities`, así que el sello ya está
  comprometido cuando `record()` se ejecuta.
- **`AuditLevel::SECURITY` — justificado y no una copia ciega**: el docblock reargumenta la elección para
  este hecho concreto (coherente con por qué `USER_LOCKED` también es `security`, para que ambas filas
  aparezcan juntas en una consulta por nivel). Nota no bloqueante: `AuditLevel::ACTIVITY` ya trae
  swallow-and-report incorporado en `SymfonyAuditLogger::writeActivity()` al mismo canal `observability` —
  la elección de `SECURITY` + `catch` a mano duplica ese mecanismo, pero es un patrón preexistente en todo el
  repo (`RecordLockoutAuditBestEffort` lo hace igual), no algo que introduzca esta PR.
- **`lockedUntil()` nulo — hermético en la práctica.** `awaitsLockoutNoticeAt()` exige `isLockedAt($now)`
  (`lockedUntil instanceof DateTimeImmutable && lockedUntil > $now`) antes de que `notifyOwner()` continúe, y
  nada entre esa comprobación y `record()` toca `lockedUntil` (solo `markLockoutNotified()`, que solo escribe
  `lockoutNotifiedAt`). El parámetro nullable es superficie defensiva de un método con contrato más amplio que
  esta única llamada, y está probado en aislamiento (`testANullExpiryCarriesNoMetadata`).
- **Metadata — limpia.** Solo `lockedUntil` (ISO-8601 ATOM). Nada de IP, user-agent, ni la dirección de
  correo más allá del `resource_id` ya llevado.
- **`.audit-evidence-actions` y `.audit-resource-types` — ambos correctos.** `ACCOUNT_LOCKOUT_NOTIFIED` no es
  evidencia de un borrado ejecutado; `AuditResourceAnonymiser::anonymise()` reescribe por
  `(resource_type, resource_id)`, no filtra por `action`, así que la fila nueva queda cubierta por la
  clasificación `User => person` ya existente sin línea nueva.
- **La extracción por PHPMD — verificada, no solo aceptada.** `NotifyLockedIdentitiesTest.php` es
  byte-idéntico a `origin/main` (`git diff origin/main` vacío) y sus 9 tests originales pasan sin tocar.
  `php.md` reporta 0 violaciones en la corrida completa.
- **Tests no vacuos — falsificados por mutación, no solo leídos.** Borrar la llamada
  `$this->noticeAudit->record(...)` → 2 tests rojos. Borrar el `catch (Throwable)` del wrapper → 3 tests
  rojos.
- **`services.yaml` — verificado en vivo vía `debug:container`**: `NotifyLockedIdentities` autoenlaza
  `RecordLockoutNoticeAuditBestEffort`, que autoenlaza `SymfonyAuditLogger` (alias `AuditLogger`) y
  `$logger` ligado a `monolog.logger.observability`, sin ambigüedad.
- **Gates — todos verdes en corrida fresca**: `make php.stan` (0 errores), la suite filtrada de este cambio
  (31/31), `make php.quality` completo (deptrac 0 violaciones/0 sin cubrir, PHPMD 0 violaciones,
  composer-require-checker 0 símbolos desconocidos, CS-Fixer 0 correcciones, gherkinlint limpio, todas las
  suites PHPUnit verdes).

**Nota cosmética, no accionada.** La cabecera de `api/.audit-resource-types` enumera «cinco llamadores» de
`FulfilIdentityErasure::SUBJECT_RESOURCE_TYPE`; con `RecordLockoutNoticeAuditBestEffort` son ya seis. El
propio fichero marca esa lista como ilustrativa y no verificada por gate, así que no se ha tocado — es la
misma clase de deriva que el fichero ya documenta como su propia limitación.

## Gates

Todos en corrida fresca desde el worktree, exit code impreso:

| Gate | Resultado |
|---|---|
| `make php.stan` | 0 — No errors |
| `make php.unit` (suite completa) | 0 — 3169 tests, 2 skipped (preexistentes) |
| `make php.quality` (barrido completo) | 0 — PHPStan, Rector, CS-Fixer, PHPMD, deptrac, composer-require-checker, gherkinlint, todos los `php.lint.*` |

## Fuera de alcance, explícitamente

- El mecanismo de correo/scheduler de `NotifyLockedIdentities` — ya existía y funciona; no se ha tocado.
- La palanca de desbloqueo administrativo de #602 — PR hermana, otro worktree.
- #718 (retención de IP/user-agent) — decisión ya diferida, ajena a este cambio.
- La condición de carrera de `LoginAttemptRegistrar::recordFailure()` — conocida, diferida deliberadamente.
