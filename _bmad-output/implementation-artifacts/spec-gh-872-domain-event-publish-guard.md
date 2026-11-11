---
title: 'gh-872: guarda en runtime — transporte async transaccional y publicación dentro de transacción'
type: 'bugfix'
created: '2026-11-11'
status: 'ready-for-dev'
route: 'dispatch'
review_loop_iteration: 0
context:
  - '{project-root}/docs/adr/event-driven-architecture.md'
  - '{project-root}/docs/adr/event-store-and-projections.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** La atomicidad outbox + `event_store` con la escritura de negocio solo se comprueba fuera de banda: el entrypoint ejecuta `event:transport:assert-transactional` (a) y nada comprueba que se publique dentro de una transacción (b). Un proceso que salta el entrypoint (`docker run`, `compose run --entrypoint`, `entrypoint:` sobrescrito) o un publicador futuro fuera de transacción publica sin aviso (#872, residuales 1 y 2).

**Approach:** Un middleware de guarda, PRIMERO en `messenger.bus.default`, que para todo envío original (sin `ReceivedStamp`) exige (b) transacción activa en la conexión del ORM si el mensaje es un `DomainEvent` o Messenger lo enruta a `async`, y (a) que `async` sea el transporte Doctrine sobre esa misma conexión (memoizado por proceso) si se enruta a `async`. Si falla, lanza y la transacción del caso de uso se revierte.

## Boundaries & Constraints

**Always:**
- Decisiones (consulta ChatGPT + Winston, contrastadas con `vendor/`): (b) cubre TODOS los `DomainEvent` (los ADR declaran atómico todo `event_store`; medidos los 18 publicadores Iam + 7 Bank/BankAccount: todos dentro de transacción, ninguno post-commit); "enrutado a `async`" se deriva de `SendersLocatorInterface::getSenders()` (clave = alias); la comprobación (a) reutiliza la identidad por `configureSchema` (`Connection.php:373`: con callback `false` añade la tabla sólo si `$forConnection === $this->driverConnection`).
- La guarda va antes de `PersistDomainEventMiddleware`: si no, una publicación fuera de transacción deja una fila `event_store` en autocommit antes de lanzar.
- La lógica de (a) se extrae a un servicio único; el comando del entrypoint lo reutiliza y se mantiene (fallo en el arranque).
- En `test`, `async` es `in-memory://`: (a) acepta `InMemoryTransport` sólo por configuración `when@test`, nunca leyendo el entorno en código. (b) sigue activa en test.
- Todo en `Infrastructure/`; sin supresiones; PHPStan max.

**Never:**
- Decorar `messenger.transport.async` (mismo servicio que lee el worker; perder una interfaz opcional rompe `messenger:stats`/`failed:show`/keepalive sin aviso).
- Listeners de arranque (`kernel.request`/`console.command`/`WorkerStartedEvent`): la seguridad ya está en el punto de publicación.
- Registrar y enviar igualmente; listas de clases a mano; reflexión sobre el transporte.
- Gates para un segundo bus o `send()` directo (hoy inexistentes): se registran como puntos ciegos en §7.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Publicación válida | `DomainEvent` enrutado a `async`, transacción activa, Doctrine en la conexión ORM | fila `event_store` + fila outbox, commit conjunto | N/A |
| Fuera de transacción | `DomainEvent` (enrutado o no) sin transacción | ni `event_store` ni outbox | excepción propia, nombra la clase del mensaje |
| Transporte erróneo | `async` = AMQP/Redis/Doctrine en otra conexión | nada se escribe; el caso de uso revierte | excepción propia con el diagnóstico actual del comando |
| Mensaje recibido | envelope con `ReceivedStamp` (worker, reintento, `failed:retry`) | pasa sin comprobar | N/A |
| No evento sin enrutar | p. ej. `SendEmailMessage` sin transacción | pasa | N/A |
| Test | `async` = in-memory con transacción | aceptado | N/A |

</frozen-after-approval>

## Code Map

- `api/src/Shared/Event/Infrastructure/Cli/AssertTransactionalTransportCommand.php` -- `problem()` contiene la lógica (a) a extraer; textos de error reutilizables.
- `api/src/Shared/Event/Infrastructure/Messenger/PersistDomainEventMiddleware.php` -- único middleware propio; NO cambia, la guarda va delante.
- `api/config/packages/messenger.yaml` -- `buses.messenger.bus.default.middleware` (:76), `when@test` (:82-86).
- `api/src/Shared/Persistence/Infrastructure/DoctrineTransactionManager.php` -- `transactional()` = `wrapInTransaction`; la excepción de la guarda la atraviesa.
- `api/src/Shared/Event/Infrastructure/Bus/SymfonyMessengerEventBus.php` -- docblock ya promete que un dispatch rechazado aborta el guardado.
- `api/tests/Unit/Shared/Event/Infrastructure/Cli/AssertTransactionalTransportCommandTest.php`, `.../Messenger/PersistDomainEventMiddlewareTest.php` -- patrones de test a seguir.
- `api/tests/Functional/Shared/Persistence/LeakedTransactionWorkerTest.php` -- construye su propio transporte; no pasa por el bus, no debe romperse.
- `api/tests/Behat/Context/OutboxContext.php:272` -- despacha dentro de `wrapInTransaction`.
- Docs: `PRODUCTION_SECURITY_CHECKLIST.md` §7 (fila #872) y §8 (caja `exec`), `docs/deployment-guide.md:34`, `docs/adr/image-deletion-signal-transport.md` (D2/D3, `@accepted-risk #872`), `docs/architecture-api.md`.

## Tasks & Acceptance

**Execution:**
- [ ] `api/src/Shared/Event/Infrastructure/Messenger/` (servicio validador de transporte) -- extraer (a), memoizado; admisión in-memory inyectada -- una única fuente para comando y guarda.
- [ ] `api/src/Shared/Event/Infrastructure/Cli/AssertTransactionalTransportCommand.php` -- delegar en el validador, mismo contrato de salida y misma severidad.
- [ ] `api/src/Shared/Event/Infrastructure/Messenger/` (middleware de guarda + excepciones) -- implementar la matriz.
- [ ] `api/config/packages/messenger.yaml` + config de servicios `when@test` -- registrar la guarda primera; admitir in-memory sólo en test.
- [ ] Tests unitarios -- cada fila de la matriz, incluida conexión distinta contra la misma BD.
- [ ] Test funcional (kernel real) -- fuera de transacción: excepción y cero filas nuevas en `event_store` y `messenger_messages` (fija el orden de middlewares por comportamiento); dentro: éxito.
- [ ] Docs listados en el Code Map -- #872 residuales 1 y 2 cerrados; residual 3 sigue abierto; nuevos puntos ciegos (otro bus, `send()` directo, `isTransactionActive()` no prueba que sea la transacción de la escritura de negocio).

**Acceptance Criteria:**
- Given un proceso sin entrypoint con `MESSENGER_TRANSPORT_DSN` erróneo, when un caso de uso publica un evento enrutado a `async`, then lanza y no persiste nada.
- Given el árbol actual, when corren `make php.test` y Behat, then todo verde sin tocar publicadores.
- Given un worker que reintenta un mensaje fallido, when lo reenvía, then el reintento llega a la cola.

## Implementation Notes

## Spec Change Log

## Review Triage Log

## Verification

**Commands:**
- `make php.stan` -- sin errores.
- `make php.unit c='--filter "Transport|Guard|PersistDomainEvent"'` -- verde.
- `make php.test` -- verde (unit + functional + Behat).
- `make php.lint.persistent-transport` -- verde.
- `make php.quality` -- verde.
