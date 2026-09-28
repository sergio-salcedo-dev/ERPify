---
title: 'DW-8 — tipar el par sujeto/pseudónimo de EventStoreSubjectAnonymiser'
type: 'refactor'
created: '2026-09-28'
status: 'done'
baseline_revision: '2e4a1a9a3ec54078183d5f185f58a5d6b00d388b'
review_loop_iteration: 1
followup_review_recommended: false
context: []
warnings: []
deferred: []
---

<intent-contract>

## Intent

**Problem:** `EventStoreSubjectAnonymiser::anonymise(string $subjectId, string $pseudonym)` es una mutación irreversible cuyos dos `string` consecutivos se pueden permutar en silencio: el `UPDATE` busca el pseudónimo, no casa nada, devuelve 0 y la entrada de cumplimiento queda indistinguible de «este sujeto no tenía eventos» con el id real vivo. Hoy sólo el espía del test unitario fija el orden.

**Approach:** Sustituir el par por un value object `SubjectPseudonymisation` (constructor privado + `of()`, como `AuditResource`) que valida ambos UUID y rechaza el par idéntico; el puerto, el adaptador DBAL, el espía y el único llamador pasan a recibir/construir el VO, leyendo los campos por nombre.

## Boundaries & Constraints

**Always:** el VO vive en `Erpify\Shared\Event\Application` junto al puerto; `of()` ejecuta `Uuid::ensure()` sobre ambos (misma excepción `InvalidUuidException` que el adaptador lanza hoy) y rechaza sujeto == pseudónimo comparando sin distinguir mayúsculas; el llamador construye el VO con argumentos nombrados; el SQL del adaptador no cambia ni en texto ni en parámetros.

**Never:** no tocar el `UPDATE` (sigue siendo la entrada `UPDATE event_store` de `SanctionedLogMutationGateTest`); no mover el VO a `Domain/` ni importar `Shared\Audit` desde `Shared\Event`; no cambiar `ActorAnonymisationResult` ni los puertos de auditoría; no editar `deferred-work.md`.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Par válido | dos UUID distintos | VO con `subjectId`/`pseudonym` tal cual | — |
| Sujeto mal formado | `'%.*%'`, UUID | no se construye el VO | `InvalidUuidException` |
| Pseudónimo mal formado | UUID, `'\1'` | no se construye el VO | `InvalidUuidException` |
| Par idéntico (otra caja) | `X`, `strtoupper(X)` | no se construye el VO: la reescritura se reportaría como éxito sin borrar nada | `InvalidArgumentException` |

</intent-contract>

## Code Map

- `api/src/Shared/Event/Application/EventStoreSubjectAnonymiser.php:56` -- puerto; firma `anonymise(string, string): int` → `anonymise(SubjectPseudonymisation): int`; docblock del método describe `$subjectId`/`$pseudonym`.
- `api/src/Shared/Event/Infrastructure/Persistence/DbalEventStoreSubjectAnonymiser.php:55-75` -- adaptador; hoy hace `Uuid::ensure()` x2 y enlaza `subject_id`/`pseudonym`/`subject_pattern`; el docblock (párrafo «Both arguments are validated UUIDs») debe apuntar a que la validación vive en el VO.
- `api/src/Iam/Identity/Application/FulfilIdentityErasure.php:181-185,254-264` -- único llamador. El helper privado `anonymiseBusinessLog(IdentityErasureResult, string $subjectId, string $pseudonym)` y su llamada posicional (l.181-185) son un par de strings adyacentes: deben desaparecer. El VO se construye en `execute()` junto a `$subject = AuditResource::of(...)` y se pasa entero al helper.
- `api/src/Shared/Audit/Domain/AuditResource.php` -- precedente a imitar (ctor privado, `of()` que valida).
- `api/tests/Unit/Shared/Event/Infrastructure/Double/RecordingEventStoreSubjectAnonymiser.php` -- espía; sigue registrando `['subjectId'=>…, 'pseudonym'=>…]` leyendo del VO (así `FulfilIdentityErasureEventStoreTest` no cambia).
- `api/tests/Functional/Shared/Event/EventStoreSubjectAnonymiserFunctionalTest.php` -- 5 llamadas `->anonymise(a, b)`; los dos tests `itRefusesAMalformed*BeforeReachingTheDriver` pasan a ser del VO (unitarios).
- `api/tests/Unit/Iam/Identity/Application/FulfilIdentityErasureEventStoreTest.php` -- ya fija el orden a través del espía; no requiere cambios.
- `api/tests/Unit/Shared/Event/Application/` -- hogar del nuevo test unitario.

## Tasks & Acceptance

**Execution:**
- `api/src/Shared/Event/Application/SubjectPseudonym.php` -- crear VO `final readonly` con `public string $value`, ctor privado y `fromString(string $value)` que ejecuta `Uuid::ensure()` -- el segundo miembro del par deja de ser un `string`, así que una permutación posicional es un `TypeError` y no un no-op.
- `api/src/Shared/Event/Application/SubjectPseudonymisation.php` -- crear VO `final readonly` con `public string $subjectId`, `public string $pseudonym`, ctor privado, `of(string $subjectId, SubjectPseudonym $pseudonym)` que ejecuta `Uuid::ensure()` sobre el sujeto y sobre `$pseudonym->value`, y rechaza el par igual sin distinguir mayúsculas (`\InvalidArgumentException`) -- que el par no pueda viajar suelto ni invertido.
- `api/src/Shared/Event/Application/EventStoreSubjectAnonymiser.php` -- cambiar la firma y el docblock del método -- el orden deja de ser posicional en el puerto.
- `api/src/Shared/Event/Infrastructure/Persistence/DbalEventStoreSubjectAnonymiser.php` -- recibir el VO y CONSERVAR `Uuid::ensure()` sobre ambos campos justo antes del SQL (defensa en la frontera de la interpolación: la seguridad del regex no debe depender sólo de un invariante de clase que reflection/unserialize pueden saltarse); ajustar el docblock en presente.
- `api/src/Iam/Identity/Application/FulfilIdentityErasure.php` -- en `execute()` construir `SubjectPseudonymisation::of(subjectId: $subjectId, pseudonym: SubjectPseudonym::fromString($anonymisation->pseudonym))` y cambiar `anonymiseBusinessLog` a `(IdentityErasureResult $identity, SubjectPseudonymisation $pseudonymisation)` -- ningún marco de la cadena conserva dos strings adyacentes.
- `api/tests/Unit/Shared/Event/Infrastructure/Double/RecordingEventStoreSubjectAnonymiser.php` -- adaptar la firma.
- `api/tests/Functional/Shared/Event/EventStoreSubjectAnonymiserFunctionalTest.php` -- adaptar las llamadas, retirar los dos tests de guardas y ajustar su docblock.
- `api/tests/Unit/Shared/Event/Application/SubjectPseudonymisationTest.php` -- test unitario (`#[CoversClass]` de ambos VO) de las cuatro filas de la matriz, más la colisión exacta en la misma caja.

Comentarios nuevos en presente: nada de «were», «rather than re-checked here», «are not here» (regla de comentarios relativos al cambio).

**Acceptance Criteria:**
- Given el puerto, when un llamador intenta `anonymise($a, $b)` con dos strings, then PHPStan lo rechaza (la firma sólo admite `SubjectPseudonymisation`).
- Given `FulfilIdentityErasure` borrando un sujeto vivo, when llega al log de negocio, then el espía registra `subjectId` = id del sujeto y `pseudonym` = pseudónimo del pase de actor (test existente en verde sin cambios).
- Given la batería funcional del adaptador, when se ejecuta contra Postgres, then los tests restantes pasan sin cambios en el SQL.

## Spec Change Log

### 2026-09-28 — iteración 1
- **Hallazgo:** Blind Hunter + Intent Alignment — el orden seguía siendo invertible: `of(string, string)` posicional y el helper privado `anonymiseBusinessLog(…, string $subjectId, string $pseudonym)` con su llamada posicional; ningún test enrojecía con la permutación, contra el «so the order cannot be inverted» del intent.
- **Enmienda:** nuevo VO `SubjectPseudonym` como segundo argumento de `of()`; el VO se forma en `execute()` y el helper lo recibe entero; el adaptador conserva `Uuid::ensure()`; test de la colisión en la misma caja; comentarios en presente. Design Notes reescritas (se retira el descarte del tipo propio).
- **Estado malo evitado:** una API que «tipa» el puerto pero deja el par posicional un marco más arriba.
- **KEEP:** nombre y ubicación de `SubjectPseudonymisation`; la guarda `strcasecmp`; el espía registrando `['subjectId'=>…, 'pseudonym'=>…]` para que `FulfilIdentityErasureEventStoreTest` no cambie; el helper `pair()` del test funcional; los comentarios de los tests de guardas trasladados al unitario; SQL intacto.

## Review Triage Log

### 2026-09-28 — Review pass
- verdicts: 13 findings — high 2, medium 0, low 3, false 8, maybe-false 0
- findings:
  - `[high]` `[bad_spec]` (Blind) el riesgo de permutación sube un marco: `anonymiseBusinessLog` sigue con dos strings adyacentes — verificado en `FulfilIdentityErasure.php:181-185,255`; enmienda: el VO se forma en `execute()` y el helper lo recibe.
  - `[high]` `[bad_spec]` (Blind) `of(string, string)` recrea el par posicional — verificado: ambos UUID válidos, la inversión pasa; enmienda: `SubjectPseudonym` tipado como 2º argumento.
  - `[false]` `[reject]` (Blind) el puerto hermano `AuditResourceAnonymiser` no tiene la guarda de igualdad — su pseudónimo lo acuña `DbalAuditActorAnonymiser` con `Uuid::generate()` en el mismo pase; la colisión no es alcanzable y el intent se limita al event store.
  - `[low]` `[bad_spec]` (Blind) comentarios relativos al cambio («were», «rather than re-checked here», «are not here») — verificado; enmienda: instrucción de redactarlos en presente.
  - `[false]` `[reject]` (Blind) `InvalidArgumentException` dentro de la transacción sin mapeo ni test de rollback — el pseudónimo es recién acuñado, la guarda es inalcanzable desde este llamador, y si saltase `wrapInTransaction` revierte: fallar alto en lo inalcanzable es lo correcto.
  - `[false]` `[reject]` (Blind) `InvalidUuidException` mapea a 400 — comportamiento idéntico al de antes (el adaptador ya lanzaba lo mismo) y `execute()` valida el sujeto en la l.146 antes de la transacción.
  - `[low]` `[bad_spec]` (Blind + Edge) el adaptador pierde su última guarda y depende de un invariante de clase saltable por reflection/unserialize — verificado; enmienda: el adaptador conserva `Uuid::ensure()` sobre ambos.
  - `[low]` `[bad_spec]` (Blind) falta la colisión exacta en la misma caja — enmienda: añadir el caso al unitario.
  - `[false]` `[reject]` (Blind) UUID no canónicos admitidos por `Uuid::isValid` — `SymfonyUuid::isValid()` usa por defecto el formato RFC 4122 de 36 caracteres; comportamiento sin cambios respecto al adaptador previo.
  - `[false]` `[reject]` (Blind) contabilidad del ledger sin cerrar — la invocación prohíbe editar `deferred-work.md`; lo cierra el orquestador.
  - `[false]` `[reject]` (Blind) el espía aplana el VO — registra cada campo leído por nombre y el test compara claves nombradas; una permutación en el llamador sigue enrojeciéndolo.
  - `[false]` `[reject]` (Edge) reconstrucción vía unserialize/reflection — agrupado con la fila del adaptador (misma causa); la vía no es alcanzable en producción (nadie deserializa este VO), cubierta por la guarda conservada.
  - `[false]` `[reject]` (Intent) divergencia «el orden sigue fijado sólo por convención y por el espía» — descriptivo; su contenido es el mismo defecto de las dos filas `high` y se resuelve con su enmienda (sin doble conteo de severidad).

### 2026-09-28 — Review pass (iteración 1)
- verdicts: 16 findings — high 0, medium 1, low 2, false 13, maybe-false 0
- findings:
  - `[medium]` `[patch]` (Blind) las guardas `Uuid::ensure()` del adaptador quedaron sin test al borrar los dos tests funcionales — verificado; restaurados `itRefusesAMalformed{Subject,Pseudonym}BeforeReachingTheDriver` con un par construido sin `of()` (`Closure::bind` al ctor privado); falsificado: sin las guardas, 2 fallos (exit 2).
  - `[low]` `[patch]` (Blind) docblocks contradictorios (test funcional «no se puede construir sin pasarlas» vs adaptador «reflection/unserialize lo saltan») — docblock del test reescrito en presente: guardas del VO en su unitario, las de frontera aquí.
  - `[low]` `[patch]` (Blind) `of()` revalida `$pseudonym->value` sin decir por qué — se conserva (lo exige el intent-contract) y el docblock explica que sostiene el invariante ante un `SubjectPseudonym` construido sin su fábrica.
  - `[false]` `[reject]` (Blind) la protección de tipo acaba en el par (`public string $pseudonym`) — adaptador y espía leen por NOMBRE de propiedad; no queda ninguna lista posicional donde permutar en silencio.
  - `[false]` `[reject]` (Blind) `fromString()` no prueba la procedencia del pseudónimo — el intent trata del orden, no de la procedencia; atar el VO a `ActorAnonymisationResult` exigiría que `Shared/Event` dependa de `Shared/Audit`, lo que el spec prohíbe.
  - `[false]` `[reject]` (Blind) ningún test de caso de uso cubre el nuevo camino de fallo — el pseudónimo lo acuña `Uuid::generate()` en el mismo pase: el estado no es alcanzable, y fallar alto en lo inalcanzable es lo correcto (revierte `wrapInTransaction`).
  - `[false]` `[reject]` (Blind) el par se construye aunque el eje de eventos no corra — mismo motivo: la guarda solo salta sobre un pseudónimo inválido o idéntico, que la cadena no produce.
  - `[medium]` `[patch]` (Edge) guarda del adaptador sin test — misma causa que la primera fila; mismo parche.
  - `[low]` `[patch]` (Edge) revalidación inalcanzable en `of()` — misma causa que la fila de `of()`; mismo parche.
  - `[low]` `[patch]` (Edge) el docblock del test funcional afirma una prueba que no existe — misma causa que la fila de docblocks; mismo parche.
  - `[medium]` `[patch]` (Verification gap, preverificado) guardas del adaptador borrables en verde — misma causa; mismo parche, falsificado.
  - `[false]` `[reject]` (Verification gap, otros) la validación se adelanta al camino «nada que borrar» — ver la fila del par construido siempre.
  - `[false]` `[reject]` (Intent) la inversión en la fábrica solo está medio cerrada — la inversión posicional es ahora un `TypeError` y PHPStan la rechaza; invertir exige escribir `SubjectPseudonym::fromString($subjectId)`, que ya no es silencioso.
  - `[false]` `[reject]` (Intent) ningún test fija la propiedad a nivel de tipos — la sostiene PHPStan sobre el árbol en `php.quality`; un test de `TypeError` exigiría suprimir PHPStan en el propio test.
  - `[false]` `[reject]` (Intent) el adaptador perdió cobertura directa — misma causa que la primera fila, ya parcheada (sin doble conteo).
  - `[false]` `[reject]` (Intent) validación anticipada e invariante de igualdad no pedido — descriptivo; la guarda de igualdad cierra un falso «borrado confirmado» y está en el intent-contract.

## Design Notes

El pseudónimo viaja como `SubjectPseudonym`, no como `string`: así `of($pseudonym, $subjectId)` no compila bajo PHPStan ni corre (`TypeError` con `strict_types`), a diferencia de dos UUID que ninguna validación distingue. Invertir el par exige escribir `SubjectPseudonym::fromString($subjectId)`, una línea que nombra el error en vez de esconderlo en el orden. No se toca `ActorAnonymisationResult`: la conversión ocurre en el llamador. La guarda de igualdad cierra otro riesgo real: con sujeto == pseudónimo el `UPDATE` reporta N filas «anonimizadas» y el id sigue vivo.

## Verification

**Commands:**
- `make php.unit c='--filter "SubjectPseudonymisationTest|FulfilIdentityErasure|EventStoreSubjectAnonymiser|EraseIdentitySubjectCommand|ErasureLockOrder|AdministratorErasureRace"'` -- expected: exit 0
- `make php.stan` -- expected: exit 0
- `make php.quality` -- expected: exit 0

## Auto Run Result

Status: done

**Resumen:** `EventStoreSubjectAnonymiser::anonymise()` recibe un único `SubjectPseudonymisation` (ctor privado + `of(string $subjectId, SubjectPseudonym $pseudonym)`). Como el pseudónimo lleva su propio tipo, un par invertido de forma posicional ya no compila en PHPStan y lanza un `TypeError` en tiempo de ejecución. `of()` valida los dos UUID y rechaza el par idéntico sin distinguir mayúsculas. El llamador forma el par en `execute()` y el helper privado lo recibe entero: ningún marco de la cadena conserva dos strings adyacentes. El adaptador conserva sus guardas en la frontera del SQL, que no ha cambiado.

**Ficheros:**
- `api/src/Shared/Event/Application/SubjectPseudonym.php` — nuevo VO del pseudónimo (valida UUID).
- `api/src/Shared/Event/Application/SubjectPseudonymisation.php` — nuevo VO del par (valida UUID y rechaza la igualdad).
- `api/src/Shared/Event/Application/EventStoreSubjectAnonymiser.php` — firma tipada y docblock.
- `api/src/Shared/Event/Infrastructure/Persistence/DbalEventStoreSubjectAnonymiser.php` — recibe el VO y mantiene `Uuid::ensure()` antes del SQL.
- `api/src/Iam/Identity/Application/FulfilIdentityErasure.php` — forma el par con argumentos nombrados; el helper recibe el VO.
- `api/tests/Unit/Shared/Event/Application/SubjectPseudonymisationTest.php` — 5 tests (las 4 filas de la matriz y la colisión en la misma caja).
- `api/tests/Functional/Shared/Event/EventStoreSubjectAnonymiserFunctionalTest.php` — helper `pair()`; las guardas del adaptador se prueban con un par construido sin la fábrica.
- `api/tests/Unit/Shared/Event/Infrastructure/Double/RecordingEventStoreSubjectAnonymiser.php` — firma nueva, misma forma de registro.

**Revisión:**
- Pasada 1: 13 hallazgos → `bad_spec` (el par seguía siendo posicional en `of()` y en el helper) y re-derivación.
- Pasada 2: 16 hallazgos → 3 entradas parcheadas (1 medium, 2 low); 13 `false` rechazados con su motivo en el triage log. Nada diferido.

**Seguimiento recomendado:** false (primera pasada tras la re-derivación: ningún `high` parcheado y un solo `medium`).

**Verificación:**
- `make php.unit` con el filtro del spec más `SanctionedLogMutation`: 91 tests OK.
- `make php.quality`: exit 0.
- Falsificación: sin las guardas del adaptador, los dos tests de frontera dan exit 2; bytes restaurados y comprobados con `cmp`.

**Riesgos residuales:** invertir el par sigue siendo posible escribiéndolo a propósito (`SubjectPseudonym::fromString($subjectId)`): ya no es silencioso, pero no es imposible. La protección de tipo la sostiene PHPStan, no un test en tiempo de ejecución.
