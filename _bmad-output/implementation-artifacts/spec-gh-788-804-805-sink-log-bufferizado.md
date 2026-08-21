---
title: 'Dar dueño al sink de log bufferizado: argv de consola (#788), topología de canales (#804), retención acotada (#805)'
type: 'security'
created: '2026-08-21'
status: 'in-review'
review_loop_iteration: 0
context: []
baseline_commit: '3f8145c'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problema.** `php://stderr` bajo el driver json-file de Docker es donde el handler `nested` de prod vuelca el
buffer `fingers_crossed`, y ese sumidero no tenía dueño en **ninguno** de sus dos ejes. Tres issues, un
subsistema:

- **#788 — qué escribe.** El `ErrorListener` de `symfony/console` registra el **argv completo** de un proceso
  que falla: `:46` escribe `'command' => (string) $event->getInput()` a CRITICAL en `ConsoleErrorEvent`, y
  `:67` la misma cadena a DEBUG en **cualquier** salida distinta de cero. El canal `console` está dentro del
  handler bufferizado de prod (excluye sólo `deprecation` y `observability`) y CRITICAL está por encima de
  `action_level: error`, así que ese registro no se limita a ocupar el buffer: **lo vuelca**. Dos formas de
  argv aquí llevan lo que no puede llegar ahí — `identity:gdpr:erase-subject <uuid>` mete el identificador de
  una persona en el registro del borrado que el comando existe para ejecutar, y
  `organization:administrator:create <email> <password>` mete una contraseña **en claro** junto a la dirección
  a la que pertenece.
- **#804 — qué más amplifica.** `console` y `messenger` tienen productores propios por encima del umbral,
  a diferencia de la superficie HTTP, cuya línea 4xx es `warning` y queda por debajo.
- **#805 — cuánto se conserva.** Ningún fichero compose declaraba un bloque `logging:`, así que cada
  contenedor corría el driver json-file en su default **sin cota**: cualquier registro que llegara allí
  sobrevivía a todos los demás controles de retención de la aplicación (prune de `audit_log`, prune de
  `messenger_messages`, casos de uso de borrado GDPR), que sí tienen dueño.

**Enfoque.** Un processor por estructura para #788, una decisión registrada y pinchada para #804, y una cota
declarada más gate para #805. Decisiones del usuario tomadas antes de escribir código: conservar el nombre del
comando y borrar el resto; **no** tocar el argumento posicional de contraseña (residual `ps` registrado); y
rotación en los tres compose con gate.

</frozen-after-approval>

## Lo entregado

| Issue | Mecanismo | Gate |
|---|---|---|
| #788 | `ConsoleCommandRedactionProcessor` — reduce `command` al NOMBRE + centinela | `ConsoleCommandCarrierGateTest`, `ConsoleCommandRedactionProcessorTest` |
| #804 | Decisión registrada: sin exclusión de canal; el único portador de datos personales era `command` | `BufferedChannelAmplificationGateTest` |
| #805 | `logging:` acotado (`json-file`, `10m` x `5`) en los tres compose | `BoundedContainerLogRetentionGateTest` |

Targets nuevos: `make php.lint.log-carriers`, `make php.lint.log-retention`, ambos cableados en
`php.quality` y `php.quality.dry-run`.

**Por estructura, nunca por enumeración** es la elección que carga el peso en #788. La alternativa descartada
—una lista de comandos sensibles cuyos argumentos se redactan— se lee como de mayor fidelidad y es la forma que
**ya falló dos veces aquí**: el filtro `query` de Caddy casaba **nombres** de parámetro y dejó pasar nueve
deletreos de un valor, y #389/#803 cerraron la misma clase. Bajo una lista, el comando que se añada mañana va
en claro por defecto y nada enrojece.

## Adversarial pass

**Alcance y honestidad sobre quién la hizo.** Esta pasada la ejecutó **el propio autor del cambio**, en la
misma sesión y con el mismo contexto. `CLAUDE.md` → «Security review on every change» → Process dice
explícitamente que la autocertificación **no cuenta**: el gate es una lectura hostil por alguien distinto del
autor (un contexto fresco, otro modelo o una persona). Por tanto **este registro no satisface la regla por sí
solo** y queda anotado como lo que es. No se abre PR sobre esta base sin una pasada independiente o un
`ADVERSARIAL_PASS_ACK` explícito del usuario. Se registra igualmente porque encontró defectos reales y
arreglarlos costó una edición en lugar de una segunda PR, que es exactamente lo que la regla persigue.

### Lo que derribó, y se arregló en el mismo diff

1. **El processor se podía condicionar fuera de producción y todo seguía verde.** La clase no llevaba ninguna
   guarda contra `#[When]`/`#[WhenNot]`, que es el modo de fallo que `CLAUDE.md` documenta bajo «Declaring a
   class out of production» y que `PersonDataRedactionArrivalTest` sí pincha para la regla hermana. Un
   `#[When(env: 'dev')]` habría dejado el test del processor, el test del carrier y toda la suite en verde
   mientras quitaba la redacción del **único** entorno cuyo sumidero no tiene dueño. Cerrado con
   `theProcessorIsNotConditionedIntoOrOutOfAnyEnvironment`, y **falsado**: plantando el atributo, la fila
   enrojece con «the rule is conditioned into named environments, so production may not have it».
2. **El gate de retención leía un servicio malformado como conforme.** Una clave de servicio sin mapa debajo
   parsea a `null`, y `$definition['logging'] ?? $base[$name]['logging'] ?? null` la habría hecho caer al
   segundo eslabón — es decir, un servicio roto se leía como «hereda del base» en vez de como el fichero
   malformado que es. Cerrado con un `assertIsArray` por servicio antes de resolver la herencia.
3. **Hueco de tipos para PHPStan `level: max`.** El método guiado por `DataProvider` recibía `array $pinned`
   sin `@param list<string>`, que en `level: max` es un `missingType.iterableValue`. Añadido junto con
   `@param class-string $emitter`, retirando un `@var` en línea que afirmaba el tipo en vez de declararlo.

### Lo que atacó y resistió

- **Colisión de la clave `command`.** Si algún emisor propio escribiera un `command` con otro significado, el
  processor le borraría los argumentos. Medido: `grep -rn "'command' =>" api/src/` no devuelve **ningún**
  emisor (la única coincidencia es la propia docblock del processor). El portador es exclusivamente del
  framework.
- **La premisa de `FingersCrossedActivationIntegrityTest`.** Ese test recorre los processors del contenedor y
  exige que ninguno destruya el `HttpExceptionInterface` que lee `HttpCodeActivationStrategy`. El processor
  nuevo sólo toca `command` y devuelve el registro intacto cuando no hay nada que redactar, así que la
  exclusión por código HTTP sigue siendo legible. No pincha un CONJUNTO de processors, así que añadir un
  tercero no lo enrojece.
- **El `assertCount(1, ...)` de `PersonDataRedactionArrivalTest`.** Filtra por `instanceof
  PersonDataRedactionProcessor`, no por cardinalidad total, así que el processor nuevo no lo altera.
- **Semántica de merge de Compose.** Verificada con el instrumento real y no con mi reimplementación:
  `docker compose -f compose.yaml -f compose.prod.yaml config` resuelve el bloque acotado en **los cinco**
  servicios de prod, incluido `scheduler_worker`, que es el único que el overlay introduce.
- **Los tres gates enrojecen.** Falsados uno a uno mutando el árbol y observando la fila: `mailpit` sin bloque
  → rojo; cota de prod servida por el entorno (`${LOG_MAX_SIZE:-10m}`) → rojo por dos vías; `Worker` con un
  productor activante plantado → rojo; el payload devuelto al registro del retry listener → rojo; la etiqueta
  `monolog.processor` retirada → rojo; el portador `command` renombrado en vendor → rojo.

### Lo que este cambio deja abierto, y no está cerrado por él

- **`error` y `exception` llevan el mensaje del propio throwable en todos los canales.** Un throwable compuesto
  a partir de un dato personal llega a este sumidero por ahí. Indefendible por construcción con un processor,
  misma clase que el residual cuatro de §7, sin regla en este repositorio.
- **La rotación acota TAMAÑO, no EDAD.** Desaloja por volumen, así que un despliegue ocioso conserva su línea
  más antigua indefinidamente. Sigue sin haber TTL ni vía de borrado, y por eso la entrada de #805 en §7 queda
  **sin marcar**.
- **El argv sigue en la lista de procesos del host.** Ningún processor la alcanza, así que una contraseña
  pasada posicionalmente se expone a todo proceso local con independencia de esta regla.
- **La degradación declarada del processor:** una invocación que EMPIEZA por una opción global pierde también
  el nombre, porque localizarlo pasada una opción exige la definición de entrada del propio comando. Medido:
  nada en este repositorio invoca esa forma.

### Verificación que NO se pudo ejecutar aquí, y es una limitación de este registro

El entorno remoto no tiene daemon de Docker, y el proxy de egress deniega con **403** las descargas de archivos
zip de GitHub (`api.github.com/.../zipball`, `codeload.github.com`), que es como Composer instala
`phpstan/phpstan` — cuya entrada en el lock tiene `source: null`, así que `--prefer-source` no lo salva. En
consecuencia:

- **`make php.stan`, `make php.quality` y `make php.deptrac` no se han ejecutado.** Los huecos de tipos se
  buscaron a mano; eso no sustituye al analizador.
- **Las suites Functional y Behat no se han ejecutado** (sin base de datos, y con `behat/behat` y
  `justinrainbow/json-schema` ausentes del árbol instalado).
- **El enrolamiento en el contenedor COMPILADO no está verificado.** El gate lee la declaración de
  `config/services.yaml`; que el processor acabe empujado en cada logger de canal es una propiedad del
  contenedor, y ahí `PersonDataRedactionArrivalTest` es el instrumento — que aquí no corre.

Lo que sí se ejecutó: la suite `tests/Unit` completa, con línea base. Sin los cambios, **2665 tests, 20 errores
y 9 fallos**; con ellos, **2701 tests, los mismos 20 errores y los mismos 9 fallos** — los 29 son de paquetes
dev ausentes, idénticos en ambas ramas. Los 37 tests nuevos pasan (127 aserciones).
