---
title: 'DW-9 — gate del conjunto cerrado de mutaciones sancionadas sobre event_store y audit_log'
type: 'chore'
created: '2026-09-24'
status: 'done'
baseline_revision: '6de9d159924527a800d7a3055898768d450d910c'
review_loop_iteration: 0
followup_review_recommended: false
context: []
warnings: ['oversized']
deferred:
  - summary: >-
      CLAUDE.md "Required checks" no nombra que añadir una mutación sobre event_store/audit_log exige una línea en SANCTIONED de SanctionedLogMutationGateTest más la decisión en el ADR.
    evidence: |-
      Blind Hunter / Intent Auditor: los ADR y el quickref ya lo dicen, pero el fichero que los agentes leen primero no; el arreglo edita un fichero de contexto de agente, que el triage manda diferir.
    location: >-
      CLAUDE.md (Required checks)
    severity: low
---

<intent-contract>

## Intent

**Problem:** D12 (`docs/adr/event-store-and-projections.md`) y D4 (`docs/adr/audit-activity-log.md`) prometen un «conjunto cerrado de mutaciones sancionadas» sobre `event_store` y `audit_log`, pero sólo el `DELETE` de `audit_log` está vigilado (`AuditPruneStatementGateTest`); un `UPDATE`/`TRUNCATE`/upsert nuevo sobre cualquiera de las dos tablas entra sin que nada se ponga rojo, y el ADR aún dice «un solo miembro por tabla» cuando viven cuatro.

**Approach:** Un gate de artefacto kernel-free en `api/tests/Unit/Gate/` que tokeniza cada `.php` de `api/src`, extrae toda mutación no-append sobre las dos tablas y exige que el conjunto hallado sea **igual** (en ambos sentidos) al declarado: `DbalEventStoreSubjectAnonymiser` (UPDATE event_store), `DbalAuditActorAnonymiser` y `DbalAuditResourceAnonymiser` (UPDATE audit_log), `DbalAuditLogPruner` (DELETE audit_log). El motor vive en `api/tests/Support/` y se falsifica con un test de reglas sobre fuente sintética.

## Boundaries & Constraints

**Always:** tokenizar (`token_get_all`), nunca regex sobre bytes crudos, para que un comentario no cuente; unir literales concatenados con `.` y resolver `self::`/`static::` de constantes string locales; igualdad exacta fichero→mutaciones (un miembro desaparecido también es rojo); cada caso negativo del test de reglas junto a uno positivo; cabecera del gate con «qué prueba un verde» y sus puntos ciegos; clasificar los dos tests en `api/.artifact-gate-placement` como `home`.

**Never:** tocar código de producción en `api/src`; editar `deferred-work.md`; registro nuevo en la raíz de `api/` (el conjunto se declara como constante del gate: cambiarlo exige diff visible en revisión); barrer `api/tests` o `api/migrations` (el purgador de fixtures trunca legítimamente); retirar `AuditPruneStatementGateTest` (pina cláusulas que este gate no mira).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Árbol real | `api/src` actual | Hallado == declarado (4 ficheros, 1 mutación cada uno) | verde |
| UPDATE nuevo | `'UPDATE audit_log SET …'` en otro fichero | `['UPDATE audit_log']` para ese fichero | rojo nombrando fichero |
| Concatenado / constante | `'UPDATE ' . 'event_store'`, `'DELETE FROM ' . self::TABLE` | detectado | rojo |
| Variantes SQL | `DELETE FROM ONLY`, `public."audit_log"`, `TRUNCATE a, audit_log`, `MERGE INTO`, `INSERT … ON CONFLICT … DO UPDATE`, heredoc | detectado (UPDATE/DELETE/TRUNCATE/MERGE/UPSERT) | rojo |
| API DBAL | `->update('audit_log', …)`, `->delete('event_store', …)` | detectado | rojo |
| No mutaciones | comentario con `UPDATE audit_log`; `SELECT … FROM audit_log … FOR UPDATE`; `INSERT INTO audit_log` sin upsert; `ON CONFLICT DO NOTHING`; `UPDATE audit_logger` | nada | verde |
| Miembro desaparecido | se borra la sentencia del pruner | falta en hallado | rojo |

</intent-contract>

## Code Map

- `api/src/Shared/Event/Infrastructure/Persistence/DbalEventStoreSubjectAnonymiser.php:63` -- único `UPDATE event_store` (borrado GDPR, D12).
- `api/src/Shared/Audit/Infrastructure/Persistence/DbalAuditActorAnonymiser.php:74` -- `UPDATE audit_log` eje actor; contiene además `… ORDER BY id FOR UPDATE` (no debe contar).
- `api/src/Shared/Audit/Infrastructure/Persistence/DbalAuditResourceAnonymiser.php:99` -- `UPDATE audit_log` eje recurso.
- `api/src/Shared/Audit/Infrastructure/Persistence/DbalAuditLogPruner.php:144` -- `DELETE FROM audit_log` (retención); `FOR UPDATE` en el subselect.
- `api/src/Shared/Event/Infrastructure/Persistence/DbalEventStore.php:56`, `.../Audit/.../DbalAuditLogWriter.php:43` -- `INSERT` append (no cuentan; el de event_store usa `ON CONFLICT (event_id) DO NOTHING`).
- `api/src/**/{EventStoreSchemaListener,AuditLogSchemaListener,DbalAuditTimelineRepository}.php` -- `const string TABLE = 'event_store'|'audit_log'` sólo para lecturas/esquema: justifica resolver `self::TABLE`.
- `api/tests/Unit/Gate/AuditPruneStatementGateTest.php` -- modelo de estilo (CoversNothing, `ApiSourceFiles`, mensajes que explican el porqué); se conserva.
- `api/tests/Support/DbalTableApiCalls.php`, `api/tests/Unit/Gate/SanctionedLogMutationBlindSpotGateTest.php` -- añadidos en la pasada de seguimiento: la lectura de `->update()`/`->delete()` de DBAL y los pines de puntos ciegos, separados por los umbrales de PHPMD.
- `api/tests/Support/ApiSourceFiles.php` (`root()`, `phpFiles()`), `api/tests/Support/PhpSource.php` -- helpers reutilizables.
- `api/tests/Unit/Gate/StackedDocblockRulesGateTest.php` -- modelo de test de reglas (positivo junto a cada negativo).
- `api/.artifact-gate-placement:~186` -- lista `home` alfabética; el gate de colocación exige cada fichero de la home listado.
- `docs/adr/event-store-and-projections.md:294-420` (D12, «Qué cierra el conjunto…», «Trigger para construir ese gate») -- prosa a alinear; `docs/adr/audit-activity-log.md:219-222` (D4) -- añadir puntero al gate.
- `docs/claude-code-quickref.md:72` -- menciona los gates de sentencia de audit; añadir el nuevo.

## Tasks & Acceptance

**Execution:**
- `api/tests/Support/SanctionedLogMutations.php` -- motor: `in(string $source): list<string>` que devuelve descriptores `"<VERBO> <tabla>"` (UPDATE, DELETE, TRUNCATE, MERGE, UPSERT) en orden de aparición, con tokenización, unión de concatenaciones, constantes locales, heredoc y detección de `->update|delete('<tabla>')` -- regla reutilizable y falsificable.
- `api/tests/Unit/Gate/SanctionedLogMutationRulesGateTest.php` -- falsifica el motor con la matriz I/O (positivos y negativos) -- un detector que no ve nada da el mismo verde que uno que no puede ver.
- `api/tests/Unit/Gate/SanctionedLogMutationGateTest.php` -- declara `SANCTIONED` (4 ficheros→`['UPDATE event_store']`/`['UPDATE audit_log']`×2/`['DELETE audit_log']`), barre `api/src` y afirma igualdad; cabecera con verde/puntos ciegos (interpolación, `sprintf`, nombre de tabla en variable o constante ajena, DQL, SQL fuera de `src`, disparadores/funciones de BD) -- cierra DW-9.
- `api/.artifact-gate-placement` -- añadir las dos líneas `home` en orden -- lo exige `ArtifactGatePlacementGateTest`.
- `docs/adr/event-store-and-projections.md` -- reescribir «Qué cierra el conjunto» y el trigger: cuatro miembros entre las dos tablas, cerrado por el gate, qué prueba y qué no -- la prosa prometía un control inexistente.
- `docs/adr/audit-activity-log.md` -- una frase en D4 nombrando el gate -- simetría.
- `docs/claude-code-quickref.md` -- una frase junto al gate de la poda.

**Acceptance Criteria:**
- Given el árbol actual, when corre `SanctionedLogMutationGateTest`, then pasa y el conjunto hallado son exactamente los cuatro ficheros declarados.
- Given se planta `'UPDATE event_store SET …'` en cualquier clase de `api/src` (medido y revertido copiando bytes), when corre el gate, then falla nombrando ese fichero.
- Given se elimina una sentencia sancionada, when corre el gate, then falla (miembro ausente).
- Given `make php.lint.gate-placement` y `make php.quality`, when corren, then salen 0.

## Spec Change Log

## Review Triage Log

### 2026-09-24 — Review pass
- verdicts: 32 findings — high 0, medium 5, low 20, false 7, maybe-false 0
- findings:
  - `medium` `patch` [BH] `audit-activity-log.md` conservaba «Cerrado significa cerrado por revisión, no por gate», contradiciendo D4 nuevo y D12 — párrafo reescrito: cerrado por `SanctionedLogMutationGateTest`, puntero a D12.
  - `low` `reject` [BH] SQL entera en una constante usada vía `self::` cuenta dos veces — falla hacia rojo con mensaje claro; el arreglo añade ramas y cambia el falso rojo por un falso verde si la constante se usa desde otra clase. Documentado en cabecera y mensaje de fallo.
  - `medium` `patch` [BH] argumento con nombre `->update(table: 'audit_log')` no se detectaba — `tableArgumentAt()` acepta `table:`; fixture añadido; re-medido rojo plantándolo en `src`.
  - `low` `patch` [BH] `.=` y subexpresiones entre paréntesis escapan — añadido a los puntos ciegos (cabecera + ADR).
  - `low` `patch` [BH] DDL (`DROP`/`ALTER TABLE`) no cubierto — declarado fuera del conjunto (esquema = migraciones) en cabecera y ADR.
  - `low` `patch` [BH] `;` dentro de un valor entrecomillado corta upsert/TRUNCATE — documentado como punto ciego.
  - `medium` `patch` [BH] escapes de comillas dobles sin decodificar (`"UPDATE\taudit_log"`) — `stripcslashes` en literales dobles y partes de heredoc; fixtures añadidos.
  - `low` `patch` [BH] cualquier literal con forma de mutación (mensaje de excepción) pone rojo, sin documentar — dirección fail-safe declarada en cabecera y en el mensaje de fallo.
  - `low` `reject` [BH] constantes por fichero y no por clase (la última gana) — PSR-4: una clase por fichero en `src`; improbable. Constante nombrada por su propia clase: documentada como punto ciego.
  - `low` `patch` [BH] puntos ciegos declarados sin fijar en el test de reglas — nuevo `aDeclaredBlindSpotStaysBlind` (sprintf, interpolación antes de la tabla, constante ajena), cada uno junto a una mutación real.
  - `low` `defer` [BH] sin target `php.lint.*` ni entrada en CLAUDE.md «Required checks» — target: precedente `AuditPruneStatementGateTest` corre en el lane unit sin target; la entrada de CLAUDE.md edita contexto de agente → diferida.
  - `low` `patch` [BH] la frase del ADR «cualquier mutación nueva lo pone rojo» es más ancha que el motor — agrupada con las filas de puntos ciegos; lista ampliada.
  - `low` `reject` [ECH] doble conteo de constante (duplicado de la fila BH) — mismo motivo que arriba.
  - `medium` `patch` [ECH] escapes en comillas dobles (duplicado) — mismo arreglo.
  - `low` `patch` [ECH] argumento con nombre (duplicado, raíz compartida con BH) — mismo arreglo.
  - `low` `patch` [ECH] alias en `->update('audit_log a')` rechazado por el ancla `$` — alias opcional (`a` / `AS a`) aceptado; fixture.
  - `low` `patch` [ECH] constante que referencia otra declarada debajo — documentada como punto ciego (improbable; punto fijo sería complejidad sin caso real).
  - `low` `patch` [ECH] `;` en literal corta el upsert (duplicado) — documentado.
  - `low` `patch` [ECH] DROP/ALTER (duplicado) — documentado fuera del conjunto.
  - `low` `patch` [ECH] prosa en literales da falso rojo (duplicado) — documentado.
  - `low` `patch` [ECH] claim del ADR más ancho que el motor — lista de puntos ciegos alineada.
  - `low` `patch` [ECH] claim de la cabecera sobre constantes del mismo fichero — matizado (orden de declaración, sólo `self::`/`static::`).
  - `low` `patch` [VG] rama de comillas dobles interpoladas nunca ejercitada — fixture `"UPDATE audit_log SET ip = {$ip}"`.
  - `low` `patch` [VG] `ONLY` sólo probado para DELETE — fixtures `UPDATE ONLY`, `MERGE INTO ONLY`, `TRUNCATE ONLY`.
  - `false` `reject` [VG-otros] interpolación en el hueco del esquema no detectada — ya es punto ciego declarado y ahora fijado en `aDeclaredBlindSpotStaysBlind`.
  - `medium` `patch` [IA] D12 «hoy exactamente una» se lee como el conjunto entero — cualificado: una sobre `event_store`, cuatro entre los dos logs, cerradas por el gate.
  - `false` `reject` [IA] verbos ampliados más allá de UPDATE/DELETE — el intent pide cerrar el conjunto; TRUNCATE/MERGE/upsert son mutaciones no-append; no contradice nada.
  - `false` `reject` [IA] igualdad en vez de subconjunto — deliberado (Design Notes): la dirección «miembro desaparecido» impide un extractor vacuo.
  - `false` `reject` [IA] claves por ruta y no por clase — equivalentes hoy; mover el fichero debe revisarse igual.
  - `false` `reject` [IA] los tests sólo ejercitan fuente sintética / sin registro de ejecución — la verificación plantó mutaciones reales en `src` (rojo nombrando el fichero) y restauró por copia de bytes; comandos con exit 0 en Auto Run Result.
  - `false` `reject` [IA] añadidos no pedidos (`PhpStringExpressions`, quickref, D4) — exigidos por PHPMD y por la regla de docs del repo.
  - `false` `reject` [IA] sin target make (R4) — el intent no lo pide; precedente `AuditPruneStatementGateTest`.

### 2026-09-24 — Review pass (seguimiento)
- verdicts: 31 findings — high 0, medium 3, low 20, false 8, maybe-false 0
- findings:
  - `low` `defer` [BH] sin target `php.lint.*` para el gate — carried: fila de la pasada anterior (precedente `AuditPruneStatementGateTest` en el lane unit); ya en `deferred` junto a la entrada de CLAUDE.md.
  - `low` `defer` [BH] CLAUDE.md «Required checks» no nombra la regla — carried: ya diferida (edita contexto de agente).
  - `low` `patch` [BH] la lista de puntos ciegos vivía en dos copias (cabecera y D12) que iban a divergir — D12 reducido a un puntero a la cabecera del gate, única copia.
  - `low` `patch` [BH] `aDeclaredBlindSpotStaysBlind` fijaba 3 de ~9 puntos ciegos — añadidos `.=`, paréntesis, constante por nombre de clase, variable, DDL, `;` en valor entrecomillado y el doble conteo de constante; movidos a `SanctionedLogMutationBlindSpotGateTest` (umbral de longitud de PHPMD).
  - `false` `reject` [BH] el esquema interpolado se podría cerrar — es un punto ciego declarado y fijado, no una promesa rota; cerrarlo es mejora, no defecto.
  - `low` `patch` [BH] la cabecera decía que variable/`sprintf`/constante ajena «se vuelven un hueco opaco» — corregido: sólo la interpolación es hueco; lo demás corta la expresión.
  - `medium` `patch` [BH] `table:` con nombre fuera de la primera posición no se detectaba; `->update/delete` sobre cualquier receptor da falso rojo sin documentar — `DbalTableApiCalls::tableArgumentIndex()` busca la etiqueta `table:` en el nivel superior de la llamada; fixtures positivo y anidado-negativo; plantado `->update(data: …, table: 'audit_log', …)` en `DbalAuditTimelineRepository.php` → rojo nombrando el fichero, restaurado por copia de bytes. Receptor arbitrario: documentado como falso rojo en cabecera y mensaje.
  - `low` `patch` [BH] `static::` sobrescrito en subclase se resuelve al valor del fichero — documentado en la cabecera. Constantes por fichero: carried, rechazada en la pasada anterior (PSR-4).
  - `low` `reject` [BH] `PhpStringExpressions` sin test directo — se ejercita entero a través de los fixtures del motor; un segundo consumidor no existe; improbable que un desarrollador lo encuentre.
  - `low` `reject` [BH] tablas en duro en vez de derivarlas de los listeners — un renombrado sigue dando rojo (miembros desaparecidos); acoplar el gate a los listeners añade complejidad para un caso improbable.
  - `false` `reject` [BH] `dek_keystore` fuera del alcance — el intent acota el conjunto a `event_store` y `audit_log`.
  - `low` `patch` [BH] el ADR borraba el trigger antiguo sin decir por qué se construyó ya — párrafo «Por qué el gate no esperó…»: el trigger ya había saltado (tres mutaciones de `audit_log` anteriores a la frase).
  - `low` `patch` [BH] `audit-activity-log.md` decía lo mismo dos veces — la frase de D4 se reduce a un puntero al párrafo «Cerrado».
  - `low` `patch` [BH] un fichero sancionado licencia cualquier mutación del mismo verbo y tabla — declarado en la cabecera (el descriptor no lee columnas; eso es de los tests de cada miembro).
  - `medium` `patch` [ECH] `table:` en posición posterior — mismo arreglo que la fila BH.
  - `low` `patch` [ECH] `INSERT` en CTE seguido de un upsert sobre otra tabla da `UPSERT audit_log` — falso rojo añadido a la dirección fail-safe de la cabecera.
  - `low` `patch` [ECH] `->delete('audit_log')` sobre cualquier receptor — documentado (fila BH).
  - `low` `patch` [ECH] literal con prefijo binario `b"…"` no se decodificaba — `PhpStringExpressions` quita `b`/`B` antes de mirar la comilla (también en `b"` interpolado); fixture añadido.
  - `low` `patch` [ECH] la cabecera y D12 afirmaban ceguera ante una constante «referenciada antes de declararse» — medido: se resuelve (y hasta una construida desde otra de debajo se ve en su propia declaración); cláusula retirada.
  - `low` `patch` [VG] el orden de aparición dentro de UNA cadena no estaba probado (`ksort`) — fixture `DELETE …; TRUNCATE x, event_store; UPDATE …` en una sola cadena.
  - `low` `patch` [VG] pines de puntos ciegos incompletos — mismo arreglo que la fila BH.
  - `medium` `patch` [VG-otros] `table:` en posición posterior — mismo arreglo.
  - `low` `patch` [VG-otros] afirmación de la constante adelantada — mismo arreglo.
  - `low` `patch` [VG-otros] `b"…"`, `\u{…}`, constante de espacio de nombres sin `self::` — `b"…"` parcheado; `\u{…}` y la constante de namespace rechazadas: grafías improbables en SQL y el arreglo añade ramas.
  - `false` `reject` [VG-otros] el árbol no esconde un mutador — no es defecto: confirma que el verde real no es vacuo.
  - `false` `reject` [IA] los resultados de la matriz se infieren de motor + comparación, no del gate sobre un árbol plantado — carried (pasada anterior) y re-medido en esta: planta en `src` → rojo nombrando el fichero.
  - `false` `reject` [IA] «miembro desaparecido» probado con un sustituto — carried; la dirección se midió sobre el pruner real en la pasada anterior.
  - `false` `reject` [IA] regex sobre texto reconstruido frente a «nunca regex» — el intent da el propósito («para que un comentario no cuente») y lo cumple la tokenización previa.
  - `low` `patch` [IA] puntos ciegos copiados en el ADR — mismo arreglo que la fila BH (una sola copia).
  - `false` `reject` [IA] dirección fail-safe añadida fuera de la matriz — carried; no contradice la matriz.
  - `false` `reject` [IA] `deferred-work.md` modificado en el worktree — es contabilidad del orquestador, no de este cambio; no se toca ni se revierte.

## Design Notes

Igualdad exacta en lugar de «subconjunto de lo permitido»: la dirección «falta un miembro» es la que impide que el motor se vuelva vacuo en silencio (un extractor roto encuentra cero y pasaría). Constante en el gate, no registro: cuatro miembros estables, y un registro de raíz añadiría su propio gate de obsolescencia sin comprar nada que la igualdad no dé ya.

## Verification

**Commands:**
- `make php.unit c='--filter "SanctionedLogMutation"'` -- expected: OK, >0 tests (gate, reglas y puntos ciegos).
- `make php.lint.gate-placement` -- expected: exit 0.
- `make php.stan` y `make php.quality` -- expected: exit 0.

## Auto Run Result

Status: done

**Resumen.** Pasada de revisión de seguimiento sobre DW-9 (gate del conjunto cerrado de mutaciones sobre `event_store`/`audit_log`). Cierra un falso verde real (`table:` con nombre después de otros argumentos con nombre), decodifica literales `b"…"`, corrige dos afirmaciones de la cabecera que el motor desmiente, fija todos los puntos ciegos del motor en un test propio y deja una sola copia de la lista de puntos ciegos.

**Ficheros.**
- `api/tests/Support/DbalTableApiCalls.php` — nuevo: lectura de `->update()`/`->delete()` de DBAL, con `table:` en cualquier posición del nivel superior.
- `api/tests/Support/SanctionedLogMutations.php` — delega la API de tabla en `DbalTableApiCalls` (umbral de complejidad de PHPMD).
- `api/tests/Support/PhpStringExpressions.php` — acepta el prefijo binario `b`/`B` en literales dobles.
- `api/tests/Unit/Gate/SanctionedLogMutationGateTest.php` — cabecera corregida (hueco vs. fin de expresión, sin «referenciada antes de declararse», `static::` en subclase, verbo+tabla sin columnas, falsos rojos de receptor arbitrario y CTE); mensaje de fallo ampliado.
- `api/tests/Unit/Gate/SanctionedLogMutationRulesGateTest.php` — fixtures de orden dentro de una cadena, `table:` posterior y anidado, `b"…"`.
- `api/tests/Unit/Gate/SanctionedLogMutationBlindSpotGateTest.php` — nuevo: los pines de puntos ciegos (9 formas), fuera del test de reglas por el umbral de longitud.
- `api/.artifact-gate-placement` — línea `home` del test nuevo.
- `docs/adr/event-store-and-projections.md` — D12 apunta a la cabecera del gate en vez de copiar la lista; explica por qué el gate no esperó al trigger.
- `docs/adr/audit-activity-log.md` — D4 sin la frase duplicada.

**Revisión.** 31 hallazgos: 19 filas `patch` (3 medium, 16 low; muchas duplicadas entre capas sobre 11 arreglos), 2 `defer` carried (target make y CLAUDE.md, ya en `deferred`), 2 `low` rechazadas (test directo de `PhpStringExpressions`; tablas derivadas de los listeners) más la parte `\u{…}`/constante de namespace de una fila parcheada (improbables; el arreglo añade ramas), 8 `false` (ver Review Triage Log).

**Follow-up recomendado: false.** Pasada de seguimiento sin ningún `high` parcheado (medium 3, low 16): el trabajo ha convergido.

**Verificación.**
- `make php.unit c='--filter "SanctionedLogMutation|AuditPruneStatementGateTest"'` — exit 0, OK (45 tests, 731 assertions).
- `make php.lint.gate-placement` — exit 0, OK (10 tests).
- `make php.quality` — exit 0 (PHPStan, PHPMD, deptrac, cs-fixer, phpcs y los `php.lint.*`).
- Falsificación: plantado `$this->connection->update(data: ['ip' => ''], table: 'audit_log', criteria: ['id' => 1])` en `api/src/Backoffice/Audit/Infrastructure/Persistence/Dbal/DbalAuditTimelineRepository.php` → rojo «issues UPDATE audit_log but is not a sanctioned mutator»; restaurado por copia de bytes, `git status api/src` limpio.
- Medido: una constante referenciada antes de su declaración SÍ se resuelve (el fixture que la suponía ciega salió rojo), por eso se retiró la afirmación.

**Riesgos residuales.** Los puntos ciegos declarados en la cabecera del gate (interpolación, `sprintf`, variables, constante ajena, `.=`, paréntesis, DQL, SQL fuera de `src`, escritores del lado de BD, columnas de un miembro sancionado). El gate sigue corriendo sólo en el lane unit (diferido). `_bmad-output/implementation-artifacts/deferred-work.md` estaba modificado por el orquestador al llegar (DW-9 cerrado, DW-53 añadido) y se deja sin commitear, intacto.
