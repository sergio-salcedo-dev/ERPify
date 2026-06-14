# ADR — Contextos Behat para los seams de eventos y observabilidad

> **Estado:** propuesto · **Fecha:** 2026-06-14 · **Ámbito:** `api/tests/Behat/Context` +
> `api/tests/Behat/Support` + un doble de logger de test + config de transporte por suite Behat.
>
> Sigue a [`event-driven-architecture.md`](./event-driven-architecture.md) (PR #277): aquel ADR cerró
> el dual-write evento→outbox; **este fija cómo se asevera ese seam — y las líneas de log del contrato
> de error — de forma declarativa en Behat**, tomando como modelo dos bundles internos
> (`event-bundle-dev`, `test-bundle-dev`) sin arrastrar su stack.

## Contexto

ERPify ya tiene infra Behat madura: `Support/{Json,PostProcess,Tool}` (traits `JsonToolTrait`,
`TableShouldMatchTrait`, `PropertyPostProcessTrait`, `Json`), `NodeModifier/*`, y contextos
`EntityManager`/`Doctrine`/`Fixtures`/`Http*`/`SqlQuery`/`RateLimit`. Lo que **falta** son pasos
declarativos para dos seams que ya son de primera clase en el dominio:

1. **Publicación de eventos al outbox** — tras PR #277 los casos de uso de `Bank` publican por
   `EventBus` y el gate `php.lint.event-bus` lo hace load-bearing, pero no hay forma de aseverar en una
   `.feature` que "se publicó `BankCreatedDomainEvent` con `bankId=X`" sin caer a SQL crudo.
2. **Líneas de log del contrato RFC 9457** — el contrato de errores emite una línea por error
   (`exception_category`, redacción, niveles; NFR26) y hoy no se asevera declarativamente.

Los bundles chiliz traen contextos maduros para ambos (`SymfonyMessengerContext`, `OutboxContext`,
`SymfonyMessengerConsumeContext`, `LoggerContext`, `RateLimiterContext`), pero asumen **su** stack: JMS
Serializer, una entidad `OutboxEvent` propia, tipos `Chiliz\*` / `HumanReadableIntEnumInterface`, un
wrapper `Chiliz\Utilities\RateLimiter` y anotaciones `@Then`. Portarlos verbatim importaría todo eso.
La decisión es **qué capacidad portar, cómo re-anclarla en la infra ERPify, y qué descartar**.

## Decisiones

### D1 — Portar capacidades, no clases: re-anclar en la infra ERPify

Cada contexto portado usa lo que ERPify ya tiene, no lo que chiliz traía:

| Chiliz usa | ERPify usa | Por qué |
|---|---|---|
| `JMS\Serializer` + `ArrayTransformerInterface` | **Symfony Serializer** | Es el stack de la app; `SymfonyMessengerContext` ya serializa con él |
| `Chiliz\TestBundle\Json\Json`, traits `Chiliz\*` | `Support/Json/Json`, traits `Support/PostProcess/*` propios | Ya existen y son la fuente de verdad de aserción JSON |
| Anotaciones `@Then`/`@Given` | Atributos `#[Then]`/`#[Given]` (`Behat\Step\*`) | Estilo de la casa (ver `RateLimitContext`) |
| `LoggerMock`, `HumanReadableIntEnumInterface`, `Chiliz\Utilities\RateLimiter` | Dobles nativos / nada | No existen en ERPify; arrastrarlos acopla la infra de test a otro dominio |

### D2 — `MessengerContext`: aseverar publicación contra el transporte in-memory

Nuevo `MessengerContext` (modelo `SymfonyMessengerContext`): pasos para contar mensajes en un
transporte, aseverar instancia y campos. En `when@test` los transportes son `in-memory://?serialize=true`
(ver `config/packages/messenger.yaml`), así que introspecciona `InMemoryTransport::getSent()`. Es el
**compañero declarativo del gate `php.lint.event-bus`**: las features de `Bank` aseveran
"se publicó `BankCreatedDomainEvent` en `async`" sin tocar SQL.

Descartado: arrastrar el truco del `lastSentObject` dictionary del contexto chiliz (su comentario de
"3 horas perdidas" es un workaround de su container de test; ERPify no necesita almacenar el mensaje en
el contexto, así que no reproduce el problema).

### D3 — `OutboxContext`: **sin** entidad `OutboxEvent`; dos modos sobre el transporte real

El `OutboxContext` chiliz asume una entidad `OutboxEvent` + tabla outbox propia leída vía
`EntityManager`. ERPify **no la tiene**: su outbox **es** la tabla del transporte Doctrine
(`messenger_messages`), y en test ni eso — es in-memory. Opciones:

- **(a) Introducir una entidad `OutboxEvent`** → **descartado**: contradice el diseño shippeado en PR
  #277 (outbox = transporte Doctrine) y duplica la fuente de verdad.
- **(b) Aseverar siempre contra in-memory** (= D2) → cubre "se encoló", pero **no prueba la atomicidad
  real**: el `InMemoryTransport` no participa de la transacción de la DB, así que no demuestra que el
  `INSERT` del transporte commitea en la **misma** transacción que el agregado (la garantía D3 del ADR
  de eventos).
- **(c) Elegida** — un escenario/suite Behat **opt-in** que vuelca el transporte `async` a `doctrine`
  (tabla real `messenger_messages`). El `OutboxContext` portado consulta `messenger_messages`
  (no una entidad), decodifica el envelope con el `SerializerInterface` de Messenger y asevera
  tipo/propiedades del `DomainEvent`. Permite probar la **atomicidad**: un rollback deja **0** filas de
  agregado **y 0** de outbox.

Trade-off explícito: (c) toca DB real → más lento y exige config de transporte por suite. Se **reserva
a escenarios de atomicidad/outbox**; para el grueso ("¿se publicó el evento?") basta D2. El modo
doctrine añade `BEGIN`/`COMMIT` al conteo de queries — los budgets de las features afectadas se ajustan
igual que en PR #277 (coste consciente de la atomicidad, no enmascaramiento).

### D4 — `LoggerContext`: doble de test `SpyLogger`, no `LoggerMock`

Nuevo `LoggerContext` para aseverar declarativamente las líneas del contrato RFC 9457 (nivel, mensaje,
`exception_category`, contexto, redacción). Requiere **una pieza nueva**: un doble PSR-3 que capture las
entradas, cableado en `when@test` como `LoggerInterface` del canal relevante.

Naming por la convención de `docs/rules/testing.md`: es un **test-double pattern (spy)** sobre una
interfaz de framework (PSR-3), no una implementación de un puerto de dominio ERPify → **`SpyLogger`**,
no `LoggerMock` (el nombre chiliz viola la convención) ni `InMemoryLogger` (reservado a implementaciones
de un puerto de dominio).

Descartado: parsear ficheros de log (frágil, acoplado al formato de salida). Descartado: reutilizar el
canal de Monolog de prod sin doble (no da introspección estructurada del contexto).

### D5 — `ConsumeContext`: aditivo, para aseverar **efectos** del handler

Portar `SymfonyMessengerConsumeContext` (`I consume N messages from "X" transport` + limpieza de los
listeners de parada del worker) para escenarios que prueban el **efecto** del consumo: fila en
`audit_log`, claim de idempotencia en `handled_domain_event`
([`domain-event-handler-idempotency.md`](./domain-event-handler-idempotency.md)), envío de email. Bajo
demanda; no necesario para el grueso de las features, que se quedan en publicación (D2).

### D6 — Rate limiter: **no** generalizar todavía (Rule of Three)

ERPify ya tiene `RateLimitContext` específico (`anonymous_api`), más limpio para su único limitador. El
`RateLimiterContext` chiliz genérico (cualquier `app.rate_limit.<event>`, set/consume/reset/dump)
depende de un wrapper `RateLimiter` que ERPify no tiene. **Decisión:** mantener `RateLimitContext` tal
cual; extraer los pasos genéricamente útiles (dump/reset por nombre de limitador) **solo cuando aparezca
un 2º limitador** (Rule of Three). Hoy: no-op deliberado, documentado aquí para que no se relea como
olvido.

### D7 — Ubicación y aislamiento de la infra de test

Los contextos viven en `api/tests/Behat/Context/`, los dobles en `api/tests/Behat/Support/` (o
`tests/Double/`): es infra de test, no producción, así que **no** dispara el gate de bounded-context.
Los pasos se mantienen **genéricos** — nombre de transporte, FQCN del evento, nombre de canal — sin
reachear a internals de `Bank`, para que la infra de test no se acople a un módulo.

## Verificación

- Features de `Bank` (POST/PUT/DELETE) con asserts de publicación (D2) en verde, contrato RFC 9457
  intacto.
- Un escenario de **atomicidad outbox** (modo doctrine, D3): el rollback de un fallo de publicación deja
  0 filas de agregado y 0 en `messenger_messages`.
- Un escenario de **línea de log** del contrato de error (D4) en verde.
- Budgets `N requests got executed` de las features afectadas por el modo doctrine actualizados.
- `make php.behat` y `make php.quality` (`level: max`) limpios.

## Triggers de revisita

(a) Aparece un **2º rate limiter** → se generaliza `RateLimitContext` (D6). (b) Adopción de un **broker
externo** → contexto para aseverar el relay aguas abajo del outbox. (c) Aterriza el **`CommandBus`
(#263)** → el `wrapInTransaction` que prueban los escenarios de atomicidad (D3) migra al middleware del
bus; esos escenarios se reescriben contra el nuevo borde transaccional.
