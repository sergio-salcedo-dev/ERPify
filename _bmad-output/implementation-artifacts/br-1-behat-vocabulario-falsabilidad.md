---
baseline_commit: f2e80a9d9b5e2db4ac7a2fe5b03145bf3c5641d0
---

# Story BR-1: Vocabulario y falsabilidad de Behat

Status: in-progress

> Épica: [`epics-backlog-resolution.md`](../planning-artifacts/epics-backlog-resolution.md) · BR-1 de 8
> Issues: #313 #319 #320 #430 #590 #591 #592
> Rama: `fix/api-behat-falsabilidad-br1-xmvk` · Worktree: `.claude/worktrees/br-1-behat-vocabulario-falsabilidad-xmvk`
> Base: `main` @ `f2e80a9d` · Medido el 2026-08-09

## El lote no es lo que su título dice

**Tres de los siete issues ya están resueltos.** Un arco previo —PRs **#596–#601**, del 28 de julio de 2026— trabajó
exactamente este terreno, arregló el código y **no cerró los issues**. Es la lección que gobierna la épica
aplicándose a la propia épica: *el backlog se pudre por lo que se entrega, no por el paso del tiempo*.

Lo confirma la deriva de la única prosa que describe este terreno. `api/CLAUDE.md:79`, medido hoy contra
`f2e80a9d`:

| Afirma | Mide hoy | |
|---|---|---|
| «205 step patterns» | **209** | deriva |
| «47 features» | **49** | deriva |
| «tres contextos enteros [ociosos]: `EntityManagerContext`, `JsonErrorContext`, `JsonSchemaContext`» | `EntityManagerContext` tiene **18 usos** en features | **falso** |

Los otros dos sí están ociosos (7 y 6 patrones, 0 usos). Pero la frase se equivoca en un tercio de su propio
ejemplo, y las dos cifras que cita han derivado — y **nada vigila ninguna de las tres**. Ése es el argumento
completo de este lote en tres filas: la regla de #601 es correcta y no es falsable, así que se pudre en silencio
mientras todos los gates siguen verdes.

> **Corrección a la épica.** [`epics-backlog-resolution.md:39-41`](../planning-artifacts/epics-backlog-resolution.md)
> declara que «#590 y #591 son los dos con consecuencia real; el resto es higiene del vocabulario». Medido contra
> `f2e80a9d`, **es exactamente al revés**: #590 y #591 están cerrados y sin coste, y el valor del lote vive en
> #320, #319 y #430 — los que la épica llamó higiene. La afirmación se conserva a propósito en el fichero de
> épica, marcada, porque el error es reproducible: se midió el título del issue en vez del código.

Lo que sí sobrevive intacto es el **hueco estructural**, y es el que da sentido al nombre del lote: #601 dejó la
regla del vocabulario en prosa de `api/CLAUDE.md` **sin un solo mecanismo que la haga falsable**. No hay nada que
cuente pasos ociosos, nada que impida una frase casi-duplicada, y las cifras que la regla cita ya han derivado.
Una regla que sólo vive en prosa no es un control: es una intención.

## Decisiones

Ninguna de estas la puede tomar quien implementa: D1–D3 eligen entre dos invariantes (no entre dos formas de
escribir lo mismo), y D4–D5 fijan el **alcance** del lote.

**D1, D4 y D5 quedaron ratificadas por Sergio el 2026-08-09, las tres en el sentido recomendado.** El lote crece
respecto a los 7 issues y lo hace a propósito: entra el mecanismo de falsabilidad y entran las seis vacuidades
medidas. **D2 y D3 se ratificaron el 2026-08-09 con el código delante** (T0): D2 en el sentido recomendado; D3
**en contra de la recomendación**, hacia el rechazo ruidoso — registrado como tal en la tabla.

| # | Fork | Decisión | Argumento |
|---|---|---|---|
| **D1** | #320: ¿fallo limpio o wontfix documentado? ¿2 métodos o los 13? | ✅ **RATIFICADA — fallo limpio, los 13** | Arreglar 2 y dejar 11 con el defecto reparte el mismo trait en dos contratos. El coste marginal del 3.º al 13.º es cero |
| **D2** | #313: ¿sintaxis de escape `\:\:` o wontfix documentado? | ✅ **RATIFICADA — wontfix documentado** | La opción de «modificador sólo si el nombre está registrado» **contradice el contrato del docblock** (`NodeModifierLocator.php:16-18`: *«an unknown suffix is a loud exception, never a silent miss»*) — cambiaría un fallo ruidoso por una degradación silenciosa. Y el escape añade sintaxis a las features para un caso con **0 ocurrencias medidas** |
| **D3** | #319: ¿cualificar por cola los pasos no cualificados, o retirarlos? | ✅ **RATIFICADA — rechazo ruidoso: TODO paso de outbox nombra su cola** | Sergio eligió la variante más fuerte de «cualificar», por encima de la recomendada (defaultear a `async`). No choca con `api/CLAUDE.md:80`: la frase **no se borra**, queda registrada y **redirige** a la canónica. Lo que se retira es una *near-duplicate phrasing* — el propio párrafo de esa regla la llama «the actual waste». Coste medido: 2 líneas de feature |
| **D4** | ¿Entra en BR-1 el **mecanismo de falsabilidad** del vocabulario, o es lote aparte? | ✅ **RATIFICADA — entra** | Es lo que el título del lote promete y lo único que impide que #601 se vuelva a pudrir. Coste: 1 clase de gate en un hogar que ya tiene 25 hermanas. Riesgo: cero producción. **Pero es crecimiento de alcance sobre los 7 issues — decisión de Sergio, no del implementador** |
| **D5** | Las **seis vacuidades medidas que ningún issue registra** (sección siguiente): ¿entran? | ✅ **RATIFICADA — las seis, V6 incluida** | Las cinco de `JsonToolTrait` viven en el fichero que #320 ya abre: boy-scout, coste marginal ~0, y son literalmente «asserts que no pueden fallar», el tema del lote. V6 vive en un fichero que nadie más toca y entra igualmente, porque es el mismo defecto de clase y **un pendiente aquí sería PR propia, no una etiqueta** |

## Realidad medida, issue por issue

Medido dos veces por lectores independientes y verificado a mano en los cuatro puntos de mayor consecuencia.

### #590 · Los asserts a cero pasan vacuamente fuera de un transporte in-memory — **YA RESUELTO**

| Afirmación del issue | Veredicto |
|---|---|
| `OutboxContext::messages()` hace `continue` sobre cualquier transporte no in-memory | **Falso hoy.** El método ya no vive ahí: se extrajo a `tests/Behat/Support/Messenger/Outbox.php:76-87` |
| `transport()` devuelve `null` cuando la cola no existe → el assert a cero pasa sin mirar | **Cerrado por construcción.** `MessengerTransports::inMemory()` (`:94-103`) **lanza** `RuntimeException` si el servicio no es `InMemoryTransport`. `service()` (`:105-110`) sólo devuelve `null` para alimentar el mensaje de rechazo |
| El fetch-size trunca la lectura | **Cerrado.** `MessengerTransports.php:33` — `WHOLE_QUEUE = PHP_INT_MAX`, con el porqué en `:56-60` |

El docblock declara el invariante (`MessengerTransports.php:25-27`): *«It refuses rather than degrades: a name that
resolves to nothing, or to a transport of the wrong kind, throws instead of yielding an empty read that would
satisfy a zero-assertion.»* Arreglado por `ab796333` (#596), centralizado por `50a82c0e` (#598).

**Precisión sobre el alcance original:** la rama vacua sólo era alcanzable si `messenger.transport.async`/`failed`
no resolvían a `InMemoryTransport`, y `config/packages/messenger.yaml:46-52` (`when@test`) los fija a
`in-memory://?serialize=true`. Era un **fail-open latente ante un cambio de config**, no un assert vacuo en vuelo.
Hoy es irrelevante: el guard lanza sea cual sea el transporte.

**Coste: 0. Acción: cerrar con evidencia.**

### #591 · Nada fija el orden 403-antes-de-422 — **YA RESUELTO**

| Afirmación del issue | Veredicto |
|---|---|
| El test de prioridad que existe fija el orden de **CORS**, no el de autorización/validación | **CIERTO, y sigue siéndolo.** `ExceptionResponderListenerPriorityTest` fija `ExceptionResponder::PRIORITY === 16` (`:56-80`) y `CorsListener` a prioridad 0 (`:123-146`). No nombra `ControllerAttributesListener` ni `RequestPayloadValueResolver` en ninguna línea |
| Por tanto **nada** fija 403-antes-de-422 | **Falso.** El pin existe, en Behat y no en PHPUnit: `features/backoffice/bank/access_control.feature:142-152` |

La épica midió el fichero equivocado. Las dos afirmaciones conviven: el *test de prioridad* efectivamente sólo
pinnea CORS, y el orden 403/422 está pinneado **en otro sitio**. El `git grep` del issue no lo encontró porque
ningún escenario nombra las clases ni pone «422» en la misma línea que «403».

El escenario, verificado a mano, lleva su propio comentario: *«it goes red with a 422 if payload mapping ever runs
ahead of the permission gate, which would hand an unauthorized caller the validation verdict»*. Cubre las dos
mitades del resolver:

- **Body** (`:142-152`) — viewer + `{"name": ""}` → `403` + `the response should not contain "violations"`.
  No es vacuo: `CreateBankCommand:20-26` lleva `#[Assert\NotBlank]` en `name` **y** en `shortName`, así que ese
  body produce 2 violaciones — medido en `create.feature:63-85`.
- **Query** (`:71-79`) — `?paginationMode=nonsense&limit=-5` → `403` sin `violations`. Contrapartida 422 con
  llamador autorizado en `search.feature:75` y `search.feature:109`.

**Cronología que explica por qué quedó abierto:** #591 se abrió el 2026-07-27 22:38; el escenario lo introdujo
`9be7d474` (#553) el 2026-07-28 10:44 — **el propio PR bajo revisión adversarial absorbió el hallazgo antes de
mergear**, y nadie cerró el issue.

**Coste: 0. Acción: cerrar con evidencia.** Residuo no bloqueante: el pin es conductual sobre rutas Bank; ninguna
otra ruta gateada tiene canario propio. Si se quiere, va a `deferred-work.md`, no a este PR.

### #592 · El filtro `~@wip` no casa ningún escenario pero invita a aparcar uno rojo — **YA RESUELTO Y GATEADO**

| Afirmación del issue | Veredicto |
|---|---|
| `behat.dist.php` filtra `~@wip` del perfil por defecto | **Falso hoy.** `behat.dist.php:59-62` sólo lleva `withCompatibilityMode(GherkinCompatibilityMode::LEGACY)`; el `use TagFilter` desapareció |
| Es un mecanismo de exclusión vivo | **Retirado y con trinquete.** El **único** `withFilter(` del árbol es la cadena que el gate busca para prohibirla: `BehatSuiteCoverageGateTest.php:80` |
| Podría quedar otra vía de exclusión | **No queda ninguna.** `git grep -nE 'withFilter|TagFilter|--tags'` sobre `api`, `.github` y `make` → 1 solo hit, el del gate. CI invoca `make php.behat` pelado (`ci.yml:186` → `vendor/bin/behat --format=pretty`) |

Se tomó la **opción 1** del issue (retirar el filtro), no la 2 (guard). Arreglado por `ab796333` (#596), gateado por
`7503390d` (#597).

**Coste: 0. Acción: cerrar con evidencia.**

### #320 · `JsonToolTrait` lanza en un dot-path ausente en vez de fallar limpiamente — **CONFIRMADO, y mayor de lo declarado**

| Afirmación del issue | Veredicto |
|---|---|
| `JsonInspector` usa `THROW_ON_INVALID_PROPERTY_PATH` | **CONFIRMADO** — `Support/Json/JsonInspector.php:27` |
| `should be null` y `should have N elements` llaman `evaluate()` sin try/catch | **CONFIRMADO** — `JsonToolTrait.php:103-110` y `:160-169` |
| Sus hermanos están protegidos | **CONFIRMADO en 2 de ellos** — `jsonPropertyShouldExist():139-146` y `ShouldNotExist():148-158` sí envuelven |
| Son «2 métodos y sus hermanos» | **Subestimado: son 13.** Todos llaman `evaluate()` desnudo — `:70 :89 :105 :114 :123 :132 :162 :173 :182 :192 :202 :215 :224` |

Irradia a 4 contextos: `JsonNodeContext`, `JsonSchemaContext`, `MercureContext`, `OutboxContext`.

> ### ⚠ TRAMPA — el arreglo puede fabricar la vacuidad que este lote existe para eliminar
>
> `OutboxContext::eventMatchesTable()` (`:335-347`) usa la excepción **como predicado**: envuelve
> `jsonPropertyShouldBeEqualTo()` en `try { … } catch (Throwable) { return false; }`. Verificado a mano.
>
> - Si el arreglo hace que un path ausente **siga lanzando** pero con un tipo limpio (`AssertionFailedError`),
>   el `catch (Throwable)` lo sigue capturando y **no cambia nada**. Seguro.
> - Si el arreglo hace que un path ausente **deje de lanzar** (tratarlo como `null` y dar el assert por bueno),
>   `eventMatchesTable()` devolverá `true` para un evento que **no tiene** esa propiedad. El paso
>   `there should have been an outbox event created containing: | X | null |` empezará a pasar **vacuamente**.
>
> Es decir: el segundo camino convierte a BR-1 en productor del defecto de BR-1. **La aserción de que esto no
> ocurrió es un criterio de aceptación, no una nota** — ver AC4.
>
> **Y el paso tiene hoy 0 usos en features, lo cual lo empeora, no lo alivia.** Un paso ocioso es —por la regla de
> `api/CLAUDE.md:80`— una aserción construida esperando a ser gastada, y gastarla es justo lo que hicieron #599 y
> #600. Un arreglo descuidado de #320 no rompe nada visible hoy: arma la trampa para el primero que obedezca la
> regla. Nada se pondría rojo al armarla.

**Coste:** 1 fichero (`JsonToolTrait.php`) + verificación en `OutboxContext.php:343`. **Exige D1.**

### #319 · Indexado no cualificado de `OutboxContext` abarca `async`+`failed` — **CONFIRMADO**

| Afirmación del issue | Veredicto |
|---|---|
| Los pasos no cualificados agregan las colas de ambos transportes en un índice concatenado 1-based | **CONFIRMADO** — `Support/Messenger/Outbox.php:30` (`INSPECTABLE_QUEUES = ['async','failed']`) y `:76-87` (concatena en un `list` plano etiquetando cada entrada con su `queue`) |
| Consumidores: `outboxEventsWereCreated()`, `selectEventByNumber()`, los dos pasos de tabla | **CONFIRMADO** — `OutboxContext.php:73-84`, `:99-103`, `:184-204`, y también `removeEventByNumber():246-255` |
| La variante cualificada sí filtra | **CONFIRMADO, con el buen patrón** — `Outbox.php:92-108` rechaza además una cola no inspeccionable |
| «El evento nº 1 puede resolver a un sobre de `failed`» | **PARCIAL** — sólo si `async` está **vacía**, porque `messages()` recorre `async` primero. La mitad del **conteo** sí es exacta sin condición |
| Los pasos no cualificados están en uso | **CORRECCIÓN AL ISSUE** — las 40+ invocaciones de conteo/selección son todas `on the queue "async"`. **Pero hay un consumidor vivo que el issue no lista:** `features/backoffice/bank/dispatch_event.feature:152` → `I remove event 1 from the outbox` → `removeEventByNumber()` → índice concatenado |

**Por qué es latente hoy, y no por la razón que da el issue:** `failed` no puede llenarse bajo los pasos de consumo
porque el dispatcher privado de `MessengerConsumerContext` no lleva `SendFailedMessageToFailureTransportListener`
(documentado en `:40-45`). Nada enruta a `failed`. Eso hace el defecto latente **por composición del doble**, no
por diseño — un cambio en el dispatcher lo despierta sin avisar.

**Coste:** 2 ficheros (`OutboxContext.php`, `Outbox.php`) + posiblemente 1 línea de `dispatch_event.feature`.
Mecánico bajo D3.

### #313 · Un property path con `::` literal es irrepresentable — **CONFIRMADO (latente, y el issue ya lo admite)**

| Afirmación del issue | Veredicto |
|---|---|
| `explicitModifierName()` toma la subcadena tras el último `::` sin escape | **CONFIRMADO** — `tests/Behat/NodeModifier/NodeModifierLocator.php:86-99` (`strrpos($path, '::')`, `substr($position + 2)`) |
| Un sufijo no registrado lanza | **CONFIRMADO** — `:64-71`, `UnknownNodeModifierException` en `:70` |
| El impacto es teórico | **CONFIRMADO y medido.** Los únicos sufijos `::` en los 49 features son `::amount` y `::null`. **Cero** paths con `::` literal |

Única evolución desde su apertura: `:96-98` ahora trata un `::` final desnudo como path literal en vez de lanzar por
nombre vacío. La limitación de fondo sigue intacta. **Exige D2.**

### #430 · Unificar el vocabulario de aserción de comandos — **CONFIRMADO**

Dos vocabularios paralelos que el lector tiene que aprender:

| `MessengerConsumerContext` | `SymfonyCommandContext` |
|---|---|
| `:100` `I execute :command with options:` | `:63` `I run the :commandName command with options:` |
| `:131` `the command should succeed` | `:80` `the last command should succeed` |
| — *(no existe)* | `:92` `the last command should fail` |
| `:142` `the output should contain :text` | `:102` `the command output should contain :needle` |
| — *(no existe)* | `:117` `the command output should be JSON with a :field field` |

Ambos siguen registrados por separado en `behat.dist.php:93-94`. La premisa de diseño que justifica **dos
implementaciones** (Worker propio vs `ApplicationTester`) sigue vigente y documentada en
`MessengerConsumerContext.php:33-38` — lo que se unifica es **el vocabulario**, no necesariamente el mecanismo.

**Dos correcciones al issue:**

1. Lista `the command output should not contain` en `SymfonyCommandContext` — **ese paso no existe hoy**. Sí existe
   `should be JSON with a :field field`, que el issue no menciona.
2. La asimetría real es que `MessengerConsumerContext` **no tiene** `the command should fail`. Unificar obliga a
   decidir qué significa «fail» para un Worker.

**Blast radius medido** (el issue dice «10+ escenarios»; es mayor):

- Vocabulario messenger: **23 usos en 9 features** (`bank/count` ×5, `bank/create`, `bank/delete`,
  `bank/dispatch_event` ×4, `bank/update`, `bank_account/{create,delete,status,update}`).
- Vocabulario consola: **7 usos en 1 feature** (`features/shared/console/dead_letter_status.feature`).
- `I execute :command with options:` está **ocioso** (0 usos) — candidato natural a absorber el cambio sin tocar
  ningún escenario.

`50a82c0e` (#598) ya unificó el **transporte** compartido; los **steps** quedaron sin tocar.

**Y el nombre miente sobre el sujeto.** `the command should succeed` **no ejecuta ningún comando de consola**:
valida un `Symfony\Component\Messenger\Worker` construido a mano (`runWorker():197-229`), y su exit code es
sintético — `0` si `$worker->run()` no lanzó, `1` si lanzó (`:222`/`:224`). Igual `the output should contain`,
que asserta sobre el buffer de un `ConsoleLogger` (`:209-210`), no sobre la salida de un comando. El docblock de
`SymfonyCommandContext:24-27` reconoce el solape y lo declara deliberado: *«reusing them here would redefine those
patterns and break the whole suite»* — la duplicación es la consecuencia asumida de que Messenger ocupó primero
las frases genéricas. Unificar es, antes que nada, **decidir qué sujeto nombra cada frase**.

`I execute :command with options:` está ocioso **pero no es un absorbedor limpio**: su cuerpo sólo admite
`messenger:consume` (assert en `:103-107`).

## Vacuidad medida que ningún issue registra

Seis asserts que **no pueden fallar** o que fallan por la razón equivocada, encontrados midiendo, no leyendo el
backlog. Ninguno tiene issue. Son exactamente lo que da nombre al lote — *un paso que no puede fallar no es
cobertura* — y cinco de los seis viven en el fichero que #320 ya abre.

| # | Dónde | Qué pasa vacuamente |
|---|---|---|
| V1 | `JsonToolTrait:163` — `\count((array) $value)` | `should have 0 elements` **pasa contra un `null` explícito**; `should have 1 element` pasa contra **cualquier escalar** |
| V2 | `JsonToolTrait:123` `ShouldBeFalse` | `FILTER_VALIDATE_BOOLEAN` ⇒ `should be false` pasa para `""`, `0`, `null`, `[]` y **cualquier string no reconocido** |
| V3 | `JsonToolTrait:132` `ShouldBeTrue` | Simétrico de V2 |
| V4 | `JsonToolTrait:43-55` `jsonShouldNotBeValid` | El `return` dentro del `catch` hace pasar **cualquier** excepción como «no valida» — incluido un fallo al **cargar el esquema**. El test pasa cuando el esquema no existe |
| V5 | `JsonToolTrait:103-105` `ShouldBeNull` | No aplica `propertyPostProcessName()` (su hermano `ShouldBeEqualTo:68` sí), así que un path con sufijo `::<modifier>` llega crudo al accessor |
| V6 | `NodeModifier/Scalar/BackedEnumNodeModifier.php:56` | `\substr($value, ((int) \stripos($value, 'Enum::')) + 6)` — **depende de que el FQCN contenga el token literal `Enum::`**. Un enum llamado `Status` hace que `stripos` devuelva `false`, castea a `0`, y la case se toma desde el offset 6 del FQCN. Mismo supuesto en `PropertyPostProcessTrait:59` |

V1–V4 son de la misma familia que #320 y **mayores en consecuencia**: #320 produce un error ruidoso (feo, pero
visible); V1–V4 producen **verde**. Es la diferencia entre un test que grita mal y un test que miente.

## Acceptance Criteria

**AC1 — Los tres resueltos se cierran con evidencia, no con código.**
#590, #591 y #592 se cierran en GitHub con un comentario que cita `fichero:línea` y el commit que los arregló
(`ab796333` / `7503390d` / `9be7d474`). Ninguno produce diff en `api/`. Un cierre por título es una violación.

**AC2 — La corrección a la épica queda escrita.**
`epics-backlog-resolution.md` §BR-1 recoge que #590/#591/#592 estaban resueltos y que la frase «los dos con
consecuencia real» era falsa, **conservando la afirmación original marcada** (precedente: el blockquote de BR-2 en
`:51-54`). Se actualiza también el orden recomendado si el re-medido lo abarata.

**AC3 — #320: el fallo es limpio y el alcance es completo.**
Un dot-path ausente produce un fallo de aserción legible, no una `UnexpectedValueException` cruda, en **todos** los
métodos que D1 determine. Existe un test por cada forma de fallo cubierta.

**AC4 — #320 no ha fabricado vacuidad en el outbox.** *(gate de la trampa)*
Se demuestra, con la sonda escrita en el propio artefacto, que
`there should have been an outbox event created containing:` con una propiedad **ausente** en el evento **sigue
fallando** tras el cambio. La demostración es por **sabotaje**: se neutraliza el evento, se observa el rojo, y se
restaura **copiando los bytes** — nunca con `git checkout --`.

**AC5 — #319: el índice es por cola.**
Conteo, selección y borrado no cualificados dejan de agregar `async`+`failed`. `dispatch_event.feature:152` sigue
verde. Ningún paso se borra por estar ocioso.

**AC6 — #430: un solo vocabulario de comandos.**
Sobrevive una sola forma de decir «el comando fue bien», «el comando falló» y «la salida contiene». Los pasos
retirados del vocabulario visible **no se eliminan**: se mantienen como alias o se documenta la decisión contraria
en su declaración. Las 30 invocaciones medidas (23 + 7) siguen verdes.

**AC6b — Las seis vacuidades cerradas.**
Cada vacuidad que entre se cierra con una aserción que **se ha visto roja contra el caso que hoy pasa**: un `null`
explícito para `should have 0 elements`, un string arbitrario para `should be false`, un esquema inexistente para
`should not be valid`. Un arreglo sin ese rojo no cuenta — es la misma clase de defecto que se está cerrando.

**AC7 — #313 resuelto según D2.**
Si es wontfix: la limitación queda escrita **en el docblock de `NodeModifierLocator`**, no sólo en el issue, y el
issue se cierra apuntando ahí. Si es escape: hay test de ambos lados (path con `::` literal, y sufijo modificador).

**AC8 — Gates verdes de corrida fresca, con exit code impreso.**
`make php.stan` · `make php.quality` · `make php.unit` · `make php.behat` · `make php.lint.gherkin`.
Cada uno en su tabla `Gate / Exit`, una tabla por tanda. **Ningún verde se copia de una corrida anterior.**

**AC9 — Todo gate o aserción nuevo se ha visto rojo.**
Tabla «provocados en rojo»: qué se rompió, qué falló, cuántos rojos. El conteo se re-mide **al final**, porque se
pudre con el propio diff. Un control que nunca se ha visto rojo no es un control.

**AC10 — Pase adversarial ejecutado y registrado ANTES de `gh pr create`.**
Sus hallazgos se escriben en este artefacto antes de abrir el PR, y el cuerpo del PR dice dónde están. Sin draft
(no se usan en este repo). Un pase cuyos hallazgos sólo caben en un segundo PR es una revisión, no un gate.

## Tasks / Subtasks

- [x] **T0 · Ratificar D2 y D3** con el código delante. D1, D4 y D5 ya están cerradas. (AC: —)
- [ ] **T1 · Cerrar #590, #591, #592** con comentario de evidencia medida. (AC: 1)
  - [ ] Redactar los tres comentarios citando `fichero:línea` + commit que lo arregló
  - [ ] Verificar que ninguno exige diff en `api/`
- [ ] **T2 · Corregir la épica.** (AC: 2)
  - [ ] Reescribir §BR-1 con el re-medido; conservar la afirmación previa en blockquote marcado
  - [ ] Revisar si el «orden recomendado» de `:186` cambia al abaratarse BR-1
- [x] **T3 · #320 — fallo limpio en dot-path ausente.** (AC: 3, 4)
  - [x] Aplicar D1 sobre `JsonToolTrait.php`
  - [x] **Antes de tocar nada**, escribir la sonda de vacuidad del outbox y verla verde
  - [x] Tras el cambio, re-correr la sonda; falsificarla por sabotaje y restaurar por copia de bytes
  - [x] Test por cada forma de fallo
- [x] **T4 · #319 — indexado por cola.** (AC: 5)
  - [x] `OutboxContext.php` bajo D3 (`Outbox.php` no necesitó cambio: `messagesOnQueue()` ya era el buen patrón)
  - [x] Verificar `dispatch_event.feature:152` (y `:191`, que el issue no listaba)
- [ ] **T5 · #430 — un vocabulario de comandos.** (AC: 6)
  - [ ] Medir el vocabulario vivo primero: `make php.behat c='-dl'` y `c="-d '<texto>'"` — **no fiarse de las cifras de `api/CLAUDE.md`, ya derivaron**
  - [ ] Decidir la semántica de «fail» para el Worker
  - [ ] Migrar las 30 invocaciones; ningún paso borrado
- [x] **T5b · V1–V6, las seis.** (AC: 6b)
  - [x] Escribir primero el caso que hoy pasa y **verlo pasar** — es la prueba de la vacuidad
  - [x] Arreglar; verlo rojo contra ese mismo caso
- [ ] **T6 · #313 bajo D2.** (AC: 7)
- [ ] **T7 · Mecanismo de falsabilidad del vocabulario.** (AC: —)
  - [ ] Modelar sobre `BehatSuiteCoverageGateTest` (mismo hogar, mismas auto-protecciones anti-vacuidad)
  - [ ] Verlo rojo antes de darlo por bueno
- [ ] **T8 · Higiene del diff y de la prosa que este lote desmiente.** (AC: —)
  - [ ] Barrer comentarios con IDs de issue/historia en `api/tests/**` — prohibidos fuera de este artefacto
  - [ ] **`api/CLAUDE.md:79`**: corregir el ejemplo falso (`EntityManagerContext` **no** está ocioso, 18 usos) y
        decidir qué hacer con las cifras. Si D4 = sí, **la prosa deja de citar números y apunta al gate**; si
        D4 = no, se re-miden a mano y se acepta que volverán a derivar
  - [ ] Decidir la deriva de `api/CLAUDE.md:27` (ver Dev Notes)
- [ ] **T9 · Gates + rojos provocados.** (AC: 8, 9)
- [ ] **T10 · Pase adversarial, registrado aquí, ANTES del PR.** (AC: 10)
- [ ] **T11 · Code review de la tarea completa**, y sólo entonces mover el marcador de sprint a `done`.

## Dev Notes

### Ficheros a tocar

| Fichero | Issue | Nota |
|---|---|---|
| `api/tests/Behat/Support/PostProcess/JsonToolTrait.php` | #320 · **V1–V5** | Único escritor; irradia a 4 contextos |
| `api/tests/Behat/NodeModifier/Scalar/BackedEnumNodeModifier.php` | **V6** (si D5) | Aislado; `PropertyPostProcessTrait:59` comparte el supuesto |
| `api/CLAUDE.md` | T8 | `:27` contradice a `:50` · `:79` afirma un ejemplo falso y dos cifras derivadas |
| `api/tests/Behat/Context/OutboxContext.php` | #319 escritor · #320 **lector** | **Colisión alta** — ver la trampa |
| `api/tests/Behat/Support/Messenger/Outbox.php` | #319 | `INSPECTABLE_QUEUES` en `:30` |
| `api/tests/Behat/Context/MessengerConsumerContext.php` | #430 | |
| `api/tests/Behat/Context/SymfonyCommandContext.php` | #430 | |
| `api/features/backoffice/bank/dispatch_event.feature` | #319 (`:152`) · #430 (`:30,31,78,110`) | **Colisión media** — mismo fichero, líneas distintas |
| `api/tests/Behat/NodeModifier/NodeModifierLocator.php` | #313 | Aislado del resto |
| `api/behat.dist.php` | #430 | Sólo si cambia el orden de hooks |
| `_bmad-output/planning-artifacts/epics-backlog-resolution.md` | AC2 | |

### Orden obligado

**#320 antes que #319.** #320 cambia cómo falla `evaluate()`; `OutboxContext::eventMatchesTable():337-345` traduce
ese fallo a «no casa». Hacer #319 primero significa reescribir el indexado y volver al mismo fichero con un cambio
semántico por debajo. #430 y #313 son paralelizables e independientes.

### Rutas que no son adivinables

- **`api/tools/behat` no existe.** Behat 4 vive en el árbol único: `api/composer.json` (`require-dev`),
  configurado por `api/behat.dist.php`, ejecutado desde `api/vendor/bin/behat`.
- Los gates estáticos viven en `api/tests/Unit/Shared/Architecture/` — **25 clases** hoy.
- El soporte de Behat se reparte entre `tests/Behat/Context/`, `tests/Behat/Support/` y `tests/Behat/NodeModifier/`.

### Patrones a REUTILIZAR

- **`BehatSuiteCoverageGateTest` es el molde de cualquier gate nuevo**: textual y no cargando el config (porque
  `Behat\Config\*` es prerelease), recorrido **depth-unbounded a propósito** (`:127-128`), y —lo importante—
  **se autoprotege de su propia vacuidad**: `assertNotEmpty($paths)` en `:100` y `assertNotEmpty($relative)` en
  `:137`, este último con el comentario *«a zero-feature scan would make this gate vacuous»*.
- **`MessengerTransports` es el molde del rechazo duro**: lanzar en vez de degradar, con un `refusal()` que
  distingue «no registrado» de «tipo equivocado» (`:117-126`).
- Interrogar `pg_locks` en vez de simular concurrencia (`DoctrineActiveAdministratorDirectoryTest.php:111-137`).
- `-dl` y `-d '<texto>'` **imprimen el vocabulario vivo**. No transcribirlo a este artefacto: se pudre.

### Regresiones que NO se pueden romper

1. **`eventMatchesTable()` usa la excepción como predicado** (`OutboxContext.php:335-347`). El fix de #320 puede
   volver vacuo `there should have been an outbox event created containing:`. Es AC4.
2. **Nunca borrar un paso por estar ocioso** (`api/CLAUDE.md:80`). Los ociosos son aserciones ya construidas que
   nadie está haciendo — coverage signal, no código muerto.
3. **No reintroducir `withFilter(`** en `behat.dist.php`: `BehatSuiteCoverageGateTest:77-88` se pone rojo.
4. **Los pasos de depuración manual llevan comentario en su declaración** (`I print all outbox events`, el
   `#[Given('I reset the outbox context')]` que duplica el hook `#[BeforeScenario]`). No «limpiarlos».
5. **No subir Behat.** `behat/behat v4.0.0-alpha1` es prerelease y `behat.dist.php` está escrito contra
   `Behat\Config\*`; una subida es un cambio **a la suite**, no rutina. Fuera de alcance.
6. **No tocar `GherkinCompatibilityMode::LEGACY`** (`behat.dist.php:59-62`). Es load-bearing y la razón está en
   `:43-58`: bajo `GHERKIN_32` los tags **conservan el prefijo `@`**, y `SecurityContext` compara el nombre desnudo
   (`ANONYMOUS_TAG = 'anonymous'`, `:57`, `:162`) — así que los 28 escenarios `@anonymous` **se autenticarían** y
   devolverían 403 donde esperan 401. Agravante: el `FileCache` de gherkin **no cachea por modo de
   compatibilidad**, así que un cambio puede parecer inocuo hasta que la caché se invalida. Si #430 toca
   `behat.dist.php` (orden de hooks), no arrastres nada más de ese fichero.
7. **`AuditTimelineSearchCursorFunctionalTest::testTimelineAccessPathsAreIndexBacked` falla de forma reproducible
   tras una corrida de `make php.behat`** — siembra con `TRUNCATE`+reinserción sin `ANALYZE`, y el planner elige
   sobre `reltuples` rancio. **NO es regresión de este diff.** Behat además resetea y resiembra la BD, así que los
   conteos de aserciones de `php.unit` derivan por ambiente.

### Cobertura existente y huecos

- `BehatSuiteCoverageGateTest` cubre **que todos los escenarios corran** y **que no haya filtros**. No cubre nada
  sobre el vocabulario: ni pasos ociosos, ni frases casi-duplicadas. Ése es el hueco de D4.
- **Vocabulario ocioso medido hoy** (para T7, y para gastarlo al tocar features): contexts enteros sin usar —
  `JsonErrorContext` (7 patrones) y `JsonSchemaContext` (6). Pasos sueltos ociosos: `the :transportName transport
  should hold :count message(s)`, `I consume … with time limit :seconds`, `I execute :command with options:`,
  `:number outbox event(s) was/were created` (sin cola), `I got the event number :number from the outbox` (sin
  cola), y **los dos pasos de tabla del outbox** (`there should [not] have been an outbox event created
  containing:`). Los `I print …` / `I reset …` son escotillas manuales declaradas, **no** deuda de cobertura.
- **Todo el pinning de prioridades de listener vive en PHPUnit** (`tests/Unit/` + `tests/Functional/`), ninguno en
  Behat. La cadena efectiva es `UnauthenticatedAccessListener` (40) → los auditores (32) →
  `UnknownPayloadMemberListener` (24) → `ExceptionResponder` (16) → CORS Nelmio en `kernel.response` (0). Contexto
  para #591: el pin de prioridades es PHPUnit, el pin **conductual** de 403-antes-de-422 es Behat. Son dos
  controles distintos y ambos existen.
- `features/backoffice/users/erase.feature:113` **siembra una fila `resource_type='User'` por SQL directo** — un
  barrido textual sobre `api/src` no la ve, pero uno que lea `api/features` sí. Relevante si T7 recorre features.
- `api/CLAUDE.md:27` dice que `tools/` contiene instalaciones Composer aisladas para «PHPUnit / **Behat**», y la
  línea 50 del mismo fichero explica que ese puente **desapareció**. Verificado: `api/tools/` tiene 9
  subdirectorios y ninguno es `behat`. **Es una contradicción interna del fichero, en el terreno exacto de este
  lote** — candidata a regla del boy-scout, nombrada en el resumen si se aplica (T8).

### Project Structure Notes

Cero producción: el diff vive en `api/tests/`, `api/features/`, `api/behat.dist.php` y documentación. No toca
`api/src/`, no hay migración, no hay cambio de contrato HTTP. La revisión de seguridad del checklist aplica
mayormente por «no aplica» — decláralo explícitamente en el PR en vez de saltarlo en silencio.

### References

- [`epics-backlog-resolution.md`](../planning-artifacts/epics-backlog-resolution.md) — §BR-1, y §«Criterios de cierre»
- [`api/CLAUDE.md`](../../api/CLAUDE.md) — `:79-82` regla del vocabulario · `:54` prerelease de Behat · `:27` la deriva
- [`docs/rules/testing.md`](../../docs/rules/testing.md) — `:35-46` «Assert the seed before asserting the absence» · `:48-50` vocabulario
- [`br-2-residuos-eje-referencias-persona.md`](br-2-residuos-eje-referencias-persona.md) — precedente de formato, evidencia y pase adversarial
- Arco previo: `ab796333` (#596) · `7503390d` (#597) · `50a82c0e` (#598) · `11ec4259` (#599) · `389f59e3` (#600) · `efe46e33` (#601)

## Dev Agent Record

### Agent Model Used

Claude Opus 5 (1M context) — `bmad-dev-story`.

### Debug Log References

**Colisión de nombres trait↔clase (costó los 9 primeros rojos de Behat).** El helper de mensajes que introduje en
`JsonToolTrait` se llamaba `describe()`, y `OutboxContext` **ya declara un `describe(object $event)` privado**. PHP
resuelve el método de la clase por encima del del trait **sin avisar** — ni error de compilación, ni aviso de
PHPStan (que dio verde) — así que los 9 pasos `The outbox event property … should be equal to …` murieron con un
`TypeError: describe(): Argument #1 ($event) must be of type object, string given`. El síntoma apareció sólo en
Behat, nunca en unit ni en análisis estático. Renombrado a `describeNode()`; medí después los métodos declarados en
los 4 consumidores del trait y ninguno más colisiona.

**Lección de método, no de código:** un trait de aserciones no puede introducir un nombre corto sin medirlo contra
todos sus hosts. El grep de esa comprobación está en el propio historial de esta sesión.

### Completion Notes List

**T0 — D2 y D3 ratificadas con el código delante.** D2: wontfix documentado (0 paths con `::` literal medidos en
los 49 features; los únicos sufijos son `::uuid ::regex ::date ::null ::amount`). D3: Sergio eligió el **rechazo
ruidoso** por encima de la recomendación (defaultear a `async`). Consecuencia asumida y ejecutada: **todo** paso de
outbox nombra su cola, incluidos los dos de tabla y el borrado por tipo.

**T3 — #320, los 13 métodos.** Todas las lecturas de nodo pasan ahora por un `readNode()` privado que (a) quita el
sufijo `::<modifier>` antes de tocar el accessor, (b) convierte un dot-path ausente en fallo de aserción legible y
(c) **sigue lanzando**. Lo tercero es lo que salva AC4.

**AC4 — la trampa no se armó.** `OutboxContext::eventMatchesTable()` usa la excepción como predicado. La sonda
(`OutboxTableMatchTest`) se escribió **antes** de tocar nada y se vio verde; sigue verde después del cambio de #320
y después del de #319 (migrada a la forma cualificada, que es donde D3 dejó el paso). Lleva controles positivos: sin
ellos, un paso que rechazara todo satisfaría igual los tests de propiedad ausente.

**Distinción que el issue no pedía y que evita mentir en el mensaje:** un path *ausente* es expectativa incumplida y
sale como fallo; un selector que el accessor **no sabe parsear** conserva su propia excepción. Llamar «no existe» a
un `node[` mandaría al lector a buscar un campo de payload en vez de una errata. Esto cierra además una vacuidad que
ningún issue registra (**V7**): `jsonPropertyShouldNotExist()` capturaba `Exception` en ancho y daba por «no existe»
cualquier selector roto.

**T5b — las seis vacuidades, y dos supresiones de PHPStan retiradas.**

- **V1** `should have N elements`: `(array) $value` contaba el *cast*, no el nodo. Ahora un escalar o un `null` es
  fallo explícito. `assertEquals` → `assertSame`.
- **V2/V3** `should be true/false`: `FILTER_VALIDATE_BOOLEAN` mapea a `false` todo lo que no reconoce. Ahora se
  exige un booleano JSON de verdad. **Medido antes de endurecer**: los 47 usos en features son `pagination.hasNext`,
  `hasPrev` y `data[0].current`, booleanos reales — Behat 410/410 lo confirma.
- **V4** `should not be valid`: el `catch (Exception) { return; }` aceptaba como «no valida» cualquier excepción,
  incluida la de **no haber podido cargar el esquema** — pasaba con más fuerza justo cuando menos había comprobado.
  Narrado a `UnexpectedValueException`, que es lo único que significa «el validador rechazó el documento» (medida la
  jerarquía de `justinrainbow/json-schema`: ninguna de sus excepciones lo extiende).
- **V5** `should be null` no aplicaba `propertyPostProcessName()`. Resuelto **para los 13** al centralizar en
  `readNode()`, no sólo para el que el issue nombraba.
- **V6** `BackedEnumNodeModifier` localizaba la case buscando el token literal `Enum::`. **No es latente: está roto
  para todos los enums del repo** — ninguno se llama `…Enum`, así que `stripos` devolvía `false`, casteaba a 0 y
  leía la case desde el offset 6 del FQCN. Ahora la case es lo que sigue al único `::`. Su gemelo
  `PropertyPostProcessTrait::propertyPostProcessIsBackedEnum()` **tenía cero llamadores** y duplicaba, roto, el
  predicado que sí funciona 20 líneas más allá (`supportsValue()`): borrado. No es vocabulario de paso, es un helper
  muerto.
- **Dos supresiones fuera de `phpstan.neon`**: `cast.string` y `argument.type` sobre `JsonToolTrait` quedaron sin
  usar al sustituir los casts ciegos por guardias reales. PHPStan las reportó como *unmatched* — así se detectaron.
  Arreglo real, no supresión.

**T4 — #319 bajo D3.** Las seis frases sin cola siguen registradas y **rechazan**, nombrando la canónica; se añaden
cuatro formas cualificadas que faltaban (los dos pasos de tabla y los dos borrados). `Outbox.php` no necesitó tocarse:
`messagesOnQueue()` ya rechazaba una cola no inspeccionable. `messages()` sobrevive y sigue en uso: es la lectura
honesta de «todas las colas» que usan las dos escotillas de diagnóstico, donde ver todo es justamente el punto.

### File List

```
api/tests/Behat/Support/PostProcess/JsonToolTrait.php            (M)  #320 · V1–V5 · V7
api/tests/Behat/Support/PostProcess/PropertyPostProcessTrait.php (M)  V6 (helper muerto borrado)
api/tests/Behat/NodeModifier/Scalar/BackedEnumNodeModifier.php   (M)  V6
api/tests/Behat/Context/OutboxContext.php                        (M)  #319 (D3)
api/features/backoffice/bank/dispatch_event.feature              (M)  #319 — :152 y :191
api/tools/phpstan/phpstan.neon                                   (M)  2 supresiones retiradas
api/tests/Unit/Behat/Context/OutboxTableMatchTest.php            (A)  sonda AC4 + rechazos de D3
api/tests/Unit/Behat/Support/PostProcess/JsonNodeAssertionsTest.php       (A)  #320 (los 13) + V1–V5 + V7
api/tests/Unit/Behat/Support/PostProcess/Fixtures/JsonAssertions.php      (A)  host del trait
api/tests/Unit/Behat/NodeModifier/Scalar/BackedEnumNodeModifierTest.php   (M)  V6
_bmad-output/implementation-artifacts/br-1-behat-vocabulario-falsabilidad.md (M)
_bmad-output/implementation-artifacts/sprint-status.yaml                     (M)
```

> Deriva final pendiente de `git diff --name-only $(git merge-base origin/main HEAD)...HEAD` en el commit de cierre.

### Gates

**Tanda 1 — 2026-08-09, tras T3 + T4 + T5b** (corrida fresca, exit code impreso):

| Gate | Exit | Resultado |
|---|---|---|
| `make php.stan` | **0** | No errors |
| `make php.unit` | **0** | 2507 tests, 10251 assertions, 2 skipped |
| `make php.behat` | **0** | 410 escenarios / 3806 pasos, todos verdes |

Pendientes de tanda final: `make php.quality`, `make php.lint.gherkin`.

### Rojos provocados

**Medición intermedia** (se re-mide al final; el conteo se pudre con el propio diff).

| Qué se rompió | Cómo | Rojos | De |
|---|---|---|---|
| `JsonToolTrait` completo → bytes de `f2e80a9d` | copia de bytes desde `tmp/br1-falsification/`, **nunca `git checkout --`** | **34** (33 failures + 1 error) | 44 |
| `BackedEnumNodeModifier` → bytes de `f2e80a9d` | idem | **3** | 4 |

Restauración verificada con `cmp -s` en ambos casos: bytes idénticos.

**Rojo no provocado sino encontrado:** los 9 pasos de Behat que murieron por la colisión `describe()` (ver Debug
Log). Es la evidencia de que el gate de Behat no es decorativo aquí — PHPStan y los unit tests dieron verde con el
defecto dentro.

### Pase adversarial

_Pendiente — T10. Se ejecuta y se registra **aquí** antes de `gh pr create`._

### Change Log

| Fecha | Cambio |
|---|---|
| 2026-08-09 | T0: D2 (wontfix) y D3 (rechazo ruidoso) ratificadas con el código delante |
| 2026-08-09 | T3 + T5b: #320 sobre los 13 métodos, V1–V7, sonda AC4 verde antes y después |
| 2026-08-09 | T4: #319 bajo D3 — 6 rechazos, 4 formas cualificadas nuevas, 2 líneas de feature |
