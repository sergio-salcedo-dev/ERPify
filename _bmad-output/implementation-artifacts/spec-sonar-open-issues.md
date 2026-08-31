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
- `api/src/Shared/Console/Infrastructure/UnattendedRunPolicy.php` — el predicado y la frase. Su docblock
  argumentaba que la colocación NO se comparte; puesto al día en la entrada del Spec Change Log.
- `api/src/Shared/Console/Infrastructure/ConfirmedErasureCommand.php` — **nuevo**: la secuencia de cuatro
  modos que los dos borrados de sujeto tenían byte a byte, heredada en vez de copiada.
- `api/tests/Unit/Gate/ConfirmationGuardAdjacencyGateTest.php` — barrido de texto que fija la forma, ahora
  sobre la unión de las dos vías.
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
  es duplicación real y la Regla de Tres se cumple exacta. **La segunda razón que escribí aquí era falsa**
  y la review la desmontó: `sonar-project.properties:23` fija
  `sonar.cpd.exclusions=api/tests/**,pwa/tests/**`, así que la duplicación en tests no puede mover
  `new_duplicated_lines_density`. La extracción sigue siendo correcta; la medición que la justificaba
  apuntaba a la mitad excluida del árbol.

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

## Adversarial pass

Tres capas en paralelo sobre el diff completo (tracked + untracked), contexto fresco, sólo lectura, contra
la ruta absoluta del worktree: Blind Hunter, Edge Case Hunter y Acceptance Auditor.

**Corrigieron al autor dos veces, y ese es el hallazgo de más valor:**

1. Sostuve que borrar del step la guarda de no-vacío dejaba el fichero en verde antes del cambio. Esa
   medición era una corrida `--filter` de **un método**; a nivel de fichero ya estaba en rojo, porque los
   dos casos de rechazo fijan el mensaje que produce esa guarda. Re-medido: 3 fallos, con y sin el cambio.
   Lo que la aserción nueva cierra es la falsabilidad del **caso positivo** — que es la `S2699` —, no un
   hueco de detección del fichero.
2. Escribí que el rechazo siendo un brazo del `match` cierra el oráculo de existencia «estructuralmente».
   Cierto del brazo, falso del run: `cannotAnswer()` no ve un stdin vacío pero **sin leer**, cuyo `feof()`
   es falso, y ese run alcanza el conteo antes de que la pregunta degrade la entrada. **Preexistente** —
   A/B de 1024 casos, cero diferencia contra `63c17130` —, así que lo que este cambio aportaba era la
   afirmación. Declarado ahora como residuo con su coste.

**Aplicados como patch:**

1. `recordErasure()` había quedado sin la guarda `affectedRows > 0`: al partir el método se quedó en el
   llamante, y `ACTOR_TRAIL_ERASED` es exento de la poda para siempre, así que una fila ahí es una
   atestación inmortal de un borrado que no ocurrió. La guarda vuelve al método que la necesita.
2. El docblock de `eraseAndReport()` afirmaba que un fallo ahí «no cambió nada y es seguro repetir»; su
   propio `catch` dice lo contrario.
3. `assertSame(2, …)` sobre el contador de aserciones afirmaba interioridades de PHPUnit — medido en
   `vendor/`, `assertNotSame()` incrementa dos veces con operandos `bool`. Pasa a un **suelo**.
4. `DrainedInputStream::open()` custodiaba su postcondición con `assert()`, que desaparece bajo
   `zend.assertions=-1`. Pasa a `throw`.
5. Cambié un falsador directo por una suposición sobre el doble: restaurado con un flag `$asked` afirmado.
6. Referencia cruzada rancia: «asserted below» sobre una aserción que el cambio movió arriba.
7. El expediente nombraba `refuseUnattended()`, que no existe, y su AC cubría 1 de los 3 tests de
   precedencia.

**Desestimados, con su razón:** `true === $input->getOption(...)` asume `VALUE_NONE`, que es lo que las tres
definiciones declaran, la comparación es anterior a este cambio y `ArgvInput` lanza ante `--dry-run=0`. Y el
suelo del contador no distingue «dos aserciones» de «las dos correctas» — esa dirección ya la cubren los dos
casos de rechazo del mismo fichero.

**Confirmado sano:** los 5 métodos de `S1142` quedan ≤3 returns medidos por flujo de tokens y el refactor no
creó ninguno nuevo con 4+; `make php.quality.dry-run` (forma de CI, sin fixers) sale 0; los cinco
`php.lint.*` de registros salen 0 y ninguno queda rancio; ni `docs/` ni `PRODUCTION_SECURITY_CHECKLIST.md`
describen la estructura interna de estos comandos; barrido de comentarios limpio.

**Una capa predijo el rojo de la quality gate antes de que existiera**, midiendo que la secuencia idéntica
entre los dos comandos gemelos pasaba de 95 a 147 tokens contra un default de CPD de 100. Se materializó
como 13,7 %, y su cierre es la entrada del Spec Change Log.

## Spec Change Log

**2026-08-31 — la quality gate salió roja, y su arreglo deroga dos restricciones del bloque congelado.**

Disparador: SonarCloud sobre la PR #898 cerró las 8 issues (`OPEN` = 0) pero puso
`new_duplicated_lines_density` en **13,7 %** contra umbral 3. Un solo bloque:
`EraseIdentitySubjectCommand.php:112` (61 líneas) ↔ `EraseBankAccountSubjectCommand.php:106` (50). Dar forma
idéntica a los dos comandos cruzó el umbral de CPD; `api/src` no está en `sonar.cpd.exclusions`.

**Restricciones congeladas que el usuario derogó explícitamente al elegir esta salida** (quedan escritas
arriba sin tocar, porque el bloque es suyo): *«`ConfirmationGuardAdjacencyGateTest` verde sin tocarlo»* y
*«Never: mover `confirm()` fuera de los ficheros de comando»*. Ambas caen; el `Ask First` —«cualquier
hallazgo que exija tocar `UnattendedRunPolicy` o el gate»— se cumplió: se preguntó y se consultó al
arquitecto antes de tocar nada.

Lectura del arquitecto, adoptada:

- **La decisión previa no cae; se aplica bien por primera vez.** `UnattendedRunPolicy` dice «centraliza lo
  que no varía». Entre esos dos comandos la colocación **no varía** — 147 tokens idénticos lo prueban. La
  premisa que la sostenía era falsa para ese par, y verdadera sólo para el de auditoría.
- **El comando de auditoría se queda FUERA del padre**, y esa exclusión es el mismo principio: su orden sí
  difiere (lee el conteo entre el rechazo y la pregunta), y expresarlo como hook metería una decisión de
  orden real detrás de un contrato padre-hijo — justo el eje donde han vivido todos los defectos de este
  subsistema.
- **Fusionar los dos en una clase está cerrado**: cruza `Backoffice` con `Iam`, dos contextos acotados. El
  padre va al kernel compartido, importable desde ambos.
- **Corrección a la propuesta del gate, que estaba incompleta.** La herencia es más fuerte *para quien
  hereda*, más débil para quien no; el gate no se muda, afirma la **unión**. Y —esto es lo que lo salva de
  quedar vacío— **cuenta comandos que alcanzan la confirmación por cualquiera de las dos vías**, no ficheros
  que contienen un token: contado por token, el día que el tercero heredase también, ningún `#[AsCommand]`
  contendría `confirm(` y el suelo pasaría a cero probando nada.

Resultado medido: la secuencia idéntica más larga baja de **147 a 95 tokens** — bajo el default de CPD, y al
nivel que ya tenía la base `63c17130`.

Falsado, no afirmado — mutación aplicada, rojo observado, bytes restaurados:

| Mutación | Resultado |
|----------|-----------|
| Borrar la relectura de `isInteractive()` **del padre** | rojo, y nombra el fichero del padre — que el gate viejo no podía ver |
| Que un comando deje de extender el padre sin rechazar por su cuenta | rojo por el suelo de población |
| Borrar el brazo `cannotAnswer()` del comando de auditoría | rojo: «neither extends ConfirmedErasureCommand nor calls cannotAnswer() itself» |

Descartadas, con su razón: deshacer la extracción en uno de los dos (juega contra la métrica y reintroduce
la precedencia posicional); `sonar.cpd.exclusions` (apaga la señal justo donde acertó); mergear en rojo (en
una PR cuyo objetivo entero es higiene de Sonar).

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
