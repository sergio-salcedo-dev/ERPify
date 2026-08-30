---
title: 'Cerrar las 8 issues abiertas de SonarCloud arreglando el código, no silenciándolo'
type: 'refactor'
created: '2026-08-30'
status: 'in-review'
review_loop_iteration: 0
context: []
baseline_commit: '63c17130'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problema.** SonarCloud reporta 8 issues abiertas en `main` (medidas el 2026-08-30: las 8 líneas
coinciden con el árbol, el análisis no está rancio). Dos señalan defectos que ninguna otra herramienta
ve. `php:S2699` (BLOCKER) marca un test que **pasa aunque el step bajo prueba no haga nada** — su
comentario declara que no asserta a propósito, lo que deja sin falsador la mitad «positiva» del
fichero. Las cinco `php:S1142` de los tres comandos CLI de borrado GDPR señalan que la precedencia
`--dry-run` > `--force` > no-preguntable > preguntar es **posicional** (una secuencia de `if` mantenida
a mano) en un flujo cuyo contrato son los exit codes, y donde ya se midió un defecto de orden: leer el
conteo en la rama que iba a rechazar convirtió el exit code en un oráculo de existencia sobre un id.

**Enfoque.** Arreglar las 8 en el código, ninguna supresión. Los `preflight()` pasan a una tabla de
decisión `match (true)` que hace la precedencia estructural; el `eraseAndReport` de auditoría se parte
por la costura que su docblock ya explica (hacer el UPDATE irreversible / registrar que ocurrió); el
test sin aserción afirma el delta del contador de aserciones de PHPUnit; el `assertSame` de `S3415` se
mueve dentro del callback donde el valor está vivo.

## Boundaries & Constraints

**Always:**
- Comportamiento observable idéntico en los tres comandos: mismos exit codes, mismos mensajes, misma
  precedencia de flags, y el rechazo de un run no preguntable **antes** de leer el conteo.
- `ConfirmationGuardAdjacencyGateTest` verde sin tocarlo: cada comando conserva su
  `UnattendedRunPolicy::cannotAnswer(` y su `isInteractive()` a ≤1 sentencia del `confirm()`.
- Comentarios que explican el diseño, nunca la regla: ni `NOSONAR`, ni códigos `SXXXX`, ni jerga de
  «presupuesto de returns».

**Ask First:**
- Cualquier hallazgo que exija tocar `UnattendedRunPolicy` o el gate de adyacencia.
- Marcar una issue `accepted`/`falsepositive` en Sonar en vez de arreglarla.

**Never:**
- Mover `confirm()` fuera de los ficheros de comando (dejaría el gate sin población).
- Reordenar `--force` por encima de `--dry-run`.
- Barrer issues de Sonar fuera de estas 8.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior |
|----------|---------------|----------------------------|
| Vista previa | `--dry-run` (con o sin `--force`) | Reporta, no muta; `0` (`1` si el conteo falla) |
| Desatendido con fuerza | `--force --no-interaction` | Borra sin preguntar; `0` (`1` si falla) |
| No preguntable | `--no-interaction` sin `--force` | Rechaza, **no lee el trail**; `2` |
| Respuesta «no» | stdin interactivo responde `n` | Nada borrado; `0` |
| stdin agotado | stream en EOF tras `confirm()` | Rechaza; `2` |
| Auto-auditoría rota | UPDATE commitea, el log falla | Avisa de que es irreversible; `3` |

</frozen-after-approval>

## Code Map

- `api/src/Shared/Audit/Infrastructure/Cli/EraseActorAuditTrailCommand.php` — 3 de las 5 `S1142`
  (`preflight` 4 returns, `confirmMatchedRows` 5, `eraseAndReport` 4).
- `api/src/Iam/Identity/Infrastructure/Cli/EraseIdentitySubjectCommand.php` — `preflight`, 6 returns.
- `api/src/Backoffice/BankAccount/Infrastructure/Cli/EraseBankAccountSubjectCommand.php` — ídem, 6.
- `api/src/Shared/Console/Infrastructure/UnattendedRunPolicy.php` — **no se toca**: su docblock
  argumenta que el predicado se comparte y la colocación no.
- `api/tests/Unit/Gate/ConfirmationGuardAdjacencyGateTest.php` — barrido de texto que fija la forma.
- `api/tests/Unit/Behat/Context/RunOutputAbsenceTest.php` — `S2699` en `:69`.
- `api/tests/Behat/Context/RunOutcomeContext.php` — el step bajo prueba: hace exactamente 2 aserciones.
- `api/tests/Unit/Iam/Identity/Application/RequestPasswordResetTest.php` — `S3415` en `:153`.
- `api/tests/Unit/Iam/Identity/Application/InMemoryPasswordResetTokenRepository.php` — `onSave` corre
  **antes** de cada `save()`, así que el `assertCount(1, saved)` existente prueba que el callback corrió.
- `pwa/tests/app/backoffice/backOfficeMenuPermissions.test.tsx` — `S5906` en `:137`.

## Tasks & Acceptance

**Execution:**
- [x] `EraseBankAccountSubjectCommand.php` y `EraseIdentitySubjectCommand.php` — `preflight()` a
  `match (true)` de cuatro modos; extraer `reportDryRun()`, `refuseUnaskableRun()` (colapsa las dos
  llamadas idénticas a `refuse()`) y `confirmErasure()` (pregunta + relectura adyacente).
- [x] `EraseActorAuditTrailCommand.php` — `preflight()` a `match (true)`; sacar de
  `confirmMatchedRows()` la pregunta y sus dos formas sin respuesta; partir `eraseAndReport()` en
  «anonimizar» y «registrar la anonimización».
- [x] `EraseActorAuditTrailCommandConfirmationTest.php` — caso nuevo: `--dry-run --force` juntos siguen
  siendo vista previa (falsador de la precedencia, que hoy no fija nadie).
- [x] `RunOutputAbsenceTest.php` — el caso positivo afirma que el step realizó sus 2 aserciones
  (`Assert::getCount()` antes/después), en lugar del comentario «sin aserción a propósito».
- [x] `RequestPasswordResetTest.php` — mover el `assertSame` del orden dentro de `onSave`; eliminar la
  variable capturada inicializada a `null`.
- [x] `backOfficeMenuPermissions.test.tsx` — `expect(leafNames(manager)).toHaveLength(...)`.
- [x] **Forzado por PHPMD, no previsto en el plan:** el test nuevo dejó
  `EraseBankAccountSubjectCommandTest` en 11 métodos públicos (límite 10). Partido en
  `EraseBankAccountSubjectCommandConfirmationTest`, que es además la convención que los otros dos
  comandos ya seguían y este era el único sin ella.
- [x] **Rule of Three:** el helper `drainedStream()` quedaba en tres copias byte-idénticas (md5
  `54649f56…`). Extraído a `api/tests/Unit/Shared/Console/Double/DrainedInputStream.php`. Motivo doble:
  es duplicación real, y la condición `new_duplicated_lines_density < 3%` de la quality gate se mide
  sobre ~400 líneas nuevas, así que un bloque idéntico de >12 la tumbaría.

**Acceptance Criteria:**
- Dado el árbol resultante, cuando SonarCloud reanalice la rama, las 8 cierran como `FIXED` y no
  aparece ninguna nueva.
- Dado `RunOutputAbsenceTest`, cuando se borra del step **solo** la guarda de no-vacío, el caso positivo
  falla — que es lo que le da falsador **propio**, la defensa que `php:S2699` señalaba que le faltaba.
  **No es un hueco de detección del fichero**, y afirmarlo lo fue: medido a nivel de fichero, esa misma
  mutación ya tumbaba `…OverARunThatPrintedNothing` y `…OverAnOutputOfWhitespaceAlone` antes de este
  cambio, porque ambos fijan el mensaje que produce la guarda. El verde que medí era una corrida
  `--filter` de **un solo método**.
- Dado **cada uno de los tres comandos**, cuando se invierten los brazos `dry-run`/`force`, su caso nuevo
  falla. Los tres tienen su falsador, no solo el de auditoría.
- Dado cada comando, cuando se borra la relectura de `isInteractive()` o la llamada a `cannotAnswer()`,
  `ConfirmationGuardAdjacencyGateTest` falla.

## Design Notes

`match (true)` evalúa los brazos en orden y cortocircuita: la precedencia deja de ser una secuencia de
`if` y se lee como la tabla que el docblock ya describe. El rechazo del run no preguntable es un brazo
(`UnattendedRunPolicy::cannotAnswer($input) => UnattendedRunPolicy::refuse(...)`), no una sentencia
dentro del flujo, así que esa rama **no puede** alcanzar el conteo — el orden queda garantizado por la
forma, no por la posición.

Riesgo conocido: el set `earlyReturn` de Rector reexpande `return A || B;` en guardas. Aquí no hay OR en
posición de return, pero hay que **releer los tres ficheros después de `make php.quality`** (que aplica
los fixers) y confirmar que el `match` sobrevivió.

## Verification

**Ejecutado (exit code impreso en cada corrida):**

- `make php.stan` → **0**. `make php.quality` → **0** (tras dos rojos reales: PHPMD 11 métodos, luego verde).
- `make php.unit` completo → **0**, `Tests: 3421, Assertions: 17761, Skipped: 2`.
- `make pwa.test.unit` (fichero tocado) → **0**, 10 tests. `make pwa.quality` → **0**.
- Rector NO reescribió el `match (true)`: releídos los tres ficheros tras el `php.quality` que aplica
  fixers, los cuatro brazos siguen en su sitio.

**Falsaciones ejecutadas (mutar → rojo → restaurar copiando bytes):**

| Mutación | Resultado |
|----------|-----------|
| Borrar del step **solo** la guarda de no-vacío, corrida `--filter` de un método | rojo con la aserción nueva; **verde** con el test pre-cambio (`OK (1 test, 1 assertion)`) — el caso positivo no tenía falsador propio |
| Lo mismo, corrida del **fichero entero** | rojo con y sin el cambio: 3 fallos, dos de ellos los casos de rechazo. El fichero nunca fue ciego a esa mutación; lo que faltaba era la falsabilidad de ESTE caso |
| Vaciar el step entero | rojo por ambos lados: PHPUnit ya lo marca `Risky`. Tampoco era el hueco |
| Intercambiar los brazos `dry-run`/`force` en los 3 comandos | rojo, 3 tests / 3 fallos |
| Borrar la relectura de `isInteractive()` | rojo, `ConfirmationGuardAdjacencyGateTest` |
| Borrar el brazo `cannotAnswer()` | rojo, `ConfirmationGuardAdjacencyGateTest` |
| Invertir el orden delete/save en `RequestPasswordReset` | rojo, `the pending token was not dropped before the new one was written` |

**Límites de lo medido, declarados en vez de implícitos:**

- El contador de aserciones es un **suelo** (`>=2`), no una igualdad: `Assert::assertNotSame()` incrementa
  **dos veces** cuando ambos operandos son `bool` (llama a `assertNotEquals()` y **no retorna**) — leído en
  `vendor/`, no de memoria. Un `=== 2` afirmaría un detalle interno de PHPUnit.
- El suelo no distingue «las dos aserciones correctas» de «dos aserciones cualesquiera». Esa dirección la
  cubren los dos casos de rechazo del mismo fichero, que fijan el mensaje de la guarda.
- La AC de que SonarCloud cierre las 8 como `FIXED` **no es medible antes del push**. Lo que sí está medido:
  los 5 métodos de `S1142` quedan ≤3 returns y el refactor no creó ninguno nuevo con 4+; los constructos de
  `S2699`, `S3415` y `S5906` ya no existen.
- `true === $input->getOption(...)` asume `VALUE_NONE`, que es lo que las tres definiciones declaran. La
  comparación es anterior a este cambio y `ArgvInput` lanza ante `--dry-run=0`, así que no se toca aquí.

**Commands:**
- `make php.unit c='--filter "EraseActorAuditTrail|EraseIdentitySubject|EraseBankAccountSubject|ConfirmationGuardAdjacency|RunOutputAbsence|RequestPasswordReset"'` — verde.
- `make php.stan`, luego `make php.quality` — exit 0, y releer los 3 comandos.
- `make pwa.test.unit c='tests/app/backoffice/backOfficeMenuPermissions.test.tsx'` y `make pwa.quality` — verde.
- Falsación: vaciar el step, invertir los brazos del `match`, borrar la relectura de `isInteractive()`
  — una a una, rojo comprobado y restaurado **copiando los bytes** (nunca `git checkout --`).
