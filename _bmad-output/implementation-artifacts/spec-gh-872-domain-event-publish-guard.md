---
title: 'gh-872: guarda en runtime — transporte async transaccional y publicación dentro de transacción'
type: 'bugfix'
created: '2026-11-11'
status: 'done'
baseline_commit: '1ddd7bdb8e8bbe2b222a1a7cdd2c10080136049c'
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
- [x] `api/src/Shared/Event/Infrastructure/Messenger/` (servicio validador de transporte) -- extraer (a), memoizado; admisión in-memory inyectada -- una única fuente para comando y guarda.
- [x] `api/src/Shared/Event/Infrastructure/Cli/AssertTransactionalTransportCommand.php` -- delegar en el validador, mismo contrato de salida y misma severidad.
- [x] `api/src/Shared/Event/Infrastructure/Messenger/` (middleware de guarda + excepciones) -- implementar la matriz.
- [x] `api/config/packages/messenger.yaml` + config de servicios `when@test` -- registrar la guarda primera; admitir in-memory sólo en test.
- [x] Tests unitarios -- cada fila de la matriz, incluida conexión distinta contra la misma BD.
- [x] Test funcional (kernel real) -- fuera de transacción: excepción y cero filas nuevas en `event_store` y `messenger_messages` (fija el orden de middlewares por comportamiento); dentro: éxito.
- [x] Docs listados en el Code Map -- #872 residuales 1 y 2 cerrados; residual 3 sigue abierto; nuevos puntos ciegos (otro bus, `send()` directo, `isTransactionActive()` no prueba que sea la transacción de la escritura de negocio).

**Acceptance Criteria:**
- Given un proceso sin entrypoint con `MESSENGER_TRANSPORT_DSN` erróneo, when un caso de uso publica un evento enrutado a `async`, then lanza y no persiste nada.
- Given el árbol actual, when corren `make php.test` y Behat, then todo verde sin tocar publicadores.
- Given un worker que reintenta un mensaje fallido, when lo reenvía, then el reintento llega a la cola.

## Implementation Notes

## Spec Change Log

## Review Triage Log

Capas: Blind Hunter, Edge Case Hunter, Verification Gap y Acceptance Auditor (el `CLAUDE.md` exige esta última). Sin hilos de PR: la PR aún no existe.

| # | Hallazgo (capas) | Veredicto | Ruta | Evidencia |
|---|------------------|-----------|------|-----------|
| 1 | Las aserciones sobre `messenger_messages` del test funcional no pueden fallar; docblock y checklist las presentan como prueba del outbox (BH, VG, AA) | low | patch | En `test`, `async` es `in-memory://`: la tabla nunca recibe filas. Muerden `event_store` y `getSent()`. |
| 2 | Comentario relativo al cambio «used to be checked out of band» en el docblock del middleware (BH) | low | patch | `CLAUDE.md` → Code comments lo prohíbe. |
| 3 | `isRoutedToAsync()` construye todos los remitentes y un fallo ajeno a `async` se informa como fallo de `async` (BH, EH) | false | rechazado | `routing:` sólo enruta a `async` (`failed` no es destino de enrutado); no es alcanzable hoy y el mensaje dice «a transport it is routed to». |
| 4 | Memoizar un fallo transitorio deja el proceso rechazando hasta reiniciar (BH, EH) | low | rechazado | La spec fija «memoizado por proceso»; la DSN es fija durante el proceso y el fallo es fail-closed. Cambiarlo añade ramas. |
| 5 | Veredicto memoizado ligado a una conexión reseteada en modo worker (EH) | false | rechazado | `EntityManager::reset()` no sustituye el objeto `Connection` DBAL; sin ejemplo alcanzable. |
| 6 | La memoización del resultado `null` (el de producción) no tiene test (BH, VG) | medium | patch | `itInspectsTheTransportOncePerProcess` sólo cubre un resultado no nulo. |
| 7 | El parámetro `erpify.messenger.accepted_async_transport_classes` de producción no está fijado por ningún gate (BH, VG, AA-F1) | medium | patch | Añadir `InMemoryTransport` a `services.yaml` desactiva el control en prod con todo en verde. |
| 8 | Docs: «500 naming the message class and the cause» (BH, EH, AA) | low | patch | `docs/api-error-contract.md`: en prod el `title` es «An unexpected error occurred.»; clase y causa sólo llegan al log. |
| 9 | Docs: «rolls back / before anything is written» sin transacción abierta (BH) | low | patch | Sin transacción no hay nada que revertir; la escritura de negocio autocommiteada permanece. Residual no listado en §7. |
| 10 | Punto ciego sin listar: evento enrutado a otro alias distinto de `async` sólo recibe (b) (AA-F3, EH) | low | patch | Hoy no existe; es una línea en la fila §7. |
| 11 | Enrutado por interfaz y `TransportNamesStamp` sin test contra el `SendersLocator` real (VG, EH) | low | patch | El docblock lo promete; los dobles sólo devuelven `async` o vacío. Un test con el localizador real lo fija. |
| 12 | Frase obsoleta en el ADR de borrado de imágenes, línea 29 (AA-F4) | low | patch | Contradice el párrafo 44 ya actualizado. |
| 13 | «Primero en el bus»: `DispatchAfterCurrentBus` puede reenviar fuera de la transacción (EH) | false | rechazado | Nada en `api/src`, `config` ni `tests` usa `DispatchAfterCurrentBusStamp`. |
| 14 | Fila #872 narrada como cerrada antes de la revisión; columna de dueño «not recorded» (BH) | false | rechazado | La spec pedía la actualización; la revisión ha corrido antes de publicar. «not recorded» es anterior al cambio. |
| 15 | Sin test del comando bajo `APP_ENV=test` (BH) | low | rechazado | El cableado del parámetro lo ejerce el test funcional; el comando sólo delega. |
| 16 | Doble negación, resultado `?string` como texto libre, ids de servicio internos (BH) | low | rechazado | Cosmético; el enum añade superficie pública sin segundo consumidor. |
| 17 | No hay evidencia de que los publicadores existentes sobrevivan (BH) | false | rechazado | `make php.test` verde (4996 + 527 Behat); AA recorrió los 34 `publish` de `src`, todos dentro de `transactional()`. |
| 18 | §8 sobrevende la guarda como sustituto de la detección en arranque (EH) | low | rechazado | La redacción actual dice «early, loud reading»; no afirma sustitución. |
| 19 | Import sólo de docblock y `BooleanArgumentFlag` de PHPMD (AA-F5/F6) | maybe-false | comprobar | Se resuelve con `make php.quality` final; si rojo, patch. |

## Verification

**Commands:**
- `make php.stan` -- sin errores.
- `make php.unit c='--filter "Transport|Guard|PersistDomainEvent"'` -- verde.
- `make php.test` -- verde (unit + functional + Behat).
- `make php.lint.persistent-transport` -- verde.
- `make php.quality` -- verde.
