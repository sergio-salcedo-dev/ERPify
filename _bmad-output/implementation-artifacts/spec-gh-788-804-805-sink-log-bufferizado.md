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

> **Corrección de hecho sobre el bloque de arriba, que no se edita por estar congelado.** La frase «dos formas
> de argv aquí llevan lo que no puede llegar ahí» describe el CRITICAL de `:46` como alcanzable por un fallo de
> esos comandos. No lo es: los tres capturan `Throwable`, así que sólo llegan al DEBUG de `:67`, que se
> descarta sin `passthru_level`. El productor vivo de `:46` es una invocación que la consola no puede *bindear*
> (opción desconocida, aridad incorrecta, nombre mal escrito). Lo midió el pase adversarial; el detalle está en
> su sección y en §7 del checklist.

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

**Independiente, dos lentes en paralelo, contexto fresco y antes de que exista la PR.** Ninguna de las dos
escribió una línea de este código. Lente A atacó **los gates y el código** (falsos verdes, bypass del
processor, PHPStan `level: max` calibrado contra el resto del directorio); lente B atacó **las afirmaciones y
el argumento de privacidad** (¿cierra de verdad lo que dice?, ¿qué prosa vuelve falsa?, ¿qué pidieron los
issues que no ha llegado?). Entre las dos, **cuatro GRAVE y ocho MEDIUM**, cada uno con reproducción medida.

Una pasada previa del autor había encontrado dos defectos (el `#[When]` y el servicio malformado) y está
registrada como lo que era: autocertificación, que la regla dice que no cuenta. **No confirmó el cambio: lo
reescribió** — el processor, los tres gates y dieciocho sitios de prosa.

### Lo que derribó, y era una fuga real

1. **La regla de redacción fallaba ABIERTA con cualquier argv sin espacio ASCII, y eso es una fuga en
   CRITICAL.** `\s` sin el modificador `u` es la clase de BYTES `[ \t\n\r\f\v]`, así que un separador
   Unicode dejaba todo el argv como **un** token, y un token único se devolvía verbatim. Medido, cuatro
   formas: `identity:gdpr:erase-subject=<uuid>`, `organization:administrator:create:alice@example.com`, y el
   mismo comando separado por U+00A0, U+2007 o U+3000. Y es alcanzable sin error del operador:
   `Application::run()` despacha `ConsoleErrorEvent` **sin comando** en `CommandNotFoundException`, de modo
   que el token entero es entrada del operador y aterriza dos veces (contexto y mensaje interpolado). Cerrado
   con un split `/[\s\p{Z}]+/u` y una comprobación de FORMA sobre el token superviviente: se conserva sólo si
   parece un nombre de comando.
2. **El processor fallaba ABIERTO con un portador que no puede leer, y el test lo tenía PINCHADO COMO
   CORRECTO.** `context['command'] = ['identity:gdpr:erase-subject', '<uuid>']` se devolvía intacto y
   `JsonFormatter` emitía el array verbatim — mientras `PersonDataRedactionProcessor` argumenta explícitamente
   lo contrario para este mismo caso («redactar el valor sea cual sea su tipo es lo que cierra eso»). Ahora
   falla **cerrado**, y el caso del test se invirtió.
3. **Siete ficheros afirmaban «ningún compose declara un driver `logging:`», que este cambio vuelve falso, y
   el diff no tocaba ninguno.** Al verificarlo resultaron ser **dieciocho** sitios, incluidos docblocks de
   gates y dos documentos. Corregidos con una cláusula quirúrgica: la rotación acota TAMAÑO, y el TTL y el
   dueño siguen sin existir.
4. **`PersonDataRedactionProcessor` seguía nombrando `{command}` como la fuga viva que su hermano no alcanza,
   y contaba tres processors.** Son cuatro, y esa fuga la cierra precisamente la clase nueva — que además
   *citaba* esa frase como si siguiera en pie. Corregido en los tres puntos.

### Los cuatro falsos verdes de los gates, cada uno reproducido y ahora en rojo

- **El universo del gate de amplificación era una lista a mano de cuatro clases** bajo el rótulo «every
  installed class that logs on one of those channels». El framework etiqueta **ocho** servicios sobre el canal
  `messenger`; plantar `$this->logger?->error('…', $context + ['payload' => $message])` en
  `HandleMessageMiddleware` — que tiene el objeto mensaje en la mano — dejaba el gate en `OK`. El universo se
  **deriva** ahora de los tags `monolog.logger` de la configuración vendor instalada.
- **La forma PSR-3 `->log($level, …)` era invisible.** Cambiar un `->info(` por `->log('critical', ` en una
  clase pinchada como «no puede activar» daba `OK`. Ahora esa forma se rechaza de plano: su nivel es un
  argumento que ningún matcher por nivel puede clasificar.
- **La comprobación del payload era una denylist de dos deletreos.** Añadir `'payload' => $message` al mismo
  array daba `OK`. Ahora se afirma el literal del contexto **entero** — la misma lección que el propio
  processor aplica al rechazar enumerar comandos sensibles.
- **`channels:` sólo se leía en su forma denylist.** Reescribir el handler como `channels: ["request",
  "security"]` saca ambos canales del buffer, y tanto este gate como `MonologExclusionDeclarationGateTest`
  seguían verdes. Ahora se detecta la forma allowlist y la pertenencia se invierte.

### Y dos huecos más, de otra clase

- **`include:` derrotaba entero el gate de retención.** Una línea trayendo un `compose.extra.yaml` con un
  `otel_collector` sin cota: `docker compose config` lo mostraba sin bloque `logging:` y el gate reportaba
  `OK (10 tests, 72 assertions)` — porque también satisface `EXPECTED_SERVICES`, que es la propia guarda
  anti-vacuidad. Ahora se rechaza de plano en vez de medio resolverse.
- **Un bloque `when@prod:` desenrolaba el processor con el gate verde.** El gate de enrolamiento leía sólo la
  clave `services:` raíz; `MicroKernelTrait` importa además `config/services_{env}.yaml` (y `services_dev` y
  `services_test` ya existen). Añadir `autoconfigure: false` + `tags: []` en un `when@prod:` daba `OK`. Ahora
  se leen los bloques `when@` y los ficheros por entorno.

### Lo que atacaron y resistió

- **La afirmación de ORDEN es cierta**, verificada en las fuentes: `AddProcessorsPass` empuja un
  `monolog.processor` sin scope sobre cada `monolog.channel_logger`, `LoggerChannelPass` corre antes, y
  `MonologExtension` desactiva `process_psr_3_messages` en cualquier handler que declare `handler:` — así que
  `PsrLogMessageProcessor` vive sólo en `nested`. Medido de punta a punta: `context.command`, el hueco
  `{command}` interpolado, el email y la contraseña desaparecen.
- **Sin stack trace y sin argumentos**: `zend.exception_ignore_args = On` está fijado en
  `api/frankenphp/conf.d/10-app.ini` para todos los entornos, e `include_stacktraces` no está puesto.
- **La conclusión de la enumeración de #804 es correcta**: un solo `critical` en toda la pila messenger, y el
  payload es realmente sólo `$message::class`.
- **El merge de Compose y la efectividad de #805**: `docker compose config` muestra `json-file / 10m / 5` en
  los cinco servicios de prod y en los cinco de dev.
- **`ArgvInput` no derrota la regla** en ninguna invocación multi-token: el escapado entrecomilla el nombre,
  `getInputString()` lo desentrecomilla, y un argumento con espacios sólo produce más fragmentos descartados.
- **Ningún test existente se rompe**: `PersonDataRedactionArrivalTest` filtra por clase, y
  `FingersCrossedActivationIntegrityTest` barre processors que DESTRUYAN `context['exception']`, que este no
  toca.

### Lo que sigue abierto, y queda escrito en §7 en vez de insinuarse cerrado

- **El texto del propio throwable.** Medido con una pipeline Monolog real: un valor que la consola rechazó
  sobrevive **tres** veces en el mismo registro (`context.message`, `context.exception.message` y el hueco
  `{message}`). Por eso el encabezado de §7 dice «under `command`» y no «no longer writes its argv».
- **Un valor pegado al nombre del comando** usando sólo caracteres que un nombre de comando admite (un uuid
  tras un `-` o un `:`) sobrevive a la comprobación de forma. No puede llevar una dirección ni una contraseña
  con símbolos. Cerrarlo exige casar contra los nombres de comando REGISTRADOS, lo que acopla el processor al
  registro de la consola: anotado como seguimiento en vez de hecho a ciegas.
- **PostgreSQL escribe al mismo sumidero.** `log_min_error_statement = error` vuelca la sentencia infractora
  al stderr del contenedor `database`, y una violación de unicidad lleva `DETAIL: Key (email)=(…)`. El eje de
  retención lo cubre; el de contenido se detiene en Monolog. Ningún issue de los tres lo vio.
- **La rotación acota TAMAÑO, no EDAD**, y el argv sigue en la lista de procesos del host.

### Verificación que NO se pudo ejecutar aquí, y es una limitación de este registro

El entorno remoto no tiene daemon de Docker, y el proxy de egress deniega con **403** las descargas de
archivos zip de GitHub (`api.github.com/.../zipball`, `codeload.github.com`), que es como Composer instala
`phpstan/phpstan` — cuya entrada en el lock tiene `source: null`, así que `--prefer-source` no lo salva. En
consecuencia:

- **`make php.stan`, `make php.quality` y `make php.deptrac` no se han ejecutado.** La lente A sí consiguió
  correr `phpstan.phar` a `level: max` de forma aislada sobre el directorio de gates y calibrarlo contra los
  ficheros preexistentes; sus cinco hallazgos (`offsetAccess.nonOffsetAccessible` encadenado sobre `mixed`,
  `argument.type` en `Level::fromName`, `method.alreadyNarrowedType` por un `@var` que afirmaba en vez de
  declarar, y un `offsetAccess.notFound` sin `assertArrayHasKey`) están corregidos. Eso no equivale a haber
  pasado el gate del repo.
- **Las suites Functional y Behat no se han ejecutado** (sin base de datos, y con `behat/behat`,
  `justinrainbow/json-schema` y `DoctrineFixturesBundle` ausentes del árbol instalado).
- **El enrolamiento en el contenedor COMPILADO sigue sin verificarse.** Los gates leen ahora la declaración
  raíz *y* los overrides por entorno, que era el vector concreto; que el processor acabe empujado en cada
  logger de canal es una propiedad del contenedor, y el instrumento para eso es
  `PersonDataRedactionArrivalTest`, que aquí no arranca.

Lo que sí se ejecutó: la suite `tests/Unit` completa, con línea base. Sin los cambios, **2665 tests, 20
errores y 9 fallos**; con ellos, **2721 tests, los mismos 20 errores y los mismos 9 fallos** — los 29 son de
paquetes dev ausentes, idénticos en ambas ramas. Y cada aserción nueva se falsó mutando el árbol y observando
la fila enrojecer.
