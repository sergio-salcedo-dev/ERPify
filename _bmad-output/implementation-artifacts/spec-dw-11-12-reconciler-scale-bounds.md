---
title: 'DW-11/DW-12 — cotas de escala del reconciliador de referencias a persona'
type: 'refactor'
created: '2026-09-24'
status: 'done'
baseline_revision: '6d46119356ae6cf50b9012f023f13f0c298d076a'
review_loop_iteration: 0
followup_review_recommended: false
context:
  - '{project-root}/docs/rules/database.md'
  - '{project-root}/docs/rules/testing.md'
warnings: ['oversized']
deferred: []
---

<intent-contract>

## Intent

**Problem:** `identity:gdpr:reconcile-subject-references` tiene dos precipicios de escala. `DoctrineLiveIdentityDirectory::existingIdsAmong()` liga un parámetro por id en UNA sentencia, así que a partir de 65 536 ids distintos cada tick falla (`PersonReferenceProbeFailed`) (DW-11). Y cada una de las seis fuentes de referencias (cinco `PersonReferenceSource` más `DbalPersonResourceReferences`) lee su columna entera en una sola sentencia sin `LIMIT` ni keyset (DW-12); `DbalRecoverySecretPersonReferences` además omite `DISTINCT`.

**Approach:** (1) Trocear la sonda de vida dentro del adaptador Doctrine: la cota de 65535 es del protocolo de PostgreSQL, no del caso de uso, así que el puerto pasa a prometer que responde para una lista de cualquier tamaño. (2) Un único motor compartido de lectura keyset (`SELECT DISTINCT col … WHERE col > :after ORDER BY col LIMIT :n`, en bucle hasta una página corta) que usan las seis fuentes, con lo que cada sentencia queda acotada y es un range scan sobre el índice cuya columna líder es `col`.

## Boundaries & Constraints

**Always:** el contrato de salida no cambia (ids distintos, en orden total ascendente, en la grafía del llamador para la sonda de vida). Cada id se sondea exactamente una vez aunque se trocee, así que un sujeto recibe el mismo veredicto en todos los ejes. El tamaño de página/trozo se inyecta por constructor con un valor por defecto (patrón `DbalAuditLogPruner::$batchSize`) y rechaza `< 1`. Las fuentes se siguen construyendo como `new X($connection)`. Todo el SQL va parametrizado; los identificadores de tabla/columna son literales de clase, nunca entrada del usuario.

**Never:** trocear en `ReconcileErasedSubjectReferences::liveAmong()` (la cota es del adaptador, y otro llamador del puerto la heredaría sin resolver). Tampoco envolver la reconciliación en una transacción `REPEATABLE READ` (ya no es una instantánea única y la registry lo declara), ni tocar `deferred-work.md` ni cambiar el enrutado/`services.yaml`. Nada de paginación por `OFFSET`.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Sonda sobre el techo | 70 000 ids, uno vivo | `[vivo]`, en varias sentencias | sin error de driver |
| Sonda cruzando trozos | trozo 2, ids en orden y grafía del llamador, vivos en trozos distintos | subconjunto en el orden y grafía de entrada | — |
| Lista vacía | `[]` | `[]` sin consultar | — |
| Página exacta | filas = múltiplo del tamaño de página | todos los ids, una consulta extra vacía termina el bucle | — |
| Fuente paginada | tamaño de página 1, ≥2 ids sembrados | contiene los sembrados; lista estrictamente ascendente (luego distinta) | — |
| Fila no-string | el driver devuelve un no-string | — | lanza (el reconciliador lo envuelve en `PersonReferenceProbeFailed`), nunca se descarta en silencio |

</intent-contract>

## Code Map

- `api/src/Iam/Identity/Infrastructure/Persistence/Doctrine/DoctrineLiveIdentityDirectory.php` -- sonda `IN (:ids)` única; docblock que afirma "chunking… not before". Trocear con `array_chunk`; conjunto de búsqueda en minúsculas sobre la unión de trozos.
- `api/src/Iam/Identity/Domain/Repository/LiveIdentityDirectory.php:24-34` -- el docblock del puerto dice "No caller chunks"; pasa a "cualquier tamaño; la cota del driver es asunto de la implementación".
- `api/src/Iam/Identity/Application/ReconcileErasedSubjectReferences.php` -- docblock de clase ("One probe… collapses five expanded IN lists into one"): una sonda LÓGICA, troceada por el adaptador; el argumento de corrección se sostiene porque se trocean ids, no lugares.
- `api/src/Iam/Identity/Application/PersonReferenceProbeFailed.php:17-19` -- cita el techo de 65535 como cota dura; actualizar.
- Nuevo `api/src/Shared/Persistence/Infrastructure/KeysetDistinctIds.php` -- motor keyset compartido (6 llamadores: la Regla de Tres se cumple). `Shared.Infrastructure` es importable desde cualquier `*.Infrastructure` (deptrac `&infra`).
- Las fuentes, todas `fetchFirstColumn` sin límite: `api/src/Organization/Membership/Infrastructure/Persistence/Doctrine/DbalMembershipPersonReferences.php` (índice único `user_id`), `api/src/Iam/Session/Infrastructure/Persistence/Doctrine/DbalSessionPersonReferences.php` (`idx_iam_session_user_id_status (user_id, status)`), `api/src/Iam/Invitation/Infrastructure/Persistence/Doctrine/DbalInvitationPersonReferences.php` (`idx_iam_invitation_invited_user_id`), `api/src/Iam/Identity/Infrastructure/Persistence/Doctrine/DbalPasswordResetTokenPersonReferences.php` (`idx_identity_password_reset_token_user_id`), `api/src/Iam/Identity/Infrastructure/Persistence/Doctrine/DbalRecoverySecretPersonReferences.php` (índice único `uniq_identity_recovery_secret_user_id`: la premisa del ledger de que "crece con las filas" es falsa, pero la asimetría desaparece gratis con el motor), `api/src/Shared/Audit/Infrastructure/Persistence/DbalPersonResourceReferences.php` (`audit_log_resource_idx (resource_type, resource_id)`; ámbito extra `resource_type = :t AND resource_erased = FALSE`).
- `api/src/Shared/Privacy/Application/PersonReferenceSource.php` -- contrato: añadir que la lectura va en páginas acotadas.
- Tests: `api/tests/Functional/Iam/Identity/DoctrineLiveIdentityDirectoryTest.php`, los seis `api/tests/Functional/**/Dbal*PersonReferencesTest.php` + `api/tests/Functional/Shared/Audit/PersonResourceReferencesFunctionalTest.php` (patrón: `inRolledBackTransaction`, aserciones por contención porque la BD de test es compartida). `DbalRecoverySecretPersonReferencesTest::testTheUniqueIndexIsWhatMakesTheAbsentDistinctSafe` pierde su razón de ser en cuanto la fuente dice `DISTINCT`.

## Tasks & Acceptance

**Execution:**
- `api/src/Shared/Persistence/Infrastructure/KeysetDistinctIds.php` -- crear: `Connection` + `pageSize` (defecto 5000, `< 1` → `InvalidArgumentException`), `idsOf(table, column, scope = 'TRUE', scopeParameters = [])` que construye `SELECT DISTINCT c FROM t WHERE c IS NOT NULL AND (scope) [AND c > :keyset_after] ORDER BY c LIMIT :keyset_limit` y repite hasta una página corta; un valor no-string lanza `UnexpectedValueException`; un `scopeParameters` que use los nombres reservados lanza `LogicException` -- una sola implementación del bucle y del cursor.
- Las seis fuentes -- delegar en el motor, con `int $pageSize = KeysetDistinctIds::DEFAULT_PAGE_SIZE` como segundo argumento del constructor; reescribir los docblocks que afirman "una lectura" y el de "no `DISTINCT`" de recovery-secret -- cota por sentencia.
- `DoctrineLiveIdentityDirectory.php` -- `int $chunkSize` (defecto 5000, guarda `< 1`) y troceo; docblocks del adaptador, del puerto, del caso de uso y de `PersonReferenceProbeFailed` -- DW-11.
- `PersonReferenceSource.php` -- docblock del contrato.
- Tests -- funcional del motor (tabla `TEMP` en transacción revertida: página exacta, página corta, NULL excluido, ámbito, nombres reservados, tamaño inválido; no-string con un stub); sonda con 70 000 ids y con trozo 2; en cada test de fuente un caso con `pageSize` 1 y ≥2 ids sembrados; eliminar el test del índice único de recovery-secret.

**Acceptance Criteria:**
- Given 70 000 ids distintos, uno de ellos vivo, when `existingIdsAmong()` se ejecuta contra PostgreSQL real, then devuelve exactamente ese id sin error de driver.
- Given cualquier fuente construida con tamaño de página 1 y al menos dos ids sembrados, when se llama `retainedPersonIds()`, then la lista contiene los sembrados y es estrictamente ascendente.
- Given el árbol, when se ejecutan `make php.quality` y los tests afectados, then todo sale en verde.

## Spec Change Log

## Review Triage Log

### 2026-09-24 — Review pass
- verdicts: 23 findings — high 0, medium 2, low 12, false 9, maybe-false 0
- findings:
  - `[low]` `[reject]` (blind) El `LogicException` de nombres reservados del motor sale envuelto como `PersonReferenceProbeFailed` — ninguna fuente pasa esos nombres (solo la de auditoría pasa `resource_type`); arreglarlo exige otra jerarquía de excepciones para un fallo de cableado que cualquier test de la fuente pone en rojo.
  - `[low]` `[reject]` (blind) Dos tipos de excepción para «id no-string» (`UnexpectedValueException` en el motor frente a `CorruptIdentityRow`) — ambos acaban envueltos en el mismo `PersonReferenceProbeFailed`; `Shared` no puede lanzar un tipo de `Iam`, y las columnas son `uuid`, que nunca llega como no-string.
  - `[low]` `[patch]` (blind) El docblock de `PersonReferenceSource` daba a entender que paginar acota la memoria — reescrito: la paginación acota lo que cada sentencia devuelve y recorre; la lista sigue siendo proporcional a las personas distintas.
  - `[false]` `[reject]` (blind) Las afirmaciones de «range scan» no se sostienen — `EXPLAIN` sobre `erpify_db_test`: `membership` usa `Index Cond: user_id > …` sobre `uniq_86ffd285a76ed395`, `iam_session` hace `Index Only Scan` sobre `idx_iam_session_user_id_status`, y `audit_log` hace `Index Scan` sobre `audit_log_resource_idx` con `resource_type = … AND resource_id > …` como condición de índice y `resource_erased` como filtro, tal como dice el docblock.
  - `[false]` `[reject]` (blind) El orden en tipo `uuid` frente al orden textual no está probado — el cursor es el valor tal como lo escribe PostgreSQL (hex en minúsculas) y se compara con `>` en tipo `uuid`, el mismo orden que el `ORDER BY`; no hay grafía alcanzable en la que las páginas diverjan.
  - `[low]` `[patch]` (blind) La aserción de que «el techo es un trozo válido» era vacía (`existingIdsAmong([])` no consulta) — sustituida por `testAChunkAtTheCeilingIsOneStatementTheDriverAccepts`: 65 535 ids en una sentencia contra PostgreSQL real.
  - `[low]` `[reject]` (blind) Tamaños por defecto de 5000 sin argumentar y `new KeysetDistinctIds` dentro de cada constructor — 5000 es el `DEFAULT_BATCH_SIZE` de `DbalAuditLogPruner`; no se nombra un daño concreto, y reestructurar seis constructores para inyectar un servicio no compra nada medible.
  - `[low]` `[reject]` (blind) La regla «en páginas acotadas» del contrato no la hace cumplir ningún gate — hace falta una fuente nueva que se la salte, y el remedio es un gate nuevo, no una corrección directa.
  - `[low]` `[patch]` (blind) El puerto `PersonResourceReferences` solo prometía «distinct» y no tenía la nota de paginación — ahora promete «distinct, ascending» y añade que la lectura va paginada y no es una instantánea.
  - `[low]` `[reject]` (blind) Identificadores interpolados protegidos solo por un docblock — todos los llamadores pasan literales de clase; el ámbito es SQL por construcción, así que una regex sobre tabla/columna no haría seguro el método; el contrato queda documentado.
  - `[low]` `[reject]` (blind) La ventana sin instantánea se alarga y se descarta `REPEATABLE READ` — el falso positivo es transitorio, se corrige solo en la siguiente pasada y ya lo declara la registry; una transacción que retiene la instantánea durante toda la ejecución es complejidad nueva con su propio coste.
  - `[low]` `[reject]` (blind) Tests que solo usan mocks viven en un `KernelTestCase` — coste despreciable (el filtro entero tarda 1,7 s) y no afecta a lo que se verifica.
  - `[false]` `[reject]` (edge) Un `$scope` vacío produce `AND ()` — ningún llamador pasa un ámbito vacío (el defecto es `'TRUE'`), y el resultado sería un error de sintaxis ruidoso, no un resultado erróneo.
  - `[false]` `[reject]` (edge) Un parámetro de ámbito de tipo array no se expande — ningún llamador lo pasa, y fallaría de forma ruidosa en el driver.
  - `[low]` `[reject]` (edge) Inyección de SQL mediante `$table`/`$column` — el mismo hallazgo que el de identificadores interpolados de la capa blind, con el mismo motivo de rechazo.
  - `[medium]` `[patch]` (verification-gap) Los tests por fuente seguían en verde si una fuente volvía a una sola lectura sin límite — añadido `PersonReferenceSourcesPageTheirReadTest` (data provider sobre las seis fuentes, `pageSize` 1, `Connection` mockeada): exactamente 3 sentencias, `keyset_limit === 1` en todas y `['a','b']` devuelto.
  - `[false]` `[reject]` (intent) Troceo en el adaptador y no en `liveAmong()` — el intent dice explícitamente «existingIdsAmong()/liveAmong()» y pide actualizar el docblock del adaptador.
  - `[medium]` `[patch]` (intent) DW-11/DW-12 solo se probaban en el adaptador, nunca en la superficie del reconciliador ni con el tamaño de página de producción — añadido `ReconcilerPastTheParameterCeilingFunctionalTest`: 70 000 filas de `audit_log` en una transacción revertida, el caso de uso real sacado del contenedor con los valores por defecto (5000), y veredicto sin excepción con los ids extremos reportados.
  - `[false]` `[reject]` (intent) La memoria y el tiempo total de cada pasada no quedan acotados — el mecanismo del intent («keyset per user_id/resource_id», «DISTINCT») es lo implementado, y el ledger acepta explícitamente que `DISTINCT` acota el resultado a personas («the scan, not the array»).
  - `[false]` `[reject]` (intent) Se eliminó el test del índice único de recovery-secret — con `DISTINCT` el índice deja de condicionar la salida, así que perderlo no cambia ningún resultado.
  - `[false]` `[reject]` (intent) El cursor va sobre la columna y no sobre `id`, como en el pruner — el intent pide literalmente continuación «per user_id/resource_id».
  - `[low]` `[reject]` (intent) El tamaño de 5000 no está medido — duplica el hallazgo de la capa blind sobre los tamaños por defecto; mismo motivo.
  - `[false]` `[reject]` (intent) Añadidos fuera del intent (motor, trait, docblocks, línea de la registry) — son la implementación del mecanismo pedido y la corrección de la documentación que queda falsa sin ellos.

### 2026-09-24 — Review pass (seguimiento)
- verdicts: 23 findings — high 0, medium 0, low 7, false 16, maybe-false 0
- findings:
  - `[false]` `[reject]` (blind) Las entradas DW-11/DW-12 siguen en `deferred-work.md`: el orquestador las marca `status: done` en el árbol de trabajo, y el intent prohíbe tocar el ledger.
  - `[low]` `[reject]` (blind) El `LogicException` de nombres reservados sale envuelto como `PersonReferenceProbeFailed` — carried: la excepción documentada en `ReconcileErasedSubjectReferences:66` y `:195` cubre el ensamblado del veredicto, no las lecturas; el código sigue igual que en el row anterior.
  - `[low]` `[reject]` (blind) Identificadores interpolados sin validar en `KeysetDistinctIds` — carried: todos los llamadores pasan literales de clase; mismo motivo que la pasada anterior.
  - `[false]` `[reject]` (blind) Ventana sin instantánea / `REPEATABLE READ` no discutido — carried: el intent lo excluye explícitamente (**Never**).
  - `[low]` `[patch]` (blind) En el eje de auditoría `LIMIT` acota lo que la página devuelve, no lo que recorre (filas borradas y eventos repetidos de una persona entre los límites de la página) — el docblock de `DbalPersonResourceReferences` ahora lo dice explícitamente. La forma del plan (range scan) ya la había medido la pasada anterior con `EXPLAIN`.
  - `[low]` `[reject]` (blind) Tamaños por defecto de 5000 sin argumentar — carried: mismo motivo que la pasada anterior.
  - `[low]` `[reject]` (blind) `pageSize` en seis constructores y `new KeysetDistinctIds` dentro de ellos — carried: el intent exige `new X($connection)` y el patrón `$batchSize`.
  - `[false]` `[reject]` (blind) El docblock de recovery-secret nombra un índice que ya nada comprueba — `DoctrineRecoverySecretRepositoryTest::aSecondSecretForOneIdentityIsRefusedByTheSchema` sigue fijando `uniq_identity_recovery_secret_user_id`.
  - `[low]` `[reject]` (blind) El gate de referencias a persona ahora podría comprobar la columna que lee cada fuente — es una capacidad nueva de un gate, no un defecto del cambio; arreglarlo es mucho más que una corrección directa.
  - `[low]` `[reject]` (blind) Tests que solo usan mocks viven en clases de kernel funcional — carried: mismo motivo que la pasada anterior.
  - `[false]` `[reject]` (blind) Los tests de escala se solapan — no son redundantes: uno fija la promesa del puerto en el adaptador y el otro el control cableado de punta a punta. Todo el filtro, 70 000 ids incluidos, tarda 2,1 s.
  - `[false]` `[reject]` (blind) Ningún test muestra que el driver rechaza 65 536 — `MAX_BOUND_PARAMETERS` es el límite Int16 del protocolo de PostgreSQL. La pasada anterior midió el rechazo del driver (`number of parameters must be between 0 and 65535`).
  - `[false]` `[reject]` (edge) Parámetros de ámbito que necesiten tipo DBAL (listas para `IN`) — carried: ningún llamador los pasa, y el driver fallaría de forma ruidosa.
  - `[false]` `[reject]` (edge) Borrar el test del índice único deja sin comprobar la unicidad de recovery-secret — `aSecondSecretForOneIdentityIsRefusedByTheSchema` la sigue comprobando contra el esquema real.
  - `[false]` `[reject]` (intent) No se ejercita el comando CLI ni el tick del scheduler — el cambio queda por debajo del comando, que no se toca; el caso de uso cableado desde el contenedor sí se ejercita por encima del techo.
  - `[false]` `[reject]` (intent) Solo el eje de auditoría se siembra a escala — el techo es una propiedad de la unión que se sondea, y un solo eje basta para cruzarlo. La paginación de cada fuente la fija `PersonReferenceSourcesPageTheirReadTest`.
  - `[false]` `[reject]` (intent) El caso «fila no-string envuelta por el reconciliador» no se prueba de punta a punta — el `catch (Throwable)` de `idsHeldBy` es genérico y el envoltorio ya está probado. El lanzamiento se prueba en el motor.
  - `[false]` `[reject]` (intent) El range scan no tiene test y el índice de auditoría no lleva `resource_id` como columna líder — carried: la pasada anterior midió los planes con `EXPLAIN`, y el ámbito fija por igualdad la columna líder.
  - `[false]` `[reject]` (intent) Cota superior de 65 535 en la sonda, que el intent no pide — es una guarda aditiva que rechaza un tamaño que ninguna sentencia puede llevar; no contradice nada.
  - `[false]` `[reject]` (intent) Ya nada comprueba el índice de recovery-secret — duplica el hallazgo de la capa edge; mismo motivo.
  - `[false]` `[reject]` (intent) El número de sentencias de la sonda no se afirma contra PostgreSQL real — lo fija el test con mock (trozo 2, tres llamadas), y sin troceo el test de 70 000 ids fallaría en el driver.
  - `[false]` `[reject]` (intent) La consulta extra vacía en la página exacta solo se prueba con mock — el stub fija los cursores `[null, 'b', 'd']`, y el resultado con PostgreSQL real se prueba aparte.
  - `[false]` `[reject]` (intent) El puerto de auditoría promete ahora «ascending» — el SQL anterior ya tenía `ORDER BY`, así que no cambia ningún comportamiento.

## Design Notes

El motor construye el SQL a partir de literales de clase, así que la parametrización se reserva para valores. Se descarta la alternativa de dos plantillas SQL por fuente (doce strings casi idénticos) y también `(:after IS NULL OR c > :after)`: esta última solo sirve el range scan si el plan se hace a medida, lo cual es frágil con sentencias preparadas. El cursor es el último valor tal como lo escribió PostgreSQL, y compararlo con `>` sobre una columna `uuid` usa el orden de `uuid`, el mismo del `ORDER BY`. Las páginas no forman una instantánea, pero cada id sale como mucho una vez gracias al cursor estrictamente creciente, y una fila insertada tras el cursor igual la habría perdido una lectura única anterior.

## Verification

**Commands:**
- `make php.unit c='--filter "KeysetDistinctIds|LiveIdentityDirectory|PersonReferences|PersonResourceReferences|ReconcileErasedSubjectReferences|PersonReferenceSource"'` -- expected: verde
- `make php.stan` y `make php.quality` -- expected: exit 0


## Auto Run Result

Status: done

**Resumen.** La sonda de vida (`DoctrineLiveIdentityDirectory`) trocea los ids en sentencias de como mucho 5000. Su constructor rechaza tamaños `<1` y `>65535`, y el puerto promete responder para una lista de cualquier tamaño. Las seis fuentes de referencias a persona leen su columna con un motor keyset compartido (`KeysetDistinctIds`): `SELECT DISTINCT … > :after ORDER BY … LIMIT :n` hasta la primera página corta. Recovery-secret gana su `DISTINCT`, y un valor no-string detiene la lectura en lugar de descartarse en silencio. El código está en `1bcaf17dae0d1f9810704f2773c16a6eeab6f1be`. Esta pasada de seguimiento solo corrige un docblock.

**Ficheros:**
- `api/src/Shared/Persistence/Infrastructure/KeysetDistinctIds.php` — motor keyset nuevo.
- `api/src/Iam/Identity/Infrastructure/Persistence/Doctrine/DoctrineLiveIdentityDirectory.php` — troceo y guardas de tamaño.
- Seis fuentes (`Dbal{Membership,Session,Invitation,PasswordResetToken,RecoverySecret}PersonReferences`, `DbalPersonResourceReferences`) — delegan en el motor con un `pageSize` inyectable.
- Docblocks de `LiveIdentityDirectory`, `ReconcileErasedSubjectReferences`, `PersonReferenceProbeFailed`, `PersonReferenceSource` y `PersonResourceReferences`, más la cabecera de `api/.person-reference-policy`.
- `DbalPersonResourceReferences.php` (esta pasada) — el docblock aclara que `LIMIT` acota lo que devuelve la página, no lo que recorre.
- Tests: `KeysetDistinctIdsTest`, `PersonReferenceSourcesPageTheirReadTest`, `ReconcilerPastTheParameterCeilingFunctionalTest`, el trait `AssertsKeysetPagedIds`, y casos nuevos en `DoctrineLiveIdentityDirectoryTest` y en los seis tests de fuente.

**Revisión (seguimiento):** 23 hallazgos de cuatro capas (blind 12, edge 2, verification-gap 0, intent 9).
- 1 parche aplicado, de severidad low: el docblock del eje de auditoría.
- 0 diferidos.
- 22 rechazados: 6 low, cada uno con su motivo (la mayoría repiten veredictos de la primera pasada), y 16 false, cada uno con su refutación en el Review Triage Log.

**Revisión de seguimiento recomendada:** `false`. Es una pasada de seguimiento y no ha parcheado ningún `high`: solo 1 parche low (0 high, 0 medium). El trabajo ha convergido.

**Verificación:**
- Filtro de tests afectados (incluye `ReconcilerPastTheParameterCeiling`; con `--list-tests` se ve que corren los tres tests de techo): 80 tests, OK, exit 0.
- `make php.stan`: exit 0.
- `make php.quality`: exit 0.

**Riesgos residuales:**
- La memoria sigue siendo O(ids distintos) en cada fuente y en la unión del reconciliador. Lo que queda acotado es cada sentencia.
- En `audit_log` cada página recorre todas las filas entre sus límites, las borradas incluidas; ahora está documentado.
- Las páginas no forman una instantánea: el falso positivo transitorio ya declarado se amplía un poco, y se corrige solo en la pasada siguiente.
- Ningún gate obliga a una séptima fuente a leer en páginas.
- `deferred-work.md` lo modifica el orquestador y se deja sin commitear, porque su commit le corresponde a él.
