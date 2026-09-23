# ADR — The aggregate receives the instant, not the clock

> **Status:** accepted · **Date:** 2026-09-23 · **Scope:** `api/src` — `AggregateRoot`, every aggregate factory and
> mutator that stamps time, `DomainEvent::$occurredOn`, `Shared/Images` `Image`, the use cases that mint or mutate
> them, and the PHPUnit clock harness.

## Contexto

La aplicación leía la hora de **dos** fuentes que podían discrepar, más una tercera que ninguna alcanzaba:

- `AggregateRoot::__construct()` y 14 mutadores sobre 5 entidades (más `Image::__construct`) estampaban
  `createdAt`/`updatedAt` desde un **reloj ambiental estático**, `SystemClock::now()`.
- Los casos de uso calculaban sus propios instantes (caducidades, ventanas) desde un **puerto `Clock` inyectado**.
- `DomainEvent::$occurredOn` caía a `new DateTimeImmutable()` cuando el emisor no lo pasaba: el reloj de pared.

En producción `SystemClockInitializer` copiaba el `clock` del contenedor sobre el ambiental en `kernel.request`,
`console.command` y cada mensaje del worker, así que las dos fuentes coincidían. En la suite no: al fijar el reloj
ambiental en `2050-06-15` mientras cada test unitario inyectaba su propio doble, `StartSessionTest` construía una
`Session` con `createdAt = 2050-06-15` y `expiresAt = 2026-07-17` — una caducidad ~24 años **anterior** a su propia
creación — en un test verde. 36 de los 54 ficheros que construían un `FixedClock` corrían con dos fuentes
separadas por 24 años. Ninguna aserción comparaba las dos, así que nada se ponía rojo.

## Decisiones

**D1 — El dominio recibe un valor, nunca un servicio.** `AggregateRoot::__construct(DateTimeImmutable $now)`, y
cada factoría o mutador que estampa tiempo toma el instante como argumento. La capa de aplicación lee su `Clock`
inyectado **una vez por operación** y pasa el `DateTimeImmutable` hacia dentro. Leer un reloj es E/S y una decisión
sobre *cuándo*; pertenece al borde que ya la posee. Con ello un agregado es función de sus entradas, y `Session`
deja de *recibir* `$now` para responder (`isExpired`, `isActive`) mientras *alcanzaba un global* para registrar.

Una lectura por operación incluye lo que la operación compone. `CreateUser`, `InviteUser` y `GrantMembership` son
pasos de un alta (`SendInvitation`, `organization:administrator:create`), nunca puntos de entrada, así que
reciben el instante de su orquestador en lugar de leer un reloj propio: identidad, membresía e invitación llevan
una sola lectura. Lo mismo vale para un puerto que escribe sello: `SessionRepository::revokeAllForUser()` y
`revokeOthersForUser()` reciben el `$now` con el que el caso de uso registra el evento, porque el `UPDATE` masivo
no hidrata agregados y, leyendo su propio reloj, estampaba `revokedAt` en un instante distinto del `occurredOn` del
hecho que lo anuncia. Un adapter sigue leyendo el `Clock` inyectado para *consultar* (qué sesión es admisible
ahora), nunca para *estampar*.

**D2 — `DomainEvent::$occurredOn` es obligatorio y va antes que `$eventId`.** El emisor pasa el instante de la
operación que produjo el hecho, así que un evento no puede discrepar de los sellos que el agregado registra a su
lado. `eventId` sigue siendo opcional (replay y reintentos conservan su identidad).

**D3 — Se borran `SystemClock`, `NativeClock`, `SystemClockInitializer` y `Timestamped::setCreatedAt/setUpdatedAt`.**
No hay segunda fuente que reconciliar. Los dos setters tenían 0 llamadas en `api/src` y 23 en `api/tests`: existían
solo para esquivar el sello ambiental, y tenían la forma que el checklist de seguridad prohíbe (setters de campos de
auditoría en la entidad).

**D4 — Una sola fuente en `api/src`, con guarda.** `make php.lint.wall-clock` (`WallClockReadGateTest`, reglas en
`WallClockReadRulesGateTest`) rechaza cualquier lectura del instante que no pase por el puerto: `new
DateTimeImmutable()` sin argumento o con literal relativo, `time()`/`microtime()`/`date($fmt)`, y el reloj global
de Symfony alcanzado estáticamente. Levantarla destapó dos fuentes más en infraestructura —
`PruneFailedMessagesHandler` y `PruneHandledDomainEventsHandler` calculaban su umbral con `new
DateTimeImmutable('-N days')`—, que ahora leen el `Clock` normalizado a UTC (la zona en que se escriben sus
columnas). El de claims gana además el suelo de retención que el otro ya tenía: una ventana de cero o negativa
ponía el umbral en el futuro y borraba en el primer tick los claims de eventos aún en reintento. Una excepción
viva: `RateLimitSnapshot` resta `\time()` del `getRetryAfter()` del limitador porque el limitador de Symfony
sella sus ventanas con `microtime()`/`time()`; el delta tiene que tomarse en ese marco. La excepción se rechaza en
cuanto el fichero deja de leer el reloj.

**D5 — El arnés de PHPUnit fija una sola fuente.** `FreezeSystemClockExtension::pin()` fija ya solo el reloj global
de Symfony (el que lee el `clock` del contenedor). Un test que no se preocupa por la hora pasa
`Tests\Double\Clock\SuiteInstant::now()` —el mismo instante—, las madres lo usan por defecto, y un test que inyecta
su propio `FixedClock` entrega **ese** instante a cada agregado que construye. Las fixtures de Alice obtienen el
suyo de `Tests\DataFixtures\SeedInstant` (el reloj global, el que la aplicación lee); tres factorías finas —`Bank`,
`BankAccount`, `Organization`— se añaden a las cuatro que ya existían porque YAML no puede nombrar un instante.

### Alternativas descartadas

- **A — Parchear los tests.** Ya estaba aplicado a mano en 19 de 54 sitios sin gate; el siguiente test reabre el
  hueco.
- **B — Inyectar `Clock` en el agregado.** `Clock` no es una dependencia prohibida (es un puerto propio,
  [`external-dependencies-in-domain.md`](external-dependencies-in-domain.md)); la objeción es de alcance y
  seguridad. Un parámetro de constructor arregla 1 de 16 lecturas: Doctrine hidrata sin ejecutar el constructor
  (`ImagePersistenceTest` lo fija), así que no llega a los 14 mutadores de entidades ya cargadas. La variante con
  propiedad `private Clock $clock` rompe producción con PHPStan verde: se asigna en el constructor, lo que satisface
  `checkUninitializedProperties`, y el primer mutador sobre una entidad cargada lanza *Typed property must not be
  accessed before initialization* en la ruta de escritura.
- **Listener Doctrine `prePersist` / Timestampable.** `createdAt` viaja en payloads de eventos publicados
  (`BankSnapshot.createdAt`; el `occurredOn` de `SessionStarted` es `getCreatedAt()`), y los eventos se registran
  *antes* del flush: el listener estamparía después de que el payload hubiera copiado el valor. Y ambas columnas son
  de ordenación keyset, no decoración.
- **La guarda primero, como contención.** Medido: ponía en rojo 36 de 54 ficheros el primer día — un bloqueo de la
  migración, no contención — y contradecía lo que el pin había comprado. Es correcta como **último** paso, cuando ya
  existe una sola fuente.

## Consecuencias

- **El coste conservado.** Los sellos de un agregado de test son ya una elección del test. Los que no se preocupan
  siguen compartiendo `SuiteInstant`, así que las ordenaciones por `createdAt` entre ellos siguen cayendo al
  desempate por id; un test que pasa instantes distintos vuelve a ordenar por tiempo.
- **Un diff ancho y mecánico, y atómico.** El estático no se puede borrar hasta que cada lector toma el parámetro,
  y el parámetro no existe hasta que `AggregateRoot` lo declara; PHPStan pone en rojo cada llamada sin convertir.
- **Producción apenas cambia de comportamiento.** El inicializador se borra porque ya no hay segunda fuente, no
  porque estuviera mal. Lo que sí cambia es lo que las dos lecturas separaban por microsegundos (el `revokedAt` de
  una revocación masiva frente al `occurredOn` de su evento; los sellos de un alta compuesta) y el suelo de
  retención de los claims, que rechaza una ventana que antes aceptaba.

**Lo que esto no afirma.** Nada sobre el reloj de Postgres, el bootstrap de Behat ni un `time()` alcanzado por un
callable. La guarda prueba que `api/src` tiene una fuente; no dice nada de un test que fabrica un literal de fecha,
que sigue siendo cosa de revisión. Sus puntos ciegos completos están en `api/tests/Support/WallClockReads.php`.
