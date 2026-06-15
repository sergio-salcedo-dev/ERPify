# ADR — Contextos Behat para los seams de eventos y observabilidad

> **⚠️ SUPERSEDIDO en parte (2026-06-15).** El seam de test se rediseñó — ver
> [`docs/superpowers/specs/2026-06-15-behat-event-observability-contexts-design.md`](../superpowers/specs/2026-06-15-behat-event-observability-contexts-design.md).
> Cambios frente a lo que describe este ADR (el estado de #277): `OutboxContext` ahora asevera el
> **transporte/outbox por nombre lógico de cola** sobre el `InMemoryTransport` (cola pendiente `get()`),
> no el store `domain_event` (ese se asevera con los pasos genéricos de `EntityManagerContext`);
> `MessengerContext` se **borró**; `MessengerConsumeContext` → `MessengerConsumerContext`, que consume
> vía la clase `Worker` real (no `messenger:consume`, que resetea el transporte in-memory); se añadieron
> `NotificationContext` y `MercureContext` con dobles grabadores en `services_test.yaml`; y
> `features/shared/domain_events/event_publication.feature` se disolvió inline en
> `create`/`update`/`delete.feature`. `LoggerContext` sigue igual. Lo de abajo describe el estado de #277.

> **Estado:** aceptado (implementado) · **Fecha:** 2026-06-14 · **Ámbito:**
> `api/tests/Behat/Context/{Messenger,MessengerConsume,Outbox,Logger}Context` + su registro en
> `tools/behat/behat.yml.dist` + `features/shared/domain_events/event_publication.feature`.
>
> Sigue a [`event-driven-architecture.md`](./event-driven-architecture.md) (rama
> `feat/shared-event-bus-outbox-b1pa`, aún sin mergear a `main`): aquel ADR cerró el dual-write
> evento→outbox; **este fija cómo se asevera ese seam — y las líneas de log del contrato de error — de
> forma declarativa en Behat**, tomando como modelo dos bundles internos (`event-bundle-dev`,
> `test-bundle-dev`) sin arrastrar su stack.
>
> Dos decisiones se reorientaron al implementar, frente a la dirección inicial, por hallazgos en el
> código (anotados en D3 y D4): el outbox de test no se modela con un flip de transporte sino sobre el
> store `domain_event`, y el aserto de log reusa el `BufferingLogger` ya cableado en lugar de un doble
> nuevo.

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

**Hallazgo al implementar:** se temía que `KernelBrowser` → `kernel.terminate` disparara el
`services_resetter` y vaciara el `InMemoryTransport` antes del aserto (como hace con el cache array que
documenta `RateLimitContext`). Verificado empíricamente que **no**: tras una write HTTP, `getSent()`
sigue mostrando los envelopes, así que los pasos D2 funcionan post-request, no solo sobre dispatch
in-step. El payload se asevera vía `DomainEvent::toPrimitives()` (no la normalización Symfony de sus
props privadas), con el Symfony Serializer como fallback para mensajes no-`DomainEvent`.

### D3 — `OutboxContext`: **sin** entidad `OutboxEvent`, sobre el store `domain_event`

El `OutboxContext` chiliz asume una entidad `OutboxEvent` + tabla outbox propia leída vía
`EntityManager`. ERPify **no la tiene**: su outbox de entrega **es** la tabla del transporte Doctrine
(`messenger_messages`), y en test ni eso — es in-memory. Alternativas y decisión:

- **(a) Introducir una entidad `OutboxEvent`** → **descartado**: contradice el diseño del event-bus
  (outbox = transporte Doctrine) y duplica la fuente de verdad.
- **(b) Flip del transporte `async` a `doctrine` en una suite Behat opt-in** para aseverar
  `messenger_messages` y la atomicidad de la entrega → **diferido**: exige config de transporte por
  suite (alto coste/fragilidad), y un hallazgo lo hace innecesario para el grueso (ver abajo).
- **(c) Elegida** — aseverar el **store `domain_event`** (`StoredDomainEvent`), que
  `PersistDomainEventMiddleware` escribe **dentro de la misma transacción** que el agregado. Hallazgo
  clave: ese middleware corre en el **bus**, independiente del binding de transporte, así que la fila
  existe también con el transporte in-memory de test — sin flip. El `OutboxContext` portado lee las
  filas por `name`, decodifica el `body` JSON (ya deserializado por Doctrine, sin `PhpSerializer`) y
  asevera props anidadas (`bankId`, …) — el análogo fiel del chiliz, reset-inmune por ser una fila DB.

El fraseo de los pasos (`:count domain events named :name should be stored`,
`the stored domain event :name body :property should be equal to :value`) es **distinto** del paso
genérico `there should have N "...StoredDomainEvent" entity found by "name="` que ya usa el WIP humano de
las features de Bank, así que no hay colisión de step definitions: aquél cuenta existencia, éste asevera
contenido de payload.

Diferido (trigger de revisita): el flip a `doctrine`/`messenger_messages` para probar la atomicidad de la
**entrega** (no solo del store) se añade cuando aterrice un broker/relay aguas abajo del outbox.

### D4 — `LoggerContext`: reusar el `BufferingLogger` existente, no un doble nuevo

`LoggerContext` asevera declarativamente las líneas del contrato RFC 9457 (nivel, mensaje,
`exception_category`, contexto). **Hallazgo al implementar:** ERPify **ya** tiene el capturador —
`Symfony\Component\ErrorHandler\BufferingLogger`, registrado público en `services_test.yaml` e inyectado
**solo** en `ExceptionResponder` (con la decisión deliberada de *no* aliasear `LoggerInterface` global).
Los tests funcionales ya leen su `cleanLogs()`. Así que **no** se introduce un `SpyLogger` nuevo: el
contexto inyecta ese mismo `BufferingLogger`.

`cleanLogs()` **consume** el buffer, por lo que el contexto drena-y-acumula (`@BeforeScenario` limpia) para
que todos los registros sigan visibles a través de varios ciclos request/assert en un escenario.

Descartado: parsear ficheros de log (frágil). Descartado: un doble `SpyLogger` nuevo (innecesario —
duplicaría el `BufferingLogger` ya cableado donde importa, y reabriría la decisión de "no aliasear global").

### D5 — `MessengerConsumeContext`: worker real para aseverar **efectos** del consumo

`MessengerConsumeContext` corre el worker `messenger:consume` real (`I consume N messages from the "X"
transport`) para escenarios que prueban el **efecto** del consumo a través del stack completo de
middleware de recepción: claim de idempotencia en `handled_domain_event`
([`domain-event-handler-idempotency.md`](./domain-event-handler-idempotency.md)), retries al transporte
`failed`. Esto lo distingue del paso ligero `message N ... is processed` de `MessengerContext`, que invoca
solo el handler callable y **se salta** ese middleware.

Dos salvaguardas lo hacen seguro en un proceso Behat largo: purga de los stop-listeners stale de consumes
previos (acumulan en el dispatcher compartido; un límite stale menor pararía el worker antes de tiempo) y
un `--time-limit` backstop anti-cuelgue.

No demostrado en la feature de ejemplo a propósito: consumir un `BankCreatedDomainEvent` dispara sus
handlers reales (email a Mailpit y publish a Mercure); el de Mercure bloquea ~5 s por el timeout local
conocido del hub, lo que acoplaría y ralentizaría una feature compartida. El contexto queda construido,
cableado y **smoke-verificado** funcionando; demostrarlo se reserva a un escenario que necesite el efecto
y tolere ese coste.

### D6 — Rate limiter: **no** generalizar todavía (Rule of Three)

ERPify ya tiene `RateLimitContext` específico (`anonymous_api`), más limpio para su único limitador. El
`RateLimiterContext` chiliz genérico (cualquier `app.rate_limit.<event>`, set/consume/reset/dump)
depende de un wrapper `RateLimiter` que ERPify no tiene. **Decisión:** mantener `RateLimitContext` tal
cual; extraer los pasos genéricamente útiles (dump/reset por nombre de limitador) **solo cuando aparezca
un 2º limitador** (Rule of Three). Hoy: no-op deliberado, documentado aquí para que no se relea como
olvido.

### D7 — Ubicación y aislamiento de la infra de test

Los cuatro contextos viven en `api/tests/Behat/Context/` y se registran explícitamente en
`tools/behat/behat.yml.dist` (Behat solo instancia los listados): es infra de test, no producción, así
que **no** dispara el gate de bounded-context. No se añadió ningún doble nuevo (D4 reusa el
`BufferingLogger`). Los pasos se mantienen **genéricos** — nombre de transporte, FQCN del evento, nombre
del store — sin reachear a internals de `Bank`, para que la infra de test no se acople a un módulo.

## Verificación

- `features/shared/domain_events/event_publication.feature`: tres escenarios verdes sobre una write de
  `Bank` — publicación al transporte `async` (D2), payload del store `domain_event` (D3) y la línea de
  log `warning` "API error response built" con `type=validation-failed`/`status=422` (D4).
- `MessengerConsumeContext` (D5) smoke-verificado: consume el evento y la aserción de store se mantiene
  (no demostrado en la suite por el coste del handler de Mercure).
- Suite Behat completa **127/127 escenarios** verde — sin regresión en el WIP de Bank ni en el resto.
- `make php.quality` (`level: max`, incluye los gates `gherkin`/`event-bus`/`bounded-context`) limpio.

## Triggers de revisita

(a) Aparece un **2º rate limiter** → se generaliza `RateLimitContext` (D6). (b) Adopción de un **broker
externo** → contexto para aseverar el relay aguas abajo del outbox. (c) Aterriza el **`CommandBus`
(#263)** → el `wrapInTransaction` que prueban los escenarios de atomicidad (D3) migra al middleware del
bus; esos escenarios se reescriben contra el nuevo borde transaccional.
